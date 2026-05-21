<?php
require_once __DIR__ . '/backend/auth.php';
require_login();
require_role('admin');

$msg = '';
$err = '';
$requestId = filter_input(INPUT_GET, 'request_id', FILTER_VALIDATE_INT) ?: 0;

// ─── Handle assignment POST ────────────────────────────────────────────
// Robust 4-step validation before any DB write:
//   Step 1 → Rescuer ID exists and is not blocked
//   Step 2 → Profile completeness (name, email required)
//   Step 3 → Availability status (must be active/available)
//   Step 4 → Duplicate assignment detection
// ────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['assign_action'])) {
    $postRequestId = filter_input(INPUT_POST, 'request_id', FILTER_VALIDATE_INT) ?: 0;
    $rescuerId     = filter_input(INPUT_POST, 'rescuer_id', FILTER_VALIDATE_INT) ?: 0;

    // Basic input validation
    if ($postRequestId <= 0 || $rescuerId <= 0) {
        header('Location: rescuer_directory.php?request_id=' . $postRequestId . '&err=' . urlencode('Invalid request or rescuer ID.'));
        exit;
    }

    try {
        // ═══════════════════════════════════════════════════════════════
        // STEP 1: Verify rescuer exists in the users table with role='rescuer'
        //         and the account is not blocked/suspended.
        // ═══════════════════════════════════════════════════════════════
        $rescuerStmt = $pdo->prepare(
            "SELECT id, name, email, phone, availability_status, account_status
             FROM users
             WHERE id = ? AND role = 'rescuer'"
        );
        $rescuerStmt->execute([$rescuerId]);
        $rescuerData = $rescuerStmt->fetch(PDO::FETCH_ASSOC);

        if (!$rescuerData) {
            throw new RuntimeException('Rescuer ID #' . $rescuerId . ' does not exist or is not a rescuer account.');
        }

        // Check if account is blocked/suspended
        $accountStatus = $rescuerData['account_status'] ?? 'active';
        if ($accountStatus === 'blocked' || $accountStatus === 'suspended') {
            throw new RuntimeException('Rescuer "' . htmlspecialchars($rescuerData['name']) . '" is currently ' . $accountStatus . '. Cannot assign blocked/suspended accounts.');
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 2: Validate profile completeness.
        //         Required fields: name, email.
        //         These are essential for communication during rescue.
        // ═══════════════════════════════════════════════════════════════
        $missingFields = [];
        if (empty(trim($rescuerData['name'] ?? '')))  $missingFields[] = 'name';
        if (empty(trim($rescuerData['email'] ?? '')))  $missingFields[] = 'email';

        if (!empty($missingFields)) {
            throw new RuntimeException(
                'Rescuer information is incomplete or unavailable. Missing: ' . implode(', ', $missingFields) . '. '
                . 'The rescuer must complete their profile before being assigned.'
            );
        }

        // ═══════════════════════════════════════════════════════════════
        // STEP 3: Check rescuer availability status.
        //         Must be 'available' or 'active' (not 'offline' or 'busy').
        // ═══════════════════════════════════════════════════════════════
        $availability = $rescuerData['availability_status'] ?? 'available';
        if ($availability === 'offline') {
            throw new RuntimeException(
                'Rescuer "' . htmlspecialchars($rescuerData['name']) . '" is currently offline. '
                . 'Only available/active rescuers can be assigned. Ask them to set status to Available first.'
            );
        }
        // Note: 'busy' rescuers CAN be assigned (admin override) but we show a softer check via card UI.
        // Strict mode: uncomment the line below to block busy rescuers entirely.
        // if ($availability === 'busy') throw new RuntimeException('Rescuer is busy with another case.');

        // ═══════════════════════════════════════════════════════════════
        // STEP 4: Duplicate assignment detection.
        //         Check if this case already has a rescuer assigned.
        //         If same rescuer → block with clear message.
        //         If different rescuer → warn and block (simple version).
        // ═══════════════════════════════════════════════════════════════
        $caseStmt = $pdo->prepare(
            'SELECT id, assigned_rescuer_id, status, animal_type FROM rescue_cases WHERE id = ?'
        );
        $caseStmt->execute([$postRequestId]);
        $caseRow = $caseStmt->fetch(PDO::FETCH_ASSOC);

        if (!$caseRow) {
            throw new RuntimeException('Case #' . $postRequestId . ' not found in the system.');
        }

        $currentRescuerId = !empty($caseRow['assigned_rescuer_id']) ? (int)$caseRow['assigned_rescuer_id'] : null;

        if ($currentRescuerId !== null) {
            if ($currentRescuerId === $rescuerId) {
                // Same rescuer already assigned — block duplicate
                throw new RuntimeException(
                    'This request is already assigned to "' . htmlspecialchars($rescuerData['name']) . '". No changes were made.'
                );
            } else {
                // Different rescuer already assigned — warn and block
                $existingStmt = $pdo->prepare('SELECT name FROM users WHERE id = ?');
                $existingStmt->execute([$currentRescuerId]);
                $existingName = $existingStmt->fetchColumn() ?: ('Rescuer #' . $currentRescuerId);
                throw new RuntimeException(
                    'This request is already assigned to another rescuer ("' . htmlspecialchars($existingName) . '"). '
                    . 'Please release the current assignment from the rescuer dashboard first, or remove via admin panel before reassigning.'
                );
            }
        }

        // ═══════════════════════════════════════════════════════════════
        // ALL CHECKS PASSED — Proceed with assignment
        // ═══════════════════════════════════════════════════════════════

        // Update rescue_cases: assign rescuer + ensure status is actionable
        $pdo->prepare(
            "UPDATE rescue_cases SET assigned_rescuer_id = ?, status = IF(status = 'rejected', 'pending', status) WHERE id = ?"
        )->execute([$rescuerId, $postRequestId]);

        // Update rescue_requests that reference this case
        try {
            $pdo->prepare('UPDATE rescue_requests SET rescuer_id = ?, rescuer_notified = 1 WHERE case_id = ?')
                ->execute([$rescuerId, $postRequestId]);
        } catch (Throwable $e) {
            // case_id column may not exist — fall through
        }

        // Update rescuers table status if it exists
        try {
            $pdo->prepare("UPDATE rescuers SET status = 'busy' WHERE id = ?")->execute([$rescuerId]);
        } catch (Throwable $e) {
            // rescuers table may not exist
        }

        // ─── Notifications ────────────────────────────────────────────
        // Notify the rescuer (appears in their dashboard Notifications tab)
        try {
            $caseAnimal = $caseRow['animal_type'] ?? 'animal';
            $notifMsg = sprintf(
                'New assignment: Case #%d (%s) has been assigned to you by admin.',
                $postRequestId,
                $caseAnimal
            );
            $pdo->prepare(
                "INSERT INTO notifications (rescuer_id, message, status, created_at) VALUES (?, ?, 'unread', NOW())"
            )->execute([$rescuerId, $notifMsg]);
        } catch (Throwable $e) {
            // notifications table may not exist
        }

        // Notify the reporter that a rescuer has been assigned
        try {
            $reporterId = $caseRow['reporter_id'] ?? null;
            if (!$reporterId) {
                $repStmt = $pdo->prepare('SELECT reporter_id FROM rescue_cases WHERE id = ?');
                $repStmt->execute([$postRequestId]);
                $reporterId = $repStmt->fetchColumn();
            }
            if ($reporterId) {
                $rName = $rescuerData['name'] ?: 'A rescuer';
                $pdo->prepare(
                    "INSERT INTO user_notifications (user_id, case_id, message, category, is_read, created_at)
                     VALUES (?, ?, ?, 'status_update', 0, NOW())"
                )->execute([
                    (int)$reporterId,
                    $postRequestId,
                    $rName . ' has been assigned to your rescue case #' . $postRequestId . '.'
                ]);
            }
        } catch (Throwable $e) {
            // user_notifications table may not exist
        }

        // ─── Audit log ────────────────────────────────────────────────
        try {
            $adminId = (int)$_SESSION['user_id'];
            $pdo->prepare(
                'INSERT INTO admin_activity_log (admin_id, action_type, target_table, target_id, details) VALUES (?,?,?,?,?)'
            )->execute([
                $adminId,
                'assign_rescuer',
                'rescue_cases',
                $postRequestId,
                'rescuer_id=' . $rescuerId . ' | name=' . ($rescuerData['name'] ?? '') . ' | validation=passed'
            ]);
        } catch (Throwable $e) {
            // Log table may not exist
        }

        header('Location: admin_dashboard.php?tab=requests&msg=' . urlencode(
            'Rescuer "' . ($rescuerData['name'] ?? 'Unknown') . '" assigned successfully to Case #' . $postRequestId . '.'
        ));
        exit;

    } catch (Throwable $e) {
        header('Location: rescuer_directory.php?request_id=' . $postRequestId . '&err=' . urlencode($e->getMessage()));
        exit;
    }
}

