<?php
ob_start();
require_once __DIR__ . '/includes/config.php';
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

// ── Self-service (any logged-in user) ────────────────────────
if ($action === 'update_profile') {
    $full_name = trim($_POST['full_name'] ?? '');
    $email     = trim($_POST['email']     ?? '');
    if (!$full_name || !$email)
        jsonResponse(['success' => false, 'message' => 'Full name and email are required.'], 400);
    $pdo = getDB();
    // Check email not taken by someone else
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->execute([$email, $_SESSION['user_id']]);
    if ($chk->fetch())
        jsonResponse(['success' => false, 'message' => 'Email already in use by another account.'], 409);
    $pdo->prepare("UPDATE users SET full_name=?, email=? WHERE id=?")
        ->execute([$full_name, $email, $_SESSION['user_id']]);
    $_SESSION['full_name'] = $full_name;
    jsonResponse(['success' => true, 'message' => 'Profile updated.', 'user' => ['full_name' => $full_name, 'email' => $email]]);
}

if ($action === 'change_password') {
    $current = $_POST['current_password'] ?? '';
    $new     = $_POST['new_password']     ?? '';
    $confirm = $_POST['confirm_password'] ?? '';
    if (!$current || !$new || !$confirm)
        jsonResponse(['success' => false, 'message' => 'All password fields are required.'], 400);
    if ($new !== $confirm)
        jsonResponse(['success' => false, 'message' => 'New passwords do not match.'], 400);
    if (strlen($new) < 8)
        jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters.'], 400);
    $pdo  = getDB();
    $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($current, $user['password']))
        jsonResponse(['success' => false, 'message' => 'Current password is incorrect.'], 401);
    $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
        ->execute([password_hash($new, PASSWORD_DEFAULT), $_SESSION['user_id']]);
    jsonResponse(['success' => true, 'message' => 'Password changed successfully.']);
}

if ($action === 'upload_avatar') {
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK)
        jsonResponse(['success' => false, 'message' => 'No image was uploaded, or the upload failed.'], 400);

    $file = $_FILES['avatar'];

    if ($file['size'] > 4 * 1024 * 1024)
        jsonResponse(['success' => false, 'message' => 'Image must be smaller than 4MB.'], 400);

    $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
    $mime = mime_content_type($file['tmp_name']);
    if (!isset($allowedMimes[$mime]))
        jsonResponse(['success' => false, 'message' => 'Only JPG, PNG, WEBP, or GIF images are allowed.'], 400);

    $pdo    = getDB();
    $userId = $_SESSION['user_id'];

    $relDir = 'assets/uploads/avatars/';
    $absDir = PROJECT_ROOT . '/frontend/' . $relDir;
    if (!is_dir($absDir) && !mkdir($absDir, 0755, true) && !is_dir($absDir))
        jsonResponse(['success' => false, 'message' => 'Could not prepare the upload directory on the server.'], 500);

    $filename = 'user_' . $userId . '_' . time() . '.' . $allowedMimes[$mime];
    $relPath  = $relDir . $filename;

    if (!move_uploaded_file($file['tmp_name'], $absDir . $filename))
        jsonResponse(['success' => false, 'message' => 'Could not save the uploaded image.'], 500);

    // Track the previous file so it can be cleaned up after the DB update succeeds.
    $prev = $pdo->prepare("SELECT avatar FROM users WHERE id = ?");
    $prev->execute([$userId]);
    $oldAvatar = $prev->fetchColumn();

    $pdo->prepare("UPDATE users SET avatar = ? WHERE id = ?")->execute([$relPath, $userId]);

    if ($oldAvatar && $oldAvatar !== $relPath) {
        $oldAbs = PROJECT_ROOT . '/frontend/' . ltrim($oldAvatar, '/');
        if (is_file($oldAbs)) @unlink($oldAbs);
    }

    jsonResponse(['success' => true, 'message' => 'Profile picture updated.', 'avatar' => avatarUrl($relPath)]);
}

// ── Admin-only beyond this point ─────────────────────────────
if ($_SESSION['role'] !== 'admin')
    jsonResponse(['success' => false, 'message' => 'Admin access required.'], 403);

$pdo = getDB();

