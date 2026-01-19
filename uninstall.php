<?php
defined('ABSPATH') || die;
if (!defined('WP_UNINSTALL_PLUGIN')) {
    die;
}

/* Delete options */
$options = array(
    'wpumaps_options',
);
foreach ($options as $opt) {
    delete_option($opt);
    delete_site_option($opt);
}

/* Delete all posts */
$allposts = get_posts(array(
    'post_type' => array('maps', 'map_markers'),
    'numberposts' => -1,
    'fields' => 'ids'
));
foreach ($allposts as $p) {
    wp_delete_post($p, true);
}

/* Delete all terms */
$taxonomy = 'marker_categories';
$terms = get_terms($taxonomy, array('hide_empty' => false));
foreach ($terms as $term) {
    wp_delete_term($term->term_id, $taxonomy);
}
