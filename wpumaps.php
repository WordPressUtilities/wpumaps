<?php
/*
Plugin Name: WPU Maps
Plugin URI: https://github.com/WordPressUtilities/wpumaps
Update URI: https://github.com/WordPressUtilities/wpumaps
Description: Simple maps for your website
Version: 0.9.0
Author: Darklg
Author URI: https://darklg.me/
Text Domain: wpumaps
Domain Path: /lang
Requires at least: 6.2
Requires PHP: 8.0
Network: Optional
License: MIT License
License URI: https://opensource.org/licenses/MIT
*/

if (!defined('ABSPATH')) {
    exit();
}

class WPUMaps {
    private $plugin_version = '0.9.0';
    private $plugin_settings = array(
        'id' => 'wpumaps',
        'name' => 'WPU Maps'
    );
    private $basetoolbox;
    private $basefields;
    private $basefilecache;
    private $settings;
    private $settings_obj;
    private $settings_details;

    # https://docs.mapbox.com/mapbox-gl-js/guides/install/#import-or-install-mapbox-gl-js
    private $mapbox_version = 'v3.18.0';
    # https://docs.mapbox.com/mapbox-search-js/guides/autofill/web/#installation-when-using-the-mapbox-cdn
    private $mapbox_autofill_version = 'v1.5.0';
    private $plugin_description;

    public function __construct() {
        add_action('init', array(&$this, 'register_entities'));
        add_action('init', array(&$this, 'load_filecache'));
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_fields'));
        add_action('init', array(&$this, 'load_settings'));

        /* Menu */
        add_action('admin_menu', array(&$this, 'admin_menu'));
        add_action('admin_head-post-new.php', array($this, 'admin_head'));
        add_action('admin_head-post.php', array($this, 'admin_head'));
        add_action('admin_head-edit.php', array($this, 'admin_head'));
        add_action('admin_head-edit-tags.php', array($this, 'admin_head'));
        add_action('admin_head-term.php', array($this, 'admin_head'));

        /* Security */
        add_filter('wp_insert_post_data', array(&$this, 'wp_insert_post_data__map_markers'), 10, 2);
        add_action('admin_notices', array(&$this, 'admin_notices__map_markers'));

        /* Cache */
        add_action('save_post', array(&$this, 'save_post_maps'), 999, 3);
        add_action('save_post', array(&$this, 'save_post_map_markers'), 999, 3);
        add_action('saved_marker_categories', array(&$this, 'generate_cache'), 999, 3);

        /* Assets */
        add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array(&$this, 'wp_enqueue_scripts'));

        /* Shortcode */
        add_shortcode('wpumaps_map', array($this, 'display_map'));

