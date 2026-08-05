<?php
ob_start();
// ============================================================
// api/notifications.php — Notifications CRUD
// ============================================================
require_once __DIR__ . '/includes/config.php';
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';

switch ($action) {

    case 'list':
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT * FROM notifications
            WHERE user_id = ?
            ORDER BY created_at DESC
            LIMIT 20
        ");
        $stmt->execute([$_SESSION['user_id']]);
        $notifs = $stmt->fetchAll();
        $unread = array_filter($notifs, fn($n) => !$n['is_read']);
        jsonResponse(['success' => true, 'data' => $notifs, 'unread_count' => count($unread)]);

    case 'mark_read':
        $pdo = getDB();
        // Scoped to this user's own targeted notifications only. Broadcast
        // rows (user_id IS NULL) intentionally aren't touched here — a
        // single is_read flag on a shared row would otherwise mark it read
        // for every user the moment any one of them opened the panel.
        $pdo->prepare("UPDATE notifications SET is_read = 1 WHERE user_id = ?")
            ->execute([$_SESSION['user_id']]);
        jsonResponse(['success' => true]);

    case 'create':
        $pdo = getDB();
        $pdo->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)")
            ->execute([
                $_POST['user_id'] ?? null,
                $_POST['title']   ?? '',
                $_POST['message'] ?? '',
                $_POST['type']    ?? 'info',
            ]);
        jsonResponse(['success' => true]);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
?>
