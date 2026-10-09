<?php

declare(strict_types=1);

define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', true);

if (($argv[1] ?? '') === '--worker') {
    require __DIR__ . '/bootstrap.php';
    wp_installing(false);
    try {
        require __DIR__ . '/scale-checks.php';
    } catch (Throwable $error) {
        fwrite(STDERR, get_class($error) . ': ' . $error->getMessage() . PHP_EOL);
        exit(1);
    }
    exit;
}

$schema = getenv('NGINX_TEST_DB_NAME');
$host = getenv('NGINX_TEST_DB_HOST') ?: '127.0.0.1:33079';
if (!is_string($schema) || preg_match('/^sympress_review_nginx[a-z0-9_]*$/', $schema) !== 1) {
    throw new RuntimeException('A fresh disposable sympress_review_nginx schema is mandatory.');
}
[$server, $port] = array_pad(explode(':', $host, 2), 2, '3306');
$pdo = new PDO('mysql:host=' . $server . ';port=' . $port, getenv('NGINX_TEST_DB_USER') ?: 'root', getenv('NGINX_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `' . $schema . '`');
$originalBuffer = null;
function resizeScaleBufferPool(PDO $database, int $bytes): void
{
    $before = (int) $database->query('SELECT @@GLOBAL.innodb_buffer_pool_size')->fetchColumn();
    $database->exec('SET GLOBAL innodb_buffer_pool_size=' . $bytes);
    $deadline = microtime(true) + 10;
    do {
        $effective = (int) $database->query('SELECT @@GLOBAL.innodb_buffer_pool_size')->fetchColumn();
        if ($bytes < $before ? $effective <= $bytes : $effective >= $bytes) { return; }
        usleep(250000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('The requested scale buffer pool was not applied.');
}
try {
    $buffer = getenv('NGINX_TEST_SCALE_BUFFER_POOL_MB');
    if (is_string($buffer) && $buffer !== '') {
        $megabytes = filter_var($buffer, FILTER_VALIDATE_INT, ['options' => ['min_range' => 64, 'max_range' => 2048]]);
        if ($megabytes === false) { throw new RuntimeException('Scale buffer pool must be 64..2048 MiB.'); }
        $otherSchemas = array_diff($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN), ['information_schema', 'performance_schema', 'mysql', 'sys', $schema]);
        if ($otherSchemas !== []) { throw new RuntimeException('A buffer-pool override requires a dedicated empty disposable database server.'); }
        $originalBuffer = (int) $pdo->query('SELECT @@GLOBAL.innodb_buffer_pool_size')->fetchColumn();
        putenv('NGINX_TEST_SCALE_INITIAL_BUFFER_POOL_BYTES=' . $originalBuffer);
        resizeScaleBufferPool($pdo, $megabytes * 1048576);
    }
    require __DIR__ . '/bootstrap.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Disposable scale fixture', 'fixture-admin', 'fixture@example.test', false, '', bin2hex(random_bytes(24)));
    wp_installing(false);
    foreach ($wpdb->ms_global_tables as $table) { $wpdb->$table = $wpdb->base_prefix . $table; }
    install_network();
    $created = populate_network(1, 'example.test', 'fixture@example.test', 'Disposable scale network', '/', false);
    if (is_wp_error($created)) { throw new RuntimeException($created->get_error_message()); }
    putenv('NGINX_TEST_MULTISITE=1');
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--worker'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) { throw new RuntimeException('Could not start scale checks.'); }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) { throw new RuntimeException('Scale checks failed.'); }
} finally {
    $pdo->exec('DROP DATABASE `' . $schema . '`');
    if ($originalBuffer !== null) { resizeScaleBufferPool($pdo, $originalBuffer); }
}