        /* Preview */
        add_action('add_meta_boxes', array($this, 'add_map_metabox'));
        add_action('template_redirect', array($this, 'preview_map'));
    }

    public function load_filecache() {
        require_once __DIR__ . '/inc/WPUBaseFileCache/WPUBaseFileCache.php';
        $this->basefilecache = new \wpumaps\WPUBaseFileCache('wpumaps');
    }

    public function load_toolbox() {
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpumaps\WPUBaseToolbox(array(
            'need_form_js' => false
        ));
    }

    public function load_fields() {
        $field_lat_lng = array(
            'type' => 'group',
            'sub_fields' => array(
                'address' => array(
                    'label' => __('Address', 'wpumaps')
                ),
                'lat' => array(
                    'column_start' => true,
                    'label' => __('Latitude', 'wpumaps'),
                    'type' => 'number',
                    'extra_attributes' => array(
                        'step' => 'any'
                    )
                ),
                'lng' => array(
                    'label' => __('Longitude', 'wpumaps'),
                    'column_end' => true,
                    'type' => 'number',
                    'extra_attributes' => array(
                        'step' => 'any'
                    )
                )
            )
        );

        $fields = array();
        $field_groups = array();

        /* MAP */
        $field_groups['maps'] = array(
            'label' => __('Coordinates', 'wpumaps'),
            'post_type' => array('maps')
        );
        $fields['map_enable_autocenter'] = array(
            'label' => __('Enable auto center', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, the map will automatically center on the markers.', 'wpumaps'),
            'group' => 'maps'
        );
        $fields['map_zoom'] = array(
            'label' => __('Zoom level', 'wpumaps'),
            'type' => 'number',
            'group' => 'maps',
            'toggle-display' => array(
                'map_enable_autocenter' => 'notchecked'
            ),
            'extra_attributes' => array(
                'step' => '1',
                'min' => '0',
                'max' => '22'
            )
        );
        $fields['map_lat_lng'] = array_merge(
            $field_lat_lng,
            array(
                'group' => 'maps',
                'toggle-display' => array(
                    'map_enable_autocenter' => 'notchecked'
                )
            )
        );
        $fields['map_style'] = array(
            'label' => __('Map style', 'wpumaps'),
            'type' => 'select',
            'group' => 'maps',
            'data' => array(
                'streets-v11' => 'Streets',
                'outdoors-v11' => 'Outdoors',
                'light-v10' => 'Light',
                'dark-v10' => 'Dark',
                'satellite-v9' => 'Satellite',
                'satellite-streets-v11' => 'Satellite Streets',
                'navigation-day-v1' => 'Navigation Day',
                'navigation-night-v1' => 'Navigation Night'
            )
        );

        $fields['map_scrollwheel_enable'] = array(
            'label' => __('Enable scroll zoom', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, users will be able to zoom the map using their mouse scroll wheel.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $map_categories = get_terms(array(
            'taxonomy' => 'marker_categories',
            'hide_empty' => false
        ));
        if (!empty($map_categories)) {
            $field_groups['maps_settings'] = array(
                'label' => __('Settings', 'wpumaps'),
                'post_type' => array('maps')
            );

            $categories = array();
            foreach ($map_categories as $category) {
                $categories[$category->term_id] = $category->name . ' (' . $category->count . ')';
            }
            $fields['map_categories'] = array(
                'label' => __('Marker Categories', 'wpumaps'),
                'type' => 'checkboxes',
                'taxonomy' => 'marker_categories',
                'help' => __('Select the categories of markers to display on this map. If none selected, all categories will be displayed.', 'wpumaps'),
                'group' => 'maps_settings',
                'data' => $categories
            );
        }

        /* MARKERS */
        $field_groups['markers'] = array(
            'label' => __('Coordinates', 'wpumaps'),
            'post_type' => array('map_markers')
        );
        $field_groups['markers_category'] = array(
            'label' => __('Settings', 'wpumaps'),
            'taxonomy' => array('marker_categories')
        );
        $field_groups['markers_popup'] = array(
            'label' => __('Popup', 'wpumaps'),
            'post_type' => array('map_markers')
        );
        $fields['marker_lat_lng'] = array_merge(
            $field_lat_lng,
            array(
                'group' => 'markers'
            )
        );
        $fields['marker_icon'] = array(
            'label' => __('Icon', 'wpumaps'),
            'type' => 'image',
            'group' => 'markers'
        );
        $fields['marker_icon_category'] = array(
            'label' => __('Icon', 'wpumaps'),
            'type' => 'image',
            'group' => 'markers_category'
        );
        $fields['marker_popup_image'] = array(
            'label' => __('Image', 'wpumaps'),
            'type' => 'image',
            'group' => 'markers_popup'
        );
        $fields['marker_popup_title'] = array(
            'label' => __('Title', 'wpumaps'),
            'group' => 'markers_popup'
        );
        $fields['marker_popup_content'] = array(
            'label' => __('Content', 'wpumaps'),
            'type' => 'textarea',
            'group' => 'markers_popup'
        );
        require_once __DIR__ . '/inc/WPUBaseFields/WPUBaseFields.php';
        $this->basefields = new \wpumaps\WPUBaseFields($fields, $field_groups);
    }

    public function load_settings() {
        $this->settings_details = array(
            # Admin page
            'create_page' => true,
            'plugin_basename' => plugin_basename(__FILE__),
            'plugin_name' => $this->plugin_settings['name'],
            'menu_name' => __('Settings', 'wpumaps'),
            'parent_page' => 'edit.php?post_type=maps',
            'parent_page_url' => 'edit.php?post_type=maps',
            'plugin_id' => $this->plugin_settings['id'],
            'option_id' => $this->plugin_settings['id'] . '_options',
            'sections' => array(
                'mapbox' => array(
                    'name' => __('Mapbox', 'wpumaps')
                )
            )
        );
        $has_api_key = defined('WPUMAPS_MAPBOX_KEY') && !empty(WPUMAPS_MAPBOX_KEY);
        $this->settings = array(
            'mapbox_key' => array(
                'label' => __('Mapbox Key', 'wpumaps'),
                'readonly' => $has_api_key,
                'help' => $has_api_key ? __('This key is defined in wp-config.php and cannot be changed here.', 'wpumaps') : '<span class="wpumaps-mapbox-key-help"></span>'
            )
        );
        require_once __DIR__ . '/inc/WPUBaseSettings/WPUBaseSettings.php';
        $this->settings_obj = new \wpumaps\WPUBaseSettings($this->settings_details, $this->settings);
    }

    public function register_entities() {
        # MAPS
        register_post_type('maps', array(
            'public' => true,
            'show_in_admin_bar' => false,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'publicly_queryable' => false,
            'label' => __('Maps', 'wpumaps'),
            'menu_icon' => 'dashicons-location-alt',
            'supports' => array('title'),
            'labels' => array(
                'all_items' => __('All Maps', 'wpumaps'),
                'add_new_item' => __('Add New Map', 'wpumaps'),
                'edit_item' => __('Edit Map', 'wpumaps'),
                'new_item' => __('New Map', 'wpumaps'),
                'view_item' => __('View Map', 'wpumaps'),
                'search_items' => __('Search Maps', 'wpumaps'),
                'not_found' => __('No maps found', 'wpumaps'),
                'not_found_in_trash' => __('No maps found in Trash', 'wpumaps')
            )
        ));
        # MARKERS
        register_post_type('map_markers', array(
            'public' => true,
            'show_in_admin_bar' => false,
            'show_in_menu' => true,
            'show_in_nav_menus' => false,
            'show_in_rest' => false,
            'rewrite' => false,
            'publicly_queryable' => false,
            'label' => __('Markers', 'wpumaps'),
            'menu_icon' => 'dashicons-location-alt',
            'supports' => array('title'),
            'labels' => array(
                'all_items' => __('All Markers', 'wpumaps'),
                'add_new_item' => __('Add New Marker', 'wpumaps'),
                'edit_item' => __('Edit Marker', 'wpumaps'),
                'new_item' => __('New Marker', 'wpumaps'),
                'view_item' => __('View Marker', 'wpumaps'),
                'search_items' => __('Search Markers', 'wpumaps'),
                'not_found' => __('No markers found', 'wpumaps'),
                'not_found_in_trash' => __('No markers found in Trash', 'wpumaps')
            )
        ));
        # MARKER CATEGORIES
        register_taxonomy('marker_categories', 'map_markers', array(
            'label' => __('Marker Categories', 'wpumaps'),
            'hierarchical' => true,
            'public' => true,
            'publicly_queryable' => false,
            'show_admin_column' => true
        ));
    }

    /* ----------------------------------------------------------
      MENUS
    ---------------------------------------------------------- */

    public function admin_menu() {
        add_submenu_page(
            'edit.php?post_type=maps',
            __('Markers', 'wpumaps'),
            __('Markers', 'wpumaps'),
            'edit_posts',
            'edit.php?post_type=map_markers',
            null,
            1
        );
        add_submenu_page(
            'edit.php?post_type=maps',
            __('Categories', 'wpumaps'),
            __('Categories', 'wpumaps'),
            'manage_categories',
            'edit-tags.php?taxonomy=marker_categories&post_type=map_markers'
        );
        remove_menu_page('edit.php?post_type=map_markers');
        remove_submenu_page('edit.php?post_type=maps', 'post-new.php?post_type=maps');

    }

    // Highlight markers submenu
    public function admin_head($submenu_file) {
        global $current_screen, $parent_file, $submenu_file;
        if ($current_screen instanceof WP_Screen && $current_screen->post_type === 'map_markers') {
            $parent_file = 'edit.php?post_type=maps';
            $submenu_file = 'edit.php?post_type=map_markers';
        }
    }

    /* ----------------------------------------------------------
      ADMIN
    ---------------------------------------------------------- */

    public function get_mapbox_key() {
        $mapbox_key = apply_filters('wpumaps_mapbox_key', $this->settings_obj->get_setting('mapbox_key'));
        if (defined('WPUMAPS_MAPBOX_KEY') && !empty(WPUMAPS_MAPBOX_KEY)) {
            $mapbox_key = WPUMAPS_MAPBOX_KEY;
        }
        return $mapbox_key;
    }

    public function admin_enqueue_scripts() {
        /* Back Style */
        wp_register_style('wpumaps_back_style', plugins_url('assets/back.css', __FILE__), array(), $this->plugin_version);
        wp_enqueue_style('wpumaps_back_style');
        /* Back Script */
        wp_register_script('wpumaps_back_script', plugins_url('assets/back.js', __FILE__), array(), $this->plugin_version, true);
        wp_localize_script('wpumaps_back_script', 'wpumaps_admin_settings', array(
            'mapbox_version' => $this->mapbox_version,
            'mapbox_text_valid' => __('Valid', 'wpumaps'),
            'mapbox_text_invalid' => __('Invalid', 'wpumaps'),
            'mapbox_key_help_empty_text' => sprintf(__('If you do not have a Mapbox key, you can get one for free at %s', 'wpumaps'), 'https://www.mapbox.com/'),
            'mapbox_key_help_filled_text' => sprintf(__('Test this API Key : %s', 'wpumaps'), '<button>Test</button>'),
            'mapbox_autofill_version' => $this->mapbox_autofill_version,
            'mapbox_key' => $this->get_mapbox_key()
        ));
        wp_enqueue_script('wpumaps_back_script');
    }

    public function wp_enqueue_scripts() {

        /* Front Style */
        wp_register_style('wpumaps_front_style', plugins_url('assets/front.css', __FILE__), array(), $this->plugin_version);
        wp_enqueue_style('wpumaps_front_style');
        /* Front Script with localization / variables */
        wp_register_script('wpumaps_front_script', plugins_url('assets/front.js', __FILE__), array(), $this->plugin_version, true);
        wp_localize_script('wpumaps_front_script', 'wpumaps_settings', array(
            'mapbox_version' => $this->mapbox_version,
            'mapbox_key' => $this->get_mapbox_key()
        ));
        wp_enqueue_script('wpumaps_front_script');
    }

    /* ----------------------------------------------------------
      GET MAP
    ---------------------------------------------------------- */

    public function get_markers_from_map($args) {
        $q = array(
            'post_type' => 'map_markers',
            'posts_per_page' => -1
        );
        if (!is_array($args)) {
            $args = array();
        }
        $selected_categories = array();
        if (isset($args['map_id'])) {
            $selected_categories = get_post_meta($args['map_id'], 'map_categories', 1);
        }
        if (isset($args['categories'])) {
            $selected_categories = $args['categories'];
        }
        if (isset($args['marker_id'])) {
            $q['post__in'] = array($args['marker_id']);
        }
        if (!is_array($selected_categories)) {
            $selected_categories = array();
        }
        if (!empty($selected_categories)) {
            $selected_categories = array_map('intval', $selected_categories);
            $q['tax_query'] = array(
                array(
                    'taxonomy' => 'marker_categories',
                    'field' => 'term_id',
                    'terms' => $selected_categories
                )
            );
        }
        return $this->get_markers_from_query(get_posts($q), array(
            'selected_categories' => $selected_categories
        ));
    }

    public function get_markers_from_query($markers, $args = array()) {
        $markers_data = array();
        foreach ($markers as $marker) {
            $markers_data[] = $this->get_marker($marker, $args);
        }
        return $markers_data;
    }

    public function get_marker($marker, $args = array()) {
        /* Popup */
        $popup_content_image = '';
        $popup_image_id = get_post_meta($marker->ID, 'marker_popup_image', 1);
        if ($popup_image_id) {
            $popup_content_image = wp_get_attachment_image_url($popup_image_id, 'medium');
        }

        $popup_content_html = '';
        $popup_title = get_post_meta($marker->ID, 'marker_popup_title', 1);
        if ($popup_title) {
            $popup_title = trim(esc_html($popup_title));
            if ($popup_title) {
                $popup_content_html .= '<h3>' . $popup_title . '</h3>';
            }
        }
        $popup_content = get_post_meta($marker->ID, 'marker_popup_content', 1);
        if ($popup_content) {
            $popup_content = trim(esc_html($popup_content));
            if ($popup_content) {
                $popup_content_html .= wpautop($popup_content);
            }
        }

        $marker_data = array(
            'name' => get_the_title($marker),
            'lat' => (get_post_meta($marker->ID, 'marker_lat_lng__lat', 1)),
            'lng' => (get_post_meta($marker->ID, 'marker_lat_lng__lng', 1))
        );
        /* Icon */
        $marker_icon_url = $this->get_marker_icon_url($marker->ID, isset($args['selected_categories']) ? $args['selected_categories'] : array());
        if ($marker_icon_url) {
            $marker_data['icon_url'] = $marker_icon_url;
        }
        if ($popup_content_html) {
            $marker_data['popup_content_html'] = $popup_content_html;
        }
        if ($popup_content_image) {
            $marker_data['popup_content_image'] = $popup_content_image;
        }

        return $marker_data;
    }

    private function get_marker_icon_url($marker_id, $selected_categories = array()) {
        $icon_size = apply_filters('wpumaps_marker_icon_size', 'medium');
        $marker_icon_url = '';
        $marker_icon_id = get_post_meta($marker_id, 'marker_icon', 1);
        if ($marker_icon_id) {
            return wp_get_attachment_image_url($marker_icon_id, $icon_size);
        }
        $categories = get_the_terms($marker_id, 'marker_categories');
        if (!$categories || is_wp_error($categories)) {
            return $marker_icon_url;
        }
        foreach ($categories as $category) {
            if (!in_array($category->term_id, $selected_categories)) {
                continue;
            }
            $category_icon_id = get_term_meta($category->term_id, 'marker_icon_category', 1);
            if ($category_icon_id) {
                return wp_get_attachment_image_url($category_icon_id, $icon_size);
            }
        }

        return $marker_icon_url;
    }

    public function get_map_details($map_id) {
        $autocenter = get_post_meta($map_id, 'map_enable_autocenter', 1);
        $map_details = array(
            'zoom' => 0,
            'lat' => 0,
            'lng' => 0
        );
        if (!$autocenter) {
            $map_details['zoom'] = intval(get_post_meta($map_id, 'map_zoom', 1));
            $map_details['lat'] = floatval(get_post_meta($map_id, 'map_lat_lng__lat', 1));
            $map_details['lng'] = floatval(get_post_meta($map_id, 'map_lat_lng__lng', 1));
        }
        $map_details['style'] = get_post_meta($map_id, 'map_style', 1);
        $map_details['scrollwheel_enable'] = get_post_meta($map_id, 'map_scrollwheel_enable', 1) ? true : false;

        return $map_details;
    }

    public function get_map_data($atts) {
        $map_id = 'map_' . md5(json_encode($atts));

        $map_details = array();
        $markers = array();
        $map_init = false;

        if (isset($atts['id']) && !empty($atts['id'])) {
            $data = $this->basefilecache->get_cache('map_' . $atts['id'], 0);
            if ($data && (!isset($atts['nocache']) || !$atts['nocache'])) {
                return $data;
            }
            $map_details = $this->get_map_details($atts['id']);
            $markers = $this->get_markers_from_map(array('map_id' => $atts['id']));
            $map_init = true;
        }

        if (isset($atts['categories']) && !empty($atts['categories'])) {
            $markers = $this->get_markers_from_map(array(
                'categories' => explode(',', $atts['categories'])
            ));
            $map_init = true;
        }
        if (isset($atts['marker_id']) && !empty($atts['marker_id'])) {
            $markers = $this->get_markers_from_map(array('marker_id' => $atts['marker_id']));
            $map_init = true;
        }

        if (!$map_init) {
            error_log('WPUMaps: No map ID, categories or marker ID provided for map display.');
            return '';
        }

        return array(
            'map_id' => $map_id,
            'map_details' => $map_details,
            'markers' => $markers
        );
    }

    public function display_map($atts) {
        $map_data = false;
        if (isset($atts['file']) && is_readable($atts['file'])) {
            $validated_file_path = $this->validate_map_file_path($atts['file']);
            if ($validated_file_path) {
                $map_data = unserialize(file_get_contents($validated_file_path));
            }
        }

        if (!is_array($map_data)) {
            $map_data = $this->get_map_data($atts);
        }

        if (!$map_data) {
            return '';
        }

        add_action('wp_footer', function () use ($map_data) {
            echo '<script class="wpumaps__data">';
            echo 'window.wpumaps = window.wpumaps || [];';
            echo 'window.wpumaps.push(' . json_encode($map_data) . ');';
            echo '</script>';
        });

        /* Wrapper */
        $html = '<div class="wpumaps__wrapper" data-wpumaps="' . esc_attr($map_data['map_id']) . '">';
        $html .= '<div class="wpumaps__map"></div>';
        $html .= '</div>';

        return $html;

    }

    /* ----------------------------------------------------------
      Cache
    ---------------------------------------------------------- */

    /**
     * Ensure that a file path is valid and allowed
     */
    public function validate_map_file_path($file_path) {

        /* File should exists */
        $file_path = realpath($file_path);
        if ($file_path === false || !is_readable($file_path)) {
            return false;
        }

        /* File should be in cache dir */
        $real_base = realpath(WP_CONTENT_DIR . '/cache/');
        if (!str_starts_with($file_path, $real_base)) {
            return false;
        }

        /* File should be in a valid dir */
        $relative_file_path = str_replace($real_base, '', $file_path);
        if (!preg_match('#^/wpumaps/map_([0-9]+)$#', $relative_file_path) && !preg_match('#^/site_([0-9]+)/wpumaps/map_([0-9]+)$#', $relative_file_path)) {
            return false;
        }

        if (strpos($relative_file_path, '.') !== false || strpos($relative_file_path, 'wpumaps/map_') === false) {
            return false;
        }

        return $file_path;

    }

    /* Create cache */

    public function save_post_maps($post_ID, $post, $update) {
        if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'maps') {
            return;
        }
        $this->generate_cache(array($post_ID));
    }

    public function save_post_map_markers($post_ID, $post, $update) {
        if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'map_markers') {
            return;
        }
        $this->generate_cache(array());
    }

    public function generate_cache($cache_to_generate) {
        if (empty($cache_to_generate) || !is_array($cache_to_generate)) {
            $cache_to_generate = get_posts(array(
                'post_type' => 'maps',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ));
        }

        foreach ($cache_to_generate as $map_id) {
            // Trigger cache generation
            $data = $this->get_map_data(array('id' => $map_id, 'nocache' => true));
            $this->basefilecache->set_cache('map_' . $map_id, $data);
        }

    }

    /* ----------------------------------------------------------
      Validate markers
    ---------------------------------------------------------- */

    /* Prevent publishing a marker if it doesn't have valid coordinates */
    public function wp_insert_post_data__map_markers($data, $postarr) {
        if (!isset($postarr['post_type']) || $postarr['post_type'] != 'map_markers' || $data['post_status'] != 'publish' || !is_numeric($postarr['ID'])) {
            return $data;
        }

        $lng = isset($postarr['ID']) ? get_post_meta($postarr['ID'], 'marker_lat_lng__lng', true) : '';
        $lat = isset($postarr['ID']) ? get_post_meta($postarr['ID'], 'marker_lat_lng__lat', true) : '';

        if (isset($_POST['wpubasefields_marker_lat_lng__lng'])) {
            $lng = $_POST['wpubasefields_marker_lat_lng__lng'];
        }
        if (isset($_POST['wpubasefields_marker_lat_lng__lat'])) {
            $lat = $_POST['wpubasefields_marker_lat_lng__lat'];
        }

        if (empty($lng) || empty($lat)) {
            $data['post_status'] = 'draft';
            set_transient('wpumaps_missing_lng_notice_' . $postarr['ID'], true, 60);
        }

        return $data;
    }

    /* Notice if a marker could not be published */
    public function admin_notices__map_markers() {
        global $pagenow, $post;
        if ($pagenow != 'post.php' || !isset($post) || $post->post_type != 'map_markers' || $post->post_status != 'draft') {
            return;
        }
        $transient_key = 'wpumaps_missing_lng_notice_' . $post->ID;
        if (get_transient($transient_key)) {
            delete_transient($transient_key);
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Marker could not be published because coordinates are invalid.', 'wpumaps') . '</p></div>';
        }
    }

    /* ----------------------------------------------------------
      Preview
    ---------------------------------------------------------- */

    public function add_map_metabox() {
        $current_screen = get_current_screen();
        if (!$current_screen || !in_array($current_screen->post_type, array('maps'))) {
            return;
        }
        if (isset($current_screen->action) && $current_screen->action == 'add') {
            return;
        }
        add_meta_box(
            'wpumaps_map_preview',
            __('Preview', 'wpumaps'),
            function ($post) {
                $preview_url = add_query_arg(array(
                    'wpumaps_preview_map' => $post->ID
                ), home_url('/'));
                echo '<a href="' . esc_url($preview_url) . '" target="_blank" class="button">' . esc_html(__('Preview saved map', 'wpumaps')) . '</a>';
            },
            'maps',
            'side'
        );
    }

    public function preview_map() {
        if (!is_user_logged_in() || !isset($_GET['wpumaps_preview_map']) || !is_numeric($_GET['wpumaps_preview_map']) || !current_user_can('edit_posts')) {
            return;
        }
        wp_head();
        echo '<div class="wpumaps-preview-wrapper">' . $this->display_map(array('id' => intval($_GET['wpumaps_preview_map']))) . '</div>';
        wp_footer();
        exit;
    }

}

$WPUMaps = new WPUMaps();
