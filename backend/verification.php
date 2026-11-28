<?php
declare(strict_types=1);

require_once __DIR__ . '/resend.php';

// Derivable for safe delivery retries, but not recoverable from the database without the server key.
function waitlist_verification_code(string $requestId, string $key): string
{
    return str_pad((string) (hexdec(substr(hash_hmac('sha256', 'verification-code|' . $requestId, $key), 0, 12)) % 1000000), 6, '0', STR_PAD_LEFT);
}

function waitlist_verification_hash(int $member, string $requestId, string $code, string $key): string
{
    return hash_hmac('sha256', "$member|$requestId|$code", $key);
}

function waitlist_lock_member(PDO $pdo, string $email, string $token): array
{
    $query = $pdo->prepare('SELECT member_number, edit_token_hash, profile_completed_at, email_verified_at
        FROM waitlist_entries WHERE email = ? FOR UPDATE');
    $query->execute([$email]);
    $member = $query->fetch();
    if (!$member || !hash_equals($member['edit_token_hash'], waitlist_token_hash($token))) {
        throw new WaitlistError(401, 'invalid_session', 'Please return to the signup form and re-enter your email.');
    }
    if (!$member['profile_completed_at']) {
        throw new WaitlistError(409, 'profile_incomplete', 'Please finish your name, title and skills first.');
    }
    return $member;
}

function waitlist_code_input(array $input): string
{
    $code = $input['code'] ?? '';
    if (!is_string($code) || !preg_match('/^[0-9]{6}$/D', $code)) {
        throw new WaitlistError(422, 'invalid_code', 'Enter the 6-digit code from your email.');
    }
    return $code;
}

// Runs with the member's row locked inside an open transaction. Commits it, then emails the code.
// One challenge per member, shared by first-time verification and recovery: either way the code proves the inbox.
function waitlist_send_challenge(PDO $pdo, int $number, string $email, bool $force, array $config, ?callable $sender = null): array
{
    $now = time();
    waitlist_mail_settings($config, $email);
    $query = $pdo->prepare('SELECT * FROM waitlist_email_verifications WHERE member_number = ?');
    $query->execute([$number]);
    $row = $query->fetch();
    $active = $row && strtotime($row['expires_at'] . ' UTC') > $now && (int) $row['attempts'] < 5;
    // Reopening or retrying a completed request must not send duplicate emails.
    if ($active && $row['sent_at'] && !$force) {
        $pdo->commit();
        return ['sent' => true, 'resendAfter' => max(0, strtotime($row['next_send_at'] . ' UTC') - $now)];
    }
    if ($row && strtotime($row['next_send_at'] . ' UTC') > $now) {
        throw new WaitlistError(429, 'resend_cooldown', 'Please wait one minute before requesting another code.');
    }
    $sameWindow = $row && strtotime($row['send_window_at'] . ' UTC') > $now - 3600;
    $count = $sameWindow ? (int) $row['send_count'] : 0;
    if ($count >= 5) {
        throw new WaitlistError(429, 'email_send_limit', 'You have requested several codes. Please try again in one hour.');
    }
    // A failed/uncertain delivery retries the identical request and code using Resend idempotency.
    $retry = $active && !$row['sent_at'];
    $requestId = $retry ? $row['request_id'] : bin2hex(random_bytes(16));
    $code = waitlist_verification_code($requestId, $config['app_key']);
    $expires = $retry ? $row['expires_at'] : gmdate('Y-m-d H:i:s', $now + 600);
    $window = $sameWindow ? $row['send_window_at'] : gmdate('Y-m-d H:i:s', $now);
    $upsert = $pdo->prepare('INSERT INTO waitlist_email_verifications
        (member_number, request_id, code_hash, expires_at, attempts, next_send_at, send_window_at, send_count)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE request_id = VALUES(request_id),
        code_hash = VALUES(code_hash), expires_at = VALUES(expires_at), attempts = VALUES(attempts),
        next_send_at = VALUES(next_send_at), send_window_at = VALUES(send_window_at), send_count = VALUES(send_count),
        sent_at = NULL, provider_email_id = NULL');
    $upsert->execute([$number, $requestId,
        waitlist_verification_hash($number, $requestId, $code, $config['app_key']), $expires,
        $retry ? (int) $row['attempts'] : 0, gmdate('Y-m-d H:i:s', $now + 60), $window, $count + 1]);
    $pdo->commit();
    // Provider calls never hold a database lock. The persisted challenge survives timeouts.
    $payload = waitlist_verification_payload($config, $email, $code);
    $providerId = ($sender ?? 'waitlist_send_verification_email')($config, $payload, $requestId);
    $pdo->prepare('UPDATE waitlist_email_verifications SET sent_at = UTC_TIMESTAMP(), provider_email_id = ?
        WHERE member_number = ? AND request_id = ?')->execute([$providerId, $number, $requestId]);
    return ['sent' => true, 'resendAfter' => max(0, $now + 60 - time())];
}

// Runs inside an open transaction. A wrong code commits its failed attempt before throwing,
// otherwise attackers would get unlimited guesses. A right code consumes the challenge.
function waitlist_check_challenge(PDO $pdo, int $number, string $code, array $config): void
{
    $query = $pdo->prepare('SELECT * FROM waitlist_email_verifications WHERE member_number = ? FOR UPDATE');
    $query->execute([$number]); $row = $query->fetch();
    if (!$row || strtotime($row['expires_at'] . ' UTC') <= time()) {
        throw new WaitlistError(422, 'code_expired', 'This code has expired. Please request a new code.');
    }
    if ((int) $row['attempts'] >= 5) {
        throw new WaitlistError(429, 'code_attempt_limit', 'Too many incorrect codes. Please request a new code.');
    }
    if (!hash_equals($row['code_hash'], waitlist_verification_hash($number, $row['request_id'], $code, $config['app_key']))) {
        $pdo->prepare('UPDATE waitlist_email_verifications SET attempts = attempts + 1 WHERE member_number = ?')->execute([$number]);
        $pdo->commit();
        throw new WaitlistError(422, 'incorrect_code', 'That code is not correct. Check your email and try again.');
    }
    $pdo->prepare('DELETE FROM waitlist_email_verifications WHERE member_number = ?')->execute([$number]);
}

function waitlist_request_code(PDO $pdo, array $input, string $token, array $config, ?callable $sender = null): array
{
    $email = waitlist_email($input['email'] ?? null);
    waitlist_token_hash($token);
    $force = ($input['resend'] ?? false) === true;
    $pdo->beginTransaction();
    try {
        $member = waitlist_lock_member($pdo, $email, $token);
        $number = (int) $member['member_number'];
        if ($member['email_verified_at']) {
            $entry = waitlist_entry($pdo, $number); $pdo->commit();
            return ['created' => false, 'entry' => $entry];
        }
        $verification = waitlist_send_challenge($pdo, $number, $email, $force, $config, $sender);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return ['created' => false, 'entry' => waitlist_entry($pdo, $number), 'verification' => $verification];
}

function waitlist_verify_code(PDO $pdo, array $input, string $token, array $config): array
{
    $email = waitlist_email($input['email'] ?? null);
    waitlist_token_hash($token);
    $code = waitlist_code_input($input);
    $pdo->beginTransaction();
    try {
        $member = waitlist_lock_member($pdo, $email, $token);
        $number = (int) $member['member_number'];
        if ($member['email_verified_at']) {
            $entry = waitlist_entry($pdo, $number); $pdo->commit();
            return ['created' => false, 'entry' => $entry];
        }
        waitlist_check_challenge($pdo, $number, $code, $config);
        $pdo->prepare('UPDATE waitlist_entries SET email_verified_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
            WHERE member_number = ?')->execute([$number]);
        $entry = waitlist_entry($pdo, $number); $pdo->commit();
        return ['created' => false, 'entry' => $entry];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}

// Recovery: an email already on the waitlist, opened in a browser without its key.
// The code goes only to that inbox, and the reply reveals nothing about the signup.
function waitlist_recover_request(PDO $pdo, array $input, string $token, array $config, ?callable $sender = null): array
{
    $email = waitlist_email($input['email'] ?? null);
    waitlist_token_hash($token);
    $force = ($input['resend'] ?? false) === true;
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT member_number FROM waitlist_entries WHERE email = ? FOR UPDATE');
        $query->execute([$email]);
        $number = $query->fetchColumn();
        if ($number === false) {
            throw new WaitlistError(404, 'not_found', 'This email is not on the waitlist yet. Enter it again to join.');
        }
        $verification = waitlist_send_challenge($pdo, (int) $number, $email, $force, $config, $sender);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
    return ['created' => false, 'verification' => $verification];
}

// The right code hands the signup to this browser's key (the old browser's key stops working)
// and counts as email verification, since it proves the inbox.
function waitlist_recover(PDO $pdo, array $input, string $token, array $config): array
{
    $email = waitlist_email($input['email'] ?? null);
    $tokenHash = waitlist_token_hash($token);
    $code = waitlist_code_input($input);
    $pdo->beginTransaction();
    try {
        $query = $pdo->prepare('SELECT member_number, edit_token_hash FROM waitlist_entries WHERE email = ? FOR UPDATE');
        $query->execute([$email]);
        $member = $query->fetch();
        if (!$member) {
            throw new WaitlistError(404, 'not_found', 'This email is not on the waitlist yet. Enter it again to join.');
        }
        $number = (int) $member['member_number'];
        // A lost success response retried with the same key is already done.
        if (!hash_equals($member['edit_token_hash'], $tokenHash)) {
            waitlist_check_challenge($pdo, $number, $code, $config);
            $pdo->prepare('UPDATE waitlist_entries SET edit_token_hash = ?,
                email_verified_at = COALESCE(email_verified_at, UTC_TIMESTAMP()), updated_at = UTC_TIMESTAMP()
                WHERE member_number = ?')->execute([$tokenHash, $number]);
        }
        $entry = waitlist_entry($pdo, $number); $pdo->commit();
        return ['created' => false, 'entry' => $entry];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $error;
    }
}
