<?php
// Food Dash In-App Notification & Audit Logging Engine (PostgreSQL PDO)

function create_notification($conn, int $user_id, string $type, string $title, string $message, ?int $order_id = null): bool {
    if (!($conn instanceof PDO)) return false;
    $stmt = $conn->prepare("INSERT INTO notifications (user_id, order_id, notification_type, title, message, is_read, created_at) VALUES (:uid, :oid, :type, :title, :msg, FALSE, NOW())");
    return $stmt->execute([
        ':uid'   => $user_id,
        ':oid'   => $order_id,
        ':type'  => $type,
        ':title' => $title,
        ':msg'   => $message
    ]);
}

function notify_role($conn, string $role, ...$args): int {
    if (!($conn instanceof PDO)) return 0;
    $count = 0;
    $role = strtolower(trim($role));
    $roles = [$role];
    if ($role === 'chef') $roles[] = 'manager';
    if ($role === 'manager') $roles[] = 'chef';

    $type = 'general';
    $title = '';
    $message = '';
    $order_id = null;

    if (count($args) === 3) {
        $title = (string)$args[0];
        $message = (string)$args[1];
        $order_id = $args[2] !== null ? (int)$args[2] : null;
    } elseif (count($args) >= 4) {
        $type = (string)$args[0];
        $title = (string)$args[1];
        $message = (string)$args[2];
        $order_id = isset($args[3]) && $args[3] !== null ? (int)$args[3] : null;
    } elseif (count($args) === 2) {
        $title = (string)$args[0];
        $message = (string)$args[1];
    }

    $in = implode(',', array_fill(0, count($roles), '?'));
    $stmt = $conn->prepare("SELECT user_id FROM users WHERE LOWER(role) IN ($in)");
    $stmt->execute($roles);
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        if (create_notification($conn, (int)$row['user_id'], $type, $title, $message, $order_id)) $count++;
    }
    return $count;
}

function record_order_audit_log($conn, int $order_id, ?string $old_status, string $new_status, ?int $user_id, string $role, ?string $notes = null): bool {
    if (!($conn instanceof PDO)) return false;
    $old_status_val = $old_status !== null ? strtoupper(trim($old_status)) : null;
    $new_status_val = strtoupper(trim($new_status));
    $role_val = strtolower(trim($role));

    $stmt = $conn->prepare("INSERT INTO order_audit_logs (order_id, old_status, new_status, action_by_user_id, action_by_role, notes, created_at) VALUES (:oid, :old_st, :new_st, :uid, :role, :notes, NOW())");
    return $stmt->execute([
        ':oid'    => $order_id,
        ':old_st' => $old_status_val,
        ':new_st' => $new_status_val,
        ':uid'    => $user_id,
        ':role'   => $role_val,
        ':notes'  => $notes
    ]);
}

/**
 * Validates password against strong password policy:
 * - Minimum 8 characters
 * - At least one uppercase letter (A-Z)
 * - At least one lowercase letter (a-z)
 * - At least one numeric digit (0-9)
 * - At least one special character (@$!%*?&)
 *
 * @param string $password
 * @return array Array of error strings; empty array if valid.
 */
function validate_strong_password(string $password): array {
    $errors = [];
    if (strlen($password) < 8) {
        $errors[] = "Password must be at least 8 characters long.";
    }
    if (!preg_match('/[A-Z]/', $password)) {
        $errors[] = "Must contain at least one uppercase letter (A-Z).";
    }
    if (!preg_match('/[a-z]/', $password)) {
        $errors[] = "Must contain at least one lowercase letter (a-z).";
    }
    if (!preg_match('/[0-9]/', $password)) {
        $errors[] = "Must contain at least one number (0-9).";
    }
    if (!preg_match('/[@$!%*?&]/', $password)) {
        $errors[] = "Must contain at least one special character (@$!%*?&).";
    }
    return $errors;
}

/**
 * Centralized Security & Activity Audit Logger
 * Records immutable events to system_audit_logs table.
 *
 * @param PDO $conn
 * @param int|null $user_id
 * @param string $action (e.g. LOGIN_SUCCESS, LOGIN_FAILED, LOGOUT, USER_CREATED, etc.)
 * @param string|null $details
 * @param string|null $override_username
 * @param string|null $override_role
 * @return bool
 */
if (!function_exists('log_activity')) {
function log_activity($conn, ?int $user_id, string $action, ?string $details = null, ?string $override_username = null, ?string $override_role = null): bool {
    if (!($conn instanceof PDO)) return false;
    $action_val = strtoupper(trim($action));
    $ip_address = substr($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1', 0, 45);
    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string)$_SERVER['HTTP_USER_AGENT'], 0, 255) : 'System/API';
    
    $username = $override_username;
    $role = $override_role;
    $actual_user_id = $user_id;

    if ($actual_user_id !== null && $actual_user_id > 0) {
        if (isset($_SESSION['user_id']) && (int)$_SESSION['user_id'] === $actual_user_id) {
            if ($username === null) $username = $_SESSION['username'] ?? $_SESSION['full_name'] ?? null;
            if ($role === null) $role = $_SESSION['role'] ?? null;
        }

        if ($username === null || $role === null) {
            $stmt = $conn->prepare("SELECT username, role FROM users WHERE user_id = :uid LIMIT 1");
            $stmt->execute([':uid' => $actual_user_id]);
            if ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                if ($username === null) $username = $row['username'];
                if ($role === null) $role = $row['role'];
            }
        }
    } elseif (isset($_SESSION['user_id'])) {
        $actual_user_id = (int)$_SESSION['user_id'];
        if ($username === null) $username = $_SESSION['username'] ?? $_SESSION['full_name'] ?? null;
        if ($role === null) $role = $_SESSION['role'] ?? null;
    }

    try {
        $stmt = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (:uid, :uname, :role, :action, :details, :ip, :ua, NOW())");
        return $stmt->execute([
            ':uid'     => $actual_user_id,
            ':uname'   => $username,
            ':role'    => $role,
            ':action'  => $action_val,
            ':details' => $details,
            ':ip'      => $ip_address,
            ':ua'      => $user_agent
        ]);
    } catch (Throwable $e) {
        // If foreign key constraint failed on user_id, retry with user_id = null
        try {
            $stmt = $conn->prepare("INSERT INTO system_audit_logs (user_id, username, role, action, details, ip_address, user_agent, created_at) VALUES (NULL, :uname, :role, :action, :details, :ip, :ua, NOW())");
            return $stmt->execute([
                ':uname'   => $username,
                ':role'    => $role,
                ':action'  => $action_val,
                ':details' => $details,
                ':ip'      => $ip_address,
                ':ua'      => $user_agent
            ]);
        } catch (Throwable $t2) {
            error_log("Activity log notice: " . $t2->getMessage());
            return false;
        }
    }
}
}
?>
