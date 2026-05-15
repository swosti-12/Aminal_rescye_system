<?php
require_once 'backend/auth.php';
require_once __DIR__ . '/backend/Services/GeocodingService.php';
require_login();
require_role('rescuer');

$msg = '';
$rescuerId = (int)$_SESSION['user_id'];

function rescuer_audit_log(PDO $pdo, int $rescuerId, ?int $caseId, string $action, ?string $details = null): void {
    try {
        $pdo->prepare(
            'INSERT INTO rescuer_activity_log (rescuer_id, case_id, action_type, details) VALUES (?,?,?,?)'
        )->execute([$rescuerId, $caseId, $action, $details]);
    } catch (Throwable $e) {
        // Table may not exist until migrate_rescuer_dashboard.sql is run
    }
}

function rescuer_owns_case(PDO $pdo, int $caseId, int $rescuerId): bool {
    $st = $pdo->prepare('SELECT id FROM rescue_cases WHERE id = ? AND assigned_rescuer_id = ?');
    $st->execute([$caseId, $rescuerId]);
    return (bool)$st->fetch();
}

/** @return array{text: string, needs_geocode: bool, lat: ?float, lon: ?float, case_id: int} */
function rescuer_case_location_meta(array $case): array {
    $lat = isset($case['latitude']) ? (float)$case['latitude'] : null;
    $lon = isset($case['longitude']) ? (float)$case['longitude'] : null;
    $coords = ($lat !== null && $lon !== null)
        ? GeocodingService::coordinateFallback($lat, $lon)
        : '—';
    $caseId = (int)($case['id'] ?? 0);

    if (!empty($case['request_location'])) {
        return ['text' => trim((string)$case['request_location']), 'needs_geocode' => false, 'lat' => $lat, 'lon' => $lon, 'case_id' => $caseId];
    }
    if (!empty($case['address'])) {
        return ['text' => trim((string)$case['address']), 'needs_geocode' => false, 'lat' => $lat, 'lon' => $lon, 'case_id' => $caseId];
    }
    return ['text' => $coords, 'needs_geocode' => true, 'lat' => $lat, 'lon' => $lon, 'case_id' => $caseId];
}

function rescuer_location_label(string $text): string {
    $text = trim($text);
    if ($text === '' || stripos($text, 'location:') === 0) {
        return $text !== '' ? $text : 'Location: —';
    }
    return 'Location: ' . $text;
}

function notify_reporter(PDO $pdo, int $caseId, string $message, string $category = 'status_update'): void {
    try {
        $st = $pdo->prepare('SELECT reporter_id FROM rescue_cases WHERE id = ? LIMIT 1');
        $st->execute([$caseId]);
        $row = $st->fetch();
        if (!$row || empty($row['reporter_id'])) {
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO user_notifications (user_id, rescue_id, message, category, is_read, created_at) VALUES (?, ?, ?, ?, 0, NOW())"
        );
        $ins->execute([(int)$row['reporter_id'], $caseId, $message, $category]);
    } catch (Throwable $e) {
        error_log('notify_reporter failed: ' . $e->getMessage());
    }
}

function notify_admin(PDO $pdo, int $caseId, int $rescuerId, string $message, string $category = 'progress_update'): void {
    try {
        // Insert for all admins (admin_id = NULL means broadcast to all)
        $pdo->prepare(
            "INSERT INTO admin_notifications (admin_id, case_id, rescuer_id, message, category, is_read, created_at)
             VALUES (NULL, ?, ?, ?, ?, 0, NOW())"
        )->execute([$caseId, $rescuerId, $message, $category]);
    } catch (Throwable $e) {
        // admin_notifications table may not exist — run migrate_admin_notifications.sql
    }
}

// --- Profile: password / picture ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $updates = [];
    $params = [];

    if (!empty($new_password)) {
        if (strlen($new_password) < 6) {
            $msg = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $msg = 'Passwords do not match. Please re-enter.';
        } else {
            $updates[] = 'password = ?';
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }
    }

    if (empty($msg) && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
        $dir = 'uploads/profiles/';
        if (!file_exists($dir)) {
            mkdir($dir, 0777, true);
        }
        $file_name = time() . '_' . basename($_FILES['profile_picture']['name']);
        $target = $dir . $file_name;
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
            $updates[] = 'profile_picture = ?';
            $params[] = $target;
        }
    }

    if (empty($msg) && count($updates) > 0) {
        $params[] = $rescuerId;
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        $msg = $pdo->prepare($sql)->execute($params) ? 'Profile updated successfully!' : 'Failed to update profile.';
    }
}

// --- Contact info ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_contact'])) {
    $phone = trim($_POST['phone'] ?? '');
    $name = trim($_POST['display_name'] ?? '');
    $bio = trim($_POST['bio'] ?? '');
    try {
        if ($name !== '') {
            $pdo->prepare('UPDATE users SET name = ?, phone = ?, bio = ? WHERE id = ?')->execute([$name, $phone ?: null, $bio ?: null, $rescuerId]);
        } else {
            $pdo->prepare('UPDATE users SET phone = ?, bio = ? WHERE id = ?')->execute([$phone ?: null, $bio ?: null, $rescuerId]);
        }
    } catch (Throwable $e) {
        if ($name !== '') {
            $pdo->prepare('UPDATE users SET name = ?, phone = ? WHERE id = ?')->execute([$name, $phone ?: null, $rescuerId]);
        } else {
            $pdo->prepare('UPDATE users SET phone = ? WHERE id = ?')->execute([$phone ?: null, $rescuerId]);
        }
    }
    rescuer_audit_log($pdo, $rescuerId, null, 'update_contact', $phone);
    $msg = 'Contact information saved.';
}

// --- Availability (active / busy / inactive) ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['set_availability'])) {
    $av = $_POST['availability'] ?? '';
    if (in_array($av, ['available', 'busy', 'offline'], true)) {
        $pdo->prepare('UPDATE users SET availability_status = ? WHERE id = ?')->execute([$av, $rescuerId]);
        rescuer_audit_log($pdo, $rescuerId, null, 'availability', $av);
        $msg = 'Availability updated to: ' . $av . '.';
    }
}

// --- Location sync ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_location'])) {
    $lat = $_POST['latitude'] ?? null;
    $lon = $_POST['longitude'] ?? null;
    if ($lat !== null && $lon !== null) {
        $pdo->prepare('UPDATE users SET latitude=?, longitude=? WHERE id=?')->execute([$lat, $lon, $rescuerId]);
        $msg = 'Location synced.';
    }
}

