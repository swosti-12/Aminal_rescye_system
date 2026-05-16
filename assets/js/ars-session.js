/**
 * Per-tab role context for RescueNet multi-login.
 * sessionStorage is isolated per browser tab; sends X-ARS-Role on fetch() calls.
 */
(function (global) {
    'use strict';

    var STORAGE_KEY = 'ars_tab_role';

    function normalizeRole(role) {
        role = String(role || '').toLowerCase().trim();
        if (role === 'administrator') return 'admin';
        return role === 'admin' || role === 'rescuer' || role === 'user' ? role : '';
    }

    function detectRoleFromPath() {
        var path = (global.location && global.location.pathname) || '';
        var file = path.split('/').pop() || '';
        if (file === 'admin_dashboard.php' || file === 'manage_rescuer.php' || file === 'rescuer_directory.php' || file === 'assign_rescuer.php') {
            return 'admin';
        }
        if (file === 'rescuer_dashboard.php' || file === 'update_availability.php') {
            return 'rescuer';
        }
        if (file === 'user_dashboard.php' || file === 'ai_rescue_request.php') {
            return 'user';
        }
        var params = new URLSearchParams(global.location.search || '');
        return normalizeRole(params.get('intent') || params.get('ars_ctx') || '');
    }

    function getTabRole() {
        try {
            var stored = normalizeRole(global.sessionStorage.getItem(STORAGE_KEY));
            if (stored) return stored;
        } catch (e) {
            /* private mode */
        }
        var detected = detectRoleFromPath();
        if (detected) {
            setTabRole(detected);
        }
        return detected;
    }

    function setTabRole(role) {
        role = normalizeRole(role);
        if (!role) return;
        try {
            global.sessionStorage.setItem(STORAGE_KEY, role);
        } catch (e) {
            /* ignore */
        }
    }

    function patchFetch() {
        if (!global.fetch) return;
        var original = global.fetch.bind(global);
        global.fetch = function (input, init) {
            init = init || {};
            var role = getTabRole();
            if (role) {
                var headers = new Headers(init.headers || {});
                if (!headers.has('X-ARS-Role')) {
                    headers.set('X-ARS-Role', role);
                }
                init.headers = headers;
                init.credentials = init.credentials || 'same-origin';
            }
            return original(input, init);
        };
    }

    var detected = detectRoleFromPath();
    if (detected) {
        setTabRole(detected);
    }
    patchFetch();

    global.ArsSession = {
        getTabRole: getTabRole,
        setTabRole: setTabRole,
        normalizeRole: normalizeRole,
    };
})(typeof window !== 'undefined' ? window : this);