// ─── Validate request_id ───────────────────────────────────────────────
if ($requestId <= 0) {
    header('Location: admin_dashboard.php?tab=requests&err=' . urlencode('No request ID provided.'));
    exit;
}

$err = trim($_GET['err'] ?? '');

// Fetch the case / request info
$caseData = null;
try {
    $st = $pdo->prepare("
        SELECT c.*, u.name AS reporter_name
        FROM rescue_cases c
        JOIN users u ON c.reporter_id = u.id
        WHERE c.id = ?
    ");
    $st->execute([$requestId]);
    $caseData = $st->fetch();
} catch (Throwable $e) {
    $caseData = null;
}

if (!$caseData) {
    header('Location: admin_dashboard.php?tab=requests&err=' . urlencode('Case #' . $requestId . ' not found.'));
    exit;
}

// ─── Fetch all rescuers ────────────────────────────────────────────────
$rescuers = [];
try {
    // Try with rescuers table (has location + specialization)
    $rescuers = $pdo->query("
        SELECT
            u.id,
            u.name,
            u.email,
            COALESCE(u.account_status, 'active') AS account_status,
            COALESCE(u.availability_status, 'available') AS availability_status,
            COALESCE(r.latitude, u.latitude) AS latitude,
            COALESCE(r.longitude, u.longitude) AS longitude,
            r.status AS rescuer_status,
            COALESCE(u.specialization, r.specialization, 'General Rescue') AS specialization
        FROM users u
        LEFT JOIN rescuers r ON r.id = u.id
        WHERE u.role = 'rescuer'
          AND (u.account_status IS NULL OR u.account_status = 'active')
        ORDER BY u.name ASC
    ")->fetchAll();
} catch (Throwable $e) {
    // Fallback: no rescuers table — pull from users only
    try {
        $rescuers = $pdo->query("
            SELECT
                id, name, email,
                COALESCE(account_status, 'active') AS account_status,
                COALESCE(availability_status, 'available') AS availability_status,
                latitude, longitude,
                availability_status AS rescuer_status,
                NULL AS specialization
            FROM users
            WHERE role = 'rescuer'
              AND (account_status IS NULL OR account_status = 'active')
            ORDER BY name ASC
        ")->fetchAll();
    } catch (Throwable $e2) {
        $rescuers = [];
    }
}

$body_class = 'admin-dashboard-page';
require_once __DIR__ . '/includes/header.php';
?>

<style>
/* ─── Rescuer Directory Page ──────────────────────────────────────── */
.rd-shell {
    max-width: 1360px;
    margin: 1.5rem auto;
    padding: 0 1.25rem 3rem;
}
.rd-back {
    display: inline-flex;
    align-items: center;
    gap: 0.45rem;
    color: #4f46e5;
    text-decoration: none;
    font-weight: 600;
    font-size: 0.9rem;
    margin-bottom: 1.25rem;
    padding: 0.4rem 0.75rem;
    border-radius: 10px;
    transition: background 0.2s;
}
.rd-back:hover {
    background: rgba(79, 70, 229, 0.08);
}
.rd-header {
    margin-bottom: 1.5rem;
}
.rd-header h1 {
    font-family: 'Outfit', sans-serif;
    font-size: 1.65rem;
    font-weight: 800;
    color: #0f172a;
    margin: 0 0 0.4rem;
}
.rd-header p {
    color: #64748b;
    font-size: 0.92rem;
    margin: 0;
    line-height: 1.5;
}
.rd-case-banner {
    background: linear-gradient(135deg, rgba(99,102,241,0.08), rgba(16,185,129,0.06));
    border: 1px solid rgba(99,102,241,0.18);
    border-radius: 14px;
    padding: 1rem 1.25rem;
    margin-bottom: 1.5rem;
    display: flex;
    flex-wrap: wrap;
    gap: 1.5rem;
    align-items: center;
}
.rd-case-banner .meta-item {
    display: flex;
    flex-direction: column;
    gap: 0.15rem;
}
.rd-case-banner .meta-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: #94a3b8;
}
.rd-case-banner .meta-value {
    font-size: 0.95rem;
    font-weight: 600;
    color: #0f172a;
}
.rd-case-banner .meta-item--wide {
    flex: 1 1 100%;
    min-width: 280px;
}
.rd-location-row {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 0.75rem;
    margin-top: 0.35rem;
}
.rd-location-link {
    color: #4f46e5;
    text-decoration: underline;
    cursor: pointer;
    font-weight: 600;
    border: none;
    background: none;
    padding: 0;
    font-size: inherit;
    text-align: left;
}
.rd-location-link:hover {
    color: #3730a3;
}
.rd-view-map-btn {
    display: inline-flex;
    align-items: center;
    gap: 0.35rem;
    padding: 0.4rem 0.85rem;
    border-radius: 10px;
    border: 1px solid #c7d2fe;
    background: #eef2ff;
    color: #4338ca;
    font-weight: 700;
    font-size: 0.82rem;
    cursor: pointer;
    transition: background 0.15s, transform 0.15s;
}
.rd-view-map-btn:hover {
    background: #e0e7ff;
    transform: translateY(-1px);
}
#rd-case-map.geo-map-mini {
    min-height: 280px;
    width: 100%;
    margin-top: 0.75rem;
    border-radius: 12px;
    border: 1px solid #e2e8f0;
}
/* Grid of rescuer cards */
.rd-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 1.25rem;
}
.rd-card {
    background: #fff;
    border: 1px solid rgba(15,23,42,0.08);
    border-radius: 16px;
    box-shadow: 0 4px 18px rgba(15,23,42,0.05);
    padding: 1.35rem 1.4rem 1.15rem;
    display: flex;
    flex-direction: column;
    gap: 0.7rem;
    transition: transform 0.25s cubic-bezier(.4,0,.2,1), box-shadow 0.25s cubic-bezier(.4,0,.2,1), border-color 0.25s;
    position: relative;
}
.rd-card:hover {
    transform: translateY(-5px);
    box-shadow: 0 14px 36px rgba(79,70,229,0.14);
    border-color: rgba(99,102,241,0.25);
}
.rd-card.rd-card--assigned {
    border-color: rgba(16,185,129,0.35);
    box-shadow: 0 0 0 3px rgba(16,185,129,0.12), 0 4px 18px rgba(15,23,42,0.05);
}
.rd-card__top {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    gap: 0.6rem;
}
.rd-card__avatar {
    width: 48px;
    height: 48px;
    border-radius: 14px;
    background: linear-gradient(135deg, #6366f1, #10b981);
    color: #fff;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    font-weight: 700;
    flex-shrink: 0;
}
.rd-card__info {
    flex: 1;
    min-width: 0;
}
.rd-card__name {
    font-family: 'Outfit', sans-serif;
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    margin: 0;
    line-height: 1.3;
}
.rd-card__email {
    font-size: 0.82rem;
    color: #64748b;
    word-break: break-all;
}
.rd-card__badge {
    display: inline-flex;
    align-items: center;
    gap: 0.3rem;
    padding: 0.22rem 0.6rem;
    border-radius: 999px;
    font-size: 0.7rem;
    font-weight: 700;
    text-transform: capitalize;
    flex-shrink: 0;
}
.rd-badge--active  { background: #dcfce7; color: #166534; }
.rd-badge--busy    { background: #fee2e2; color: #991b1b; }
.rd-badge--offline { background: #e2e8f0; color: #334155; }
.rd-badge--available { background: #dbeafe; color: #1e40af; }
.rd-card__details {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 0.45rem 1rem;
    font-size: 0.84rem;
}
.rd-card__detail-label {
    font-size: 0.68rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: #94a3b8;
}
.rd-card__detail-value {
    color: #334155;
    font-weight: 500;
}
.rd-card__assign-btn {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.45rem;
    width: 100%;
    padding: 0.7rem 1rem;
    border: none;
    border-radius: 12px;
    background: linear-gradient(135deg, #6366f1, #4f46e5);
    color: #fff;
    font-family: 'Inter', sans-serif;
    font-size: 0.88rem;
    font-weight: 700;
    cursor: pointer;
    margin-top: auto;
    transition: transform 0.2s, box-shadow 0.2s, opacity 0.2s;
    box-shadow: 0 4px 16px rgba(99,102,241,0.3);
}
.rd-card__assign-btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 8px 24px rgba(99,102,241,0.4);
}
.rd-card__assign-btn:disabled {
    opacity: 0.4;
    cursor: not-allowed;
    transform: none;
    box-shadow: none;
}
.rd-card__assign-btn:active:not(:disabled) {
    transform: translateY(0);
}
.rd-empty {
    background: #fff;
    border: 1px dashed #cbd5e1;
    border-radius: 16px;
    padding: 3rem 2rem;
    text-align: center;
    color: #64748b;
    font-size: 0.95rem;
}
.rd-toast {
    border-radius: 12px;
    padding: 0.8rem 1rem;
    font-size: 0.9rem;
    margin-bottom: 1rem;
    display: flex;
    align-items: center;
    gap: 0.5rem;
}
.rd-toast--err { background: #fee2e2; color: #991b1b; }
.rd-toast--ok  { background: #dcfce7; color: #166534; }
@media (max-width: 640px) {
    .rd-grid { grid-template-columns: 1fr; }
    .rd-card__details { grid-template-columns: 1fr; }
}
</style>

<div class="rd-shell">
    <a href="admin_dashboard.php?tab=requests" class="rd-back"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to dashboard</a>

    <?php if ($err !== ''): ?>
        <div class="rd-toast rd-toast--err"><i class="fa-solid fa-circle-exclamation"></i> <?php echo htmlspecialchars($err); ?></div>
    <?php endif; ?>

    <div class="rd-header">
        <h1><i class="fa-solid fa-user-nurse text-primary" aria-hidden="true"></i> Rescuer Directory</h1>
        <p>Select a rescuer to assign to <strong>Case #<?php echo (int)$caseData['id']; ?></strong>. Cards show availability, location, and specialization.</p>
    </div>

    <div class="rd-case-banner">
        <div class="meta-item">
            <span class="meta-label">Case ID</span>
            <span class="meta-value">#<?php echo (int)$caseData['id']; ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Animal</span>
            <span class="meta-value"><?php echo htmlspecialchars($caseData['animal_type']); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Reporter</span>
            <span class="meta-value"><?php echo htmlspecialchars($caseData['reporter_name']); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Status</span>
            <span class="meta-value"><?php echo htmlspecialchars($caseData['status']); ?></span>
        </div>
        <div class="meta-item">
            <span class="meta-label">Priority</span>
            <span class="meta-value"><?php echo htmlspecialchars($caseData['priority_level']); ?></span>
        </div>
        <?php
        $caseLat = isset($caseData['latitude']) && $caseData['latitude'] !== '' && $caseData['latitude'] !== null
            ? (float)$caseData['latitude'] : null;
        $caseLon = isset($caseData['longitude']) && $caseData['longitude'] !== '' && $caseData['longitude'] !== null
            ? (float)$caseData['longitude'] : null;
        if ($caseLat !== null && $caseLon !== null):
        ?>
        <div class="meta-item meta-item--wide">
            <span class="meta-label">Request location</span>
            <div class="rd-location-row">
                <span class="js-rescue-location js-rd-view-map rd-location-link rd-location-text"
                      data-lat="<?php echo htmlspecialchars((string)$caseLat); ?>"
                      data-lon="<?php echo htmlspecialchars((string)$caseLon); ?>"
                      data-case-id="<?php echo (int)($caseData['id'] ?? 0); ?>"
                      data-needs-geocode="1"
                      data-skip-geocode="0"
                      role="button"
                      tabindex="0"
                      data-title="Case #<?php echo (int)($caseData['id'] ?? 0); ?> — rescue request"
                      title="Click to view on map"><i class="fa-solid fa-location-dot" aria-hidden="true"></i> <?php echo htmlspecialchars($caseLat); ?>, <?php echo htmlspecialchars($caseLon); ?></span>
                <button type="button" class="rd-view-map-btn js-rd-view-map"
                        data-lat="<?php echo htmlspecialchars((string)$caseLat); ?>"
                        data-lon="<?php echo htmlspecialchars((string)$caseLon); ?>"
                        data-title="Case #<?php echo (int)($caseData['id'] ?? 0); ?> — rescue request">
                    <i class="fa-solid fa-map" aria-hidden="true"></i> View on Map
                </button>
            </div>
            <div id="rd-case-map" class="geo-map-mini" hidden aria-label="Map showing rescue request location"></div>
        </div>
        <?php endif; ?>
    </div>

    <?php if (empty($rescuers)): ?>
        <div class="rd-empty">
            <i class="fa-solid fa-users-slash" style="font-size:2rem;margin-bottom:0.5rem;display:block;"></i>
            No active rescuers found. Ensure rescuer accounts exist with role <code>rescuer</code> and are not blocked.
        </div>
    <?php else: ?>
        <div class="rd-grid">
            <?php
            $currentAssigned = $caseData['assigned_rescuer_id'] ?? null;
            foreach ($rescuers as $r):
                $isCurrentlyAssigned = ($currentAssigned && (int)$currentAssigned === (int)$r['id']);
                $statusRaw = $r['rescuer_status'] ?? $r['availability_status'] ?? 'offline';
                $badgeClass = 'rd-badge--offline';
                if ($statusRaw === 'available') $badgeClass = 'rd-badge--available';
                elseif ($statusRaw === 'busy') $badgeClass = 'rd-badge--busy';
                elseif ($statusRaw === 'active') $badgeClass = 'rd-badge--active';

                $lat = $r['latitude'] ?? '—';
                $lng = $r['longitude'] ?? '—';
                $spec = !empty($r['specialization']) ? $r['specialization'] : 'General Rescue';
                $initials = strtoupper(substr($r['name'], 0, 1));
            ?>
            <article class="rd-card <?php echo $isCurrentlyAssigned ? 'rd-card--assigned' : ''; ?>">
                <div class="rd-card__top">
                    <div class="rd-card__avatar"><?php echo $initials; ?></div>
                    <div class="rd-card__info">
                        <h3 class="rd-card__name"><?php echo htmlspecialchars($r['name']); ?></h3>
                        <span class="rd-card__email"><?php echo htmlspecialchars($r['email']); ?></span>
                    </div>
                    <span class="rd-card__badge <?php echo $badgeClass; ?>"><?php echo htmlspecialchars($statusRaw); ?></span>
                </div>

                <div class="rd-card__details">
                    <div>
                        <div class="rd-card__detail-label">Location</div>
                        <div class="rd-card__detail-value"><?php echo htmlspecialchars("$lat, $lng"); ?></div>
                    </div>
                    <div>
                        <div class="rd-card__detail-label">Specialization</div>
                        <div class="rd-card__detail-value"><?php echo htmlspecialchars($spec); ?></div>
                    </div>
                </div>

                <form method="post" class="rd-assign-form">
                    <input type="hidden" name="assign_action" value="1">
                    <input type="hidden" name="request_id" value="<?php echo (int)$caseData['id']; ?>">
                    <input type="hidden" name="rescuer_id" value="<?php echo (int)$r['id']; ?>">
                    <button type="submit" class="rd-card__assign-btn" <?php echo $isCurrentlyAssigned ? 'disabled' : ''; ?>>
                        <i class="fa-solid fa-<?php echo $isCurrentlyAssigned ? 'check-circle' : 'hand-pointer'; ?>" aria-hidden="true"></i>
                        <?php echo $isCurrentlyAssigned ? 'Currently Assigned' : 'Assign to this Case'; ?>
                    </button>
                </form>
            </article>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="assets/js/rescuer-geocode.js"></script>
<script>
function rdOpenCaseMap(triggerEl) {
    if (!window.LocationGeocode) return;
    var lat = parseFloat(triggerEl.getAttribute('data-lat') || '');
    var lon = parseFloat(triggerEl.getAttribute('data-lon') || '');
    if (isNaN(lat) || isNaN(lon)) return;
    var mapEl = document.getElementById('rd-case-map');
    LocationGeocode.openInlineMap('rd-case-map', lat, lon, triggerEl.getAttribute('data-title') || 'Case location');
    if (mapEl) {
        mapEl.hidden = false;
        mapEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        setTimeout(function () {
            if (mapEl._leaflet_map) mapEl._leaflet_map.invalidateSize();
        }, 350);
    }
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.js-rd-view-map').forEach(function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            rdOpenCaseMap(btn);
        });
        btn.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                rdOpenCaseMap(btn);
            }
        });
    });
    // Disable button on submit to prevent double-click
    document.querySelectorAll('.rd-assign-form').forEach(function (form) {
        form.addEventListener('submit', function () {
            var btn = form.querySelector('.rd-card__assign-btn');
            if (!btn || btn.disabled) return;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin" aria-hidden="true"></i> Assigning…';
        });
    });
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