// --- Rescue workflow actions ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'], $_POST['case_id'])) {
    $case_id = (int)$_POST['case_id'];
    $action = $_POST['action'];

    if (!rescuer_owns_case($pdo, $case_id, $rescuerId)) {
        $msg = 'Invalid case.';
    } elseif ($action === 'start_progress') {
        $pdo->prepare("UPDATE rescue_cases SET status='accepted' WHERE id=? AND assigned_rescuer_id=? AND status='pending'")
            ->execute([$case_id, $rescuerId]);
        notify_reporter($pdo, $case_id, 'Request in progress: your assigned rescuer has started moving to the location.', 'in_progress');
        notify_admin($pdo, $case_id, $rescuerId, 'Rescuer #' . $rescuerId . ' started rescue for Case #' . $case_id, 'status_change');
        rescuer_audit_log($pdo, $rescuerId, $case_id, 'start_progress', 'Pending → In progress');
        $msg = 'Rescue marked in progress. Proceed to the location.';
    } elseif ($action === 'arrived') {
        notify_reporter($pdo, $case_id, 'Rescuer has reached location and is assessing the animal.', 'arrived');
        notify_admin($pdo, $case_id, $rescuerId, 'Rescuer #' . $rescuerId . ' arrived at location for Case #' . $case_id, 'status_change');
        rescuer_audit_log($pdo, $rescuerId, $case_id, 'arrived', 'Rescuer reached location');
        $msg = 'Arrival update sent to reporter.';
    } elseif ($action === 'release') {
        $pdo->prepare("UPDATE rescue_cases SET assigned_rescuer_id=NULL, status='pending' WHERE id=? AND assigned_rescuer_id=?")
            ->execute([$case_id, $rescuerId]);
        notify_reporter($pdo, $case_id, 'Your request has been moved back to queue for reassignment.', 'reassigned');
        notify_admin($pdo, $case_id, $rescuerId, 'Rescuer #' . $rescuerId . ' released Case #' . $case_id . ' — needs reassignment', 'status_change');
        rescuer_audit_log($pdo, $rescuerId, $case_id, 'release_assignment', 'Released for reassignment');
        $msg = 'You released this assignment. An admin can reassign it.';
    } elseif ($action === 'resolve') {
        $animal_condition = trim($_POST['animal_condition'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if ($animal_condition === '' || $notes === '') {
            $msg = 'Please describe the animal condition and add rescue notes.';
        } else {
            $proof_path = null;
            if (isset($_FILES['proof']) && $_FILES['proof']['error'] === 0) {
                $dir = 'uploads/proofs/';
                if (!file_exists($dir)) {
                    mkdir($dir, 0777, true);
                }
                $target = $dir . time() . '_' . basename($_FILES['proof']['name']);
                if (move_uploaded_file($_FILES['proof']['tmp_name'], $target)) {
                    $proof_path = $target;
                }
            }
            $fullNotes = "Animal condition (after rescue): " . $animal_condition . "\n\nRescuer report: " . $notes;
            $pdo->prepare(
                "UPDATE rescue_cases SET status='resolved', resolution_notes=?, proof_image_path=COALESCE(?, proof_image_path), resolved_at=NOW() WHERE id=? AND assigned_rescuer_id=?"
            )->execute([$fullNotes, $proof_path, $case_id, $rescuerId]);
            notify_reporter($pdo, $case_id, 'Animal rescued successfully. Your case has been marked as completed.', 'resolved');
            notify_admin($pdo, $case_id, $rescuerId, 'Case #' . $case_id . ' resolved by Rescuer #' . $rescuerId . '. Animal condition: ' . substr($animal_condition, 0, 100), 'status_change');
            rescuer_audit_log($pdo, $rescuerId, $case_id, 'complete_rescue', substr($animal_condition, 0, 200));
            $msg = 'Rescue marked completed. Thank you.';
        }
    } elseif ($action === 'add_note') {
        $note_text = trim($_POST['progress_note'] ?? '');
        if ($note_text === '') {
            $msg = 'Please enter a progress note.';
        } else {
            try {
                $pdo->prepare('INSERT INTO rescue_updates (case_id, rescuer_id, note, created_at) VALUES (?, ?, ?, NOW())')
                    ->execute([$case_id, $rescuerId, $note_text]);
                notify_reporter($pdo, $case_id, 'Rescuer update: ' . $note_text, 'progress_update');
                notify_admin($pdo, $case_id, $rescuerId, 'Progress note on Case #' . $case_id . ': ' . substr($note_text, 0, 150), 'progress_update');
                rescuer_audit_log($pdo, $rescuerId, $case_id, 'progress_note', substr($note_text, 0, 200));
                $msg = 'Progress note added. Reporter and admin notified.';
            } catch (Throwable $e) {
                $msg = 'Could not save note. Run database/migrate_rescue_updates.sql first.';
            }
        }
    }
}

/*
 * Only accepted-system requests: rescue_requests.Accepted + High OR legacy rows (no rr) with high/urgent priority.
 */
$sqlCases = "
    SELECT c.*, u.name AS reporter_name, u.phone AS reporter_phone, u.email AS reporter_email,
           rr.location AS request_location, rr.status AS rr_status, rr.priority AS rr_priority, rr.confidence AS rr_confidence
    FROM rescue_cases c
    INNER JOIN users u ON c.reporter_id = u.id
    LEFT JOIN rescue_requests rr ON rr.case_id = c.id
    WHERE c.assigned_rescuer_id = ?
      AND c.status != 'rejected'
      AND (
            (rr.id IS NOT NULL AND rr.status = 'Accepted' AND rr.priority = 'High')
         OR (rr.id IS NULL AND c.priority_level IN ('high', 'urgent'))
      )
    ORDER BY FIELD(c.status, 'pending', 'accepted', 'resolved'),
             FIELD(c.priority_level, 'urgent', 'high', 'medium', 'low'),
             c.created_at DESC
";
$stmt = $pdo->prepare($sqlCases);
$stmt->execute([$rescuerId]);
$all_cases = $stmt->fetchAll();

// Fetch progress notes for all assigned cases
$case_notes = [];
try {
    $caseIds = array_column($all_cases, 'id');
    if (!empty($caseIds)) {
        $placeholders = implode(',', array_fill(0, count($caseIds), '?'));
        $notesStmt = $pdo->prepare(
            "SELECT ru.*, u.name AS rescuer_name FROM rescue_updates ru JOIN users u ON ru.rescuer_id = u.id WHERE ru.case_id IN ($placeholders) ORDER BY ru.created_at DESC"
        );
        $notesStmt->execute($caseIds);
        foreach ($notesStmt->fetchAll() as $note) {
            $case_notes[(int)$note['case_id']][] = $note;
        }
    }
} catch (Throwable $e) {
    // rescue_updates table may not exist yet
}

$active_cases = array_values(array_filter($all_cases, function ($c) {
    return in_array($c['status'], ['pending', 'accepted'], true);
}));
$completed_cases = array_values(array_filter($all_cases, function ($c) {
    return $c['status'] === 'resolved';
}));

$stats = [
    'pending' => count(array_filter($active_cases, fn($c) => $c['status'] === 'pending')),
    'in_progress' => count(array_filter($active_cases, fn($c) => $c['status'] === 'accepted')),
    'completed' => count($completed_cases),
];
$urgent_active = array_filter($active_cases, function ($c) {
    return in_array($c['priority_level'], ['urgent', 'high'], true);
});

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$rescuerId]);
$user_data = $stmt->fetch();

$recent_log = [];
try {
    $recent_log = $pdo->prepare(
        "SELECT l.*, c.animal_type FROM rescuer_activity_log l
         LEFT JOIN rescue_cases c ON l.case_id = c.id
         WHERE l.rescuer_id = ? ORDER BY l.created_at DESC LIMIT 15"
    );
    $recent_log->execute([$rescuerId]);
    $recent_log = $recent_log->fetchAll();
} catch (Throwable $e) {
    $recent_log = [];
}

$location_state = [
    'latitude' => $user_data['latitude'] ?? null,
    'longitude' => $user_data['longitude'] ?? null,
    'updated_at' => null,
    'status' => ($user_data['availability_status'] ?? 'offline') === 'available' ? 'active' : 'inactive',
];
try {
    $locStmt = $pdo->prepare('SELECT latitude, longitude, status, updated_at FROM rescuer_locations WHERE rescuer_id = ? LIMIT 1');
    $locStmt->execute([$rescuerId]);
    $loc = $locStmt->fetch();
    if ($loc) {
        $location_state['latitude'] = $loc['latitude'];
        $location_state['longitude'] = $loc['longitude'];
        $location_state['status'] = $loc['status'] ?? $location_state['status'];
        $location_state['updated_at'] = $loc['updated_at'] ?? null;
    }
} catch (Throwable $e) {
}

