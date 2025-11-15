<?php
defined('ABSPATH') || die;

/* ----------------------------------------------------------
  Wrapper
---------------------------------------------------------- */

echo '<div class="wpumaps__wrapper" data-wpumaps="' . esc_attr($map_id) . '">';
echo '<div id="mymap" class="wpumaps__map"></div>';
echo '</div>';

/* ----------------------------------------------------------
  Map content
---------------------------------------------------------- */

add_action('wp_footer', function () use ($map_id) {
    echo '<script class="wpumaps__data">';
    echo 'window.wpumaps = window.wpumaps || [];';
    echo 'window.wpumaps.push({';
    echo 'map_id: ' . esc_js($map_id) . ',';
    echo 'map_details: ' . wp_json_encode($this->get_map_details($map_id)) . ',';
    echo 'markers: ' . wp_json_encode($this->get_markers($map_id)) . '';
    echo '});';
    echo '</script>';
});
