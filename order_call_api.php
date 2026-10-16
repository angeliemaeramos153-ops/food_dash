<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function call_api_json(bool $success, string $message = '', array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    call_api_json(false, 'Unauthorized. Please log in.', [], 401);
}

$user_id = (int)$_SESSION['user_id'];
$role = strtolower(trim((string)$_SESSION['role']));
$user_name = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'User'));

// Ensure table exists with all required columns
try {
    $conn->exec("CREATE TABLE IF NOT EXISTS order_calls (
        call_id INT AUTO_INCREMENT PRIMARY KEY,
        order_id INT NOT NULL,
        caller_role VARCHAR(32) NOT NULL DEFAULT 'rider',
        caller VARCHAR(32) NOT NULL DEFAULT 'rider',
        caller_id INT NOT NULL DEFAULT 0,
        caller_name VARCHAR(128) NOT NULL DEFAULT 'Rider',
        caller_phone VARCHAR(64) NOT NULL DEFAULT '',
        receiver_role VARCHAR(32) NOT NULL DEFAULT 'customer',
        receiver_id INT NOT NULL DEFAULT 0,
        status VARCHAR(32) NOT NULL DEFAULT 'idle',
        call_status VARCHAR(32) NOT NULL DEFAULT 'idle',
        webrtc_signal TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_order_status (order_id, call_status),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$payload = is_array($json_input) ? $json_input : $_POST;

$action = $_GET['action'] ?? $payload['action'] ?? '';
if (empty($action) && $method === 'GET') {
    $action = 'get_status';
}

// -------------------------------------------------------------
// 1. START / TRIGGER CALL
// -------------------------------------------------------------
if ($action === 'start_call' || $action === 'trigger_call' || $action === 'call') {
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        call_api_json(false, 'Valid order_id is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, customer_phone, rider_id, rider_phone, order_ref, status FROM food_orders WHERE order_id = :oid LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        call_api_json(false, 'Order not found.', [], 404);
    }

    $customer_id = (int)($order['customer_id'] ?? 0);
    $customer_phone = !empty($order['customer_phone']) ? trim($order['customer_phone']) : '+63 917 123 4567';
    $rider_phone = !empty($order['rider_phone']) ? trim($order['rider_phone']) : '+63 917 555 0192';

    // Mark previous active calls on this order as ended/missed
    $upd = $conn->prepare("UPDATE order_calls SET status = 'ended', call_status = 'ended' WHERE order_id = :oid AND call_status IN ('calling', 'ringing', 'connected')");
    $upd->execute([':oid' => $order_id]);

    $caller_role = ($role === 'customer') ? 'customer' : 'rider';
    $receiver_role = ($caller_role === 'rider') ? 'customer' : 'rider';
    $receiver_id = ($caller_role === 'rider') ? $customer_id : (int)($order['rider_id'] ?? 0);
    $caller_phone = ($caller_role === 'rider') ? $rider_phone : $customer_phone;
    $receiver_name = ($caller_role === 'rider') ? ($order['customer_name'] ?? 'Customer') : 'Rider';

    $ins = $conn->prepare("INSERT INTO order_calls (
        order_id, caller_role, caller, caller_id, caller_name, caller_phone,
        receiver_role, receiver_id, status, call_status, created_at, updated_at
    ) VALUES (
        :oid, :crole, :caller, :cid, :cname, :cphone,
        :rrole, :rid, 'calling', 'calling', NOW(), NOW()
    )");
    $ins->execute([
        ':oid'    => $order_id,
        ':crole'  => $caller_role,
        ':caller' => $caller_role,
        ':cid'    => $user_id,
        ':cname'  => $user_name,
        ':cphone' => $caller_phone,
        ':rrole'  => $receiver_role,
        ':rid'    => $receiver_id
    ]);
    $call_id = (int)$conn->lastInsertId();

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
    } catch (Throwable $e) {}

    call_api_json(true, 'Call started.', [
        'call_id'       => $call_id,
        'order_id'      => $order_id,
        'call_status'   => 'calling',
        'status'        => 'calling',
        'caller'        => $caller_role,
        'caller_role'   => $caller_role,
        'caller_name'   => $user_name,
        'caller_phone'  => $caller_phone,
        'receiver_name' => $receiver_name,
        'receiver_role' => $receiver_role,
        'timestamp'     => time(),
        'created_at'    => date('Y-m-d H:i:s')
    ]);
}

// -------------------------------------------------------------
// 2. GET CALL STATUS / POLL
// -------------------------------------------------------------
if ($action === 'get_status' || $action === 'poll') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    $customer_id = (int)($_GET['customer_id'] ?? $payload['customer_id'] ?? ($role === 'customer' ? $user_id : 0));

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
        call_api_json(true, 'No active order.', [
            'order_id'    => 0,
            'call_status' => 'idle',
            'status'      => 'idle',
            'caller'      => null,
            'timestamp'   => time(),
            'active_call' => null
        ]);
    }

    // Look for active call on this order within 90 seconds
    $stmt = $conn->prepare("SELECT 
        call_id, order_id, caller_role, COALESCE(caller, caller_role) AS caller,
        caller_id, caller_name, caller_phone, receiver_role, receiver_id,
        COALESCE(call_status, status) AS call_status, status,
        webrtc_signal, created_at, updated_at,
        TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds,
        TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_update
    FROM order_calls 
    WHERE order_id = :oid 
    ORDER BY call_id DESC LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $call = $stmt->fetch(PDO::FETCH_ASSOC);

    $normalized_status = 'idle';
    if ($call) {
        $raw_st = strtolower(trim((string)$call['call_status']));
        if ($raw_st === 'ringing') $raw_st = 'calling';
        if ($raw_st === 'accepted') $raw_st = 'connected';

        $age = (int)$call['seconds_since_update'];

        // Auto-expire unanswered calling after 45s, or ended calls older than 5s
        if ($raw_st === 'calling' && $age > 45) {
            $conn->prepare("UPDATE order_calls SET call_status = 'missed', status = 'missed' WHERE call_id = :cid")->execute([':cid' => $call['call_id']]);
            $raw_st = 'idle';
        } elseif (in_array($raw_st, ['ended', 'declined', 'missed'], true) && $age > 10) {
            $raw_st = 'idle';
        }

        $normalized_status = $raw_st;
    }

    call_api_json(true, 'Call status retrieved.', [
        'order_id'       => $order_id,
        'call_status'    => $normalized_status,
        'status'         => $normalized_status,
        'caller'         => $call['caller'] ?? null,
        'caller_role'    => $call['caller_role'] ?? null,
        'caller_name'    => $call['caller_name'] ?? '',
        'caller_phone'   => $call['caller_phone'] ?? '',
        'receiver_role'  => $call['receiver_role'] ?? '',
        'receiver_id'    => (int)($call['receiver_id'] ?? 0),
        'call_id'        => (int)($call['call_id'] ?? 0),
        'elapsed_seconds'=> (int)($call['elapsed_seconds'] ?? 0),
        'timestamp'      => time(),
        'active_call'    => ($normalized_status !== 'idle') ? $call : null
    ]);
}

