<?php
ob_start();
require_once __DIR__ . '/includes/config.php';

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    case 'login':
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if (empty($username) || empty($password))
            jsonResponse(['success' => false, 'message' => 'Username and password are required.'], 400);

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id, username, full_name, email, role, password, is_active, avatar FROM users WHERE username = ? OR email = ? LIMIT 1");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, $user['password']))
            jsonResponse(['success' => false, 'message' => 'Invalid username or password.'], 401);
        if (!$user['is_active'])
            jsonResponse(['success' => false, 'message' => 'Your account has been deactivated.'], 403);

        $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
        session_regenerate_id(true);
        $_SESSION['user_id']   = $user['id'];
        $_SESSION['username']  = $user['username'];
        $_SESSION['full_name'] = $user['full_name'];
        $_SESSION['role']      = $user['role'];

        jsonResponse([
            'success'  => true,
            'message'  => 'Login successful',
            'user'     => ['id' => $user['id'], 'username' => $user['username'], 'full_name' => $user['full_name'], 'role' => $user['role'], 'email' => $user['email'], 'avatar' => avatarUrl($user['avatar'])],
            'redirect' => BASE_URL . 'pages/dashboard.html'
        ]);

    case 'logout':
        session_destroy();
        jsonResponse(['success' => true, 'redirect' => BASE_URL . 'index.html']);

    case 'check':
        jsonResponse(isLoggedIn() ? ['logged_in' => true, 'user' => currentUser()] : ['logged_in' => false]);

    case 'signup':
        $full_name        = trim($_POST['full_name'] ?? '');
        $email            = trim($_POST['email'] ?? '');
        $password         = $_POST['password'] ?? '';
        $password_confirm = $_POST['password_confirm'] ?? '';
        $role             = in_array($_POST['role'] ?? '', ['admin','trader','supplier']) ? $_POST['role'] : 'trader';

        if (!$full_name || !$email || !$password)
            jsonResponse(['success' => false, 'message' => 'All fields are required.'], 400);
        if (!preg_match('/^[A-Za-z][A-Za-z\s.\'-]*$/', $full_name))
            jsonResponse(['success' => false, 'message' => 'Full name can only contain letters, spaces, periods, apostrophes, or hyphens.'], 400);
        if (!preg_match('/^(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/', $password))
            jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters long and include at least one uppercase letter and one special character.'], 400);
        if ($password !== $password_confirm)
            jsonResponse(['success' => false, 'message' => 'Passwords do not match.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);

        $username = explode('@', $email)[0] . '_' . random_int(1000, 9999);
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        if ($stmt->fetch())
            jsonResponse(['success' => false, 'message' => 'Email already registered. Please sign in.'], 409);

        $hash = password_hash($password, PASSWORD_DEFAULT);
        try {
            $pdo->prepare("INSERT INTO users (username, email, password, full_name, role, is_active) VALUES (?,?,?,?,?,1)")
                ->execute([$username, $email, $hash, $full_name, $role]);
            $userId = $pdo->lastInsertId();
            // No session — user must log in manually
            $emailSent = sendEmail($email, 'Welcome to SugarCast', '', getWelcomeEmailTemplate($full_name, $username));

            // Notify admins — both by email and in-app — so account
            // creation is actually visible to someone, not just a log line.
            sendEmail(ADMIN_EMAIL, 'New User Registration — SugarCast', '',
                getAdminNewUserEmailTemplate($full_name, $username, $email, 'trader'));
            notifyAdmins('New Trader Registered', "{$full_name} ({$email}) just created an account.", 'info');

            // Also notify the newly created account owner so the action is
            // visible in their own notification panel immediately.
            createNotification((int)$userId, 'Account created', 'Your SugarCast account has been created successfully. You can sign in and start using the platform.', 'success');

            error_log("New account: $username ($email)");
            jsonResponse([
                'success'    => true,
                'message'    => 'Account created successfully. Please sign in.',
                'email_sent' => $emailSent,
                'user'       => ['id' => $userId, 'username' => $username, 'full_name' => $full_name, 'role' => $role, 'email' => $email, 'avatar' => null],
            ], 201);
        } catch (PDOException $e) {
            error_log("Signup error: " . $e->getMessage());
            jsonResponse(['success' => false, 'message' => 'Error creating account. Please try again.'], 500);
        }

    case 'forgot_password':
        $email = trim($_POST['email'] ?? '');
        if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'message' => 'A valid email address is required.'], 400);

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE email = ? LIMIT 1");
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        // Always return a generic success message, whether or not the
        // email exists — avoids leaking which addresses are registered.
        if ($user) {
            $token   = generateSecureToken(32);
            $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
            $pdo->prepare("UPDATE users SET reset_token = ?, reset_expires = ? WHERE id = ?")
                ->execute([$token, $expires, $user['id']]);

            $resetUrl = BASE_URL . 'reset-password.html?token=' . urlencode($token);
            sendEmail(
                $user['email'],
                'Reset Your Password — SugarCast',
                '',
                getPasswordResetRequestEmailTemplate($user['full_name'], $resetUrl)
            );
            error_log("Password reset requested for: {$user['email']}");
        }

        jsonResponse(['success' => true, 'message' => 'If that email is registered, a reset link has been sent.']);

    case 'reset_password':
        $token    = $_POST['token']            ?? '';
        $password = $_POST['password']         ?? '';
        $confirm  = $_POST['password_confirm'] ?? '';

        if (!$token)
            jsonResponse(['success' => false, 'message' => 'Reset token is required.'], 400);
        if (!$password || !$confirm)
            jsonResponse(['success' => false, 'message' => 'Please enter and confirm your new password.'], 400);
        if (!preg_match('/^(?=.*[A-Z])(?=.*[^A-Za-z0-9]).{8,}$/', $password))
            jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters long and include at least one uppercase letter and one special character.'], 400);
        if ($password !== $confirm)
            jsonResponse(['success' => false, 'message' => 'Passwords do not match.'], 400);

        $pdo  = getDB();
        $stmt = $pdo->prepare("SELECT id FROM users WHERE reset_token = ? AND reset_expires > NOW() LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user)
            jsonResponse(['success' => false, 'message' => 'This reset link is invalid or has expired. Please request a new one.'], 400);

        $pdo->prepare("UPDATE users SET password = ?, reset_token = NULL, reset_expires = NULL WHERE id = ?")
            ->execute([password_hash($password, PASSWORD_DEFAULT), $user['id']]);

        createNotification((int)$user['id'], 'Password updated', 'Your password was changed successfully. If this was not you, contact support immediately.', 'success');

        jsonResponse(['success' => true, 'message' => 'Password reset successfully. You can now sign in.']);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
