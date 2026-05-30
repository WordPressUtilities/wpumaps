<?php
defined('ABSPATH') || die;

$total_markers = wp_count_posts('map_markers');
if (empty($total_markers->publish) && empty($total_markers->draft)) {
    echo wpautop(esc_html__('No marker found to export.', 'wpumaps'));
    return;
}

echo wpautop(__('Export all your markers in a CSV file. The exported file contains the marker name, coordinates, address and popup content.', 'wpumaps'));
echo wpautop(__('This file can be used to import your markers. The uniqid field is used to uniquely identify each marker and to allow updates during import.', 'wpumaps'));
echo '<p>';
echo '<label for="wpumaps_export_categories">' . esc_html__('Export only markers from category:', 'wpumaps') . '</label><br />';
echo '<select name="wpumaps_export_categories" id="wpumaps_export_categories">';
echo '<option value="">' . esc_html__('All categories', 'wpumaps') . '</option>';
$categories = get_terms(array(
    'taxonomy' => 'marker_categories',
    'hide_empty' => false
));
foreach ($categories as $category) {
    echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . ' (' . esc_html($category->count) . ')</option>';
}
echo '</select>';
echo '</p>';
submit_button(__('Export all markers', 'wpumaps'), 'primary', 'wpumaps_export_markers');
