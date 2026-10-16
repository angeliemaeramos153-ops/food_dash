<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function comm_out(bool $success, string $message = '', array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    comm_out(false, 'Unauthorized. Please log in.', [], 401);
}

$user_id = (int)$_SESSION['user_id'];
$role = strtolower(trim((string)$_SESSION['role']));
$user_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));

// Ensure order_calls table exists
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS order_calls (
        call_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        caller_role VARCHAR(32) NOT NULL DEFAULT 'rider',
        caller_id INT NOT NULL DEFAULT 0,
        caller_name VARCHAR(128) NOT NULL DEFAULT 'Rider',
        caller_phone VARCHAR(64) NOT NULL DEFAULT '',
        receiver_role VARCHAR(32) NOT NULL DEFAULT 'customer',
        receiver_id INT NOT NULL DEFAULT 0,
        status ENUM('ringing', 'accepted', 'declined', 'ended', 'missed') NOT NULL DEFAULT 'ringing',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order_status (order_id, status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    // Ignore if table exists or migration ran
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$payload = is_array($json_input) ? $json_input : $_POST;

if ($method === 'POST' && !empty($payload['action'])) {
    $action = $payload['action'];
}

// -------------------------------------------------------------
// 1. TRIGGER CALL
// -------------------------------------------------------------
if ($action === 'trigger_call') {
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        comm_out(false, 'Valid order_id is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        comm_out(false, 'Order not found.', [], 404);
    }

    $customer_id = (int)($order['customer_id'] ?? 0);
    $customer_phone = !empty($order['customer_phone']) ? trim($order['customer_phone']) : '+63 917 123 4567';
    $rider_phone = !empty($order['rider_phone']) ? trim($order['rider_phone']) : '+63 917 555 0192';

    // Mark any previous active ringing calls on this order as ended or missed
    $upd = $conn->prepare("UPDATE order_calls SET status = 'missed' WHERE order_id = :oid AND status = 'ringing'");
    $upd->execute([':oid' => $order_id]);

    $caller_role = ($role === 'customer') ? 'customer' : 'rider';
    $receiver_role = ($caller_role === 'rider') ? 'customer' : 'rider';
    $receiver_id = ($caller_role === 'rider') ? $customer_id : (int)($order['rider_id'] ?? 0);
    $caller_phone = ($caller_role === 'rider') ? $rider_phone : $customer_phone;

    $ins = $conn->prepare("INSERT INTO order_calls (order_id, caller_role, caller_id, caller_name, caller_phone, receiver_role, receiver_id, status, created_at) VALUES (:oid, :crole, :cid, :cname, :cphone, :rrole, :rid, 'ringing', NOW())");
    $ins->execute([
        ':oid'   => $order_id,
        ':crole' => $caller_role,
        ':cid'   => $user_id,
        ':cname' => $user_name,
        ':cphone'=> $caller_phone,
        ':rrole' => $receiver_role,
        ':rid'   => $receiver_id
    ]);
    $call_id = (int)$conn->lastInsertId();

    // Trigger in-app notification
    try {
        if ($caller_role === 'rider' && $customer_id > 0) {
            create_notification(
                $conn,
                $customer_id,
                'incoming_call',
                'Incoming Call from Rider (' . $user_name . ')',
                'Your delivery rider is calling regarding order ' . ($order['order_ref'] ?: '#' . $order_id) . '.',
                $order_id
            );
        }
    } catch (Throwable $e) {
        error_log('Call notification error: ' . $e->getMessage());
    }

    comm_out(true, 'Call triggered successfully.', [
        'call_id'        => $call_id,
        'order_id'       => $order_id,
        'status'         => 'ringing',
        'caller_name'    => $user_name,
        'caller_phone'   => $caller_phone,
        'customer_phone' => $customer_phone,
        'rider_phone'    => $rider_phone,
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

// -------------------------------------------------------------
// 2. SEND MESSAGE
// -------------------------------------------------------------
if ($action === 'send_message') {
    $order_id = (int)($payload['order_id'] ?? 0);
    $text = trim((string)($payload['message_text'] ?? $payload['text'] ?? ''));

    if ($order_id <= 0) {
        comm_out(false, 'Valid order_id is required.', [], 422);
    }
    if ($text === '') {
        comm_out(false, 'Message text cannot be empty.', [], 422);
    }

    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        comm_out(false, 'Order not found.', [], 404);
    }

    $sender_role = ($role === 'rider') ? 'rider' : 'customer';

    $ins = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, :role, :uid, :name, :txt, NOW())");
    $ins->execute([
        ':oid'  => $order_id,
        ':role' => $sender_role,
        ':uid'  => $user_id,
        ':name' => $user_name,
        ':txt'  => $text
    ]);
    $msg_id = (int)$conn->lastInsertId();

    try {
        if ($sender_role === 'rider' && !empty($order['customer_id'])) {
            create_notification(
                $conn,
                (int)$order['customer_id'],
                'rider_message',
                'Message from Rider (' . $user_name . ')',
                $text,
                $order_id
            );
        } elseif ($sender_role === 'customer' && !empty($order['rider_id'])) {
            $rq = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = :rid LIMIT 1");
            $rq->execute([':rid' => (int)$order['rider_id']]);
            $r_uid = $rq->fetchColumn();
            if ($r_uid) {
                create_notification(
                    $conn,
                    (int)$r_uid,
                    'customer_message',
                    'Message from Customer (' . $user_name . ')',
                    $text,
                    $order_id
                );
            }
        }
    } catch (Throwable $e) {
        error_log('Notification error: ' . $e->getMessage());
    }

    comm_out(true, 'Message sent successfully.', [
        'message_id'     => $msg_id,
        'order_id'       => $order_id,
        'sender_role'    => $sender_role,
        'sender_name'    => $user_name,
        'message_text'   => $text,
        'time_formatted' => date('g:i A'),
        'created_at'     => date('Y-m-d H:i:s'),
    ]);
}

// -------------------------------------------------------------
// 3. RESPOND / UPDATE CALL STATUS
// -------------------------------------------------------------
if ($action === 'respond_call' || $action === 'end_call') {
    $call_id = (int)($payload['call_id'] ?? $_GET['call_id'] ?? 0);
    $status = trim((string)($payload['status'] ?? ($action === 'end_call' ? 'ended' : 'accepted')));

    if (!in_array($status, ['accepted', 'declined', 'ended', 'missed'], true)) {
        $status = 'ended';
    }

    if ($call_id <= 0) {
        // If no call_id specified, check for active ringing call for order_id
        $order_id = (int)($payload['order_id'] ?? 0);
        if ($order_id > 0) {
            $upd = $conn->prepare("UPDATE order_calls SET status = :st WHERE order_id = :oid AND status = 'ringing'");
            $upd->execute([':st' => $status, ':oid' => $order_id]);
            comm_out(true, 'Call status updated for order.', ['order_id' => $order_id, 'status' => $status]);
        }
        comm_out(false, 'Valid call_id or order_id is required.', [], 422);
    }

    $upd = $conn->prepare("UPDATE order_calls SET status = :st WHERE call_id = :cid");
    $upd->execute([':st' => $status, ':cid' => $call_id]);

    comm_out(true, 'Call status updated.', [
        'call_id' => $call_id,
        'status'  => $status,
    ]);
}

// -------------------------------------------------------------
// 4. GET UPDATES (Active calls + new messages)
// -------------------------------------------------------------
if ($action === 'get_updates' || $method === 'GET') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    $after_id = (int)($_GET['after_message_id'] ?? $_GET['after_id'] ?? $payload['after_message_id'] ?? 0);
    $customer_id = (int)($_GET['customer_id'] ?? $payload['customer_id'] ?? ($role === 'customer' ? $user_id : 0));

    // If no order_id provided, automatically resolve the customer's latest active order
    if ($order_id <= 0 && $customer_id > 0) {
        $ostmt = $conn->prepare("SELECT order_id FROM food_orders 
            WHERE customer_id = :cid 
            ORDER BY CASE 
                WHEN status IN ('out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer', 'arrived') THEN 1 
                WHEN status IN ('preparing', 'ready_for_pickup', 'accepted', 'claimed', 'pending') THEN 2 
                ELSE 3 END ASC, order_id DESC LIMIT 1");
        $ostmt->execute([':cid' => $customer_id]);
        $orow = $ostmt->fetch(PDO::FETCH_ASSOC);
        if ($orow) {
            $order_id = (int)$orow['order_id'];
        }
    }

    if ($order_id <= 0) {
        comm_out(true, 'No active order for customer.', [
            'order_id'           => 0,
            'status'             => 'none',
            'delivery_status'    => 'none',
            'is_arrived'         => false,
            'active_call'        => null,
            'messages'           => [],
            'new_messages_count' => 0,
            'latest_message_id'  => $after_id,
            'latest_banner'      => null
        ]);
    }

    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.customer_id, o.customer_name,
        COALESCE(o.customer_phone, cu.phone, '+63 917 123 4567') AS customer_phone,
        o.store_name, o.delivery_address, o.status, o.delivery_status,
        COALESCE(r.rider_name, ru.full_name, 'Alex Rider') AS rider_name,
        COALESCE(o.rider_phone, r.phone, ru.phone, '+63 917 555 0192') AS rider_phone
    FROM food_orders o
    LEFT JOIN riders r ON (o.rider_id = r.rider_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    LEFT JOIN users cu ON (o.customer_id = cu.user_id)
    WHERE o.order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        comm_out(true, 'Order not found.', [
            'order_id'           => 0,
            'status'             => 'none',
            'delivery_status'    => 'none',
            'is_arrived'         => false,
            'active_call'        => null,
            'messages'           => [],
            'new_messages_count' => 0,
            'latest_message_id'  => $after_id,
            'latest_banner'      => null
        ]);
    }

    // Default valid fallback phone numbers
    if (empty($order['customer_phone'])) $order['customer_phone'] = '+63 917 123 4567';
    if (empty($order['rider_phone'])) $order['rider_phone'] = '+63 917 555 0192';

    // Check for active ringing call created within the last 45 seconds
    $call_stmt = $conn->prepare("SELECT call_id, order_id, caller_role, caller_id, caller_name, caller_phone, receiver_role, receiver_id, status, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds FROM order_calls WHERE order_id = :oid AND status = 'ringing' AND created_at >= (NOW() - INTERVAL 45 SECOND) ORDER BY call_id DESC LIMIT 1");
    $call_stmt->execute([':oid' => $order_id]);
    $active_call = $call_stmt->fetch(PDO::FETCH_ASSOC);

    if ($active_call) {
        $active_call['call_id'] = (int)$active_call['call_id'];
        $active_call['order_id'] = (int)$active_call['order_id'];
        $active_call['elapsed_seconds'] = (int)$active_call['elapsed_seconds'];
    } else {
        $active_call = null;
    }

    // Fetch new messages since after_id
    $m_sql = "SELECT message_id, order_id, sender_role, sender_id, sender_name, message_text, created_at 
              FROM order_messages 
              WHERE order_id = :oid ";
    $m_params = [':oid' => $order_id];

    if ($after_id > 0) {
        $m_sql .= " AND message_id > :aid ";
        $m_params[':aid'] = $after_id;
    }
    $m_sql .= " ORDER BY message_id ASC LIMIT 50";

    $m_stmt = $conn->prepare($m_sql);
    $m_stmt->execute($m_params);
    $messages = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

    $latest_msg_id = $after_id;
    foreach ($messages as &$msg) {
        $msg['message_id'] = (int)$msg['message_id'];
        $msg['order_id'] = (int)$msg['order_id'];
        $msg['time_formatted'] = date('g:i A', strtotime($msg['created_at']));
        $msg['is_me'] = ($msg['sender_role'] === $role && (int)$msg['sender_id'] === $user_id);
        if ($msg['message_id'] > $latest_msg_id) {
            $latest_msg_id = $msg['message_id'];
        }
    }
    unset($msg);

    // Latest message overall for banner notifications
    $latest_banner = null;
    if (!empty($messages)) {
        $last_one = end($messages);
        $latest_banner = [
            'message_id'   => (int)$last_one['message_id'],
            'sender_role'  => $last_one['sender_role'],
            'sender_name'  => $last_one['sender_name'],
            'message_text' => $last_one['message_text'],
            'created_at'   => $last_one['created_at']
        ];
    }

    $is_arrived = (strtolower(trim((string)($order['delivery_status'] ?? ''))) === 'arrived' || strtolower(trim((string)($order['status'] ?? ''))) === 'arrived');

    comm_out(true, 'Updates retrieved.', [
        'order_id'           => $order_id,
        'order_ref'          => $order['order_ref'],
        'status'             => $order['status'] ?? 'none',
        'delivery_status'    => $order['delivery_status'] ?? 'none',
        'is_arrived'         => $is_arrived,
        'customer_name'      => $order['customer_name'] ?? 'Customer',
        'customer_phone'     => $order['customer_phone'],
        'rider_name'         => $order['rider_name'],
        'rider_phone'        => $order['rider_phone'],
        'active_call'        => $active_call,
        'messages'           => $messages,
        'new_messages_count' => count($messages),
        'latest_message_id'  => $latest_msg_id,
        'latest_banner'      => $latest_banner
    ]);
}

comm_out(false, 'Invalid action specified.', [], 400);
