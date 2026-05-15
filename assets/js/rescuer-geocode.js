/**
 * Rescuer dashboard: resolve rescue request coordinates to readable addresses.
 */
(function () {
    'use strict';

    var queue = [];
    var processing = false;
    var RATE_LIMIT_MS = 1100;

    function locationLabel(text) {
        if (!text) return 'Location: —';
        if (String(text).toLowerCase().indexOf('location:') === 0) return text;
        return 'Location: ' + text;
    }

    function coordFallback(lat, lon) {
        return 'Location: ' + lat + ', ' + lon;
    }

    function fetchAddress(lat, lon, caseId, done) {
        var params = new URLSearchParams({
            lat: String(lat),
            lng: String(lon),
        });
        if (caseId) {
            params.set('case_id', String(caseId));
        }
        fetch('backend/get_address.php?' + params.toString(), {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (data && data.ok && data.address) {
                    done(data.address, false);
                } else if (data && data.fallback) {
                    done(data.fallback, true);
                } else {
                    done(null, true);
                }
            })
            .catch(function () {
                done(null, true);
            });
    }

    function drainQueue() {
        if (processing || queue.length === 0) {
            return;
        }
        processing = true;
        var job = queue.shift();
        fetchAddress(job.lat, job.lon, job.caseId, function (address, usedFallback) {
            var lat = job.lat;
            var lon = job.lon;
            var text = address && !usedFallback
                ? locationLabel(address)
                : coordFallback(lat, lon);
            job.el.textContent = text;
            job.el.setAttribute('data-geocoded', '1');
            if (job.onDone) {
                job.onDone(text, !usedFallback);
            }
            processing = false;
            setTimeout(drainQueue, RATE_LIMIT_MS);
        });
    }

    function enqueue(el, onDone) {
        if (!el || el.getAttribute('data-geocoded') === '1') {
            return;
        }
        if (el.getAttribute('data-skip-geocode') === '1') {
            el.setAttribute('data-geocoded', '1');
            return;
        }
        if (el.getAttribute('data-needs-geocode') !== '1') {
            return;
        }
        var lat = el.getAttribute('data-lat');
        var lon = el.getAttribute('data-lon');
        if (!lat || !lon) {
            return;
        }
        queue.push({
            el: el,
            lat: lat,
            lon: lon,
            caseId: el.getAttribute('data-case-id') || '',
            onDone: onDone || null,
        });
        drainQueue();
    }

    window.RescuerGeocode = {
        enqueue: enqueue,
        scan: function () {
            document.querySelectorAll('.js-rescue-location[data-needs-geocode="1"]').forEach(function (el) {
                enqueue(el);
            });
        },
        resolveForDetail: function (d, locEl) {
            if (!locEl) {
                return;
            }
            var preset = d.location || '';
            if (preset && d.needsGeocode !== true && d.needsGeocode !== '1') {
                locEl.textContent = locationLabel(preset);
                return;
            }
            locEl.textContent = coordFallback(d.lat, d.lon);
            fetchAddress(d.lat, d.lon, d.id, function (address, usedFallback) {
                locEl.textContent =
                    address && !usedFallback
                        ? locationLabel(address)
                        : coordFallback(d.lat, d.lon);
            });
        },
    };

    document.addEventListener('DOMContentLoaded', function () {
        window.RescuerGeocode.scan();
    });
})();
