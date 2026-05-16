<?php
require_once __DIR__ . '/backend/auth.php';
require_login();
require_role('admin');
require_once __DIR__ . '/db.php';

$activePage = 'manage_rescuers';
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: 0;
$statusFilter = strtolower(trim((string)($_GET['status_filter'] ?? 'all')));
$search = trim((string)($_GET['search'] ?? ''));
$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

if ($requestId <= 0) {
    $latestPendingStmt = $pdo->query("SELECT id FROM rescue_requests WHERE status = 'pending' ORDER BY id DESC LIMIT 1");
    $requestId = (int)($latestPendingStmt->fetchColumn() ?: 0);
}

$requestData = null;
if ($requestId > 0) {
    $requestStmt = $pdo->prepare('SELECT * FROM rescue_requests WHERE id = ?');
    $requestStmt->execute([$requestId]);
    $requestData = $requestStmt->fetch();
}

function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float
{
    $earthRadiusKm = 6371;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) * sin($dLat / 2)
        + cos(deg2rad($lat1)) * cos(deg2rad($lat2))
        * sin($dLon / 2) * sin($dLon / 2);
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earthRadiusKm * $c;
}

$rescuers = [];
if ($requestData) {
    $rescuerSql = 'SELECT id, name, phone, status, latitude, longitude FROM rescuers';
    $params = [];
    $where = [];

    if (in_array($statusFilter, ['available', 'busy', 'offline'], true)) {
        $where[] = 'status = ?';
        $params[] = $statusFilter;
    }

    if ($search !== '') {
        $where[] = '(name LIKE ? OR phone LIKE ?)';
        $params[] = '%' . $search . '%';
        $params[] = '%' . $search . '%';
    }

    if ($where) {
        $rescuerSql .= ' WHERE ' . implode(' AND ', $where);
    }

    $rescuerSql .= ' ORDER BY name ASC';
    $rescuerStmt = $pdo->prepare($rescuerSql);
    $rescuerStmt->execute($params);
    $rescuers = $rescuerStmt->fetchAll();

    foreach ($rescuers as &$rescuer) {
        $hasLocation = $rescuer['latitude'] !== null && $rescuer['longitude'] !== null;
        $rescuer['is_available'] = $rescuer['status'] === 'available' ? 1 : 0;
        $rescuer['distance_km'] = $hasLocation
            ? haversine_km((float)$requestData['latitude'], (float)$requestData['longitude'], (float)$rescuer['latitude'], (float)$rescuer['longitude'])
            : PHP_FLOAT_MAX;
    }
    unset($rescuer);

    usort($rescuers, static function (array $a, array $b): int {
        return $a['distance_km'] <=> $b['distance_km'];
    });
}

