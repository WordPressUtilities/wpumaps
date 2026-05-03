document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    /* Initial check */
    if (!window.wpumaps || !window.wpumaps.length) {
        return;
    }
    if (!window.wpumaps_settings || !window.wpumaps_settings.mapbox_key) {
        return;
    }

    var mapbox_assets_url = 'https://api.mapbox.com/mapbox-gl-js/' + window.wpumaps_settings.mapbox_version + '/';


    /* Load CSS */
    var link = document.createElement('link');
    link.href = mapbox_assets_url + 'mapbox-gl.css';
    link.rel = 'stylesheet';
    document.head.appendChild(link);

    /* Load JS async */
    wpumaps_load_js(mapbox_assets_url + 'mapbox-gl.js', function() {
        mapboxgl.accessToken = window.wpumaps_settings.mapbox_key;
        Array.prototype.forEach.call(window.wpumaps, wpumaps_load_map);
    });

});

function wpumaps_load_js(_url, _callback) {
    'use strict';
    var existing_script = document.querySelector('script[src="' + _url + '"]');
    if (existing_script) {
        if (typeof _callback != 'function') {
            return;
        }
        if (existing_script.getAttribute('data-wpumaps-loaded') == '1') {
            _callback();
        } else {
            existing_script.addEventListener('load', _callback);
        }
        return;
    }
    var script = document.createElement('script');
    script.src = _url;
    script.onload = function() {
        script.setAttribute('data-wpumaps-loaded', '1');
        if (typeof _callback === 'function') {
            _callback();
        }
    };
    document.head.appendChild(script);
}


