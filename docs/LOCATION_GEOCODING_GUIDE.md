# Location & Reverse Geocoding — Complete Implementation Guide

**RescueNet (AI-Powered Animal Rescue System)**  
Stack: HTML, CSS, JavaScript, PHP, MySQL, **Leaflet.js**, **OpenStreetMap Nominatim** (free — no Google API key).

This guide matches your **actual project folder** (`Aminal_rescye_system/`). Most code is already implemented; use this as your final-year reference and checklist.

---

## 10. Project structure (your repo)

```
Aminal_rescye_system/
├── assets/
│   ├── css/
│   │   ├── style.css              ← shared .geo-* location styles
│   │   └── user-dashboard.css     ← map panel styles
│   └── js/
│       ├── user-dashboard.js      ← user report map + GPS + address
│       ├── rescuer-geocode.js     ← reverse geocode + maps + distance
│       └── ars-session.js         ← multi-tab login (separate feature)
├── backend/
│   ├── db_config.php              ← PDO connection
│   ├── auth.php                   ← login guards
│   ├── get_address.php            ← JSON reverse-geocode API
│   ├── update_location.php        ← rescuer live GPS (replaces update_rescuer_location.php)
│   ├── api/
│   │   ├── user_dashboard_poll.php ← tracking poll (replaces fetch_tracking.php)
│   │   └── admin_notifications.php ← admin map + rescuer markers
│   └── Services/
│       ├── GeocodingService.php   ← Nominatim reverse geocode (PHP)
│       └── RescueSubmissionService.php ← saves lat/lon/address on report
├── database/
│   ├── schema.sql
│   └── migrate_rescue_address.sql ← add address column
├── views/user/dashboard.php       ← user report + tracking UI
├── user_dashboard.php
├── rescuer_dashboard.php
├── admin_dashboard.php
├── rescuer_directory.php
├── manage_rescuer.php
└── includes/header.php            ← Leaflet CDN
```

---

## 1. Database changes (MySQL)

### Why store latitude, longitude, AND address?

| Field | Purpose |
|-------|---------|
| `latitude` / `longitude` | Exact pin for maps, distance math, live tracking — **source of truth** |
| `address` | Human-readable text for UI, reports, admin — **cache** from Nominatim |

Coordinates never change meaning; addresses are looked up once and cached.

### Tables already used

**`rescue_cases`** (animal report location):

```sql
USE animal_rescue;

-- Run once in phpMyAdmin or MySQL CLI:
SOURCE database/migrate_rescue_address.sql;
```

Migration file:

```sql
ALTER TABLE rescue_cases
    ADD COLUMN IF NOT EXISTS address VARCHAR(512) NULL DEFAULT NULL
    AFTER longitude;
```

**`rescuer_locations`** (live rescuer GPS — already in schema):

```sql
-- rescuer_id, latitude, longitude, status, updated_at
```

**`users`** (optional last known position):

```sql
-- latitude, longitude on users table
```

### Example row after report

| id | latitude | longitude | address |
|----|----------|-----------|---------|
| 12 | 27.694500 | 85.342000 | New Baneshwor, Kathmandu, Bagmati Province, Nepal |

---

## 2. HTML UI changes (by module)

### A. User report (`views/user/dashboard.php`)

Already includes:

- Hidden fields: `#ud-lat`, `#ud-lon`
- Map container: `#ud-map`
- **Use my location**: `#ud-use-location`
- Address preview: `#ud-address-text` (shows 📍 readable address)
- Optional notes: `location_text`
- Tracking panel: `.js-rescue-location` + **View on Map**

### B. Rescuer (`rescuer_dashboard.php`)

- Live sharing: `#loc-lat`, `#loc-lon`, `#loc-address`, `#rescuer-live-map`
- Case modal: `#rescuer-modal-loc`, `#rescuer-detail-map` (dual markers: animal + rescuer)

### C. Admin (`admin_dashboard.php`, `rescuer_directory.php`, `manage_rescuer.php`)

- Request cards: `.js-rescue-location` + **View on Map**
- Notifications tab: `#admin-rescuer-map` (live rescuer markers)
- Directory: distance (km) + geocoded location

---

## 3. CSS styling

Shared classes in `assets/css/style.css`:

