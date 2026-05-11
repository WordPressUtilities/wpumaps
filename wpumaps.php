<?php
/*
Plugin Name: WPU Maps
Plugin URI: https://github.com/WordPressUtilities/wpumaps
Update URI: https://github.com/WordPressUtilities/wpumaps
Description: Simple maps for your website
Version: 0.15.5
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
    private $plugin_version = '0.15.5';
    private $plugin_settings = array(
        'user_capability' => 'edit_others_posts',
        'id' => 'wpumaps',
        'name' => 'WPU Maps'
    );
    private $basetoolbox;
    private $basefilecache;
    private $baseadminpages;
    private $messages;
    private $settings;
    private $settings_obj;
    private $settings_details;

    # https://docs.mapbox.com/mapbox-gl-js/guides/get-started/use-with-cdn/
    private $mapbox_version = 'v3.23.1';
    # https://docs.mapbox.com/mapbox-search-js/guides/autofill/web/#installation-when-using-the-mapbox-cdn
    private $mapbox_autofill_version = 'v1.5.0';
    # https://docs.mapbox.com/api/search/geocoding/
    private $mapbox_geocoding_version = 'v6';

    public function __construct() {
        add_action('init', array(&$this, 'load_translation'));
        add_action('init', array(&$this, 'register_entities'));
        add_action('init', array(&$this, 'load_filecache'));
        add_action('init', array(&$this, 'load_messages'));
        add_action('init', array(&$this, 'load_admin_pages'));
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
        add_action('trashed_post', array(&$this, 'deleted_post'), 999, 3);
        add_action('deleted_post', array(&$this, 'deleted_post'), 999, 3);
        add_action('save_post', array(&$this, 'save_post_maps'), 999, 3);
        add_action('save_post', array(&$this, 'save_post_map_markers'), 999, 3);
        add_action('saved_marker_categories', array(&$this, 'generate_cache'), 999, 3);

        /* Assets */
        add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array(&$this, 'wp_enqueue_scripts'));

        /* Shortcode */
        add_shortcode('wpumaps_map', array($this, 'display_map'));

        /* Preview */
        add_action('add_meta_boxes', array($this, 'add_map_metabox'), 99);
        add_action('add_meta_boxes', array($this, 'add_marker_metabox'), 99);
        add_action('template_redirect', array($this, 'preview_map'));
    }

    # TRANSLATION
    public function load_translation() {
        $lang_dir = dirname(plugin_basename(__FILE__)) . '/lang/';
        if (strpos(__DIR__, 'mu-plugins') !== false) {
            load_muplugin_textdomain('wpumaps', $lang_dir);
        } else {
            load_plugin_textdomain('wpumaps', false, $lang_dir);
        }
        /* Load desc string */
        __('Simple maps for your website', 'wpumaps');
    }

    public function load_filecache() {
        require_once __DIR__ . '/inc/WPUBaseFileCache/WPUBaseFileCache.php';
        $this->basefilecache = new \wpumaps\WPUBaseFileCache('wpumaps');
    }

    public function load_messages() {
        if (!is_admin()) {
            return;
        }
        require_once __DIR__ . '/inc/WPUBaseMessages/WPUBaseMessages.php';
        $this->messages = new \wpumaps\WPUBaseMessages($this->plugin_settings['id']);
    }

    public function load_toolbox() {
        require_once __DIR__ . '/inc/WPUBaseToolbox/WPUBaseToolbox.php';
        $this->basetoolbox = new \wpumaps\WPUBaseToolbox(array(
            'need_form_js' => false
        ));
    }

    public function load_admin_pages() {

        $admin_pages = array(
            'import' => array(
                'has_file' => 1,
                'section' => 'edit.php?post_type=maps',
                'name' => __('Import', 'wpumaps'),
                'function_content' => array(&$this,
                    'page_content__import'
                ),
                'function_action' => array(&$this,
                    'page_action__import'
                )
            ),
            'export' => array(
                'section' => 'edit.php?post_type=maps',
                'name' => __('Export', 'wpumaps'),
                'function_content' => array(&$this,
                    'page_content__export'
                ),
                'function_action' => array(&$this,
                    'page_action__export'
                )
            )
        );

        $pages_options = array(
            'id' => $this->plugin_settings['id'],
            'level' => $this->plugin_settings['user_capability'],
            'basename' => plugin_basename(__FILE__)
        );

        // Init admin page
        require_once __DIR__ . '/inc/WPUBaseAdminPage/WPUBaseAdminPage.php';
        $this->baseadminpages = new \wpumaps\WPUBaseAdminPage();
        $this->baseadminpages->init($pages_options, $admin_pages);
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
                'navigation-night-v1' => 'Navigation Night',
                'custom' => __('Custom style', 'wpumaps')
            )
        );
        $mapbox_studio_link = make_clickable('https://console.mapbox.com/studio/');
        $mapbox_studio_link = str_replace('<a href=', '<a target="_blank" href=', $mapbox_studio_link);
        $fields['map_style_custom'] = array(
            'label' => __('Custom style URL', 'wpumaps'),
            'type' => 'text',
            'group' => 'maps',
            'help' => __('Create a custom style on Mapbox Studio :', 'wpumaps') . ' ' . $mapbox_studio_link,
            'toggle-display' => array(
                'map_style' => 'custom'
            )
        );

        $field_groups['maps_settings_markers'] = array(
            'label' => __('Markers', 'wpumaps'),
            'post_type' => array('maps')
        );
        $map_categories = get_terms(array(
            'taxonomy' => 'marker_categories',
            'hide_empty' => false
        ));
        if (!empty($map_categories)) {
            $categories = array();
            foreach ($map_categories as $category) {
                $categories[$category->term_id] = $category->name . ' (' . $category->count . ')';
            }
            $fields['map_categories'] = array(
                'label' => __('Marker Categories', 'wpumaps'),
                'type' => 'checkboxes',
                'taxonomy' => 'marker_categories',
                'help' => __('Select the categories of markers to display on this map. If none selected, all categories will be displayed.', 'wpumaps'),
                'group' => 'maps_settings_markers',
                'data' => $categories
            );
        }
        $fields['map_marker_width'] = array(
            'label' => __('Marker width (px)', 'wpumaps'),
            'type' => 'number',
            'help' => __('Set a custom width for the markers on this map. The height will be adjusted automatically to keep the aspect ratio.', 'wpumaps'),
            'group' => 'maps_settings_markers',
            'default_value' => 32,
            'extra_attributes' => array(
                'step' => '1',
                'min' => '1'
            )
        );

        $field_groups['maps_settings'] = array(
            'label' => __('Settings', 'wpumaps'),
            'post_type' => array('maps')
        );
        $fields['maps_show_search_box'] = array(
            'label' => __('Show search box', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, a search box will be displayed on the map, allowing users to search for locations.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $fields['maps_show_geolocate_control'] = array(
            'label' => __('Show geolocate control', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, a geolocate control will be displayed on the map, allowing users to center the map on their current location.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $fields['map_scrollwheel_enable'] = array(
            'label' => __('Enable scroll zoom', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, users will be able to zoom the map using their mouse scroll wheel.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $fields['map_center_on_marker_click'] = array(
            'label' => __('Center map on marker click', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, the map will center on a marker when it is clicked.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $fields['map_reset_on_popup_close'] = array(
            'label' => __('Reset map on popup close', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, the map will reset to its initial state when a marker popup is closed.', 'wpumaps'),
            'group' => 'maps_settings'
        );
        $fields['map_reset_when_leaving'] = array(
            'label' => __('Reset map when leaving area', 'wpumaps'),
            'type' => 'checkbox',
            'help' => __('If enabled, the map will reset to its initial state when the user leaves the area.', 'wpumaps'),
            'group' => 'maps_settings'
        );

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
        $fields['marker_popup_button'] = array(
            'label' => __('Button', 'wpumaps'),
            'type' => 'wp_link',
            'group' => 'markers_popup'
        );
        require_once __DIR__ . '/inc/WPUBaseFields/WPUBaseFields.php';
        new \wpumaps\WPUBaseFields($fields, $field_groups);
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
            'mapbox_searchbox_placeholder' => __('Search', 'wpumaps'),
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
            'mapbox_autofill_version' => $this->mapbox_autofill_version,
            'mapbox_key' => $this->get_mapbox_key(),
            'mapbox_searchbox_placeholder' => __('Search', 'wpumaps')
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
        $popup_button = json_decode(get_post_meta($marker->ID, 'marker_popup_button', 1), true);
        if ($popup_button && isset($popup_button['url'], $popup_button['title'], $popup_button['target']) && !empty($popup_button['url']) && !empty($popup_button['title'])) {
            $popup_content_html .= wpautop('<a href="' . esc_url($popup_button['url']) . '" target="' . esc_attr($popup_button['target']) . '" class="wpumaps-popup-button">' . esc_html($popup_button['title']) . '</a>');
        }

        $marker_data = array(
            'name' => get_the_title($marker),
            'categories' => wp_get_post_terms($marker->ID, 'marker_categories', array('fields' => 'slugs')),
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

    private function get_all_markers_uniqids() {
        $markers = get_posts(array(
            'post_type' => 'map_markers',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'fields' => 'ids'
        ));
        $uniqids = array();
        foreach ($markers as $marker_id) {
            $uniqid = get_post_meta($marker_id, 'marker_unique_id', 1);
            if (!$uniqid) {
                continue;
            }
            $uniqids[$uniqid] = $marker_id;
        }
        return $uniqids;
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
        $map_details['style_custom'] = get_post_meta($map_id, 'map_style_custom', 1);

        $marker_width = get_post_meta($map_id, 'map_marker_width', 1);
        $map_details['marker_width'] = $marker_width ? intval($marker_width) : 32;
        $map_details['scrollwheel_enable'] = get_post_meta($map_id, 'map_scrollwheel_enable', 1) ? true : false;
        $map_details['show_search_box'] = get_post_meta($map_id, 'maps_show_search_box', 1) ? true : false;
        $map_details['show_geolocate_control'] = get_post_meta($map_id, 'maps_show_geolocate_control', 1) ? true : false;
        $map_details['center_on_marker_click'] = get_post_meta($map_id, 'map_center_on_marker_click', 1) ? true : false;
        $map_details['reset_on_popup_close'] = get_post_meta($map_id, 'map_reset_on_popup_close', 1) ? true : false;
        $map_details['reset_when_leaving'] = get_post_meta($map_id, 'map_reset_when_leaving', 1) ? true : false;

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
                $map_data = unserialize(file_get_contents($validated_file_path), array('allowed_classes' => false));
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
            echo 'window.wpumaps.push(' . wp_json_encode($map_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ');';
            echo '</script>';
        });

        /* Wrapper */
        $marker_width = isset($map_data['map_details']['marker_width']) ? intval($map_data['map_details']['marker_width']) : 32;
        $html = '<div style="--wpumaps-marker-icon-width: ' . esc_attr($marker_width) . 'px;" class="wpumaps__wrapper" data-wpumaps="' . esc_attr($map_data['map_id']) . '">';
        $html .= '<div class="wpumaps__map"></div>';
        $html .= '</div>';

        return $html;

    }

    public function get_markers_without_coordinates() {
        $markers = get_posts(array(
            'post_type' => 'map_markers',
            'posts_per_page' => -1,
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => 'marker_lat_lng__address',
                    'value' => '',
                    'compare' => '!='
                ),
                array(
                    'relation' => 'OR',
                    array(
                        'key' => 'marker_lat_lng__lat',
                        'value' => '',
                        'compare' => '='
                    ),
                    array(
                        'key' => 'marker_lat_lng__lng',
                        'value' => '',
                        'compare' => '='
                    ),
                    array(
                        'key' => 'marker_lat_lng__lat',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => 'marker_lat_lng__lng',
                        'compare' => 'NOT EXISTS'
                    ),
                    array(
                        'key' => 'marker_lat_lng__lat',
                        'value' => 0,
                        'compare' => '='
                    ),
                    array(
                        'key' => 'marker_lat_lng__lng',
                        'value' => 0,
                        'compare' => '='
                    )
                )
            )
        ));
        return $markers;
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

    /* Purge cache */
    public function deleted_post($post_ID) {
        $post_type = get_post_type($post_ID);
        error_log('WPUMaps: Post deleted with ID ' . $post_ID . ' and type ' . $post_type);
        if ($post_type === 'maps') {
            $this->generate_cache(array($post_ID));
        } elseif ($post_type === 'map_markers') {
            $this->generate_cache(array());
        }
    }

    /* Create cache */

    public function save_post_maps($post_ID) {
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID) || !current_user_can('edit_post', $post_ID)) {
            return;
        }
        if (!isset($_POST['post_type']) || $_POST['post_type'] !== 'maps') {
            return;
        }
        $this->generate_cache(array($post_ID));
    }

    public function save_post_map_markers($post_ID) {
        if (wp_is_post_autosave($post_ID) || wp_is_post_revision($post_ID) || !current_user_can('edit_post', $post_ID)) {
            return;
        }
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

        if (isset($_POST['wpubasefields_marker_lat_lng__lng']) && is_numeric($_POST['wpubasefields_marker_lat_lng__lng'])) {
            $lng = $_POST['wpubasefields_marker_lat_lng__lng'];
        }
        if (isset($_POST['wpubasefields_marker_lat_lng__lat']) && is_numeric($_POST['wpubasefields_marker_lat_lng__lat'])) {
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
        if (get_post_status() == 'draft') {
            return;
        }
        add_meta_box(
            'wpumaps_map_preview',
            __('Preview', 'wpumaps'),
            function ($post) {
                $preview_url = add_query_arg(array(
                    'wpumaps_preview_map' => $post->ID
                ), home_url('/'));
                $this->preview_metabox_content($preview_url, __('Preview saved map', 'wpumaps'));
            },
            'maps',
            'advanced',
            'low'
        );
    }

    public function add_marker_metabox() {
        $current_screen = get_current_screen();
        if (!$current_screen || !in_array($current_screen->post_type, array('map_markers'))) {
            return;
        }
        if (isset($current_screen->action) && $current_screen->action == 'add') {
            return;
        }
        if (get_post_status() == 'draft') {
            return;
        }
        add_meta_box(
            'wpumaps_marker_preview',
            __('Preview', 'wpumaps'),
            function ($post) {
                $preview_url = add_query_arg(array(
                    'wpumaps_preview_marker' => $post->ID
                ), home_url('/'));
                $this->preview_metabox_content($preview_url, __('Preview saved marker', 'wpumaps'));
            },
            'map_markers',
            'advanced',
            'low'
        );
    }

    public function preview_metabox_content($preview_url, $button_label) {
        echo '<button type="button" class="button wpumaps-preview-toggle" data-preview-url="' . esc_url($preview_url) . '">' . esc_html($button_label) . '</button>';
        echo '<div class="wpumaps-preview-iframe-wrap" style="display:none;margin-top:10px;">';
        echo '<iframe class="wpumaps-preview-iframe" style="width:100%;height:500px;border:1px solid #ccd0d4;"></iframe>';
        echo '</div>';
    }

    public function preview_map() {
        if (!is_user_logged_in() || !current_user_can($this->plugin_settings['user_capability'])) {
            return;
        }

        $map_content = false;
        if (isset($_GET['wpumaps_preview_map']) && is_numeric($_GET['wpumaps_preview_map'])) {
            $map_content = $this->display_map(array('id' => intval($_GET['wpumaps_preview_map'])));
        }
        if (isset($_GET['wpumaps_preview_marker']) && is_numeric($_GET['wpumaps_preview_marker'])) {
            $map_content = $this->display_map(array('marker_id' => intval($_GET['wpumaps_preview_marker'])));
        }
        if (!$map_content) {
            return;
        }

        add_filter('show_admin_bar', '__return_false');

        get_header();
        echo '<div class="wpumaps-preview-wrapper">' . $map_content . '</div>';
        get_footer();
        exit;
    }

    /* ----------------------------------------------------------
      Import
    ---------------------------------------------------------- */

    public function page_content__import() {
        $example_file = base64_encode(file_get_contents(__DIR__ . '/inc/example-markers.csv'));
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
    }

    public function page_action__import() {
        if (isset($_POST['wpumaps_import_markers']) && isset($_FILES['wpumaps_import_file'])) {
            $this->page_action__import__import_markers();
        }
        if (isset($_POST['wpumaps_geocode_markers'])) {
            $this->page_action__import__geocode_markers_without_coordinates();
        }
    }

    public function page_action__import__import_markers() {

        $import_file = $_FILES['wpumaps_import_file'];
        if ($import_file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($import_file['tmp_name']) || strtolower(pathinfo($import_file['name'], PATHINFO_EXTENSION)) !== 'csv') {
            $this->messages->set_message('invalid_csv_file', __('Invalid CSV file.', 'wpumaps'), 'error');
            return false;
        }

        $csv_file = fopen($import_file['tmp_name'], 'r');
        if (!$csv_file) {
            $this->messages->set_message('invalid_csv_file', __('Invalid CSV file.', 'wpumaps'), 'error');
            return false;
        }

        $headers = fgetcsv($csv_file);
        $import_data = array();

        while (($row = fgetcsv($csv_file)) !== false) {
            if (count($row) !== count($headers)) {
                continue;
            }
            $import_data[] = array_combine($headers, $row);
        }
        fclose($csv_file);

        if (!is_array($import_data)) {
            $this->messages->set_message('invalid_csv_file', __('Invalid CSV file.', 'wpumaps'), 'error');
            return false;
        }

        $new_markers = 0;
        $markers_updated = 0;

        $existing_uniqids = $this->get_all_markers_uniqids();

        foreach ($import_data as $item) {
            $uniqid = isset($item['uniqid']) ? sanitize_text_field($item['uniqid']) : '';
            if (!$uniqid) {
                $this->messages->set_message('missing_uniqid', __('At least one marker is missing a uniqid.', 'wpumaps'), 'error');
                continue;
            }

            if (isset($existing_uniqids[$uniqid])) {
                $marker_id = $existing_uniqids[$uniqid];
                error_log('Updating existing marker with uniqid ' . $uniqid . ' (ID: ' . $marker_id . ')');
                $markers_updated++;
            } else {
                $marker_id = wp_insert_post(array(
                    'post_type' => 'map_markers',
                    'post_title' => isset($item['name']) ? sanitize_text_field($item['name']) : '',
                    'post_status' => 'draft'
                ));
                if (is_wp_error($marker_id)) {
                    continue;
                }
                $new_markers++;
            }
            $this->update_marker_from_import_item($marker_id, $item);
        }

        if ($new_markers > 0) {
            $str = $new_markers > 1 ? __('%d markers created.', 'wpumaps') : __('%d marker created.', 'wpumaps');
            $this->messages->set_message('import_success', sprintf($str, $new_markers), 'updated');
        }
        if ($markers_updated > 0) {
            $str = $markers_updated > 1 ? __('%d markers updated.', 'wpumaps') : __('%d marker updated.', 'wpumaps');
            $this->messages->set_message('update_success', sprintf($str, $markers_updated), 'updated');
        }
    }

    private function update_marker_from_import_item($marker_id, $item) {
        update_post_meta($marker_id, 'marker_unique_id', isset($item['uniqid']) ? sanitize_text_field($item['uniqid']) : '');
        update_post_meta($marker_id, 'marker_lat_lng__lat', isset($item['lat']) ? floatval($item['lat']) : 0);
        update_post_meta($marker_id, 'marker_lat_lng__lng', isset($item['lng']) ? floatval($item['lng']) : 0);
        update_post_meta($marker_id, 'marker_lat_lng__address', isset($item['address']) ? sanitize_text_field($item['address']) : '');
        update_post_meta($marker_id, 'marker_popup_title', isset($item['popup_title']) ? sanitize_text_field($item['popup_title']) : '');
        update_post_meta($marker_id, 'marker_popup_content', isset($item['popup_content']) ? sanitize_textarea_field($item['popup_content']) : '');
        if (isset($item['categories'])) {
            $this->update_marker_from_import_item_categories($marker_id, $item['categories']);
        }
    }

    private function update_marker_from_import_item_categories($marker_id, $categories_slugs) {
        $term_ids = array();
        foreach (explode('|', $categories_slugs) as $cat_slug) {
            $cat_slug = sanitize_title($cat_slug);
            if (!$cat_slug) {
                continue;
            }
            $term = get_term_by('slug', $cat_slug, 'marker_categories');
            if (!$term) {
                $term = wp_insert_term($cat_slug, 'marker_categories', array('slug' => $cat_slug));
                $term = is_wp_error($term) ? false : get_term($term['term_id'], 'marker_categories');
            }
            if ($term && !is_wp_error($term)) {
                $term_ids[] = $term->term_id;
            }
        }
        wp_set_post_terms($marker_id, $term_ids, 'marker_categories');
    }

    /* ----------------------------------------------------------
      Geocoding
    ---------------------------------------------------------- */

    public function page_action__import__geocode_markers_without_coordinates() {
        $markers_without_coordinates = $this->get_markers_without_coordinates();
        $geocoded = 0;
        foreach ($markers_without_coordinates as $marker) {
            $address = get_post_meta($marker->ID, 'marker_lat_lng__address', 1);
            if (!$address) {
                continue;
            }
            $coordinates = $this->geocode_address($address);
            if (!$coordinates) {
                continue;
            }
            update_post_meta($marker->ID, 'marker_lat_lng__lat', $coordinates['lat']);
            update_post_meta($marker->ID, 'marker_lat_lng__lng', $coordinates['lng']);
            $geocoded++;
        }
        if ($geocoded > 0) {
            $str = $geocoded > 1 ? __('%d markers geocoded.', 'wpumaps') : __('%d marker geocoded.', 'wpumaps');
            $this->messages->set_message('geocode_success', sprintf($str, $geocoded), 'updated');
        } else {
            $this->messages->set_message('geocode_no_results', __('No marker could be geocoded. Please check the addresses and try again.', 'wpumaps'), 'error');
        }
    }

    public function geocode_address($address) {
        $geocoding_endpoint = 'https://api.mapbox.com/search/geocode/' . $this->mapbox_geocoding_version . '/forward?q=' . rawurlencode($address) . '&access_token=' . $this->get_mapbox_key();
        $geocode_informations = wp_remote_get($geocoding_endpoint);
        if (is_wp_error($geocode_informations) || !isset($geocode_informations['body'])) {
            return false;
        }
        $geocode_informations = json_decode($geocode_informations['body'], true);
        if (!isset($geocode_informations['features']) || !is_array($geocode_informations['features']) || count($geocode_informations['features']) === 0) {
            return false;
        }
        $coordinates = $geocode_informations['features'][0]['geometry']['coordinates'];
        return array(
            'lng' => $coordinates[0],
            'lat' => $coordinates[1],
            'address' => $geocode_informations['features'][0]['properties']['full_address']
        );
    }

    /* ----------------------------------------------------------
      Export
    ---------------------------------------------------------- */

    public function page_content__export() {

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
    }

    public function page_action__export() {
        if (!isset($_POST['wpumaps_export_markers'])) {
            return;
        }

        $q = array(
            'post_type' => 'map_markers',
            'posts_per_page' => -1
        );
        if (isset($_POST['wpumaps_export_categories']) && !empty($_POST['wpumaps_export_categories']) && is_numeric($_POST['wpumaps_export_categories'])) {
            $q['tax_query'] = array(
                array(
                    'taxonomy' => 'marker_categories',
                    'field' => 'term_id',
                    'terms' => intval($_POST['wpumaps_export_categories'])
                )
            );
        }
        $markers = get_posts($q);

        $export_data = array();
        foreach ($markers as $marker) {
            $marker_title = get_the_title($marker);
            if (!$marker_title) {
                continue;
            }
            $uniqid = get_post_meta($marker->ID, 'marker_unique_id', 1);
            if (!$uniqid) {
                $uniqid = 'marker-' . $marker->ID;
                update_post_meta($marker->ID, 'marker_unique_id', $uniqid);
            }
            $marker_terms = get_the_terms($marker->ID, 'marker_categories');
            $categories_slugs = '';
            if ($marker_terms && !is_wp_error($marker_terms)) {
                $categories_slugs = implode('|', wp_list_pluck($marker_terms, 'slug'));
            }
            $export_data_item = array(
                'uniqid' => $uniqid,
                'name' => $marker_title,
                'lat' => get_post_meta($marker->ID, 'marker_lat_lng__lat', 1),
                'lng' => get_post_meta($marker->ID, 'marker_lat_lng__lng', 1),
                'address' => get_post_meta($marker->ID, 'marker_lat_lng__address', 1),
                'popup_title' => get_post_meta($marker->ID, 'marker_popup_title', 1),
                'popup_content' => get_post_meta($marker->ID, 'marker_popup_content', 1),
                'popup_button' => get_post_meta($marker->ID, 'marker_popup_button', 1),
                'categories' => $categories_slugs
            );
            $export_data[] = $export_data_item;

        }

        $this->basetoolbox->export_array_to_csv($export_data, 'wpumaps_markers_export');

    }

}

$WPUMaps = new WPUMaps();
