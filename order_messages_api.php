<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function json_out(bool $success, string $message = '', array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Ensure receiver_id and receiver_role columns exist in order_messages
try {
    $cols = $conn->query("SHOW COLUMNS FROM order_messages LIKE 'receiver_id'")->fetchAll();
    if (empty($cols)) {
        $conn->exec("ALTER TABLE order_messages ADD COLUMN receiver_id INT NULL DEFAULT NULL AFTER sender_id");
    }
    $cols2 = $conn->query("SHOW COLUMNS FROM order_messages LIKE 'receiver_role'")->fetchAll();
    if (empty($cols2)) {
        $conn->exec("ALTER TABLE order_messages ADD COLUMN receiver_role VARCHAR(32) NULL DEFAULT 'customer' AFTER receiver_id");
    }
} catch (Throwable $e) {}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    json_out(false, 'Unauthorized. Please log in.', [], 401);
}

$user_id = (int)$_SESSION['user_id'];
$role = strtolower(trim((string)$_SESSION['role']));
$user_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));

// GET: Retrieve messages for an active order
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $order_id = (int)($_GET['order_id'] ?? 0);
    $after_id = (int)($_GET['after_id'] ?? 0);

    if ($order_id <= 0) {
        json_out(false, 'Valid order_id is required.', [], 400);
    }

    // Verify order access and resolve real phone numbers & details
    $stmt = $conn->prepare("SELECT 
        o.order_id, o.customer_id, o.customer_name, 
        COALESCE(NULLIF(TRIM(o.customer_phone), ''), NULLIF(TRIM(cu.phone), ''), '+63 917 123 4567') AS customer_phone,
        o.rider_id, 
        COALESCE(NULLIF(TRIM(o.rider_phone), ''), NULLIF(TRIM(ru.phone), ''), '+63 917 555 0192') AS rider_phone,
        o.order_ref, o.store_name, o.status, o.delivery_status 
        FROM food_orders o
        LEFT JOIN users cu ON (o.customer_id = cu.user_id)
        LEFT JOIN riders r ON (o.rider_id = r.rider_id)
        LEFT JOIN users ru ON (r.user_id = ru.user_id)
        WHERE o.order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        json_out(false, 'Order not found.', [], 404);
    }

    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    $customer_id = (int)($order['customer_id'] ?? 0);

    // Permission check: Customer must match customer_id
    if ($role === 'customer' && $customer_id !== $user_id && !$is_demo) {
        json_out(false, 'Access denied.', [], 403);
    }

    $sql = "SELECT message_id, order_id, sender_role, sender_id, receiver_id, 
            COALESCE(receiver_role, 'customer') AS receiver_role, 
            sender_name, message_text, created_at 
            FROM order_messages 
            WHERE order_id = :oid ";
    $params = [':oid' => $order_id];

    if ($after_id > 0) {
        $sql .= " AND message_id > :after_id ";
        $params[':after_id'] = $after_id;
    }

    $sql .= " ORDER BY message_id ASC LIMIT 100";
    $m_stmt = $conn->prepare($sql);
    $m_stmt->execute($params);
    $messages = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format timestamps and ownership nicely
    foreach ($messages as &$msg) {
        $msg['message_id'] = (int)$msg['message_id'];
        $msg['order_id'] = (int)$msg['order_id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
        $msg['receiver_id'] = (int)($msg['receiver_id'] ?? 0);
        $msg['receiver_role'] = strtolower(trim((string)($msg['receiver_role'] ?? 'customer')));
        $msg['time_formatted'] = date('g:i A', strtotime($msg['created_at']));

        if ($role === 'rider') {
            $msg['is_me'] = ($msg['sender_role'] === 'rider');
        } elseif ($role === 'customer') {
            $msg['is_me'] = ($msg['sender_role'] === 'customer');
        } elseif ($role === 'admin' || $role === 'manager') {
            $msg['is_me'] = in_array($msg['sender_role'], ['admin', 'manager'], true);
        } else {
            $msg['is_me'] = ($msg['sender_id'] === $user_id);
        }
    }
    unset($msg);

    $cust_phone = !empty($order['customer_phone']) ? $order['customer_phone'] : '+63 928 555 0148';
    $rider_phone = !empty($order['rider_phone']) ? $order['rider_phone'] : '+63 917 555 0192';
    $clean_order_ref = '#' . ltrim(!empty($order['order_ref']) ? $order['order_ref'] : ('FD-' . $order_id), '#');

    json_out(true, 'Messages loaded.', [
        'order_id'       => $order_id,
        'order_ref'      => $clean_order_ref,
        'messages'       => $messages,
        'customer_name'  => $order['customer_name'] ?? 'Customer',
        'customer_phone' => $cust_phone,
        'rider_phone'    => $rider_phone,
        'store_name'     => $order['store_name'] ?? 'Store',
        'status'         => $order['status'] ?? '',
        'delivery_status'=> $order['delivery_status'] ?? '',
    ]);
}