```css
/* Address preview under map (user report) */
.geo-address-preview { ... }

/* Case / admin location card */
.geo-location-block { ... }
.geo-location-block__addr { ... }

/* Inline Leaflet map */
.geo-map-mini { height: 220px; ... }

/* Map legend dots (animal vs rescuer) */
.geo-legend-dot--animal { background: #dc2626; }
.geo-legend-dot--rescuer { background: #3b82f6; }
```

User dashboard map sizing: `assets/css/user-dashboard.css` → `.ud-map`

---

## 4. JavaScript implementation

### CDN (in `includes/header.php`)

```html
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
```

### A. Geolocation API (`assets/js/user-dashboard.js`)

```javascript
navigator.geolocation.getCurrentPosition(
    function (pos) {
        var lat = pos.coords.latitude;
        var lng = pos.coords.longitude;
        setCoords(lat, lng);  // updates hidden inputs + map marker
    },
    function () {
        alert('Could not read GPS. Allow location or tap the map.');
    },
    { enableHighAccuracy: true, timeout: 12000 }
);
```

### B. Reverse geocoding (via your PHP proxy — recommended)

**Do not call Nominatim directly from the browser** (CORS + rate limits). Use:

```
GET backend/get_address.php?lat=27.6945&lon=85.3420&case_id=12
```

Response:

```json
{
  "ok": true,
  "address": "New Baneshwor, Kathmandu, Nepal",
  "latitude": 27.6945,
  "longitude": 85.342
}
```

Client (`assets/js/rescuer-geocode.js`):

```javascript
LocationGeocode.fetchAddress(lat, lon, caseId).then(function (addr) {
    element.textContent = '📍 ' + addr;
});
```

### C. Auto-fill address

On pin move / GPS, `setCoords()` in `user-dashboard.js` updates `#ud-address-text`.

### D. Leaflet map (user report)

```javascript
map = L.map('ud-map').setView([27.7172, 85.3240], 13);
L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap'
}).addTo(map);
marker = L.marker(center, { draggable: true }).addTo(map);
```

### E. Live location (rescuer — 12s interval)

`rescuer_dashboard.php` → `captureAndSync()` → `POST backend/update_location.php`:

```json
{ "action": "update", "latitude": 27.71, "longitude": 85.32 }
```

Map marker moves with `locationMarker.setLatLng([lat, lon])`.

*Note: Your project uses **12 seconds** (not 5) to respect Nominatim and server load.*

### F. AJAX to PHP

Report submit uses normal **form POST** to `user_dashboard.php` with hidden `latitude` / `longitude`.  
Server geocodes in `RescueSubmissionService.php` and saves `address`.

Live GPS uses **fetch JSON** to `backend/update_location.php`.

### G. Distance calculation

Haversine in `assets/js/rescuer-geocode.js`:

```javascript
var km = LocationGeocode.haversineKm(rescuerLat, rescuerLon, caseLat, caseLon);
```

PHP equivalent in `manage_rescuer.php` → `haversine_km()`.

---

## 5. PHP backend

### Database connection

`backend/db_config.php` — PDO instance `$pdo`.

### Reverse geocode API — `backend/get_address.php`

- Requires login (`auth.php`)
- Calls `GeocodingService::reverseGeocode($lat, $lon)`
- Optional: `case_id` caches address on `rescue_cases`

### Save report location — not a separate `save_location.php`

Flow:

1. `user_dashboard.php` POST → `UserDashboardController::handleReportSubmit()`
2. `RescueSubmissionService::submitFromUser()` geocodes and inserts case

### Update rescuer location — `backend/update_location.php`

```php
// JSON body: { "action": "start|update|stop", "latitude": ..., "longitude": ... }
$stmt = $pdo->prepare('UPDATE users SET latitude=?, longitude=? WHERE id=?');
// Also upserts rescuer_locations table
```

### Tracking poll — `backend/api/user_dashboard_poll.php`

Returns case status JSON every 12s (user dashboard poll).

### Admin view

- `admin_dashboard.php` — request queue with geocoded location
- `backend/api/admin_notifications.php?rescuer_locations=1` — markers for map

### GeocodingService (core)

`backend/Services/GeocodingService.php`:

