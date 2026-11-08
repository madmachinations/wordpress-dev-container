<?php
declare(strict_types=1);

function fail(string $message): never {
    fwrite(STDERR, "wordpress-dev: $message\n");
    exit(1);
}

function required(string $name): string {
    $value = getenv($name);
    if ($value === false || $value === '') {
        fail("$name must be set and non-empty.");
    }
    return $value;
}

function origin(string $name): string {
    $value = rtrim(required($name), '/');
    if (!str_contains($value, '://')) {
        $value = ($name === 'LOCAL_DOMAIN' ? 'http://' : 'https://') . $value;
    }
    $parts = parse_url($value);
    if ($parts === false || !in_array($parts['scheme'] ?? '', ['http', 'https'], true)
        || empty($parts['host']) || preg_match('/[\s\x00-\x1f]/', $value)
        || isset($parts['user']) || isset($parts['pass'])
        || isset($parts['query']) || isset($parts['fragment']) || !empty($parts['path'])) {
        fail("$name must be an HTTP(S) origin (hostname and optional port, without a path, credentials, query, or fragment).");
    }
    $host = $parts['host'];
    $ipv6 = str_starts_with($host, '[') && str_ends_with($host, ']')
        && filter_var(substr($host, 1, -1), FILTER_VALIDATE_IP, FILTER_FLAG_IPV6);
    if (!$ipv6 && !filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
        fail("$name has an invalid hostname.");
    }
    if (isset($parts['port']) && ($parts['port'] < 1 || $parts['port'] > 65535)) {
        fail("$name has an invalid port.");
    }
    return $parts['scheme'] . '://' . strtolower($parts['host']) . (isset($parts['port']) ? ':' . $parts['port'] : '');
}

function domains(): array {
    $urls = [];
    $authorities = [];
    foreach (['PRODUCTION_DOMAIN', 'STAGING_DOMAIN', 'LOCAL_DOMAIN'] as $name) {
        $urls[$name] = origin($name);
        $authority = preg_replace('~^https?://~', '', $urls[$name]);
        if (in_array($authority, $authorities, true)) {
            fail('Production, staging, and local origins must have distinct host/port combinations.');
        }
        $authorities[] = $authority;
    }
    return $urls;
}

function validateDatabase(): void {
    foreach (['DB_HOST', 'DB_USER', 'DB_PASS', 'DB_NAME'] as $name) {
        required($name);
    }
    $port = required('DB_PORT');
    if (!ctype_digit($port) || (int)$port < 1 || (int)$port > 65535) {
        fail('DB_PORT must be between 1 and 65535.');
    }
}

function wp(array $args): int {
    $command = array_merge(['wp', '--skip-plugins', '--skip-themes'], $args);
    passthru(implode(' ', array_map('escapeshellarg', $command)), $status);
    return $status;
}

function requireSingleSite(): void {
    $check = <<<'PHP'
if (is_multisite()) {
    WP_CLI::error('Multisite is not supported.');
}
PHP;
    if (wp(['eval', $check]) !== 0) {
        fail('Unable to load a single-site WordPress installation.');
    }
}
