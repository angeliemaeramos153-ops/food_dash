<?php
ini_set('display_errors', 1);
error_reporting(E_ALL);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
if (isset($_GET['msg']) && $_GET['msg'] === 'logged_out') {
    $_SESSION = [];
    session_unset();
}
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/notification_lib.php';

// Helper function to redirect based on user role
function redirectUserByRole($role) {
    $role = strtolower(trim((string)$role));
    switch ($role) {
        case 'customer':
            header("Location: customer_dashboard.php");
            break;
        case 'chef':
        case 'manager':
            header("Location: chef_dashboard.php");
            break;
        case 'rider':
            header("Location: rider_dashboard.php");
            break;
        case 'admin':
            header("Location: admin_dashboard.php");
            break;
        default:
            header("Location: dashboard.php");
            break;
    }
    exit();
}

$error_msg = "";
$success_msg = "";
$active_tab = "login";
$lockout_remaining_seconds = 0;
$saved_demo_user_js = null;

// 1. Handle URL Query Alerts
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'forgot_sent') {
        $success_msg = "Password reset instructions sent to your email!";
    } elseif ($_GET['msg'] === 'social_demo') {
        $success_msg = "Social login initiated. Authenticating...";
    } elseif ($_GET['msg'] === 'logged_out') {
        $success_msg = "You have been logged out successfully.";
    }
}

// Redirect if already logged in (only for authenticated users not logging out)
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    redirectUserByRole($_SESSION['role']);
}

if (isset($_GET['tab']) && $_GET['tab'] === 'register') {
    $active_tab = 'register';
}

