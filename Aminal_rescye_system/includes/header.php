<?php
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
if (!isset($body_class)) {
    $body_class = '';
}
if (!isset($pdo)) {
    $dbCfg = __DIR__ . '/../backend/db_config.php';
    if (is_file($dbCfg)) {
        require_once $dbCfg;
    }
}
if (!function_exists('get_site_setting')) {
    $helper = __DIR__ . '/../backend/site_settings_helper.php';
    if (is_file($helper)) {
        require_once $helper;
    }
}
$announcement_banner = '';
if (isset($pdo)) {
    try {
        $announcement_banner = trim(get_site_setting($pdo, 'announcement', ''));
    } catch (Throwable $e) {
        $announcement_banner = '';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>RescueNet - Smart Animal Rescue System</title>
    <meta name="description" content="RescueNet uses AI-powered dispatch and live geolocation to connect reporters with the nearest rescuers. Report injured animals and save lives.">
    <!-- Google Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&family=Outfit:wght@500;600;700;800&display=swap" rel="stylesheet">
    <!-- FontAwesome -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Leaflet.js -->
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" crossorigin="">
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js" crossorigin=""></script>
    <link rel="stylesheet" href="assets/css/style.css">
    <?php if (isset($extra_styles) && is_array($extra_styles) && $extra_styles !== []): ?>
        <?php foreach ($extra_styles as $_css_href): ?>
            <link rel="stylesheet" href="<?php echo htmlspecialchars((string) $_css_href, ENT_QUOTES, 'UTF-8'); ?>">
        <?php endforeach; ?>
    <?php endif; ?>
</head>
<body class="<?php echo htmlspecialchars($body_class); ?>">
    <header class="site-header" id="site-header">
        <div class="nav-accent-line" aria-hidden="true"></div>
        <nav class="navbar" id="main-navbar" aria-label="Primary">
            <a href="index.php" class="nav-brand">
                <span class="nav-brand__mark" aria-hidden="true"><i class="fa-solid fa-paw"></i></span>
                <span class="nav-brand__text">RescueNet</span>
            </a>
            <button type="button" class="nav-toggle" aria-expanded="false" aria-controls="primary-menu" id="nav-toggle" aria-label="Open navigation menu">
                <span class="nav-toggle__bar" aria-hidden="true"></span>
                <span class="nav-toggle__bar" aria-hidden="true"></span>
                <span class="nav-toggle__bar" aria-hidden="true"></span>
            </button>
            <div class="nav-shell" id="primary-menu">
                <ul class="nav-links">
                    <li><a href="index.php">Home</a></li>
                    <li><a href="about.php">About</a></li>
                    <li><a href="contact.php">Contact</a></li>
                </ul>
                <div class="nav-actions">
                    <?php if (isset($_SESSION['user_id'])): ?>
                        <?php if ($_SESSION['role'] == 'admin'): ?>
                            <a href="admin_dashboard.php" class="nav-actions__link"><i class="fa-solid fa-gauge-high"></i> Dashboard</a>
                        <?php elseif ($_SESSION['role'] == 'rescuer'): ?>
                            <a href="rescuer_dashboard.php" class="nav-actions__link"><i class="fa-solid fa-truck-medical"></i> Dashboard</a>
                        <?php else: ?>
                            <a href="user_dashboard.php" class="nav-actions__link"><i class="fa-solid fa-house-chimney"></i> Dashboard</a>
                        <?php endif; ?>
                        <a href="logout.php" class="btn btn-nav btn-nav--outline">Logout</a>
                    <?php else: ?>
                        <a href="login.php" class="btn btn-nav btn-nav--primary">Login</a>
                        <a href="register.php" class="btn btn-nav btn-nav--accent">Register</a>
                    <?php endif; ?>
                </div>
            </div>
        </nav>
        <?php if ($announcement_banner !== ''): ?>
            <div class="site-announcement" role="status" style="background: linear-gradient(90deg, #4338ca, #6366f1); color: #fff; text-align: center; padding: 0.5rem 1rem; font-size: 0.875rem;">
                <?php echo htmlspecialchars($announcement_banner); ?>
            </div>
        <?php endif; ?>
    </header>