function wpumaps_load_map(_map) {
    'use strict';
    var $map = document.querySelector('[data-wpumaps="' + _map.map_id + '"]');
    if (!$map) {
        return;
    }

    var $map_target = $map.querySelector('.wpumaps__map');
    if (!$map_target) {
        return;
    }

    var _initial_zoom = 0;
    if (!_map.map_details || _map.map_details.length === 0) {
        _map.map_details = {
            lat: 0,
            lng: 0,
            zoom: _initial_zoom
        };
    }

    /* if not lat or no lng, center between markers */
    if (_map.map_details.lat === 0 && _map.map_details.lng === 0 && _map.markers && _map.markers.length) {
        var lats = _map.markers.map(function(marker) {
            return marker.lat;
        });
        var lngs = _map.markers.map(function(marker) {
            return marker.lng;
        });
        var min_lat = Math.min.apply(null, lats);
        var max_lat = Math.max.apply(null, lats);
        var min_lng = Math.min.apply(null, lngs);
        var max_lng = Math.max.apply(null, lngs);
        _map.map_details.lat = (min_lat + max_lat) / 2;
        _map.map_details.lng = (min_lng + max_lng) / 2;
    }

    /* Style */
    var _style = '';
    if (_map.map_details.style && _map.map_details.style.length) {
        _style = _map.map_details.style;
    }

    var _style_url = 'mapbox://styles/mapbox/' + (_style.length ? _style : 'streets-v11');
    if (_style === 'custom' && _map.map_details.style_custom && _map.map_details.style_custom.length) {
        _style_url = _map.map_details.style_custom;
    }

    /* Load map */
    var map = new mapboxgl.Map({
        container: $map_target,
        zoom: _map.map_details.zoom,
        style: _style_url,
        center: [_map.map_details.lng, _map.map_details.lat]
    });

    map.on('style.load', () => {
        const pageLanguage = document.documentElement.lang?.split('-')[0] || 'en';
        setMapLanguage(map, pageLanguage);
    });

    function setMapLanguage(map, lang) {
        const supported = ['ar', 'de', 'en', 'es', 'fr', 'it', 'ja', 'ko', 'pt', 'ru', 'zh'];
        const resolvedLang = supported.includes(lang) ? lang : 'en';
        const field = ['get', `name_${resolvedLang}`];

        map.getStyle().layers.forEach(layer => {
            if (layer.type !== 'symbol') return;
            const layout = map.getLayoutProperty(layer.id, 'text-field');
            if (!layout) return;
            map.setLayoutProperty(layer.id, 'text-field', field);
        });
    }

    /* Map settings */
    map.addControl(new mapboxgl.NavigationControl());
    if (_map.map_details.scrollwheel_enable === undefined || _map.map_details.scrollwheel_enable === false) {
        map.scrollZoom.disable();
    }

    /* If zoom is 0 and we have markers, fit bounds */
    if (_map.map_details.zoom === 0 && _map.markers && _map.markers.length) {
        if (_map.markers.length > 1) {
            var bounds = new mapboxgl.LngLatBounds();
            _map.markers.forEach(function(marker) {
                bounds.extend([marker.lng, marker.lat]);
            });

            function fitBounds() {
                map.fitBounds(bounds, {
                    padding: 60,
                    duration: 0
                });
                _initial_zoom = map.getZoom();
            }
            fitBounds();
            window.addEventListener('resize', fitBounds);
        } else {
            map.flyTo({
                center: [parseFloat(_map.markers[0].lng, 10), parseFloat(_map.markers[0].lat, 10)],
                essential: true,
                duration: 0,
                zoom: 14
            });
        }
    }

    /* Reset map when leaving area */
    var _initial_center = [parseFloat(_map.map_details.lng, 10), parseFloat(_map.map_details.lat, 10)];
    function resetMap() {
        map.flyTo({
            center: _initial_center,
            essential: true,
            duration: 500,
            zoom: _initial_zoom
        });
    }
    if (_map.map_details.reset_when_leaving) {
        var _timeout_reset;
        $map.addEventListener('mouseleave', function() {
            clearTimeout(_timeout_reset);
            _timeout_reset = setTimeout(resetMap, 1000);
        });
        $map.addEventListener('mousemove', function() {
            clearTimeout(_timeout_reset);
        });
    }

    /* Search box */
    if (_map.map_details.show_search_box) {
        wpumaps_load_js('https://api.mapbox.com/search-js/' + window.wpumaps_settings.mapbox_autofill_version + '/web.js', function() {
            const searchBox = new MapboxSearchBox();
            searchBox.accessToken = window.wpumaps_settings.mapbox_key;
            searchBox.options = {
                types: 'city, country',
            };
            searchBox.placeholder = window.wpumaps_settings.mapbox_searchbox_placeholder;
            searchBox.marker = true;
            searchBox.mapboxgl = mapboxgl;
            map.addControl(searchBox, 'top-left');
        });
    }

    if (_map.map_details.show_geolocate_control) {
        map.addControl(new mapboxgl.GeolocateControl({
            positionOptions: {
                enableHighAccuracy: true
            },
            trackUserLocation: true,
            showUserHeading: true
        }));
    }

    /* Add markers */
    _map.markers.forEach(function(marker) {

        var _popup_content = '';
        if (marker.popup_content_image) {
            _popup_content += '<div class="wpumaps-marker-popup-image"><img src="' + marker.popup_content_image + '" alt="" /></div>';
        }
        if (marker.popup_content_html && marker.popup_content_html.length) {
            _popup_content += '<div class="wpumaps-marker-popup-content">' + marker.popup_content_html + '</div>';
        }

        var _marker_height = 32;
        if (_map.map_details.marker_width) {
            _marker_height = _map.map_details.marker_width;
        }

        var _marker_params = {};
        if (marker.icon_url) {
            var _icon_el = document.createElement('div');
            _icon_el = document.createElement('div');
            _icon_el.className = 'wpumaps-marker-icon' + (_popup_content ? ' wpumaps-marker-icon--has-popup' : '');
            _icon_el.style.backgroundImage = 'url(' + marker.icon_url + ')';
            _marker_params.element = _icon_el;
            _marker_params.offset = {
                x: 0,
                y: -_marker_height / 2
            };
        }

        var _marker = new mapboxgl.Marker(_marker_params)
            .setLngLat([marker.lng, marker.lat])
            .addTo(map);

        if (_map.map_details.center_on_marker_click) {
            _marker.getElement().addEventListener('click', function() {
                map.flyTo({
                    center: [parseFloat(marker.lng, 10), parseFloat(marker.lat, 10)],
                    essential: true,
                    duration: 500,
                    zoom: Math.max(map.getZoom(), 14)
                });
            });
        }

        if (_popup_content) {
            var _popup_classname = 'wpumaps-marker-popup';
            if (marker.popup_content_image) {
                _popup_classname += ' wpumaps-marker-popup--has-image';
            }
            if (marker.categories && marker.categories.length) {
                marker.categories.forEach(function(category) {
                    _popup_classname += ' wpumaps-marker-popup--category-' + category;
                });
            }

            var _popup_settings = {
                className: _popup_classname,
                focusAfterOpen: false
            };
            if (marker.icon_url) {
                _popup_settings.offset = {
                    'top': [0, 0],
                    'bottom': [0, -_marker_height],
                };
            }
            var popup = new mapboxgl.Popup(_popup_settings)
                .setHTML(_popup_content);
            _marker.setPopup(popup);

            if (_map.map_details.reset_on_popup_close) {
                popup.on('close', function() {
                    resetMap();
                });
            }

        }


    });


}
