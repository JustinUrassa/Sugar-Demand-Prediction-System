<?php
ob_start();
// ============================================================
// api/reports.php — Generate and retrieve reports
// ============================================================
require_once __DIR__ . '/includes/config.php';
requireLogin();

$action = $_POST['action'] ?? $_GET['action'] ?? '';

switch ($action) {

    // ── MONTHLY BREAKDOWN (for a single year, or year=all) ───
    case 'monthly':
        $yearParam = $_GET['year'] ?? date('Y');
        $isAll     = ($yearParam === 'all');
        $pdo       = getDB();

        $where  = $isAll ? '' : 'WHERE YEAR(sale_date) = ?';
        $params = $isAll ? [] : [(int)$yearParam];

        $stmt = $pdo->prepare("
            SELECT
                DATE_FORMAT(sale_date,'%Y-%m') as period,
                DATE_FORMAT(sale_date,'%b %Y')  as label,
                SUM(quantity_kg)                as total_sales,
                SUM(total_revenue)              as total_revenue,
                AVG(price_per_kg)               as avg_price,
                MAX(quantity_kg)                as peak_sales,
                MIN(quantity_kg)                as low_sales,
                COUNT(*)                        as record_count
            FROM sugar_sales
            $where
            GROUP BY DATE_FORMAT(sale_date,'%Y-%m'), DATE_FORMAT(sale_date,'%b %Y')
            ORDER BY period
        ");
        $stmt->execute($params);
        $months = $stmt->fetchAll();

        // Dominant season per month - the season most of that month's sales
        // records were tagged with. Kept separate from the aggregate above
        // so that query stays a plain GROUP BY.
        $seasonStmt = $pdo->prepare("
            SELECT period, season FROM (
                SELECT
                    DATE_FORMAT(sale_date,'%Y-%m') as period,
                    season,
                    COUNT(*) as cnt,
                    ROW_NUMBER() OVER (PARTITION BY DATE_FORMAT(sale_date,'%Y-%m') ORDER BY COUNT(*) DESC) as rn
                FROM sugar_sales
                $where
                GROUP BY period, season
            ) ranked WHERE rn = 1
        ");
        $seasonStmt->execute($params);
        $seasonByPeriod = [];
        foreach ($seasonStmt->fetchAll() as $s) $seasonByPeriod[$s['period']] = $s['season'];

        // Flag the peak month so the table/chart can highlight it, same as
        // the old hardcoded data did.
        $peakSales = 0;
        foreach ($months as $m) $peakSales = max($peakSales, (float)$m['total_sales']);

        foreach ($months as &$m) {
            $m['season'] = $seasonByPeriod[$m['period']] ?? 'dry';
            $m['peak']   = ((float)$m['total_sales'] === $peakSales) && $peakSales > 0;
        }
        unset($m);

        jsonResponse(['success' => true, 'data' => $months, 'year' => $isAll ? 'all' : (int)$yearParam]);

    // ── YEARLY SUMMARY (all years on record) ─────────────────
    case 'yearly':
        $pdo  = getDB();
        $stmt = $pdo->query("
            SELECT
                YEAR(sale_date)       as year,
                SUM(quantity_kg)      as total_sales,
                SUM(total_revenue)    as total_revenue,
                AVG(price_per_kg)     as avg_price,
                COUNT(*)              as record_count
            FROM sugar_sales
            GROUP BY YEAR(sale_date)
            ORDER BY year
        ");
        $years = $stmt->fetchAll();

        // Peak month per year, resolved with a window function rather than
        // a correlated subquery per row.
        $peakStmt = $pdo->query("
            SELECT year, label, qty FROM (
                SELECT
                    YEAR(sale_date) as year,
                    DATE_FORMAT(sale_date,'%b') as label,
                    SUM(quantity_kg) as qty,
                    ROW_NUMBER() OVER (PARTITION BY YEAR(sale_date) ORDER BY SUM(quantity_kg) DESC) as rn
                FROM sugar_sales
                GROUP BY YEAR(sale_date), DATE_FORMAT(sale_date,'%Y-%m'), DATE_FORMAT(sale_date,'%b')
            ) ranked WHERE rn = 1
        ");
        $peaks = [];
        foreach ($peakStmt->fetchAll() as $p) $peaks[$p['year']] = $p;

        foreach ($years as &$y) {
            $p = $peaks[$y['year']] ?? null;
            $y['peak_month'] = $p['label'] ?? null;
            $y['peak_qty']   = $p ? (float)$p['qty'] : null;
        }
        unset($y);

        jsonResponse(['success' => true, 'data' => $years]);

    // ── YEARS ON RECORD (populates the year filter dropdown) ─
    case 'years':
        $pdo  = getDB();
        $stmt = $pdo->query("SELECT DISTINCT YEAR(sale_date) as year FROM sugar_sales ORDER BY year DESC");
        jsonResponse(['success' => true, 'data' => array_map('intval', array_column($stmt->fetchAll(), 'year'))]);

    // ── BANNER SUMMARY (rolling 12 months + YoY change) ──────
    case 'summary':
        $pdo = getDB();
        $maxDate = $pdo->query("SELECT MAX(sale_date) as d FROM sugar_sales")->fetchColumn();

        if (!$maxDate) {
            jsonResponse(['success' => true, 'has_data' => false]);
        }

        // Current window: the 12 calendar months ending on the most recent
        // sale on record (not "today" - seed/demo data may not be current).
        $curStart  = date('Y-m-01', strtotime($maxDate . ' -11 months'));
        $prevEnd   = date('Y-m-d', strtotime($curStart . ' -1 day'));
        $prevStart = date('Y-m-01', strtotime($prevEnd . ' -11 months'));

        $windowStmt = $pdo->prepare("
            SELECT
                COALESCE(SUM(quantity_kg),0)   as total_sales,
                COALESCE(SUM(total_revenue),0) as total_revenue,
                COALESCE(AVG(price_per_kg),0)  as avg_price
            FROM sugar_sales WHERE sale_date BETWEEN ? AND ?
        ");
        $windowStmt->execute([$curStart, $maxDate]);
        $current = $windowStmt->fetch();

        $windowStmt->execute([$prevStart, $prevEnd]);
        $previous = $windowStmt->fetch();

        $peakStmt = $pdo->prepare("
            SELECT DATE_FORMAT(sale_date,'%b %Y') as label, SUM(quantity_kg) as qty
            FROM sugar_sales WHERE sale_date BETWEEN ? AND ?
            GROUP BY DATE_FORMAT(sale_date,'%Y-%m'), DATE_FORMAT(sale_date,'%b %Y')
            ORDER BY qty DESC LIMIT 1
        ");
        $peakStmt->execute([$curStart, $maxDate]);
        $peak = $peakStmt->fetch();

        $pctChange = function ($new, $old) {
            if (!$old) return null;
            return round((($new - $old) / $old) * 100, 1);
        };

        jsonResponse([
            'success'            => true,
            'has_data'           => true,
            'window_label'       => date('M Y', strtotime($curStart)) . ' - ' . date('M Y', strtotime($maxDate)),
            'total_sales'        => (float)$current['total_sales'],
            'total_revenue'      => (float)$current['total_revenue'],
            'avg_price'          => (float)$current['avg_price'],
            'peak_label'         => $peak['label'] ?? null,
            'peak_qty'           => (float)($peak['qty'] ?? 0),
            'sales_growth_pct'   => $pctChange((float)$current['total_sales'], (float)$previous['total_sales']),
            'revenue_growth_pct' => $pctChange((float)$current['total_revenue'], (float)$previous['total_revenue']),
            'price_growth_pct'   => $pctChange((float)$current['avg_price'], (float)$previous['avg_price']),
        ]);

    case 'save':
        $pdo = getDB();
        $pdo->prepare("
            INSERT INTO reports (report_name, report_type, period_start, period_end,
                total_sales, total_revenue, avg_price, generated_by)
            VALUES (?,?,?,?,?,?,?,?)
        ")->execute([
            $_POST['report_name']  ?? 'Report ' . date('Y-m-d'),
            $_POST['report_type']  ?? 'monthly',
            $_POST['period_start'] ?? date('Y-m-01'),
            $_POST['period_end']   ?? date('Y-m-t'),
            (float)($_POST['total_sales']   ?? 0),
            (float)($_POST['total_revenue'] ?? 0),
            (float)($_POST['avg_price']     ?? 0),
            $_SESSION['user_id'],
        ]);
        jsonResponse(['success' => true, 'message' => 'Report saved.']);

    case 'list':
        $pdo  = getDB();
        $stmt = $pdo->query("
            SELECT r.*, u.full_name as generated_by_name
            FROM reports r
            LEFT JOIN users u ON u.id = r.generated_by
            ORDER BY r.created_at DESC
            LIMIT 50
        ");
        jsonResponse(['success' => true, 'data' => $stmt->fetchAll()]);

    default:
        jsonResponse(['error' => 'Unknown action'], 400);
}
?>