switch ($action) {

    // ── LIST ─────────────────────────────────────────────────
    case 'list':
        $stmt = $pdo->query("
            SELECT id, username, full_name, email, role, is_active, last_login, created_at
            FROM users
            ORDER BY created_at DESC
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);

    // ── ADD ──────────────────────────────────────────────────
    case 'add':
        $username  = trim($_POST['username']  ?? '');
        $email     = trim($_POST['email']     ?? '');
        $password  = $_POST['password']       ?? '';
        $full_name = trim($_POST['full_name'] ?? '');
        $role      = in_array($_POST['role'] ?? '', ['admin','trader','supplier'])
                     ? $_POST['role'] : 'trader';

        if (!$username || !$email || !$password || !$full_name)
            jsonResponse(['success' => false, 'message' => 'All fields are required.'], 400);
        if (strlen($password) < 8)
            jsonResponse(['success' => false, 'message' => 'Password must be at least 8 characters.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);

        try {
            $stmt = $pdo->prepare("
                INSERT INTO users (username, email, password, full_name, role, is_active)
                VALUES (?, ?, ?, ?, ?, 1)
            ");
            $stmt->execute([$username, $email, password_hash($password, PASSWORD_DEFAULT), $full_name, $role]);
            $newId = $pdo->lastInsertId();

            // Return the full new user row so frontend can add it without a re-fetch
            $row = $pdo->prepare("SELECT id, username, full_name, email, role, is_active, last_login, created_at FROM users WHERE id = ?");
            $row->execute([$newId]);
            $newUser = $row->fetch();

            jsonResponse(['success' => true, 'message' => 'User created successfully.', 'id' => $newId, 'user' => $newUser]);
        } catch (PDOException $e) {
            if ($e->getCode() === '23000') {
                jsonResponse(['success' => false, 'message' => 'Username or email already exists.'], 409);
            }
            throw $e;
        }

    // ── UPDATE ───────────────────────────────────────────────
    case 'update':
        $id        = (int)($_POST['id']        ?? 0);
        $email     = trim($_POST['email']      ?? '');
        $full_name = trim($_POST['full_name']  ?? '');
        $role      = in_array($_POST['role'] ?? '', ['admin','trader','supplier'])
                     ? $_POST['role'] : 'trader';
        $is_active = (int)($_POST['is_active'] ?? 1);

        if (!$id)    jsonResponse(['success' => false, 'message' => 'User ID required.'], 400);
        if (!$email) jsonResponse(['success' => false, 'message' => 'Email is required.'], 400);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL))
            jsonResponse(['success' => false, 'message' => 'Invalid email address.'], 400);

        // Check email uniqueness
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $id]);
        if ($chk->fetch())
            jsonResponse(['success' => false, 'message' => 'Email already in use by another account.'], 409);

        $pdo->prepare("UPDATE users SET email=?, full_name=?, role=?, is_active=? WHERE id=?")
            ->execute([$email, $full_name, $role, $is_active, $id]);

        // Return updated row so frontend can sync without re-fetch
        $row = $pdo->prepare("SELECT id, username, full_name, email, role, is_active, last_login, created_at FROM users WHERE id = ?");
        $row->execute([$id]);
        jsonResponse(['success' => true, 'message' => 'User updated.', 'user' => $row->fetch()]);

    // ── DELETE ───────────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id)
            jsonResponse(['success' => false, 'message' => 'User ID required.'], 400);
        if ($id === (int)$_SESSION['user_id'])
            jsonResponse(['success' => false, 'message' => 'You cannot delete your own account.'], 400);

        // Verify user exists before deleting
        $chk = $pdo->prepare("SELECT id, full_name FROM users WHERE id = ?");
        $chk->execute([$id]);
        $target = $chk->fetch();
        if (!$target)
            jsonResponse(['success' => false, 'message' => 'User not found. They may have already been deleted.'], 404);

        $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$id]);

        // Confirm deletion
        $verify = $pdo->prepare("SELECT id FROM users WHERE id = ?");
        $verify->execute([$id]);
        if ($verify->fetch())
            jsonResponse(['success' => false, 'message' => 'Delete failed. Please try again.'], 500);

        jsonResponse(['success' => true, 'message' => "User \"{$target['full_name']}\" deleted.", 'deleted_id' => $id]);

    // ── TOGGLE STATUS ─────────────────────────────────────────
    case 'toggle_status':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'User ID required.'], 400);

        $pdo->prepare("UPDATE users SET is_active = NOT is_active WHERE id = ?")->execute([$id]);

        // Return the new status
        $row = $pdo->prepare("SELECT id, is_active, full_name FROM users WHERE id = ?");
        $row->execute([$id]);
        $updated = $row->fetch();
        jsonResponse([
            'success'    => true,
            'message'    => "User \"{$updated['full_name']}\" " . ($updated['is_active'] ? 'activated' : 'deactivated') . '.',
            'is_active'  => (bool)$updated['is_active'],
            'user_id'    => $id,
        ]);

    // ── RESET PASSWORD ────────────────────────────────────────
    case 'reset_password':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'User ID required.'], 400);
        $stmt = $pdo->prepare("SELECT id, full_name, email, username FROM users WHERE id = ?");
        $stmt->execute([$id]);
        $user = $stmt->fetch();
        if (!$user) jsonResponse(['success' => false, 'message' => 'User not found.'], 404);

        $temp = generateSecurePassword(12);
        $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")
            ->execute([password_hash($temp, PASSWORD_DEFAULT), $id]);

        $emailSent = sendEmail(
            $user['email'],
            'Password Reset — SugarCast',
            '',
            getPasswordResetEmailTemplate($user['full_name'], $user['username'], $temp)
        );
        error_log("Password reset for user: {$user['username']} by admin: {$_SESSION['username']}");

        jsonResponse([
            'success'    => true,
            'message'    => "Password reset for \"{$user['full_name']}\". Email sent to {$user['email']}.",
            'email_sent' => $emailSent,
            'user'       => $user['full_name'],
            'email'      => $user['email'],
        ]);

    default:
        jsonResponse(['error' => 'Unknown action.'], 400);
}
