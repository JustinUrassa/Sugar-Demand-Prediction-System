<?php
ob_start();
// ============================================================
// api/predict.php — Demand Analysis
// ============================================================
require_once __DIR__ . '/includes/config.php';
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? 'predict';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

switch ($action) {

    // ── PREDICT DEMAND ───────────────────────────────────────
    case 'predict':
        $targetDate    = $_POST['target_date']    ?? '';
        $prevSales     = (float)($_POST['prev_sales']   ?? 0);
        $price         = (float)($_POST['price']        ?? 0);
        $season        = $_POST['season']          ?? 'dry';
        $isHoliday     = (int)($_POST['is_holiday'] ?? 0);

        if (empty($targetDate) || $prevSales <= 0 || $price <= 0) {
            jsonResponse(['success' => false, 'message' => 'All fields are required and must be valid.'], 400);
        }

        $pdo = getDB();

        // ── Try the trained ML model first ───────────────────
        // Pull recent daily sales history (up to ~13 months back)
        // so the Python side can build lag/rolling features.
        $histStmt = $pdo->prepare("
            SELECT sale_date AS date, SUM(quantity_kg) AS qty, AVG(price_per_kg) AS price
            FROM sugar_sales
            WHERE sale_date < ? AND sale_date >= DATE_SUB(?, INTERVAL 400 DAY)" . ($isAdmin ? '' : " AND recorded_by = ?") . "
            GROUP BY sale_date
            ORDER BY sale_date ASC
        ");
        $histStmt->execute($isAdmin ? [$targetDate, $targetDate] : [$targetDate, $targetDate, $userId]);
        $history = $histStmt->fetchAll();

        $mlResult = runMlPrediction([
            'target_date' => $targetDate,
            'price'       => $price,
            'is_holiday'  => $isHoliday,
            'history'     => $history,
        ]);

        if (empty($mlResult['success'])) {
            jsonResponse([
                'success' => false,
                'message' => $mlResult['message'] ?? 'ML prediction failed.',
            ], 500);
        }

        $modelUsed       = 'ml_linear_regression';
        $predictedDemand = round(max((float)$mlResult['predicted_demand'], 100), 2);
        $confidence      = (int)$mlResult['confidence'];
        $margin          = $predictedDemand * (1 - ($confidence / 100)) * 1.5;
        $lower           = round(max($predictedDemand - $margin, 0), 2);
        $upper           = round($predictedDemand + $margin, 2);
        $modelMeta       = [
            'features_used' => $mlResult['features_used'] ?? null,
            'data_quality'  => $mlResult['data_quality'] ?? null,
        ];

        // Save prediction
        $stmt = $pdo->prepare("
            INSERT INTO predictions
                (prediction_date, target_date, previous_sales, input_price, input_season,
                 is_holiday, predicted_demand, confidence_level, model_used, lower_bound, upper_bound, predicted_by)
            VALUES (CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $targetDate, $prevSales, $price, $season, $isHoliday,
            $predictedDemand, $confidence, $modelUsed, $lower, $upper, $_SESSION['user_id']
        ]);
        $predId = $pdo->lastInsertId();

        // Auto-generate recommendation
        $recStock = round($predictedDemand * 1.15, 2); // 15% buffer
        $currentStock = (float)($_POST['current_stock'] ?? 0);
        $riskLevel = 'low';
        $shortage = 0; $overstock = 0; $action_msg = 'Stock levels are adequate.';

        if ($currentStock > 0) {
            $gap = $recStock - $currentStock;
            if ($gap > $predictedDemand * 0.20) {
                $riskLevel = 'critical'; $shortage = 1;
                $action_msg = "URGENT: Order " . number_format($gap, 0) . " kg immediately.";
            } elseif ($gap > 0) {
                $riskLevel = 'medium';
                $action_msg = "Consider ordering " . number_format($gap, 0) . " kg additional stock.";
            } elseif ($currentStock > $recStock * 1.30) {
                $riskLevel = 'low'; $overstock = 1;
                $action_msg = "Overstock warning: reduce next order.";
            }
        }

        $validFrom  = date('Y-m-d');
        $validUntil = $targetDate;
        $pdo->prepare("
            INSERT INTO recommendations
                (prediction_id, recommended_stock, risk_level, shortage_alert, overstock_warning,
                 current_stock, action_required, valid_from, valid_until)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([$predId, $recStock, $riskLevel, $shortage, $overstock, $currentStock ?: null, $action_msg, $validFrom, $validUntil]);

        // Notify — only for outcomes that actually need attention, so the
        // notification bell stays meaningful rather than firing on every
        // routine "stock levels are adequate" prediction.
        $targetLabel = date('d M Y', strtotime($targetDate));
        if ($riskLevel === 'critical') {
            createNotification($_SESSION['user_id'], 'Critical Stock Alert',
                "$action_msg (target date: $targetLabel)", 'error');
            notifyAdmins('Critical Stock Alert', "{$_SESSION['full_name']} has a critical stock shortfall for $targetLabel — $action_msg", 'error');
        } elseif ($riskLevel === 'medium') {
            createNotification($_SESSION['user_id'], 'Stock Recommendation',
                "$action_msg (target date: $targetLabel)", 'warning');
        } elseif ($overstock) {
            createNotification($_SESSION['user_id'], 'Overstock Warning',
                "$action_msg (target date: $targetLabel)", 'warning');
        }

        jsonResponse([
            'success'          => true,
            'prediction_id'    => $predId,
            'predicted_demand' => $predictedDemand,
            'confidence'       => $confidence,
            'lower_bound'      => $lower,
            'upper_bound'      => $upper,
            'model_used'       => $modelUsed,
            'model_meta'       => $modelMeta,
            'recommendation'   => [
                'recommended_stock' => $recStock,
                'risk_level'        => $riskLevel,
                'shortage_alert'    => (bool)$shortage,
                'overstock_warning' => (bool)$overstock,
                'action_required'   => $action_msg,
            ],
            'factors' => $usedMl ? null : [
                'season_factor'  => $seasonFactor,
                'price_factor'   => round($priceFactor, 4),
                'holiday_factor' => $holidayBonus,
            ]
        ]);

    // ── GET RECENT PREDICTIONS ───────────────────────────────
    case 'list':
        $pdo   = getDB();
        $limit = (int)($_GET['limit'] ?? 20);
        $stmt  = $pdo->prepare("
            SELECT p.*, u.full_name as predicted_by_name
            FROM predictions p
            LEFT JOIN users u ON p.predicted_by = u.id
            " . ($isAdmin ? '' : 'WHERE p.predicted_by = ? ') . "
            ORDER BY p.created_at DESC
            LIMIT ?
        ");
        $stmt->execute($isAdmin ? [$limit] : [$userId, $limit]);
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);

    // ── GET SINGLE PREDICTION ────────────────────────────────
    case 'get':
        $id  = (int)($_GET['id'] ?? 0);
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT p.*, r.recommended_stock, r.risk_level, r.shortage_alert, r.overstock_warning, r.action_required FROM predictions p LEFT JOIN recommendations r ON r.prediction_id = p.id WHERE p.id = ?" . ($isAdmin ? '' : " AND p.predicted_by = ?"));
        $stmt->execute($isAdmin ? [$id] : [$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['success' => false, 'message' => 'Not found'], 404);
        jsonResponse(['success' => true, 'data' => $row]);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
?>
