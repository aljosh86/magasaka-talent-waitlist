<?php
declare(strict_types=1);

// Requires a local MariaDB/MySQL administrator to create an isolated temporary database.
// Never reads or changes the real waitlist's entries.
require_once __DIR__ . '/../backend/bootstrap.php';
$root = dirname(__DIR__);
$keep = in_array('--keep-browser-fixture', $argv, true);
$dbName = 'magasaka_waitlist_test_' . bin2hex(random_bytes(5));
$config = [
    'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => $dbName,
    'db_user' => getenv('WAITLIST_TEST_ADMIN_USER') ?: 'root',
    'db_password' => getenv('WAITLIST_TEST_ADMIN_PASSWORD') ?: '',
    'app_key' => bin2hex(random_bytes(32)),
    'allowed_origins' => ['http://127.0.0.1:4175'],
];
$admin = new PDO('mysql:host=127.0.0.1;charset=utf8mb4', $config['db_user'], $config['db_password'],
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec("CREATE DATABASE `$dbName` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
if (!is_dir($root . '/.local')) { mkdir($root . '/.local'); }
$configPath = $root . '/.local/waitlist-test-config.php';
file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n");
$server = null;
$passed = 0;
$success = false;
function check(bool $condition, string $description): void {
    global $passed;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $description); }
    $passed++; echo "PASS: $description\n";
}
function rejects(callable $call, string $code, string $description): void {
    try { $call(); } catch (WaitlistError $error) { check($error->errorCode === $code, $description); return; }
    throw new RuntimeException('FAILED (no error): ' . $description);
}
function clearFixture(PDO $pdo): void {
    $pdo->exec('DELETE FROM waitlist_skills');
    $pdo->exec('UPDATE waitlist_entries SET referred_by = NULL');
    $pdo->exec('DELETE FROM waitlist_entries');
    $pdo->exec('DELETE FROM waitlist_rate_limits');
    $pdo->exec('UPDATE waitlist_counter SET next_number = 1 WHERE id = 1');
}
try {
    $pdo = waitlist_connect($config);
    $schema = file_get_contents($root . '/database/schema.sql');
    $pdo->exec($schema); $pdo->exec($schema);
    check((int) $pdo->query('SELECT next_number FROM waitlist_counter')->fetchColumn() === 1, 'Schema imports safely and starts at #1');
    rejects(fn () => waitlist_join($pdo, ['email' => 'invalid'], bin2hex(random_bytes(32))), 'invalid_email', 'Reject invalid email');
    $token = bin2hex(random_bytes(32));
    $first = waitlist_join($pdo, ['email' => ' First@Example.COM '], $token)['entry'];
    check($first['no'] === 1 && $first['email'] === 'first@example.com' && !$first['complete'] && !$first['verified'], 'Email reserves #1 before profile completion; remains unverified');
    check(strlen($first['ref']) === 18, 'Server generates a referral code');
    $retry = waitlist_join($pdo, ['email' => 'first@example.com'], $token);
    check(!$retry['created'] && $retry['entry']['no'] === 1, 'Retry with original key returns the same entry');
    rejects(fn () => waitlist_join($pdo, ['email' => 'FIRST@EXAMPLE.COM'], bin2hex(random_bytes(32))), 'email_exists', 'Duplicate email cannot claim another slot or expose a profile');
    check((int) $pdo->query('SELECT next_number FROM waitlist_counter')->fetchColumn() === 2, 'Duplicate and failed requests do not skip numbers');
    $name = "José O’Neil 🌱";
    $saved = waitlist_update($pdo, ['email' => $first['email'], 'name' => $name], $token, false)['entry'];
    check($saved['name'] === $name && !$saved['complete'], 'Save Unicode name independently');
    $profile = ['email' => $first['email'], 'name' => $name, 'title' => 'Web Developer', 'picks' => ['PHP', 'Custom niche', 'QA Testing']];
    $done = waitlist_update($pdo, $profile, $token, true)['entry'];
    check($done['complete'] && $done['picks'] === $profile['picks'] && $done['no'] === 1 && $done['ref'] === $first['ref'], 'Complete profile saves all fields and keeps the original number and referral');
    rejects(fn () => waitlist_update($pdo, $profile, bin2hex(random_bytes(32)), true), 'invalid_session', 'Cannot edit another signup using only its email');
    rejects(fn () => waitlist_update($pdo, array_replace($profile, ['picks' => []]), $token, true), 'invalid_skills', 'Require at least one skill');
    rejects(fn () => waitlist_update($pdo, array_replace($profile, ['picks' => ['A', 'B', 'C', 'D']]), $token, true), 'invalid_skills', 'Reject more than three skills');
    rejects(fn () => waitlist_update($pdo, array_replace($profile, ['picks' => ['PHP', 'php']]), $token, true), 'duplicate_skills', 'Reject duplicate skills');
    rejects(fn () => waitlist_update($pdo, array_replace($profile, ['title' => 'Unlisted']), $token, true), 'invalid_title', 'Validate professional titles on the server');
    rejects(fn () => waitlist_update($pdo, array_replace($profile, ['name' => str_repeat('x', 101)]), $token, true), 'invalid_input', 'Enforce name length');
    check(waitlist_entry($pdo, 1)['picks'] === $profile['picks'], 'Rejected edits leave existing profile intact');
    $second = waitlist_join($pdo, ['email' => 'second@example.com', 'referredBy' => $first['ref']], bin2hex(random_bytes(32)))['entry'];
    check($second['no'] === 2 && $second['referredBy'] === $first['ref'], 'Save referral attribution for the next signup');
    for ($i = 0; $i < 20; $i++) { waitlist_rate_limit($pdo, 'test-ip', $config['app_key'], 'join'); }
    rejects(fn () => waitlist_rate_limit($pdo, 'test-ip', $config['app_key'], 'join'), 'rate_limited', 'Throttle excessive signup attempts');

    clearFixture($pdo);
    $pdo->beginTransaction();
    $seed = $pdo->prepare('INSERT INTO waitlist_entries (member_number, email, referral_code, edit_token_hash) VALUES (?, ?, ?, ?)');
    for ($i = 1; $i <= 9999; $i++) {
        $seed->execute([$i, "seed$i@example.com", 'mg' . str_pad((string) $i, 16, '0', STR_PAD_LEFT), hash('sha256', "seed$i")]);
    }
    $pdo->exec('UPDATE waitlist_counter SET next_number = 10000 WHERE id = 1');
    $pdo->commit();
    $workers = [];
    for ($i = 0; $i < 8; $i++) {
        $process = proc_open([PHP_BINARY, __DIR__ . '/waitlist-worker.php', $configPath, "race$i@example.com", bin2hex(random_bytes(32))],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $workers[] = [$process, $pipes];
    }
    $numbers = []; $full = 0;
    foreach ($workers as [$process, $pipes]) {
        $result = json_decode(stream_get_contents($pipes[1]), true);
        $error = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0 && $error === '' && is_array($result), 'Concurrent worker completed');
        if (isset($result['number'])) { $numbers[] = $result['number']; }
        elseif (($result['code'] ?? '') === 'waitlist_full') { $full++; }
    }
    check($numbers === [10000] && $full === 7, 'Exactly one concurrent signup gets #10,000; all others are refused');
    check((int) $pdo->query('SELECT COUNT(*) FROM waitlist_entries')->fetchColumn() === 10000, 'Database never exceeds 10,000 entries');
    check((int) $pdo->query('SELECT next_number FROM waitlist_counter')->fetchColumn() === 10001, 'Counter stops after the last slot');
    $pdo->exec('DELETE FROM waitlist_entries WHERE member_number = 5000');
    rejects(fn () => waitlist_join($pdo, ['email' => 'after-delete@example.com'], bin2hex(random_bytes(32))), 'waitlist_full', 'Deleting an entry does not reuse an issued number');

    clearFixture($pdo);
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    $port = (int) substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
    $env = array_merge(getenv(), ['WAITLIST_CONFIG_FILE' => $configPath]);
    $log = $root . '/.local/waitlist-http-test.log';
    $server = proc_open([PHP_BINARY, '-S', "127.0.0.1:$port", '-t', $root . '/magasaka-waitlist-fullquality'],
        [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $serverPipes, $root, $env);
    fclose($serverPipes[0]);
    $url = "http://127.0.0.1:$port/api/waitlist.php";
    $http = function (string $method, ?string $body = null, array $headers = []) use ($url): array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => 5, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers]);
        if ($body !== null) { curl_setopt($ch, CURLOPT_POSTFIELDS, $body); }
        $raw = curl_exec($ch); $status = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerLength = curl_getinfo($ch, CURLINFO_HEADER_SIZE); curl_close($ch);
        return ['status' => $status, 'headers' => substr($raw ?: '', 0, $headerLength),
            'data' => json_decode(substr($raw ?: '', $headerLength), true)];
    };
    for ($i = 0; $i < 30; $i++) { if ($http('GET')['status']) break; usleep(100000); }
    check($http('GET')['status'] === 405, 'HTTP API rejects reads; no public listing of emails');
    $headers = ['Content-Type: application/json', 'X-Waitlist-Token: ' . bin2hex(random_bytes(32))];
    check($http('POST', '{', $headers)['status'] === 400, 'HTTP rejects malformed JSON');
    check($http('POST', str_repeat('x', 8193), $headers)['status'] === 413, 'HTTP enforces request size');
    check($http('POST', '{}', ['Content-Type: text/plain'])['status'] === 415, 'HTTP rejects unsupported content types');
    check($http('POST', '{"action":"join","email":"http@example.com"}', ['Content-Type: application/json'])['status'] === 401, 'HTTP requires a browser key');
    check($http('OPTIONS', null, ['Origin: https://untrusted.example'])['status'] === 403, 'CORS rejects unapproved previews');
    $cors = $http('OPTIONS', null, ['Origin: http://127.0.0.1:4175']);
    check($cors['status'] === 204 && str_contains($cors['headers'], 'Access-Control-Allow-Origin: http://127.0.0.1:4175'), 'CORS supports a configured static preview');
    check(str_contains($cors['headers'], 'Access-Control-Expose-Headers: Retry-After'), 'Cross-origin previews can read the resend cooldown');
    $joined = $http('POST', '{"action":"join","email":"http@example.com"}', $headers);
    check($joined['status'] === 201 && $joined['data']['entry']['no'] === 1, 'HTTP saves a real signup at #1');
    check(str_contains($joined['headers'], 'Cache-Control: no-store'), 'Private API responses cannot be cached');
    $nameResponse = $http('POST', '{"action":"name","email":"http@example.com","name":"HTTP Test"}', $headers);
    check($nameResponse['status'] === 200 && $nameResponse['data']['entry']['name'] === 'HTTP Test', 'HTTP saves the name');
    $completed = $http('POST', '{"action":"complete","email":"http@example.com","name":"HTTP Test","title":"Web Developer","picks":["PHP"]}', $headers);
    check($completed['status'] === 200 && $completed['data']['entry']['complete'], 'HTTP completes the card profile');
    check(!str_contains(json_encode($completed['data']), 'token'), 'HTTP never exposes stored authentication hashes');
    clearFixture($pdo);
    $success = true;
    echo "\n$passed checks passed.\n";
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    if (!$keep || !$success) {
        $admin->exec("DROP DATABASE `$dbName`");
        unlink($configPath);
        echo "Removed the isolated test database.\n";
    } else {
        echo "Empty browser-test database retained: $dbName\n";
        echo "Config: .local/waitlist-test-config.php\n";
    }
}
