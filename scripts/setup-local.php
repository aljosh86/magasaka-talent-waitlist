<?php
declare(strict_types=1);

// Run from a terminal, never through a browser. Does not reset existing data.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/../backend/bootstrap.php';
$root = dirname(__DIR__);
$database = 'magasaka_waitlist';
$admin = new PDO('mysql:host=127.0.0.1;dbname=' . $database . ';charset=utf8mb4',
    getenv('WAITLIST_ADMIN_USER') ?: 'root', getenv('WAITLIST_ADMIN_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$admin->exec(file_get_contents($root . '/database/schema.sql'));
$configPath = $root . '/backend/config.local.php';
if (!is_file($configPath)) {
    $user = 'magasaka_wl_' . bin2hex(random_bytes(4));
    $password = bin2hex(random_bytes(24));
    $account = $admin->quote($user) . "@'localhost'";
    $admin->exec('CREATE USER ' . $account . ' IDENTIFIED BY ' . $admin->quote($password));
    $admin->exec('GRANT SELECT, INSERT, UPDATE, DELETE ON `' . $database . '`.* TO ' . $account);
    $config = [
        'db_host' => '127.0.0.1', 'db_port' => 3306, 'db_name' => $database,
        'db_user' => $user, 'db_password' => $password,
        'app_key' => bin2hex(random_bytes(32)), 'allowed_origins' => [],
    ];
    if (file_put_contents($configPath, "<?php\nreturn " . var_export($config, true) . ";\n", LOCK_EX) === false) {
        throw new RuntimeException('Could not write the private local configuration.');
    }
}
$pdo = waitlist_connect(waitlist_config());
$count = (int) $pdo->query('SELECT COUNT(*) FROM waitlist_entries')->fetchColumn();
$next = (int) $pdo->query('SELECT next_number FROM waitlist_counter WHERE id = 1')->fetchColumn();
echo "Local database ready: $database. Entries: $count. Next member number: $next.\n";
echo "Private database settings saved in backend/config.local.php (excluded from Git).\n";
