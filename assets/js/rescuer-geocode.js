/**
 * Reverse geocoding + map helpers for RescueNet (Nominatim via backend/get_address.php).
 * Used on rescuer dashboard, user dashboard, and admin assignment screens.
 */
(function (global) {
    'use strict';

    var API = 'backend/get_address.php';
    var cache = Object.create(null);
    var queue = [];
    var queueBusy = false;

    function roundKey(lat, lon) {
        return Number(lat).toFixed(5) + ',' + Number(lon).toFixed(5);
    }

    function haversineKm(lat1, lon1, lat2, lon2) {
        var R = 6371;
        var dLat = ((lat2 - lat1) * Math.PI) / 180;
        var dLon = ((lon2 - lon1) * Math.PI) / 180;
        var a =
            Math.sin(dLat / 2) * Math.sin(dLat / 2) +
            Math.cos((lat1 * Math.PI) / 180) *
                Math.cos((lat2 * Math.PI) / 180) *
                Math.sin(dLon / 2) *
                Math.sin(dLon / 2);
        return R * 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
    }

    function fetchAddress(lat, lon, caseId) {
        var key = roundKey(lat, lon);
        if (cache[key]) {
            return Promise.resolve(cache[key]);
        }
        var url =
            API +
            '?lat=' +
            encodeURIComponent(lat) +
            '&lon=' +
            encodeURIComponent(lon);
        if (caseId) {
            url += '&case_id=' + encodeURIComponent(caseId);
        }
        return new Promise(function (resolve) {
            queue.push(function () {
                fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } })
                    .then(function (r) {
                        return r.json();
                    })
                    .then(function (data) {
                        var addr =
                            data && data.ok && data.address
                                ? data.address
                                : data && data.fallback
                                  ? data.fallback
                                  : lat + ', ' + lon;
                        cache[key] = addr;
                        resolve(addr);
                    })
                    .catch(function () {
                        var fb = Number(lat).toFixed(5) + ', ' + Number(lon).toFixed(5);
                        cache[key] = fb;
                        resolve(fb);
                    })
                    .finally(function () {
                        setTimeout(function () {
                            queueBusy = false;
                            if (queue.length) {
                                queueBusy = true;
                                queue.shift()();
                            }
                        }, 1100);
                    });
            });
            if (!queueBusy) {
                queueBusy = true;
                queue.shift()();
            }
        });
    }

    function locationLabel(text) {
        text = (text || '').trim();
        if (!text) {
            return 'Location: —';
        }
        if (text.toLowerCase().indexOf('location:') === 0) {
            return text;
        }
        return 'Location: ' + text;
    }

    function resolveElement(el) {
        if (!el || el.getAttribute('data-skip-geocode') === '1') {
            return;
        }
        var lat = parseFloat(el.getAttribute('data-lat') || '');
        var lon = parseFloat(el.getAttribute('data-lon') || '');
        if (isNaN(lat) || isNaN(lon)) {
            return;
        }
        var caseId = el.getAttribute('data-case-id') || '';
        if (el.getAttribute('data-needs-geocode') !== '1' && el.textContent.trim() !== '') {
            return;
        }
        el.setAttribute('aria-busy', 'true');
        fetchAddress(lat, lon, caseId).then(function (addr) {
            el.textContent = locationLabel(addr);
            el.setAttribute('data-skip-geocode', '1');
            el.removeAttribute('aria-busy');
        });
    }

    function resolveAll(selector) {
        var nodes = document.querySelectorAll(selector || '.js-rescue-location');
        Array.prototype.forEach.call(nodes, resolveElement);
    }

    function destroyMapOnEl(el) {
        if (el && el._leaflet_map) {
            el._leaflet_map.remove();
            el._leaflet_map = null;
        }
    }

    function addMapLegend(map) {
        var legend = L.control({ position: 'bottomright' });
        legend.onAdd = function () {
            var div = L.DomUtil.create('div', 'geo-map-legend');
            div.innerHTML =
                '<span><i class="geo-legend-dot geo-legend-dot--animal"></i> Animal</span>' +
                '<span><i class="geo-legend-dot geo-legend-dot--rescuer"></i> Rescuer</span>';
            return div;
        };
        legend.addTo(map);
    }

    function openInlineMap(containerId, lat, lon, title) {
        var el = document.getElementById(containerId);
        if (!el || typeof L === 'undefined') {
            return null;
        }
        el.hidden = false;
        destroyMapOnEl(el);
        el.innerHTML = '';
        var map = L.map(el, { scrollWheelZoom: false }).setView([lat, lon], 15);
        el._leaflet_map = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);
        L.circleMarker([lat, lon], {
            radius: 10,
            color: '#b91c1c',
            fillColor: '#dc2626',
            fillOpacity: 0.92,
            weight: 2,
        })
            .addTo(map)
            .bindPopup(title || 'Location')
            .openPopup();
        setTimeout(function () {
            map.invalidateSize();
        }, 150);
        return map;
    }

    /**
     * Animal (red) + optional rescuer (blue) markers on one map.
     * @param {string} containerId
     * @param {{ lat: number, lon: number, label?: string }} animal
     * @param {{ lat: number, lon: number, label?: string }|null} rescuer
     */
    function initDualMarkerMap(containerId, animal, rescuer) {
        var el = document.getElementById(containerId);
        if (!el || typeof L === 'undefined' || !animal) {
            return null;
        }
        var aLat = Number(animal.lat);
        var aLon = Number(animal.lon);
        if (isNaN(aLat) || isNaN(aLon)) {
            return null;
        }

        el.hidden = false;
        destroyMapOnEl(el);
        el.innerHTML = '';

        var map = L.map(el, { scrollWheelZoom: true }).setView([aLat, aLon], 14);
        el._leaflet_map = map;
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; OpenStreetMap',
        }).addTo(map);

        var bounds = [];
        L.circleMarker([aLat, aLon], {
            radius: 10,
            color: '#b91c1c',
            fillColor: '#dc2626',
            fillOpacity: 0.92,
            weight: 2,
        })
            .addTo(map)
            .bindPopup(animal.label || 'Animal reported location');
        bounds.push([aLat, aLon]);

        var rLat = rescuer ? Number(rescuer.lat) : NaN;
        var rLon = rescuer ? Number(rescuer.lon) : NaN;
        if (!isNaN(rLat) && !isNaN(rLon)) {
            L.circleMarker([rLat, rLon], {
                radius: 10,
                color: '#1d4ed8',
                fillColor: '#3b82f6',
                fillOpacity: 0.92,
                weight: 2,
            })
                .addTo(map)
                .bindPopup(rescuer.label || 'Your live location');
            bounds.push([rLat, rLon]);

            var distEl = document.getElementById('rescuer-modal-distance');
            if (distEl) {
                var km = haversineKm(rLat, rLon, aLat, aLon);
                distEl.textContent = 'Approx. ' + km.toFixed(2) + ' km from animal (your last shared GPS)';
                distEl.hidden = false;
            }
        }

        if (bounds.length > 1) {
            map.fitBounds(bounds, { padding: [48, 48], maxZoom: 15 });
            addMapLegend(map);
        } else {
            map.setView([aLat, aLon], 15);
        }

        setTimeout(function () {
            map.invalidateSize();
        }, 150);
        return map;
    }

    function initRescuerDetailMap(containerId, d) {
        var rescuer =
            d.rescuerLat != null && d.rescuerLon != null && !isNaN(Number(d.rescuerLat)) && !isNaN(Number(d.rescuerLon))
                ? {
                      lat: Number(d.rescuerLat),
                      lon: Number(d.rescuerLon),
                      label: 'Your live location',
                  }
                : null;
        return initDualMarkerMap(containerId, {
            lat: Number(d.lat),
            lon: Number(d.lon),
            label: 'Animal reported location',
        }, rescuer);
    }

    function resolveForDetail(d, el) {
        if (!el) {
            return;
        }
        var lat = Number(d.lat);
        var lon = Number(d.lon);
        var base = d.location || '';
        el.textContent = locationLabel(base || (lat + ', ' + lon));

        function showDistance() {
            var distEl = document.getElementById('rescuer-modal-distance');
            if (!distEl) return;
            var rLat = d.rescuerLat != null ? Number(d.rescuerLat) : parseFloat(distEl.getAttribute('data-rescuer-lat') || '');
            var rLon = d.rescuerLon != null ? Number(d.rescuerLon) : parseFloat(distEl.getAttribute('data-rescuer-lon') || '');
            if (!isNaN(rLat) && !isNaN(rLon) && !isNaN(lat) && !isNaN(lon)) {
                var km = haversineKm(rLat, rLon, lat, lon);
                distEl.textContent = 'Approx. ' + km.toFixed(2) + ' km from animal (your last shared GPS)';
                distEl.hidden = false;
            }
        }

        if (!isNaN(lat) && !isNaN(lon) && d.needsGeocode) {
            fetchAddress(lat, lon, d.id).then(function (addr) {
                el.textContent = locationLabel(addr);
                showDistance();
            });
        } else {
            showDistance();
        }
    }

    global.LocationGeocode = {
        fetchAddress: fetchAddress,
        locationLabel: locationLabel,
        resolveAll: resolveAll,
        resolveElement: resolveElement,
        openInlineMap: openInlineMap,
        initDualMarkerMap: initDualMarkerMap,
        initRescuerDetailMap: initRescuerDetailMap,
        haversineKm: haversineKm,
    };

    global.RescuerGeocode = {
        resolveForDetail: resolveForDetail,
        resolveAll: resolveAll,
    };

    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('.js-rescue-location')) {
            resolveAll('.js-rescue-location');
        }
    });
})(typeof window !== 'undefined' ? window : this);
