<?php
require_once 'backend/auth.php';

if (is_logged_in()) {
    header('Location: index.php');
    exit;
}

function hasColumn(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
    $stmt->execute([$column]);
    return (bool) $stmt->fetch();
}

function ensureUsersSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS users (
            id INT AUTO_INCREMENT PRIMARY KEY,
            full_name VARCHAR(120) NOT NULL,
            username VARCHAR(120) NOT NULL UNIQUE,
            email VARCHAR(190) NULL UNIQUE,
            name VARCHAR(120) NULL,
            password VARCHAR(255) NOT NULL,
            role ENUM('user','rescuer','admin') NOT NULL DEFAULT 'user',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );

    if (!hasColumn($pdo, 'users', 'full_name')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN full_name VARCHAR(120) NULL");
    }
    if (!hasColumn($pdo, 'users', 'username')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN username VARCHAR(120) NULL");
    }
    if (!hasColumn($pdo, 'users', 'email')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN email VARCHAR(190) NULL");
    }
    if (!hasColumn($pdo, 'users', 'name')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN name VARCHAR(120) NULL");
    }
    if (!hasColumn($pdo, 'users', 'role')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN role VARCHAR(20) NOT NULL DEFAULT 'user'");
    }
}

ensureUsersSchema($pdo);

$errors = [];
$fullName = '';
$email = '';
$selectedRole = 'user';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $fullName = trim((string) ($_POST['full_name'] ?? ''));
    $emailRaw = trim((string) ($_POST['email'] ?? ''));
    $email = strtolower($emailRaw);
    $password = (string) ($_POST['password'] ?? '');
    $confirmPassword = (string) ($_POST['confirm_password'] ?? '');
    $selectedRole = (string) ($_POST['role'] ?? 'user');
    $username = $email;
    $allowedRoles = ['user', 'rescuer'];

    if ($fullName === '' || mb_strlen($fullName) < 2) {
        $errors[] = 'Full name must be at least 2 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Please enter a valid email address.';
    }
    if (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters.';
    }
    if ($password !== $confirmPassword) {
        $errors[] = 'Confirm password does not match password.';
    }
    if (!in_array($selectedRole, $allowedRoles, true)) {
        $errors[] = 'Please select a valid role.';
        $selectedRole = 'user';
    }

    if ($errors === []) {
        $check = $pdo->prepare('SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1');
        $check->execute([
            ':username' => $username,
            ':email' => $email,
        ]);

        if ($check->fetch()) {
            $errors[] = 'An account with this email/username already exists.';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $insert = $pdo->prepare(
                'INSERT INTO users (full_name, name, username, email, password, role) VALUES (:full_name, :name, :username, :email, :password, :role)'
            );
            $ok = $insert->execute([
                ':full_name' => $fullName,
                ':name' => $fullName,
                ':username' => $username,
                ':email' => $email,
                ':password' => $hash,
                ':role' => $selectedRole,
            ]);

            if ($ok) {
                header('Location: login.php?registered=1');
                exit;
            }
            $errors[] = 'Registration failed. Please try again.';
        }
    }
}
?>
<?php
$body_class = 'register-page';
require_once 'includes/header.php';
?>
<style>
        :root {
            --accent: #4f46e5;
            --accent-2: #06b6d4;
            --danger: #dc2626;
            --ok: #047857;
            --card: rgba(255, 255, 255, 0.9);
            --text: #111827;
            --muted: #6b7280;
            --border: #e5e7eb;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: Inter, Segoe UI, Arial, sans-serif;
            color: var(--text);
            background: linear-gradient(-45deg, #1d4ed8, #4f46e5, #0ea5e9, #22c55e);
            background-size: 300% 300%;
            animation: gradientShift 14s ease infinite;
            min-height: 100vh;
        }
        @keyframes gradientShift {
            0% { background-position: 0% 50%; }
            50% { background-position: 100% 50%; }
            100% { background-position: 0% 50%; }
        }
        .register-shell {
            display: grid;
            place-items: center;
            min-height: calc(100vh - var(--nav-height, 78px) - 130px);
            padding: 24px;
        }
        .card {
            width: min(100%, 520px);
            background: var(--card);
            border: 1px solid rgba(255,255,255,0.4);
            border-radius: 18px;
            box-shadow: 0 20px 55px rgba(0,0,0,0.2);
            padding: 28px;
        }
        h1 { margin: 0 0 6px; font-size: 1.8rem; }
        .lead { margin: 0 0 20px; color: var(--muted); }
        .field { margin-bottom: 14px; }
        label { display: block; margin-bottom: 6px; font-weight: 600; }
        input[type="text"], input[type="email"], input[type="password"], select {
            width: 100%;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 11px 12px;
            outline: none;
            font-size: 0.96rem;
            transition: border-color .2s ease, box-shadow .2s ease;
        }
        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 3px rgba(79, 70, 229, 0.15);
        }
        .pass-wrap { position: relative; }
        .toggle-btn {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            border: none;
            background: #eef2ff;
            color: #3730a3;
            border-radius: 8px;
            padding: 6px 9px;
            cursor: pointer;
            font-size: 0.82rem;
        }
        .error-text {
            min-height: 18px;
            margin-top: 4px;
            color: var(--danger);
            font-size: 0.85rem;
        }
        .server-errors {
            margin-bottom: 14px;
            border: 1px solid #fecaca;
            background: #fef2f2;
            color: #991b1b;
            border-radius: 10px;
            padding: 10px 12px;
        }
        .server-errors ul { margin: 0; padding-left: 18px; }
        .btn-submit {
            width: 100%;
            margin-top: 6px;
            border: none;
            border-radius: 12px;
            padding: 12px 14px;
            background: linear-gradient(135deg, var(--accent), var(--accent-2));
            color: #fff;
            font-weight: 700;
            font-size: 0.98rem;
            cursor: pointer;
            transition: transform .18s ease, box-shadow .18s ease, filter .18s ease;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 24px rgba(79, 70, 229, 0.3);
            filter: brightness(1.05);
        }
        .login-link { margin-top: 12px; text-align: center; color: var(--muted); }
        .login-link a { color: var(--accent); text-decoration: none; font-weight: 600; }
        @media (max-width: 620px) {
            .card { padding: 20px; border-radius: 14px; }
            h1 { font-size: 1.55rem; }
        }
