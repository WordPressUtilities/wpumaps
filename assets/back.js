/* ----------------------------------------------------------
  Dynamic Help Text for Mapbox Key Field
---------------------------------------------------------- */

document.addEventListener("DOMContentLoaded", function() {
    'use strict';
    var help_container = document.querySelector('.wpumaps-mapbox-key-help');
    if (!help_container) {
        return;
    }
    var $input = document.getElementById('mapbox_key');

    function update_help_text() {
        if ($input.value.trim() === '') {
            help_container.innerHTML = wpumaps_admin_settings.mapbox_key_help_empty_text;
        } else {
            help_container.innerHTML = wpumaps_admin_settings.mapbox_key_help_filled_text;
        }
    }
    update_help_text();
    $input.addEventListener('input', update_help_text);

    /* Click on button */
    help_container.addEventListener('click', function(event) {
        if (event.target.tagName.toLowerCase() !== 'button') {
            return;
        }
        var $btn = event.target;
        event.preventDefault();
        $btn.innerText = '⏳ Testing...';
        var test_url = 'https://api.mapbox.com/geocoding/v5/mapbox.places/Paris.json?access_token=' + encodeURIComponent($input.value.trim());
        fetch(test_url).then(function(response) {
            if (response.ok) {
                $btn.innerText = '✅ ' + wpumaps_admin_settings.mapbox_text_valid;
            } else {
                $btn.innerText = '❌ ' + wpumaps_admin_settings.mapbox_text_invalid;
            }
        });
    });

});

/* ----------------------------------------------------------
  Autofill Address with Mapbox Search JS
---------------------------------------------------------- */

document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    var $fields = document.querySelectorAll('#wpubasefields_marker_lat_lng__address, #wpubasefields_map_lat_lng__address');
    if (!$fields.length) {
        return;
    }
    var mapbox_script = document.createElement('script');
    mapbox_script.id = 'search-js';
    mapbox_script.async = true;
    mapbox_script.src = 'https://api.mapbox.com/search-js/' + wpumaps_admin_settings.mapbox_autofill_version + '/web.js';
    mapbox_script.onload = function() {
        wpumaps_setup_autofill($fields);
    }
    document.head.appendChild(mapbox_script);
});

function wpumaps_setup_autofill($fields) {
    'use strict';

    $fields.forEach(function($field) {

        /* Init element */
        var _field_name = $field.getAttribute('name'),
            $parent = $field.parentElement,
            $wrapper = $field.closest('[data-group]');

        /* MapboxSearchBox supports all feature types (streets without numbers, lieux-dits, POI…) */
        var searchBox = new mapboxsearch.MapboxSearchBox();

        /* Insert into DOM first so the component can render its internals */
        $parent.insertBefore(searchBox, $field);
        searchBox.accessToken = wpumaps_admin_settings.mapbox_key;
        searchBox.options = { types: 'address,street,place,poi,locality,neighborhood' };

        /* Then move the field inside the search box */
        searchBox.appendChild($field);

        setTimeout(function() {
            $field.setAttribute('name', _field_name);
        }, 100);

        var $lat = $wrapper.querySelector('input[type="number"][name*="__lat"]'),
            $lng = $wrapper.querySelector('input[type="number"][name*="__lng"]');

        searchBox.addEventListener('retrieve', function(event) {
            var feature = event.detail.features[0];

            /* Extract coordinates — Point or bbox center fallback */
            var coords = null;
            if (feature.geometry && feature.geometry.type === 'Point') {
                coords = feature.geometry.coordinates;
            } else if (feature.properties.coordinates) {
                coords = [feature.properties.coordinates.longitude, feature.properties.coordinates.latitude];
            } else if (feature.properties.bbox) {
                var bbox = feature.properties.bbox;
                coords = [(bbox[0] + bbox[2]) / 2, (bbox[1] + bbox[3]) / 2];
            }

            if (coords) {
                $lat.value = coords[1];
                $lng.value = coords[0];
            }

            /* Save label — full_address for addresses, name/place_name for streets & features */
            var label = feature.properties.full_address
                || feature.properties.place_name
                || feature.properties.name
                || '';
            setTimeout(function() {
                $field.setAttribute('name', _field_name);
                $field.value = label;
            }, 100);
        });
    });
}
