<?php
// ============================================================
// includes/config.php
// ============================================================
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);
ini_set('log_errors', '1');

set_exception_handler(function (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json');
        header('Access-Control-Allow-Origin: *');
    }
    echo json_encode(['success' => false, 'message' => 'Server error: ' . $e->getMessage()]);
    exit;
});

set_error_handler(function (int $errno, string $errstr, string $errfile, int $errline) {
    if ($errno & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR)) {
        throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
    }
    error_log("PHP Warning [$errno]: $errstr in $errfile:$errline");
    return true;
});

define('DB_HOST',     'localhost');
define('DB_USER',     'root');
define('DB_PASS',     '');
define('DB_NAME',     'sugar_demand_db');
define('APP_NAME',    'SugarCast');
define('APP_VERSION', '1.0.0');

// ── Email Configuration ──────────────────────────────────────
// Gmail-ready defaults. Replace the placeholder values below with your own
// credentials to send real emails from the app.
function getEmailSetting(string $name, $default = '') {
    $value = getenv($name);
    if ($value === false || $value === '') {
        $value = $_ENV[$name] ?? $default;
    }
    return $value === false ? $default : $value;
}

define('MAIL_DRIVER', strtolower(getEmailSetting('MAIL_DRIVER', 'smtp')));
define('MAIL_HOST', getEmailSetting('MAIL_HOST', 'smtp.gmail.com'));
define('MAIL_PORT', (int) getEmailSetting('MAIL_PORT', '587'));
define('MAIL_USERNAME', getEmailSetting('MAIL_USERNAME', 'your@gmail.com'));
define('MAIL_PASSWORD', getEmailSetting('MAIL_PASSWORD', 'your-app-password'));
define('MAIL_ENCRYPTION', strtolower(getEmailSetting('MAIL_ENCRYPTION', 'tls')));
define('MAIL_FROM', getEmailSetting('MAIL_FROM', 'your@gmail.com'));
define('MAIL_FROM_NAME', getEmailSetting('MAIL_FROM_NAME', 'SugarCast System'));
define('ADMIN_EMAIL', getEmailSetting('ADMIN_EMAIL', 'your@gmail.com'));

define('BASE_URL',    getBaseUrl());

// ── ML Integration ────────────────────────────────────────────
// Path to the Python venv created alongside the project
// (see pyvenv.cfg — created with: python -m venv .venv).
// Falls back to a bare "python"/"python3" on PATH if the venv
// isn't found, so this still works outside XAMPP.
//
// Project layout: sugarcast/{frontend, backend, database, ml}
// This file lives in backend/includes/, so the project root is
// two levels up, and ml/ is a sibling of backend/.
define('PROJECT_ROOT', dirname(__DIR__, 2));
define('ML_SCRIPT_PATH', PROJECT_ROOT . '/ml/predict_demand.py');
define('OCR_SCRIPT_PATH', PROJECT_ROOT . '/ml/ocr_receipt.py');
define('ML_PYTHON_BIN', (function (): string {
    $root = PROJECT_ROOT;
    $winPy = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'Scripts' . DIRECTORY_SEPARATOR . 'python.exe';
    $nixPy = $root . DIRECTORY_SEPARATOR . '.venv' . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'python';
    if (file_exists($winPy)) return $winPy;
    if (file_exists($nixPy)) return $nixPy;
    return stripos(PHP_OS, 'WIN') === 0 ? 'python' : 'python3';
})());

function getBaseUrl(): string {
    if (PHP_SAPI === 'cli') return 'http://localhost/';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
    $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $script = $_SERVER['SCRIPT_NAME'] ?? '/';
    $base   = preg_replace('#/backend/.*$#', '/frontend/', $script) ?? '/frontend/';
    return rtrim($scheme . $host . $base, '/') . '/';
}

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 0) == 443;
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $isHttps,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    session_start();
}

header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Frame-Options: SAMEORIGIN');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (strpos($ct, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        if ($raw) { $d = json_decode($raw, true); if (is_array($d)) $_POST = array_merge($_POST, $d); }
    }
}

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            ensureSchemaCompatibility($pdo);
        } catch (PDOException $e) {
            http_response_code(500);
            die(json_encode(['success' => false, 'message' => 'Database connection failed: ' . $e->getMessage()]));
        }
    }
    return $pdo;
}

