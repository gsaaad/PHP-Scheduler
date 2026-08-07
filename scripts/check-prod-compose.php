<?php

declare(strict_types=1);

/**
 * Asserts the production compose file is not dev-shaped.
 *
 * The failure this guards against is invisible until it has already happened:
 * a stack that boots fine, serves traffic fine, and is also publishing its
 * database to the internet or running the host checkout instead of the image
 * that CI verified.
 *
 * Usage:
 *   docker compose -f docker-compose.prod.yml config --format json \
 *     | php scripts/check-prod-compose.php
 */

$raw = stream_get_contents(STDIN);
if ($raw === false || trim($raw) === '') {
    fwrite(STDERR, "No compose config on stdin.\n");
    exit(1);
}

$config = json_decode($raw, true);
if (!is_array($config) || !isset($config['services'])) {
    fwrite(STDERR, "Could not parse compose config as JSON.\n");
    exit(1);
}

$services = $config['services'];
$failures = [];

// The database must not be reachable from outside the compose network. The
// development file maps 5433 to the host, which on a server is the internet.
foreach ($services['db']['ports'] ?? [] as $port) {
    $failures[] = sprintf(
        'db publishes a port (%s); it should be reachable only on the compose network',
        $port['published'] ?? '?'
    );
}

$app = $services['app'] ?? [];

// Plain HTTP must not be reachable alongside the TLS front door.
foreach ($app['ports'] ?? [] as $port) {
    $hostIp = $port['host_ip'] ?? '0.0.0.0';
    if (!in_array($hostIp, ['127.0.0.1', '::1'], true)) {
        $failures[] = sprintf(
            'app publishes %s:%s to a public interface; it should bind to loopback behind Caddy',
            $hostIp,
            $port['published'] ?? '?'
        );
    }
}

$volumes = $app['volumes'] ?? [];

// A bind mount over the document root replaces the built image with whatever
// is on the host, which defeats the point of building and testing an artifact.
foreach ($volumes as $mount) {
    if (($mount['type'] ?? '') === 'bind' && ($mount['target'] ?? '') === '/var/www/html') {
        $failures[] = 'app bind-mounts the source tree over /var/www/html, masking the built image';
    }
}

// Uploaded media lives in the container layer unless something persists it,
// so every redeploy would silently discard it.
$targets = array_column($volumes, 'target');
if (!in_array('/var/www/html/storage/robot-media', $targets, true)) {
    $failures[] = 'uploaded media has no volume; it would be lost on every redeploy';
}

if ($failures !== []) {
    fwrite(STDERR, "FAIL: docker-compose.prod.yml is not production-shaped\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  - {$failure}\n");
    }
    exit(1);
}

echo "ok   db unpublished, app on loopback, media persisted, no source bind-mount\n";
