<?php
/*
Plugin Name: WPU Maps
Plugin URI: https://github.com/WordPressUtilities/wpumaps
Update URI: https://github.com/WordPressUtilities/wpumaps
Description: Simple maps for your website
Version: 0.0.1
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
    private $plugin_version = '0.0.1';
    private $plugin_settings = array(
        'id' => 'wpumaps',
        'name' => 'WPU Maps'
    );
    private $basetoolbox;
    private $basefields;
    private $settings;
    private $settings_obj;
    private $settings_details;
    private $mapbox_version = 'v3.17.0-beta.1';
    private $mapbox_autofill_version = 'v1.5.0';
    private $plugin_description;

    public function __construct() {
        add_action('init', array(&$this, 'load_toolbox'));
        add_action('init', array(&$this, 'load_fields'));
        add_action('init', array(&$this, 'load_settings'));
        add_action('init', array(&$this, 'register_post_type'));

        /* Menu */
        add_action('admin_menu', array(&$this, 'admin_menu'));
        add_action('admin_head-post-new.php', array($this, 'admin_head'));
        add_action('admin_head-post.php', array($this, 'admin_head'));
        add_action('admin_head-edit.php', array($this, 'admin_head'));

        /* Assets */
        add_action('admin_enqueue_scripts', array(&$this, 'admin_enqueue_scripts'));
        add_action('wp_enqueue_scripts', array(&$this, 'wp_enqueue_scripts'));

        /* Shortcode */
        add_shortcode('wpumaps_map', function ($atts) {
            $atts = shortcode_atts(array(
                'id' => ''
            ), $atts, 'wpumaps_map');
            return $this->display_map($atts['id']);
        });
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

        $fields = array(
            'map_zoom' => array(
                'label' => __('Zoom level', 'wpumaps'),
                'type' => 'number',
                'group' => 'maps'
            ),
            'map_lat_lng' => array_merge(
                $field_lat_lng,
                array(
                    'group' => 'maps'
                )
            ),
            'marker_lat_lng' => array_merge(
                $field_lat_lng,
                array(
                    'group' => 'markers'
                )
            )
        );
        $field_groups = array(
            'maps' => array(
                'label' => __('Coordinates', 'wpumaps'),
                'post_type' => array('maps')
            ),
            'markers' => array(
                'label' => __('Coordinates', 'wpumaps'),
                'post_type' => array('map_markers')
            )
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
        $this->settings = array(
            'mapbox_key' => array(
                'label' => __('Mapbox Key', 'wpumaps')
            )
        );
        require_once __DIR__ . '/inc/WPUBaseSettings/WPUBaseSettings.php';
        $this->settings_obj = new \wpumaps\WPUBaseSettings($this->settings_details, $this->settings);
    }

    public function register_post_type() {
        # MAPS
        register_post_type('maps', array(
            'public' => true,
            'publicly_queryable' => false,
            'label' => __('Maps', 'wpumaps'),
            'menu_icon' => 'dashicons-location-alt',
            'supports' => array('title')
        ));
        # MARKERS
        register_post_type('map_markers', array(
            'public' => true,
            'publicly_queryable' => false,
            'label' => __('Markers', 'wpumaps'),
            'menu_icon' => 'dashicons-location-alt',
            'supports' => array('title')
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

    public function admin_enqueue_scripts() {
        /* Back Style */
        wp_register_style('wpumaps_back_style', plugins_url('assets/back.css', __FILE__), array(), $this->plugin_version);
        wp_enqueue_style('wpumaps_back_style');
        /* Back Script */
        wp_register_script('wpumaps_back_script', plugins_url('assets/back.js', __FILE__), array(), $this->plugin_version, true);
        wp_localize_script('wpumaps_back_script', 'wpumaps_admin_settings', array(
            'mapbox_version' => $this->mapbox_version,
            'mapbox_autofill_version' => $this->mapbox_autofill_version,
            'mapbox_key' => $this->settings_obj->get_setting('mapbox_key')
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
            'mapbox_key' => $this->settings_obj->get_setting('mapbox_key')
        ));
        wp_enqueue_script('wpumaps_front_script');
    }

    /* ----------------------------------------------------------
      GET MAP
    ---------------------------------------------------------- */

    public function get_markers($map_id) {
        $markers_posts = get_posts(array(
            'post_type' => 'map_markers',
            'posts_per_page' => -1
        ));

        $markers = array();
        foreach ($markers_posts as $marker) {
            $marker = array(
                'name' => get_the_title($marker),
                'lat' => (get_post_meta($marker->ID, 'marker_lat_lng__lat', 1)),
                'lng' => (get_post_meta($marker->ID, 'marker_lat_lng__lng', 1))
            );
            $markers[] = $marker;
        }
        return $markers;
    }

    public function get_map_details($map_id) {
        $map = array(
            'zoom' => intval(get_post_meta($map_id, 'map_zoom', 1)),
            'lat' => floatval(get_post_meta($map_id, 'map_lat_lng__lat', 1)),
            'lng' => floatval(get_post_meta($map_id, 'map_lat_lng__lng', 1))
        );
        return $map;
    }

    public function display_map($map_id) {
        ob_start();
        require_once __DIR__ . '/inc/templates/map.php';
        return ob_get_clean();
    }
}

$WPUMaps = new WPUMaps();