function ensureSchemaCompatibility(PDO $pdo): void {
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM users");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $alter = [];
        if (!in_array('reset_token', $columns, true)) {
            $alter[] = "ADD COLUMN reset_token VARCHAR(255) DEFAULT NULL";
        }
        if (!in_array('reset_expires', $columns, true)) {
            $alter[] = "ADD COLUMN reset_expires DATETIME DEFAULT NULL";
        }
        if (!in_array('avatar', $columns, true)) {
            $alter[] = "ADD COLUMN avatar VARCHAR(255) DEFAULT NULL";
        }
        if ($alter) {
            $pdo->exec('ALTER TABLE users ' . implode(', ', $alter));
        }
    } catch (Throwable $e) {
        error_log('Schema compatibility check failed: ' . $e->getMessage());
    }

    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM recommendations");
        $columns = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field');
        $alter = [];
        if (!in_array('is_acknowledged', $columns, true)) {
            $alter[] = "ADD COLUMN is_acknowledged TINYINT(1) NOT NULL DEFAULT 0";
        }
        if (!in_array('acknowledged_at', $columns, true)) {
            $alter[] = "ADD COLUMN acknowledged_at DATETIME DEFAULT NULL";
        }
        if ($alter) {
            $pdo->exec('ALTER TABLE recommendations ' . implode(', ', $alter));
        }
    } catch (Throwable $e) {
        error_log('Recommendations schema compatibility check failed: ' . $e->getMessage());
    }
}

// ── ML Prediction Bridge ────────────────────────────────────
// Runs backend/ml/predict_demand.py in the project's Python
// venv, feeding it recent daily sales history over stdin and
// reading back a JSON prediction. Returns an array that always
// has a 'success' key so callers can fall back safely.
function runMlPrediction(array $payload): array {
    if (!file_exists(ML_SCRIPT_PATH)) {
        return ['success' => false, 'message' => 'ML script not found on server.'];
    }

    $cmd = escapeshellarg(ML_PYTHON_BIN) . ' ' . escapeshellarg(ML_SCRIPT_PATH);
    $descriptors = [
        0 => ['pipe', 'r'], // stdin
        1 => ['pipe', 'w'], // stdout
        2 => ['pipe', 'w'], // stderr
    ];

    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Could not start Python ML process.'];
    }

    fwrite($pipes[0], json_encode($payload));
    fclose($pipes[0]);

    stream_set_timeout($pipes[1], 15);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 && !$stdout) {
        error_log("ML prediction process failed (exit $exitCode): $stderr");
        return ['success' => false, 'message' => 'ML process error.'];
    }

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        error_log("ML prediction returned non-JSON output: $stdout | stderr: $stderr");
        return ['success' => false, 'message' => 'ML process returned an invalid response.'];
    }

    return $decoded;
}

// ── OCR Bridge (receipt / ledger photo extraction) ───────────
// Runs ml/ocr_receipt.py against an uploaded image using the same venv
// Python as the ML bridge above. Tesseract itself is a system binary, not
// a pip package — if it isn't installed, the script reports that clearly
// and the caller can still let the trader type figures in by hand.
function runOcrExtraction(string $imagePath): array {
    if (!file_exists(OCR_SCRIPT_PATH)) {
        return ['success' => false, 'message' => 'OCR script not found on server.'];
    }

    $cmd = escapeshellarg(ML_PYTHON_BIN) . ' ' . escapeshellarg(OCR_SCRIPT_PATH) . ' ' . escapeshellarg($imagePath);
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($process)) {
        return ['success' => false, 'message' => 'Could not start the OCR process.'];
    }

    fclose($pipes[0]);
    stream_set_timeout($pipes[1], 20);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0 && !$stdout) {
        error_log("OCR process failed (exit $exitCode): $stderr");
        return ['success' => false, 'message' => 'OCR process error. Is Tesseract installed on the server?'];
    }

    $decoded = json_decode(trim($stdout), true);
    if (!is_array($decoded)) {
        error_log("OCR returned non-JSON output: $stdout | stderr: $stderr");
        return ['success' => false, 'message' => 'OCR process returned an invalid response.'];
    }

    return $decoded;
}

