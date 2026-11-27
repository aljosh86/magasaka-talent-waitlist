<?php
declare(strict_types=1);

// Mang Juan's answers. POST {"messages":[{"role":"user"|"assistant","content":"..."}]} -> {"ok":true,"reply":"..."}.
// When this fails the page answers from its own built-in replies, so the chat never goes silent.
require_once __DIR__ . '/../../backend/bootstrap.php';
require_once __DIR__ . '/../../backend/juan.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');

try {
    $config = waitlist_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    if ($origin !== '' && $origin !== $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '')) {
        if (!in_array($origin, $config['allowed_origins'], true)) {
            throw new WaitlistError(403, 'origin_not_allowed', 'Mang Juan is not available from this address.');
        }
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST, OPTIONS');
        throw new WaitlistError(405, 'method_not_allowed', 'Please ask from the page.');
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new WaitlistError(415, 'invalid_content_type', 'Please ask your question again.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 32769);
    if ($raw === false || strlen($raw) > 32768) {
        throw new WaitlistError(413, 'request_too_large', 'Please ask a shorter question.');
    }
    try { $input = json_decode($raw, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new WaitlistError(400, 'invalid_json', 'Please ask your question again.'); }
    $messages = juan_messages($input);
    juan_settings($config);   // no key configured: answer at once so the page uses its own replies
    juan_rate_limit(waitlist_connect($config), $_SERVER['REMOTE_ADDR'] ?? 'unknown', $config['app_key']);
    echo json_encode(['ok' => true, 'reply' => juan_reply($config, $messages)], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (WaitlistError $error) {
    http_response_code($error->status);
    if ($error->status === 429) { header('Retry-After: 60'); }
    echo json_encode(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $error) {
    error_log('Mang Juan request failed: ' . get_class($error));
    http_response_code(503);
    echo json_encode(['ok' => false, 'code' => 'unavailable', 'message' => 'Mang Juan is resting right now.']);
}
