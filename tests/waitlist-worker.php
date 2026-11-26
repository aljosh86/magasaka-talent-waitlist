<?php
declare(strict_types=1);
require_once __DIR__ . '/../backend/bootstrap.php';
$config = require $argv[1];
if (!str_starts_with($config['db_name'], 'magasaka_waitlist_test_')) { exit(1); }
try {
    $result = waitlist_join(waitlist_connect($config), ['email' => $argv[2]], $argv[3]);
    echo json_encode(['number' => $result['entry']['no']]);
} catch (WaitlistError $error) {
    echo json_encode(['code' => $error->errorCode]);
}