</style>

    <main class="register-shell">
        <section class="card">
            <h1>Create Your Account</h1>
            <p class="lead">Join and help report animals in need safely and quickly.</p>

            <?php if ($errors !== []): ?>
                <div class="server-errors">
                    <ul>
                        <?php foreach ($errors as $err): ?>
                            <li><?php echo htmlspecialchars($err, ENT_QUOTES, 'UTF-8'); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>

            <form method="POST" id="registerForm" novalidate>
                <div class="field">
                    <label for="full_name">Full Name</label>
                    <input type="text" id="full_name" name="full_name" value="<?php echo htmlspecialchars($fullName, ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="error-text" id="nameError"></div>
                </div>

                <div class="field">
                    <label for="email">Email</label>
                    <input type="email" id="email" name="email" value="<?php echo htmlspecialchars($email, ENT_QUOTES, 'UTF-8'); ?>" required>
                    <div class="error-text" id="emailError"></div>
                </div>

                <div class="field">
                    <label for="password">Password</label>
                    <div class="pass-wrap">
                        <input type="password" id="password" name="password" required>
                        <button type="button" class="toggle-btn" data-target="password">Show</button>
                    </div>
                    <div class="error-text" id="passwordError"></div>
                </div>

                <div class="field">
                    <label for="confirm_password">Confirm Password</label>
                    <div class="pass-wrap">
                        <input type="password" id="confirm_password" name="confirm_password" required>
                        <button type="button" class="toggle-btn" data-target="confirm_password">Show</button>
                    </div>
                    <div class="error-text" id="confirmError"></div>
                </div>

                <div class="field">
                    <label for="role">Register As</label>
                    <select id="role" name="role" required>
                        <option value="user" <?php echo $selectedRole === 'user' ? 'selected' : ''; ?>>User</option>
                        <option value="rescuer" <?php echo $selectedRole === 'rescuer' ? 'selected' : ''; ?>>Rescuer</option>
                    </select>
                </div>

                <button type="submit" class="btn-submit">Register Now</button>
            </form>

            <p class="login-link">Already registered? <a href="login.php">Go to login</a></p>
        </section>
    </main>

<script>
    (function () {
            var form = document.getElementById('registerForm');
            var fullName = document.getElementById('full_name');
            var email = document.getElementById('email');
            var password = document.getElementById('password');
            var confirmPassword = document.getElementById('confirm_password');
            var nameError = document.getElementById('nameError');
            var emailError = document.getElementById('emailError');
            var passwordError = document.getElementById('passwordError');
            var confirmError = document.getElementById('confirmError');

            function setError(el, msg) { el.textContent = msg; }
            function isEmail(value) { return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value); }

            function validateName() {
                var v = fullName.value.trim();
                if (v.length < 2) { setError(nameError, 'Full name must be at least 2 characters.'); return false; }
                setError(nameError, '');
                return true;
            }

            function validateEmail() {
                var v = email.value.trim();
                if (!isEmail(v)) { setError(emailError, 'Please enter a valid email address.'); return false; }
                setError(emailError, '');
                return true;
            }

            function validatePassword() {
                var v = password.value;
                if (v.length < 6) { setError(passwordError, 'Password must be at least 6 characters.'); return false; }
                setError(passwordError, '');
                return true;
            }

            function validateConfirm() {
                if (confirmPassword.value !== password.value) {
                    setError(confirmError, 'Confirm password must match password.');
                    return false;
                }
                setError(confirmError, '');
                return true;
            }

            fullName.addEventListener('input', validateName);
            email.addEventListener('input', validateEmail);
            password.addEventListener('input', function () {
                validatePassword();
                validateConfirm();
            });
            confirmPassword.addEventListener('input', validateConfirm);

            form.addEventListener('submit', function (e) {
                var ok = validateName() & validateEmail() & validatePassword() & validateConfirm();
                if (!ok) e.preventDefault();
            });

            document.querySelectorAll('.toggle-btn').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var targetId = btn.getAttribute('data-target');
                    var input = document.getElementById(targetId);
                    var hidden = input.type === 'password';
                    input.type = hidden ? 'text' : 'password';
                    btn.textContent = hidden ? 'Hide' : 'Show';
                });
            });
    })();
</script>
<?php require_once 'includes/footer.php'; ?>