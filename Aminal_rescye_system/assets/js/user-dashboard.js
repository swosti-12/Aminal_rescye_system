(function () {
    'use strict';

    var DEFAULT_CENTER = [27.7172, 85.324];
    var map = null;
    var marker = null;

    function qs(sel, ctx) {
        return (ctx || document).querySelector(sel);
    }

    function qsa(sel, ctx) {
        return Array.prototype.slice.call((ctx || document).querySelectorAll(sel));
    }

    function setCoords(lat, lng) {
        var la = qs('#ud-lat');
        var lo = qs('#ud-lon');
        var hint = qs('#ud-coords-hint');
        if (la) la.value = String(lat);
        if (lo) lo.value = String(lng);
        if (hint) {
            hint.textContent = 'Pin set: ' + lat.toFixed(5) + ', ' + lng.toFixed(5);
        }
        if (marker && map) {
            marker.setLatLng([lat, lng]);
            map.panTo([lat, lng]);
        }
    }

    function initMap() {
        var el = qs('#ud-map');
        if (!el || typeof L === 'undefined') return;

        var osm = L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
        });
        var satellite = L.tileLayer(
            'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}',
            {
                maxZoom: 19,
                attribution: 'Tiles &copy; Esri',
            }
        );

        map = L.map(el, { scrollWheelZoom: true, layers: [osm] }).setView(DEFAULT_CENTER, 13);
        L.control.layers({ 'Street map': osm, Satellite: satellite }, {}, { position: 'topright' }).addTo(map);
        L.control.scale({ imperial: false, metric: true, position: 'bottomleft' }).addTo(map);

        marker = L.marker(DEFAULT_CENTER, { draggable: true }).addTo(map);
        setCoords(DEFAULT_CENTER[0], DEFAULT_CENTER[1]);

        map.on('click', function (e) {
            setCoords(e.latlng.lat, e.latlng.lng);
        });
        marker.on('dragend', function () {
            var p = marker.getLatLng();
            setCoords(p.lat, p.lng);
        });
    }

    function mapSearch() {
        var input = qs('#ud-map-search-q');
        var hint = qs('#ud-coords-hint');
        if (!input || !map) return;
        var q = (input.value || '').trim();
        if (!q) {
            if (hint) hint.textContent = 'Enter a place or address to search.';
            return;
        }
        if (hint) hint.textContent = 'Searching…';
        var url =
            'https://nominatim.openstreetmap.org/search?' +
            new URLSearchParams({ q: q, format: 'json', limit: '1' }).toString();
        fetch(url, {
            headers: { Accept: 'application/json' },
            referrerPolicy: 'strict-origin-when-cross-origin',
        })
            .then(function (r) {
                return r.json();
            })
            .then(function (data) {
                if (!data || !data.length) {
                    if (hint) hint.textContent = 'No results. Try a different search.';
                    return;
                }
                var lat = parseFloat(data[0].lat);
                var lon = parseFloat(data[0].lon);
                if (isNaN(lat) || isNaN(lon)) {
                    if (hint) hint.textContent = 'Unexpected search response.';
                    return;
                }
                setCoords(lat, lon);
                map.setView([lat, lon], 16);
                var name = data[0].display_name || '';
                if (hint) {
                    hint.textContent =
                        name.length > 90 ? 'Found: ' + name.slice(0, 90) + '…' : 'Found: ' + name;
                }
            })
            .catch(function () {
                if (hint) hint.textContent = 'Search failed. Check your connection.';
            });
    }

    function assignFileToMainImage(file) {
        var main = qs('#ud-image');
        if (!main || !file) return;
        var dt = new DataTransfer();
        dt.items.add(file);
        main.files = dt.files;
        main.dispatchEvent(new Event('change', { bubbles: true }));
    }

    function cameraCaptureSetup() {
        var btn = qs('#ud-open-camera');
        var cam = qs('#ud-camera-only');
        if (!btn || !cam) return;
        btn.addEventListener('click', function () {
            cam.click();
        });
        cam.addEventListener('change', function () {
            var f = cam.files && cam.files[0];
            if (f) assignFileToMainImage(f);
            cam.value = '';
        });
    }

    function useGeolocation() {
        if (!navigator.geolocation) {
            alert('Geolocation is not supported in this browser.');
            return;
        }
        var hint = qs('#ud-coords-hint');
        if (hint) hint.textContent = 'Locating…';
        navigator.geolocation.getCurrentPosition(
            function (pos) {
                setCoords(pos.coords.latitude, pos.coords.longitude);
                if (map) map.setView([pos.coords.latitude, pos.coords.longitude], 15);
            },
            function () {
                if (hint) hint.textContent = 'Could not read GPS. Allow location or tap the map.';
            },
            { enableHighAccuracy: true, timeout: 12000 }
        );
    }

    function drawerSetup() {
        var side = qs('.ud-sidebar');
        var toggle = qs('#ud-mobile-menu');
        var overlay = qs('#ud-drawer-overlay');
        function closeDrawer() {
            if (side) side.classList.remove('is-open');
            if (overlay) overlay.hidden = true;
            if (toggle) toggle.setAttribute('aria-expanded', 'false');
        }
        function openDrawer() {
            if (side) side.classList.add('is-open');
            if (overlay) overlay.hidden = false;
            if (toggle) toggle.setAttribute('aria-expanded', 'true');
        }
        if (toggle && side) {
            toggle.addEventListener('click', function () {
                if (side.classList.contains('is-open')) closeDrawer();
                else openDrawer();
            });
        }
        if (overlay) {
            overlay.addEventListener('click', closeDrawer);
        }
        window.addEventListener('resize', function () {
            if (window.matchMedia('(min-width: 1041px)').matches) closeDrawer();
        });
        return closeDrawer;
    }

    function bindNav(closeDrawer) {
        function showPanel(id) {
            qsa('.ud-panel').forEach(function (p) {
                p.hidden = true;
                p.classList.remove('is-visible');
            });
            var panel = qs('#ud-panel-' + id);
            if (panel) {
                panel.hidden = false;
                panel.classList.add('is-visible');
                if (id === 'report' && map) {
                    setTimeout(function () {
                        map.invalidateSize();
                    }, 200);
                }
            }
            qsa('.ud-nav__link').forEach(function (btn) {
                var on = btn.getAttribute('data-ud-panel') === id;
                btn.classList.toggle('is-active', on);
                btn.setAttribute('aria-current', on ? 'page' : 'false');
            });
            if (typeof closeDrawer === 'function') closeDrawer();
        }

        qsa('.ud-nav__link').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showPanel(btn.getAttribute('data-ud-panel'));
            });
        });
        qsa('[data-ud-goto]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showPanel(btn.getAttribute('data-ud-goto'));
            });
        });
    }

    function escapeHtml(text) {
        return String(text || '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderNotifications(items) {
        var list = qs('#ud-notifications-list');
        if (!list) return;
        if (!Array.isArray(items) || !items.length) {
            list.innerHTML = '<li class="ud-notifications__empty">No notifications yet. Updates will appear when rescuers act on your case.</li>';
            return;
        }
        list.innerHTML = items
            .map(function (n) {
                var unread = Number(n.is_read || 0) === 0;
                return (
                    '<li class="ud-notification-item ' +
                    (unread ? 'is-unread' : '') +
                    '">' +
                    '<span class="ud-notification-item__dot" aria-hidden="true"></span>' +
                    '<div><p class="ud-notification-item__msg">' +
                    escapeHtml(n.message) +
                    '</p><time class="ud-notification-item__time">' +
                    escapeHtml(n.created_at) +
                    '</time></div></li>'
                );
            })
            .join('');
    }

    function setUnreadCount(count) {
        var badge = qs('.ud-nav__link[data-ud-panel="notifications"] .ud-nav__count');
        var countEl = qs('#ud-notify-count');
        if (countEl) countEl.textContent = String(count || 0);
        if (!badge) return;
        if (count > 0) {
            badge.textContent = String(count > 99 ? '99+' : count);
            badge.style.display = 'inline-flex';
        } else {
            badge.style.display = 'none';
        }
    }

    function themeSetup() {
        var root = document.documentElement;
        var btn = qs('#ud-theme-toggle');
        var stored = localStorage.getItem('ud-theme');
        if (stored === 'dark') {
            root.setAttribute('data-theme', 'dark');
            if (btn) {
                btn.setAttribute('aria-pressed', 'true');
                var ic = btn.querySelector('i');
                var tx = btn.querySelector('.ud-theme-toggle__text');
                if (ic) {
                    ic.className = 'fa-solid fa-sun';
                }
                if (tx) tx.textContent = 'Light mode';
            }
        }
        if (!btn) return;
        btn.addEventListener('click', function () {
            var dark = root.getAttribute('data-theme') === 'dark';
            if (dark) {
                root.removeAttribute('data-theme');
                localStorage.setItem('ud-theme', 'light');
                btn.setAttribute('aria-pressed', 'false');
                var i = btn.querySelector('i');
                var t = btn.querySelector('.ud-theme-toggle__text');
                if (i) i.className = 'fa-solid fa-moon';
                if (t) t.textContent = 'Dark mode';
            } else {
                root.setAttribute('data-theme', 'dark');
                localStorage.setItem('ud-theme', 'dark');
                btn.setAttribute('aria-pressed', 'true');
                var i2 = btn.querySelector('i');
                var t2 = btn.querySelector('.ud-theme-toggle__text');
                if (i2) i2.className = 'fa-solid fa-sun';
                if (t2) t2.textContent = 'Light mode';
            }
        });
    }

    function imagePreview() {
        var input = qs('#ud-image');
        var prev = qs('#ud-image-preview');
        if (!input || !prev) return;
        input.addEventListener('change', function () {
            var f = input.files && input.files[0];
            if (!f) {
                prev.hidden = true;
                prev.removeAttribute('src');
                return;
            }
            prev.src = URL.createObjectURL(f);
            prev.hidden = false;
        });
    }

    function formGuard() {
        var form = qs('#ud-rescue-form');
        if (!form) return;
        form.addEventListener('submit', function (e) {
            var la = qs('#ud-lat');
            var lo = qs('#ud-lon');
            if (!la || !lo || la.value === '' || lo.value === '') {
                e.preventDefault();
                alert('Please set a location on the map or use “Use my location”.');
            }
        });
    }

    function caseSnapshot(cases) {
        return cases
            .map(function (c) {
                return c.id + ':' + c.status + ':' + (c.tracking && c.tracking.percent);
            })
            .join('|');
    }

    function applyTrackingToDom(c) {
        var article = document.querySelector('.ud-case[data-case-id="' + c.id + '"]');
        if (!article) return;

        var tr = c.tracking || {};
        var bar = article.querySelector('.ud-progress');
        var fill = article.querySelector('.ud-progress__fill');
        if (bar && fill && tr.variant !== 'rejected') {
            var pct = tr.percent != null ? tr.percent : 0;
            bar.setAttribute('aria-valuenow', String(pct));
            fill.style.width = pct + '%';
        }

        var list = article.querySelector('.ud-timeline');
        if (list && tr.steps && tr.variant !== 'rejected') {
            var items = list.querySelectorAll('.ud-timeline__step');
            tr.steps.forEach(function (step, i) {
                if (!items[i]) return;
                items[i].classList.toggle('is-done', !!step.done);
                items[i].classList.toggle('is-active', !!step.active);
            });
        }
    }

    function pushToast(message) {
        var host = qs('#ud-toast-host');
        if (!host) return;
        var el = document.createElement('div');
        el.className = 'ud-toast';
        el.setAttribute('role', 'status');
        el.textContent = message;
        host.appendChild(el);
        setTimeout(function () {
            el.remove();
        }, 6000);
    }

    function poll() {
        var url = window.__USER_POLL_URL__;
        if (!url) return;

        var initial = window.__USER_DASHBOARD_INITIAL__ || [];
        var last = caseSnapshot(initial);

        setInterval(function () {
            fetch(url, { credentials: 'same-origin', cache: 'no-store' })
                .then(function (r) {
                    return r.json();
                })
                .then(function (data) {
                    if (!data || !data.ok || !data.cases) return;
                    var snap = caseSnapshot(data.cases);
                    if (snap !== last) {
                        data.cases.forEach(function (c) {
                            applyTrackingToDom(c);
                        });
                        var live = qs('#ud-notify-live');
                        if (live) {
                            live.textContent = 'Updated ' + new Date().toLocaleTimeString();
                        }
                        pushToast('Rescue status updated — check your request list.');
                        last = snap;
                    }
                    if (data.notifications) {
                        renderNotifications(data.notifications);
                    }
                    if (typeof data.unread_notifications !== 'undefined') {
                        setUnreadCount(Number(data.unread_notifications || 0));
                    }
                })
                .catch(function () {});
        }, 12000);
    }

    function flash() {
        var f = window.__USER_FLASH__;
        if (!f || !f.message || typeof Swal === 'undefined') return;
        var icon = f.type === 'error' ? 'error' : f.type === 'warning' ? 'warning' : 'success';
        Swal.fire({
            icon: icon,
            title: f.type === 'error' ? 'Notice' : f.type === 'warning' ? 'Update' : 'Success',
            text: f.message,
            confirmButtonColor: '#4F46E5',
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        themeSetup();
        var closeDrawer = drawerSetup();
        bindNav(closeDrawer);
        initMap();
        imagePreview();
        cameraCaptureSetup();
        formGuard();
        flash();
        poll();
        setUnreadCount(Number(qs('#ud-notify-count') ? qs('#ud-notify-count').textContent : 0));

        var useLoc = qs('#ud-use-location');
        if (useLoc) useLoc.addEventListener('click', useGeolocation);

        var searchBtn = qs('#ud-map-search-btn');
        var searchInput = qs('#ud-map-search-q');
        if (searchBtn) searchBtn.addEventListener('click', mapSearch);
        if (searchInput) {
            searchInput.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') {
                    ev.preventDefault();
                    mapSearch();
                }
            });
        }

        var markReadBtn = qs('#ud-mark-read-btn');
        if (markReadBtn) {
            markReadBtn.addEventListener('click', function () {
                fetch(window.__USER_POLL_URL__ + '?mark_read=1', { credentials: 'same-origin', cache: 'no-store' })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (!data || !data.ok) return;
                        renderNotifications(data.notifications || []);
                        setUnreadCount(Number(data.unread_notifications || 0));
                    })
                    .catch(function () {});
            });
        }
    });
})();
