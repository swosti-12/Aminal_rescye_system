<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_config.php';

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_role($role) {
    if (!is_logged_in() || $_SESSION['role'] !== $role) {
        header('Location: login.php');
        exit;
    }
}
?>
