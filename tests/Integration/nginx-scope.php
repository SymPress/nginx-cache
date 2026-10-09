<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use SymPress\NginxCache\Key\CacheKeyStrategy;
use SymPress\NginxCache\Purge\SiteScopedCacheScanner;
use SymPress\NginxCache\Value\SiteKeyMatcher;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

function scopeAddress(): string
{
    $socket = stream_socket_server('tcp://127.0.0.1:0');
    if (!is_resource($socket)) { throw new RuntimeException('Cannot reserve a fixture port.'); }
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    if (!is_string($address)) { throw new RuntimeException('Cannot resolve fixture port.'); }
    return $address;
}

$nginx = (new ExecutableFinder())->find('nginx');
if ($nginx === null) { throw new RuntimeException('A native Nginx binary is required.'); }
$fs = new Filesystem();
$root = sys_get_temp_dir() . '/sympress-native-scope-' . bin2hex(random_bytes(8));
$front = scopeAddress();
$back = scopeAddress();
$server = $origin = null;
try {
    $fs->mkdir($root);
    $fs->dumpFile($root . '/router.php', '<?php header("Cache-Control: public, max-age=600"); echo "Native cache fixture";');
    $origin = new Process([PHP_BINARY, '-S', $back, $root . '/router.php']);
    $origin->start();
    $fs->dumpFile($root . '/nginx.conf', <<<NGINX
pid $root/nginx.pid;
error_log $root/error.log;
events { worker_connections 32; }
http {
    access_log off;
    client_body_temp_path $root/body;
    proxy_temp_path $root/proxy-temp;
    fastcgi_temp_path $root/fastcgi-temp;
    uwsgi_temp_path $root/uwsgi-temp;
    scgi_temp_path $root/scgi-temp;
    proxy_cache_path $root/cache levels=1:2 keys_zone=fixture:1m inactive=10m;
    server {
        listen $front;
        location / {
            proxy_pass http://$back;
            proxy_cache fixture;
            proxy_cache_key "\$scheme|\$request_method|\$host|\$request_uri";
            proxy_cache_valid 200 10m;
            add_header X-Fixture-Cache \$upstream_cache_status always;
        }
    }
}
NGINX);
    (new Process([$nginx, '-p', $root, '-c', $root . '/nginx.conf', '-t']))->mustRun();
    $server = new Process([$nginx, '-p', $root, '-c', $root . '/nginx.conf', '-g', 'daemon off;']);
    $server->start();
    $http = HttpClient::create(['timeout' => 2, 'max_redirects' => 0]);
    for ($i = 0; $i < 100; ++$i) {
        try { if ($http->request('GET', 'http://' . $front . '/ready/', ['headers' => ['Host' => 'ready.test']])->getStatusCode() === 200) { break; } }
        catch (Throwable) {}
        usleep(50000);
    }
    $cases = [['example.test', '/article/'], ['example.test', '/shop/product/'], ['example.test', '/shop/child/page/'], ['other.test', '/article/']];
    foreach ($cases as [$host, $path]) {
        $headers = [];
        for ($i = 0; $i < 2; ++$i) {
            $response = $http->request('GET', 'http://' . $front . $path, ['headers' => ['Host' => $host]]);
            $response->getContent();
            $headers[] = $response->getHeaders()['x-fixture-cache'][0] ?? '';
        }
        if ($headers !== ['MISS', 'HIT']) { throw new RuntimeException('Native cache did not warm: ' . json_encode($headers)); }
    }
    $scanner = new SiteScopedCacheScanner(new CacheKeyStrategy());
    $matcher = new SiteKeyMatcher(['example.test' => '/shop/'], ['example.test' => ['/', '/shop/', '/shop/child/']]);
    $scan = $scanner->scan($root . '/cache', $matcher);
    if ($scan['partial'] || count($scan['entries']) !== 1) { throw new RuntimeException('Binary Nginx KEY ownership was not resolved correctly.'); }
    $all = (new SiteScopedCacheScanner(new CacheKeyStrategy()))->scan($root . '/cache', new SiteKeyMatcher(['other.test' => '/', 'example.test' => '/', 'ready.test' => '/']));
    $before = [];
    foreach ($all['entries'] as $file) { $before[$file] = [hash_file('sha256', $file), filemtime($file)]; }
    $fs->remove($scan['entries']);
    foreach ($before as $file => $snapshot) {
        if (in_array($file, $scan['entries'], true)) { continue; }
        if (!is_file($file) || $snapshot !== [hash_file('sha256', $file), filemtime($file)]) { throw new RuntimeException('Native sibling cache changed.'); }
    }
    echo 'PASS: real Nginx MISS/HIT responses, binary KEY parsing, nested-site boundaries and unchanged sibling cache hashes/mtimes.' . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'Nginx fixture: ' . (is_file($root . '/error.log') ? $fs->readFile($root . '/error.log') : '') . PHP_EOL);
    fwrite(STDERR, 'Origin fixture: ' . ($origin?->getErrorOutput() ?? '') . PHP_EOL);
    throw $error;
} finally {
    $server?->stop(2);
    $origin?->stop(2);
    $fs->remove($root);
}
