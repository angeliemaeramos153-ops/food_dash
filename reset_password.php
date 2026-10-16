<?php
session_start();
require_once 'db_connect.php';

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
            $uname = $_SESSION['username'] ?? 'User';
            $urole = $_SESSION['role'] ?? 'customer';
            if ($conn instanceof PDO) {
                try {
                    $stmt = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (:uid, :uname, :role, :act, :det, :ip, :ua, NOW())");
                    $stmt->execute([':uid' => $user_id, ':uname' => $uname, ':role' => $urole, ':act' => $action, ':det' => $det, ':ip' => $ip, ':ua' => $ua]);
                } catch (Throwable $e1) {}
            }
        } catch (Throwable $t) {}
        return true;
    }
}

$token = trim($_REQUEST['token'] ?? '');
$error_msg = "";
$success_msg = "";
$email_record = null;
$target_user = null;

if (!empty($token)) {
    $stmt = $conn->prepare("SELECT * FROM system_emails WHERE token = :tok LIMIT 1");
    $stmt->execute([':tok' => $token]);
    $email_record = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($email_record) {
        $u_stmt = $conn->prepare("SELECT * FROM users WHERE LOWER(email) = LOWER(:em) LIMIT 1");
        $u_stmt->execute([':em' => $email_record['recipient']]);
        $target_user = $u_stmt->fetch(PDO::FETCH_ASSOC);
    }
}

