<?php

declare(strict_types=1);

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db_config.php';
require_once __DIR__ . '/backend/controllers/UserDashboardController.php';

require_login();
require_role('user');

$flash = ['message' => '', 'type' => ''];
$controller = new UserDashboardController($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $note = $controller->handleProfileUpdate((int) $_SESSION['user_id'], $_POST, $_FILES);
    if ($note !== '') {
        $flash['message'] = $note;
        $flash['type'] = strpos($note, 'Failed') !== false || strpos($note, 'Could not') !== false ? 'error' : 'success';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['report'])) {
    $r = $controller->handleReportSubmit((int) $_SESSION['user_id'], $_POST, $_FILES);
    $flash['message'] = $r['message'];
    $flash['type'] = $r['type'];
}

$data = $controller->getDashboardData((int) $_SESSION['user_id']);
extract($data, EXTR_SKIP);

$body_class = 'user-dashboard';
$extra_styles = ['assets/css/user-dashboard.css'];

require_once __DIR__ . '/includes/header.php';
require_once __DIR__ . '/views/user/dashboard.php';

$extra_scripts = ['assets/js/user-dashboard.js'];
require_once __DIR__ . '/includes/footer.php';