// ── Notifications ─────────────────────────────────────────────
// Central place for every real event in the system to raise an in-app
// notification. $userId = null broadcasts to every user (notifications.php
// treats NULL user_id as "visible to everyone"); pass a specific user id
// to target one account, or loop this over an admin list to reach admins
// only. Never throws — a notification failure should never break the
// request that triggered it.
function createNotification(?int $userId, string $title, string $message, string $type = 'info'): void {
    try {
        getDB()->prepare("INSERT INTO notifications (user_id, title, message, type) VALUES (?,?,?,?)")
            ->execute([$userId, $title, $message, $type]);
    } catch (Throwable $e) {
        error_log('createNotification failed: ' . $e->getMessage());
    }
}

// Fan a notification out to every active admin account. Used for events
// an admin should see (new signups, critical stock alerts) without
// broadcasting them to every trader too.
function notifyAdmins(string $title, string $message, string $type = 'info'): void {
    try {
        $admins = getDB()->query("SELECT id FROM users WHERE role = 'admin' AND is_active = 1")
            ->fetchAll(PDO::FETCH_COLUMN);
        foreach ($admins as $adminId) {
            createNotification((int)$adminId, $title, $message, $type);
        }
    } catch (Throwable $e) {
        error_log('notifyAdmins failed: ' . $e->getMessage());
    }
}