// Handle Form Submit
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action_update_password'])) {
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!$email_record || !$target_user) {
        $error_msg = "Invalid or expired password reset link. Please request a new link.";
    } elseif (strlen($new_password) < 6) {
        $error_msg = "Password must be at least 6 characters long.";
    } elseif ($new_password !== $confirm_password) {
        $error_msg = "Passwords do not match. Please re-enter.";
    } else {
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        try {
            $up_stmt = $conn->prepare("
                UPDATE users 
                SET password = :pwd, failed_attempts = 0, lockout_until = NULL 
                WHERE user_id = :uid
            ");
            $up_stmt->execute([
                ':pwd' => $new_hash,
                ':uid' => $target_user['user_id']
            ]);

            // Invalidate token
            $conn->prepare("UPDATE system_emails SET token = CONCAT(token, '_USED') WHERE id = :id")->execute([':id' => $email_record['id']]);

            log_activity($conn, (int)$target_user['user_id'], 'PASSWORD_RESET_COMPLETED', "User {$target_user['username']} ({$target_user['email']}) reset password via email link.");

            $success_msg = "Password reset successfully! Your account lockout has been cleared and your new password is now active.";
            $email_record = null; // Clear so form is replaced with login CTA
        } catch (Throwable $e) {
            $error_msg = "Failed to update password: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset Password - FoodDash</title>
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
  <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <style>
    :root {
      --fd-rust: #9E2A2B;
      --fd-sage: #4A5B2B;
      --fd-sage-light: #f1f5eb;
      --fd-gold: #E09F3E;
      --fd-cream: #FFFDF9;
      --fd-dark: #1F2421;
    }
    * { box-sizing: border-box; margin: 0; padding: 0; font-family: 'Plus Jakarta Sans', sans-serif; }
    body {
      background: #f4f6f8;
      min-height: 100vh;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      padding: 20px;
    }
    .reset-card {
      background: #ffffff;
      max-width: 460px;
      width: 100%;
      border-radius: 16px;
      box-shadow: 0 10px 30px rgba(0,0,0,0.08);
      border: 1px solid #e2e8f0;
      padding: 36px 32px;
      text-align: center;
    }
    .brand-logo {
      font-size: 28px;
      font-weight: 800;
      color: var(--fd-sage);
      margin-bottom: 8px;
      letter-spacing: -0.5px;
    }
    .brand-logo span { color: var(--fd-rust); }
    .page-title {
      font-size: 20px;
      font-weight: 700;
      color: var(--fd-dark);
      margin-bottom: 8px;
    }
    .page-desc {
      font-size: 13px;
      color: #64748b;
      margin-bottom: 24px;
      line-height: 1.5;
    }
    .user-pill {
      display: inline-flex;
      align-items: center;
      gap: 6px;
      background: var(--fd-sage-light);
      color: var(--fd-sage);
      font-size: 12px;
      font-weight: 600;
      padding: 6px 14px;
      border-radius: 20px;
      margin-bottom: 20px;
      border: 1px solid #d9e6cb;
    }
    .form-group {
      text-align: left;
      margin-bottom: 18px;
    }
    .form-group label {
      display: block;
      font-size: 12px;
      font-weight: 700;
      color: #334155;
      margin-bottom: 6px;
      text-transform: uppercase;
      letter-spacing: 0.5px;
    }
    .form-control {
      width: 100%;
      padding: 12px 14px;
      border: 1.5px solid #cbd5e1;
      border-radius: 10px;
      font-size: 14px;
      color: #1e293b;
      transition: all 0.2s;
    }
    .form-control:focus {
      outline: none;
      border-color: var(--fd-sage);
      box-shadow: 0 0 0 3px rgba(74, 91, 43, 0.15);
    }
    .btn-submit {
      width: 100%;
      padding: 14px;
      background: var(--fd-sage);
      color: #ffffff;
      border: none;
      border-radius: 10px;
      font-size: 15px;
      font-weight: 700;
      cursor: pointer;
      transition: all 0.2s;
      box-shadow: 0 4px 12px rgba(74, 91, 43, 0.25);
      margin-top: 8px;
    }
    .btn-submit:hover {
      background: #3c4a23;
      transform: translateY(-1px);
    }
    .btn-secondary {
      display: inline-block;
      margin-top: 16px;
      font-size: 13px;
      font-weight: 600;
      color: #64748b;
      text-decoration: none;
      transition: color 0.2s;
    }
    .btn-secondary:hover { color: var(--fd-rust); }
    .alert {
      padding: 12px 16px;
      border-radius: 10px;
      font-size: 13px;
      text-align: left;
      margin-bottom: 20px;
      line-height: 1.5;
    }
    .alert-error {
      background: #fef2f2;
      border: 1px solid #fecaca;
      color: #991b1b;
    }
    .alert-success {
      background: #f0fdf4;
      border: 1px solid #bbf7d0;
      color: #166534;
    }
    .success-box {
      padding: 20px 0;
    }
    .success-icon {
      font-size: 54px;
      color: #16a34a;
      margin-bottom: 16px;
    }
  </style>
</head>
<body>

  <div class="reset-card">
    <div class="brand-logo">Food<span>Dash</span></div>

    <?php if (!empty($success_msg)): ?>
      <div class="success-box">
        <i class="fa-solid fa-circle-check success-icon"></i>
        <h3 class="page-title" style="color: #16a34a;">Password Reset Complete!</h3>
        <p class="page-desc" style="margin-bottom: 24px;"><?php echo htmlspecialchars($success_msg); ?></p>
        <a href="index.php" class="btn-submit" style="display: block; text-decoration: none; text-align: center;">Proceed to Login</a>
      </div>

    <?php elseif (!$email_record || !$target_user): ?>
      <div style="padding: 20px 0;">
        <i class="fa-solid fa-triangle-exclamation" style="font-size: 48px; color: var(--fd-rust); margin-bottom: 14px;"></i>
        <h3 class="page-title">Invalid or Expired Link</h3>
        <p class="page-desc">This password reset link is invalid, has expired, or has already been used. Please return to the login screen and request a new password recovery link.</p>
        <a href="index.php" class="btn-submit" style="display: block; text-decoration: none; text-align: center;">Back to Login</a>
      </div>

    <?php else: ?>
      <h3 class="page-title">Set a New Password</h3>
      <p class="page-desc">Enter your new secure password below to regain full access to your FoodDash account.</p>

      <div class="user-pill">
        <i class="fa-solid fa-user-check"></i>
        <span><?php echo htmlspecialchars($target_user['email']); ?> (<?php echo htmlspecialchars($target_user['username']); ?>)</span>
      </div>

      <?php if (!empty($error_msg)): ?>
        <div class="alert alert-error">
          <i class="fa-solid fa-circle-xmark"></i> <?php echo htmlspecialchars($error_msg); ?>
        </div>
      <?php endif; ?>

      <form method="POST" action="reset_password.php?token=<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
        <input type="hidden" name="action_update_password" value="1">

        <div class="form-group">
          <label>New Password</label>
          <input type="password" name="new_password" class="form-control" placeholder="At least 6 characters" required autofocus minlength="6">
        </div>

        <div class="form-group">
          <label>Confirm Password</label>
          <input type="password" name="confirm_password" class="form-control" placeholder="Re-type your new password" required minlength="6">
        </div>

        <button type="submit" class="btn-submit">
          <i class="fa-solid fa-key"></i> Update Password
        </button>

        <a href="index.php" class="btn-secondary">
          <i class="fa-solid fa-arrow-left"></i> Cancel and Return to Login
        </a>
      </form>
    <?php endif; ?>
  </div>

</body>
</html>
