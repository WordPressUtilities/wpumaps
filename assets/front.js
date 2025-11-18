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
    var script = document.createElement('script');
    script.src = mapbox_assets_url + 'mapbox-gl.js';
    script.async = true;
    script.onload = function() {
        mapboxgl.accessToken = window.wpumaps_settings.mapbox_key;
        Array.prototype.forEach.call(window.wpumaps, wpumaps_load_map);
    }
    document.head.appendChild(script);

});


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

    if (!_map.map_details || _map.map_details.length === 0) {
        _map.map_details = {
            lat: 0,
            lng: 0,
            zoom: 0
        };
    }

    /* if not lat or no lng, center between markers */
    if (_map.map_details.lat === 0 && _map.map_details.lng === 0) {
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

    /* Load map */
    var map = new mapboxgl.Map({
        container: $map_target,
        zoom: _map.map_details.zoom,
        center: [_map.map_details.lng, _map.map_details.lat]
    });

    /* Map settings */
    map.addControl(new mapboxgl.NavigationControl());
    map.scrollZoom.disable();

    /* If zoom is 0 and we have markers, fit bounds */
    if (_map.map_details.zoom === 0) {
        if (_map.markers.length > 1) {
            var bounds = new mapboxgl.LngLatBounds();
            _map.markers.forEach(function(marker) {
                bounds.extend([marker.lng, marker.lat]);
            });
            map.fitBounds(bounds, {
                padding: 60,
                duration: 0
            });
        } else {
            map.setZoom(14);
        }
    }

    /* Add markers */
    _map.markers.forEach(function(marker) {

        var _marker_params = {};
        if (marker.icon_url) {
            var _icon_el = document.createElement('div');
            _icon_el = document.createElement('div');
            _icon_el.className = 'wpumaps-marker-icon';
            _icon_el.style.backgroundImage = 'url(' + marker.icon_url + ')';
            _marker_params.element = _icon_el;
            _marker_params.offset = {
                x: 0,
                y: -16
            };

        }

        var _marker = new mapboxgl.Marker(_marker_params)
            .setLngLat([marker.lng, marker.lat])
            .addTo(map);

        if (marker.popup_content && marker.popup_content.length) {
            var popup = new mapboxgl.Popup()
                .setHTML(marker.popup_content);
            _marker.setPopup(popup);
        }


    });


}
