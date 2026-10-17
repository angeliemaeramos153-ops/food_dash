<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(bool $success, string $message = '', array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

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

    // Verify order access
    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status, delivery_status FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        json_out(false, 'Order not found.', [], 404);
    }

    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    $customer_id = (int)($order['customer_id'] ?? 0);

    // Permission check: Customer must match customer_id, or Rider must match rider_id (or demo)
    if ($role === 'customer' && $customer_id !== $user_id && !$is_demo) {
        json_out(false, 'Access denied.', [], 403);
    }

    $scope_condition = $is_demo 
        ? "(sender_id = :uid OR receiver_id = :uid OR sender_id = :cid OR receiver_id = :cid)"
        : "(sender_id = :uid OR receiver_id = :uid)";

    $sql = "SELECT message_id, order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at 
            FROM order_messages 
            WHERE order_id = :oid AND {$scope_condition} ";
    $params = [':oid' => $order_id, ':uid' => $user_id];
    if ($is_demo) {
        $params[':cid'] = $customer_id ?: $user_id;
    }

    if ($after_id > 0) {
        $sql .= " AND message_id > :after_id ";
        $params[':after_id'] = $after_id;
    }

    $sql .= " ORDER BY message_id ASC LIMIT 100";
    $m_stmt = $conn->prepare($sql);
    $m_stmt->execute($params);
    $messages = $m_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Format timestamps nicely
    foreach ($messages as &$msg) {
        $msg['message_id'] = (int)$msg['message_id'];
        $msg['order_id'] = (int)$msg['order_id'];
        $msg['sender_id'] = (int)$msg['sender_id'];
        $msg['receiver_id'] = (int)($msg['receiver_id'] ?? 0);
        $msg['time_formatted'] = date('g:i A', strtotime($msg['created_at']));
        $msg['is_me'] = ($msg['sender_role'] === $role && ($msg['sender_id'] === $user_id || ($is_demo && in_array($msg['sender_id'], [15, 26], true))));
    }
    unset($msg);

    // Resolve contacts with fallbacks
    $cust_phone = !empty($order['customer_phone']) ? $order['customer_phone'] : '+63 928 555 0148';
    $rider_phone = !empty($order['rider_phone']) ? $order['rider_phone'] : '+63 917 555 0192';

    json_out(true, 'Messages loaded.', [
        'order_id'       => $order_id,
        'order_ref'      => $order['order_ref'],
        'messages'       => $messages,
        'customer_name'  => $order['customer_name'] ?? 'Customer',
        'customer_phone' => $cust_phone,
        'rider_phone'    => $rider_phone,
    ]);
}

// POST: Send a new message
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;

    $order_id = (int)($payload['order_id'] ?? 0);
    $text = trim((string)($payload['message_text'] ?? $payload['text'] ?? ''));

    if ($order_id <= 0) {
        json_out(false, 'Valid order_id is required.', [], 400);
    }
    if ($text === '') {
        json_out(false, 'Message text cannot be empty.', [], 422);
    }

    // Verify order
    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status, delivery_status FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        json_out(false, 'Order not found.', [], 404);
    }

    $sender_role = ($role === 'rider') ? 'rider' : 'customer';
    $receiver_id = 0;
    if ($sender_role === 'customer') {
        $rider_id = (int)($order['rider_id'] ?? 0);
        if ($rider_id > 0) {
            $rq = $conn->prepare("SELECT user_id FROM riders WHERE rider_id = :rid LIMIT 1");
            $rq->execute([':rid' => $rider_id]);
            $receiver_id = (int)$rq->fetchColumn();
        }
        if ($receiver_id <= 0) $receiver_id = 17;
    } else {
        $receiver_id = (int)($order['customer_id'] ?? 0);
        if ($receiver_id <= 0) $receiver_id = 26;
    }

    // Insert message using prepared statement
    $ins = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at) VALUES (:oid, :role, :uid, :rec, :name, :txt, NOW())");
    $ins->execute([
        ':oid'  => $order_id,
        ':role' => $sender_role,
        ':uid'  => $user_id,
        ':rec'  => $receiver_id,
        ':name' => $user_name,
        ':txt'  => $text
    ]);
    $new_id = (int)$conn->lastInsertId();

    // Trigger in-app notification to counter-party
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
            // Find user_id of rider
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
        // Notification failure should not break messaging
        error_log('Notification error: ' . $e->getMessage());
    }

    json_out(true, 'Message sent successfully.', [
        'message_id' => $new_id,
        'order_id'   => $order_id,
        'sender_role'=> $sender_role,
        'sender_name'=> $user_name,
        'message_text'=> $text,
        'time_formatted' => date('g:i A'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);
}

json_out(false, 'Method not allowed.', [], 405);
