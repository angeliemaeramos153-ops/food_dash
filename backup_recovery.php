<?php
// ============================================================================
// FOOD DASH • REQUIREMENT H: DATABASE BACKUP AND RECOVERY MODULE
// High-Security Admin Control Panel for MySQL Database Snapshots & Recovery
// ============================================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once 'db_connect.php';
require_once 'notification_lib.php';

// Safe Fallback for Activity Logging
if (!function_exists('log_activity')) {
    function log_activity($conn, $user_id_or_action, $action_or_details = '', $details = '') {
        try {
            $user_id = null;
            $action = 'ACTION';
            $det = '';
            if (is_numeric($user_id_or_action)) {
                $user_id = (int)$user_id_or_action;
                $action = (string)$action_or_details;
                $det = (string)$details;
            } else {
                $action = (string)$user_id_or_action;
                $det = (string)$action_or_details;
                $user_id = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
            }
            $ip = substr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 0, 45);
            $ua = substr($_SERVER['HTTP_USER_AGENT'] ?? 'System/API', 0, 255);
            $uname = $_SESSION['username'] ?? $_SESSION['full_name'] ?? 'Admin';
            $urole = $_SESSION['role'] ?? 'admin';
            if ($conn instanceof PDO) {
                try {
                    $stmt = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (:uid, :uname, :role, :act, :det, :ip, :ua, NOW())");
                    $stmt->execute([':uid' => $user_id, ':uname' => $uname, ':role' => $urole, ':act' => $action, ':det' => $det, ':ip' => $ip, ':ua' => $ua]);
                } catch (Throwable $e1) {
                    try {
                        $stmt2 = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (NULL, :uname, :role, :act, :det, :ip, :ua, NOW())");
                        $stmt2->execute([':uname' => $uname, ':role' => $urole, ':act' => $action, ':det' => $det, ':ip' => $ip, ':ua' => $ua]);
                    } catch (Throwable $e2) {}
                }
            }
        } catch (Throwable $t) {
            error_log("Activity log notice: " . $t->getMessage());
        }
        return true;
    }
}

// 1. STRICT AUTHENTICATION GUARD: Admin Role Only
if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit();
}

$user_id   = (int)($_SESSION['user_id'] ?? 0);
$username  = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Admin';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'Admin';
$user_role = ucfirst($_SESSION['role'] ?? 'Admin');

// CSRF Token Initialization
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// 2. BACKUP DIRECTORY SETUP & PROTECTION
$backup_dir = __DIR__ . DIRECTORY_SEPARATOR . 'backups';
if (!is_dir($backup_dir)) {
    @mkdir($backup_dir, 0755, true);
}

// Ensure .htaccess blocks direct HTTP web requests to /backups/
$htaccess_path = $backup_dir . DIRECTORY_SEPARATOR . '.htaccess';
if (!file_exists($htaccess_path)) {
    $htaccess_content = "# Deny all direct web access to database backups\n"
                      . "<IfModule authz_core_module>\n"
                      . "    Require all denied\n"
                      . "</IfModule>\n"
                      . "<IfModule !authz_core_module>\n"
                      . "    Order deny,allow\n"
                      . "    Deny from all\n"
                      . "</IfModule>\n";
    @file_put_contents($htaccess_path, $htaccess_content);
}

// Ensure index.php serves 403 Forbidden as secondary protection
$index_block_path = $backup_dir . DIRECTORY_SEPARATOR . 'index.php';
if (!file_exists($index_block_path)) {
    @file_put_contents($index_block_path, "<?php http_response_code(403); exit('Access Forbidden');");
}

