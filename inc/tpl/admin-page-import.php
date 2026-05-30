<?php
defined('ABSPATH') || die;

$example_file = base64_encode(file_get_contents(dirname(__DIR__) . '/example-markers.csv'));
/* Import */
echo wpautop(__('Import markers from a CSV file. The file should contain the marker name, coordinates, address and popup content.', 'wpumaps'));
echo wpautop(__('The uniqid field is used to uniquely identify each marker and to allow updates during import. If a marker with the same uniqid already exists, it will be updated instead of creating a new one.', 'wpumaps'));
echo wpautop(__('New marker are created with the "draft" status, so you can review them before publishing.', 'wpumaps'));
echo '<input required type="file" name="wpumaps_import_file" accept=".csv" />';
echo '<p>';
submit_button(__('Import markers', 'wpumaps'), 'primary', 'wpumaps_import_markers', false);
echo ' <a href="' . esc_attr('data:text/csv;base64,' . $example_file) . '" class="button" download="example-markers.csv">' . esc_html(__('Example file', 'wpumaps')) . '</a>';
echo '</p>';

/* Find markers without lat or lng */
$markers_without_coordinates = $this->get_markers_without_coordinates();
if (!empty($markers_without_coordinates)) {
    echo '<hr />';
    echo '<h2>' . esc_html__('Markers with missing coordinates', 'wpumaps') . '</h2>';
    echo '<ul>';
    foreach ($markers_without_coordinates as $marker) {
        $edit_link = get_edit_post_link($marker->ID);
        echo '<li><a href="' . esc_url($edit_link) . '">' . esc_html(get_the_title($marker)) . '</a></li>';
    }
    echo '</ul>';
    submit_button(__('Geocode markers with missing coordinates', 'wpumaps'), 'secondary', 'wpumaps_geocode_markers', true, array(
        'formnovalidate' => 'formnovalidate'
    ));
}
