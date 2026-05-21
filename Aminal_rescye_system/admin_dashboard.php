<?php
require_once 'backend/auth.php';
require_once 'backend/site_settings_helper.php';
require_login();
require_role('admin');

$msg = $_GET['msg'] ?? '';
$tab = $_GET['tab'] ?? 'overview';

function admin_audit_log(PDO $pdo, int $adminId, string $actionType, ?string $targetTable, ?int $targetId, ?string $details): void {
    try {
        $pdo->prepare(
            'INSERT INTO admin_activity_log (admin_id, action_type, target_table, target_id, details) VALUES (?,?,?,?,?)'
        )->execute([$adminId, $actionType, $targetTable, $targetId, $details]);
    } catch (Throwable $e) {
        // Log table may not exist until migration is applied
    }
}

function resolve_case_id_for_request(PDO $pdo, array $req): ?int {
    if (!empty($req['case_id'])) {
        return (int)$req['case_id'];
    }
    try {
        $st = $pdo->prepare(
            'SELECT id FROM rescue_cases WHERE reporter_id = ? AND (image_path = ? OR image_path IS NULL) ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$req['user_id'], $req['image']]);
        $row = $st->fetch();
        if ($row) {
            return (int)$row['id'];
        }
        $st2 = $pdo->prepare(
            'SELECT id FROM rescue_cases WHERE reporter_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st2->execute([$req['user_id']]);
        $row2 = $st2->fetch();
        return $row2 ? (int)$row2['id'] : null;
    } catch (Throwable $e) {
        return null;
    }
}

$adminId = (int)$_SESSION['user_id'];

// ─── Admin profile update (password / picture) ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_password = trim($_POST['new_password'] ?? '');
    $confirm_password = trim($_POST['confirm_password'] ?? '');
    $updates = [];
    $params = [];

    if ($new_password !== '') {
        if (strlen($new_password) < 6) {
            $err = 'Password must be at least 6 characters.';
        } elseif ($new_password !== $confirm_password) {
            $err = 'Passwords do not match. Please re-enter.';
        } else {
            $updates[] = 'password = ?';
            $params[] = password_hash($new_password, PASSWORD_DEFAULT);
        }
    }

    if (empty($err) && isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
        $dir = 'uploads/profiles/';
        if (!file_exists($dir)) mkdir($dir, 0777, true);
        $file_name = time() . '_' . basename($_FILES['profile_picture']['name']);
        $target = $dir . $file_name;
        if (move_uploaded_file($_FILES['profile_picture']['tmp_name'], $target)) {
            $updates[] = 'profile_picture = ?';
            $params[] = $target;
        }
    }

    if (empty($err) && count($updates) > 0) {
        $params[] = $adminId;
        $pdo->prepare('UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);
        admin_audit_log($pdo, $adminId, 'update_profile', 'users', $adminId, 'Profile updated');
        header('Location: admin_dashboard.php?tab=profile&msg=' . urlencode('Profile updated successfully.'));
        exit;
    } elseif (!empty($err)) {
        header('Location: admin_dashboard.php?tab=profile&err=' . urlencode($err));
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['admin_action'])) {
    $action = $_POST['admin_action'];
    try {
        if ($action === 'override_ai') {
            $rid = (int)($_POST['request_id'] ?? 0);
            $newDecision = $_POST['new_decision'] ?? '';
            $note = trim($_POST['override_note'] ?? '');
            if (!$rid || !in_array($newDecision, ['Accepted', 'Rejected'], true)) {
                throw new RuntimeException('Invalid override data.');
            }
            $st = $pdo->prepare('SELECT * FROM rescue_requests WHERE id = ?');
            $st->execute([$rid]);
            $req = $st->fetch();
            if (!$req) {
                throw new RuntimeException('Request not found.');
            }
            $caseId = resolve_case_id_for_request($pdo, $req);
            $isAccept = ($newDecision === 'Accepted');
            $upd = $pdo->prepare(
                'UPDATE rescue_requests SET status = ?, priority = ?, decision_source = ?, override_note = ? WHERE id = ?'
            );
            $upd->execute([
                $newDecision,
                $isAccept ? 'High' : 'Low',
                'admin',
                $note ?: 'Admin override',
                $rid,
            ]);
            if ($caseId) {
                $assignId = $req['rescuer_id'] ? (int)$req['rescuer_id'] : null;
                if ($isAccept && !$assignId) {
                    $r = $pdo->query("
                        SELECT id FROM users WHERE role='rescuer'
                        AND (account_status IS NULL OR account_status = 'active')
                        AND availability_status = 'available'
                        ORDER BY id ASC LIMIT 1
                    ")->fetch();
                    if (!$r) {
                        $r = $pdo->query("
                            SELECT id FROM users WHERE role='rescuer'
                            AND (account_status IS NULL OR account_status = 'active')
                            ORDER BY id ASC LIMIT 1
                        ")->fetch();
                    }
                    $assignId = $r ? (int)$r['id'] : null;
                }
                if ($isAccept) {
                    $pdo->prepare(
                        "UPDATE rescue_cases SET status='pending', priority_level='high', detected_injury_severity='high', assigned_rescuer_id = COALESCE(?, assigned_rescuer_id) WHERE id = ?"
                    )->execute([$assignId, $caseId]);
                    if ($assignId) {
                        $pdo->prepare('UPDATE rescue_requests SET rescuer_id = ?, rescuer_notified = 1 WHERE id = ?')->execute([$assignId, $rid]);
                    }
                } else {
                    $pdo->prepare(
                        "UPDATE rescue_cases SET status='rejected', priority_level='low', detected_injury_severity='low', assigned_rescuer_id = NULL WHERE id = ?"
                    )->execute([$caseId]);
                    $pdo->prepare('UPDATE rescue_requests SET rescuer_id = NULL, rescuer_notified = 0 WHERE id = ?')->execute([$rid]);
                }
            }
            admin_audit_log($pdo, $adminId, 'override_ai', 'rescue_requests', $rid, $newDecision . ': ' . $note);
            header('Location: admin_dashboard.php?tab=requests&msg=' . urlencode('AI decision overridden successfully.'));
            exit;
        }

        if ($action === 'assign_rescuer') {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $rescuerId = (int)($_POST['rescuer_id'] ?? 0);
            if (!$caseId || !$rescuerId) {
                throw new RuntimeException('Case and rescuer required.');
            }
            $pdo->prepare('UPDATE rescue_cases SET assigned_rescuer_id = ?, status = IF(status = \'rejected\', \'pending\', status) WHERE id = ?')->execute([$rescuerId, $caseId]);
            try {
                $pdo->prepare('UPDATE rescue_requests SET rescuer_id = ?, rescuer_notified = 1 WHERE case_id = ?')->execute([$rescuerId, $caseId]);
            } catch (Throwable $e) {
                // case_id column may be missing
            }
            admin_audit_log($pdo, $adminId, 'assign_rescuer', 'rescue_cases', $caseId, 'rescuer_id=' . $rescuerId);
            header('Location: admin_dashboard.php?tab=requests&msg=' . urlencode('Rescuer assigned.'));
            exit;
        }

        if ($action === 'case_status') {
            $caseId = (int)($_POST['case_id'] ?? 0);
            $newStatus = $_POST['case_status'] ?? '';
            $allowed = ['pending', 'accepted', 'resolved', 'rejected'];
            if (!$caseId || !in_array($newStatus, $allowed, true)) {
                throw new RuntimeException('Invalid case status.');
            }
            if ($newStatus === 'resolved') {
                $pdo->prepare("UPDATE rescue_cases SET status = ?, resolved_at = NOW() WHERE id = ?")->execute([$newStatus, $caseId]);
            } else {
                $pdo->prepare('UPDATE rescue_cases SET status = ? WHERE id = ?')->execute([$newStatus, $caseId]);
            }
            admin_audit_log($pdo, $adminId, 'case_status', 'rescue_cases', $caseId, $newStatus);
            header('Location: admin_dashboard.php?tab=requests&msg=' . urlencode('Rescue status updated.'));
            exit;
        }

        if ($action === 'block_user') {
            $uid = (int)($_POST['user_id'] ?? 0);
            $block = ($_POST['block'] ?? '1') === '1';
            if (!$uid || $uid === $adminId) {
                throw new RuntimeException('Invalid user.');
            }
            $st = $pdo->prepare('SELECT role FROM users WHERE id = ?');
            $st->execute([$uid]);
            $u = $st->fetch();
            if ($u && $u['role'] === 'admin') {
                throw new RuntimeException('Cannot block an administrator.');
            }
            try {
                $pdo->prepare('UPDATE users SET account_status = ? WHERE id = ?')->execute([$block ? 'blocked' : 'active', $uid]);
            } catch (Throwable $e) {
                throw new RuntimeException('Add account_status column (run database/migrate_admin_features.sql).');
            }
            admin_audit_log($pdo, $adminId, $block ? 'block_user' : 'unblock_user', 'users', $uid, '');
            header('Location: admin_dashboard.php?tab=users&msg=' . urlencode($block ? 'User blocked.' : 'User unblocked.'));
            exit;
        }

        if ($action === 'delete_case') {
            $caseId = (int)($_POST['case_id'] ?? 0);
            if (!$caseId) {
                throw new RuntimeException('Invalid case.');
            }
            $pdo->beginTransaction();
            $pdo->prepare('DELETE FROM adoption_predictions WHERE case_id = ?')->execute([$caseId]);
            try {
                $pdo->prepare('DELETE FROM rescue_requests WHERE case_id = ?')->execute([$caseId]);
            } catch (Throwable $e) {
            }
            $pdo->prepare('DELETE FROM rescue_cases WHERE id = ?')->execute([$caseId]);
            $pdo->commit();
            admin_audit_log($pdo, $adminId, 'delete_case', 'rescue_cases', $caseId, 'Spam or invalid request removed');
            header('Location: admin_dashboard.php?tab=requests&msg=' . urlencode('Request deleted.'));
            exit;
        }

        if ($action === 'add_rescuer') {
            $name = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $password = $_POST['password'] ?? '';
            if ($name === '' || $email === '' || strlen($password) < 6) {
                throw new RuntimeException('Name, email, and password (min 6 chars) required.');
            }
            $st = $pdo->prepare('SELECT id FROM users WHERE email = ?');
            $st->execute([$email]);
            if ($st->fetch()) {
                throw new RuntimeException('Email already registered.');
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pdo->prepare('INSERT INTO users (name, email, password, role) VALUES (?, ?, ?, \'rescuer\')')->execute([$name, $email, $hash]);
            admin_audit_log($pdo, $adminId, 'add_rescuer', 'users', (int)$pdo->lastInsertId(), $email);
            header('Location: admin_dashboard.php?tab=users&msg=' . urlencode('Rescuer account created.'));
            exit;
        }

        if ($action === 'save_content') {
            save_site_setting($pdo, 'about_intro', trim($_POST['about_intro'] ?? ''));
            save_site_setting($pdo, 'contact_address', trim($_POST['contact_address'] ?? ''));
            save_site_setting($pdo, 'contact_email', trim($_POST['contact_email'] ?? ''));
            save_site_setting($pdo, 'contact_phone', trim($_POST['contact_phone'] ?? ''));
            save_site_setting($pdo, 'announcement', trim($_POST['announcement'] ?? ''));
            admin_audit_log($pdo, $adminId, 'save_content', 'site_settings', null, '');
            header('Location: admin_dashboard.php?tab=content&msg=' . urlencode('Site content saved.'));
            exit;
        }
    } catch (Throwable $e) {
        header('Location: admin_dashboard.php?tab=' . urlencode($tab) . '&err=' . urlencode($e->getMessage()));
        exit;
    }
}

$err = $_GET['err'] ?? '';

// Profile update (unchanged)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $new_password = $_POST['new_password'];
    $updates = [];
    $params = [];
    if (!empty($new_password)) {
        $updates[] = 'password = ?';
        $params[] = password_hash($new_password, PASSWORD_DEFAULT);
    }
    if (isset($_FILES['profile_picture']) && $_FILES['profile_picture']['error'] === 0) {
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
    if (count($updates) > 0) {
        $params[] = $_SESSION['user_id'];
        $sql = 'UPDATE users SET ' . implode(', ', $updates) . ' WHERE id = ?';
        if ($pdo->prepare($sql)->execute($params)) {
            $msg = 'Profile updated successfully!';
        } else {
            $msg = 'Failed to update profile.';
        }
    }
}

// Stats
$stats = [];
try {
    $stats['total_users'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='user'")->fetchColumn();
    $stats['total_rescuers'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role='rescuer'")->fetchColumn();
    $stats['total_cases'] = (int)$pdo->query('SELECT COUNT(*) FROM rescue_cases')->fetchColumn();
    $stats['pending'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status='pending'")->fetchColumn();
    $stats['in_progress'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status='accepted'")->fetchColumn();
    $stats['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status='resolved'")->fetchColumn();
    $stats['rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_cases WHERE status='rejected'")->fetchColumn();
    $stats['req_accepted'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_requests WHERE status='Accepted'")->fetchColumn();
    $stats['req_rejected'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_requests WHERE status='Rejected'")->fetchColumn();
    $stats['low_confidence'] = (int)$pdo->query('SELECT COUNT(*) FROM rescue_requests WHERE confidence < 0.70')->fetchColumn();
    $stats['admin_overrides'] = (int)$pdo->query("SELECT COUNT(*) FROM rescue_requests WHERE decision_source='admin'")->fetchColumn();
} catch (Throwable $e) {
    $stats = array_fill_keys(['total_users', 'total_rescuers', 'total_cases', 'pending', 'in_progress', 'completed', 'rejected', 'req_accepted', 'req_rejected', 'low_confidence', 'admin_overrides'], 0);
}

$stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
$stmt->execute([$_SESSION['user_id']]);
$user_data = $stmt->fetch();

$ai_requests = [];
try {
    $ai_requests = $pdo->query("
        SELECT r.*, u.name AS reporter_name, u.email AS reporter_email,
               c.animal_type, c.status AS case_status, c.id AS linked_case_id,
               resc.name AS rescuer_name
        FROM rescue_requests r
        JOIN users u ON r.user_id = u.id
        LEFT JOIN rescue_cases c ON r.case_id = c.id
        LEFT JOIN users resc ON r.rescuer_id = resc.id
        ORDER BY r.created_at DESC
        LIMIT 200
    ")->fetchAll();
} catch (Throwable $e) {
    $ai_requests = [];
}

$cases_list = [];
try {
    $cases_list = $pdo->query("
        SELECT c.*, u.name AS reporter_name, rep.name AS rescuer_name
        FROM rescue_cases c
        JOIN users u ON c.reporter_id = u.id
        LEFT JOIN users rep ON c.assigned_rescuer_id = rep.id
        ORDER BY c.created_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (Throwable $e) {
    $cases_list = [];
}

$all_users = [];
try {
    $all_users = $pdo->query("
        SELECT id, name, email, role, COALESCE(account_status, 'active') AS account_status, created_at
        FROM users
        WHERE role != 'admin'
        ORDER BY role, name
    ")->fetchAll();
} catch (Throwable $e) {
    $all_users = $pdo->query('SELECT id, name, email, role, created_at FROM users WHERE role != \'admin\' ORDER BY role, name')->fetchAll();
}

$rescuers_list = [];
try {
    $rescuers_list = $pdo->query("
        SELECT id, name, email, COALESCE(account_status,'active') AS account_status
        FROM users WHERE role='rescuer' ORDER BY name
    ")->fetchAll();
} catch (Throwable $e) {
    $rescuers_list = $pdo->query("SELECT id, name, email FROM users WHERE role='rescuer' ORDER BY name")->fetchAll();
}

$activity_log = [];
try {
    $activity_log = $pdo->query("
        SELECT l.*, a.name AS admin_name
        FROM admin_activity_log l
        JOIN users a ON l.admin_id = a.id
        ORDER BY l.created_at DESC
        LIMIT 100
    ")->fetchAll();
} catch (Throwable $e) {
    $activity_log = [];
}

$notifications = $pdo->query("
    SELECT * FROM rescue_cases
    WHERE status IN ('pending','accepted') OR priority_level IN ('high','urgent')
    ORDER BY created_at DESC LIMIT 8
")->fetchAll();

$about_intro = get_site_setting($pdo, 'about_intro', '');
$contact_address = get_site_setting($pdo, 'contact_address', '');
$contact_email = get_site_setting($pdo, 'contact_email', '');
$contact_phone = get_site_setting($pdo, 'contact_phone', '');
$announcement = get_site_setting($pdo, 'announcement', '');
$body_class = 'admin-dashboard-page';
?>
<?php require_once 'includes/header.php'; ?>

<style>
/* Fallback safety so layout remains clean even with stale cache */
.admin-section { display: none; }
.admin-section.active { display: block; }
.admin-dashboard-layout { display: flex; gap: 1.25rem; align-items: flex-start; }
.admin-main-scroll { flex: 1; min-width: 0; }
@media (max-width: 1200px) {
    .admin-dashboard-layout { flex-wrap: wrap; }
    .admin-main-scroll { width: 100%; }
}
</style>

<div class="admin-dashboard-layout">
    <aside class="admin-sidebar-nav" aria-label="Admin navigation">
        <div class="admin-sidebar-brand">
            <?php if (!empty($user_data['profile_picture'])): ?>
                <img src="<?php echo htmlspecialchars($user_data['profile_picture']); ?>" alt="" style="width: 72px; height: 72px; border-radius: 50%; object-fit: cover; border: 2px solid rgba(99,102,241,0.25);">
            <?php else: ?>
                <div style="width: 72px; height: 72px; border-radius: 50%; background: linear-gradient(135deg, #ea580c, #fb923c); color: white; display: flex; align-items: center; justify-content: center; font-size: 1.75rem; margin: 0 auto;">
                    <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                </div>
            <?php endif; ?>
            <h3><?php echo htmlspecialchars($user_data['name']); ?></h3>
            <span class="role-tag">Administrator</span>
            <p class="role-desc">Supervise AI accuracy, users, and rescue workflow—not field response.</p>
        </div>
        <ul class="admin-sidebar-menu">
            <li><a href="#" class="<?php echo $tab === 'overview' ? 'active' : ''; ?>" data-tab="overview"><i class="fa-solid fa-chart-line" aria-hidden="true"></i> Overview</a></li>
            <li><a href="#" class="<?php echo $tab === 'requests' ? 'active' : ''; ?>" data-tab="requests"><i class="fa-solid fa-clipboard-list" aria-hidden="true"></i> AI &amp; requests</a></li>
            <li><a href="#" class="<?php echo $tab === 'users' ? 'active' : ''; ?>" data-tab="users"><i class="fa-solid fa-users-gear" aria-hidden="true"></i> Users &amp; rescuers</a></li>
            <li><a href="#" class="<?php echo $tab === 'notifications' ? 'active' : ''; ?>" data-tab="notifications"><i class="fa-solid fa-bell" aria-hidden="true"></i> Notifications <span id="admin-notif-badge" style="background:#ef4444;color:#fff;border-radius:999px;padding:0.1rem 0.45rem;font-size:0.7rem;margin-left:0.3rem;display:none;"></span></a></li>
            <li><a href="#" class="<?php echo $tab === 'content' ? 'active' : ''; ?>" data-tab="content"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i> Site content</a></li>
            <li><a href="#" class="<?php echo $tab === 'log' ? 'active' : ''; ?>" data-tab="log"><i class="fa-solid fa-list-ul" aria-hidden="true"></i> Activity log</a></li>
            <li><a href="#" class="<?php echo $tab === 'profile' ? 'active' : ''; ?>" data-tab="profile"><i class="fa-solid fa-user-gear" aria-hidden="true"></i> Profile</a></li>
            <li><a href="logout.php" class="nav-logout"><i class="fa-solid fa-right-from-bracket" aria-hidden="true"></i> Logout</a></li>
        </ul>
    </aside>

    <div class="admin-main-scroll" role="main">
        <div id="tab-overview" class="admin-section <?php echo $tab === 'overview' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Control center</h1>
                <p>Monitor volumes, AI acceptance rates, and rescue lifecycle. Your role is supervision and correction—not hands-on rescue.</p>
            </header>

            <div class="admin-stat-grid">
                <div class="admin-stat-card" style="--stat-accent:#6366f1;"><div class="value"><?php echo $stats['total_cases']; ?></div><div class="label">Total reports</div></div>
                <div class="admin-stat-card" style="--stat-accent:#10b981;"><div class="value"><?php echo $stats['req_accepted']; ?></div><div class="label">Accepted (AI/DB)</div></div>
                <div class="admin-stat-card" style="--stat-accent:#ef4444;"><div class="value"><?php echo $stats['req_rejected']; ?></div><div class="label">Rejected</div></div>
                <div class="admin-stat-card" style="--stat-accent:#f59e0b;"><div class="value"><?php echo $stats['pending']; ?></div><div class="label">Pending</div></div>
                <div class="admin-stat-card" style="--stat-accent:#3b82f6;"><div class="value"><?php echo $stats['in_progress']; ?></div><div class="label">In progress</div></div>
                <div class="admin-stat-card" style="--stat-accent:#8b5cf6;"><div class="value"><?php echo $stats['completed']; ?></div><div class="label">Completed</div></div>
                <div class="admin-stat-card" style="--stat-accent:#64748b;"><div class="value"><?php echo $stats['low_confidence']; ?></div><div class="label">Low confidence</div></div>
                <div class="admin-stat-card" style="--stat-accent:#ec4899;"><div class="value"><?php echo $stats['admin_overrides']; ?></div><div class="label">Admin overrides</div></div>
            </div>

            <div class="admin-panel">
                <h2 class="admin-panel__title"><i class="fa-solid fa-users text-primary" aria-hidden="true"></i> Community snapshot</h2>
                <p style="margin:0; color:#64748b; font-size:0.92rem; line-height:1.6;">Registered reporters: <strong style="color:#0f172a;"><?php echo $stats['total_users']; ?></strong>
                    &nbsp;·&nbsp; Rescuers: <strong style="color:#0f172a;"><?php echo $stats['total_rescuers']; ?></strong></p>
                <p style="margin:0.75rem 0 0; font-size:0.82rem; color:#94a3b8;">If AI requests or logs are empty, run <code>database/migrate_admin_features.sql</code> once in phpMyAdmin.</p>
            </div>
        </div>

        <div id="tab-requests" class="admin-section <?php echo $tab === 'requests' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>AI supervision &amp; requests</h1>
                <p>Each row is a clear card: verify the image and AI verdict, then use grouped actions to override, assign a rescuer, move the case along, or remove spam.</p>
            </header>

            <div class="admin-panel">
                <h2 class="admin-panel__title"><i class="fa-solid fa-images text-primary" aria-hidden="true"></i> Rescue request queue</h2>
                <?php if (empty($ai_requests)): ?>
                    <p style="margin:0; color:#64748b;">No data in <code>rescue_requests</code> yet, or run <code>database/migrate_admin_features.sql</code> for <code>case_id</code> / <code>decision_source</code>.</p>
                <?php else: ?>
                    <?php foreach ($ai_requests as $r): ?>
                        <?php
                        $lowC = ((float)$r['confidence']) < 0.7;
                        $src = ($r['decision_source'] ?? 'ai') === 'admin' ? 'admin' : 'ai';
                        $cid = !empty($r['linked_case_id']) ? (int)$r['linked_case_id'] : (!empty($r['case_id']) ? (int)$r['case_id'] : null);
                        ?>
                        <article class="admin-request-card">
                            <div class="admin-request-card__head">
                                <strong>Request #<?php echo (int)$r['id']; ?></strong>
                                <span class="admin-badge-pill <?php echo $src === 'admin' ? 'admin-badge-pill--admin' : 'admin-badge-pill--ai'; ?>"><?php echo strtoupper($src); ?> decision</span>
                                <?php if ($lowC): ?><span class="admin-badge-pill admin-badge-pill--risk">Low confidence</span><?php endif; ?>
                                <?php if ($r['status'] === 'Accepted'): ?><span class="admin-badge-pill admin-badge-pill--ok">Accepted</span><?php else: ?><span class="admin-badge-pill admin-badge-pill--risk">Rejected</span><?php endif; ?>
                                <span style="margin-left:auto; font-size:0.8rem; color:#94a3b8;"><?php echo date('M j, Y · H:i', strtotime($r['created_at'])); ?></span>
                            </div>
                            <div class="admin-request-card__body">
                                <div>
                                    <?php if (!empty($r['image'])): ?>
                                        <a href="<?php echo htmlspecialchars($r['image']); ?>" target="_blank" rel="noopener" title="Open full image">
                                            <img class="admin-request-card__thumb" src="<?php echo htmlspecialchars($r['image']); ?>" alt="Uploaded animal">
                                        </a>
                                    <?php else: ?>
                                        <div class="admin-request-card__thumb" style="display:flex;align-items:center;justify-content:center;background:#f1f5f9;color:#94a3b8;font-size:0.75rem;">No image</div>
                                    <?php endif; ?>
                                </div>
                                <dl class="admin-meta-grid">
                                    <div>
                                        <dt>Reporter</dt>
                                        <dd><?php echo htmlspecialchars($r['reporter_name']); ?><br><span style="font-weight:400;color:#64748b;font-size:0.82rem;"><?php echo htmlspecialchars($r['reporter_email']); ?></span></dd>
                                    </div>
                                    <div>
                                        <dt>AI injury label</dt>
                                        <dd><?php echo htmlspecialchars($r['ai_result']); ?> &nbsp;·&nbsp; <strong><?php echo number_format((float)$r['confidence'] * 100, 1); ?>%</strong></dd>
                                    </div>
                                    <div>
                                        <dt>System decision</dt>
                                        <dd><?php echo htmlspecialchars($r['status']); ?> priority <strong><?php echo htmlspecialchars($r['priority']); ?></strong></dd>
                                    </div>
                                    <div>
                                        <dt>Location</dt>
                                        <dd style="font-weight:400;"><?php
                                            $loc = $r['location'] ?? '';
                                            if (function_exists('mb_strimwidth')) {
                                                echo htmlspecialchars(mb_strimwidth($loc, 0, 120, '…'));
                                            } else {
                                                echo htmlspecialchars(strlen($loc) > 120 ? substr($loc, 0, 117) . '…' : $loc);
                                            }
                                        ?></dd>
                                    </div>
                                    <div style="grid-column:1/-1;">
                                        <dt>Description</dt>
                                        <dd style="font-weight:400;line-height:1.5;"><?php
                                            $desc = $r['description'] ?? '';
                                            if (function_exists('mb_strimwidth')) {
                                                $desc = mb_strimwidth($desc, 0, 280, '…');
                                            } elseif (strlen($desc) > 280) {
                                                $desc = substr($desc, 0, 277) . '…';
                                            }
                                            echo nl2br(htmlspecialchars($desc));
                                        ?></dd>
                                    </div>
                                    <?php if ($cid): ?>
                                    <div>
                                        <dt>Linked case</dt>
                                        <dd>#<?php echo $cid; ?> · <?php echo htmlspecialchars($r['animal_type'] ?? '—'); ?> · <em><?php echo htmlspecialchars($r['case_status'] ?? ''); ?></em>
                                            <?php if (!empty($r['rescuer_name'])): ?><br><span style="font-size:0.82rem;color:#64748b;">Rescuer: <?php echo htmlspecialchars($r['rescuer_name']); ?></span><?php endif; ?></dd>
                                    </div>
                                    <?php endif; ?>
                                </dl>
                                <div class="admin-actions-stack">
                                    <div class="action-group">
                                        <h4>Override AI</h4>
                                        <form method="post">
                                            <input type="hidden" name="admin_action" value="override_ai">
                                            <input type="hidden" name="request_id" value="<?php echo (int)$r['id']; ?>">
                                            <select name="new_decision" class="form-control">
                                                <option value="Accepted" <?php echo $r['status'] === 'Accepted' ? 'selected' : ''; ?>>Accept case</option>
                                                <option value="Rejected" <?php echo $r['status'] === 'Rejected' ? 'selected' : ''; ?>>Reject case</option>
                                            </select>
                                            <input type="text" name="override_note" class="form-control" placeholder="Reason for audit log">
                                            <button type="submit" class="btn btn-primary">Apply override</button>
                                        </form>
                                    </div>
                                    <?php if ($cid): ?>
                                    <div class="action-group">
                                        <h4>Assign rescuer</h4>
                                        <a href="rescuer_directory.php?request_id=<?php echo $cid; ?>" class="btn btn-secondary" style="display:inline-flex;align-items:center;gap:0.4rem;text-decoration:none;font-size:0.82rem;"><i class="fa-solid fa-user-nurse" aria-hidden="true"></i> Assign</a>
                                    </div>
                                    <div class="action-group">
                                        <h4>Case lifecycle</h4>
                                        <form method="post">
                                            <input type="hidden" name="admin_action" value="case_status">
                                            <input type="hidden" name="case_id" value="<?php echo $cid; ?>">
                                            <select name="case_status" class="form-control">
                                                <option value="pending">Pending</option>
                                                <option value="accepted">In progress</option>
                                                <option value="resolved">Completed</option>
                                                <option value="rejected">Rejected</option>
                                            </select>
                                            <button type="submit" class="btn btn-secondary">Update status</button>
                                        </form>
                                    </div>
                                    <div class="action-group">
                                        <h4>Spam / invalid</h4>
                                        <form method="post" onsubmit="return confirm('Delete this case and related records permanently?');">
                                            <input type="hidden" name="admin_action" value="delete_case">
                                            <input type="hidden" name="case_id" value="<?php echo $cid; ?>">
                                            <button type="submit" class="btn" style="background:#fee2e2;color:#991b1b;border:none;">Delete case</button>
                                        </form>
                                    </div>
                                    <?php else: ?>
                                    <div class="action-group">
                                        <p style="margin:0;font-size:0.82rem;color:#64748b;">No linked case ID for this row—override still updates the AI request record. New submissions include <code>case_id</code> after migration.</p>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <div class="admin-panel" style="overflow-x:auto;">
                <h2 class="admin-panel__title"><i class="fa-solid fa-route text-primary" aria-hidden="true"></i> Recent cases (lifecycle overview)</h2>
                <table class="admin-data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Animal</th>
                            <th>Status</th>
                            <th>Priority</th>
                            <th>Reporter</th>
                            <th>Rescuer</th>
                            <th>Created</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($cases_list as $c): ?>
                        <tr>
                            <td>#<?php echo (int)$c['id']; ?></td>
                            <td><?php echo htmlspecialchars($c['animal_type']); ?></td>
                            <td><?php echo htmlspecialchars($c['status']); ?></td>
                            <td><?php echo htmlspecialchars($c['priority_level']); ?></td>
                            <td><?php echo htmlspecialchars($c['reporter_name']); ?></td>
                            <td><?php echo htmlspecialchars($c['rescuer_name'] ?? '—'); ?></td>
                            <td><?php echo date('M j, H:i', strtotime($c['created_at'])); ?></td>
                        </tr>
                    <?php endforeach; ?>
                    <?php if (empty($cases_list)): ?>
                        <tr><td colspan="7" style="padding:1.25rem; color:#64748b;">No cases yet.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-users" class="admin-section <?php echo $tab === 'users' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Users &amp; rescuers</h1>
                <p>Keep the community trustworthy: onboard rescuers here, and block accounts that abuse the platform.</p>
            </header>



            <div class="admin-panel" style="overflow-x:auto;">
                <h2 class="admin-panel__title"><i class="fa-solid fa-address-book text-primary" aria-hidden="true"></i> Directory (non-admin)</h2>
                <table class="admin-data-table">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Name</th>
                            <th>Email</th>
                            <th>Role</th>
                            <th>Status</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($all_users as $u): ?>
                        <?php $st = $u['account_status'] ?? 'active'; ?>
                        <tr>
                            <td><?php echo (int)$u['id']; ?></td>
                            <td><?php echo htmlspecialchars($u['name']); ?></td>
                            <td><?php echo htmlspecialchars($u['email']); ?></td>
                            <td><?php echo htmlspecialchars($u['role']); ?></td>
                            <td><span class="admin-badge-pill <?php echo $st === 'blocked' ? 'admin-badge-pill--risk' : 'admin-badge-pill--ok'; ?>"><?php echo htmlspecialchars($st); ?></span></td>
                            <td>
                                <form method="post" style="display:inline;">
                                    <input type="hidden" name="admin_action" value="block_user">
                                    <input type="hidden" name="user_id" value="<?php echo (int)$u['id']; ?>">
                                    <input type="hidden" name="block" value="<?php echo $st === 'blocked' ? '0' : '1'; ?>">
                                    <button type="submit" class="btn btn-secondary" style="font-size:0.78rem;"><?php echo $st === 'blocked' ? 'Unblock' : 'Block'; ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <div id="tab-content" class="admin-section <?php echo $tab === 'content' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Site content</h1>
                <p>Keep public pages accurate: About intro, contact blocks, and the optional announcement bar.</p>
            </header>
            <div class="admin-panel" style="max-width: 720px;">
                <form method="post">
                    <input type="hidden" name="admin_action" value="save_content">
                    <div class="form-group">
                        <label>About page intro</label>
                        <textarea name="about_intro" class="form-control" rows="4"><?php echo htmlspecialchars($about_intro); ?></textarea>
                    </div>
                    <div class="form-group">
                        <label>Contact address</label>
                        <input type="text" name="contact_address" class="form-control" value="<?php echo htmlspecialchars($contact_address); ?>">
                    </div>
                    <div class="form-group">
                        <label>Contact email</label>
                        <input type="email" name="contact_email" class="form-control" value="<?php echo htmlspecialchars($contact_email); ?>">
                    </div>
                    <div class="form-group">
                        <label>Contact phone</label>
                        <input type="text" name="contact_phone" class="form-control" value="<?php echo htmlspecialchars($contact_phone); ?>">
                    </div>
                    <div class="form-group">
                        <label>Announcement (banner text; leave empty to hide)</label>
                        <textarea name="announcement" class="form-control" rows="2" placeholder="e.g. Maintenance tonight 10pm"><?php echo htmlspecialchars($announcement); ?></textarea>
                    </div>
                    <button type="submit" class="btn btn-primary">Save content</button>
                </form>
            </div>
        </div>

        <div id="tab-log" class="admin-section <?php echo $tab === 'log' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Activity log</h1>
                <p>Audit trail: overrides, rescuer assignment, user blocks, and content updates.</p>
            </header>
            <div class="admin-panel" style="overflow-x:auto;">
                <?php if (empty($activity_log)): ?>
                    <p style="margin:0; color:#64748b;">No entries yet. Run migration to create <code>admin_activity_log</code>.</p>
                <?php else: ?>
                    <table class="admin-data-table">
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Admin</th>
                                <th>Action</th>
                                <th>Target</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($activity_log as $log): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($log['created_at']); ?></td>
                                <td><?php echo htmlspecialchars($log['admin_name']); ?></td>
                                <td><?php echo htmlspecialchars($log['action_type']); ?></td>
                                <td><?php echo htmlspecialchars(trim(($log['target_table'] ?? '') . ' #' . ($log['target_id'] ?? ''), ' #')); ?></td>
                                <td><?php echo htmlspecialchars($log['details'] ?? ''); ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>

        <div id="tab-profile" class="admin-section <?php echo $tab === 'profile' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Your profile</h1>
                <p>Update your admin avatar or password.</p>
            </header>
            <div class="admin-panel" style="max-width: 480px;">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="update_profile" value="1">
                    <div class="form-group">
                        <label>Profile picture</label>
                        <input type="file" name="profile_picture" class="form-control" accept="image/*">
                    </div>
                    <div class="form-group">
                        <label>New password</label>
                        <input type="password" name="new_password" id="admin-new-pw" class="form-control" placeholder="Leave blank to keep current" minlength="6">
                    </div>
                    <div class="form-group">
                        <label>Confirm password</label>
                        <input type="password" name="confirm_password" id="admin-confirm-pw" class="form-control" placeholder="Re-enter new password">
                        <small id="admin-pw-match" style="font-size:0.78rem;"></small>
                    </div>
                    <button type="submit" class="btn btn-primary">Save</button>
                </form>
                <script>
                (function(){
                    var pw = document.getElementById('admin-new-pw');
                    var cpw = document.getElementById('admin-confirm-pw');
                    var hint = document.getElementById('admin-pw-match');
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
        </div>

        <!-- ═══ Notifications Tab ═══ -->
        <div id="tab-notifications" class="admin-section <?php echo $tab === 'notifications' ? 'active' : ''; ?>">
            <header class="admin-page-header">
                <h1>Notifications</h1>
                <p>Live updates from rescuers — progress notes, status changes, and location updates.</p>
            </header>
            <div style="display:flex;justify-content:flex-end;margin-bottom:1rem;gap:0.5rem;">
                <button type="button" class="btn btn-secondary" id="admin-mark-all-read"><i class="fa-solid fa-check-double"></i> Mark all as read</button>
                <button type="button" class="btn btn-secondary" id="admin-refresh-notif"><i class="fa-solid fa-arrows-rotate"></i> Refresh</button>
            </div>
            <div class="admin-panel" id="admin-notif-list" style="max-height:500px;overflow-y:auto;">
                <p style="color:#94a3b8;text-align:center;padding:2rem;"><i class="fa-solid fa-spinner fa-spin"></i> Loading notifications...</p>
            </div>

            <h3 style="margin-top:1.5rem;"><i class="fa-solid fa-location-dot" style="color:#4f46e5;"></i> Active Rescuer Locations</h3>
            <div id="admin-rescuer-map" style="height:350px;border-radius:12px;border:1px solid #e2e8f0;margin-top:0.5rem;background:#f1f5f9;"></div>
        </div>
    </div>

    <aside class="admin-sidebar-alerts" aria-label="Attention queue">
        <h3><i class="fa-solid fa-bell" aria-hidden="true"></i> Needs attention</h3>
        <?php if (count($notifications) === 0): ?>
            <p style="margin:0; font-size:0.88rem; color:#64748b;">No high-priority items in queue.</p>
        <?php else: ?>
            <?php foreach ($notifications as $n): ?>
                <div class="admin-alert-item">
                    <strong>#<?php echo (int)$n['id']; ?></strong> <?php echo htmlspecialchars($n['animal_type']); ?><br>
                    <span style="color:#64748b;font-size:0.8rem;"><?php echo htmlspecialchars($n['status']); ?> · <?php echo htmlspecialchars($n['priority_level']); ?></span>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </aside>
</div>

<script>
function activateAdminTab(tabName, linkEl) {
    document.querySelectorAll('.admin-section').forEach(function (s) { s.classList.remove('active'); });
    document.querySelectorAll('.admin-sidebar-menu a[data-tab]').forEach(function (a) { a.classList.remove('active'); });
    var pane = document.getElementById('tab-' + tabName);
    if (pane) pane.classList.add('active');
    if (linkEl) linkEl.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function () {
    var links = document.querySelectorAll('.admin-sidebar-menu a[data-tab]');
    if (!links.length) return;

    var activeLink = document.querySelector('.admin-sidebar-menu a[data-tab].active');
    if (!activeLink) activeLink = links[0];
    activateAdminTab(activeLink.getAttribute('data-tab'), activeLink);

    links.forEach(function (link) {
        link.addEventListener('click', function (e) {
            e.preventDefault();
            var tab = this.getAttribute('data-tab');
            activateAdminTab(tab, this);
            if (history.replaceState) {
                history.replaceState(null, '', 'admin_dashboard.php?tab=' + encodeURIComponent(tab));
            }
        });
    });
});
</script>

<!-- Admin Notification Polling -->
<script>
(function(){
    var list = document.getElementById('admin-notif-list');
    var badge = document.getElementById('admin-notif-badge');
    var markBtn = document.getElementById('admin-mark-all-read');
    var refreshBtn = document.getElementById('admin-refresh-notif');
    var adminMap = null;
    var adminMarkers = [];
    var mapInitialized = false;

    function initMap() {
        if (mapInitialized || typeof L === 'undefined') return;
        var mapEl = document.getElementById('admin-rescuer-map');
        if (!mapEl) return;
        adminMap = L.map('admin-rescuer-map').setView([27.7172, 85.3240], 6);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19, attribution: '&copy; <a href="https://openstreetmap.org">OpenStreetMap</a>'
        }).addTo(adminMap);
        mapInitialized = true;
        // Multiple invalidateSize to handle hidden container
        setTimeout(function(){ adminMap.invalidateSize(); }, 200);
        setTimeout(function(){ adminMap.invalidateSize(); }, 600);
        setTimeout(function(){ adminMap.invalidateSize(); }, 1200);
    }

    function updateMapMarkers(locations) {
        if (!adminMap) return;
        // Clear old markers
        adminMarkers.forEach(function(m){ adminMap.removeLayer(m); });
        adminMarkers = [];

        if (!locations || locations.length === 0) {
            return; // Map shows but no markers
        }

        var bounds = [];
        locations.forEach(function(r) {
            var lat = parseFloat(r.latitude), lon = parseFloat(r.longitude);
            if (isNaN(lat) || isNaN(lon)) return;
            var m = L.marker([lat, lon]).addTo(adminMap)
                .bindPopup('<strong>' + (r.name||'Rescuer') + '</strong><br>Status: ' +
                    (r.availability_status||'unknown') +
                    '<br><small>' + lat.toFixed(5) + ', ' + lon.toFixed(5) + '</small>');
            adminMarkers.push(m);
            bounds.push([lat, lon]);
        });
        if (bounds.length > 0) {
            adminMap.fitBounds(bounds, { padding: [30, 30], maxZoom: 14 });
        }
        adminMap.invalidateSize();
    }

    function renderNotifications(data) {
        if (!data || !data.ok) return;
        // Badge
        if (data.unread_count > 0) {
            badge.textContent = data.unread_count;
            badge.style.display = 'inline';
        } else {
            badge.style.display = 'none';
        }
        // List
        if (!data.notifications || data.notifications.length === 0) {
            list.innerHTML = '<p style="color:#94a3b8;text-align:center;padding:2rem;">No notifications yet.</p>';
        } else {
            list.innerHTML = data.notifications.map(function(n) {
                var unread = !n.is_read || n.is_read === '0' || n.is_read === 0;
                var cat = n.category || 'system';
                var icon = cat === 'progress_update' ? 'fa-pen-to-square' :
                           cat === 'status_change' ? 'fa-flag' :
                           cat === 'assignment' ? 'fa-user-check' :
                           cat === 'location_update' ? 'fa-location-dot' : 'fa-bell';
                var color = cat === 'status_change' ? '#4f46e5' :
                            cat === 'progress_update' ? '#059669' : '#6366f1';
                return '<div style="padding:0.75rem 1rem;border-bottom:1px solid #f1f5f9;' + (unread ? 'background:#eef2ff;border-left:3px solid #4f46e5;' : '') + '">' +
                    '<p style="margin:0;font-size:0.88rem;"><i class="fa-solid ' + icon + '" style="color:' + color + ';margin-right:0.4rem;"></i>' +
                    (n.message || '').replace(/</g,'&lt;') + '</p>' +
                    '<small style="color:#94a3b8;font-size:0.72rem;">' + (n.created_at || '') +
                    (n.case_id ? ' · Case #' + n.case_id : '') + '</small></div>';
            }).join('');
        }
        // Update map markers
        initMap();
        updateMapMarkers(data.rescuer_locations || []);
    }

    function fetchNotifications(markRead) {
        var url = 'backend/api/admin_notifications.php?rescuer_locations=1';
        if (markRead) url += '&mark_read=1';
        fetch(url, { credentials: 'same-origin' })
            .then(function(r){ return r.json(); })
            .then(renderNotifications)
            .catch(function(){ list.innerHTML = '<p style="color:#dc2626;text-align:center;padding:1rem;">Could not load notifications. Run database/migrate_admin_notifications.sql</p>'; });
    }

    if (markBtn) markBtn.addEventListener('click', function(){ fetchNotifications(true); });
    if (refreshBtn) refreshBtn.addEventListener('click', function(){ fetchNotifications(false); });

    // Initial load + poll every 10 seconds
    fetchNotifications(false);
    setInterval(function(){ fetchNotifications(false); }, 10000);

    // Fix map tiles when switching to notifications tab
    var origActivate = window.activateAdminTab;
    window.activateAdminTab = function(tabName, linkEl) {
        if (origActivate) origActivate(tabName, linkEl);
        if (tabName === 'notifications') {
            setTimeout(function(){
                initMap();
                if (adminMap) {
                    adminMap.invalidateSize();
                }
            }, 300);
        }
    };
})();
</script>

<?php if ($msg || $err): ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    Swal.fire({
        icon: '<?php echo $err ? 'error' : 'success'; ?>',
        title: '<?php echo $err ? 'Error' : 'Done'; ?>',
        text: '<?php echo addslashes($err ?: $msg); ?>',
        confirmButtonColor: '#4F46E5'
    });
});
</script>
<?php endif; ?>

<?php require_once 'includes/footer.php'; ?>
