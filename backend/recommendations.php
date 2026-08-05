<?php
ob_start();
// ============================================================
// api/recommendations.php — List & acknowledge stock recommendations
// ============================================================
require_once __DIR__ . '/includes/config.php';
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? 'list';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

switch ($action) {

    // ── LIST (joined with the prediction that produced it) ──
    case 'list':
        $pdo   = getDB();
        $limit = (int)($_GET['limit'] ?? 50);
        $stmt  = $pdo->prepare("
            SELECT
                r.id, r.prediction_id, r.recommended_stock, r.risk_level,
                r.shortage_alert, r.overstock_warning, r.current_stock,
                r.action_required, r.valid_from, r.valid_until,
                r.is_acknowledged, r.acknowledged_at, r.created_at,
                p.predicted_demand AS estimated_demand, p.target_date,
                p.confidence_level, p.model_used,
                p.previous_sales, p.input_price, p.input_season, p.is_holiday
            FROM recommendations r
            JOIN predictions p ON p.id = r.prediction_id
            " . ($isAdmin ? '' : 'WHERE p.predicted_by = ? ') . "
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->execute($isAdmin ? [$limit] : [$userId, $limit]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);

    // ── ACKNOWLEDGE ───────────────────────────────────────────
    case 'acknowledge':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'Recommendation ID required.'], 400);

        $pdo = getDB();
        $chk = $pdo->prepare($isAdmin ? "SELECT r.id FROM recommendations r WHERE r.id = ?" : "SELECT r.id FROM recommendations r JOIN predictions p ON p.id = r.prediction_id WHERE r.id = ? AND p.predicted_by = ?");
        $chk->execute($isAdmin ? [$id] : [$id, $userId]);
        if (!$chk->fetch())
            jsonResponse(['success' => false, 'message' => 'Recommendation not found.'], 404);

        $pdo->prepare($isAdmin ? "UPDATE recommendations SET is_acknowledged = 1, acknowledged_at = NOW() WHERE id = ?" : "UPDATE recommendations r JOIN predictions p ON p.id = r.prediction_id SET r.is_acknowledged = 1, r.acknowledged_at = NOW() WHERE r.id = ? AND p.predicted_by = ?")
            ->execute($isAdmin ? [$id] : [$id, $userId]);

        jsonResponse(['success' => true, 'message' => 'Recommendation acknowledged.']);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
?>
