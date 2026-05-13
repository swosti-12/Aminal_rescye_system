// Navigation, active links, scroll behaviors, and geolocation helpers
document.addEventListener('DOMContentLoaded', () => {

    /* ===== Active nav link ===== */
    const currentPath = window.location.pathname.split('/').pop() || 'index.php';
    document.querySelectorAll('.nav-links a').forEach((link) => {
        const href = link.getAttribute('href');
        if (href === currentPath || (currentPath === '' && href === 'index.php')) {
            link.classList.add('active');
        }
    });

    /* ===== Mobile nav toggle ===== */
    const toggle = document.getElementById('nav-toggle');
    const shell = document.querySelector('.nav-shell');
    if (toggle && shell) {
        toggle.addEventListener('click', () => {
            const open = !shell.classList.contains('is-open');
            shell.classList.toggle('is-open', open);
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        shell.querySelectorAll('a').forEach((a) => {
            a.addEventListener('click', () => {
                if (window.matchMedia('(max-width: 1040px)').matches) {
                    shell.classList.remove('is-open');
                    toggle.setAttribute('aria-expanded', 'false');
                }
            });
        });
        const mq = window.matchMedia('(min-width: 1041px)');
        const closeIfDesktop = () => {
            if (mq.matches) {
                shell.classList.remove('is-open');
                toggle.setAttribute('aria-expanded', 'false');
            }
        };
        mq.addEventListener('change', closeIfDesktop);
        window.addEventListener('resize', closeIfDesktop);
    }

    /* ===== Navbar scroll-shrink ===== */
    const navbar = document.getElementById('main-navbar');
    if (navbar) {
        let ticking = false;
        window.addEventListener('scroll', () => {
            if (!ticking) {
                window.requestAnimationFrame(() => {
                    if (window.scrollY > 60) {
                        navbar.classList.add('scrolled');
                    } else {
                        navbar.classList.remove('scrolled');
                    }
                    ticking = false;
                });
                ticking = true;
            }
        }, { passive: true });
    }

    /* ===== Back to top button ===== */
    const backToTop = document.getElementById('back-to-top');
    if (backToTop) {
        window.addEventListener('scroll', () => {
            if (window.scrollY > 500) {
                backToTop.classList.add('visible');
            } else {
                backToTop.classList.remove('visible');
            }
        }, { passive: true });
        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    /* ===== Scroll reveal ===== */
    const reveals = document.querySelectorAll('.reveal');
    if (reveals.length && 'IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
            entries.forEach((entry) => {
                if (entry.isIntersecting) {
                    entry.target.classList.add('revealed');
                    observer.unobserve(entry.target);
                }
            });
        }, { threshold: 0.12, rootMargin: '0px 0px -40px 0px' });
        reveals.forEach((el) => observer.observe(el));
    } else {
        reveals.forEach((el) => el.classList.add('revealed'));
    }
});

/** One-shot capture for forms (lat/lon inputs). */
function requestLocation(latInputId, lonInputId) {
    if (navigator.geolocation) {
        navigator.geolocation.getCurrentPosition(
            (position) => {
                document.getElementById(latInputId).value = position.coords.latitude;
                document.getElementById(lonInputId).value = position.coords.longitude;
                Swal.fire({
                    icon: 'success',
                    title: 'Location Captured!',
                    text: 'Your current location has been fetched successfully.',
                    confirmButtonColor: '#4F46E5',
                });
            },
            (error) => {
                console.error('Error getting location:', error);
                Swal.fire({
                    icon: 'error',
                    title: 'Location Access Denied',
                    text: 'Please allow location access to auto-fill or enter manually.',
                    confirmButtonColor: '#4F46E5',
                });
            },
        );
    } else {
        Swal.fire({
            icon: 'warning',
            title: 'Not Supported',
            text: 'Geolocation is not supported by your browser.',
            confirmButtonColor: '#4F46E5',
        });
    }
}

/* ===== Live location IIFE ===== */
(function () {
    const API_URL = 'backend/update_location.php';
    const MIN_INTERVAL_MS = 12000;
    let watchId = null;
    let lastSent = 0;
    let onUpdate = null;

    function postLocation(lat, lng) {
        const now = Date.now();
        if (now - lastSent < MIN_INTERVAL_MS) {
            return;
        }
        lastSent = now;
        fetch(API_URL, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ latitude: lat, longitude: lng }),
        })
            .then((r) => r.json())
            .then((data) => {
                if (typeof onUpdate === 'function') {
                    onUpdate(data.ok ? 'synced' : 'error', data);
                }
            })
            .catch(() => {
                if (typeof onUpdate === 'function') {
                    onUpdate('error', null);
                }
            });
    }

    window.RescueNetLiveLocation = {
        start(callbacks) {
            if (!navigator.geolocation) {
                if (callbacks && callbacks.onStatus) {
                    callbacks.onStatus('unsupported');
                }
                return;
            }
            onUpdate = callbacks && callbacks.onUpdate ? callbacks.onUpdate : null;
            this.stop();
            watchId = navigator.geolocation.watchPosition(
                (pos) => {
                    const lat = pos.coords.latitude;
                    const lng = pos.coords.longitude;
                    postLocation(lat, lng);
                    if (callbacks && callbacks.onPosition) {
                        callbacks.onPosition(pos);
                    }
                },
                (err) => {
                    if (callbacks && callbacks.onStatus) {
                        callbacks.onStatus('error', err);
                    }
                },
                { enableHighAccuracy: true, maximumAge: 10000, timeout: 20000 },
            );
            if (callbacks && callbacks.onStatus) {
                callbacks.onStatus('watching');
            }
        },
        stop() {
            if (watchId != null) {
                navigator.geolocation.clearWatch(watchId);
                watchId = null;
            }
            onUpdate = null;
        },
    };
})();
