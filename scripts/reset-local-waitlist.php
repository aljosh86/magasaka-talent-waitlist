<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/bootstrap.php';
$config = waitlist_config();
$options = getopt('', ['database:', 'confirm-delete-all']);
if (!in_array($config['db_host'], ['127.0.0.1', 'localhost', '::1'], true)
    || ($options['database'] ?? '') !== $config['db_name'] || !isset($options['confirm-delete-all'])) {
    fwrite(STDERR, "No data deleted. Local testing only. To delete ALL entries and restart at #1, explicitly run:\n"
        . "php scripts/reset-local-waitlist.php --database=" . $config['db_name'] . " --confirm-delete-all\n");
    exit(1);
}
$pdo = waitlist_connect($config);
$pdo->beginTransaction();
try {
    $pdo->query('SELECT next_number FROM waitlist_counter WHERE id = 1 FOR UPDATE')->fetchColumn();
    $pdo->exec('DELETE FROM waitlist_skills');
    $pdo->exec('UPDATE waitlist_entries SET referred_by = NULL');
    $pdo->exec('DELETE FROM waitlist_entries');
    $pdo->exec('DELETE FROM waitlist_rate_limits');
    $pdo->exec('UPDATE waitlist_counter SET next_number = 1 WHERE id = 1');
    $pdo->commit();
    echo "Cleared local waitlist entries. The next signup will receive #1.\n";
} catch (Throwable $error) {
    $pdo->rollBack(); throw $error;
}