- Calls `https://nominatim.openstreetmap.org/reverse?lat=...&lon=...`
- User-Agent header required by OSM policy
- Throttle ~1 request/second via `backend/cache/nominatim_throttle.txt`

---

## 6. MySQL data fetching (examples)

### Admin — readable address on request card

```php
$caseAddr = trim($r['case_address'] ?? '');
$adminLocText = $caseAddr !== '' ? $caseAddr : ($r['location'] ?? '');
```

If no cached address, JS class `.js-rescue-location` calls `get_address.php`.

### Rescuer — case location

```php
function rescuer_case_location_meta(array $case): array {
    if (!empty($case['address'])) {
        return ['text' => $case['address'], 'needs_geocode' => false, ...];
    }
    // else geocode via JS
}
```

### User — tracking list

```php
$caseAddr = trim((string) ($report['address'] ?? ''));
$caseLocText = $caseAddr !== '' ? $caseAddr : "$caseLat, $caseLon";
```

---

## 7. Leaflet.js integration

| Feature | Where |
|---------|--------|
| Tiles | OpenStreetMap `{s}.tile.openstreetmap.org` |
| User pin | `user-dashboard.js` draggable marker |
| Rescuer live | `#rescuer-live-map` |
| Case detail | `#rescuer-detail-map` dual markers |
| Admin | `#admin-rescuer-map` |
| Popup | `.bindPopup('Animal reported location')` |

---

## 8. Live tracking system

```
Rescuer browser                Server                    Admin/User
     |                            |                            |
     |-- GPS every 12s ---------->| update_location.php        |
     |                            | UPDATE rescuer_locations   |
     |                            |                            |
     |                            |<-- poll 10s ---------------|
     |                            | admin_notifications.php    |
     |                            | JSON rescuer_locations     |
     |                            |--------------------------->| update Leaflet markers
```

Frontend: `setInterval(captureAndSync, 12000)` in rescuer dashboard.  
Backend: stores coordinates; address filled via `get_address.php` when UI requests it.

---

## 9. Final system flow

```
User clicks "Use My Location"
    → navigator.geolocation
    → setCoords(lat, lon)
    → fetch backend/get_address.php
    →显示 "📍 New Baneshwor, Kathmandu, Nepal"
    → User submits form
    → RescueSubmissionService geocodes again (server) + INSERT rescue_cases
    → Admin / Rescuer see address + map
    → Rescuer shares live GPS → update_location.php
    → Admin map shows blue markers; distance via haversine
```

---

## 11. Professional improvements (roadmap)

| Feature | Status / idea |
|---------|----------------|
| Nearest rescuer | `manage_rescuer.php` + `backend/get_nearby_rescuers.php` |
| Route navigation | Link to OSM: `https://www.openstreetmap.org/directions?to=lat,lon` |
| ETA | `distance_km / average_speed_kmh` (e.g. 30 km/h in city) |
| Heatmap | Leaflet.heat plugin on pending cases |
| Loading UI | `aria-busy` on `.js-rescue-location` while geocoding |
| GPS denied | Alert + "tap map to set pin" (already in user-dashboard.js) |
| Fallback | Show `lat, lon` if Nominatim fails |

---

## 12. Setup checklist (XAMPP)

1. Start Apache + MySQL.
2. Import `database/schema.sql`.
3. Run `SOURCE database/migrate_rescue_address.sql;`
4. Ensure `backend/cache/` is writable (Nominatim throttle file).
5. Test user report → confirm `rescue_cases.address` populated.
6. Test rescuer live sharing → confirm `rescuer_locations` updates.
7. Test admin notifications map tab.

---

## File map (requested names → your project)

| Guide name | Your project file |
|------------|-------------------|
| `save_location.php` | `RescueSubmissionService.php` + `user_dashboard.php` POST |
| `update_rescuer_location.php` | `backend/update_location.php` |
| `fetch_tracking.php` | `backend/api/user_dashboard_poll.php` |
| `admin_view.php` | `admin_dashboard.php` + `rescuer_directory.php` |

---

## Security notes

- Use prepared statements (already in repositories).
- Proxy Nominatim through PHP (`get_address.php`), not exposed API keys.
- Require `auth.php` on all location endpoints.
- Rate-limit geocode (~1 req/s) to comply with OSM usage policy.

For multi-role tab login, see `README.md` → **Multi-role login** section.
