<?php
ob_start();
// ============================================================
// api/sales.php — CRUD for sugar_sales + CSV upload
// ============================================================
require_once __DIR__ . '/includes/config.php';
requireLogin();

$method = $_SERVER['REQUEST_METHOD'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$userId = (int)($_SESSION['user_id'] ?? 0);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

switch ($action) {

    // ── LIST SALES ───────────────────────────────────────────
    case 'list':
        $pdo    = getDB();
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = (int)($_GET['limit'] ?? 15);
        $offset = ($page - 1) * $limit;
        $search = '%' . trim($_GET['search'] ?? '') . '%';
        $season = $_GET['season'] ?? '';
        $from   = $_GET['from']   ?? '';
        $to     = $_GET['to']     ?? '';

        $where  = "WHERE (supplier_name LIKE ? OR market_location LIKE ? OR sugar_type LIKE ?)";
        $params = [$search, $search, $search];
        if (!$isAdmin) { $where .= " AND recorded_by = ?"; $params[] = $userId; }
        if ($season) { $where .= " AND season = ?"; $params[] = $season; }
        if ($from)   { $where .= " AND sale_date >= ?"; $params[] = $from; }
        if ($to)     { $where .= " AND sale_date <= ?"; $params[] = $to; }

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM sugar_sales $where");
        $countStmt->execute($params);
        $total = $countStmt->fetchColumn();

        $stmt = $pdo->prepare("SELECT * FROM sugar_sales $where ORDER BY sale_date DESC LIMIT $limit OFFSET $offset");
        $stmt->execute($params);

        jsonResponse([
            'success' => true,
            'data'    => $stmt->fetchAll(),
            'total'   => (int)$total,
            'page'    => $page,
            'pages'   => ceil($total / $limit),
        ]);

    // ── GET SINGLE SALE ───────────────────────────────────────
    case 'get':
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        $stmt = getDB()->prepare($isAdmin ? "SELECT * FROM sugar_sales WHERE id = ?" : "SELECT * FROM sugar_sales WHERE id = ? AND recorded_by = ?");
        $stmt->execute($isAdmin ? [$id] : [$id, $userId]);
        $row = $stmt->fetch();
        if (!$row) jsonResponse(['success' => false, 'message' => 'Record not found.'], 404);
        jsonResponse(['success' => true, 'data' => $row]);

    // ── ADD SALE ─────────────────────────────────────────────
    case 'add':
        $fields = ['sale_date','quantity_kg','price_per_kg','market_location','sugar_type','supplier_name','season','is_holiday'];
        $data = [];
        foreach ($fields as $f) $data[$f] = $_POST[$f] ?? '';
        $data['is_holiday'] = (int)($data['is_holiday'] ?? 0);
        $data['recorded_by'] = $_SESSION['user_id'];

        if (empty($data['sale_date']) || $data['quantity_kg'] <= 0) {
            jsonResponse(['success' => false, 'message' => 'Date and quantity are required.'], 400);
        }

        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO sugar_sales (sale_date, quantity_kg, price_per_kg, market_location, sugar_type, supplier_name, season, is_holiday, recorded_by)
            VALUES (:sale_date,:quantity_kg,:price_per_kg,:market_location,:sugar_type,:supplier_name,:season,:is_holiday,:recorded_by)
        ")->execute($data);

        jsonResponse(['success' => true, 'message' => 'Sale record added.', 'id' => $pdo->lastInsertId()]);

    // ── UPDATE SALE ──────────────────────────────────────────
    case 'update':
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);

        $pdo = getDB();
        $pdo->prepare("
            UPDATE sugar_sales SET
                sale_date=:sale_date, quantity_kg=:quantity_kg, price_per_kg=:price_per_kg,
                market_location=:market_location, sugar_type=:sugar_type, supplier_name=:supplier_name,
                season=:season, is_holiday=:is_holiday
            WHERE id=:id" . ($isAdmin ? '' : " AND recorded_by=:recorded_by") . "
        ")->execute([
            'sale_date'       => $_POST['sale_date'],
            'quantity_kg'     => (float)$_POST['quantity_kg'],
            'price_per_kg'    => (float)$_POST['price_per_kg'],
            'market_location' => $_POST['market_location'],
            'sugar_type'      => $_POST['sugar_type'],
            'supplier_name'   => $_POST['supplier_name'],
            'season'          => $_POST['season'],
            'is_holiday'      => (int)$_POST['is_holiday'],
            'recorded_by'     => $userId,
            'id'              => $id,
        ]);
        jsonResponse(['success' => true, 'message' => 'Record updated.']);

    // ── DELETE SALE ──────────────────────────────────────────
    case 'delete':
        $id = (int)($_POST['id'] ?? $_GET['id'] ?? 0);
        if (!$id) jsonResponse(['success' => false, 'message' => 'ID required'], 400);
        getDB()->prepare($isAdmin ? "DELETE FROM sugar_sales WHERE id = ?" : "DELETE FROM sugar_sales WHERE id = ? AND recorded_by = ?")->execute($isAdmin ? [$id] : [$id, $userId]);
        jsonResponse(['success' => true, 'message' => 'Record deleted.']);

    // ── CSV UPLOAD ───────────────────────────────────────────
    case 'upload_csv':
        if (!isset($_FILES['csv_file'])) jsonResponse(['success' => false, 'message' => 'No file uploaded'], 400);
        $file = $_FILES['csv_file'];
        if (pathinfo($file['name'], PATHINFO_EXTENSION) !== 'csv') {
            jsonResponse(['success' => false, 'message' => 'Only CSV files allowed'], 400);
        }

        $handle = fopen($file['tmp_name'], 'r');
        $header = fgetcsv($handle); // skip header row
        $pdo    = getDB();
        $stmt   = $pdo->prepare("INSERT IGNORE INTO sugar_sales (sale_date,quantity_kg,price_per_kg,market_location,sugar_type,supplier_name,season,is_holiday,recorded_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $count  = 0;

        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) < 5) continue;
            $stmt->execute([
                $row[0] ?? date('Y-m-d'),
                (float)($row[1] ?? 0),
                (float)($row[2] ?? 0),
                $row[3] ?? 'Mbeya Central Market',
                $row[4] ?? 'brown',
                $row[5] ?? '',
                $row[6] ?? 'dry',
                (int)($row[7] ?? 0),
                $_SESSION['user_id']
            ]);
            $count++;
        }
        fclose($handle);

        if ($count > 0) {
            createNotification($_SESSION['user_id'], 'CSV Import Complete',
                "$count sales record" . ($count === 1 ? '' : 's') . " imported successfully.", 'success');
        }

        jsonResponse(['success' => true, 'message' => "$count records imported successfully."]);

    // ── RECENT STATS (prefill helper for Demand Analysis) ────
    // Summarizes the last N days of recorded sales so the prediction form
    // can be pre-filled after a CSV/photo import instead of the trader
    // having to type totals in by hand.
    case 'recent_stats':
        $days = max(1, (int)($_GET['days'] ?? 30));
        $pdo  = getDB();
        $stmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(quantity_kg),0) as total_qty,
                COALESCE(AVG(price_per_kg),0) as avg_price,
                COUNT(*) as record_count,
                MAX(sale_date) as last_sale_date
            FROM sugar_sales
            WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)" . ($isAdmin ? '' : " AND recorded_by = ?") . "
        ");
        $stmt->execute($isAdmin ? [$days] : [$days, $userId]);
        $row = $stmt->fetch();

        jsonResponse([
            'success'        => true,
            'days'           => $days,
            'total_qty'      => (float)$row['total_qty'],
            'avg_price'      => (float)$row['avg_price'],
            'record_count'   => (int)$row['record_count'],
            'last_sale_date' => $row['last_sale_date'],
        ]);

    // ── OCR RECEIPT/LEDGER EXTRACTION ─────────────────────────
    // Lets a trader photograph a paper receipt or ledger page instead of
    // typing figures in by hand. This is assistive, not authoritative: it
    // returns best-guess numbers plus the raw OCR text so the trader can
    // review and correct them before anything is saved or predicted on.
    case 'ocr_extract':
        if (empty($_FILES['receipt_image']) || $_FILES['receipt_image']['error'] !== UPLOAD_ERR_OK)
            jsonResponse(['success' => false, 'message' => 'No image was uploaded, or the upload failed.'], 400);

        $file = $_FILES['receipt_image'];
        if ($file['size'] > 8 * 1024 * 1024)
            jsonResponse(['success' => false, 'message' => 'Image must be smaller than 8MB.'], 400);

        $allowedMimes = ['image/jpeg', 'image/png', 'image/webp'];
        $mime = mime_content_type($file['tmp_name']);
        if (!in_array($mime, $allowedMimes, true))
            jsonResponse(['success' => false, 'message' => 'Only JPG, PNG, or WEBP images are allowed.'], 400);

        $tmpPath = sys_get_temp_dir() . '/sc_receipt_' . uniqid() . '_' . basename($file['name']);
        if (!move_uploaded_file($file['tmp_name'], $tmpPath))
            jsonResponse(['success' => false, 'message' => 'Could not process the uploaded image.'], 500);

        $result = runOcrExtraction($tmpPath);
        @unlink($tmpPath);

        if (empty($result['success'])) {
            jsonResponse(['success' => false, 'message' => $result['message'] ?? 'Could not read this image.'], 200);
        }

        jsonResponse([
            'success'   => true,
            'raw_text'  => $result['raw_text'] ?? '',
            'extracted' => $result['extracted'] ?? [],
        ]);

    // ── DASHBOARD STATS ──────────────────────────────────────
    case 'dashboard_stats':
        $pdo = getDB();

        $salesWhere = $isAdmin ? '' : ' WHERE recorded_by = ' . $pdo->quote($userId);
        $predWhere = $isAdmin ? '' : ' WHERE predicted_by = ' . $pdo->quote($userId);

        $totalSales = $pdo->query("SELECT COALESCE(SUM(quantity_kg),0) FROM sugar_sales" . $salesWhere)->fetchColumn();
        $lastPred   = $pdo->query("SELECT predicted_demand FROM predictions" . $predWhere . " ORDER BY created_at DESC LIMIT 1")->fetchColumn();
        $lastRec    = $pdo->query("SELECT recommended_stock FROM recommendations r JOIN predictions p ON p.id = r.prediction_id" . ($isAdmin ? '' : " WHERE p.predicted_by = " . $pdo->quote($userId)) . " ORDER BY r.created_at DESC LIMIT 1")->fetchColumn();
        $curStock   = $pdo->query("SELECT COALESCE(current_stock,0) FROM recommendations r JOIN predictions p ON p.id = r.prediction_id" . ($isAdmin ? '' : " WHERE p.predicted_by = " . $pdo->quote($userId)) . " ORDER BY r.created_at DESC LIMIT 1")->fetchColumn();

        // Monthly trend (last 12 months)
        $trend = $pdo->query("
            SELECT DATE_FORMAT(sale_date,'%Y-%m') as month,
                   SUM(quantity_kg) as total_qty,
                   AVG(price_per_kg) as avg_price
            FROM sugar_sales" . $salesWhere . "
            AND sale_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
            GROUP BY DATE_FORMAT(sale_date,'%Y-%m')
            ORDER BY month
        ")->fetchAll();

        // Demand by season (real totals — replaces the old hardcoded chart data)
        $seasonRows = $pdo->query("
            SELECT season, COALESCE(SUM(quantity_kg),0) as total_qty
            FROM sugar_sales" . $salesWhere . "
            GROUP BY season
        ")->fetchAll(PDO::FETCH_KEY_PAIR);
        $seasonBreakdown = [
            'dry'      => (float)($seasonRows['dry']      ?? 0),
            'wet'      => (float)($seasonRows['wet']      ?? 0),
            'harvest'  => (float)($seasonRows['harvest']  ?? 0),
            'planting' => (float)($seasonRows['planting'] ?? 0),
        ];

        // Recent activity
        $recent = $pdo->query("
            SELECT p.id, p.target_date, p.predicted_demand, p.confidence_level,
                   r.risk_level, u.full_name
            FROM predictions p
            LEFT JOIN recommendations r ON r.prediction_id = p.id
            LEFT JOIN users u ON u.id = p.predicted_by
            " . ($isAdmin ? '' : 'WHERE p.predicted_by = ' . $pdo->quote($userId) . ' ') . "
            ORDER BY p.created_at DESC LIMIT 5
        ")->fetchAll();

        jsonResponse([
            'success'          => true,
            'total_sales_kg'   => (float)$totalSales,
            'predicted_demand' => (float)$lastPred,
            'current_stock'    => (float)$curStock,
            'recommended_stock'=> (float)$lastRec,
            'monthly_trend'    => $trend,
            'season_breakdown' => $seasonBreakdown,
            'recent_activity'  => $recent,
        ]);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
?>
