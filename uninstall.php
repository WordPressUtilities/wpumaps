<?php
defined('ABSPATH') || die;
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/* Delete options */
$options = array(
    'wpumaps__cron_hook_croninterval',
    'wpumaps__cron_hook_lastexec',
    'wpumaps_options',
    'wpumaps_wpumaps_version'
);
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}

/* Delete tables */
global $wpdb;
$wpdb->query("DROP TABLE IF EXISTS {$wpdb->prefix}wpumaps");

/* Delete all posts */
$allposts = get_posts(array(
    'post_type' => 'wpumaps',
    'numberposts' => -1,
    'fields' => 'ids'
));
foreach ($allposts as $p) {
    wp_delete_post($p, true);
}

/* Delete all terms */
$taxonomy = 'your_taxonomy';
$terms = get_terms($taxonomy, array('hide_empty' => false));
foreach ($terms as $term) {
    wp_delete_term($term->term_id, $taxonomy);
}
