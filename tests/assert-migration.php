<?php
global $wpdb;
$target = getenv('EXPECTED_ORIGIN') ?: 'http://wordpress.test:8080';
function verify($actual, $expected, $label) {
    if ($actual !== $expected) {
        WP_CLI::error("$label: expected " . var_export($expected, true) . ', got ' . var_export($actual, true));
    }
}
$fixture = get_option('migration_fixture');
verify($fixture['production'], "$target/a", 'Production URL');
verify($fixture['production_http'], "$target/b", 'HTTP variant');
verify($fixture['staging'], "$target/c", 'Staging URL');
verify($fixture['nested']['value'], "$target/d", 'Nested serialized value');
verify($fixture['email'], 'person@www.example.com', 'Email');
verify($fixture['other_host'], 'https://www.example.com.evil.test/a', 'Longer hostname');
verify($fixture['other_port'], 'https://www.example.com:9090/a', 'Other port');
$post = get_post(get_option('migration_fixture_post'));
verify($post->guid, 'https://www.example.com/?p=fixture', 'GUID');
verify($post->post_content, "$target/post $target/post", 'Post content');
$plugin = unserialize($wpdb->get_var('SELECT value FROM wp_plugin_fixture WHERE id=1'));
verify($plugin['url'], "$target/plugin", 'Plugin table serialized value');
verify($wpdb->get_var('SELECT value FROM unrelated_fixture WHERE id=1'), 'https://www.example.com/untouched', 'Unrelated table');
verify(get_option('home'), $target, 'Home URL');
verify(get_option('siteurl'), $target, 'Site URL');
WP_CLI::success('Migration assertions passed.');