// Helper: Format Bytes to Human Readable string
function format_bytes($bytes, $precision = 2) {
    if ($bytes <= 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

// Helper: Detect Binary Executables (mysqldump & mysql)
function get_binary_path($binary_name) {
    // 1. Common XAMPP Windows paths
    $candidate_paths = [
        'C:\\xampp\\mysql\\bin\\' . $binary_name . '.exe',
        'C:\\Program Files\\MySQL\\MySQL Server 8.0\\bin\\' . $binary_name . '.exe',
        'C:\\Program Files\\MariaDB 10.4\\bin\\' . $binary_name . '.exe',
        'C:\\wamp64\\bin\\mysql\\mysql8.0.27\\bin\\' . $binary_name . '.exe',
        $binary_name . '.exe',
        $binary_name
    ];
    foreach ($candidate_paths as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    // 2. Check system PATH via 'where' or 'which'
    $cmd = (stripos(PHP_OS, 'WIN') === 0) ? 'where ' . escapeshellarg($binary_name) . ' 2>nul' : 'which ' . escapeshellarg($binary_name) . ' 2>/dev/null';
    $output = @shell_exec($cmd);
    if (!empty($output)) {
        $lines = preg_split('/\r\n|\r|\n/', trim($output));
        if (!empty($lines[0]) && file_exists(trim($lines[0]))) {
            return trim($lines[0]);
        }
    }
    return $binary_name;
}

// Flash Message State
$flash_success = $_SESSION['flash_success'] ?? '';
$flash_error   = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// 3. SECURE FILE DOWNLOAD HANDLER
if (isset($_GET['action']) && $_GET['action'] === 'download') {
    $requested_file = trim($_GET['file'] ?? '');

    // Validate filename strictly (prevent path traversal)
    if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/i', $requested_file)) {
        $_SESSION['flash_error'] = "Invalid backup file name specified for download.";
        header("Location: backup_recovery.php");
        exit;
    }

    $file_path = realpath($backup_dir . DIRECTORY_SEPARATOR . $requested_file);
    $real_backup_dir = realpath($backup_dir);

    if (!$file_path || strpos($file_path, $real_backup_dir) !== 0 || !is_file($file_path)) {
        $_SESSION['flash_error'] = "Backup file not found or unauthorized access path.";
        header("Location: backup_recovery.php");
        exit;
    }

    log_activity($conn, $user_id, 'BACKUP_DOWNLOAD', "Admin downloaded SQL database backup: {$requested_file}");

    while (ob_get_level()) {
        ob_end_clean();
    }
    header('Content-Description: File Transfer');
    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . basename($file_path) . '"');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
    header('Pragma: public');
    header('Content-Length: ' . filesize($file_path));
    readfile($file_path);
    exit;
}

// 4. ACTION PROCESSORS (Backup Creation, Restore, Deletion)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrf_token, $submitted_token)) {
        $_SESSION['flash_error'] = "Security token mismatch (CSRF verification failed). Please try again.";
        header("Location: backup_recovery.php");
        exit;
    }

    // A. GENERATE NEW BACKUP
    if (isset($_POST['action_generate_backup'])) {
        $timestamp = date('Y-m-d_H-i-s');
        $filename  = 'food_dash_backup_' . $timestamp . '.sql';
        $filepath  = $backup_dir . DIRECTORY_SEPARATOR . $filename;

        // Database credentials
        $db_host = 'localhost';
        $db_port = '3306';
        $db_user = 'root';
        $db_pass = '';
        $db_name = 'food_dash';

        $mysqldump_bin = get_binary_path('mysqldump');

        $cmd = '"' . $mysqldump_bin . '"'
             . ' --host=' . escapeshellarg($db_host)
             . ' --port=' . escapeshellarg($db_port)
             . ' --user=' . escapeshellarg($db_user)
             . (!empty($db_pass) ? ' --password=' . escapeshellarg($db_pass) : '')
             . ' --databases ' . escapeshellarg($db_name)
             . ' --default-character-set=utf8mb4'
             . ' --routines --triggers --single-transaction --add-drop-database --add-drop-table'
             . ' --result-file="' . $filepath . '" 2>&1';

        $output = [];
        $return_var = 0;
        exec($cmd, $output, $return_var);

        if ($return_var === 0 && file_exists($filepath) && filesize($filepath) > 0) {
            $formatted_size = format_bytes(filesize($filepath));
            log_activity($conn, $user_id, 'BACKUP_CREATED', "Admin generated database backup: {$filename} ({$formatted_size}).");
            $_SESSION['flash_success'] = "Database backup generated successfully: <strong>{$filename}</strong> ({$formatted_size}).";
        } else {
            // Remove incomplete file if left behind
            if (file_exists($filepath)) {
                @unlink($filepath);
            }
            $err_msg = !empty($output) ? implode(' ', $output) : "Unknown execution error (code: {$return_var}).";
            log_activity($conn, $user_id, 'BACKUP_FAILED', "Failed to generate database backup. Details: {$err_msg}");
            $_SESSION['flash_error'] = "Failed to create database backup: " . htmlspecialchars($err_msg);
        }
        header("Location: backup_recovery.php");
        exit;
    }

    // B. RESTORE DATABASE FROM BACKUP
    if (isset($_POST['action_restore_backup'])) {
        $restore_file = trim($_POST['restore_file'] ?? '');

        // Security Validation
        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/i', $restore_file)) {
            $_SESSION['flash_error'] = "Invalid backup file name provided for restore.";
            header("Location: backup_recovery.php");
            exit;
        }

        $filepath = realpath($backup_dir . DIRECTORY_SEPARATOR . $restore_file);
        $real_backup_dir = realpath($backup_dir);

        if (!$filepath || strpos($filepath, $real_backup_dir) !== 0 || !is_file($filepath)) {
            $_SESSION['flash_error'] = "Selected backup file does not exist in the /backups/ directory.";
            header("Location: backup_recovery.php");
            exit;
        }

        $db_host = 'localhost';
        $db_port = '3306';
        $db_user = 'root';
        $db_pass = '';
        $db_name = 'food_dash';

        $mysql_bin = get_binary_path('mysql');
        $restore_success = false;
        $error_detail = '';

        // Execute import via proc_open streaming input file to mysql.exe
        $descriptors = [
            0 => ['file', $filepath, 'r'], // stream backup SQL directly into mysql standard input
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w']
        ];

        $cmd_mysql = '"' . $mysql_bin . '"'
                   . ' --host=' . escapeshellarg($db_host)
                   . ' --port=' . escapeshellarg($db_port)
                   . ' --user=' . escapeshellarg($db_user)
                   . (!empty($db_pass) ? ' --password=' . escapeshellarg($db_pass) : '')
                   . ' --default-character-set=utf8mb4'
                   . ' ' . escapeshellarg($db_name);

        $process = @proc_open($cmd_mysql, $descriptors, $pipes);

        if (is_resource($process)) {
            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $exit_code = proc_close($process);

            if ($exit_code === 0) {
                $restore_success = true;
            } else {
                $error_detail = !empty($stderr) ? $stderr : $stdout;
            }
        } else {
            // Fallback: Read and execute statement blocks via PDO
            try {
                $sql_content = file_get_contents($filepath);
                if (!empty($sql_content)) {
                    $conn->exec("SET FOREIGN_KEY_CHECKS = 0;");
                    $conn->exec($sql_content);
                    $conn->exec("SET FOREIGN_KEY_CHECKS = 1;");
                    $restore_success = true;
                } else {
                    $error_detail = "Backup file is empty.";
                }
            } catch (Throwable $pe) {
                $error_detail = $pe->getMessage();
            }
        }

        if ($restore_success) {
            log_activity($conn, $user_id, 'DATABASE_RESTORED', "Admin restored database from backup file: {$restore_file}");
            $_SESSION['flash_success'] = "Database restored successfully from <strong>" . htmlspecialchars($restore_file) . "</strong>. All tables and schemas are up to date.";
        } else {
            log_activity($conn, $user_id, 'RESTORE_FAILED', "Failed database restoration from {$restore_file}: {$error_detail}");
            $_SESSION['flash_error'] = "Database restore failed: " . htmlspecialchars($error_detail ?: "Execution error.");
        }
        header("Location: backup_recovery.php");
        exit;
    }

    // C. DELETE BACKUP
    if (isset($_POST['action_delete_backup'])) {
        $delete_file = trim($_POST['delete_file'] ?? '');

        if (!preg_match('/^[a-zA-Z0-9_\-\.]+\.sql$/i', $delete_file)) {
            $_SESSION['flash_error'] = "Invalid file name provided for deletion.";
            header("Location: backup_recovery.php");
            exit;
        }

        $filepath = realpath($backup_dir . DIRECTORY_SEPARATOR . $delete_file);
        $real_backup_dir = realpath($backup_dir);

        if ($filepath && strpos($filepath, $real_backup_dir) === 0 && is_file($filepath)) {
            @unlink($filepath);
            log_activity($conn, $user_id, 'BACKUP_DELETED', "Admin deleted database backup file: {$delete_file}");
            $_SESSION['flash_success'] = "Backup file <strong>" . htmlspecialchars($delete_file) . "</strong> was permanently deleted.";
        } else {
            $_SESSION['flash_error'] = "File not found or permission denied.";
        }
        header("Location: backup_recovery.php");
        exit;
    }
}

