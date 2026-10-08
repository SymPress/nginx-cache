<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use SymPress\NginxCache\Config\BypassRuleProvider;
use SymPress\NginxCache\Config\NginxConfigGenerator;
use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Settings\WordPressCacheSettings;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

$nginx = (new ExecutableFinder())->find('nginx');
if ($nginx === null) {
    throw new RuntimeException('A real nginx binary is required for the config integration test.');
}
$listener = stream_socket_server('tcp://127.0.0.1:0');
if (!is_resource($listener)) {
    throw new RuntimeException('Could not reserve a local test port.');
}
$address = stream_socket_get_name($listener, false);
fclose($listener);
if (!is_string($address)) {
    throw new RuntimeException('Could not determine the local test address.');
}
$filesystem = new Filesystem();
$root = sys_get_temp_dir() . '/sympress-nginx-config-' . bin2hex(random_bytes(8));
$process = null;
try {
    $filesystem->mkdir($root);
    $generator = new NginxConfigGenerator(
        new WordPressCacheSettings($root . '/cache'),
        new BypassRuleProvider(),
        new CacheKeyStrategy(),
    );
    $filesystem->dumpFile($root . '/http.conf', $generator->generate(context: 'http'));
    $filesystem->dumpFile($root . '/fastcgi.conf', $generator->generate(context: 'fastcgi'));
    $filesystem->dumpFile($root . '/nginx.conf', <<<NGINX
pid $root/nginx.pid;
error_log $root/error.log;
events { worker_connections 32; }
http {
    access_log off;
    include $root/http.conf;
    server {
        listen $address;
        location / {
            include $root/fastcgi.conf;
            return 200 "\$sympress_cache_skip|\$sympress_cache_request_uri|\$sympress_cache_query_string";
        }
    }
}
NGINX);
    (new Process([$nginx, '-p', $root, '-c', $root . '/nginx.conf', '-t']))->mustRun();
    if (!is_dir($root . '/cache')) {
        throw new RuntimeException('Nginx did not create its configured cache directory.');
    }
    $process = new Process([$nginx, '-p', $root, '-c', $root . '/nginx.conf', '-g', 'daemon off;']);
    $process->start();
    $http = HttpClient::create(['timeout' => 2, 'max_redirects' => 0]);
    $ready = false;
    for ($attempt = 0; $attempt < 100; ++$attempt) {
        try {
            $ready = $http->request('GET', 'http://' . $address . '/')->getStatusCode() === 200;
            if ($ready) {
                break;
            }
        } catch (Throwable) {
        }
        usleep(50_000);
    }
    if (!$ready) {
        throw new RuntimeException('The isolated Nginx fixture did not start: ' . $process->getErrorOutput());
    }
    $cases = [
        ['GET', '/', [], '0|/|'],
        ['GET', '/?utm_source=fixture&gclid=123', [], '0|/|'],
        ['GET', '/?s=private', [], '1|/?s=private|s=private'],
        ['GET', '/?utm_source=fixture&s=private', [], '1|/?utm_source=fixture&s=private|utm_source=fixture&s=private'],
        ['GET', '/wp-admin/', [], '1|/wp-admin/|'],
        ['GET', '/', ['Cookie' => 'wordpress_logged_in_fixture=1'], '1|/|'],
        ['GET', '/', ['Cookie' => 'woocommerce_items_in_cart=1'], '1|/|'],
        ['GET', '/', ['Authorization' => 'Bearer fixture'], '1|/|'],
        ['POST', '/', [], '1|/|'],
    ];
    foreach ($cases as [$method, $path, $headers, $expected]) {
        $actual = $http->request($method, 'http://' . $address . $path, ['headers' => $headers])->getContent();
        if ($actual !== $expected) {
            throw new RuntimeException(sprintf('Nginx map regression for %s %s: expected %s, got %s.', $method, $path, $expected, $actual));
        }
    }
    echo 'PASS: Nginx created the cache root; 9 real HTTP cases verify empty queries, tracking canonicalization and private-request bypass.' . PHP_EOL;
} finally {
    if ($process instanceof Process) {
        $process->stop(2);
    }
    $filesystem->remove($root);
}
