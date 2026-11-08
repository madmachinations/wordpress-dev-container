<?php
require __DIR__ . '/../scripts/common.php';

function check(bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
putenv('PRODUCTION_DOMAIN=www.example.com');
putenv('STAGING_DOMAIN=https://staging.example.com/');
putenv('LOCAL_DOMAIN=wordpress.test:8080');
$urls = domains();
check($urls['PRODUCTION_DOMAIN'] === 'https://www.example.com', 'Production default scheme');
check($urls['LOCAL_DOMAIN'] === 'http://wordpress.test:8080', 'Local scheme and port');
putenv('LOCAL_DOMAIN=https://[::1]:8443/');
check(origin('LOCAL_DOMAIN') === 'https://[::1]:8443', 'IPv6 origin');
putenv('LOCAL_DOMAIN=wordpress.test:8080');

// Exercise the actual helper with a fake wp executable so argument escaping and
// the authority boundary are checked without needing a database.
$directory = sys_get_temp_dir() . '/wp-dev-domains-' . bin2hex(random_bytes(6));
mkdir($directory);
$log = $directory . '/calls';
file_put_contents($directory . '/wp', "#!/usr/bin/env php\n<?php file_put_contents(getenv('WP_TEST_LOG'), json_encode(array_slice(\$argv, 1)) . PHP_EOL, FILE_APPEND);\n");
chmod($directory . '/wp', 0755);
symlink(__DIR__ . '/../scripts/migrate', $directory . '/migrate-to-local');
putenv('PATH=' . $directory . ':' . getenv('PATH'));
putenv('WP_TEST_LOG=' . $log);
foreach (['DB_HOST=db', 'DB_PORT=3306', 'DB_NAME=wordpress', 'DB_USER=wordpress', 'DB_PASS=test'] as $setting) {
    putenv($setting);
}
try {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($directory . '/migrate-to-local') . ' --dry-run', $output, $status);
    check($status === 0, 'Migration helper succeeds');
    $calls = array_map(fn($line) => json_decode($line, true, flags: JSON_THROW_ON_ERROR), file($log, FILE_IGNORE_NEW_LINES));
    $replacements = array_values(array_filter($calls, fn($args) => in_array('search-replace', $args, true)));
    check(count($replacements) === 2, 'Both source environments converted');
    $args = $replacements[0];
    $pattern = $args[array_search('search-replace', $args, true) + 1];
    $regex = '~' . $pattern . '~i';
    foreach (['https://www.example.com/path', 'http://www.example.com?x=1', 'https://www.example.com', 'https://WWW.EXAMPLE.COM/#hash'] as $url) {
        check(preg_match($regex, $url) === 1, "Should replace $url");
    }
    foreach (['person@www.example.com', 'https://www.example.com.evil.test/path', 'https://www.example.com:9090/path'] as $value) {
        check(preg_match($regex, $value) === 0, "Should preserve $value");
    }
    check(in_array('--dry-run', $args, true), 'Dry-run forwarded');
    check(in_array('--skip-columns=guid', $args, true), 'GUID preservation');
    check(in_array('--all-tables-with-prefix', $args, true), 'Prefix scoping');
    foreach (['https://user:password@wordpress.test', 'https://wordpress.test/subdirectory',
        'https://wordpress.test?query=1', 'https://wordpress.test#fragment', 'https://invalid$host.test',
        'https://www.example.com', 'ftp://wordpress.test', 'http://wordpress.test:0'] as $invalid) {
        putenv('LOCAL_DOMAIN=' . $invalid);
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($directory . '/migrate-to-local') . ' --dry-run 2>/dev/null', $output, $status);
        check($status !== 0, "Reject invalid/duplicate origin: $invalid");
    }
} finally {
    foreach (glob($directory . '/*') as $path) {
        unlink($path);
    }
    rmdir($directory);
}
echo "Domain and migration argument checks passed.\n";
