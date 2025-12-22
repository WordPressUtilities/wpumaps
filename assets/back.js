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
                $btn.innerText = '✅ Valid';
            } else {
                $btn.innerText = '❌ Invalid';
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
    const autofillElement = new mapboxsearch.MapboxAddressAutofill()
    autofillElement.accessToken = wpumaps_admin_settings.mapbox_key;

    $fields.forEach(function($field) {

        /* Init element */
        var _field_name = $field.getAttribute('name'),
            $parent = $field.parentElement,
            $wrapper = $field.closest('[data-group]');

        /* Move the field into the autofill element */
        autofillElement.appendChild($field);
        $parent.appendChild(autofillElement);

        setTimeout(function() {
            $field.setAttribute('name', _field_name);
        }, 100);

        var $lat = $wrapper.querySelector('input[type="number"][name*="__lat"]'),
            $lng = $wrapper.querySelector('input[type="number"][name*="__lng"]');

        autofillElement.addEventListener('retrieve', function(event) {
            /* When an address is selected : fill lat/lng fields */
            $lat.value = event.detail.features[0].geometry.coordinates[1];
            $lng.value = event.detail.features[0].geometry.coordinates[0];
            /* Save full address */
            setTimeout(function() {
                $field.setAttribute('name', _field_name);
                $field.value = event.detail.features[0].properties.full_address;
            }, 100);
        });
    });
}