$body_class = 'admin-dashboard-page';
require_once __DIR__ . '/includes/header.php';
?>
<style>
.manage-shell { max-width: 1320px; margin: 1.5rem auto; padding: 0 1rem; display: grid; grid-template-columns: 260px minmax(0, 1fr); gap: 1.25rem; }
.manage-sidebar { background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:16px; box-shadow:0 14px 35px rgba(15,23,42,0.08); padding:1rem; height: fit-content; position: sticky; top: 90px; }
.manage-sidebar h3 { margin: 0.2rem 0 1rem; font-size: 1rem; color: #0f172a; }
.manage-sidebar a { display:flex; align-items:center; gap:0.6rem; padding:0.68rem 0.8rem; border-radius:10px; color:#475569; text-decoration:none; font-weight:600; transition: all 0.2s ease; }
.manage-sidebar a:hover { background:#eef2ff; color:#3730a3; }
.manage-sidebar a.active { background:linear-gradient(135deg,#4f46e5,#6366f1); color:#fff; }
.manage-main { min-width: 0; }
.manage-head { display:flex; justify-content:space-between; align-items:flex-start; gap:1rem; margin-bottom:1rem; }
.manage-head h1 { margin:0; font-size:1.5rem; color:#0f172a; }
.manage-head p { margin:0.35rem 0 0; color:#64748b; font-size:0.92rem; }
.request-focus { background:#fff; border-radius:14px; border:1px solid rgba(15,23,42,0.08); box-shadow:0 8px 20px rgba(15,23,42,0.06); padding:1rem; margin-bottom:1rem; display:grid; gap:0.8rem; }
.request-meta { display:grid; gap:0.6rem; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); }
.request-box { background:#f8fafc; border-radius:10px; padding:0.65rem 0.75rem; }
.request-box span { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:.06em; color:#94a3b8; margin-bottom:0.25rem; }
.filters { background:#fff; border-radius:14px; border:1px solid rgba(15,23,42,0.08); box-shadow:0 8px 20px rgba(15,23,42,0.06); padding:0.9rem; margin-bottom:1rem; }
.filters form { display:grid; grid-template-columns:repeat(auto-fit,minmax(160px,1fr)); gap:0.65rem; align-items:end; }
.filters label { font-size:0.74rem; color:#64748b; display:block; margin-bottom:0.35rem; font-weight:700; text-transform:uppercase; letter-spacing:.04em; }
.filters input,.filters select { width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:0.55rem 0.65rem; font-size:0.9rem; }
.btn-primary-slim { border:none; border-radius:10px; background:#4f46e5; color:#fff; font-weight:700; cursor:pointer; padding:0.58rem 0.85rem; }
.cards { display:grid; gap:1rem; grid-template-columns:repeat(auto-fill,minmax(260px,1fr)); }
.rescuer-card { background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:14px; box-shadow:0 10px 25px rgba(15,23,42,0.06); padding:1rem; transition: transform .2s ease, box-shadow .2s ease; display:flex; flex-direction:column; gap:0.65rem; }
.rescuer-card:hover { transform:translateY(-4px); box-shadow:0 16px 34px rgba(79,70,229,0.16); }
.rescuer-top { display:flex; justify-content:space-between; gap:0.6rem; align-items:flex-start; }
.rescuer-name { margin:0; font-size:1rem; color:#0f172a; }
.status-badge { border-radius:999px; padding:0.2rem 0.55rem; font-size:0.72rem; font-weight:700; text-transform:capitalize; }
.status-available { background:#dcfce7; color:#166534; }
.status-busy { background:#fee2e2; color:#991b1b; }
.status-offline { background:#e2e8f0; color:#334155; }
.line { color:#475569; font-size:0.86rem; display:flex; justify-content:space-between; gap:0.5rem; }
.availability { display:flex; align-items:center; gap:0.45rem; font-size:0.83rem; color:#334155; }
.dot { width:10px; height:10px; border-radius:999px; display:inline-block; }
.dot-green { background:#22c55e; box-shadow:0 0 0 3px rgba(34,197,94,0.2); }
.dot-red { background:#ef4444; box-shadow:0 0 0 3px rgba(239,68,68,0.2); }
.assign-form { margin-top:auto; }
.btn-assign { width:100%; border:none; border-radius:10px; background:linear-gradient(135deg,#2563eb,#4f46e5); color:#fff; padding:0.6rem 0.75rem; font-weight:700; cursor:pointer; transition:opacity .2s ease; }
.btn-assign:disabled { opacity:0.45; cursor:not-allowed; }
.empty-box { background:#fff; border:1px dashed #cbd5e1; border-radius:14px; padding:1.5rem; text-align:center; color:#64748b; }
.toast { border-radius: 10px; margin-bottom: 1rem; padding: 0.75rem 0.85rem; font-size: 0.9rem; }
.toast-ok { background: #dcfce7; color: #166534; }
.toast-err { background: #fee2e2; color: #991b1b; }
@media (max-width: 980px) {
    .manage-shell { grid-template-columns: 1fr; }
    .manage-sidebar { position: static; }
}
</style>

<div class="manage-shell">
    <aside class="manage-sidebar">
        <h3>Admin menu</h3>
        <a href="admin_dashboard.php"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
        <a href="manage_rescuer.php" class="active"><i class="fa-solid fa-user-nurse"></i> Manage Rescuers</a>
        <a href="admin_dashboard.php?tab=requests"><i class="fa-solid fa-clipboard-list"></i> Rescue Requests</a>
        <a href="logout.php"><i class="fa-solid fa-right-from-bracket"></i> Logout</a>
    </aside>

    <section class="manage-main">
        <?php if ($msg !== ''): ?>
            <div class="toast toast-ok"><?php echo htmlspecialchars($msg); ?></div>
        <?php endif; ?>
        <?php if ($err !== ''): ?>
            <div class="toast toast-err"><?php echo htmlspecialchars($err); ?></div>
        <?php endif; ?>

        <div class="manage-head">
            <div>
                <h1>Manage Rescuers</h1>
                <p>Review available responders by nearest distance and assign to pending rescue requests.</p>
            </div>
        </div>

        <?php if (!$requestData): ?>
            <div class="empty-box">No pending request selected. Create a `pending` row in `rescue_requests` to start assignment.</div>
        <?php else: ?>
            <article class="request-focus">
                <div><strong>Rescue Request #<?php echo (int)$requestData['id']; ?></strong></div>
                <div class="request-meta">
                    <div class="request-box"><span>User</span><?php echo htmlspecialchars($requestData['user_name']); ?></div>
                    <div class="request-box"><span>Status</span><?php echo htmlspecialchars($requestData['status']); ?></div>
                    <div class="request-box request-box--wide">
                        <span>Location</span>
                        <p class="js-rescue-location geo-location-block__addr"
                           data-lat="<?php echo htmlspecialchars((string)$requestData['latitude']); ?>"
                           data-lon="<?php echo htmlspecialchars((string)$requestData['longitude']); ?>"
                           data-needs-geocode="1"
                           data-skip-geocode="0"><?php echo htmlspecialchars((string)$requestData['latitude'] . ', ' . (string)$requestData['longitude']); ?></p>
                        <button type="button" class="btn btn-secondary btn-sm js-manage-view-map"
                                data-lat="<?php echo htmlspecialchars((string)$requestData['latitude']); ?>"
                                data-lon="<?php echo htmlspecialchars((string)$requestData['longitude']); ?>"
                                data-title="Request #<?php echo (int)$requestData['id']; ?>">View on Map</button>
                        <div id="manage-request-map" class="geo-map-mini" hidden></div>
                    </div>
                </div>
            </article>

            <section class="filters">
                <form method="get">
                    <input type="hidden" name="request_id" value="<?php echo (int)$requestData['id']; ?>">
                    <div>
                        <label for="status_filter">Filter by status</label>
                        <select name="status_filter" id="status_filter">
                            <option value="all" <?php echo $statusFilter === 'all' ? 'selected' : ''; ?>>All</option>
                            <option value="available" <?php echo $statusFilter === 'available' ? 'selected' : ''; ?>>Available</option>
                            <option value="busy" <?php echo $statusFilter === 'busy' ? 'selected' : ''; ?>>Busy</option>
                            <option value="offline" <?php echo $statusFilter === 'offline' ? 'selected' : ''; ?>>Offline</option>
                        </select>
                    </div>
                    <div>
                        <label for="search">Search rescuer</label>
                        <input type="text" name="search" id="search" value="<?php echo htmlspecialchars($search); ?>" placeholder="Name or phone">
                    </div>
                    <div>
                        <button type="submit" class="btn-primary-slim">Apply</button>
                    </div>
                </form>
            </section>

            <?php if (!$rescuers): ?>
                <div class="empty-box">No rescuers found for this filter.</div>
            <?php else: ?>
                <div class="cards">
                    <?php foreach ($rescuers as $rescuer): ?>
                        <?php
                        $distanceText = $rescuer['distance_km'] === PHP_FLOAT_MAX
                            ? 'N/A'
                            : number_format((float)$rescuer['distance_km'], 2) . ' km';
                        $statusClass = 'status-' . ($rescuer['status'] ?: 'offline');
                        $isAssignable = $rescuer['status'] === 'available';
                        ?>
                        <article class="rescuer-card">
                            <div class="rescuer-top">
                                <h3 class="rescuer-name"><?php echo htmlspecialchars($rescuer['name']); ?></h3>
                                <span class="status-badge <?php echo htmlspecialchars($statusClass); ?>"><?php echo htmlspecialchars($rescuer['status']); ?></span>
                            </div>
                            <div class="availability">
                                <span class="dot <?php echo $isAssignable ? 'dot-green' : 'dot-red'; ?>"></span>
                                <?php echo $isAssignable ? 'Available now' : 'Not available'; ?>
                            </div>
                            <div class="line"><span>Phone</span><strong><?php echo htmlspecialchars($rescuer['phone'] ?: '-'); ?></strong></div>
                            <div class="line"><span>Distance</span><strong><?php echo htmlspecialchars($distanceText); ?></strong></div>
                            <div class="line"><span>Coordinates</span><strong><?php echo htmlspecialchars((string)$rescuer['latitude']); ?>, <?php echo htmlspecialchars((string)$rescuer['longitude']); ?></strong></div>
                            <form method="post" action="assign_rescuer.php" class="assign-form">
                                <input type="hidden" name="request_id" value="<?php echo (int)$requestData['id']; ?>">
                                <input type="hidden" name="rescuer_id" value="<?php echo (int)$rescuer['id']; ?>">
                                <button type="submit" class="btn-assign" <?php echo $isAssignable ? '' : 'disabled'; ?>>
                                    Assign
                                </button>
                            </form>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
</div>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
<script src="assets/js/rescuer-geocode.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.assign-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('.btn-assign');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.textContent = 'Assigning...';
        });
    });
    document.querySelectorAll('.js-manage-view-map').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (!window.LocationGeocode) return;
            var lat = parseFloat(btn.getAttribute('data-lat') || '');
            var lon = parseFloat(btn.getAttribute('data-lon') || '');
            if (isNaN(lat) || isNaN(lon)) return;
            LocationGeocode.openInlineMap('manage-request-map', lat, lon, btn.getAttribute('data-title') || 'Rescue request');
        });
    });
});
</script>

