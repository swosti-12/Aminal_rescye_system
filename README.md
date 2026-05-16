# RescueNet — Animal Rescue System

Smart animal rescue platform built with **HTML, CSS, JavaScript, PHP, and MySQL** (XAMPP-friendly). Reporters submit cases with GPS/map pins; rescuers share live location; admins assign the nearest available rescuer.

## Recent change: Multi-role login (tabs stay signed in)

**Problem:** PHP’s default session stores one `user_id` and one `role`. Logging in as Rescuer in tab 2 overwrote Admin in tab 1.

**Solution:** **Role slots** in the same PHP session cookie plus **per-tab context** in `sessionStorage` / `X-ARS-Role` header for AJAX.

| Piece | File | Role |
|-------|------|------|
| Slot storage + activate | `backend/SessionManager.php` | Keeps `admin`, `rescuer`, `user` logins side by side |
| Guards | `backend/auth.php` | Each page calls `require_role('admin')` etc. and re-activates that slot |
| Tab context | `assets/js/ars-session.js` | Remembers role per tab; patches `fetch()` |
| Login / logout | `login.php`, `logout.php` | Add slot on login; `?role=admin` or `?role=all` on logout |
| Optional DB audit | `database/migrate_user_sessions.sql` | Not required for demo mode |

### How to test (3 tabs)

1. Tab A → `login.php?intent=admin` → sign in as admin → open `admin_dashboard.php` (leave tab open).
2. Tab B → `login.php?intent=rescuer` → sign in as rescuer → open `rescuer_dashboard.php`.
3. Tab C → `login.php?intent=user` → sign in as user → open `user_dashboard.php`.
4. Return to Tab A → refresh → Admin should still work (no forced logout).

Use **different accounts** per role (admin@…, rescuer@…, user@…) from your `users` table.

### Logout behaviour

| URL | Effect |
|-----|--------|
| `logout.php?role=admin` | Signs out admin only; rescuer/user slots remain |
| `logout.php?role=all` | Clears every role slot |

### Setup

- **Required:** ensure `backend/SessionManager.php` exists (included automatically by `backend/auth.php`).
- **Optional:** import `database/migrate_user_sessions.sql` for session audit rows in MySQL.

No change to `users` table or passwords is required.

---

## Recent change: Human-readable addresses & maps

Previously, many screens showed only raw coordinates (e.g. `27.7172, 85.3240`). The system now adds **reverse geocoding** and **Leaflet maps** without removing existing features.

