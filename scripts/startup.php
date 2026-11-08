<?php
require __DIR__ . '/common.php';
validateDatabase();
domains();
$theme = required('ACTIVE_THEME');
if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_-]*$/D', $theme)) {
    fail('ACTIVE_THEME must be a theme directory slug.');
}
if (!is_file('/app/wp-load.php') || !is_file('/app/wp-config.php')) {
    fail('Mount WordPress at /app with an environment-aware wp-config.php.');
}

mysqli_report(MYSQLI_REPORT_OFF);
$deadline = microtime(true) + 60;
fwrite(STDOUT, "wordpress-dev: Waiting up to 60 seconds for the database...\n");
do {
    $db = mysqli_init();
    $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 2);
    if (@$db->real_connect(required('DB_HOST'), required('DB_USER'), required('DB_PASS'), required('DB_NAME'), (int)required('DB_PORT'))) {
        break;
    }
    if (microtime(true) >= $deadline) {
        fail('Database connection timed out. Check DB_* settings and database readiness.');
    }
    usleep(500000);
} while (true);

// Inspect tables separately: core is-installed also exits nonzero on configuration errors.
$tables = $db->query('SHOW TABLES');
if ($tables === false) {
    fail('Unable to inspect the configured database.');
}
if ($tables->num_rows === 0) {
    fwrite(STDOUT, "wordpress-dev: Database is empty; skipping migration. Import SQL and run /migrate-to-local, or complete WordPress setup.\n");
    exit(0);
}
$db->close();
if (wp(['core', 'is-installed']) !== 0) {
    fail('Database is not empty but WordPress is not installed or wp-config.php cannot connect.');
}
requireSingleSite();
passthru('/migrate-to-local', $status);
exit($status);
