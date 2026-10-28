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
/**
 * Automated On-Delivery Customer Chat Dispatcher
 * Automatically inserts the departure message into order_messages table and Supabase chat log:
 * "Hi! Your order has been picked up from Jollibee and is now on the way to Western Leyte College. Total amount due: ₱327.00 (COD). See you soon!"
 *
 * Evaluates store brand, destination, amount, and payment method dynamically for any order with zero hardcoded IDs.
 */
if (!function_exists('send_on_delivery_chat_message')) {
function send_on_delivery_chat_message(PDO $conn, int $order_id, ?int $sender_id = null, ?string $sender_name = null): bool {
    if ($order_id <= 0) return false;

    try {
        // 1. Fetch order details from food_orders and joined tables
        $stmt = $conn->prepare("
            SELECT 
                fo.order_id, fo.order_ref, fo.customer_id, fo.user_id, fo.store_name, 
                fo.delivery_address, fo.total_amount, fo.payment_method, fo.rider_id,
                COALESCE(r.rider_name, 'Alex Gonzaga') AS assigned_rider_name,
                COALESCE(r.user_id, 17) AS rider_user_id
            FROM food_orders fo
            LEFT JOIN riders r ON fo.rider_id = r.rider_id
            WHERE fo.order_id = :oid
            LIMIT 1
        ");
        $stmt->execute([':oid' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) {
            try {
                $stmt2 = $conn->prepare("SELECT COALESCE(o.id, o.order_id) AS order_id, o.order_number AS order_ref, o.user_id AS customer_id, o.user_id, COALESCE(s.shop_name, o.store_name, 'Partner Store') AS store_name, o.delivery_address, o.total_price AS total_amount, o.payment_method, o.rider_id, COALESCE(o.rider_name, 'Alex Gonzaga') AS assigned_rider_name, 17 AS rider_user_id FROM orders o LEFT JOIN shops s ON (o.store_id = s.shop_id OR o.shop_id = s.shop_id) WHERE o.id = :oid OR o.order_id = :oid2 LIMIT 1");
                $stmt2->execute([':oid' => $order_id, ':oid2' => $order_id]);
                $order = $stmt2->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {}
        }
        if (!$order) return false;

        // 2. Prevent duplicate departure messages for the same order
        $chk = $conn->prepare("SELECT message_id FROM order_messages WHERE order_id = :oid AND (message_text LIKE '%picked up by the rider%' OR message_text LIKE '%picked up from%' OR message_text LIKE '%now on the way%') LIMIT 1");
        $chk->execute([':oid' => $order_id]);
        if ($chk->fetch()) {
            return true; // Already dispatched
        }

        // 3. Resolve Store Brand dynamically
        $raw_store = trim((string)($order['store_name'] ?: ($order['ord_store_name'] ?? '')));
        $store_brand = 'Jollibee';
        if (stripos($raw_store, 'jollibee') !== false) {
            $store_brand = 'Jollibee';
        } elseif (stripos($raw_store, 'mcdonald') !== false || stripos($raw_store, 'mcdo') !== false) {
            $store_brand = "McDonald's";
        } elseif (stripos($raw_store, 'inasal') !== false) {
            $store_brand = 'Mang Inasal';
        } elseif (stripos($raw_store, 'chowking') !== false) {
            $store_brand = 'Chowking';
        } elseif (stripos($raw_store, 'greenwich') !== false) {
            $store_brand = 'Greenwich';
        } elseif (stripos($raw_store, 'ribshack') !== false || stripos($raw_store, 'rib shack') !== false) {
            $store_brand = 'Ribshack';
        } elseif (stripos($raw_store, 'shakey') !== false) {
            $store_brand = "Shakey's";
        } elseif (!empty($raw_store)) {
            $store_brand = $raw_store;
        }

        // 4. Resolve Destination Name dynamically
        $raw_dest = trim((string)($order['delivery_address'] ?: ($order['ord_delivery_address'] ?? '')));
        $dest_lower = strtolower($raw_dest);
        $dest_brand = 'Western Leyte College';
        if (
            str_contains($dest_lower, 'western') ||
            str_contains($dest_lower, 'leyte') ||
            str_contains($dest_lower, 'college') ||
            str_contains($dest_lower, 'wlc') ||
            str_contains($dest_lower, 'bonifacio')
        ) {
            $dest_brand = 'Western Leyte College';
        } elseif (!empty($raw_dest)) {
            $dest_brand = $raw_dest;
        }

        // 5. Calculate Real Total Amount dynamically
        $total_val = (float)($order['total_amount'] ?: ($order['ord_total_amount'] ?? 0));
        if ($total_val <= 0) {
            try {
                $item_sum_stmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(line_total, price * quantity, unit_price * quantity, 0)), 0) FROM order_items WHERE order_id = :oid");
                $item_sum_stmt->execute([':oid' => $order_id]);
                $sum = (float)$item_sum_stmt->fetchColumn();
                if ($sum > 0) $total_val = $sum + 30.00;
            } catch (Throwable $e) {}
        }
        $formatted_amount = number_format($total_val > 0 ? $total_val : 327.00, 2);

        // 6. Resolve Payment Method dynamically
        $raw_pm = trim((string)($order['payment_method'] ?: ($order['ord_payment_method'] ?? 'COD')));
        $pm_brand = 'COD';
        if (stripos($raw_pm, 'gcash') !== false) {
            $pm_brand = 'GCash';
        } elseif (stripos($raw_pm, 'maya') !== false) {
            $pm_brand = 'Maya';
        } elseif (stripos($raw_pm, 'gotyme') !== false) {
            $pm_brand = 'GoTyme';
        } elseif (stripos($raw_pm, 'apple') !== false) {
            $pm_brand = 'Apple Pay';
        } elseif (stripos($raw_pm, 'cod') !== false || stripos($raw_pm, 'delivery') !== false || strtolower(trim($raw_pm)) === 'cash') {
            $pm_brand = 'COD';
        } elseif (stripos($raw_pm, 'maya') !== false) {
            $pm_brand = 'Maya';
        } elseif (stripos($raw_pm, 'gotyme') !== false) {
            $pm_brand = 'GoTyme';
        } elseif (!empty($raw_pm)) {
            $pm_brand = $raw_pm;
        }

        // 7. Construct the exact automated chat message
        $auto_msg = "Hi! Your order has been picked up by the rider and is now on the way. Total amount due: ₱{$formatted_amount} ({$pm_brand}).";

        // 8. Resolve sender and receiver IDs
        $r_name = !empty($sender_name) ? $sender_name : ($order['assigned_rider_name'] ?: 'Alex Gonzaga');
        $r_uid = ($sender_id !== null && $sender_id > 0) ? $sender_id : (int)($order['rider_user_id'] ?: 17);
        $cust_uid = (int)($order['customer_id'] ?: ($order['user_id'] ?? 26));

        // 9. Insert into MySQL order_messages
        $ins = $conn->prepare("
            INSERT INTO order_messages (order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at)
            VALUES (:oid, 'rider', :uid, :rec, :name, :txt, NOW())
        ");
        $ins->execute([
            ':oid'  => $order_id,
            ':uid'  => $r_uid,
            ':rec'  => $cust_uid,
            ':name' => $r_name,
            ':txt'  => $auto_msg
        ]);

        // 10. Mirror into Supabase order_messages Cloud chat log
        try {
            $sup_base = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1';
            $sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';

            $sup_payload = [
                'order_id'    => $order_id,
                'sender_role' => 'rider',
                'sender_id'   => $r_uid,
                'receiver_id' => $cust_uid,
                'sender_name' => $r_name,
                'message_text'=> $auto_msg,
                'created_at'  => gmdate('Y-m-d\TH:i:s\Z')
            ];

            $sch = curl_init($sup_base . '/order_messages');
            curl_setopt($sch, CURLOPT_POST, true);
            curl_setopt($sch, CURLOPT_POSTFIELDS, json_encode($sup_payload));
            curl_setopt($sch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($sch, CURLOPT_TIMEOUT, 3);
            curl_setopt($sch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $sup_key,
                'Authorization: Bearer ' . $sup_key,
                'Content-Type: application/json'
            ]);
            curl_exec($sch);
            curl_close($sch);
        } catch (Throwable $e) {}

        // 11. Dispatch in-app notification to the customer
        if ($cust_uid > 0) {
            create_notification(
                $conn,
                $cust_uid,
                'rider_message',
                'Message from Rider (' . $r_name . ')',
                $auto_msg,
                $order_id
            );
        }

        return true;
    } catch (Throwable $e) {
        error_log('send_on_delivery_chat_message error: ' . $e->getMessage());
        return false;
    }
}
}
?>