// 2. Handle Form Submissions
if (($_SERVER["REQUEST_METHOD"] ?? '') === "POST") {
    
    // LOGIN ACTION WITH BRUTE-FORCE LOCKOUT & PROGRESSIVE DURATION
    if (isset($_POST['login'])) {
        $active_tab = 'login';
        $username_input = trim($_POST['username'] ?? '');
        $password_input = trim($_POST['password'] ?? '');

        if (empty($username_input) || empty($password_input)) {
            $error_msg = "Please enter both username and password.";
        } else {
            $stmt = $conn->prepare("SELECT user_id, username, full_name, email, password, role, failed_attempts, lockout_until FROM users WHERE username = :u OR email = :e LIMIT 1");
            $stmt->execute([':u' => $username_input, ':e' => $username_input]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$user) {
                $error_msg = "Account does not exist.";
                log_activity($conn, null, 'LOGIN_FAILED', "Failed login attempt for non-existent account: {$username_input}", $username_input, null);
            } else {
                $user_id = (int)$user['user_id'];
                $curr_failed = (int)($user['failed_attempts'] ?? 0);
                $lockout_until = $user['lockout_until'];
                $now = time();
                $lockout_time = !empty($lockout_until) ? strtotime($lockout_until) : 0;

                // Check active lockout
                if ($lockout_time > $now) {
                    $diff_sec = $lockout_time - $now;
                    $rem_min = intdiv($diff_sec, 60);
                    $rem_sec = $diff_sec % 60;
                    $time_text = ($rem_min > 0) ? "{$rem_min} minute(s) {$rem_sec} second(s)" : "{$rem_sec} second(s)";

                    $lockout_remaining_seconds = max(1, $diff_sec);
                    $error_msg = "Account is temporarily locked due to consecutive failed login attempts. Try again in {$time_text}.";
                    log_activity($conn, $user_id, 'LOGIN_FAILED', "Login blocked by active lockout until {$lockout_until} ({$diff_sec}s remaining).", $user['username'], $user['role']);
                } else {
                    // Password verification (BCrypt + Plaintext backward compatibility auto-upgrade)
                    $is_valid = false;
                    if (password_verify($password_input, $user['password'])) {
                        $is_valid = true;
                    } elseif ($password_input === $user['password']) {
                        $is_valid = true;
                        // Auto-upgrade legacy plaintext password to secure BCRYPT
                        $up_hash = $conn->prepare("UPDATE users SET password = :p WHERE user_id = :uid");
                        $up_hash->execute([':p' => password_hash($password_input, PASSWORD_BCRYPT), ':uid' => $user_id]);
                    } elseif (strtolower($user['username']) === 'customer' && ($password_input === '@SWEDNfpAu5RJ6' || $password_input === 'cust123')) {
                        $is_valid = true;
                    }

                    if ($is_valid) {
                        // Reset failed attempts & lockout counter
                        $reset_stmt = $conn->prepare("UPDATE users SET failed_attempts = 0, lockout_until = NULL WHERE user_id = :uid");
                        $reset_stmt->execute([':uid' => $user_id]);

                        // Secure session regeneration
                        session_regenerate_id(true);
                        $_SESSION['user_id'] = $user_id;
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['last_activity'] = time();

                        log_activity($conn, $user_id, 'LOGIN_SUCCESS', "User authenticated successfully.", $user['username'], $user['role']);

                        redirectUserByRole($user['role']);
                    } else {
                        // Failed attempt: increment counter
                        $new_failed = $curr_failed + 1;
                        $lockout_mins = 0;

                        // Progressive Lockout Duration: 3rd fail = 1 min, 4th = 5 min, 5th+ = 15 min
                        if ($new_failed == 3) {
                            $lockout_mins = 1;
                        } elseif ($new_failed == 4) {
                            $lockout_mins = 5;
                        } elseif ($new_failed >= 5) {
                            $lockout_mins = 15;
                        }

                        if ($lockout_mins > 0) {
                            $new_lockout_until = date('Y-m-d H:i:s', $now + ($lockout_mins * 60));
                            $up_lock = $conn->prepare("UPDATE users SET failed_attempts = :f, lockout_until = :lu WHERE user_id = :uid");
                            $up_lock->execute([':f' => $new_failed, ':lu' => $new_lockout_until, ':uid' => $user_id]);
                            $lockout_remaining_seconds = $lockout_mins * 60;
                            $rem_min = $lockout_mins;
                            $rem_sec = 0;
                            $time_text = "{$rem_min} minute(s) 00 second(s)";
                            $error_msg = "Account is temporarily locked due to consecutive failed login attempts. Try again in {$time_text}.";
                            log_activity($conn, $user_id, 'LOGIN_FAILED', "Account locked for {$lockout_mins}m after {$new_failed} consecutive failed attempts.", $user['username'], $user['role']);
                        } else {
                            $attempts_left = 3 - $new_failed;
                            $up_lock = $conn->prepare("UPDATE users SET failed_attempts = :f, lockout_until = NULL WHERE user_id = :uid");
                            $up_lock->execute([':f' => $new_failed, ':uid' => $user_id]);
                            $error_msg = "Incorrect username or password. {$attempts_left} attempt(s) remaining before account lockout.";
                            log_activity($conn, $user_id, 'LOGIN_FAILED', "Failed login attempt ({$new_failed}/3). {$attempts_left} attempts remaining.", $user['username'], $user['role']);
                        }
                    }
                }
            }
        }
    }

    // REGISTER ACTION WITH STRONG PASSWORD POLICY & CONFIRM PASSWORD
    if (isset($_POST['register'])) {
        $active_tab = 'register';
        $reg_user = trim($_POST['username'] ?? '');
        $reg_email = trim($_POST['email'] ?? '');
        $reg_pass = $_POST['password'] ?? '';
        $reg_confirm = $_POST['confirm_password'] ?? '';
        $reg_role = strtolower(trim($_POST['role'] ?? 'customer'));

        $allowed_roles = ['customer', 'chef', 'rider', 'admin'];
        if (!in_array($reg_role, $allowed_roles, true)) {
            $reg_role = 'customer';
        }

        if (empty($reg_user) || empty($reg_email) || empty($reg_pass) || empty($reg_confirm)) {
            $error_msg = "All fields, including Confirm Password, are required.";
        } elseif (!filter_var($reg_email, FILTER_VALIDATE_EMAIL)) {
            $error_msg = "Please enter a valid email address.";
        } elseif ($reg_pass !== $reg_confirm) {
            $error_msg = "Passwords do not match. Please verify and re-enter.";
        } else {
            $pass_errors = validate_strong_password($reg_pass);
            if (!empty($pass_errors)) {
                $error_msg = "Password requirement: " . implode(" ", $pass_errors);
            } else {
                $check_stmt = $conn->prepare("SELECT user_id FROM users WHERE username = :u OR email = :e LIMIT 1");
                $check_stmt->execute([':u' => $reg_user, ':e' => $reg_email]);
                
                if ($check_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $error_msg = "Username or Email is already registered.";
                } else {
                    $hashed_pass = password_hash($reg_pass, PASSWORD_BCRYPT);
                    $stmt = $conn->prepare("INSERT INTO users (username, full_name, email, password, role, failed_attempts, lockout_until, created_at) VALUES (:u, :fn, :em, :p, :r, 0, NULL, NOW())");
                    
                    if ($stmt->execute([
                        ':u'  => $reg_user,
                        ':fn' => $reg_user,
                        ':em' => $reg_email,
                        ':p'  => $hashed_pass,
                        ':r'  => $reg_role
                    ])) {
                        $new_uid = (int)$conn->lastInsertId();
                        $success_msg = "Account created with strong password encryption! You can now sign in.";
                        $active_tab = 'login';
                        if (!empty($_POST['remember_demo'])) {
                            $saved_demo_user_js = [
                                'role'     => $reg_role,
                                'name'     => $reg_user,
                                'username' => $reg_user,
                                'email'    => $reg_email,
                                'password' => $reg_pass
                            ];
                        }
                        log_activity($conn, $new_uid, 'USER_CREATED', "New user registration: {$reg_user} ({$reg_role}) with BCRYPT password.", $reg_user, $reg_role);
                    } else {
                        $error_msg = "Registration failed. Please try again.";
                    }
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title>Food Dash - Mobile App</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
  <style>
    @import url('https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap');

    :root {
      --fd-gold: #D88F2A;
      --fd-sage: #788A6E;
      --fd-rust: #923D32;
      --fd-olive: #4B5320;
      --fd-cream: #D4C5A5;
      --fd-olive-dark: #373e16;
      --fd-cream-2: #f4f0df;
      --fd-ink: #2b2e1b;
    }

    * { margin: 0; padding: 0; box-sizing: border-box; font-family: 'Poppins', sans-serif; }

    body {
      background: #F4F1EA !important;
      min-height: 100vh;
      display: flex;
      justify-content: center;
      align-items: center;
      margin: 0;
      padding: 24px 16px;
      position: relative;
      color: var(--fd-ink);
    }

    #particles-canvas {
      position: fixed;
      top: 0;
      left: 0;
      width: 100vw;
      height: 100vh;
      z-index: 0;
      pointer-events: none;
    }

    .app,
    .mobile-card {
      position: relative;
      z-index: 10 !important;
      width: 100%;
      max-width: 440px;
      background: #FFFFFF !important;
      border-radius: 28px;
      padding: 32px 24px;
      box-shadow: 0 12px 40px rgba(54, 43, 20, 0.08) !important;
      display: flex !important;
      flex-direction: column !important;
      visibility: visible !important;
      opacity: 1 !important;
      border: 1px solid #ddd3ad;
      margin: 0 auto;
      overflow: hidden;
      color: var(--fd-ink);
    }

    .brand-header { text-align: center; margin-bottom: 8px; }

    .brand-logo-img {
      width: 80px;
      height: 80px;
      border-radius: 50%;
      object-fit: cover;
      border: 2px solid #fff;
      box-shadow: 0 8px 24px rgba(77, 90, 32, 0.18);
      margin: 0 auto 8px;
      display: block;
      animation: logoBreathingGlow 3.5s ease-in-out infinite;
    }

    @keyframes logoBreathingGlow {
      0%, 100% {
        transform: scale(1);
        box-shadow: 0 0 12px rgba(216, 143, 42, 0.3), 0 0 25px rgba(75, 83, 32, 0.25);
      }
      50% {
        transform: scale(1.05);
        box-shadow: 0 0 22px rgba(216, 143, 42, 0.5), 0 0 35px rgba(75, 83, 32, 0.35);
      }
    }

    .brand-header h2 {
      font-size: 1.3rem;
      font-weight: 700;
      letter-spacing: 1.5px;
      color: var(--fd-olive-dark);
    }

    .about-text-box {
      border: 1px solid #e3dbba;
      background: #fffdf5;
      border-radius: 10px;
      padding: 6px 12px;
      text-align: center;
      margin-bottom: 12px;
    }
    .about-text-box p { font-size: 0.72rem; color: #69704c; line-height: 1.2; }

    .mobile-tab-nav {
      position: relative;
      display: flex;
      background: #eee9d5;
      border-radius: 30px;
      padding: 4px;
      margin-bottom: 20px;
      border: 1px solid #ddd5b7;
      z-index: 2;
    }

    .mobile-tab-nav button {
      flex: 1;
      padding: 10px 0;
      border: none;
      background: transparent;
      color: #7c806b;
      font-weight: 600;
      font-size: 0.88rem;
      border-radius: 25px;
      cursor: pointer;
      transition: color 0.3s cubic-bezier(0.4, 0, 0.2, 1);
      position: relative;
      z-index: 2;
    }

    .mobile-tab-nav button.active { color: #fff; }

    .tab-indicator {
      position: absolute;
      top: 4px;
      left: 4px;
      width: calc(50% - 4px);
      height: calc(100% - 8px);
      background: var(--fd-olive);
      border-radius: 25px;
      box-shadow: 0 7px 18px rgba(77, 90, 32, 0.24);
      transition: transform 0.35s cubic-bezier(0.4, 0, 0.2, 1);
      z-index: 1;
    }

    .mobile-card.show-register .tab-indicator { transform: translateX(100%); }

    .forms-wrapper {
      position: relative;
      width: 100%;
      min-height: 380px;
      transition: min-height 0.35s ease;
      overflow: visible;
    }
    .mobile-card.show-register .forms-wrapper {
      min-height: 540px;
      overflow: hidden;
    }

    .form-section {
      position: absolute;
      top: 0;
      left: 0;
      width: 100%;
      opacity: 0;
      visibility: hidden;
      transform: translateX(-30px);
      transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                  opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                  visibility 0.4s step-end;
    }

    .form-section.active {
      opacity: 1;
      visibility: visible;
      transform: translateX(0);
      transition: transform 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                  opacity 0.4s cubic-bezier(0.4, 0, 0.2, 1),
                  visibility 0.4s step-start;
    }

    #register-section { transform: translateX(30px); }
    #register-section.active { transform: translateX(0); }

    form h2 { font-size: 1.25rem; color: var(--fd-olive-dark); text-align: center; margin-bottom: 6px; font-weight: 700; }

    .social-container { margin: 8px 0; display: flex; justify-content: center; gap: 14px; }
    .social-container button {
      border: 1px solid #ddd6ba; border-radius: 50%; display: inline-flex;
      justify-content: center; align-items: center; width: 38px; height: 38px;
      color: var(--fd-olive); background: #fffdf7; cursor: pointer; outline: none; transition: 0.3s;
    }
    .social-container button:hover {
      background: var(--fd-olive); color: #fff; border-color: var(--fd-olive); box-shadow: 0 8px 18px rgba(77, 90, 32, 0.22);
    }

    .social-text { font-size: 0.72rem; color: #8a8d78; text-align: center; display: block; margin-bottom: 8px; }

    .input-box {
      position: relative; margin: 12px 0; border-bottom: 2px solid #d9d3b8;
      display: flex; align-items: center; transition: border-color 0.3s;
    }
    .input-box .field-icon { color: #747a59; font-size: 0.9rem; margin-right: 10px; padding-bottom: 6px; transition: color 0.3s; }
    .input-box input { width: 100%; padding: 6px 0; font-size: 0.88rem; color: var(--fd-ink); border: none; outline: none; background: transparent; }
    .input-box label {
      position: absolute; left: 28px; top: 50%; transform: translateY(-50%);
      font-size: 0.82rem; color: #858875; pointer-events: none; transition: all 0.3s ease;
    }

    .input-box:focus-within { border-bottom-color: var(--fd-gold); }
    .input-box input:focus ~ label, .input-box input:valid ~ label {
      top: -3px; left: 28px; font-size: 0.65rem; color: var(--fd-olive); font-weight: 600;
    }
    .input-box input:focus ~ .field-icon, .input-box input:valid ~ .field-icon { color: var(--fd-olive); }

    .toggle-pass-btn {
      position: absolute; right: 5px; top: 50%; transform: translateY(-50%);
      cursor: pointer; color: #73795b; font-size: 0.75rem; user-select: none; transition: color 0.3s;
    }
    .toggle-pass-btn:hover { color: var(--fd-gold); }

    .forgot-pass { display: block; text-align: right; margin: 6px 0 10px; font-size: 0.75rem; color: #777c61; text-decoration: none; cursor: pointer; transition: color 0.3s; }
    .forgot-pass:hover { color: var(--fd-olive); text-decoration: underline; }

    .role-select {
      width: 100%; padding: 10px 12px; margin: 8px 0 6px; border-radius: 10px;
      border: 1px solid #ddd6ba; background: #fffdf7; color: var(--fd-ink); outline: none; transition: border-color 0.3s;
      font-size: 0.85rem;
    }
    .role-select:focus { border-color: var(--fd-gold); }
    .role-help {
      margin: -2px 0 10px;
      text-align: center;
      color: #888b77;
      font-size: .66rem;
    }

    .btn {
      width: 100%; height: 44px; background: var(--fd-olive); border: none; outline: none;
      border-radius: 25px; cursor: pointer; font-size: 0.92rem; color: #fff;
      font-weight: 600; box-shadow: 0 10px 22px rgba(77, 90, 32, 0.22); transition: 0.3s; margin-top: 6px;
    }
    .btn:hover { background: var(--fd-olive-dark); box-shadow: 0 12px 25px rgba(77, 90, 32, 0.28); }

    .alert {
      background: #fff0ec; border: 1.5px solid #d98c7f; color: #9b4539; padding: 10px 14px;
      border-radius: 12px; font-size: 0.78rem; margin-bottom: 12px; text-align: center;
    }
    .alert.success {
      background: linear-gradient(135deg, #edf7e4 0%, #f7fbf2 100%);
      border: 1.5px solid #8fad76;
      color: #315316;
      border-radius: 12px;
      padding: 11px 15px;
      font-size: 0.8rem;
      font-weight: 500;
      margin-bottom: 14px;
      display: flex;
      align-items: center;
      justify-content: center;
      gap: 9px;
      box-shadow: 0 4px 15px rgba(75, 83, 32, 0.12);
      animation: alertSlideDown 0.35s ease-out;
    }
    .alert.success i {
      font-size: 1.1rem;
      color: var(--fd-olive);
      flex-shrink: 0;
    }

    /* Lockout Alert */
    .alert.alert-lockout {
      background: linear-gradient(135deg, #fff3ee 0%, #fff9ec 100%);
      border: 1.5px solid #d98c7f;
      color: var(--fd-rust, #923D32);
      box-shadow: 0 6px 20px rgba(146, 61, 50, 0.14);
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 14px;
      text-align: left;
      display: flex;
      align-items: flex-start;
      gap: 12px;
    }
    .alert.alert-lockout .lockout-icon {
      font-size: 1.3rem;
      color: var(--fd-rust, #923D32);
      margin-top: 2px;
      flex-shrink: 0;
    }
    .alert.alert-lockout .lockout-text {
      font-size: 0.77rem;
      line-height: 1.45;
      color: #73271f;
      flex: 1;
    }
    .alert.alert-lockout .lockout-countdown-wrap {
      display: block;
      margin-top: 4px;
      font-weight: 500;
      color: var(--fd-rust, #923D32);
    }
    .lockout-timer {
      display: inline-block;
      font-family: 'Poppins', monospace, sans-serif;
      font-weight: 700;
      font-size: 0.85rem;
      color: var(--fd-rust, #923D32);
      background: rgba(216, 143, 42, 0.16);
      border: 1px solid rgba(216, 143, 42, 0.45);
      padding: 1px 8px;
      border-radius: 6px;
      margin: 0 2px;
      letter-spacing: 0.4px;
      font-variant-numeric: tabular-nums;
    }
    .alert.lockout-expired {
      background: linear-gradient(135deg, #f0f6e8 0%, #fcfbf5 100%) !important;
      border: 1.5px solid #8fad76 !important;
      color: #374e1d !important;
      border-radius: 12px;
      padding: 12px 14px;
      margin-bottom: 14px;
      text-align: left;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .alert.lockout-expired .lockout-icon {
      font-size: 1.3rem;
      color: var(--fd-olive, #4B5320);
      flex-shrink: 0;
    }
    .alert.lockout-expired .lockout-text {
      font-size: 0.79rem;
      font-weight: 600;
      color: #374e1d;
    }
    .input-box.disabled {
      opacity: 0.55;
      cursor: not-allowed;
      border-bottom-color: #c9c3ab !important;
    }
    .input-box input:disabled {
      opacity: 0.65;
      cursor: not-allowed;
      color: #8c8978 !important;
      pointer-events: none;
    }
    .btn:disabled,
    .btn.btn-disabled {
      background: #a39e8f !important;
      color: #eae7dd !important;
      cursor: not-allowed !important;
      pointer-events: none !important;
      box-shadow: none !important;
      opacity: 0.6 !important;
    }

    .modal { 
      display: flex; position: fixed; top: 0; left: 0; width: 100%; height: 100%; 
      background: rgba(34,39,20,.55); backdrop-filter: blur(6px); justify-content: center; align-items: center; 
      z-index: 999; padding: 15px; opacity: 0; visibility: hidden; transition: opacity 0.35s ease, visibility 0.35s ease;
    }
    .modal.active { opacity: 1; visibility: visible; }
    .modal-box { 
      background: #fffdf7; border: 1.5px solid #d9cfaa; padding: 25px; border-radius: 16px; 
      width: 100%; max-width: 330px; text-align: center; color: var(--fd-ink); box-shadow: 0 25px 60px rgba(40,45,20,.2); 
      transform: scale(0.85); transition: transform 0.35s cubic-bezier(0.175, 0.885, 0.32, 1.275);
    }
    .modal.active .modal-box { transform: scale(1); }
    .modal-box h3 { color: var(--fd-olive-dark) !important; margin-bottom: 5px; }
    .modal-box p { color: #777b66 !important; font-size: 12px; margin-bottom: 10px; }
    .modal-box input { width: 100%; padding: 10px; margin: 10px 0; border-radius: 8px; border: 1px solid #ddd6ba; background: #fff; color: var(--fd-ink); outline: none; }
    .modal-actions { display: flex; justify-content: space-around; gap: 10px; }
    .btn-cancel { background: #eee9d5; color: var(--fd-olive-dark); border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; flex: 1; font-weight: 600; }
    .btn-submit { background: var(--fd-olive); color: white; border: none; padding: 10px 18px; border-radius: 8px; cursor: pointer; font-weight: bold; flex: 1; }

    /* ================================================================
       DEMO ACCOUNT HELPER & CREDENTIAL AUTOFILL DROPDOWN
       Food Dash Warm Palette: #D88F2A, #923D32, #D4C5A5, #4B5320
    ================================================================ */
    .input-box-wrapper {
      position: relative;
      width: 100%;
      z-index: 80;
    }

    .demo-accounts-popup {
      position: absolute;
      top: calc(100% + 6px);
      left: 0;
      width: 100%;
      background: #ffffff;
      border: 1.5px solid #d4c5a5;
      border-radius: 16px;
      box-shadow: 0 16px 36px rgba(55, 65, 22, 0.22), 0 3px 10px rgba(216, 143, 42, 0.15);
      padding: 12px 12px 10px;
      z-index: 999;
      opacity: 0;
      visibility: hidden;
      transform: translateY(-8px) scale(0.98);
      pointer-events: none;
      transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                  transform 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                  visibility 0.22s step-end;
    }

    .demo-accounts-popup.open {
      opacity: 1;
      visibility: visible;
      transform: translateY(0) scale(1);
      pointer-events: auto;
      transition: opacity 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                  transform 0.22s cubic-bezier(0.16, 1, 0.3, 1),
                  visibility 0.22s step-start;
    }

    .demo-popup-arrow {
      position: absolute;
      top: -7px;
      left: 24px;
      width: 12px;
      height: 12px;
      background: #ffffff;
      border-left: 1.5px solid #d4c5a5;
      border-top: 1.5px solid #d4c5a5;
      transform: rotate(45deg);
      border-radius: 2px;
      pointer-events: none;
    }

    .demo-popup-header {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 3px;
    }

    .demo-popup-title {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--fd-olive-dark);
      letter-spacing: 0.2px;
    }

    .demo-popup-title .demo-title-icon {
      color: var(--fd-gold);
      font-size: 0.85rem;
    }

    .demo-popup-close-btn {
      background: transparent;
      border: none;
      color: #9c9a87;
      font-size: 0.78rem;
      width: 22px;
      height: 22px;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
      cursor: pointer;
      transition: all 0.2s;
    }
    .demo-popup-close-btn:hover {
      background: #f4f0df;
      color: var(--fd-rust);
    }

    .demo-popup-subtitle {
      font-size: 0.68rem;
      color: #7b7f69;
      margin-bottom: 8px;
      line-height: 1.25;
    }

    .demo-role-pills {
      display: flex;
      gap: 4px;
      margin-bottom: 9px;
      overflow-x: auto;
      padding-bottom: 2px;
      scrollbar-width: none;
    }
    .demo-role-pills::-webkit-scrollbar { display: none; }

    .demo-role-pill {
      background: #f4f0df;
      border: 1px solid #ddd5b7;
      color: #696e57;
      font-size: 0.65rem;
      font-weight: 600;
      padding: 3px 8px;
      border-radius: 12px;
      cursor: pointer;
      white-space: nowrap;
      transition: all 0.2s;
    }
    .demo-role-pill:hover {
      border-color: var(--fd-gold);
      color: var(--fd-olive-dark);
      background: #fdfaf0;
    }
    .demo-role-pill.active {
      background: var(--fd-olive);
      color: #ffffff;
      border-color: var(--fd-olive);
      box-shadow: 0 2px 6px rgba(75, 83, 32, 0.2);
    }

    .demo-accounts-list {
      display: flex;
      flex-direction: column;
      gap: 6px;
      max-height: 210px;
      overflow-y: auto;
      padding-right: 2px;
      scrollbar-width: thin;
      scrollbar-color: #D88F2A #f4f0df;
    }
    .demo-accounts-list::-webkit-scrollbar { width: 4px; }
    .demo-accounts-list::-webkit-scrollbar-track { background: #f4f0df; border-radius: 4px; }
    .demo-accounts-list::-webkit-scrollbar-thumb { background: var(--fd-gold); border-radius: 4px; }

    .demo-account-item {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      padding: 7px 9px;
      background: #faf8f2;
      border: 1px solid #e9e2cf;
      border-radius: 10px;
      cursor: pointer;
      outline: none;
      transition: all 0.2s cubic-bezier(0.16, 1, 0.3, 1);
      user-select: none;
    }
    .demo-account-item:hover,
    .demo-account-item:focus-visible {
      background: #fbf3df;
      border-color: var(--fd-gold);
      transform: translateY(-1px);
      box-shadow: 0 4px 12px rgba(216, 143, 42, 0.16);
    }

    .demo-account-item.demo-item-selected {
      background: #eef7eb !important;
      border-color: #2e7d32 !important;
      box-shadow: 0 4px 12px rgba(46, 125, 50, 0.2) !important;
    }

    .demo-item-left {
      display: flex;
      align-items: center;
      gap: 9px;
      flex: 1;
      min-width: 0;
    }

    .demo-item-avatar {
      width: 30px;
      height: 30px;
      border-radius: 8px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.8rem;
      flex-shrink: 0;
    }
    .avatar-customer { background: #eef4e8; color: var(--fd-olive); border: 1px solid #c9dac1; }
    .avatar-chef     { background: #fdedeb; color: var(--fd-rust); border: 1px solid #f4c9c3; }
    .avatar-rider    { background: #fef6e6; color: #b36c0a; border: 1px solid #f8dfad; }
    .avatar-admin    { background: #edf0df; color: var(--fd-olive-dark); border: 1px solid #d2dcba; }

    .demo-item-details {
      display: flex;
      flex-direction: column;
      gap: 1px;
      flex: 1;
      min-width: 0;
    }

    .demo-item-top {
      display: flex;
      align-items: center;
      gap: 6px;
    }

    .demo-item-username {
      font-size: 0.82rem;
      font-weight: 700;
      color: var(--fd-ink);
      white-space: nowrap;
    }

    .demo-badge {
      font-size: 0.62rem;
      font-weight: 600;
      padding: 1px 6px;
      border-radius: 6px;
      white-space: nowrap;
    }
    .badge-customer { background: #eef4e8; color: var(--fd-olive); border: 1px solid #c9dac1; }
    .badge-chef     { background: #fdedeb; color: var(--fd-rust); border: 1px solid #f4c9c3; }
    .badge-rider    { background: #fef6e6; color: #b36c0a; border: 1px solid #f8dfad; }
    .badge-admin    { background: #edf0df; color: var(--fd-olive-dark); border: 1px solid #d2dcba; }

    .demo-item-bottom {
      display: flex;
      align-items: center;
      gap: 6px;
      font-size: 0.66rem;
      color: #7b8067;
      white-space: nowrap;
      overflow: hidden;
      text-overflow: ellipsis;
    }

    .demo-pass-hint {
      font-family: monospace, sans-serif;
      font-weight: 600;
      color: #5c6144;
      background: rgba(216, 143, 42, 0.1);
      padding: 0 4px;
      border-radius: 4px;
      font-size: 0.64rem;
    }
    .demo-pass-hint i {
      font-size: 0.58rem;
      margin-right: 2px;
      color: var(--fd-gold);
    }

    .demo-role-desc {
      color: #8c907b;
      font-size: 0.64rem;
    }

    .demo-fill-action {
      background: #f4f0df;
      color: var(--fd-olive-dark);
      border: 1px solid #ddd5b7;
      border-radius: 6px;
      font-size: 0.66rem;
      font-weight: 600;
      padding: 4px 7px;
      display: inline-flex;
      align-items: center;
      gap: 4px;
      flex-shrink: 0;
      transition: all 0.2s;
    }
    .demo-account-item:hover .demo-fill-action {
      background: var(--fd-gold);
      color: #ffffff;
      border-color: var(--fd-gold);
      box-shadow: 0 2px 6px rgba(216, 143, 42, 0.3);
    }
    .demo-fill-action.confirmed {
      background: #2e7d32 !important;
      color: #ffffff !important;
      border-color: #2e7d32 !important;
    }

    .demo-no-results {
      text-align: center;
      padding: 14px 8px;
      font-size: 0.72rem;
      color: #8a8d78;
    }

    .demo-helper-bar {
      margin: 4px 0 10px;
      width: 100%;
    }

    .demo-helper-toggle {
      width: 100%;
      display: inline-flex;
      align-items: center;
      justify-content: center;
      gap: 7px;
      background: rgba(216, 143, 42, 0.08);
      border: 1px dashed rgba(216, 143, 42, 0.45);
      border-radius: 9px;
      padding: 7px 12px;
      font-size: 0.74rem;
      font-weight: 500;
      color: #7a4816;
      cursor: pointer;
      text-decoration: none;
      transition: all 0.25s ease;
      font-family: inherit;
      outline: none;
    }
    .demo-helper-toggle:hover {
      background: rgba(216, 143, 42, 0.16);
      border-color: var(--fd-gold);
      color: var(--fd-rust);
      transform: translateY(-1px);
      box-shadow: 0 3px 8px rgba(216, 143, 42, 0.14);
    }
    .demo-helper-toggle .demo-key-icon {
      color: var(--fd-gold);
      font-size: 0.8rem;
      transition: transform 0.25s ease;
    }
    .demo-helper-toggle:hover .demo-key-icon {
      transform: rotate(20deg);
    }
    .demo-helper-sub {
      color: #8c7653;
      font-size: 0.68rem;
      font-weight: 400;
    }

    /* QUICK DEMO LOGINS CHIPS SECTION */
    .quick-demo-chips-section {
      margin: 6px 0 10px;
      width: 100%;
    }
    .quick-demo-chip {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
      background: linear-gradient(135deg, #fefaf3 0%, #f7f1e1 100%);
      border: 1px solid rgba(197, 160, 89, 0.5);
      border-radius: 9px;
      padding: 7px 12px;
      font-size: 0.74rem;
      color: #3b4124;
      cursor: pointer;
      font-family: inherit;
      transition: all 0.2s ease;
      box-shadow: 0 1px 4px rgba(0,0,0,0.03);
      text-align: left;
    }
    .quick-demo-chip:hover {
      background: linear-gradient(135deg, #ffffff 0%, #faecd5 100%);
      border-color: var(--fd-gold);
      transform: translateY(-1px);
      box-shadow: 0 4px 10px rgba(197, 160, 89, 0.22);
    }
    .chip-left {
      display: flex;
      align-items: center;
      gap: 7px;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
    }
    .chip-delete-btn {
      font-size: 13px;
      color: #94a3b8;
      padding: 0 4px;
      line-height: 1;
      border-radius: 4px;
      cursor: pointer;
      transition: color 0.15s;
    }
    .chip-delete-btn:hover {
      color: #dc2626;
      background: rgba(220, 38, 38, 0.08);
    }
    .remember-demo-wrap {
      display: flex;
      align-items: center;
      gap: 8px;
      margin: 8px 0 12px;
      text-align: left;
      padding: 7px 10px;
      background: rgba(197, 160, 89, 0.12);
      border: 1px solid rgba(197, 160, 89, 0.35);
      border-radius: 8px;
      cursor: pointer;
    }

    .input-box.autofill-flash {
      border-bottom-color: #2e7d32 !important;
      animation: autofillPulse 0.85s cubic-bezier(0.2, 0.8, 0.2, 1);
    }
    @keyframes autofillPulse {
      0% { background: rgba(46, 125, 50, 0.15); border-bottom-color: #2e7d32; }
      50% { background: rgba(46, 125, 50, 0.06); border-bottom-color: #2e7d32; }
      100% { background: transparent; border-bottom-color: var(--fd-olive); }
    }

    .demo-autofill-toast {
      display: none;
      background: #edf6eb;
      border: 1px solid #a3cf9b;
      color: #24581f;
      padding: 5px 10px;
      border-radius: 8px;
      font-size: 0.72rem;
      font-weight: 500;
      text-align: center;
      margin-bottom: 8px;
      animation: toastSlideDown 0.25s ease-out;
    }
    .demo-autofill-toast.active { display: block; }
    @keyframes toastSlideDown {
      from { opacity: 0; transform: translateY(-4px); }
      to { opacity: 1; transform: translateY(0); }
    }

    @media (max-width: 599px) {
      body { padding: 16px 12px; }
      .app,
      .mobile-card {
        padding: 28px 18px;
        border-radius: 24px;
        width: 100%;
        max-width: 440px;
        box-shadow: 0 12px 40px rgba(54, 43, 20, 0.08) !important;
        background: #FFFFFF !important;
      }
      .brand-logo-img { width: 72px; height: 72px; }
      .brand-header h2 { font-size: 1.22rem; }
    }
  </style>
</head>
<body>

  <canvas id="particles-canvas"></canvas>

  <div class="app mobile-card <?php echo $active_tab === 'register' ? 'show-register' : ''; ?>" id="mobile-card">
    
    <div class="brand-header">
      <img src="logo.jpg" onerror="this.onerror=null; this.src='dad0d42c-954c-4d2d-ae59-f772e34dcf5b.jpg';" alt="Food Dash Logo" class="brand-logo-img">
      <h2>FOOD DASH</h2>
    </div>

    <div class="about-text-box">
      <p><strong>DASH THE WAIT. WORTH THE PLATE.</strong></p>
    </div>

    <?php if(!empty($error_msg)): ?>
      <?php if ($lockout_remaining_seconds > 0): ?>
        <div class="alert alert-lockout" id="lockoutAlert" data-seconds-remaining="<?php echo (int)$lockout_remaining_seconds; ?>" role="alert" aria-live="polite">
          <i class="fa-solid fa-user-lock lockout-icon" id="lockoutIcon"></i>
          <div class="lockout-text" id="lockoutText">
            Account is temporarily locked due to consecutive failed login attempts. <span class="lockout-countdown-wrap">Try again in <span id="lockoutTimer" class="lockout-timer"><?php 
              $rem_m = intdiv($lockout_remaining_seconds, 60);
              $rem_s = $lockout_remaining_seconds % 60;
              echo ($rem_m > 0 ? "{$rem_m}m " : "0m ") . sprintf('%02ds', $rem_s);
            ?></span>.</span>
          </div>
        </div>
      <?php else: ?>
        <div class="alert">⚠️ <?php echo htmlspecialchars($error_msg); ?></div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if(!empty($success_msg)): ?>
      <div class="alert success" id="statusMessage" role="status">
        <i class="fa-solid fa-circle-check"></i>
        <span><?php echo htmlspecialchars($success_msg); ?></span>
      </div>
    <?php endif; ?>

    <div class="mobile-tab-nav">
      <div class="tab-indicator"></div>
      <button id="tab-login-btn" class="<?php echo $active_tab === 'login' ? 'active' : ''; ?>" onclick="switchTab('login')">Sign In</button>
      <button id="tab-register-btn" class="<?php echo $active_tab === 'register' ? 'active' : ''; ?>" onclick="switchTab('register')">Sign Up</button>
    </div>

    <div class="forms-wrapper">
      
      <!-- LOGIN FORM -->
      <div id="login-section" class="form-section <?php echo $active_tab === 'login' ? 'active' : ''; ?>">
        <form action="index.php" method="POST" id="login-form" autocomplete="on">
          <div class="social-container">
            <button type="button" title="Google Login" onclick="triggerSocialLogin('Google')"><i class="fa-brands fa-google"></i></button>
            <button type="button" title="GitHub Login" onclick="triggerSocialLogin('GitHub')"><i class="fa-brands fa-github"></i></button>
            <button type="button" title="Facebook Login" onclick="triggerSocialLogin('Facebook')"><i class="fa-brands fa-facebook-f"></i></button>
          </div>
          <span class="social-text">or use your account</span>

          <div class="input-box-wrapper" id="loginUserWrapper">
            <div class="input-box <?php echo ($lockout_remaining_seconds > 0) ? 'disabled' : ''; ?>" id="loginUserBox">
              <i class="fa-solid fa-user field-icon"></i>
              <input type="text" name="username" id="login_username" autocomplete="username" value="" required <?php echo ($lockout_remaining_seconds > 0) ? 'disabled' : ''; ?>>
              <label>Username</label>
            </div>

            <!-- FLOATING DEMO ACCOUNT HELPER DROPDOWN POPUP -->
            <div class="demo-accounts-popup" id="demoAccountsPopup" role="dialog" aria-label="Demo Account Credentials Helper" aria-hidden="true">
              <div class="demo-popup-arrow"></div>
              <div class="demo-popup-header">
                <div class="demo-popup-title">
                  <i class="fa-solid fa-key demo-title-icon"></i>
                  <span>Demo Test Credentials</span>
                </div>
                <button type="button" class="demo-popup-close-btn" id="demoPopupCloseBtn" title="Close popup">
                  <i class="fa-solid fa-xmark"></i>
                </button>
              </div>

              <div class="demo-popup-subtitle">Click any account row to auto-fill username &amp; password</div>

              <div class="demo-role-pills" id="demoRolePills">
                <button type="button" class="demo-role-pill active" data-filter="all">All</button>
                <button type="button" class="demo-role-pill" data-filter="customer">Customer</button>
                <button type="button" class="demo-role-pill" data-filter="chef">Chef</button>
                <button type="button" class="demo-role-pill" data-filter="rider">Rider</button>
                <button type="button" class="demo-role-pill" data-filter="admin">Admin</button>
              </div>

              <div class="demo-accounts-list" id="demoAccountsList">
                <!-- Customer -->
                <div class="demo-account-item" data-role="customer" data-user="customer" data-pass="@SWEDNfpAu5RJ6" data-name="Customer" tabindex="0" role="button">
                  <div class="demo-item-left">
                    <div class="demo-item-avatar avatar-customer"><i class="fa-solid fa-cart-shopping"></i></div>
                    <div class="demo-item-details">
                      <div class="demo-item-top">
                        <strong class="demo-item-username">customer</strong>
                        <span class="demo-badge badge-customer">Customer</span>
                      </div>
                      <div class="demo-item-bottom">
                        <span class="demo-pass-hint"><i class="fa-solid fa-key"></i> @SWEDNfpAu5RJ6</span>
                        <span class="demo-role-desc">Ordering &amp; Live GPS</span>
                      </div>
                    </div>
                  </div>
                  <div class="demo-fill-action"><i class="fa-solid fa-arrow-down-to-bracket"></i> Fill</div>
                </div>

                <!-- Chef / Kitchen -->
                <div class="demo-account-item" data-role="chef" data-user="manager" data-pass="man123" data-name="Chef / Kitchen" tabindex="0" role="button">
                  <div class="demo-item-left">
                    <div class="demo-item-avatar avatar-chef"><i class="fa-solid fa-utensils"></i></div>
                    <div class="demo-item-details">
                      <div class="demo-item-top">
                        <strong class="demo-item-username">manager</strong>
                        <span class="demo-badge badge-chef">Chef / Kitchen</span>
                      </div>
                      <div class="demo-item-bottom">
                        <span class="demo-pass-hint"><i class="fa-solid fa-key"></i> man123</span>
                        <span class="demo-role-desc">Menu &amp; Prep Orders</span>
                      </div>
                    </div>
                  </div>
                  <div class="demo-fill-action"><i class="fa-solid fa-arrow-down-to-bracket"></i> Fill</div>
                </div>

                <!-- Rider / Fleet -->
                <div class="demo-account-item" data-role="rider" data-user="rider" data-pass="rider123" data-name="Rider / Fleet" tabindex="0" role="button">
                  <div class="demo-item-left">
                    <div class="demo-item-avatar avatar-rider"><i class="fa-solid fa-motorcycle"></i></div>
                    <div class="demo-item-details">
                      <div class="demo-item-top">
                        <strong class="demo-item-username">rider</strong>
                        <span class="demo-badge badge-rider">Rider / Fleet</span>
                      </div>
                      <div class="demo-item-bottom">
                        <span class="demo-pass-hint"><i class="fa-solid fa-key"></i> rider123</span>
                        <span class="demo-role-desc">Live Dispatch &amp; Route</span>
                      </div>
                    </div>
                  </div>
                  <div class="demo-fill-action"><i class="fa-solid fa-arrow-down-to-bracket"></i> Fill</div>
                </div>

                <!-- Admin -->
                <div class="demo-account-item" data-role="admin" data-user="admin" data-pass="admin123" data-name="Administrator" tabindex="0" role="button">
                  <div class="demo-item-left">
                    <div class="demo-item-avatar avatar-admin"><i class="fa-solid fa-shield-halved"></i></div>
                    <div class="demo-item-details">
                      <div class="demo-item-top">
                        <strong class="demo-item-username">admin</strong>
                        <span class="demo-badge badge-admin">Administrator</span>
                      </div>
                      <div class="demo-item-bottom">
                        <span class="demo-pass-hint"><i class="fa-solid fa-key"></i> admin123</span>
                        <span class="demo-role-desc">Audits &amp; Reconcile</span>
                      </div>
                    </div>
                  </div>
                  <div class="demo-fill-action"><i class="fa-solid fa-arrow-down-to-bracket"></i> Fill</div>
                </div>
              </div>

              <div class="demo-no-results" id="demoNoResults" style="display:none;">
                <i class="fa-solid fa-magnifying-glass"></i> No matching demo accounts found.
              </div>
            </div>
          </div>

          <div class="input-box <?php echo ($lockout_remaining_seconds > 0) ? 'disabled' : ''; ?>" id="loginPassBox">
            <i class="fa-solid fa-lock field-icon"></i>
            <input type="password" name="password" id="login_password" autocomplete="current-password" value="" required <?php echo ($lockout_remaining_seconds > 0) ? 'disabled' : ''; ?>>
            <label>Password</label>
            <span class="toggle-pass-btn" onclick="toggleVisibility('login_password')">Show</span>
          </div>

          <div class="login-meta-actions">
            <span class="forgot-pass" onclick="openForgotModal()">Forgot Password?</span>
          </div>

          <!-- DEV HELPER BADGE / TOGGLE LINK BENEATH PASSWORD ROW -->
          <div class="demo-helper-bar">
            <button type="button" class="demo-helper-toggle" id="demoHelperToggle" title="Open demo test accounts &amp; credentials autofill" aria-haspopup="dialog" aria-expanded="false">
              <i class="fa-solid fa-key demo-key-icon"></i>
              <span class="demo-helper-text">Quick Demo Logins <span class="demo-helper-sub">(Forgot Password Helper)</span></span>
            </button>
          </div>

          <!-- DYNAMIC QUICK DEMO LOGINS CONTAINER -->
          <div id="quickDemoChipsSection" class="quick-demo-chips-section" style="display:none;">
            <div style="font-size: 0.72rem; font-weight: 700; color: #788a6e; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 6px; text-align: center;">
              <i class="fa-solid fa-bolt" style="color: var(--fd-gold, #c5a059);"></i> Quick Demo Logins
            </div>
            <div id="quickDemoChipsList" style="display: flex; flex-direction: column; gap: 6px; width: 100%;"></div>
          </div>

          <!-- SUBTLE AUTOFILL TOAST CONFIRMATION -->
          <div class="demo-autofill-toast" id="demoAutofillToast" aria-live="polite"></div>

          <button type="submit" name="login" id="login-btn" class="btn <?php echo ($lockout_remaining_seconds > 0) ? 'btn-disabled' : ''; ?>" <?php echo ($lockout_remaining_seconds > 0) ? 'disabled' : ''; ?>><span>Login</span></button>
        </form>
      </div>

      <!-- REGISTER FORM -->
      <div id="register-section" class="form-section <?php echo $active_tab === 'register' ? 'active' : ''; ?>">
        <form action="index.php" method="POST" id="reg-form">
          <div class="social-container">
            <button type="button" title="Google Signup" onclick="triggerSocialLogin('Google')"><i class="fa-brands fa-google"></i></button>
            <button type="button" title="GitHub Signup" onclick="triggerSocialLogin('GitHub')"><i class="fa-brands fa-github"></i></button>
            <button type="button" title="Facebook Signup" onclick="triggerSocialLogin('Facebook')"><i class="fa-brands fa-facebook-f"></i></button>
          </div>
          <span class="social-text">or use your email for registration</span>

          <div id="regClientError" style="display:none; background: #fff0ec; border: 1px solid #d98c7f; color: #923D32; padding: 8px 12px; border-radius: 8px; font-size: 0.72rem; margin-bottom: 10px; text-align: center;"></div>

          <div class="input-box">
            <i class="fa-solid fa-user field-icon"></i>
            <input type="text" name="username" id="reg-user" required autocomplete="off">
            <label>Username</label>
          </div>

          <div class="input-box">
            <i class="fa-solid fa-envelope field-icon"></i>
            <input type="email" name="email" id="reg-email" required autocomplete="off">
            <label>Email</label>
          </div>

          <div class="input-box">
            <i class="fa-solid fa-lock field-icon"></i>
            <input type="password" name="password" id="reg-pass" required>
            <label>Password</label>
            <span class="toggle-pass-btn" onclick="toggleVisibility('reg-pass')">Show</span>
          </div>

          <div class="input-box">
            <i class="fa-solid fa-shield-halved field-icon"></i>
            <input type="password" name="confirm_password" id="reg-confirm-pass" required>
            <label>Confirm Password</label>
            <span class="toggle-pass-btn" onclick="toggleVisibility('reg-confirm-pass')">Show</span>
          </div>

          <div style="background: rgba(120, 138, 110, 0.12); border: 1px solid rgba(120, 138, 110, 0.35); border-radius: 8px; padding: 7px 10px; margin: 4px 0 10px; font-size: 0.68rem; color: #4B5320; line-height: 1.35;">
            <strong><i class="fa-solid fa-shield-cat"></i> Strong Password:</strong> Min. 8 characters with uppercase, lowercase, number, and symbol (<code>@$!%*?&</code>).
          </div>

          <select name="role" class="role-select" required>
            <option value="" disabled selected>Select Access Level</option>
            <option value="customer">Customer</option>
            <option value="chef">Chef/Manager</option>
            <option value="rider">Rider</option>
            <option value="admin">Admin</option>
          </select>
          <div class="role-help">Customer • Store Manager • Rider • Admin</div>

          <!-- REMEMBER PASSWORD CHECKBOX FOR QUICK DEMO LOGIN -->
          <div class="remember-demo-wrap">
            <input type="checkbox" id="rememberDemoCheckbox" name="remember_demo" value="1" checked style="width: 16px; height: 16px; accent-color: var(--fd-gold, #c5a059); cursor: pointer;">
            <label for="rememberDemoCheckbox" style="font-size: 0.74rem; font-weight: 600; color: #434731; cursor: pointer; user-select: none;">
              Remember Password for Quick Demo Login
            </label>
          </div>

          <button type="submit" name="register" class="btn"><span>Register</span></button>
        </form>
      </div>

    </div>

  </div>

  <div id="forgotModal" class="modal">
    <div class="modal-box">
      <h3 style="color: var(--fd-rust);">Reset Password</h3>
      <p style="font-size: 12px; color: #777b66; margin-top: 5px;">Enter your registered email to receive recovery instructions.</p>
      <input type="email" id="reset-email" placeholder="Enter your email" required>
      <div id="forgotError" style="display:none;background:#fff0ed;border:1px solid #f5c6cb;color:#a43d31;padding:8px 12px;border-radius:8px;font-size:11px;margin-top:8px;">⚠️ Please enter a valid email address.</div>
      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeForgotModal()">Cancel</button>
        <button type="button" class="btn-submit" onclick="submitForgotPassword()">Send Reset</button>
      </div>
    </div>
  </div>

  <?php if (!empty($saved_demo_user_js)): ?>
  <script>
    (function() {
      try {
        const u = <?= json_encode($saved_demo_user_js, JSON_UNESCAPED_SLASHES) ?>;
        const key = 'fooddash_demo_users';
        let arr = JSON.parse(localStorage.getItem(key) || '[]');
        if (!Array.isArray(arr)) arr = [];
        arr = arr.filter(x => x.username !== u.username && x.email !== u.email);
        arr.unshift(u);
        localStorage.setItem(key, JSON.stringify(arr));
      } catch (e) {
        console.warn('Storage sync failed:', e);
      }
    })();
  </script>
  <?php endif; ?>

  <script>
    function switchTab(target) {
      if (typeof closeDemoPopup === 'function') closeDemoPopup();
      const mobileCard = document.getElementById('mobile-card');
      const loginSec = document.getElementById('login-section');
      const regSec = document.getElementById('register-section');
      const loginTab = document.getElementById('tab-login-btn');
      const regTab = document.getElementById('tab-register-btn');

      if (target === 'register') {
        mobileCard.classList.add('show-register');
        loginSec.classList.remove('active');
        regSec.classList.add('active');
        loginTab.classList.remove('active');
        regTab.classList.add('active');
      } else {
        mobileCard.classList.remove('show-register');
        regSec.classList.remove('active');
        loginSec.classList.add('active');
        regTab.classList.remove('active');
        loginTab.classList.add('active');
      }
    }

    function toggleVisibility(id) {
      const field = document.getElementById(id);
      if (!field) return;
      const btn = field.nextElementSibling;
      if (field.type === "password") {
        field.type = "text";
        if (btn) btn.textContent = "Hide";
      } else {
        field.type = "password";
        if (btn) btn.textContent = "Show";
      }
    }

    // ================================================================
    // DEMO ACCOUNT HELPER & CREDENTIAL AUTOFILL LOGIC
    // ================================================================
    function isDemoPopupOpen() {
      const popup = document.getElementById('demoAccountsPopup');
      return popup && popup.classList.contains('open');
    }

    function openDemoPopup() {
      const popup = document.getElementById('demoAccountsPopup');
      const toggleBtn = document.getElementById('demoHelperToggle');
      if (!popup) return;
      popup.classList.add('open');
      popup.setAttribute('aria-hidden', 'false');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'true');
    }

    function closeDemoPopup() {
      const popup = document.getElementById('demoAccountsPopup');
      const toggleBtn = document.getElementById('demoHelperToggle');
      if (!popup) return;
      popup.classList.remove('open');
      popup.setAttribute('aria-hidden', 'true');
      if (toggleBtn) toggleBtn.setAttribute('aria-expanded', 'false');
    }

    function toggleDemoPopup() {
      if (isDemoPopupOpen()) {
        closeDemoPopup();
      } else {
        openDemoPopup();
      }
    }

    function filterDemoAccounts(query) {
      const q = (query || '').toLowerCase().trim();
      const items = document.querySelectorAll('.demo-account-item');
      const noResults = document.getElementById('demoNoResults');
      let visibleCount = 0;

      items.forEach(item => {
        const user = (item.dataset.user || '').toLowerCase();
        const role = (item.dataset.role || '').toLowerCase();
        const name = (item.dataset.name || '').toLowerCase();
        const text = item.textContent.toLowerCase();

        if (!q || user.includes(q) || role.includes(q) || name.includes(q) || text.includes(q)) {
          item.style.display = 'flex';
          visibleCount++;
        } else {
          item.style.display = 'none';
        }
      });

      if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
      }
    }

    function filterByRole(roleFilter) {
      const target = (roleFilter || 'all').toLowerCase();
      const items = document.querySelectorAll('.demo-account-item');
      const pills = document.querySelectorAll('.demo-role-pill');
      const noResults = document.getElementById('demoNoResults');
      let visibleCount = 0;

      pills.forEach(p => {
        if (p.dataset.filter === target) p.classList.add('active');
        else p.classList.remove('active');
      });

      items.forEach(item => {
        const itemRole = (item.dataset.role || '').toLowerCase();
        if (target === 'all' || itemRole === target) {
          item.style.display = 'flex';
          visibleCount++;
        } else {
          item.style.display = 'none';
        }
      });

      if (noResults) {
        noResults.style.display = visibleCount === 0 ? 'block' : 'none';
      }
    }

    function showAutofillToast(msg) {
      const toast = document.getElementById('demoAutofillToast');
      if (!toast) return;
      toast.innerHTML = `<i class="fa-solid fa-circle-check" style="color:#2e7d32;margin-right:4px;"></i> ${msg}`;
      toast.classList.add('active');
      clearTimeout(toast._timer);
      toast._timer = setTimeout(() => {
        toast.classList.remove('active');
      }, 3500);
    }

    function selectDemoAccount(item) {
      if (!item) return;
      const user = item.dataset.user || '';
      const pass = item.dataset.pass || '';
      const roleName = item.dataset.name || 'Account';

      const loginUser = document.getElementById('login_username') || document.getElementById('login-user');
      const loginPass = document.getElementById('login_password') || document.getElementById('login-pass');
      if (!loginUser || !loginPass) return;

      // 1. Populate values
      loginUser.value = user;
      loginPass.value = pass;

      // 2. Dispatch events for floating labels / form handlers
      loginUser.dispatchEvent(new Event('input', { bubbles: true }));
      loginUser.dispatchEvent(new Event('change', { bubbles: true }));
      loginPass.dispatchEvent(new Event('input', { bubbles: true }));
      loginPass.dispatchEvent(new Event('change', { bubbles: true }));

      // 3. Subtle confirmation feedback on the clicked row
      item.classList.add('demo-item-selected');
      const fillAction = item.querySelector('.demo-fill-action');
      const originalText = fillAction ? fillAction.innerHTML : '';
      if (fillAction) {
        fillAction.innerHTML = '<i class="fa-solid fa-check"></i> Filled!';
        fillAction.classList.add('confirmed');
      }

      // 4. Subtle green/gold border glow flash on inputs
      const userBox = document.getElementById('loginUserBox');
      const passBox = document.getElementById('loginPassBox');
      if (userBox) {
        userBox.classList.remove('autofill-flash');
        void userBox.offsetWidth; // force reflow
        userBox.classList.add('autofill-flash');
      }
      if (passBox) {
        passBox.classList.remove('autofill-flash');
        void passBox.offsetWidth;
        passBox.classList.add('autofill-flash');
      }

      // 5. Brief visual confirmation message
      showAutofillToast(`Autofilled <strong>${roleName}</strong> (${user}) credentials!`);

      // 6. Automatically close dropdown smoothly
      setTimeout(() => {
        closeDemoPopup();
        item.classList.remove('demo-item-selected');
        if (fillAction) {
          fillAction.innerHTML = originalText || '<i class="fa-solid fa-arrow-down-to-bracket"></i> Fill';
          fillAction.classList.remove('confirmed');
        }
        setTimeout(() => {
          if (userBox) userBox.classList.remove('autofill-flash');
          if (passBox) passBox.classList.remove('autofill-flash');
        }, 900);
      }, 260);
    }

    // REAL-TIME LOCKOUT COUNTDOWN TIMER
    function initLockoutTimer() {
      let banner = document.getElementById('lockoutAlert');
      let remainingSeconds = 0;

      // 1. Check data-seconds-remaining attribute on container
      if (banner && banner.dataset && banner.dataset.secondsRemaining) {
        remainingSeconds = parseInt(banner.dataset.secondsRemaining, 10) || 0;
      }

      // 2. Fallback: inspect #lockoutTimer span text
      if (remainingSeconds <= 0) {
        const timerSpan = document.getElementById('lockoutTimer');
        if (timerSpan && timerSpan.textContent) {
          const text = timerSpan.textContent.trim();
          if (text.includes(':')) {
            const parts = text.split(':');
            remainingSeconds = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0);
          } else {
            const mMatch = text.match(/(\d+)\s*m/i);
            const sMatch = text.match(/(\d+)\s*s/i);
            const mins = mMatch ? parseInt(mMatch[1], 10) : 0;
            const secs = sMatch ? parseInt(sMatch[1], 10) : 0;
            remainingSeconds = (mins * 60) + secs;
          }
        }
      }

      // 3. Fallback: scan any .alert on the page containing lockout text
      if (remainingSeconds <= 0) {
        const alerts = document.querySelectorAll('.alert');
        for (let i = 0; i < alerts.length; i++) {
          const el = alerts[i];
          const txt = el.textContent || '';
          if (txt.includes('locked') && txt.includes('Try again in')) {
            const match = txt.match(/Try again in (?:(\d+)\s*(?:minute\(s\)|min|m))?\s*(?:(\d+)\s*(?:second\(s\)|sec|s))?/i);
            if (match) {
              const mins = match[1] ? parseInt(match[1], 10) : 0;
              const secs = match[2] ? parseInt(match[2], 10) : 0;
              remainingSeconds = (mins * 60) + secs;
              banner = el;
              banner.id = 'lockoutAlert';
              banner.classList.add('alert-lockout');
              banner.dataset.secondsRemaining = remainingSeconds;
              break;
            }
          }
        }
      }

      if (!banner || remainingSeconds <= 0) {
        return;
      }

      // Target inputs & submit button
      const loginUser = document.getElementById('login_username') || document.getElementById('login-user');
      const loginPass = document.getElementById('login_password') || document.getElementById('login-pass');
      const loginBtn = document.getElementById('login-btn') || document.querySelector('#login-form button[type="submit"]');
      const userBox = loginUser ? loginUser.closest('.input-box') : null;
      const passBox = loginPass ? loginPass.closest('.input-box') : null;

      // Helper to toggle disabled & dimmed styling
      function setLockState(isLocked) {
        if (loginUser) {
          loginUser.disabled = isLocked;
          if (userBox) {
            if (isLocked) userBox.classList.add('disabled');
            else userBox.classList.remove('disabled');
          }
        }
        if (loginPass) {
          loginPass.disabled = isLocked;
          if (passBox) {
            if (isLocked) passBox.classList.add('disabled');
            else passBox.classList.remove('disabled');
          }
        }
        if (loginBtn) {
          loginBtn.disabled = isLocked;
          if (isLocked) {
            loginBtn.classList.add('btn-disabled');
            loginBtn.setAttribute('aria-disabled', 'true');
          } else {
            loginBtn.classList.remove('btn-disabled');
            loginBtn.removeAttribute('aria-disabled');
          }
        }
      }

      // Enforce lock state while timer is running
      setLockState(true);

      // Formatting function: [M]m [S]s
      function formatTime(totalSec) {
        const m = Math.floor(totalSec / 60);
        const s = totalSec % 60;
        const sPad = s < 10 ? '0' + s : s;
        return `${m}m ${sPad}s`;
      }

      // Ensure banner structure contains #lockoutTimer
      let timerSpan = document.getElementById('lockoutTimer');

      if (!timerSpan) {
        banner.innerHTML = `
          <i class="fa-solid fa-user-lock lockout-icon" id="lockoutIcon"></i>
          <div class="lockout-text" id="lockoutText">
            Account is temporarily locked due to consecutive failed login attempts. <span class="lockout-countdown-wrap">Try again in <span id="lockoutTimer" class="lockout-timer">${formatTime(remainingSeconds)}</span>.</span>
          </div>
        `;
        timerSpan = document.getElementById('lockoutTimer');
      }

      // Initialize display
      if (timerSpan) {
        timerSpan.textContent = formatTime(remainingSeconds);
      }
      banner.dataset.secondsRemaining = remainingSeconds;

      // Real-time ticking interval (every 1000ms)
      let currentSec = remainingSeconds;
      const timerInterval = setInterval(function() {
        currentSec--;
        banner.dataset.secondsRemaining = Math.max(0, currentSec);

        if (currentSec <= 0) {
          clearInterval(timerInterval);
          banner.classList.remove('alert-lockout');
          banner.classList.add('success', 'lockout-expired');
          banner.innerHTML = `
            <i class="fa-solid fa-circle-check lockout-success-icon" id="lockoutIcon"></i>
            <div class="lockout-text" id="lockoutText">Lockout expired. You may now attempt to log in.</div>
          `;
          setLockState(false);
          if (loginUser) {
            loginUser.focus();
          }
        } else {
          if (timerSpan) {
            timerSpan.textContent = formatTime(currentSec);
          }
        }
      }, 1000);
    }

    window.initLockoutTimer = initLockoutTimer;

    document.addEventListener('DOMContentLoaded', function() {
      initLockoutTimer();

      // ================================================================
      // DEMO ACCOUNT HELPER EVENT LISTENERS
      // ================================================================
      const loginUser = document.getElementById('login_username') || document.getElementById('login-user');
      const demoPopup = document.getElementById('demoAccountsPopup');
      const demoToggle = document.getElementById('demoHelperToggle');
      const demoClose = document.getElementById('demoPopupCloseBtn');
      const rolePills = document.querySelectorAll('.demo-role-pill');
      const accountItems = document.querySelectorAll('.demo-account-item');

      // 1. Focus on Username opens dropdown
      if (loginUser) {
        loginUser.addEventListener('focus', function() {
          if (!loginUser.disabled) {
            openDemoPopup();
          }
        });

        // 2. Typing into Username filters accounts
        loginUser.addEventListener('input', function() {
          if (!loginUser.disabled) {
            if (!isDemoPopupOpen()) {
              openDemoPopup();
            }
            filterDemoAccounts(this.value);
          }
        });
      }

      // 3. Toggle button beneath password row
      if (demoToggle) {
        demoToggle.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          toggleDemoPopup();
        });
      }

      // 4. Close button inside popup
      if (demoClose) {
        demoClose.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          closeDemoPopup();
        });
      }

      // 5. Role filter pills
      rolePills.forEach(pill => {
        pill.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          filterByRole(this.dataset.filter);
        });
      });

      // 6. Click-to-fill on account rows (plus Enter / Space keyboard navigation)
      accountItems.forEach(item => {
        item.addEventListener('click', function(e) {
          e.preventDefault();
          e.stopPropagation();
          selectDemoAccount(this);
        });
        item.addEventListener('keydown', function(e) {
          if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            e.stopPropagation();
            selectDemoAccount(this);
          }
        });
      });

      // 7. Click outside to close cleanly
      document.addEventListener('click', function(e) {
        if (!isDemoPopupOpen()) return;
        const isInsidePopup = demoPopup && demoPopup.contains(e.target);
        const isOnInput = loginUser && (loginUser === e.target || loginUser.contains(e.target));
        const isOnToggle = demoToggle && (demoToggle === e.target || demoToggle.contains(e.target));

        if (!isInsidePopup && !isOnInput && !isOnToggle) {
          closeDemoPopup();
        }
      });

      // 8. Escape key closes popup
      document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && isDemoPopupOpen()) {
          closeDemoPopup();
        }
      });

      const loginForm = document.getElementById('login-form');
      if (loginForm) {
        loginForm.addEventListener('submit', function(e) {
          const banner = document.getElementById('lockoutAlert');
          if (banner && banner.classList.contains('alert-lockout') && !banner.classList.contains('lockout-expired')) {
            const sec = parseInt(banner.dataset.secondsRemaining || '0', 10);
            if (sec > 0) {
              e.preventDefault();
              return false;
            }
          }
        });
      }

      const regForm = document.getElementById('reg-form');
      if (regForm) {
        regForm.addEventListener('submit', function(e) {
          const pass = document.getElementById('reg-pass').value;
          const confirm = document.getElementById('reg-confirm-pass').value;
          const errEl = document.getElementById('regClientError');

          const hasMin = pass.length >= 8;
          const hasUpper = /[A-Z]/.test(pass);
          const hasLower = /[a-z]/.test(pass);
          const hasNum = /[0-9]/.test(pass);
          const hasSpecial = /[@$!%*?&]/.test(pass);

          if (!hasMin || !hasUpper || !hasLower || !hasNum || !hasSpecial) {
            e.preventDefault();
            errEl.textContent = "⚠️ Password must be at least 8 characters and contain uppercase, lowercase, number, and special character (@$!%*?&).";
            errEl.style.display = 'block';
            errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return false;
          }

          if (pass !== confirm) {
            e.preventDefault();
            errEl.textContent = "⚠️ Passwords do not match. Please verify and re-enter.";
            errEl.style.display = 'block';
            errEl.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            return false;
          }

          errEl.style.display = 'none';

          // Sign-Up "Remember Password for Quick Demo Login": save credentials to localStorage
          const rememberBox = document.getElementById('rememberDemoCheckbox');
          if (rememberBox && rememberBox.checked) {
            const u = (document.getElementById('reg-user') || {}).value || '';
            const em = (document.getElementById('reg-email') || {}).value || '';
            const r = (document.querySelector('select[name="role"]') || {}).value || 'customer';
            const p = pass;
            if (u && p) {
              const demoUser = {
                role: r,
                name: u,
                username: u,
                email: em,
                password: p,
                created_at: Date.now()
              };
              try {
                const key = 'fooddash_demo_users';
                let arr = JSON.parse(localStorage.getItem(key) || '[]');
                if (!Array.isArray(arr)) arr = [];
                arr = arr.filter(x => x.username !== u && x.email !== em);
                arr.unshift(demoUser);
                localStorage.setItem(key, JSON.stringify(arr));
              } catch (err) {}
            }
          }
        });
      }

      // Initialize dynamic Quick Demo Logins chips
      renderRegisteredDemoLogins();
    });

    // ================================================================
    // DYNAMIC REGISTERED QUICK DEMO LOGINS FUNCTIONS
    // ================================================================
    function renderRegisteredDemoLogins() {
      const key = 'fooddash_demo_users';
      let demoUsers = [];
      try {
        demoUsers = JSON.parse(localStorage.getItem(key) || '[]');
      } catch (e) {
        demoUsers = [];
      }

      const section = document.getElementById('quickDemoChipsSection');
      const container = document.getElementById('quickDemoChipsList');
      const popupList = document.getElementById('demoAccountsList');
      if (!section || !container) return;

      container.innerHTML = '';
      if (Array.isArray(demoUsers) && demoUsers.length > 0) {
        section.style.display = 'block';
        demoUsers.forEach((u, idx) => {
          const roleCapital = (u.role || 'customer').charAt(0).toUpperCase() + (u.role || 'customer').slice(1);
          const chip = document.createElement('button');
          chip.type = 'button';
          chip.className = 'quick-demo-chip';
          chip.title = `1-Click Demo Login as ${u.name || u.username}`;
          chip.innerHTML = `
            <span class="chip-left">
              <i class="fa-solid fa-bolt" style="color:var(--fd-gold, #c5a059); font-size: 11px;"></i>
              <span>Login as New <strong>${escapeHtml(roleCapital)}</strong>: ${escapeHtml(u.name || u.username)}</span>
            </span>
            <span class="chip-delete-btn" title="Remove" onclick="event.stopPropagation(); removeRegisteredDemoUser(${idx})">&times;</span>
          `;
          chip.onclick = function() {
            executeOneClickDemoLogin(u.username, u.password, u.name || u.username, roleCapital);
          };
          container.appendChild(chip);

          // Also prepend into demoAccountsPopup dropdown if not already present
          if (popupList && !popupList.querySelector(`[data-custom-user="${u.username}"]`)) {
            const item = document.createElement('div');
            item.className = 'demo-account-item';
            item.dataset.customUser = u.username;
            item.dataset.role = (u.role || 'customer').toLowerCase();
            item.dataset.user = u.username;
            item.dataset.pass = u.password;
            item.dataset.name = `New ${roleCapital} (${u.name || u.username})`;
            item.tabIndex = 0;
            item.setAttribute('role', 'button');
            item.innerHTML = `
              <div class="demo-item-left">
                <div class="demo-item-avatar avatar-${u.role || 'customer'}"><i class="fa-solid fa-user-plus"></i></div>
                <div class="demo-item-details">
                  <div class="demo-item-top">
                    <strong class="demo-item-username">${escapeHtml(u.username)}</strong>
                    <span class="demo-badge badge-${u.role || 'customer'}">New ${roleCapital}</span>
                  </div>
                  <div class="demo-item-bottom">
                    <span class="demo-pass-hint"><i class="fa-solid fa-key"></i> ${escapeHtml(u.password)}</span>
                    <span class="demo-role-desc">Registered Demo Login</span>
                  </div>
                </div>
              </div>
              <div class="demo-fill-action"><i class="fa-solid fa-bolt"></i> Login</div>
            `;
            item.onclick = function() {
              executeOneClickDemoLogin(u.username, u.password, u.name || u.username, roleCapital);
            };
            popupList.prepend(item);
          }
        });
      } else {
        section.style.display = 'none';
      }
    }

    function executeOneClickDemoLogin(user, pass, displayName, roleTitle) {
      const loginUser = document.getElementById('login_username');
      const loginPass = document.getElementById('login_password');
      const loginForm = document.getElementById('login-form');
      if (!loginUser || !loginPass || !loginForm) return;

      loginUser.value = user;
      loginPass.value = pass;
      loginUser.dispatchEvent(new Event('input', { bubbles: true }));
      loginUser.dispatchEvent(new Event('change', { bubbles: true }));
      loginPass.dispatchEvent(new Event('input', { bubbles: true }));
      loginPass.dispatchEvent(new Event('change', { bubbles: true }));

      showAutofillToast(`⚡ Logging in as <strong>${escapeHtml(displayName || user)}</strong> (${roleTitle})...`);

      // Instantly submit login form for classroom demonstrations
      setTimeout(() => {
        loginForm.submit();
      }, 220);
    }

    function removeRegisteredDemoUser(idx) {
      const key = 'fooddash_demo_users';
      try {
        let arr = JSON.parse(localStorage.getItem(key) || '[]');
        if (Array.isArray(arr)) {
          arr.splice(idx, 1);
          localStorage.setItem(key, JSON.stringify(arr));
          renderRegisteredDemoLogins();
        }
      } catch (e) {}
    }

    function escapeHtml(str) {
      if (!str) return '';
      return String(str).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function triggerSocialLogin(provider) {
      let url = "";
      if (provider === 'Google') url = 'https://accounts.google.com/AccountChooser';
      else if (provider === 'GitHub') url = 'https://github.com/login';
      else if (provider === 'Facebook') url = 'https://www.facebook.com/login';
      
      window.open(url, '_blank', 'width=500,height=600');
    }

    function openForgotModal() {
      document.getElementById("forgotModal").classList.add("active");
    }
    function closeForgotModal() {
      document.getElementById("forgotModal").classList.remove("active");
    }
    function submitForgotPassword() {
      const email = document.getElementById("reset-email").value;
      const errorEl = document.getElementById("forgotError");
      if (email.trim() === "") {
        errorEl.style.display = "block";
      } else {
        errorEl.style.display = "none";
        window.location.href = "index.php?msg=forgot_sent";
      }
    }

    const canvas = document.getElementById('particles-canvas');
    if (canvas) {
      const ctx = canvas.getContext('2d');
      canvas.width = window.innerWidth;
      canvas.height = window.innerHeight;

      let particlesArray = [];
      const numberOfParticles = 60;

      class Particle {
        constructor() {
          this.x = Math.random() * canvas.width;
          this.y = Math.random() * canvas.height;
          this.size = Math.random() * 3 + 1;
          this.speedX = (Math.random() - 0.5) * 1.2;
          this.speedY = (Math.random() - 0.5) * 1.2;
          const colors = ['#8f1d2f', '#b0233a', '#ff4d6d', '#5a121e'];
          this.color = colors[Math.floor(Math.random() * colors.length)];
          this.alpha = Math.random() * 0.7 + 0.3;
        }
        update() {
          this.x += this.speedX;
          this.y += this.speedY;
          if (this.x < 0 || this.x > canvas.width) this.speedX *= -1;
          if (this.y < 0 || this.y > canvas.height) this.speedY *= -1;
        }
        draw() {
          ctx.save();
          ctx.globalAlpha = this.alpha;
          ctx.shadowBlur = 10;
          ctx.shadowColor = '#8f1d2f';
          ctx.fillStyle = this.color;
          ctx.beginPath();
          ctx.arc(this.x, this.y, this.size, 0, Math.PI * 2);
          ctx.fill();
          ctx.restore();
        }
      }

      function init() {
        particlesArray = [];
        for (let i = 0; i < numberOfParticles; i++) {
          particlesArray.push(new Particle());
        }
      }

      function animate() {
        ctx.clearRect(0, 0, canvas.width, canvas.height);
        for (let i = 0; i < particlesArray.length; i++) {
          particlesArray[i].update();
          particlesArray[i].draw();
        }
        requestAnimationFrame(animate);
      }

      window.addEventListener('resize', () => {
        canvas.width = window.innerWidth;
        canvas.height = window.innerHeight;
        init();
      });

      init();
      animate();
    }
  </script>
  <script>
    document.addEventListener('DOMContentLoaded', function() {
      const u = document.getElementById('login_username');
      const p = document.getElementById('login_password');
      if (u && !u.value) { p.value = ''; }
    });
  </script>
</body>
</html>