// POST: Send a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $order_id = (int)($payload['order_id'] ?? 0);
    $text = trim((string)($payload['message_text'] ?? $payload['text'] ?? ''));
    $recipient = strtolower(trim((string)($payload['recipient'] ?? $payload['receiver_role'] ?? $payload['channel'] ?? 'customer')));
    if (!in_array($recipient, ['customer', 'manager', 'admin'], true)) {
        $recipient = 'customer';
    }

    if ($order_id <= 0) {
        json_out(false, 'Valid order_id is required.', [], 400);
    }
    if ($text === '') {
        json_out(false, 'Message text cannot be empty.', [], 422);
    }

    // Verify order
    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status, delivery_status, store_name FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        json_out(false, 'Order not found.', [], 404);
    }

    $clean_order_ref = '#' . ltrim(!empty($order['order_ref']) ? $order['order_ref'] : ('FD-' . $order_id), '#');

    $sender_role = in_array($role, ['rider', 'customer', 'manager', 'admin', 'chef'], true) ? $role : 'rider';
    $receiver_role = $recipient;
    $receiver_id = 0;

    if ($sender_role === 'customer') {
        $receiver_role = 'rider';
        $rider_id = (int)($order['rider_id'] ?? 0);
        if ($rider_id > 0) {
            $rq = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = :rid LIMIT 1");
            $rq->execute([':rid' => $rider_id]);
            $receiver_id = (int)$rq->fetchColumn();
        }
        if ($receiver_id <= 0) $receiver_id = 17;
    } elseif ($sender_role === 'rider') {
        if ($recipient === 'manager' || $recipient === 'admin') {
            $receiver_role = 'manager';
            $receiver_id = 0;
        } else {
            $receiver_role = 'customer';
            $receiver_id = (int)($order['customer_id'] ?? 0);
            if ($receiver_id <= 0) $receiver_id = 26;
        }
    } else {
        // Manager or Admin
        if ($recipient === 'rider') {
            $receiver_role = 'rider';
            $rider_id = (int)($order['rider_id'] ?? 0);
            if ($rider_id > 0) {
                $rq = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = :rid LIMIT 1");
                $rq->execute([':rid' => $rider_id]);
                $receiver_id = (int)$rq->fetchColumn();
            }
        } else {
            $receiver_role = 'customer';
            $receiver_id = (int)($order['customer_id'] ?? 0);
        }
    }

    // Insert message using prepared statement
    $ins = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, receiver_id, receiver_role, sender_name, message_text, created_at) VALUES (:oid, :role, :uid, :rec, :rrole, :name, :txt, NOW())");
    $ins->execute([
        ':oid'   => $order_id,
        ':role'  => $sender_role,
        ':uid'   => $user_id,
        ':rec'   => $receiver_id,
        ':rrole' => $receiver_role,
        ':name'  => $user_name,
        ':txt'   => $text
    ]);
    $new_id = (int)$conn->lastInsertId();

    // Trigger in-app notifications to counter-party
    try {
        if ($sender_role === 'rider') {
            if ($receiver_role === 'manager' || $recipient === 'manager' || $recipient === 'admin') {
                notify_role($conn, 'admin', 'Rider Message', "Rider {$user_name} on order {$clean_order_ref}: \"{$text}\"", $order_id);
                notify_role($conn, 'manager', 'Rider Message', "Rider {$user_name} on order {$clean_order_ref}: \"{$text}\"", $order_id);
            } else {
                if (!empty($order['customer_id'])) {
                    create_notification(
                        $conn,
                        (int)$order['customer_id'],
                        'rider_message',
                        'Message from Rider (' . $user_name . ')',
                        $text,
                        $order_id
                    );
                }
            }
        } elseif ($sender_role === 'customer') {
            if (!empty($order['rider_id'])) {
                $rq = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = :rid LIMIT 1");
                $rq->execute([':rid' => (int)$order['rider_id']]);
                $r_uid = (int)$rq->fetchColumn();
                if ($r_uid > 0) {
                    create_notification(
                        $conn,
                        $r_uid,
                        'customer_message',
                        'Message from Customer (' . $user_name . ')',
                        $text,
                        $order_id
                    );
                }
            }
        }
    } catch (Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }

    // Mirror to Supabase cloud log if configured
    try {
        $sup_url = defined('SUPABASE_URL') ? SUPABASE_URL : getenv('SUPABASE_URL');
        $sup_key = defined('SUPABASE_KEY') ? SUPABASE_KEY : (getenv('SUPABASE_SERVICE_KEY') ?: getenv('SUPABASE_ANON_KEY'));
        if (!empty($sup_url) && !empty($sup_key)) {
            $sup_base = rtrim($sup_url, '/') . '/rest/v1';
            $ch = curl_init($sup_base . '/order_messages');
            $sup_payload = json_encode([
                'order_id'      => $order_id,
                'sender_role'   => $sender_role,
                'sender_id'     => $user_id,
                'receiver_id'   => $receiver_id,
                'sender_name'   => $user_name,
                'message_text'  => $text,
                'created_at'    => date('c')
            ]);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $sup_payload);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 2);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'apikey: ' . $sup_key,
                'Authorization: Bearer ' . $sup_key,
                'Prefer: return=minimal'
            ]);
            curl_exec($ch);
            curl_close($ch);
        }
    } catch (Throwable $e) {}

    json_out(true, 'Message sent successfully.', [
        'message_id'     => $new_id,
        'order_id'       => $order_id,
        'sender_role'    => $sender_role,
        'receiver_role'  => $receiver_role,
        'sender_name'    => $user_name,
        'message_text'   => $text,
        'is_me'          => true,
        'time_formatted' => date('g:i A'),
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

json_out(false, 'Method not allowed.', [], 405);
