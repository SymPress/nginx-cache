<?php

declare(strict_types=1);

if (($argv[1] ?? '') === '--worker') {
    require __DIR__ . '/bootstrap.php';
    wp_installing(false);
    require __DIR__ . '/multisite-checks.php';
    exit;
}

$schema = getenv('NGINX_TEST_DB_NAME');
$host = getenv('NGINX_TEST_DB_HOST') ?: '127.0.0.1:33079';
if (!is_string($schema) || preg_match('/^sympress_review_nginx[a-z0-9_]*$/', $schema) !== 1) {
    throw new RuntimeException('A fresh disposable schema starting sympress_review_nginx is mandatory.');
}
[$server, $port] = array_pad(explode(':', $host, 2), 2, '3306');
$pdo = new PDO('mysql:host=' . $server . ';port=' . $port, getenv('NGINX_TEST_DB_USER') ?: 'root', getenv('NGINX_TEST_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE DATABASE `' . $schema . '`');
try {
    require __DIR__ . '/bootstrap.php';
    require ABSPATH . 'wp-admin/includes/upgrade.php';
    wp_install('Disposable Multisite review', 'fixture-admin', 'fixture@example.test', false, '', bin2hex(random_bytes(24)));
    wp_installing(false);
    foreach ($wpdb->ms_global_tables as $table) {
        $wpdb->$table = $wpdb->base_prefix . $table;
    }
    install_network();
    $created = populate_network(1, 'example.test', 'fixture@example.test', 'Disposable network', '/', false);
    if (is_wp_error($created)) {
        throw new RuntimeException($created->get_error_message());
    }
    putenv('NGINX_TEST_MULTISITE=1');
    $process = proc_open([PHP_BINARY, __FILE__, '--worker'], [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
    if (!is_resource($process)) {
        throw new RuntimeException('Could not start Multisite checks.');
    }
    fclose($pipes[0]);
    if (proc_close($process) !== 0) {
        throw new RuntimeException('Multisite checks failed.');
    }
} finally {
    $pdo->exec('DROP DATABASE `' . $schema . '`');
}
