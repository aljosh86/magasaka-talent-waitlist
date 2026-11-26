<?php
declare(strict_types=1);

require_once __DIR__ . '/waitlist.php';

function waitlist_config(): array
{
    $path = getenv('WAITLIST_CONFIG_FILE') ?: __DIR__ . '/config.local.php';
    $local = is_file($path) ? require $path : [];
    $defaults = [
        'db_host' => '127.0.0.1', 'db_port' => 3306,
        'db_name' => 'magasaka_waitlist', 'db_user' => '', 'db_password' => '',
        'app_key' => '', 'allowed_origins' => [],
    ];
    $config = array_replace($defaults, is_array($local) ? $local : []);
    foreach (array_keys($defaults) as $key) {
        $value = getenv('WAITLIST_' . strtoupper($key));
        if ($value !== false) {
            $config[$key] = $key === 'allowed_origins'
                ? array_values(array_filter(array_map('trim', explode(',', $value)))) : $value;
        }
    }
    if (!$config['db_user'] || strlen($config['app_key']) < 32) {
        throw new RuntimeException('The waitlist database configuration is missing.');
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $config['db_name'])) {
        throw new RuntimeException('Invalid database name.');
    }
    return $config;
}

function waitlist_connect(array $config): PDO
{
    $pdo = new PDO(
        sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config['db_host'], $config['db_port'], $config['db_name']),
        $config['db_user'], $config['db_password'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
         PDO::ATTR_EMULATE_PREPARES => false]
    );
    $pdo->exec("SET time_zone = '+00:00'");
    return $pdo;
}
