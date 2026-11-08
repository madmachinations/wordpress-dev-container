<?php
require __DIR__ . '/common.php';
$action = $argv[1] ?? '';
$slug = $argv[2] ?? '';
$theme = required('ACTIVE_THEME');
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $theme)
    || !preg_match('/^[a-z][a-z0-9]*(?:-[a-z0-9]+)*$/D', $slug)) {
    fail('Use a theme directory slug and a lowercase block slug such as hero-banner.');
}
$themesPath = realpath('/app/wp-content/themes');
$themePath = realpath('/app/wp-content/themes/' . $theme);
if ($themesPath === false || $themePath === false || !str_starts_with($themePath, $themesPath . '/')) {
    fail("Theme $theme is missing or resolves outside the themes directory.");
}
$blocksPath = $themePath . '/blocks';
if (is_link($blocksPath) || (file_exists($blocksPath) && !is_dir($blocksPath))) {
    fail('Theme blocks path must be a directory, not a symlink or file.');
}
$destination = $blocksPath . '/' . $slug;
if ($action === 'locate') {
    if (is_link($destination) || !is_dir($destination) || !is_file($destination . '/package.json')) {
        fail("No block project found at $destination.");
    }
    echo $destination;
    exit(0);
}
if ($action !== 'create') {
    fail('Unknown block action.');
}
if (file_exists($destination) || is_link($destination)) {
    fail("$destination already exists; nothing was overwritten.");
}
if (!is_writable($themePath)) {
    fail('Theme directory is not writable by the current container user.');
}
if (!is_dir($blocksPath) && !mkdir($blocksPath, 0777, true)) {
    fail('Cannot create theme blocks directory.');
}
if (!mkdir($destination)) {
    fail('Cannot create block directory.');
}
$template = '/usr/local/share/wordpress-dev/block';
$tokens = [
    '__SLUG__' => $slug,
    '__NAMESPACE__' => strtolower(str_replace('_', '-', $theme)),
    '__TITLE__' => ucwords(str_replace('-', ' ', $slug)),
];
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($template, FilesystemIterator::SKIP_DOTS));
foreach ($files as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = substr($file->getPathname(), strlen($template) + 1);
    $target = $destination . '/' . $relative;
    if (!is_dir(dirname($target)) && !mkdir(dirname($target), 0777, true)) {
        fail('Cannot create block source directory.');
    }
    if (file_put_contents($target, strtr(file_get_contents($file->getPathname()), $tokens)) === false) {
        fail('Cannot write block source.');
    }
}
echo "Created $destination\n";
echo "Register this block in your theme's functions.php (after building):\n\n";
echo "add_action( 'init', function () {\n";
echo "    register_block_type( get_stylesheet_directory() . '/blocks/$slug/build' );\n";
echo "} );\n\n";
echo "Installing pinned dependencies and creating package-lock.json...\n";
passthru('cd ' . escapeshellarg($destination) . ' && npm install --no-audit --no-fund && npm run build', $status);
if ($status !== 0) {
    fail("Block files were created, but dependency installation/build failed. Retry npm install and npm run build inside $destination; do not rerun create-block.");
}
