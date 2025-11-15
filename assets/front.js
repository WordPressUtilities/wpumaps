document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    /* Initial check */
    if (!window.wpumaps || !window.wpumaps.length) {
        return;
    }
    if (!window.wpumaps_settings || !window.wpumaps_settings.mapbox_key) {
        console.error('WPU Maps: Missing Mapbox API key.');
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
    var $map = document.querySelector('[data-wpumaps="' + _map.map_id + '"]');
    if (!$map) {
        return;
    }

    /* Load map */
    var map = new mapboxgl.Map({
        container: $map.querySelector('.wpumaps__map'),
        zoom: _map.map_details.zoom,
        center: [_map.map_details.lng, _map.map_details.lat]
    });
    console.log([_map.map_details.lng, _map.map_details.lat]);
    map.addControl(new mapboxgl.NavigationControl());
    map.scrollZoom.disable();

    /* Add markers */
    _map.markers.forEach(function(marker) {
        new mapboxgl.Marker()
            .setLngLat([marker.lng, marker.lat])
            .addTo(map);
    });


}
