<?php
declare(strict_types=1);

require_once __DIR__ . '/../../backend/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Vary: Origin');

try {
    $config = waitlist_config();
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $sameOrigin = $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? '');
    if ($origin !== '' && $origin !== $sameOrigin) {
        if (!in_array($origin, $config['allowed_origins'], true)) {
            throw new WaitlistError(403, 'origin_not_allowed', 'Signups are not enabled from this preview address.');
        }
        header('Access-Control-Allow-Origin: ' . $origin);
    }
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-Waitlist-Token');
    header('Access-Control-Expose-Headers: Retry-After');
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Allow: POST, OPTIONS');
        throw new WaitlistError(405, 'method_not_allowed', 'Please use the signup form to join the waitlist.');
    }
    if (!str_starts_with(strtolower($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json')) {
        throw new WaitlistError(415, 'invalid_content_type', 'Please submit the signup form again.');
    }
    $raw = file_get_contents('php://input', false, null, 0, 8193);
    if ($raw === false || strlen($raw) > 8192) {
        throw new WaitlistError(413, 'request_too_large', 'This submission is too large. Please shorten your details.');
    }
    try { $input = json_decode($raw, true, 16, JSON_THROW_ON_ERROR); }
    catch (JsonException $error) { throw new WaitlistError(400, 'invalid_json', 'Please submit the signup form again.'); }
    if (!is_array($input) || !isset($input['action']) || !in_array($input['action'], ['join', 'name', 'complete', 'send_code', 'verify'], true)) {
        throw new WaitlistError(400, 'invalid_action', 'Please submit the signup form again.');
    }
    $token = $_SERVER['HTTP_X_WAITLIST_TOKEN'] ?? '';
    waitlist_token_hash($token);
    $pdo = waitlist_connect($config);
    waitlist_rate_limit($pdo, $_SERVER['REMOTE_ADDR'] ?? 'unknown', $config['app_key'], $input['action']);
    $result = match ($input['action']) {
        'join' => waitlist_join($pdo, $input, $token),
        'send_code' => waitlist_request_code($pdo, $input, $token, $config),
        'verify' => waitlist_verify_code($pdo, $input, $token, $config),
        default => waitlist_update($pdo, $input, $token, $input['action'] === 'complete'),
    };
    http_response_code($result['created'] ? 201 : 200);
    echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
} catch (WaitlistError $error) {
    http_response_code($error->status);
    if ($error->status === 429) { header('Retry-After: ' . ($error->errorCode === 'email_send_limit' ? '3600' : '60')); }
    echo json_encode(['ok' => false, 'code' => $error->errorCode, 'message' => $error->getMessage()]);
} catch (Throwable $error) {
    // Never return database credentials, stack traces, email addresses or tokens.
    error_log('Waitlist request failed: ' . get_class($error) . ' [' . $error->getCode() . ']');
    http_response_code(503);
    echo json_encode(['ok' => false, 'code' => 'unavailable',
        'message' => 'We could not confirm your signup. Please try again shortly.']);
}