function isLoggedIn(): bool { return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']); }

function requireLogin(): void {
    if (!isLoggedIn()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Unauthenticated', 'redirect' => BASE_URL.'index.html']);
        exit;
    }
}

function currentUser(): array {
    if (!isLoggedIn()) return [];
    $stmt = getDB()->prepare("SELECT id, username, full_name, email, role, avatar FROM users WHERE id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) return [];
    $user['avatar'] = avatarUrl($user['avatar']);
    return $user;
}

// Turns a stored relative path (e.g. "assets/uploads/avatars/user_3_...jpg")
// into an absolute URL the frontend can drop straight into an <img src>,
// regardless of which page/subfolder it's rendered from. Returns null when
// there's no avatar so callers can fall back to initials.
function avatarUrl(?string $path): ?string {
    if (!$path) return null;
    if (preg_match('#^https?://#i', $path)) return $path; // already absolute
    return BASE_URL . ltrim($path, '/');
}

function jsonResponse(array $data, int $code = 200): void {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Access-Control-Allow-Origin: *');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    ob_end_clean();
    echo json_encode($data);
    exit;
}

// ── Email sender (supports SMTP when configured, otherwise falls back) ───
function sendEmail(string $to, string $subject, string $textMessage = '', string $htmlMessage = ''): bool {
    $fromName    = MAIL_FROM_NAME;
    $fromAddress = MAIL_FROM;

    $boundary = md5(uniqid(rand(), true));
    $headers  = [];
    $headers[] = "From: {$fromName} <{$fromAddress}>";
    $headers[] = "Reply-To: {$fromAddress}";
    $headers[] = "X-Mailer: SugarCast/1.0";
    $headers[] = "MIME-Version: 1.0";

    $body = '';
    if ($htmlMessage) {
        $headers[] = "Content-Type: multipart/alternative; boundary=\"{$boundary}\"";
        $body  = "--{$boundary}\r\n";
        $body .= "Content-Type: text/plain; charset=UTF-8\r\n\r\n";
        $body .= ($textMessage ?: strip_tags($htmlMessage)) . "\r\n\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
        $body .= $htmlMessage . "\r\n\r\n";
        $body .= "--{$boundary}--";
    } else {
        $headers[] = "Content-Type: text/plain; charset=UTF-8";
        $body = $textMessage;
    }

    if (MAIL_DRIVER === 'smtp' && !empty(MAIL_HOST)) {
        $smtpResult = sendEmailViaSmtp($to, $subject, $body, $headers);
        error_log("SugarCast SMTP Email: to={$to} subject={$subject} result=" . ($smtpResult ? 'OK' : 'FAIL'));
        return $smtpResult;
    }

    $headerString = implode("\r\n", $headers) . "\r\n";
    $result = @mail($to, $subject, $body, $headerString);
    error_log("SugarCast Email: to={$to} subject={$subject} result=" . ($result ? 'OK' : 'FAIL'));
    return $result;
}

function sendEmailViaSmtp(string $to, string $subject, string $body, array $headers): bool {
    $host = MAIL_HOST;
    $port = MAIL_PORT ?: 587;
    $encryption = MAIL_ENCRYPTION;
    $transport = ($encryption === 'ssl') ? 'ssl://' : '';

    $context = stream_context_create([
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ],
    ]);

    $socket = @stream_socket_client($transport . $host . ':' . $port, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
    if (!$socket) {
        error_log('SMTP connect failed: ' . $errstr);
        return false;
    }

    $response = readSmtpResponse($socket);
    if ($response['code'] !== 220) {
        fclose($socket);
        return false;
    }

    sendSmtpCommand($socket, 'EHLO ' . gethostname());
    if ($encryption === 'tls') {
        sendSmtpCommand($socket, 'STARTTLS');
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        sendSmtpCommand($socket, 'EHLO ' . gethostname());
    }

    if (!empty(MAIL_USERNAME) && !empty(MAIL_PASSWORD)) {
        sendSmtpCommand($socket, 'AUTH LOGIN');
        sendSmtpCommand($socket, base64_encode(MAIL_USERNAME));
        sendSmtpCommand($socket, base64_encode(MAIL_PASSWORD));
    }

    sendSmtpCommand($socket, 'MAIL FROM:<'.MAIL_FROM.'>');
    sendSmtpCommand($socket, 'RCPT TO:<'.$to.'>');
    sendSmtpCommand($socket, 'DATA');

    $messageHeaders = [
        'To: ' . $to,
        'Subject: ' . $subject,
        'Date: ' . date('r'),
        'Message-ID: <' . uniqid('', true) . '@' . parse_url(BASE_URL, PHP_URL_HOST ?: 'localhost') . '>',
    ];
    foreach ($headers as $header) {
        $messageHeaders[] = $header;
    }

    fwrite($socket, implode("\r\n", $messageHeaders) . "\r\n\r\n" . $body . "\r\n.\r\n");
    $response = readSmtpResponse($socket);
    if ($response['code'] !== 250 && $response['code'] !== 354) {
        fclose($socket);
        return false;
    }

    sendSmtpCommand($socket, 'QUIT');
    fclose($socket);
    return true;
}

function sendSmtpCommand($socket, string $command): bool {
    fwrite($socket, $command . "\r\n");
    $response = readSmtpResponse($socket);
    return in_array($response['code'], [220, 250, 235, 354], true);
}

function readSmtpResponse($socket): array {
    $response = '';
    while (true) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $response .= $line;
        if (strlen($line) < 2 || $line[3] === ' ') {
            break;
        }
    }
    $code = 0;
    if (preg_match('/^(\d{3})/', $response, $matches)) {
        $code = (int) $matches[1];
    }
    return ['code' => $code, 'message' => trim($response)];
}

function generateSecurePassword(int $length = 12): string {
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789!@#$%^&*';
    $pwd = '';
    for ($i = 0; $i < $length; $i++) {
        $pwd .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $pwd;
}

function generateSecureToken(int $length = 32): string {
    return rtrim(strtr(base64_encode(random_bytes($length)), '+/', '-_'), '=');
}

function getPasswordResetRequestEmailTemplate(string $fullName, string $resetUrl): string {
    $body = "
    <h2 style='color:#0f172a;font-size:22px;margin:0 0 8px'>Password Reset Requested</h2>
    <p style='color:#475569;font-size:15px;line-height:1.7;margin:0 0 24px'>Hello <strong style='color:#0f172a'>{$fullName}</strong>,</p>
    <p style='color:#475569;font-size:14px;line-height:1.7;margin:0 0 24px'>A request to reset your SugarCast password was received. Click the button below to choose a new password. This link expires in one hour.</p>
    <div style='text-align:center;margin-bottom:24px'>
      <a href='{$resetUrl}' style='display:inline-block;background:linear-gradient(135deg,#0f766e,#b45309);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:14px 32px;border-radius:999px;box-shadow:0 6px 20px rgba(15,118,110,.3)'>Reset Password</a>
    </div>
    <p style='color:#94a3b8;font-size:13px;line-height:1.6;margin:0 0 12px'>If you did not request a password reset, simply ignore this email and your current password will remain unchanged.</p>
    <p style='color:#94a3b8;font-size:13px;line-height:1.6;margin:0'>If the button does not work, paste this link into your browser:<br><a href='{$resetUrl}' style='color:#0f766e;'>{$resetUrl}</a></p>
    ";
    return emailLayout('#0f766e', 'Reset Your SugarCast Password', $body);
}

// ── Email Templates ──────────────────────────────────────────
function emailLayout(string $accentColor, string $title, string $body): string {
    return "<!DOCTYPE html><html><head><meta charset='UTF-8'><meta name='viewport' content='width=device-width,initial-scale=1'>
    <title>{$title}</title></head>
    <body style='margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif'>
    <table width='100%' cellpadding='0' cellspacing='0' style='background:#f1f5f9;padding:32px 0'>
      <tr><td align='center'>
        <table width='600' cellpadding='0' cellspacing='0' style='max-width:600px;width:100%'>
          <!-- Header -->
          <tr><td style='background:linear-gradient(135deg,#0f172a,{$accentColor});border-radius:12px 12px 0 0;padding:28px 36px;text-align:center'>
            <div style='display:inline-flex;align-items:center;gap:10px'>
              <div style='width:40px;height:40px;background:rgba(255,255,255,.15);border-radius:10px;display:inline-block;text-align:center;line-height:40px;font-size:20px'>#</div>
              <span style='font-size:22px;font-weight:800;color:#fff;letter-spacing:-0.5px'>SugarCast</span>
            </div>
            <div style='font-size:11px;color:rgba(255,255,255,.55);margin-top:4px;letter-spacing:1px;text-transform:uppercase'>Mbeya Sugar Market System</div>
          </td></tr>
          <!-- Body -->
          <tr><td style='background:#fff;padding:36px;border-left:1px solid #e2e8f0;border-right:1px solid #e2e8f0'>
            {$body}
          </td></tr>
          <!-- Footer -->
          <tr><td style='background:#f8fafc;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 12px 12px;padding:20px 36px;text-align:center'>
            <p style='font-size:12px;color:#94a3b8;margin:0'>This is an automated message from SugarCast. Please do not reply to this email.</p>
            <p style='font-size:11px;color:#cbd5e1;margin:8px 0 0'>&copy; " . date('Y') . " SugarCast &mdash; Mbeya Markets, Tanzania</p>
          </td></tr>
        </table>
      </td></tr>
    </table>
    </body></html>";
}

function getWelcomeEmailTemplate(string $fullName, string $username): string {
    $appUrl = BASE_URL . 'index.html';
    $body = "
    <h2 style='color:#0f172a;font-size:22px;margin:0 0 8px'>Welcome to SugarCast! </h2>
    <p style='color:#475569;font-size:15px;line-height:1.7;margin:0 0 24px'>Hello <strong style='color:#0f172a'>{$fullName}</strong>,</p>
    <p style='color:#475569;font-size:14px;line-height:1.7;margin:0 0 24px'>Your account has been successfully created on the SugarCast Sugar Demand Management System for Mbeya Markets. You can now log in and start managing sugar demand data.</p>
    
    <div style='background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:20px;margin-bottom:24px'>
      <div style='font-size:12px;font-weight:700;color:#15803d;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px'>Your Account Details</div>
      <table width='100%' cellpadding='6' cellspacing='0'>
        <tr><td style='font-size:13px;color:#64748b;width:40%'>Username</td><td style='font-size:13px;font-weight:700;color:#0f172a'>{$username}</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Role</td><td style='font-size:13px;font-weight:700;color:#0f172a'>Trader</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Status</td><td><span style='background:#dcfce7;color:#15803d;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px'>Active</span></td></tr>
      </table>
    </div>

    <div style='text-align:center;margin-bottom:24px'>
      <a href='{$appUrl}' style='display:inline-block;background:linear-gradient(135deg,#0f766e,#b45309);color:#fff;text-decoration:none;font-weight:700;font-size:14px;padding:14px 32px;border-radius:999px;box-shadow:0 6px 20px rgba(15,118,110,.3)'>Sign In to SugarCast</a>
    </div>

    <p style='color:#94a3b8;font-size:13px;line-height:1.6;border-top:1px solid #f1f5f9;padding-top:20px;margin:0'>If you did not create this account, please contact your system administrator immediately.</p>";

    return emailLayout('#0f766e', 'Welcome to SugarCast', $body);
}

function getAdminNewUserEmailTemplate(string $newUserName, string $newUsername, string $newEmail, string $role): string {
    $body = "
    <h2 style='color:#0f172a;font-size:20px;margin:0 0 8px'>New Account Registration</h2>
    <p style='color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px'>A new user has registered on SugarCast and requires your attention.</p>
    
    <div style='background:#fefce8;border:1px solid #fde68a;border-radius:10px;padding:20px;margin-bottom:24px'>
      <div style='font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px'>New User Details</div>
      <table width='100%' cellpadding='6' cellspacing='0'>
        <tr><td style='font-size:13px;color:#64748b;width:35%'>Full Name</td><td style='font-size:13px;font-weight:700;color:#0f172a'>{$newUserName}</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Username</td><td style='font-size:13px;font-weight:700;color:#0f172a'>{$newUsername}</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Email</td><td style='font-size:13px;color:#0f172a'>{$newEmail}</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Role</td><td><span style='background:#dbeafe;color:#1d4ed8;font-size:12px;font-weight:700;padding:2px 8px;border-radius:999px'>{$role}</span></td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Registered</td><td style='font-size:13px;color:#0f172a'>" . date('d M Y, H:i') . " UTC</td></tr>
      </table>
    </div>

    <p style='color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px'>You can manage this account (change role, deactivate, or delete) from the User Management panel.</p>
    <div style='text-align:center'>
      <a href='" . BASE_URL . "pages/users.html' style='display:inline-block;background:#0f172a;color:#fff;text-decoration:none;font-weight:700;font-size:13px;padding:12px 28px;border-radius:999px'>Manage Users</a>
    </div>";

    return emailLayout('#d97706', 'New User Registration — SugarCast', $body);
}

function getPasswordResetEmailTemplate(string $fullName, string $username, string $tempPassword): string {
    $body = "
    <h2 style='color:#0f172a;font-size:20px;margin:0 0 8px'>Password Reset</h2>
    <p style='color:#475569;font-size:14px;line-height:1.7;margin:0 0 20px'>Hello <strong>{$fullName}</strong>, your password has been reset by an administrator.</p>
    
    <div style='background:#fff7ed;border:1px solid #fed7aa;border-radius:10px;padding:20px;margin-bottom:24px'>
      <div style='font-size:12px;font-weight:700;color:#92400e;text-transform:uppercase;letter-spacing:0.5px;margin-bottom:12px'>Temporary Credentials</div>
      <table width='100%' cellpadding='6' cellspacing='0'>
        <tr><td style='font-size:13px;color:#64748b;width:35%'>Username</td><td style='font-size:13px;font-weight:700;color:#0f172a'>{$username}</td></tr>
        <tr><td style='font-size:13px;color:#64748b'>Temp Password</td><td><code style='background:#fef3c7;color:#92400e;font-size:13px;font-weight:700;padding:4px 10px;border-radius:6px;letter-spacing:1px'>{$tempPassword}</code></td></tr>
      </table>
    </div>

    <div style='background:#fef2f2;border:1px solid #fecaca;border-radius:8px;padding:14px;margin-bottom:20px'>
      <p style='font-size:13px;color:#b91c1c;margin:0;font-weight:600'>! Please change your password immediately after logging in.</p>
    </div>
    <p style='color:#94a3b8;font-size:13px;line-height:1.6;border-top:1px solid #f1f5f9;padding-top:20px;margin:0'>If you did not request this reset, contact your administrator immediately.</p>";

    return emailLayout('#d97706', 'Password Reset — SugarCast', $body);
}
