<?php
require_once 'backend/auth.php';

$loginIntent = isset($_GET['intent']) && $_GET['intent'] !== ''
    ? SessionManager::normalizeRole((string)$_GET['intent'])
    : '';

if ($loginIntent !== '' && SessionManager::isRoleLoggedIn($loginIntent)) {
    if ($loginIntent === 'admin') {
        header('Location: admin_dashboard.php');
    } elseif ($loginIntent === 'rescuer') {
        header('Location: rescuer_dashboard.php');
    } else {
        header('Location: user_dashboard.php');
    }
    exit;
}

$error = '';
$success = '';
$activeRoles = SessionManager::getActiveRoles();

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $success = 'Registration successful. You can now log in.';
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim((string) ($_POST['email'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password'])) {
        if (($user['account_status'] ?? 'active') === 'blocked') {
            $error = 'Your account has been suspended. Contact an administrator.';
        } else {
            $role = SessionManager::login($pdo, $user);

            if ($role === 'admin') {
                header('Location: admin_dashboard.php');
            } elseif ($role === 'rescuer') {
                header('Location: rescuer_dashboard.php');
            } else {
                header('Location: user_dashboard.php');
            }
            exit;
        }
    } else {
        $error = 'Invalid email or password.';
    }
}
?>
<?php require_once 'includes/header.php'; ?>

<div class="container" style="display: flex; justify-content: center; align-items: center; min-height: 75vh;">
    <div class="glass-panel" style="width: 100%; max-width: 450px; padding: 3rem;">
        <h2 class="text-center" style="margin-bottom: 2rem;">Welcome Back</h2>

        <?php if ($activeRoles !== []): ?>
            <div style="background: rgba(224, 231, 255, 0.95); color: #3730a3; padding: 0.85rem 1rem; border-radius: 8px; margin-bottom: 1.25rem; font-size: 0.85rem; line-height: 1.5;">
                <strong><i class="fa-solid fa-layer-group"></i> Demo mode:</strong>
                Other roles stay signed in when you log in here.
                Active:
                <?php
                $labels = array_map(static fn($r) => SessionManager::getSessionLabel($r), $activeRoles);
                echo htmlspecialchars(implode(', ', $labels));
                ?>.
                Open each dashboard in its own browser tab.
            </div>
        <?php endif; ?>

        <?php if($error): ?>
            <div style="background: rgba(254, 226, 226, 0.9); color: #991b1b; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; text-align: center;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>

        <?php if($success): ?>
            <div style="background: rgba(209, 250, 229, 0.9); color: #065f46; padding: 1rem; border-radius: 8px; margin-bottom: 1.5rem; text-align: center;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>

        <form method="POST">
            <div class="form-group">
                <label>Email Address</label>
                <input type="email" name="email" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Password</label>
                <input type="password" name="password" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary" style="width: 100%;">Login</button>
        </form>

        <p class="text-center mt-2">
            Don't have an account? <a href="register.php">Register Here</a>
        </p>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
