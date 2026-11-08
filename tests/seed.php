<?php
global $wpdb;
global $wp_rewrite;
$wp_rewrite->set_permalink_structure('/%postname%/');
$wp_rewrite->flush_rules(false);
update_option('migration_fixture', [
    'production' => 'https://www.example.com/a',
    'production_http' => 'http://www.example.com/b',
    'staging' => 'https://staging.example.com/c',
    'nested' => ['value' => 'http://staging.example.com/d'],
    'email' => 'person@www.example.com',
    'other_host' => 'https://www.example.com.evil.test/a',
    'other_port' => 'https://www.example.com:9090/a',
]);
$post = wp_insert_post([
    'post_title' => 'Migration fixture',
    'post_status' => 'publish',
    'post_content' => 'https://www.example.com/post https://staging.example.com/post',
    'guid' => 'https://www.example.com/?p=fixture',
]);
update_option('migration_fixture_post', $post);
$wpdb->query("CREATE TABLE wp_plugin_fixture (id INT PRIMARY KEY, value LONGTEXT NOT NULL)");
$wpdb->insert('wp_plugin_fixture', ['id' => 1, 'value' => serialize(['url' => 'https://staging.example.com/plugin'])]);
$wpdb->query("CREATE TABLE unrelated_fixture (id INT PRIMARY KEY, value LONGTEXT NOT NULL)");
$wpdb->insert('unrelated_fixture', ['id' => 1, 'value' => 'https://www.example.com/untouched']);