| What | How |
|------|-----|
| Readable address | [OpenStreetMap Nominatim](https://nominatim.org/) via PHP proxy |
| Map tiles & markers | [Leaflet.js](https://leafletjs.com/) + OSM tiles (free, no API key) |
| Optional DB cache | `rescue_cases.address` column (migration provided) |
| Google Maps (optional) | Rescuer live map only — set `GOOGLE_MAPS_API_KEY` in environment |

### New / updated files

| File | Purpose |
|------|---------|
| `backend/Services/GeocodingService.php` | Server-side reverse geocode + throttle (1 req/s for Nominatim policy) |
| `backend/get_address.php` | JSON API: `?lat=&lon=` optional `&case_id=` to cache address |
| `assets/js/rescuer-geocode.js` | Client: resolve `.js-rescue-location`, maps, distance hint |
| `database/migrate_rescue_address.sql` | Adds `address VARCHAR(512)` to `rescue_cases` |
| `README.md` | This document |

### UI touchpoints

- **User dashboard** — address under map pin when reporting; “View on Map” on tracked cases
- **Rescuer dashboard** — readable location on cards/modal; **dual-marker map** (red = animal, blue = your GPS); live sharing shows address + Leaflet map; distance to case when GPS was shared
- **Admin dashboard** — request cards show geocoded address + “View on Map” when a linked case has coordinates
- **Admin — Manage rescuers / Rescuer directory** — address + inline map for request location
- **Existing** lat/lon storage and `backend/update_location.php` unchanged

---

## Quick start (XAMPP)

1. Copy project to `C:\xampp\htdocs\Aminal_rescye_system`
2. Import `database/schema.sql` (and other `database/migrate_*.sql` as needed)
3. Run optional address migration:

```sql
SOURCE database/migrate_rescue_address.sql;
```

4. Configure `backend/db_config.php` if your MySQL user/password differ
5. Open `http://localhost/Aminal_rescye_system/`
6. Default admin (from schema): `admin@rescue.com` / `password`

---

## Reverse geocoding flow

```
Browser (lat, lon)
    → GET backend/get_address.php?lat=27.71&lon=85.32&case_id=5
        → GeocodingService::reverseGeocode() → Nominatim
    ← JSON { "ok": true, "address": "Street, City, Country", ... }
```

- Coordinates remain the **source of truth** in `latitude` / `longitude` columns
- `address` is an optional cache for faster display
- On report submit, `RescueSubmissionService` geocodes once server-side when possible

---

## Google Maps API key (optional)

Only the **rescuer live location** panel can use Google Maps if a key is set. Leaflet/OSM is used otherwise.

1. Create a key in [Google Cloud Console](https://console.cloud.google.com/) (Maps JavaScript API)
2. Set environment variable before Apache/PHP runs, e.g. in `httpd.conf` or system env:

```
GOOGLE_MAPS_API_KEY=your_key_here
```

3. `rescuer_dashboard.php` loads the script when the variable is non-empty (see bottom of file)

**Do not commit API keys** to git.

---

## Testing checklist

1. **Report** — User dashboard → set pin → confirm hint shows street address (not only numbers)
2. **Submit** — Submit report; in phpMyAdmin check `rescue_cases.address` if migration ran
3. **Track** — User → Request tracking → “View on Map” opens marker
4. **Rescuer** — Open assignment → “View details & map” → address + map + distance (after sharing GPS)
5. **Live GPS** — Rescuer → Location sharing → Start → address field updates; marker moves every ~12s
6. **Admin** — Rescuer directory → case shows address; map button works

---

## Common errors

| Problem | Fix |
|---------|-----|
| Rescuer dashboard blank / PHP error | Ensure `backend/Services/GeocodingService.php` exists |
| Address stays as coordinates | Internet required for Nominatim; wait ~1s between lookups; check `backend/cache/` is writable |
| `get_address.php` 401 | Log in as user, rescuer, or admin |
| Map tiles grey | Leaflet loaded in `includes/header.php`; call map after panel visible (`invalidateSize`) |
| `address` column missing | Run `database/migrate_rescue_address.sql` (optional; JS still geocodes) |
| Fatal error: SessionManager not found | Add `backend/SessionManager.php`; clear browser cache |
| Tab still logs others out | Each dashboard must call `require_role(...)`; hard-refresh tab; use separate tabs not same-tab redirect |
| AJAX uses wrong user | Ensure `assets/js/ars-session.js` loads (in `includes/footer.php`) |

---

## Tech stack

- PHP 8+ / MySQL
- Leaflet 1.9.4, OpenStreetMap tiles
- Nominatim reverse geocoding (free, usage policy: max ~1 request/second)
- Optional Flask ML API (`app.py`) for image analysis — separate from geocoding

---

## Project structure (main)

```
Aminal_rescye_system/
├── index.php, login.php, register.php
├── user_dashboard.php          → views/user/dashboard.php
├── rescuer_dashboard.php       → rescuer assignments + live map
├── admin_dashboard.php
├── rescuer_directory.php       → assign rescuer to case
├── backend/
│   ├── SessionManager.php      → multi-role session slots
│   ├── auth.php                → require_login / require_role
│   ├── get_address.php         → reverse geocode API
│   ├── update_location.php     → live rescuer GPS (unchanged)
│   └── Services/
│       ├── GeocodingService.php
│       └── RescueSubmissionService.php
├── assets/js/
│   ├── ars-session.js          → per-tab role + fetch header
│   ├── rescuer-geocode.js
│   └── user-dashboard.js
└── database/
    ├── schema.sql
    ├── migrate_rescue_address.sql
    └── migrate_user_sessions.sql  (optional)
```

For full SRS and AI workflow, see `docs/SRS.md` and `docs/AI_RESCUE_IMPLEMENTATION.md`.
