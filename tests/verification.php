<?php
declare(strict_types=1);

// Synthetic records only. No real Resend requests; transport is injected or mocked.
require_once __DIR__ . '/../backend/bootstrap.php';
require_once __DIR__ . '/../vendor/autoload.php';
$config = [
    'db_host' => '127.0.0.1', 'db_port' => 3306,
    'db_name' => 'magasaka_waitlist_test_' . bin2hex(random_bytes(5)),
    'db_user' => getenv('WAITLIST_TEST_ADMIN_USER') ?: 'root',
    'db_password' => getenv('WAITLIST_TEST_ADMIN_PASSWORD') ?: '',
    'app_key' => bin2hex(random_bytes(32)), 'resend_api_key' => 're_test_only',
    'resend_from' => 'Magasaka <onboarding@resend.dev>',
];
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', $config['db_user'], $config['db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec('CREATE DATABASE `' . $config['db_name'] . '` CHARACTER SET utf8mb4');
$checks = 0;
function verify_check(bool $ok, string $label): void {
    global $checks;
    if (!$ok) { throw new RuntimeException('FAILED: ' . $label); }
    $checks++; echo "PASS: $label\n";
}
function verify_reject(callable $fn, string $code, string $label): void {
    try { $fn(); } catch (WaitlistError $e) { verify_check($e->errorCode === $code, $label); return; }
    throw new RuntimeException('FAILED (no rejection): ' . $label);
}
try {
    $pdo = waitlist_connect($config);
    $pdo->exec(file_get_contents(__DIR__ . '/../database/schema.sql'));
    $token = bin2hex(random_bytes(32));
    $email = 'verification@example.com';
    $input = ['email' => $email];
    $entry = waitlist_join($pdo, $input, $token)['entry'];
    $messages = [];
    $sender = function ($settings, $payload, $id) use (&$messages) {
        $messages[] = ['payload' => $payload, 'id' => $id]; return 'mock-' . $id;
    };
    $send = fn ($force = false) => waitlist_request_code($pdo, $input + ['resend' => $force], $token, $config, $sender);
    $verify = fn ($code) => waitlist_verify_code($pdo, $input + ['code' => $code], $token, $config);
    $challenge = fn () => $pdo->query('SELECT * FROM waitlist_email_verifications WHERE member_number = 1')->fetch();
    $cooldown = fn () => $pdo->exec('UPDATE waitlist_email_verifications SET next_send_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)');
    verify_reject($send, 'profile_incomplete', 'Cannot send before profile completion');
    $profile = $input + ['name' => 'Verification', 'title' => 'Web Developer', 'picks' => ['PHP']];
    $saved = waitlist_update($pdo, $profile, $token, true)['entry'];
    verify_check($saved['complete'] && !$saved['verified'], 'Saving profile does not verify email');
    verify_reject(fn () => waitlist_request_code($pdo, $input, str_repeat('b', 64), $config, $sender), 'invalid_session', 'Cannot send codes to someone else using only their email');
    verify_reject(fn () => waitlist_request_code($pdo, $input, $token, array_replace($config, ['resend_test_recipient' => 'owner@example.com']), $sender), 'mail_test_mode', 'Sandbox recipient restriction is enforced');
    verify_reject(fn () => waitlist_request_code($pdo, $input, $token, array_replace($config, ['resend_api_key' => '']), $sender), 'mail_unavailable', 'Missing mail credentials cannot pretend to send');
    $result = $send();
    $row = $challenge();
    $code = waitlist_verification_code($row['request_id'], $config['app_key']);
    verify_check(preg_match('/^[0-9]{6}$/D', $code) === 1 && str_contains($messages[0]['payload']['text'], $code), 'Send the exact six-digit code through the mail transport');
    verify_check($row['code_hash'] !== $code && strlen($row['code_hash']) === 64 && !str_contains(json_encode($result), $code), 'Store a keyed hash; never return code in API result');
    verify_check(abs(strtotime($row['expires_at'] . ' UTC') - time() - 600) < 3, 'Code expires after ten minutes');
    verify_check($row['provider_email_id'] === 'mock-' . $row['request_id'], 'Persist provider email ID for delivery investigation');
    $send();
    verify_check(count($messages) === 1, 'Reopening an active challenge sends no duplicate email');
    verify_reject(fn () => $send(true), 'resend_cooldown', 'Resending immediately is blocked');
    verify_reject(fn () => $verify('123'), 'invalid_code', 'Require exactly six digits');
    verify_reject(fn () => waitlist_verify_code($pdo, $input + ['code' => $code], str_repeat('b', 64), $config), 'invalid_session', 'Code cannot be used with a different browser key');
    $wrong = $code === '000000' ? '111111' : '000000';
    for ($i = 0; $i < 5; $i++) { verify_reject(fn () => $verify($wrong), 'incorrect_code', 'Incorrect attempt is rejected'); }
    verify_check((int) $challenge()['attempts'] === 5, 'Incorrect attempts persist instead of rolling back');
    verify_reject(fn () => $verify($code), 'code_attempt_limit', 'Five failures lock the old code');
    $cooldown(); $send(true);
    $next = $challenge();
    verify_check($next['request_id'] !== $row['request_id'] && (int) $next['attempts'] === 0, 'Resend creates a new challenge');
    verify_reject(fn () => $verify($code), 'incorrect_code', 'Old code is invalid after resend');
    $code = waitlist_verification_code($next['request_id'], $config['app_key']);
    $pdo->exec('UPDATE waitlist_email_verifications SET expires_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 SECOND)');
    verify_reject(fn () => $verify($code), 'code_expired', 'Expired codes cannot verify');
    $cooldown(); $send(true);
    $row = $challenge();
    $code = waitlist_verification_code($row['request_id'], $config['app_key']);
    $verified = $verify($code)['entry'];
    verify_check($verified['verified'] && $verified['no'] === $entry['no'] && $verified['ref'] === $entry['ref'], 'Correct code verifies and preserves member number and referral');
    verify_check($challenge() === false, 'Successful verification consumes the challenge');
    verify_check($verify($code)['entry']['verified'], 'Lost success response can safely be retried');
    $count = count($messages); $send(true);
    verify_check(count($messages) === $count, 'Verified member does not receive another verification email');

    // An uncertain provider response retains its stable code and idempotency key across retries.
    $pdo->exec('UPDATE waitlist_entries SET email_verified_at = NULL WHERE member_number = 1');
    $failedMessage = null;
    try {
        waitlist_request_code($pdo, $input, $token, $config, function ($cfg, $payload, $id) use (&$failedMessage) {
            $failedMessage = ['payload' => $payload, 'id' => $id];
            throw new WaitlistError(503, 'mail_unavailable', 'Mock network failure');
        });
    } catch (WaitlistError $error) { verify_check($error->errorCode === 'mail_unavailable', 'Provider failure stays a failure'); }
    verify_check(!waitlist_entry($pdo, 1)['verified'] && $challenge()['sent_at'] === null, 'Provider failure never marks member verified or sent');
    $cooldown(); $send();
    verify_check(end($messages) === $failedMessage, 'Delivery retry uses identical payload and idempotency key');
    $pdo->exec('UPDATE waitlist_email_verifications SET send_count = 5'); $cooldown();
    verify_reject(fn () => $send(true), 'email_send_limit', 'Maximum five sends per email per hour');
    $pdo->exec('UPDATE waitlist_email_verifications SET send_window_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 61 MINUTE)');
    $send(true);
    verify_check((int) $challenge()['send_count'] === 1, 'Hourly send allowance resets after the window');
    $payload = waitlist_verification_payload($config + ['resend_verification_template' => 'verify-template'], $email, '012345');
    verify_check(!isset($payload['text']) && !isset($payload['html']) && $payload['template']['variables']['CODE'] === '012345', 'Hosted template preserves leading zeros and uses exact variables');
    // Recovery: the same email in a new browser. The code goes to the inbox; the right code moves the signup there.
    $pdo->exec('UPDATE waitlist_entries SET email_verified_at = NULL WHERE member_number = 1');
    $pdo->exec('DELETE FROM waitlist_email_verifications');
    $newToken = bin2hex(random_bytes(32));
    verify_reject(fn () => waitlist_join($pdo, $input, $newToken), 'email_exists', 'A new browser cannot join with an email already on the list');
    verify_reject(fn () => waitlist_recover_request($pdo, ['email' => 'nobody@example.com'], $newToken, $config, $sender), 'not_found', 'Recovery needs an email on the list');
    $before = count($messages);
    $sent = waitlist_recover_request($pdo, $input, $newToken, $config, $sender);
    verify_check(count($messages) === $before + 1 && !isset($sent['entry']) && $sent['verification']['sent'], 'Recovery emails a code and reveals nothing about the signup');
    $code = waitlist_verification_code($challenge()['request_id'], $config['app_key']);
    $wrong = $code === '000000' ? '111111' : '000000';
    verify_reject(fn () => waitlist_recover($pdo, $input + ['code' => $wrong], $newToken, $config), 'incorrect_code', 'Recovery rejects a wrong code');
    verify_check((int) $challenge()['attempts'] === 1, 'A wrong recovery code counts as an attempt');
    verify_reject(fn () => waitlist_update($pdo, $profile, $newToken, true), 'invalid_session', 'The new browser has no access before the right code');
    $recovered = waitlist_recover($pdo, $input + ['code' => $code], $newToken, $config)['entry'];
    verify_check($recovered['no'] === $entry['no'] && $recovered['verified'], 'The right code keeps the member number and confirms the email');
    verify_check(waitlist_join($pdo, $input, $newToken)['entry']['no'] === $entry['no'], 'The new browser can now continue');
    verify_reject(fn () => waitlist_update($pdo, $profile, $token, true), 'invalid_session', 'The old browser key stops working');
    verify_check($challenge() === false, 'Recovery consumes the challenge');
    verify_check(waitlist_recover($pdo, $input + ['code' => $code], $newToken, $config)['entry']['no'] === $entry['no'], 'A lost recovery response can safely be retried');
    verify_reject(fn () => waitlist_recover($pdo, $input + ['code' => $code], bin2hex(random_bytes(32)), $config), 'code_expired', 'A used code cannot move the signup again');
    $pdo->exec('DELETE FROM waitlist_entries WHERE member_number = 1');
    verify_check($challenge() === false, 'Deleting a member removes their verification challenge');

    // Exercise real SDK request formatting and retries with a local mock, never the network.
    $json = ['Content-Type' => 'application/json'];
    $mock = new \GuzzleHttp\Handler\MockHandler([
        new \GuzzleHttp\Psr7\Response(500, $json, '{"name":"internal_server_error","message":"Mock","statusCode":500}'),
        new \GuzzleHttp\Psr7\Response(200, $json, '{"id":"mail-test-1"}'),
    ]);
    $requests = [];
    $capture = function ($request, $options) use ($mock, &$requests) { $requests[] = $request; return $mock($request, $options); };
    $client = waitlist_resend_client('re_fake_test', $capture);
    $sent = $client->emails->send(['from' => $config['resend_from'], 'to' => [$email], 'subject' => 'Test', 'text' => 'Test'], ['idempotency_key' => 'waitlist-verification/test']);
    verify_check($sent->id === 'mail-test-1' && count($requests) === 2, 'SDK retries provider 500 errors');
    verify_check((string) $requests[0]->getBody() === (string) $requests[1]->getBody()
        && $requests[0]->getHeaderLine('Idempotency-Key') === 'waitlist-verification/test'
        && $requests[1]->getHeaderLine('Idempotency-Key') === 'waitlist-verification/test', 'Retries preserve payload and idempotency header');
    verify_check($requests[0]->getUri()->getHost() === 'api.resend.com' && $requests[0]->getUri()->getScheme() === 'https', 'SDK always sends secrets to the fixed HTTPS provider host');
    $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(401, $json, '{"name":"invalid_api_key","message":"Mock","statusCode":401}')]);
    try { waitlist_resend_client('re_fake_test', $mock)->emails->send(['text'=>'test']); }
    catch (\Resend\Exceptions\ErrorException $error) { verify_check($error->getErrorCode() === 401 && count($mock) === 0, 'Invalid credentials are not retried'); }
    $mock = new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(429, $json + ['Retry-After'=>'60'], '{"name":"rate_limit_exceeded","message":"Mock","statusCode":429}')]);
    try { waitlist_resend_client('re_fake_test', $mock)->emails->send(['text'=>'test']); }
    catch (\Resend\Exceptions\ErrorException $error) { verify_check($error->getErrorCode() === 429 && count($mock) === 0, 'Long Retry-After is not retried early'); }
    echo "\n$checks verification checks passed.\n";
} finally {
    $admin->exec('DROP DATABASE `' . $config['db_name'] . '`');
    echo "Removed the isolated verification test database.\n";
}