// 5. FETCH & ORGANIZE ALL AVAILABLE BACKUP FILES
$backup_files = [];
$total_backup_bytes = 0;
$latest_backup_time = 0;

if (is_dir($backup_dir)) {
    $scan = scandir($backup_dir);
    foreach ($scan as $item) {
        if ($item === '.' || $item === '..' || !str_ends_with(strtolower($item), '.sql')) {
            continue;
        }
        $full_path = $backup_dir . DIRECTORY_SEPARATOR . $item;
        if (is_file($full_path)) {
            $bytes = filesize($full_path);
            $mtime = filemtime($full_path);
            $total_backup_bytes += $bytes;
            if ($mtime > $latest_backup_time) {
                $latest_backup_time = $mtime;
            }
            $backup_files[] = [
                'filename'       => $item,
                'filesize_bytes' => $bytes,
                'filesize_fmt'   => format_bytes($bytes),
                'mtime'          => $mtime,
                'date_fmt'       => date('M d, Y h:i:s A', $mtime),
                'is_readable'    => is_readable($full_path)
            ];
        }
    }
    // Sort descending: newest backups first
    usort($backup_files, function($a, $b) {
        return $b['mtime'] <=> $a['mtime'];
    });
}

$total_backups_count = count($backup_files);
$formatted_total_size = format_bytes($total_backup_bytes);
$latest_backup_str   = $latest_backup_time > 0 ? date('M d, Y • h:i A', $latest_backup_time) : 'No backups yet';
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover">
  <title>Food Dash • Database Backup & Recovery (Requirement H)</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700;800&display=swap');

    :root {
      --harvest-sun: #D88F2A;
      --sagebrush: #788A6E;
      --earth-clay: #923D32;
      --olive-shadow: #4B5320;
      --sandstone: #D4C5A5;
      --sandstone-light: #FAF6EE;
      --fd-gold: #D88F2A;
      --fd-sage: #788A6E;
      --fd-rust: #923D32;
      --fd-olive: #4B5320;
      --fd-cream: #D4C5A5;
      --fd-bg: #f4f0df;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

    html, body {
      min-height: 100vh;
      width: 100%;
      background:
        radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
        radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
        var(--fd-bg);
      color: #20241a;
      display: flex;
      justify-content: center;
      align-items: flex-start;
      margin: 0;
      padding: 20px 0;
      overflow-x: hidden;
    }

    button, input, select { font: inherit; }
    button { cursor: pointer; }

    /* RESPONSIVE APP SHELL */
    .app {
      width: 96%;
      max-width: 1400px;
      min-height: 860px;
      background: #fffdf7;
      position: relative;
      box-shadow: 0 14px 40px rgba(75,83,32,.12);
      display: flex;
      flex-direction: column;
      margin: 0 auto;
      border: 1px solid #ddd3ad;
      border-radius: 26px;
      overflow: hidden;
    }

    @media (max-width: 991px) {
      .app { width: 98%; max-width: 100%; border-radius: 20px; }
    }

    @media (max-width: 767px) {
      html, body { padding: 0; }
      .app { border: none; border-radius: 0; box-shadow: none; min-height: 100vh; max-width: 100%; }
    }

    /* MAIN CONTAINER */
    .main-content {
      margin: 0;
      width: 100%;
      flex: 1 0 auto;
      padding: 0 20px 90px;
      background: #fffdf7;
      box-sizing: border-box;
    }

    @media (max-width: 599px) {
      .main-content { padding: 0 12px 100px; }
    }

    /* TOP BAR & BREADCRUMB */
    .top-bar {
      position: sticky;
      top: 0;
      z-index: 50;
      background: rgba(255,253,247,.97);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      padding: 16px 4px 12px;
      margin: 0 -4px 16px;
      border-bottom: 1px solid rgba(231,223,196,.7);
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 12px;
      flex-wrap: wrap;
    }

    .top-bar-left h1 {
      font-size: 20px;
      color: #344016;
      font-weight: 700;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .top-bar-left p {
      color: #74776a;
      font-size: 11px;
      margin-top: 2px;
    }

    .action-header-btns {
      display: flex;
      gap: 8px;
      align-items: center;
      flex-wrap: wrap;
    }

    .btn-nav-back {
      background: #fff;
      border: 1px solid #d4c5a5;
      color: #4b5320;
      border-radius: 12px;
      padding: 8px 14px;
      font-size: 11px;
      font-weight: 600;
      text-decoration: none;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: all 0.2s;
    }
    .btn-nav-back:hover {
      background: #f7f3df;
      color: #344016;
      border-color: #c0b08a;
    }

    .db-badge-online {
      background: #eef3ec;
      border: 1px solid #788A6E;
      color: #4B5320;
      padding: 6px 12px;
      border-radius: 20px;
      font-size: 10px;
      font-weight: 700;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .pulse-dot {
      width: 8px;
      height: 8px;
      border-radius: 50%;
      background: #4B5320;
      box-shadow: 0 0 0 0 rgba(75, 83, 32, 0.7);
      animation: pulseDot 2s infinite;
    }
    @keyframes pulseDot {
      0% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(75, 83, 32, 0.7); }
      70% { transform: scale(1); box-shadow: 0 0 0 6px rgba(75, 83, 32, 0); }
      100% { transform: scale(0.95); box-shadow: 0 0 0 0 rgba(75, 83, 32, 0); }
    }

    /* ALERTS / FLASH NOTIFICATIONS */
    .alert-banner {
      padding: 12px 16px;
      border-radius: 14px;
      margin-bottom: 16px;
      font-size: 12px;
      line-height: 1.5;
      display: flex;
      align-items: center;
      gap: 10px;
      animation: fadeIn 0.3s ease;
    }
    .alert-banner.success {
      background: #edf3d8;
      border: 1px solid #c8d99c;
      color: #354614;
    }
    .alert-banner.error {
      background: #fcedec;
      border: 1px solid #f3bcba;
      color: #923D32;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-6px); }
      to { opacity: 1; transform: translateY(0); }
    }

    /* REQUIREMENT CALLOUT HERO CARD */
    .hero-requirement-card {
      background: linear-gradient(135deg, #FAF6EE 0%, #FFFFFF 100%);
      border: 1.5px solid #E7DFC4;
      border-radius: 18px;
      padding: 20px;
      margin-bottom: 20px;
      display: flex;
      justify-content: space-between;
      align-items: center;
      gap: 20px;
      box-shadow: 0 8px 24px rgba(55,65,22,.05);
      flex-wrap: wrap;
    }

    .hero-info {
      flex: 1 1 340px;
    }
    .hero-badge {
      display: inline-block;
      font-size: 10px;
      font-weight: 700;
      color: #D88F2A;
      background: #FFF4E5;
      border: 1px solid #D88F2A;
      padding: 3px 9px;
      border-radius: 12px;
      text-transform: uppercase;
      margin-bottom: 8px;
      letter-spacing: 0.5px;
    }
    .hero-info h2 {
      font-size: 16px;
      color: #344016;
      font-weight: 700;
      margin-bottom: 6px;
    }
    .hero-info p {
      font-size: 11px;
      color: #6a6e60;
      line-height: 1.6;
    }

    .btn-create-backup {
      background: linear-gradient(135deg, #4B5320 0%, #373D17 100%);
      color: #FFFFFF;
      border: none;
      border-radius: 14px;
      padding: 13px 22px;
      font-size: 12px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 10px;
      box-shadow: 0 6px 18px rgba(75, 83, 32, 0.28);
      transition: all 0.25s ease;
      white-space: nowrap;
    }
    .btn-create-backup:hover {
      background: linear-gradient(135deg, #5B6528 0%, #434B1D 100%);
      transform: translateY(-2px);
      box-shadow: 0 8px 22px rgba(75, 83, 32, 0.38);
    }
    .btn-create-backup:active {
      transform: translateY(0);
    }

    /* METRICS STATS GRID */
    .metrics-grid {
      display: grid;
      grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
      gap: 12px;
      margin-bottom: 22px;
    }

    .metric-card {
      background: #FFFFFF;
      border: 1px solid #E7DFC4;
      border-radius: 16px;
      padding: 16px;
      box-shadow: 0 4px 16px rgba(55,65,22,.04);
      display: flex;
      justify-content: space-between;
      align-items: center;
      transition: 0.2s;
    }
    .metric-card:hover {
      border-color: #D4C5A5;
      transform: translateY(-2px);
      box-shadow: 0 6px 20px rgba(55,65,22,.08);
    }
    .metric-data h3 {
      font-size: 19px;
      font-weight: 700;
      color: #344016;
      line-height: 1.2;
    }
    .metric-data p {
      font-size: 10px;
      color: #74776A;
      margin-top: 3px;
      font-weight: 500;
    }
    .metric-icon {
      font-size: 26px;
      opacity: 0.9;
    }

    /* TABLE SECTION */
    .dashboard-section {
      background: #FFFFFF;
      border: 1px solid #E7DFC4;
      border-radius: 18px;
      padding: 20px;
      box-shadow: 0 5px 20px rgba(55,65,22,.04);
      margin-bottom: 25px;
    }

    .section-header {
      display: flex;
      justify-content: space-between;
      align-items: center;
      margin-bottom: 16px;
      flex-wrap: wrap;
      gap: 10px;
    }

    .section-title {
      font-size: 14px;
      font-weight: 700;
      color: #344016;
      display: flex;
      align-items: center;
      gap: 8px;
    }

    .section-badge {
      font-size: 10px;
      background: #FAF6EE;
      color: #788A6E;
      border: 1px solid #D4C5A5;
      padding: 3px 9px;
      border-radius: 10px;
      font-weight: 600;
    }

    /* TABLE STYLES */
    .table-responsive {
      width: 100%;
      overflow-x: auto;
      -webkit-overflow-scrolling: touch;
    }

    table.backup-table {
      width: 100%;
      border-collapse: separate;
      border-spacing: 0;
      font-size: 11px;
    }

    table.backup-table thead tr {
      background: #FAF6EE;
      color: #4B5320;
      font-weight: 700;
      text-transform: uppercase;
      font-size: 9.5px;
      letter-spacing: 0.5px;
    }

    table.backup-table th {
      padding: 12px 14px;
      text-align: left;
      border-bottom: 1.5px solid #E7DFC4;
    }
    table.backup-table th:first-child { border-top-left-radius: 10px; }
    table.backup-table th:last-child { border-top-right-radius: 10px; text-align: right; }

    table.backup-table tbody tr {
      transition: background 0.15s ease;
      border-bottom: 1px solid #F0EAD6;
    }
    table.backup-table tbody tr:hover {
      background: #FDFBF5;
    }

    table.backup-table td {
      padding: 13px 14px;
      color: #2F3423;
      border-bottom: 1px solid #F0EAD6;
      vertical-align: middle;
    }

    .file-name-cell {
      display: flex;
      align-items: center;
      gap: 10px;
    }
    .file-icon-box {
      width: 34px;
      height: 34px;
      border-radius: 10px;
      background: #FAF6EE;
      border: 1px solid #D4C5A5;
      display: grid;
      place-items: center;
      color: #D88F2A;
      font-size: 14px;
      flex-shrink: 0;
    }
    .file-info-title {
      font-weight: 600;
      color: #2B3314;
      font-size: 11.5px;
      word-break: break-all;
    }
    .file-info-subtitle {
      font-size: 9px;
      color: #838779;
      margin-top: 1px;
    }

    .size-badge {
      display: inline-block;
      font-size: 10px;
      font-weight: 600;
      background: #FAF6EE;
      color: #4B5320;
      border: 1px solid #D4C5A5;
      padding: 2px 8px;
      border-radius: 8px;
    }

    .storage-tag {
      display: inline-flex;
      align-items: center;
      gap: 4px;
      font-size: 9.5px;
      font-weight: 600;
      color: #788A6E;
      background: #EEF3EC;
      border: 1px solid #CCD8C5;
      padding: 2px 7px;
      border-radius: 8px;
    }

    /* ACTION BUTTONS */
    .actions-wrapper {
      display: flex;
      gap: 6px;
      justify-content: flex-end;
      align-items: center;
      flex-wrap: wrap;
    }

    .btn-act {
      border: none;
      border-radius: 9px;
      padding: 7px 11px;
      font-size: 10px;
      font-weight: 600;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 5px;
      text-decoration: none;
      transition: all 0.2s ease;
      white-space: nowrap;
    }

    .btn-download {
      background: #FAF6EE;
      color: #5D4F1E;
      border: 1px solid #D4C5A5;
    }
    .btn-download:hover {
      background: #D88F2A;
      color: #FFFFFF;
      border-color: #D88F2A;
    }

    .btn-restore {
      background: #EEF3EC;
      color: #2E4B1E;
      border: 1px solid #B7CBB0;
    }
    .btn-restore:hover {
      background: #4B5320;
      color: #FFFFFF;
      border-color: #4B5320;
    }

    .btn-delete {
      background: #FCEDEC;
      color: #923D32;
      border: 1px solid #F3BCBA;
    }
    .btn-delete:hover {
      background: #923D32;
      color: #FFFFFF;
      border-color: #923D32;
    }

    /* EMPTY STATE */
    .empty-state {
      text-align: center;
      padding: 40px 20px;
      color: #74776A;
    }
    .empty-state i {
      font-size: 42px;
      color: #D4C5A5;
      margin-bottom: 12px;
    }
    .empty-state h3 {
      font-size: 14px;
      color: #344016;
      margin-bottom: 6px;
    }
    .empty-state p {
      font-size: 11px;
      max-width: 360px;
      margin: 0 auto 16px;
      line-height: 1.5;
    }

    /* MODAL POPUPS */
    .modal-overlay {
      display: none;
      position: fixed;
      inset: 0;
      width: 100%;
      height: 100%;
      background: rgba(28,32,6,.45);
      backdrop-filter: blur(4px);
      align-items: center;
      justify-content: center;
      padding: 16px;
      z-index: 2500;
    }
    .modal-overlay.active {
      display: flex;
    }

    .modal-card {
      width: min(440px, 100%);
      background: #FFFDF7;
      border: 1px solid #E7DFC4;
      border-radius: 20px;
      padding: 22px;
      color: #29300F;
      box-shadow: 0 20px 60px rgba(55,65,22,.28);
      animation: modalPop 0.25s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    @keyframes modalPop {
      from { transform: scale(0.92); opacity: 0; }
      to { transform: scale(1); opacity: 1; }
    }

    .modal-header-danger {
      display: flex;
      align-items: center;
      gap: 12px;
      margin-bottom: 12px;
    }
    .modal-header-danger .warning-icon {
      width: 40px;
      height: 40px;
      border-radius: 50%;
      background: #FCEDEC;
      color: #923D32;
      display: grid;
      place-items: center;
      font-size: 18px;
      flex-shrink: 0;
    }
    .modal-header-danger h3 {
      font-size: 15px;
      color: #923D32;
      font-weight: 700;
    }

    .modal-card p {
      font-size: 11px;
      color: #555A49;
      line-height: 1.6;
      margin-bottom: 14px;
    }

    .modal-file-box {
      background: #FAF6EE;
      border: 1px solid #D4C5A5;
      border-radius: 12px;
      padding: 10px 12px;
      margin-bottom: 16px;
      font-size: 11px;
    }
    .modal-file-box strong {
      color: #29300F;
      word-break: break-all;
    }
    .modal-file-box .meta {
      font-size: 9.5px;
      color: #788A6E;
      margin-top: 2px;
    }

    .modal-actions {
      display: flex;
      justify-content: flex-end;
      gap: 8px;
    }

    .btn-modal-cancel {
      background: #FFFFFF;
      border: 1px solid #D4C5A5;
      color: #555A49;
      padding: 9px 15px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 600;
    }
    .btn-modal-cancel:hover {
      background: #F7F3DF;
    }

    .btn-modal-danger {
      background: #923D32;
      border: none;
      color: #FFFFFF;
      padding: 9px 18px;
      border-radius: 10px;
      font-size: 11px;
      font-weight: 700;
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
      transition: background 0.2s;
    }
    .btn-modal-danger:hover {
      background: #7A2E25;
    }

    /* BOTTOM / SIDEBAR NAVIGATION BAR */
    .sidebar-dock {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: rgba(255, 253, 247, 0.98);
      backdrop-filter: blur(12px);
      -webkit-backdrop-filter: blur(12px);
      border-top: 1px solid #E7DFC4;
      display: flex;
      justify-content: center;
      z-index: 1000;
      box-shadow: 0 -4px 18px rgba(75, 83, 32, 0.08);
    }
    .dock-inner {
      width: 100%;
      max-width: 1400px;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 0 auto;
      padding: 8px 12px;
      overflow-x: auto;
      scrollbar-width: none;
    }
    .dock-inner::-webkit-scrollbar { display: none; }

    .dock-item {
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      gap: 3px;
      color: #74776A;
      text-decoration: none;
      font-size: 9px;
      font-weight: 600;
      padding: 6px 10px;
      border-radius: 10px;
      transition: all 0.2s;
      white-space: nowrap;
      flex-shrink: 0;
    }
    .dock-item i {
      font-size: 14px;
    }
    .dock-item:hover, .dock-item.active {
      color: #4B5320;
      background: #EEF3EC;
    }
    .dock-item.active {
      color: #4B5320;
      font-weight: 700;
      border-bottom: 2px solid #D88F2A;
    }

    /* CARD GRID FOR MOBILE SCREEN ADAPTATION */
    @media (max-width: 680px) {
      table.backup-table thead { display: none; }
      table.backup-table, table.backup-table tbody, table.backup-table tr, table.backup-table td {
        display: block;
        width: 100%;
        box-sizing: border-box;
      }
      table.backup-table tr {
        background: #FFFFFF;
        border: 1px solid #E7DFC4;
        border-radius: 14px;
        padding: 12px;
        margin-bottom: 10px;
      }
      table.backup-table td {
        padding: 4px 0;
        border: none;
      }
      table.backup-table td:last-child {
        padding-top: 10px;
        border-top: 1px solid #F0EAD6;
        margin-top: 8px;
      }
      .actions-wrapper {
        justify-content: flex-start;
      }
    }
  </style>
</head>
<body>

<div class="app">

  <main class="main-content">

    <!-- TOP CONTROL BAR -->
    <div class="top-bar">
      <div class="top-bar-left">
        <h1><i class="fa-solid fa-database" style="color:var(--fd-olive);"></i> Backup & Recovery</h1>
        <p>Requirement H • Database Snapshots, Automated mysqldump, and One-Click Restore</p>
      </div>
      <div class="action-header-btns">
        <span class="db-badge-online">
          <span class="pulse-dot"></span> MySQL: food_dash
        </span>
        <a href="admin_dashboard.php" class="btn-nav-back">
          <i class="fa-solid fa-arrow-left"></i> Admin Dashboard
        </a>
      </div>
    </div>

    <!-- FLASH MESSAGES -->
    <?php if (!empty($flash_success)): ?>
      <div class="alert-banner success">
        <i class="fa-solid fa-circle-check" style="font-size:16px;"></i>
        <div><?php echo $flash_success; ?></div>
      </div>
    <?php endif; ?>

    <?php if (!empty($flash_error)): ?>
      <div class="alert-banner error">
        <i class="fa-solid fa-triangle-exclamation" style="font-size:16px;"></i>
        <div><?php echo $flash_error; ?></div>
      </div>
    <?php endif; ?>

    <!-- HERO REQUIREMENT CARD -->
    <div class="hero-requirement-card">
      <div class="hero-info">
        <span class="hero-badge"><i class="fa-solid fa-shield-halved"></i> Requirement H Specification</span>
        <h2>Database Integrity & Disaster Recovery</h2>
        <p>
          Generates a complete, isolated SQL dump including all schema definitions, triggers, routines, and table records for <code>food_dash</code>. Files are strictly protected in the local <code>/backups/</code> directory with direct web access denied by server authorization guards.
        </p>
      </div>
      <div>
        <form method="POST" action="backup_recovery.php" onsubmit="return handleBackupSubmit(this);">
          <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
          <button type="submit" name="action_generate_backup" id="btnBackupGenerate" class="btn-create-backup">
            <i class="fa-solid fa-cloud-arrow-up"></i>
            <span>Generate New SQL Backup</span>
          </button>
        </form>
      </div>
    </div>

    <!-- METRICS STATS CARDS -->
    <div class="metrics-grid">
      <div class="metric-card">
        <div class="metric-data">
          <h3><?php echo $total_backups_count; ?></h3>
          <p>Available Backup Files</p>
        </div>
        <i class="fa-solid fa-box-archive metric-icon" style="color:var(--fd-gold);"></i>
      </div>

      <div class="metric-card">
        <div class="metric-data">
          <h3><?php echo $formatted_total_size; ?></h3>
          <p>Total Storage in /backups/</p>
        </div>
        <i class="fa-solid fa-hard-drive metric-icon" style="color:var(--fd-sage);"></i>
      </div>

      <div class="metric-card">
        <div class="metric-data">
          <h3 style="font-size:14px;"><?php echo htmlspecialchars($latest_backup_str); ?></h3>
          <p>Latest Backup Generated</p>
        </div>
        <i class="fa-solid fa-clock-rotate-left metric-icon" style="color:var(--fd-olive);"></i>
      </div>

      <div class="metric-card">
        <div class="metric-data">
          <h3>Localhost</h3>
          <p>Target: MySQL Port 3306</p>
        </div>
        <i class="fa-solid fa-server metric-icon" style="color:var(--fd-rust);"></i>
      </div>
    </div>

    <!-- BACKUP FILES REPOSITORY TABLE -->
    <div class="dashboard-section">
      <div class="section-header">
        <div class="section-title">
          <i class="fa-solid fa-table-list" style="color:var(--fd-olive);"></i>
          <span>Available Backup Archives</span>
          <span class="section-badge"><?php echo $total_backups_count; ?> Snapshot<?php echo $total_backups_count === 1 ? '' : 's'; ?></span>
        </div>
        <div style="font-size:10px; color:#788A6E;">
          <i class="fa-solid fa-lock"></i> Storage: <code>/backups/</code> (Protected by .htaccess)
        </div>
      </div>

      <div class="table-responsive">
        <?php if (empty($backup_files)): ?>
          <div class="empty-state">
            <i class="fa-solid fa-folder-open"></i>
            <h3>No Database Backups Found</h3>
            <p>You haven't generated any database backup snapshots yet. Click the "Generate New SQL Backup" button above to create your first protected backup file.</p>
            <form method="POST" action="backup_recovery.php">
              <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
              <button type="submit" name="action_generate_backup" class="btn-create-backup" style="margin: 0 auto;">
                <i class="fa-solid fa-cloud-arrow-up"></i> Generate Initial Backup
              </button>
            </form>
          </div>
        <?php else: ?>
          <table class="backup-table">
            <thead>
              <tr>
                <th>Backup File Name</th>
                <th>File Size</th>
                <th>Date & Time Created</th>
                <th>Security & Path</th>
                <th style="text-align:right;">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($backup_files as $bf): ?>
                <tr>
                  <td>
                    <div class="file-name-cell">
                      <div class="file-icon-box">
                        <i class="fa-solid fa-file-code"></i>
                      </div>
                      <div>
                        <div class="file-info-title"><?php echo htmlspecialchars($bf['filename']); ?></div>
                        <div class="file-info-subtitle">Full Schema + Triggers & Data</div>
                      </div>
                    </div>
                  </td>
                  <td>
                    <span class="size-badge"><?php echo htmlspecialchars($bf['filesize_fmt']); ?></span>
                  </td>
                  <td>
                    <div style="font-weight:600; color:#344016;"><?php echo htmlspecialchars($bf['date_fmt']); ?></div>
                    <div style="font-size:9.5px; color:#838779;"><?php echo htmlspecialchars(date('D, M d Y', $bf['mtime'])); ?></div>
                  </td>
                  <td>
                    <span class="storage-tag">
                      <i class="fa-solid fa-shield-halved"></i> Protected /backups/
                    </span>
                  </td>
                  <td>
                    <div class="actions-wrapper">
                      <!-- 1. Download Link -->
                      <a href="backup_recovery.php?action=download&file=<?php echo urlencode($bf['filename']); ?>" class="btn-act btn-download" title="Download SQL Dump">
                        <i class="fa-solid fa-download"></i> Download
                      </a>

                      <!-- 2. Restore Button (Triggers Warning Prompt) -->
                      <button type="button" class="btn-act btn-restore" onclick="openRestoreModal('<?php echo htmlspecialchars($bf['filename'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bf['filesize_fmt'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($bf['date_fmt'], ENT_QUOTES); ?>')" title="Restore Database from this file">
                        <i class="fa-solid fa-rotate-left"></i> Restore
                      </button>

                      <!-- 3. Delete Action -->
                      <button type="button" class="btn-act btn-delete" onclick="openDeleteModal('<?php echo htmlspecialchars($bf['filename'], ENT_QUOTES); ?>')" title="Permanently Delete Snapshot">
                        <i class="fa-solid fa-trash-can"></i>
                      </button>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>
      </div>
    </div>

  </main>

  <!-- DOCK NAVIGATION BAR (MATCHES ADMIN DASHBOARD) -->
  <aside class="sidebar-dock">
    <div class="dock-inner">
      <a href="admin_dashboard.php?tab=analytics" class="dock-item">
        <i class="fa-solid fa-chart-line"></i>
        <span>Analytics</span>
      </a>
      <a href="admin_dashboard.php?tab=dispatch-map" class="dock-item">
        <i class="fa-solid fa-map-location-dot"></i>
        <span>Fleet Map</span>
      </a>
      <a href="admin_dashboard.php?tab=users" class="dock-item">
        <i class="fa-solid fa-users-gear"></i>
        <span>Users</span>
      </a>
      <a href="admin_dashboard.php?tab=auditlogs" class="dock-item">
        <i class="fa-solid fa-shield-halved"></i>
        <span>Audit Trail</span>
      </a>
      <a href="admin_dashboard.php?tab=riders" class="dock-item">
        <i class="fa-solid fa-motorcycle"></i>
        <span>Riders</span>
      </a>
      <a href="admin_dashboard.php?tab=stores" class="dock-item">
        <i class="fa-solid fa-store"></i>
        <span>Stores</span>
      </a>
      <a href="admin_dashboard.php?tab=menus" class="dock-item">
        <i class="fa-solid fa-utensils"></i>
        <span>Menus</span>
      </a>
      <a href="admin_dashboard.php?tab=transactions" class="dock-item">
        <i class="fa-solid fa-receipt"></i>
        <span>Orders</span>
      </a>
      <a href="backup_recovery.php" class="dock-item active">
        <i class="fa-solid fa-database"></i>
        <span>DB Backup</span>
      </a>
    </div>
  </aside>

</div>

<!-- RESTORE DATABASE CONFIRMATION MODAL -->
<div id="restoreModal" class="modal-overlay">
  <div class="modal-card">
    <div class="modal-header-danger">
      <div class="warning-icon">
        <i class="fa-solid fa-triangle-exclamation"></i>
      </div>
      <div>
        <h3>Confirm Database Restoration</h3>
        <span style="font-size:9.5px; color:#838779;">Critical Operation • Requirement H</span>
      </div>
    </div>

    <p>
      <strong>WARNING:</strong> Restoring this backup will securely import the SQL snapshot into <code>food_dash</code> and <span style="color:#923D32; font-weight:700;">overwrite all current database data</span>. Any modifications made after this backup was created will be permanently replaced.
    </p>

    <div class="modal-file-box">
      <div>Selected Backup: <strong id="modalRestoreFilename">-</strong></div>
      <div class="meta">Size: <span id="modalRestoreSize">-</span> • Created: <span id="modalRestoreDate">-</span></div>
    </div>

    <form method="POST" action="backup_recovery.php" id="restoreForm" onsubmit="return handleRestoreSubmit();">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
      <input type="hidden" name="restore_file" id="restoreFileInput" value="">
      <input type="hidden" name="action_restore_backup" value="1">

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="closeRestoreModal()">Cancel</button>
        <button type="submit" id="btnConfirmRestore" class="btn-modal-danger">
          <i class="fa-solid fa-rotate-left"></i> Yes, Restore Database
        </button>
      </div>
    </form>
  </div>
</div>

<!-- DELETE BACKUP CONFIRMATION MODAL -->
<div id="deleteModal" class="modal-overlay">
  <div class="modal-card">
    <div class="modal-header-danger">
      <div class="warning-icon">
        <i class="fa-solid fa-trash-can"></i>
      </div>
      <div>
        <h3>Delete Backup Snapshot</h3>
        <span style="font-size:9.5px; color:#838779;">Permanent Storage Removal</span>
      </div>
    </div>

    <p>
      Are you sure you want to permanently delete this backup snapshot? This file will be unlinked from the server <code>/backups/</code> directory and cannot be recovered.
    </p>

    <div class="modal-file-box">
      <div>File to delete: <strong id="modalDeleteFilename">-</strong></div>
    </div>

    <form method="POST" action="backup_recovery.php" id="deleteForm">
      <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
      <input type="hidden" name="delete_file" id="deleteFileInput" value="">
      <input type="hidden" name="action_delete_backup" value="1">

      <div class="modal-actions">
        <button type="button" class="btn-modal-cancel" onclick="closeDeleteModal()">Cancel</button>
        <button type="submit" class="btn-modal-danger">
          <i class="fa-solid fa-trash-can"></i> Confirm Delete
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// UI Handling & JavaScript Confirmation Prompts

function handleBackupSubmit(form) {
  const btn = document.getElementById('btnBackupGenerate');
  if (btn) {
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span>Dumping Database...</span>';
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.7';
  }
  return true;
}

function openRestoreModal(filename, size, date) {
  // First, present a browser-level JavaScript confirmation prompt as required:
  const msg = "⚠️ CAUTION: RESTORE DATABASE CONFIRMATION\n\n"
            + "Restoring \"" + filename + "\" will OVERWRITE the active 'food_dash' database.\n"
            + "All recent changes made since this backup will be replaced!\n\n"
            + "Are you sure you want to proceed with this database restore?";
            
  if (!confirm(msg)) {
    return false;
  }

  // Populate modal data for final verification
  document.getElementById('modalRestoreFilename').textContent = filename;
  document.getElementById('modalRestoreSize').textContent = size;
  document.getElementById('modalRestoreDate').textContent = date;
  document.getElementById('restoreFileInput').value = filename;
  
  const modal = document.getElementById('restoreModal');
  if (modal) {
    modal.classList.add('active');
  }
}

function closeRestoreModal() {
  const modal = document.getElementById('restoreModal');
  if (modal) {
    modal.classList.remove('active');
  }
}

function handleRestoreSubmit() {
  const btn = document.getElementById('btnConfirmRestore');
  if (btn) {
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Restoring Database...';
    btn.style.pointerEvents = 'none';
    btn.style.opacity = '0.7';
  }
  return true;
}

function openDeleteModal(filename) {
  if (!confirm("Are you sure you want to delete backup file: " + filename + "?")) {
    return false;
  }
  document.getElementById('modalDeleteFilename').textContent = filename;
  document.getElementById('deleteFileInput').value = filename;
  
  const modal = document.getElementById('deleteModal');
  if (modal) {
    modal.classList.add('active');
  }
}

function closeDeleteModal() {
  const modal = document.getElementById('deleteModal');
  if (modal) {
    modal.classList.remove('active');
  }
}

// Close modal on click outside
window.addEventListener('click', function(e) {
  const rModal = document.getElementById('restoreModal');
  const dModal = document.getElementById('deleteModal');
  if (e.target === rModal) closeRestoreModal();
  if (e.target === dModal) closeDeleteModal();
});
</script>

</body>
</html>
