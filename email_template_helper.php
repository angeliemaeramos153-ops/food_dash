<?php
/**
 * FoodDash System Email Helper
 * Generates Google Workspace / Gmail-style HTML notifications for internal delivery simulation.
 */

if (!function_exists('generate_password_reset_email_html')) {
    function generate_password_reset_email_html($recipient, $otp_code, $reset_url) {
        $safe_email = htmlspecialchars($recipient, ENT_QUOTES, 'UTF-8');
        $safe_url = htmlspecialchars($reset_url, ENT_QUOTES, 'UTF-8');
        $year = date('Y');
        $timestamp = date('M d, Y h:i A');

        return <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Reset your FoodDash password</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f6f8fc; font-family: 'Google Sans', Roboto, -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif; color: #202124; line-height: 1.5;">
  <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #f6f8fc; padding: 24px 12px;">
    <tr>
      <td align="center">
        <!-- Main Container -->
        <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="max-width: 580px; background-color: #ffffff; border-radius: 12px; border: 1px solid #e0e3e7; overflow: hidden; box-shadow: 0 4px 16px rgba(60,64,67,0.08);">
          
          <!-- Top Google / FoodDash Header -->
          <tr>
            <td style="padding: 24px 32px 16px 32px; border-bottom: 1px solid #f1f3f4; background: linear-gradient(135deg, #ffffff 0%, #fafafa 100%);">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%">
                <tr>
                  <td>
                    <div style="display: inline-flex; align-items: center; gap: 8px;">
                      <span style="font-size: 22px; font-weight: 800; color: #4A5B2B; letter-spacing: -0.5px;">Food<span style="color: #9E2A2B;">Dash</span></span>
                      <span style="background: #e8f0fe; color: #1a73e8; font-size: 11px; font-weight: 600; padding: 2px 8px; border-radius: 12px; border: 1px solid #d2e3fc; margin-left: 6px;">Workspace Security</span>
                    </div>
                  </td>
                  <td align="right">
                    <span style="font-size: 11px; color: #5f6368; background: #f1f3f4; padding: 4px 8px; border-radius: 4px;">{$timestamp}</span>
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Security Badge Notice -->
          <tr>
            <td style="padding: 16px 32px 0 32px;">
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="background-color: #fcf8e3; border: 1px solid #faebcc; border-radius: 8px; padding: 10px 14px;">
                <tr>
                  <td width="24" valign="top" style="font-size: 16px; line-height: 1;">🛡️</td>
                  <td style="padding-left: 10px; font-size: 12px; color: #8a6d3b;">
                    <strong>Google Workspace Security Notice:</strong> A password reset request was initiated for <strong>{$safe_email}</strong>. If you did not make this request, you can safely ignore this email.
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          <!-- Body Content -->
          <tr>
            <td style="padding: 24px 32px;">
              <h2 style="margin: 0 0 12px 0; font-size: 20px; font-weight: 700; color: #202124;">Reset your password instructions</h2>
              <p style="margin: 0 0 16px 0; font-size: 14px; color: #3c4043; line-height: 1.6;">
                Hi there,
              </p>
              <p style="margin: 0 0 20px 0; font-size: 14px; color: #3c4043; line-height: 1.6;">
                We received a request to access your FoodDash account. Use the 6-digit verification code below or click the button to set a new password. This code expires in <strong>15 minutes</strong>.
              </p>

              <!-- OTP Verification Code Box -->
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 20px 0;">
                <tr>
                  <td align="center" style="background: #f8f9fa; border: 2px dashed #dadce0; border-radius: 10px; padding: 18px 20px;">
                    <div style="font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 1px; color: #5f6368; margin-bottom: 6px;">Your 6-Digit One-Time Code</div>
                    <div style="font-family: 'Courier New', Courier, monospace; font-size: 32px; font-weight: 800; color: #1a73e8; letter-spacing: 8px;">{$otp_code}</div>
                  </td>
                </tr>
              </table>

              <!-- Primary Action Button -->
              <table role="presentation" border="0" cellpadding="0" cellspacing="0" width="100%" style="margin: 28px 0 20px 0;">
                <tr>
                  <td align="center">
                    <a href="{$safe_url}" target="_blank" style="display: inline-block; background-color: #2e7d32; color: #ffffff; text-decoration: none; font-size: 15px; font-weight: 700; padding: 14px 36px; border-radius: 8px; box-shadow: 0 3px 8px rgba(46,125,50,0.35); text-align: center;">
                      Reset Password
                    </a>
                  </td>
                </tr>
              </table>

              <!-- Fallback Direct Link -->
              <div style="margin-top: 24px; padding-top: 16px; border-top: 1px solid #f1f3f4; font-size: 12px; color: #5f6368; line-height: 1.5;">
                <p style="margin: 0 0 6px 0;">Having trouble with the button? Copy and paste this URL into your browser:</p>
                <a href="{$safe_url}" style="color: #1a73e8; word-break: break-all; text-decoration: underline;">{$safe_url}</a>
              </div>
            </td>
          </tr>

          <!-- Security Footer -->
          <tr>
            <td style="padding: 20px 32px; background-color: #fafafa; border-top: 1px solid #ebebeb; font-size: 11px; color: #70757a; line-height: 1.5;">
              <p style="margin: 0 0 4px 0;">
                <strong>Security Protection:</strong> FoodDash will never ask for your password or verification code in an unsolicited email or phone call.
              </p>
              <p style="margin: 0; color: #9aa0a6;">
                © {$year} FoodDash Inc. • Simulated Gmail / Google Workspace Delivery Node (Port 80/443)
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>
HTML;
    }
}

if (!function_exists('send_system_email')) {
    function send_system_email($conn, $recipient, $subject, $body_html, $token = null, $otp_code = null) {
        try {
            $stmt = $conn->prepare("
                INSERT INTO system_emails (recipient, subject, body_html, token, otp_code, sent_at, is_read)
                VALUES (:rec, :sub, :body, :tok, :otp, NOW(), 0)
            ");
            $stmt->execute([
                ':rec' => trim($recipient),
                ':sub' => trim($subject),
                ':body' => $body_html,
                ':tok' => $token,
                ':otp' => $otp_code
            ]);
            return (int)$conn->lastInsertId();
        } catch (Throwable $t) {
            error_log("Failed to insert system_email: " . $t->getMessage());
            return false;
        }
    }
}

if (!function_exists('create_password_reset_request')) {
    function create_password_reset_request($conn, $email) {
        $email = trim(strtolower($email));
        if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['success' => false, 'message' => 'Please enter a valid email address.'];
        }

        // Generate token and 6-digit OTP
        $token = bin2hex(random_bytes(16));
        $otp_code = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

        // Build reset URL (relative for portability, or absolute host)
        $reset_url = "reset_password.php?token=" . urlencode($token);

        // Generate Gmail-style HTML body
        $body_html = generate_password_reset_email_html($email, $otp_code, $reset_url);
        $subject = "FoodDash - Reset your password instructions";

        // Save email
        $email_id = send_system_email($conn, $email, $subject, $body_html, $token, $otp_code);

        return [
            'success' => true,
            'message' => 'Password reset instructions sent to your email!',
            'email' => $email,
            'token' => $token,
            'otp' => $otp_code,
            'reset_url' => $reset_url,
            'email_id' => $email_id
        ];
    }
}