$rescuer_notifications = [];
try {
    $notifStmt = $pdo->prepare('SELECT id, message, status, created_at FROM notifications WHERE rescuer_id = ? ORDER BY created_at DESC LIMIT 30');
    $notifStmt->execute([$rescuerId]);
    $rescuer_notifications = $notifStmt->fetchAll();
} catch (Throwable $e) {
    $rescuer_notifications = [];
}

$body_class = 'rescuer-dashboard-page';
?>
<?php require_once 'includes/header.php'; ?>

<style>
.rescuer-shell { max-width: 1280px; margin: 0 auto; padding: 1.25rem 1rem 2.5rem; }
.rescuer-stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr)); gap: 1rem; margin-bottom: 1.25rem; }
.rescuer-stat-card { background: #fff; border: 1px solid rgba(15,23,42,0.08); border-radius: 14px; padding: 1.1rem 1.2rem; box-shadow: 0 2px 12px rgba(15,23,42,0.04); border-top: 3px solid var(--accent, #6366f1); }
.rescuer-stat-card .num { font-family: Outfit, sans-serif; font-size: 1.6rem; font-weight: 800; color: #0f172a; }
.rescuer-stat-card .lbl { font-size: 0.7rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em; color: #64748b; margin-top: 0.25rem; }
.rescuer-alert { background: linear-gradient(90deg, #fef3c7, #fff7ed); border: 1px solid #fcd34d; border-radius: 12px; padding: 0.85rem 1rem; margin-bottom: 1.25rem; font-size: 0.9rem; color: #92400e; }
.rescuer-card { background: #fff; border: 1px solid rgba(15,23,42,0.08); border-radius: 14px; overflow: hidden; box-shadow: 0 2px 14px rgba(15,23,42,0.05); display: flex; flex-direction: column; }
.rescuer-card__img { width: 100%; height: 160px; object-fit: cover; background: #f1f5f9; cursor: pointer; }
.rescuer-card__body { padding: 1.1rem 1.2rem; flex: 1; display: flex; flex-direction: column; }
.rescuer-badge { display: inline-block; padding: 0.2rem 0.55rem; border-radius: 999px; font-size: 0.68rem; font-weight: 700; text-transform: uppercase; }
.badge-pending { background: #fef9c3; color: #854d0e; }
.badge-progress { background: #dbeafe; color: #1e40af; }
.badge-urgent { background: #fee2e2; color: #991b1b; }
.rescuer-grid { display: grid; gap: 1.25rem; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); }
#rescuer-detail-modal { display: none; position: fixed; inset: 0; z-index: 2000; background: rgba(15,23,42,0.55); align-items: center; justify-content: center; padding: 1rem; }
#rescuer-detail-modal.is-open { display: flex; }
.rescuer-modal-box { background: #fff; border-radius: 16px; max-width: 640px; width: 100%; max-height: 90vh; overflow-y: auto; padding: 1.5rem; position: relative; }
.rescuer-modal-close { position: absolute; top: 0.75rem; right: 0.75rem; border: none; background: #f1f5f9; width: 36px; height: 36px; border-radius: 50%; cursor: pointer; font-size: 1.2rem; line-height: 1; }
#rescuer-detail-map { height: 220px; border-radius: 12px; margin-top: 0.75rem; z-index: 1; }
.rescuer-section-title { font-family: Outfit, sans-serif; font-size: 1.15rem; font-weight: 800; margin: 1.75rem 0 1rem; color: #0f172a; }
.history-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
.history-table th { text-align: left; padding: 0.65rem 0.75rem; background: #f1f5f9; color: #475569; font-size: 0.7rem; text-transform: uppercase; }
.history-table td { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
.rescuer-notify-wrap { position: relative; margin-bottom: 0.85rem; }
.rescuer-bell-btn { width: 100%; border: 1px solid #cbd5e1; border-radius: 10px; background: #f8fafc; color: #334155; padding: 0.55rem 0.65rem; text-align: left; font-weight: 600; cursor: pointer; display: flex; align-items: center; justify-content: space-between; }
.rescuer-bell-count { min-width: 22px; height: 22px; border-radius: 999px; background: #ef4444; color: #fff; font-size: 0.74rem; display: inline-flex; align-items: center; justify-content: center; }
.rescuer-notify-panel { display: none; position: absolute; left: 0; right: 0; top: calc(100% + 6px); background: #fff; border: 1px solid rgba(15,23,42,0.12); border-radius: 12px; box-shadow: 0 12px 28px rgba(15,23,42,0.12); max-height: 280px; overflow-y: auto; z-index: 999; }
.rescuer-notify-panel.show { display: block; }
.rescuer-notify-item { padding: 0.65rem 0.75rem; border-bottom: 1px solid #f1f5f9; }
.rescuer-notify-item:last-child { border-bottom: none; }
.rescuer-notify-item p { margin: 0; font-size: 0.82rem; color: #334155; line-height: 1.45; }
.rescuer-notify-item small { color: #94a3b8; font-size: 0.72rem; }
.rescuer-notify-item.unread { background:#eef2ff; border-left:3px solid #4f46e5; }
.rescuer-notify-item.read { background:#fff; }
.rescuer-notify-item.urgent { background:#fef2f2; border-left:3px solid #dc2626; }
.loc-share-wrap { display:grid; grid-template-columns:1.1fr 0.9fr; gap:1rem; align-items:start; }
.loc-share-card { background:#fff; border:1px solid rgba(15,23,42,0.08); border-radius:14px; box-shadow:0 10px 22px rgba(15,23,42,0.06); padding:1rem; }
.loc-status-pill { display:inline-flex; align-items:center; gap:0.4rem; font-size:0.78rem; font-weight:700; border-radius:999px; padding:0.25rem 0.6rem; }
.loc-status-pill.active { background:#dcfce7; color:#166534; }
.loc-status-pill.inactive { background:#fee2e2; color:#991b1b; }
.loc-kv { display:grid; grid-template-columns:repeat(3,minmax(120px,1fr)); gap:0.7rem; margin-top:0.8rem; }
.loc-kv div { background:#f8fafc; border-radius:10px; padding:0.55rem 0.65rem; }
.loc-kv span { display:block; font-size:0.72rem; text-transform:uppercase; letter-spacing:.05em; color:#94a3b8; margin-bottom:0.2rem; }
.loc-kv strong { color:#0f172a; font-size:0.86rem; }
.loc-map { height:320px; border-radius:12px; border:1px solid #e2e8f0; overflow:hidden; }
.loc-actions { display:flex; gap:0.7rem; flex-wrap:wrap; margin-top:0.9rem; }
.loc-log { font-size:0.8rem; color:#64748b; margin-top:0.65rem; min-height:1rem; }
@media (max-width: 980px) { .loc-share-wrap { grid-template-columns:1fr; } }
</style>

<div class="dashboard-layout mt-2 mb-2 rescuer-shell">
    <aside class="sidebar glass-panel" style="padding: 1.25rem;">
        <div style="text-align: center; margin-bottom: 1.5rem;">
            <?php if (!empty($user_data['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($user_data['profile_picture']); ?>" alt="" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover;">
            <?php else: ?>
                <div style="width: 72px; height: 72px; border-radius: 50%; background: var(--secondary-color); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin: 0 auto;">
                    <i class="fa-solid fa-truck-medical"></i>
                </div>
            <?php endif; ?>
            <h3 style="margin: 0.6rem 0 0.2rem; font-size: 1rem;"><?php echo htmlspecialchars($user_data['name']); ?></h3>
            <p style="font-size: 0.78rem; color: var(--secondary-color); font-weight: 600;">Rescuer</p>
            <p style="font-size: 0.72rem; color: #64748b; margin-top: 0.35rem;">
                Status: <strong><?php echo htmlspecialchars($user_data['availability_status'] ?? 'available'); ?></strong>
            </p>
        </div>
        <ul class="sidebar-menu">
            <li><a href="#" class="active" onclick="rescuerShow('main-section', this); return false;"><i class="fa-solid fa-gauge-high"></i> Dashboard</a></li>
            <li><a href="#" onclick="rescuerShow('location-section', this); return false;"><i class="fa-solid fa-location-dot"></i> Location Sharing</a></li>
            <li><a href="#" onclick="rescuerShow('notifications-section', this); return false;"><i class="fa-solid fa-bell"></i> Notifications</a></li>
            <li><a href="#" onclick="rescuerShow('history-section', this); return false;"><i class="fa-solid fa-clock-rotate-left"></i> Rescue History</a></li>
            <li><a href="#" onclick="rescuerShow('profile-section', this); return false;"><i class="fa-solid fa-user-gear"></i> Profile</a></li>
            <li><a href="#" onclick="rescuerShow('accountability-section', this); return false;"><i class="fa-solid fa-shield-halved"></i> Activity Log</a></li>
            <li><a href="logout.php" style="color: #dc2626;"><i class="fa-solid fa-right-from-bracket"></i> Logout</a></li>
        </ul>
    </aside>

    <div class="dashboard-main">
        <div id="main-section">
            <h2 style="margin-bottom: 0.35rem; font-family: Outfit, sans-serif;"><i class="fa-solid fa-truck-medical text-primary"></i> Rescuer dashboard</h2>
            <p style="color: #64748b; font-size: 0.92rem; margin-bottom: 1.25rem;">
                You only see <strong>accepted</strong> rescue cases (AI/admin) with <strong>high</strong> priority. Track <strong>Pending → In progress → Completed</strong> and submit your field report.
            </p>

            <div class="rescuer-stats">
                <div class="rescuer-stat-card" style="--accent:#f59e0b;"><div class="num"><?php echo $stats['pending']; ?></div><div class="lbl">Pending</div></div>
                <div class="rescuer-stat-card" style="--accent:#3b82f6;"><div class="num"><?php echo $stats['in_progress']; ?></div><div class="lbl">In progress</div></div>
                <div class="rescuer-stat-card" style="--accent:#10b981;"><div class="num"><?php echo $stats['completed']; ?></div><div class="lbl">Completed</div></div>
            </div>

            <?php if (count($urgent_active) > 0): ?>
            <div class="rescuer-alert" role="status">
                <strong><i class="fa-solid fa-bell"></i> Alerts:</strong>
                <?php echo count($urgent_active); ?> high-priority assignment(s) need your attention.
            </div>
            <?php elseif (count($active_cases) > 0): ?>
            <div class="rescuer-alert" style="background:#eff6ff;border-color:#93c5fd;color:#1e40af;">
                <strong><i class="fa-solid fa-circle-info"></i></strong> You have <?php echo count($active_cases); ?> active assignment(s).
            </div>
            <?php endif; ?>

            <h3 class="rescuer-section-title">Active assignments</h3>
            <?php if (count($active_cases) === 0): ?>
                <div class="glass-panel" style="padding: 2.5rem; text-align: center;">
                    <i class="fa-solid fa-mug-hot" style="font-size: 2.5rem; color: #cbd5e1; margin-bottom: 0.75rem;"></i>
                    <p style="color: #64748b; margin: 0;">No accepted high-priority cases assigned. Stay <strong>available</strong> and keep your location updated.</p>
                </div>
            <?php else: ?>
                <div class="rescuer-grid">
                    <?php foreach ($active_cases as $case): ?>
                        <?php
                        $workflow = $case['status'] === 'pending' ? 'pending' : 'in_progress';
                        $locMeta = rescuer_case_location_meta($case);
                        $locText = $locMeta['text'];
                        $detailPayload = json_encode([
                            'id' => (int)$case['id'],
                            'animal' => $case['animal_type'],
                            'description' => $case['description'],
                            'location' => $locText,
                            'needsGeocode' => $locMeta['needs_geocode'],
                            'lat' => (float)$case['latitude'],
                            'lon' => (float)$case['longitude'],
                            'image' => $case['image_path'],
                            'reporter' => $case['reporter_name'],
                            'phone' => $case['reporter_phone'],
                            'time' => date('M j, Y H:i', strtotime($case['created_at'])),
                        ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
                        $descShort = $case['description'] ?? '';
                        if (function_exists('mb_strimwidth')) {
                            $descShort = mb_strimwidth($descShort, 0, 140, '…');
                        } elseif (strlen($descShort) > 140) {
                            $descShort = substr($descShort, 0, 137) . '…';
                        }
                        $locShort = $locText;
                        if (function_exists('mb_strimwidth')) {
                            $locShort = mb_strimwidth($locShort, 0, 72, '…');
                        } elseif (strlen($locShort) > 72) {
                            $locShort = substr($locShort, 0, 69) . '…';
                        }
                        $locDisplay = rescuer_location_label($locShort);
                        ?>
                        <div class="rescuer-card" style="border-top: 4px solid <?php echo $case['priority_level'] === 'urgent' ? '#dc2626' : '#ea580c'; ?>;">
                            <?php if (!empty($case['image_path'])): ?>
                                <img class="rescuer-card__img" src="<?php echo htmlspecialchars($case['image_path']); ?>" alt=""
                                     role="button" tabindex="0"
                                     onclick='rescuerOpenDetail(<?php echo $detailPayload; ?>)'
                                     onkeypress="if(event.key==='Enter')this.click()">
                            <?php else: ?>
                                <div class="rescuer-card__img" style="display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:0.85rem;cursor:pointer;"
                                     role="button" tabindex="0"
                                     onclick='rescuerOpenDetail(<?php echo $detailPayload; ?>)'
                                     onkeypress="if(event.key==='Enter')this.click()">No photo — tap for map</div>
                            <?php endif; ?>
                            <div class="rescuer-card__body">
                                <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:0.5rem;margin-bottom:0.5rem;">
                                    <h3 style="margin:0;font-size:1rem;"><i class="fa-solid fa-paw"></i> <?php echo htmlspecialchars(ucfirst($case['animal_type'])); ?></h3>
                                    <?php if ($workflow === 'pending'): ?>
                                        <span class="rescuer-badge badge-pending">Pending</span>
                                    <?php else: ?>
                                        <span class="rescuer-badge badge-progress">In progress</span>
                                    <?php endif; ?>
                                    <?php if (in_array($case['priority_level'], ['urgent', 'high'], true)): ?>
                                        <span class="rescuer-badge badge-urgent"><?php echo strtoupper($case['priority_level']); ?></span>
                                    <?php endif; ?>
                                </div>
                                <p style="color:#475569;font-size:0.88rem;margin:0 0 0.75rem;line-height:1.5;flex:1;">
                                    <?php echo htmlspecialchars($descShort); ?>
                                </p>
                                <div style="font-size:0.8rem;color:#64748b;margin-bottom:0.75rem;">
                                    <div><i class="fa-solid fa-clock"></i> <?php echo date('M j, Y H:i', strtotime($case['created_at'])); ?></div>
                                    <div style="margin-top:0.25rem;"><i class="fa-solid fa-location-dot"></i>
                                        <span class="js-rescue-location"
                                              data-lat="<?php echo $locMeta['lat'] !== null ? htmlspecialchars((string)$locMeta['lat']) : ''; ?>"
                                              data-lon="<?php echo $locMeta['lon'] !== null ? htmlspecialchars((string)$locMeta['lon']) : ''; ?>"
                                              data-case-id="<?php echo (int)$locMeta['case_id']; ?>"
                                              data-needs-geocode="<?php echo $locMeta['needs_geocode'] ? '1' : '0'; ?>"
                                              data-skip-geocode="<?php echo $locMeta['needs_geocode'] ? '0' : '1'; ?>"><?php echo htmlspecialchars($locDisplay); ?></span>
                                    </div>
                                </div>
                                <button type="button" class="btn btn-secondary" style="width:100%;margin-bottom:0.5rem;font-size:0.85rem;"
                                        onclick='rescuerOpenDetail(<?php echo $detailPayload; ?>)'>
                                    <i class="fa-solid fa-expand"></i> View details &amp; map
                                </button>

                                <?php if ($case['status'] === 'pending'): ?>
                                    <form method="post" style="margin-bottom:0.5rem;">
                                        <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                                        <input type="hidden" name="action" value="start_progress">
                                        <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fa-solid fa-play"></i> Start rescue (In progress)</button>
                                    </form>
                                <?php endif; ?>

                                <?php if ($case['status'] === 'accepted'): ?>
                                    <form method="post" style="margin-bottom:0.5rem;">
                                        <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                                        <input type="hidden" name="action" value="arrived">
                                        <button type="submit" class="btn btn-secondary" style="width:100%;"><i class="fa-solid fa-location-dot"></i> Mark reached location</button>
                                    </form>

                                    <!-- Progress notes -->
                                    <div style="border:1px solid #e2e8f0;border-radius:10px;padding:0.7rem;margin-bottom:0.5rem;background:#f8fafc;">
                                        <h4 style="font-size:0.78rem;margin:0 0 0.4rem;color:#4f46e5;font-weight:700;"><i class="fa-solid fa-pen-to-square"></i> Add progress note</h4>
                                        <form method="post">
                                            <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                                            <input type="hidden" name="action" value="add_note">
                                            <textarea name="progress_note" class="form-control" rows="2" required placeholder="e.g. En route to location, Animal secured, Heading to vet..." style="font-size:0.82rem;margin-bottom:0.4rem;"></textarea>
                                            <button type="submit" class="btn btn-secondary" style="width:100%;font-size:0.8rem;"><i class="fa-solid fa-paper-plane"></i> Send update</button>
                                        </form>
                                        <?php
                                        $notes_for_case = $case_notes[(int)$case['id']] ?? [];
                                        if (!empty($notes_for_case)):
                                        ?>
                                        <div style="margin-top:0.6rem;border-top:1px solid #e2e8f0;padding-top:0.5rem;">
                                            <p style="font-size:0.7rem;font-weight:700;color:#94a3b8;text-transform:uppercase;margin:0 0 0.35rem;">Previous notes</p>
                                            <?php foreach (array_slice($notes_for_case, 0, 5) as $pn): ?>
                                            <div style="background:#fff;border:1px solid #f1f5f9;border-radius:8px;padding:0.45rem 0.55rem;margin-bottom:0.3rem;">
                                                <p style="margin:0;font-size:0.8rem;color:#334155;line-height:1.4;"><?php echo htmlspecialchars($pn['note']); ?></p>
                                                <small style="color:#94a3b8;font-size:0.68rem;"><?php echo date('M j, g:i A', strtotime($pn['created_at'])); ?></small>
                                            </div>
                                            <?php endforeach; ?>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                    <div style="border-top:1px solid #f1f5f9;padding-top:0.75rem;margin-top:auto;">
                                        <h4 style="font-size:0.8rem;margin:0 0 0.5rem;color:#64748b;">Complete &amp; report</h4>
                                        <form method="post" enctype="multipart/form-data">
                                            <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                                            <input type="hidden" name="action" value="resolve">
                                            <div class="form-group" style="margin-bottom:0.4rem;">
                                                <label style="font-size:0.78rem;">Animal condition (after rescue) *</label>
                                                <textarea name="animal_condition" class="form-control" rows="2" required placeholder="e.g. Stable, treated for wound, referred to vet"></textarea>
                                            </div>
                                            <div class="form-group" style="margin-bottom:0.4rem;">
                                                <label style="font-size:0.78rem;">Rescue notes *</label>
                                                <textarea name="notes" class="form-control" rows="2" required placeholder="What you did, where animal was taken"></textarea>
                                            </div>
                                            <div class="form-group" style="margin-bottom:0.5rem;">
                                                <label style="font-size:0.78rem;">Photo proof (optional)</label>
                                                <input type="file" name="proof" class="form-control" accept="image/*">
                                            </div>
                                            <button type="submit" class="btn btn-primary" style="width:100%;"><i class="fa-solid fa-flag-checkered"></i> Mark completed</button>
                                        </form>
                                    </div>
                                <?php endif; ?>

                                <form method="post" onsubmit="return confirm('Release this assignment? An admin can assign another rescuer.');" style="margin-top:0.5rem;">
                                    <input type="hidden" name="case_id" value="<?php echo (int)$case['id']; ?>">
                                    <input type="hidden" name="action" value="release">
                                    <button type="submit" class="btn" style="width:100%;background:#f1f5f9;color:#475569;border:none;font-size:0.8rem;">Release assignment</button>
                                </form>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

        </div>

        <div id="location-section" class="glass-panel" style="padding: 1.5rem; display: none;">
            <h2 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-location-dot text-primary"></i> Location sharing</h2>
            <p style="color: #64748b; margin-bottom: 1rem; font-size: 0.9rem;">Share your live GPS every 12 seconds so admin can assign nearest rescuer to new rescue requests.</p>
            <div class="loc-share-wrap">
                <div class="loc-share-card">
                    <span id="loc-status-pill" class="loc-status-pill <?php echo ($location_state['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive'; ?>"><i class="fa-solid fa-circle"></i> <?php echo ($location_state['status'] ?? 'inactive') === 'active' ? 'Active' : 'Inactive'; ?></span>
                    <div class="loc-kv">
                        <div><span>Latitude</span><strong id="loc-lat"><?php echo $location_state['latitude'] !== null ? htmlspecialchars((string)$location_state['latitude']) : '--'; ?></strong></div>
                        <div><span>Longitude</span><strong id="loc-lon"><?php echo $location_state['longitude'] !== null ? htmlspecialchars((string)$location_state['longitude']) : '--'; ?></strong></div>
                        <div><span>Last update</span><strong id="loc-updated"><?php echo !empty($location_state['updated_at']) ? htmlspecialchars((string)$location_state['updated_at']) : '--'; ?></strong></div>
                    </div>
                    <div class="loc-actions">
                        <button type="button" class="btn btn-primary" id="loc-start-btn"><i class="fa-solid fa-play"></i> Start Sharing Location</button>
                        <button type="button" class="btn btn-secondary" id="loc-stop-btn" disabled><i class="fa-solid fa-stop"></i> Stop Sharing Location</button>
                    </div>
                    <p id="loc-log" class="loc-log"></p>
                </div>
                <div class="loc-share-card">
                    <div id="rescuer-live-map" class="loc-map"></div>
                </div>
            </div>
        </div>

        <div id="notifications-section" class="glass-panel" style="padding: 1.5rem; display: none;">
            <h2 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-bell text-primary"></i> Notifications</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-bottom:1rem;">Unread alerts are highlighted. Urgent alerts include high-priority assignments.</p>
            <div style="display:flex;justify-content:flex-end;margin-bottom:0.8rem;">
                <button type="button" class="btn btn-secondary" id="rescuer-mark-read-btn"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
            </div>
            <div id="rescuer-notify-panel-static">
                <?php if (empty($rescuer_notifications)): ?>
                    <div class="rescuer-notify-item"><p>No notifications yet.</p></div>
                <?php else: ?>
                    <?php foreach ($rescuer_notifications as $n): ?>
                        <?php
                        $isUnread = ($n['status'] ?? 'unread') === 'unread';
                        $urgent = stripos((string)$n['message'], 'high priority') !== false;
                        ?>
                        <div class="rescuer-notify-item <?php echo $isUnread ? 'unread' : 'read'; ?> <?php echo $urgent ? 'urgent' : ''; ?>">
                            <p>
                                <?php if ($urgent): ?><strong style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> Urgent:</strong> <?php endif; ?>
                                <?php echo htmlspecialchars($n['message']); ?>
                            </p>
                            <small><?php echo htmlspecialchars($n['created_at']); ?> · <?php echo $isUnread ? 'Unread' : 'Read'; ?></small>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

        <div id="history-section" class="glass-panel" style="padding: 1.5rem; display: none;">
            <h2 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-clock-rotate-left text-primary"></i> Rescue history</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-bottom:1rem;">Track completed rescue work and outcomes.</p>
            <?php if (count($completed_cases) === 0): ?>
                <p style="color:#94a3b8;font-size:0.9rem;">No completed rescues yet.</p>
            <?php else: ?>
                <div class="glass-panel" style="padding:0;overflow-x:auto;">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>Animal</th>
                                <th>Location</th>
                                <th>Date</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($completed_cases as $c): ?>
                                <?php $histLoc = rescuer_case_location_meta($c); ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($c['animal_type']); ?></td>
                                    <td>
                                        <span class="js-rescue-location"
                                              data-lat="<?php echo $histLoc['lat'] !== null ? htmlspecialchars((string)$histLoc['lat']) : ''; ?>"
                                              data-lon="<?php echo $histLoc['lon'] !== null ? htmlspecialchars((string)$histLoc['lon']) : ''; ?>"
                                              data-case-id="<?php echo (int)$histLoc['case_id']; ?>"
                                              data-needs-geocode="<?php echo $histLoc['needs_geocode'] ? '1' : '0'; ?>"
                                              data-skip-geocode="<?php echo $histLoc['needs_geocode'] ? '0' : '1'; ?>"><?php echo htmlspecialchars(rescuer_location_label($histLoc['text'])); ?></span>
                                    </td>
                                    <td><?php echo $c['resolved_at'] ? date('M j, Y H:i', strtotime($c['resolved_at'])) : '—'; ?></td>
                                    <td><span class="rescuer-badge badge-progress">Resolved</span></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <div id="profile-section" class="glass-panel" style="padding: 1.5rem; display: none;">
            <h2 style="margin-bottom: 1rem;"><i class="fa-solid fa-user-gear text-primary"></i> Profile &amp; availability</h2>

            <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Profile section</h3>
            <form method="POST" style="max-width: 420px; margin-bottom: 1.5rem;">
                <input type="hidden" name="update_contact" value="1">
                <div class="form-group">
                    <label>Full name</label>
                    <input type="text" name="display_name" class="form-control" value="<?php echo htmlspecialchars($user_data['name']); ?>" required>
                </div>
                <div class="form-group">
                    <label>Email address</label>
                    <input type="email" class="form-control" value="<?php echo htmlspecialchars($user_data['email'] ?? ''); ?>" readonly>
                </div>
                <div class="form-group">
                    <label>Phone</label>
                    <input type="text" name="phone" class="form-control" value="<?php echo htmlspecialchars($user_data['phone'] ?? ''); ?>" placeholder="For reporters to reach you">
                </div>
                <div class="form-group">
                    <label>Bio / description</label>
                    <textarea name="bio" class="form-control" rows="3" placeholder="Short profile description"><?php echo htmlspecialchars($user_data['bio'] ?? ''); ?></textarea>
                </div>
                <button type="submit" class="btn btn-primary">Save contact</button>
            </form>

            <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Availability</h3>
            <p style="font-size:0.82rem;color:#64748b;margin-bottom:0.75rem;"><strong>Active</strong> rescuers receive new assignments. <strong>Offline</strong> rescuers are excluded from assignment matching.</p>
            <form method="POST" style="max-width: 420px; margin-bottom: 1.5rem;">
                <input type="hidden" name="set_availability" value="1">
                <div class="form-group">
                    <select name="availability" class="form-control">
                        <option value="available" <?php echo ($user_data['availability_status'] ?? '') === 'available' ? 'selected' : ''; ?>>Active</option>
                        <option value="offline" <?php echo ($user_data['availability_status'] ?? '') === 'offline' ? 'selected' : ''; ?>>Offline</option>
                    </select>
                </div>
                <button type="submit" class="btn btn-secondary">Update availability</button>
            </form>

            <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Location information</h3>
            <div class="loc-kv" style="margin:0 0 1.4rem;">
                <div><span>Current latitude</span><strong><?php echo $location_state['latitude'] !== null ? htmlspecialchars((string)$location_state['latitude']) : '--'; ?></strong></div>
                <div><span>Current longitude</span><strong><?php echo $location_state['longitude'] !== null ? htmlspecialchars((string)$location_state['longitude']) : '--'; ?></strong></div>
                <div><span>Last updated</span><strong><?php echo !empty($location_state['updated_at']) ? htmlspecialchars((string)$location_state['updated_at']) : '--'; ?></strong></div>
            </div>

            <h3 style="font-size:0.95rem;margin:0 0 0.5rem;">Account settings</h3>
            <form method="POST" enctype="multipart/form-data" style="max-width: 420px;">
                <input type="hidden" name="update_profile" value="1">
                <div class="form-group">
                    <label>Profile picture (optional)</label>
                    <input type="file" name="profile_picture" class="form-control" accept="image/*">
                </div>
                <div class="form-group">
                    <label>Change password</label>
                    <input type="password" name="new_password" id="resc-new-pw" class="form-control" placeholder="Leave blank to keep" minlength="6">
                </div>
                <div class="form-group">
                    <label>Confirm password</label>
                    <input type="password" name="confirm_password" id="resc-confirm-pw" class="form-control" placeholder="Re-enter new password">
                    <small id="resc-pw-match" style="font-size:0.78rem;"></small>
                </div>
                <button type="submit" class="btn btn-primary">Save</button>
            </form>
            <script>
            (function(){
                var pw = document.getElementById('resc-new-pw');
                var cpw = document.getElementById('resc-confirm-pw');
                var hint = document.getElementById('resc-pw-match');
                function checkMatch(){
                    if(!pw.value && !cpw.value){ hint.textContent=''; return; }
                    if(!pw.value || !cpw.value){ hint.textContent=''; return; }
                    if(pw.value === cpw.value){ hint.textContent='\u2713 Passwords match'; hint.style.color='#16a34a'; }
                    else{ hint.textContent='\u2717 Passwords do not match'; hint.style.color='#dc2626'; }
                }
                pw.addEventListener('input', checkMatch);
                cpw.addEventListener('input', checkMatch);
            })();
            </script>
        </div>

        <div id="accountability-section" class="glass-panel" style="padding: 1.5rem; display: none;">
            <h2 style="margin-bottom: 0.5rem;"><i class="fa-solid fa-shield-halved text-primary"></i> Your activity log</h2>
            <p style="color:#64748b;font-size:0.88rem;margin-bottom:1rem;">Accountability: system records your actions with timestamps (visible to administrators).</p>
            <?php if (empty($recent_log)): ?>
                <p style="color:#94a3b8;">No log entries yet. Run <code>database/migrate_rescuer_dashboard.sql</code> to create the log table.</p>
            <?php else: ?>
                <table class="history-table">
                    <thead>
                        <tr><th>Time</th><th>Action</th><th>Case</th><th>Details</th></tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_log as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($row['action_type']); ?></td>
                                <td><?php echo $row['case_id'] ? '#' . (int)$row['case_id'] . ' ' . htmlspecialchars($row['animal_type'] ?? '') : '—'; ?></td>
                                <td><?php echo htmlspecialchars($row['details'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</div>

<div id="rescuer-detail-modal" aria-modal="true" role="dialog" aria-labelledby="rescuer-modal-title">
    <div class="rescuer-modal-box">
        <button type="button" class="rescuer-modal-close" onclick="rescuerCloseDetail()" aria-label="Close">&times;</button>
        <h2 id="rescuer-modal-title" style="margin:0 0 0.75rem;font-family:Outfit,sans-serif;font-size:1.2rem;">Request details</h2>
        <img id="rescuer-modal-img" src="" alt="" style="display:none;width:100%;max-height:200px;object-fit:cover;border-radius:12px;margin-bottom:0.75rem;">
        <p id="rescuer-modal-desc" style="color:#334155;line-height:1.55;font-size:0.92rem;"></p>
        <dl style="margin:0.75rem 0;font-size:0.88rem;color:#475569;">
            <dt style="font-weight:700;color:#94a3b8;font-size:0.7rem;text-transform:uppercase;">Location</dt>
            <dd id="rescuer-modal-loc" style="margin:0.2rem 0 0.5rem;"></dd>
            <dt style="font-weight:700;color:#94a3b8;font-size:0.7rem;text-transform:uppercase;">Reporter</dt>
            <dd id="rescuer-modal-rep" style="margin:0.2rem 0 0.5rem;"></dd>
            <dt style="font-weight:700;color:#94a3b8;font-size:0.7rem;text-transform:uppercase;">Reported</dt>
            <dd id="rescuer-modal-time" style="margin:0.2rem 0 0;"></dd>
        </dl>
        <div id="rescuer-detail-map"></div>
        <a id="rescuer-modal-gmaps" href="#" target="_blank" rel="noopener" class="btn btn-secondary" style="margin-top:0.75rem;display:inline-block;">Open in Google Maps</a>
    </div>
</div>

<script src="assets/js/rescuer-geocode.js"></script>
<script>
var rescuerDetailMap = null;
function rescuerShow(sectionId, el) {
    document.getElementById('main-section').style.display = sectionId === 'main-section' ? 'block' : 'none';
    document.getElementById('location-section').style.display = sectionId === 'location-section' ? 'block' : 'none';
    document.getElementById('notifications-section').style.display = sectionId === 'notifications-section' ? 'block' : 'none';
    document.getElementById('history-section').style.display = sectionId === 'history-section' ? 'block' : 'none';
    document.getElementById('profile-section').style.display = sectionId === 'profile-section' ? 'block' : 'none';
    document.getElementById('accountability-section').style.display = sectionId === 'accountability-section' ? 'block' : 'none';
    document.querySelectorAll('.sidebar-menu a').forEach(function (a) { a.classList.remove('active'); });
    if (el) el.classList.add('active');

    // Fix: Leaflet needs invalidateSize when container transitions from hidden to visible
    if (sectionId === 'location-section') {
        setTimeout(function () {
            var mapEl = document.getElementById('rescuer-live-map');
            if (mapEl && mapEl._leaflet_id) {
                // Map already initialized — refresh tiles
                Object.keys(window).forEach(function() {});
            }
            // Trigger resize so any existing Leaflet map recalculates
            window.dispatchEvent(new Event('resize'));
        }, 200);
    }
}
function rescuerOpenDetail(d) {
    var m = document.getElementById('rescuer-detail-modal');
    document.getElementById('rescuer-modal-title').textContent = (d.animal ? d.animal : 'Animal') + ' · #' + d.id;
    document.getElementById('rescuer-modal-desc').textContent = d.description || '';
    if (window.RescuerGeocode) {
        window.RescuerGeocode.resolveForDetail(d, document.getElementById('rescuer-modal-loc'));
    } else {
        var locText = d.location || '';
        document.getElementById('rescuer-modal-loc').textContent =
            locText && String(locText).toLowerCase().indexOf('location:') === 0
                ? locText
                : 'Location: ' + (locText || (d.lat + ', ' + d.lon));
    }
    document.getElementById('rescuer-modal-rep').textContent = (d.reporter || '') + (d.phone ? ' · ' + d.phone : '');
    document.getElementById('rescuer-modal-time').textContent = d.time || '';
    var img = document.getElementById('rescuer-modal-img');
    if (d.image && String(d.image).length) { img.src = d.image; img.style.display = 'block'; } else { img.style.display = 'none'; img.removeAttribute('src'); }
    document.getElementById('rescuer-modal-gmaps').href = 'https://maps.google.com/?q=' + encodeURIComponent(d.lat + ',' + d.lon);
    m.classList.add('is-open');
    setTimeout(function () {
        var el = document.getElementById('rescuer-detail-map');
        if (!el || typeof L === 'undefined') return;
        if (rescuerDetailMap) { rescuerDetailMap.remove(); rescuerDetailMap = null; }
        rescuerDetailMap = L.map('rescuer-detail-map').setView([d.lat, d.lon], 15);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19,
            attribution: '&copy; OpenStreetMap' }).addTo(rescuerDetailMap);
        L.marker([d.lat, d.lon]).addTo(rescuerDetailMap);
        rescuerDetailMap.invalidateSize();
    }, 100);
}
function rescuerCloseDetail() {
    document.getElementById('rescuer-detail-modal').classList.remove('is-open');
    if (rescuerDetailMap) { rescuerDetailMap.remove(); rescuerDetailMap = null; }
}
document.getElementById('rescuer-detail-modal').addEventListener('click', function (e) {
    if (e.target === this) rescuerCloseDetail();
});
function requestLocation(latId, lonId) {
    if (!navigator.geolocation) { alert('Geolocation not supported.'); return; }
    navigator.geolocation.getCurrentPosition(function (pos) {
        document.getElementById(latId).value = pos.coords.latitude;
        document.getElementById(lonId).value = pos.coords.longitude;
    }, function () { alert('Could not read location.'); });
}
window.addEventListener('load', function () {
    var locationTimer = null;
    var locationMap = null; // Leaflet fallback
    var locationMarker = null; // Leaflet fallback
    var googleMap = null;
    var googleMarker = null;
    var isSharing = false;
    var statusPill = document.getElementById('loc-status-pill');
    var latBox = document.getElementById('loc-lat');
    var lonBox = document.getElementById('loc-lon');
    var updatedBox = document.getElementById('loc-updated');
    var logBox = document.getElementById('loc-log');
    var startBtn = document.getElementById('loc-start-btn');
    var stopBtn = document.getElementById('loc-stop-btn');

    function setLog(message, isError) {
        if (!logBox) return;
        logBox.textContent = message || '';
        logBox.style.color = isError ? '#b91c1c' : '#64748b';
    }

    function setSharingUI(active) {
        isSharing = active;
        if (statusPill) {
            statusPill.classList.toggle('active', active);
            statusPill.classList.toggle('inactive', !active);
            statusPill.innerHTML = active ? '<i class="fa-solid fa-circle"></i> Active' : '<i class="fa-solid fa-circle"></i> Inactive';
        }
        if (startBtn) startBtn.disabled = active;
        if (stopBtn) stopBtn.disabled = !active;
    }

    function initGoogleLocationMap(lat, lon) {
        var mapEl = document.getElementById('rescuer-live-map');
        if (!mapEl || typeof google === 'undefined' || !google.maps) return false;
        var center = { lat: Number(lat) || 27.7172, lng: Number(lon) || 85.3240 };
        if (!googleMap) {
            googleMap = new google.maps.Map(mapEl, {
                center: center,
                zoom: 15,
                mapTypeControl: true,
                streetViewControl: false,
                fullscreenControl: false
            });
            googleMarker = new google.maps.Marker({
                position: center,
                map: googleMap,
                title: 'Rescuer live location'
            });
        } else {
            googleMap.setCenter(center);
            googleMarker.setPosition(center);
        }
        return true;
    }

    function updateMap(lat, lon) {
        if (initGoogleLocationMap(lat, lon)) return;
        if (typeof L === 'undefined') {
            console.warn('Leaflet.js not loaded — map cannot render');
            return;
        }
        var mapEl = document.getElementById('rescuer-live-map');
        if (!mapEl) return;

        if (!locationMap) {
            locationMap = L.map('rescuer-live-map', { zoomControl: true }).setView([lat, lon], 15);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                maxZoom: 19,
                attribution: '&copy; <a href="https://openstreetmap.org">OpenStreetMap</a>'
            }).addTo(locationMap);
            locationMarker = L.marker([lat, lon]).addTo(locationMap)
                .bindPopup('Your live location').openPopup();
            // Multiple invalidateSize calls to handle hidden containers
            setTimeout(function () { locationMap.invalidateSize(); }, 100);
            setTimeout(function () { locationMap.invalidateSize(); }, 500);
            setTimeout(function () { locationMap.invalidateSize(); }, 1000);
            return;
        }
        locationMarker.setLatLng([lat, lon]);
        locationMap.setView([lat, lon], 15);
        locationMap.invalidateSize();
    }

    // Flask API base URL for location updates
    var FLASK_URL = 'http://127.0.0.1:5000';
    var RESCUER_ID = <?php echo (int)$rescuerId; ?>;

    function postLocationPHP(action, lat, lon) {
        // Send to PHP backend (stores in MySQL database)
        return fetch('backend/update_location.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: action, latitude: lat, longitude: lon }),
        }).then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, error: 'PHP backend unreachable' }; });
    }

    function postLocationFlask(action, lat, lon) {
        // Send to Flask backend (API layer / ML services)
        return fetch(FLASK_URL + '/update-location', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                rescuer_id: RESCUER_ID,
                action: action,
                latitude: lat,
                longitude: lon
            }),
        }).then(function (r) { return r.json(); })
          .catch(function () { return { ok: false, error: 'Flask API unreachable (is app.py running?)' }; });
    }

    function captureAndSync(action) {
        if (!navigator.geolocation) {
            setLog('Geolocation is not supported on this browser.', true);
            return;
        }
        setLog(action === 'start' ? 'Starting location sharing...' : 'Syncing location...', false);
        navigator.geolocation.getCurrentPosition(function (pos) {
            var lat = Number(pos.coords.latitude);
            var lon = Number(pos.coords.longitude);

            // Send to PHP (primary — DB storage)
            postLocationPHP(action, lat, lon).then(function (phpRes) {
                if (!phpRes || !phpRes.ok) {
                    setLog('PHP: ' + ((phpRes && phpRes.error) || 'Location update failed') + '. Check backend/update_location.php', true);
                    return;
                }
                // Update UI immediately on PHP success
                if (latBox) latBox.textContent = lat.toFixed(6);
                if (lonBox) lonBox.textContent = lon.toFixed(6);
                if (updatedBox) updatedBox.textContent = new Date().toLocaleTimeString();
                updateMap(lat, lon);
                setSharingUI(true);
                setLog('Location shared successfully (' + lat.toFixed(4) + ', ' + lon.toFixed(4) + ')', false);
            });

            // Also send to Flask (secondary — API layer, non-blocking)
            postLocationFlask(action, lat, lon).then(function (flaskRes) {
                if (flaskRes && !flaskRes.ok) {
                    console.warn('Flask location sync failed:', flaskRes.error);
                }
            });

        }, function (err) {
            if (err && err.code === 1) setLog('Location permission denied. Please allow GPS access in your browser.', true);
            else if (err && err.code === 2) setLog('GPS position unavailable. Try again.', true);
            else if (err && err.code === 3) setLog('GPS timed out. Check your location settings.', true);
            else setLog('Unable to capture GPS location.', true);
        }, { enableHighAccuracy: true, timeout: 15000, maximumAge: 5000 });
    }

    function startSharing() {
        if (isSharing) return;
        captureAndSync('start');
        // Update every 12 seconds (within 10-15s range for performance)
        locationTimer = setInterval(function () {
            if (isSharing) captureAndSync('update');
        }, 12000);
    }

    function stopSharing() {
        if (locationTimer) {
            clearInterval(locationTimer);
            locationTimer = null;
        }
        // Stop on PHP backend
        postLocationPHP('stop').then(function (res) {
            if (!res || !res.ok) {
                setLog((res && res.error) ? res.error : 'Failed to stop sharing.', true);
                return;
            }
            setSharingUI(false);
            setLog('Location sharing stopped.', false);
        });
        // Also stop on Flask (non-blocking)
        postLocationFlask('stop').then(function () {}).catch(function () {});
    }

    if (startBtn) startBtn.addEventListener('click', startSharing);
    if (stopBtn) stopBtn.addEventListener('click', stopSharing);
    setSharingUI(statusPill && statusPill.classList.contains('active'));

    var panel = document.getElementById('rescuer-notify-panel-static');
    var markReadBtn = document.getElementById('rescuer-mark-read-btn');

    function renderNotifications(payload) {
        if (!payload || !payload.ok || !panel) return;

        if (!Array.isArray(payload.notifications) || payload.notifications.length === 0) {
            panel.innerHTML = '<div class="rescuer-notify-item"><p>No notifications yet.</p></div>';
            return;
        }

        panel.innerHTML = payload.notifications.map(function (n) {
            var message = (n.message || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
            var when = n.created_at || '';
            var status = (n.status || 'unread').toLowerCase();
            var unread = status === 'unread';
            var urgent = (n.message || '').toLowerCase().indexOf('high priority') !== -1;
            return '<div class="rescuer-notify-item ' + (unread ? 'unread' : 'read') + (urgent ? ' urgent' : '') + '"><p>' +
                (urgent ? '<strong style="color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i> Urgent:</strong> ' : '') +
                message + '</p><small>' + when + ' · ' + (unread ? 'Unread' : 'Read') + '</small></div>';
        }).join('');
    }

    function fetchNotifications(markRead) {
        var url = 'backend/api/rescuer_notifications.php';
        if (markRead) url += '?mark_read=1';
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(renderNotifications)
            .catch(function () {});
    }

    if (markReadBtn) {
        markReadBtn.addEventListener('click', function () {
            fetchNotifications(true);
        });
    }

    window.initRescuerLiveGoogleMap = function () {
        initGoogleLocationMap(
            Number((latBox && latBox.textContent) || 0) || 27.7172,
            Number((lonBox && lonBox.textContent) || 0) || 85.3240
        );
    };

    fetchNotifications(false);
    setInterval(function () { fetchNotifications(false); }, 25000);
});
</script>

<?php
$google_maps_key = getenv('GOOGLE_MAPS_API_KEY');
if ($google_maps_key === false && isset($_ENV['GOOGLE_MAPS_API_KEY'])) {
    $google_maps_key = (string)$_ENV['GOOGLE_MAPS_API_KEY'];
}
$google_maps_key = trim((string)$google_maps_key);
?>
<?php if ($google_maps_key !== ''): ?>
<script async defer src="https://maps.googleapis.com/maps/api/js?key=<?php echo urlencode($google_maps_key); ?>&callback=initRescuerLiveGoogleMap"></script>
<?php endif; ?>

<?php if ($msg): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: <?php echo json_encode(strpos($msg, 'Invalid') !== false || strpos($msg, 'Please') !== false ? 'warning' : 'success'); ?>,
        title: <?php echo json_encode(strpos($msg, 'Invalid') !== false ? 'Notice' : 'Done'); ?>,
        text: <?php echo json_encode($msg); ?>,
        confirmButtonColor: '#4F46E5'
    });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