// -------------------------------------------------------------
// 3. ACCEPT / CONNECT CALL
// -------------------------------------------------------------
if ($action === 'accept_call' || $action === 'connect') {
    $call_id = (int)($payload['call_id'] ?? $_GET['call_id'] ?? 0);
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);

    if ($call_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'connected', status = 'accepted', updated_at = NOW() WHERE call_id = :cid");
        $upd->execute([':cid' => $call_id]);
    } elseif ($order_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'connected', status = 'accepted', updated_at = NOW() WHERE order_id = :oid AND call_status IN ('calling', 'ringing')");
        $upd->execute([':oid' => $order_id]);
    } else {
        call_api_json(false, 'Valid call_id or order_id required.', [], 422);
    }

    call_api_json(true, 'Call connected.', [
        'call_status' => 'connected',
        'status'      => 'connected',
        'timestamp'   => time()
    ]);
}

// -------------------------------------------------------------
// 4. DECLINE CALL
// -------------------------------------------------------------
if ($action === 'decline_call' || $action === 'decline') {
    $call_id = (int)($payload['call_id'] ?? $_GET['call_id'] ?? 0);
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);

    if ($call_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'declined', status = 'declined', updated_at = NOW() WHERE call_id = :cid");
        $upd->execute([':cid' => $call_id]);
    } elseif ($order_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'declined', status = 'declined', updated_at = NOW() WHERE order_id = :oid");
        $upd->execute([':oid' => $order_id]);
    }

    call_api_json(true, 'Call declined.', [
        'call_status' => 'declined',
        'status'      => 'declined',
        'timestamp'   => time()
    ]);
}

// -------------------------------------------------------------
// 5. END CALL / RESET TO IDLE
// -------------------------------------------------------------
if ($action === 'end_call' || $action === 'end' || $action === 'hangup' || $action === 'reset') {
    $call_id = (int)($payload['call_id'] ?? $_GET['call_id'] ?? 0);
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);

    if ($call_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'ended', status = 'ended', updated_at = NOW() WHERE call_id = :cid");
        $upd->execute([':cid' => $call_id]);
    } elseif ($order_id > 0) {
        $upd = $conn->prepare("UPDATE order_calls SET call_status = 'ended', status = 'ended', updated_at = NOW() WHERE order_id = :oid");
        $upd->execute([':oid' => $order_id]);
    }

    call_api_json(true, 'Call ended.', [
        'call_status' => 'ended',
        'status'      => 'ended',
        'timestamp'   => time()
    ]);
}

// -------------------------------------------------------------
// 6. WEBRTC SIGNALING (Optional SDP / ICE relay)
// -------------------------------------------------------------
if ($action === 'signal' || $action === 'send_signal') {
    $order_id = (int)($payload['order_id'] ?? 0);
    $signal = trim((string)($payload['signal'] ?? ''));

    if ($order_id > 0 && !empty($signal)) {
        $upd = $conn->prepare("UPDATE order_calls SET webrtc_signal = :sig, updated_at = NOW() WHERE order_id = :oid ORDER BY call_id DESC LIMIT 1");
        $upd->execute([':sig' => $signal, ':oid' => $order_id]);
    }

    call_api_json(true, 'Signal received.');
}

call_api_json(false, 'Invalid action specified.', [], 400);
