<?php
/**
 * Food Dash - Rider Action Handler API Endpoint
 * Handles rider order acceptance, status transitions ('on_delivery', 'on_way', 'near', 'arrived', 'delivered'),
 * and returns key order details in its JSON response (including order_number and total_amount).
 */
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/notification_lib.php';
require_once __DIR__ . '/geocode_lib.php';
require_once __DIR__ . '/payment_helper.php';

if (!headers_sent()) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function rider_json_out(bool $success, string $message = '', array $extra = [], int $status_code = 200): never {
    if (ob_get_level()) ob_clean();
    if (!headers_sent()) {
        http_response_code($status_code);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// 1. Resolve Request Payload
$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}
if (empty($payload) && !empty($_GET)) {
    $payload = $_GET;
}

$action = trim((string)($payload['delivery_action'] ?? $payload['action'] ?? $_GET['action'] ?? ''));
$target_status = strtolower(trim((string)($payload['status'] ?? '')));

if (empty($action) && !empty($target_status)) {
    $action = $target_status;
}

// 2. Resolve Rider Identity
$user_id = (int)($_SESSION['user_id'] ?? $payload['user_id'] ?? $payload['rider_user_id'] ?? 0);
$rid = (int)($payload['rider_id'] ?? 0);
$rider = null;

if ($rid > 0) {
    $stmt = $conn->prepare("SELECT rider_id, user_id, rider_name, phone, status, current_order FROM riders WHERE rider_id = :rid LIMIT 1");
    $stmt->execute([':rid' => $rid]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$rider && $user_id > 0) {
    $stmt = $conn->prepare("SELECT rider_id, user_id, rider_name, phone, status, current_order FROM riders WHERE user_id = :uid LIMIT 1");
    $stmt->execute([':uid' => $user_id]);
    $rider = $stmt->fetch(PDO::FETCH_ASSOC);
}

if (!$rider) {
    $q = $conn->prepare("SELECT rider_id, user_id, rider_name, phone, status, current_order FROM riders ORDER BY rider_id ASC LIMIT 1");
    $q->execute();
    $rider = $q->fetch(PDO::FETCH_ASSOC);
}

$rid = $rider ? (int)$rider['rider_id'] : 1;
$rider_user_id = $rider && !empty($rider['user_id']) ? (int)$rider['user_id'] : ($user_id ?: 17);
$rider_name = $rider['rider_name'] ?? ($_SESSION['full_name'] ?? 'Alex Gonzaga');

// 3. Resolve Order ID
$raw_order_id = $payload['order_id'] ?? $payload['id'] ?? $payload['order_ref'] ?? $payload['order_number'] ?? $_GET['order_id'] ?? 0;
$order_id = 0;

if (is_numeric($raw_order_id)) {
    $order_id = (int)$raw_order_id;
} elseif (is_string($raw_order_id) && !empty($raw_order_id)) {
    $clean_ref = ltrim(trim($raw_order_id), '#');
    $q_chk = $conn->prepare("SELECT order_id FROM food_orders WHERE order_ref = :ref OR order_ref = :raw_ref OR order_ref LIKE :like_ref LIMIT 1");
    $q_chk->execute([':ref' => '#' . $clean_ref, ':raw_ref' => $clean_ref, ':like_ref' => '%' . $clean_ref . '%']);
    $order_id = (int)($q_chk->fetchColumn() ?: 0);
    if ($order_id <= 0) {
        $q_chk2 = $conn->prepare("SELECT id FROM orders WHERE order_number = :ref OR order_number = :raw_ref OR order_number LIKE :like_ref LIMIT 1");
        $q_chk2->execute([':ref' => '#' . $clean_ref, ':raw_ref' => $clean_ref, ':like_ref' => '%' . $clean_ref . '%']);
        $order_id = (int)($q_chk2->fetchColumn() ?: 0);
    }
}

// If no order_id passed, check rider's active delivery or latest ready order
if ($order_id <= 0) {
    if (!empty($rider['current_order']) && $rider['current_order'] !== 'None') {
        $clean_cur = ltrim($rider['current_order'], '#');
        $q_cur = $conn->prepare("SELECT order_id FROM food_orders WHERE order_ref = :ref OR order_ref = :raw_ref LIMIT 1");
        $q_cur->execute([':ref' => '#' . $clean_cur, ':raw_ref' => $clean_cur]);
        $order_id = (int)($q_cur->fetchColumn() ?: 0);
    }
    if ($order_id <= 0 && in_array($action, ['accept', 'claim', 'pickup', 'accept_order', 'on_delivery'], true)) {
        // Pick the oldest ready/pending order waiting for delivery
        $q_wait = $conn->prepare("SELECT order_id FROM food_orders WHERE LOWER(TRIM(status)) IN ('ready_for_pickup', 'ready', 'preparing', 'pending') ORDER BY order_id ASC LIMIT 1");
        $q_wait->execute();
        $order_id = (int)($q_wait ? $q_wait->fetchColumn() : 0);
    }
}

// 4. GET REQUEST OR QUERY ORDERS
if ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($action)) {
    // Return active rider state and ready orders
    $ready_orders = [];
    try {
        $q_r = $conn->prepare("SELECT order_id, order_ref, customer_name, store_name, total_amount, payment_method, delivery_address, status FROM food_orders WHERE LOWER(TRIM(status)) IN ('ready_for_pickup', 'ready', 'pending', 'preparing') ORDER BY order_id ASC LIMIT 10");
        $q_r->execute();
        $ready_orders = $q_r ? $q_r->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (Throwable $e) {}

    rider_json_out(true, 'Rider status retrieved.', [
        'rider'        => $rider,
        'ready_orders' => $ready_orders
    ]);
}

// 5. ACTION: CLEAR ACTIVE DELIVERY
if ($action === 'clear_order' || $action === 'finish_delivery') {
    if ($order_id > 0) {
        try {
            $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', rider_cleared = 1, cleared_at = NOW(), updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
            $conn->prepare("UPDATE orders SET status = 'delivered', rider_cleared = 1, updated_at = NOW() WHERE id = :oid OR order_id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
            record_order_audit_log($conn, $order_id, 'DELIVERED', 'DELIVERED', $rider_user_id, 'rider', 'Delivery finished & cleared by rider ' . $rider_name);
        } catch (Throwable $e) {}
    }
    try {
        $stmt = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None', active_order_id = NULL WHERE rider_id = :rid");
        $stmt->execute([':rid' => $rid]);
    } catch (Throwable $e) {}

    unset($_SESSION['active_order_id'], $_SESSION['rider_current_order'], $_SESSION['rider_active_delivery']);

    rider_json_out(true, 'Active delivery cleared! Ready for your next order.', [
        'cleared_order_id' => $order_id,
        'rider_status'     => 'waiting'
    ]);
}

if ($order_id <= 0) {
    rider_json_out(false, 'Valid order_id is required.', [], 422);
}

// 6. Fetch Existing Order from food_orders and orders
$stmt_fo = $conn->prepare("SELECT order_id, customer_id, user_id, customer_name, customer_phone, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, delivery_address, status, delivery_status, rider_id FROM food_orders WHERE order_id = :oid LIMIT 1");
$stmt_fo->execute([':oid' => $order_id]);
$fo = $stmt_fo->fetch(PDO::FETCH_ASSOC);

$stmt_ord = $conn->prepare("SELECT id, order_id, user_id, customer_id, order_number, store_name, items_summary, total_price, total_amount, payment_method, delivery_address, status, rider_id FROM orders WHERE id = :oid OR order_id = :oid2 LIMIT 1");
$stmt_ord->execute([':oid' => $order_id, ':oid2' => $order_id]);
$ord = $stmt_ord->fetch(PDO::FETCH_ASSOC);

if (!$fo && !$ord) {
    rider_json_out(false, 'Order not found.', [], 404);
}

// Derive canonical order reference and order number
$raw_ref = !empty($fo['order_ref']) ? $fo['order_ref'] : (!empty($ord['order_number']) ? $ord['order_number'] : ('FD-' . $order_id));
$clean_order_number = ltrim($raw_ref, '#');
$full_order_number = '#' . $clean_order_number;

// Calculate real total amount directly from database values and items
$real_total_amount = (float)(
    (!empty($fo['total_amount']) && (float)$fo['total_amount'] > 0)
        ? $fo['total_amount']
        : ((!empty($ord['total_price']) && (float)$ord['total_price'] > 0)
            ? $ord['total_price']
            : ($ord['total_amount'] ?? 0))
);

if ($real_total_amount <= 0) {
    try {
        $item_sum_stmt = $conn->prepare("SELECT COALESCE(SUM(COALESCE(line_total, price * quantity, unit_price * quantity, 0)), 0) FROM order_items WHERE order_id = :oid");
        $item_sum_stmt->execute([':oid' => $order_id]);
        $sum = (float)$item_sum_stmt->fetchColumn();
        if ($sum <= 0) {
            $item_sum_stmt2 = $conn->prepare("SELECT COALESCE(SUM(COALESCE(line_total, price * quantity, unit_price * quantity, 0)), 0) FROM food_order_items WHERE order_id = :oid");
            $item_sum_stmt2->execute([':oid' => $order_id]);
            $sum = (float)$item_sum_stmt2->fetchColumn();
        }
        $fee = isset($fo['delivery_fee']) ? (float)$fo['delivery_fee'] : 30.00;
        if ($sum > 0) {
            $real_total_amount = round($sum + $fee, 2);
        }
    } catch (Throwable $e) {}
}

$formatted_total_amount = number_format($real_total_amount, 2);
$actual_customer_id = (int)($fo['customer_id'] ?? $fo['user_id'] ?? $ord['user_id'] ?? $ord['customer_id'] ?? 0);
$customer_name = $fo['customer_name'] ?? 'Customer';
$store_name = $fo['store_name'] ?? $ord['store_name'] ?? 'Partner Store';
$delivery_address = $fo['delivery_address'] ?? $ord['delivery_address'] ?? '';
$payment_method = $fo['payment_method'] ?? $ord['payment_method'] ?? 'Cash on Delivery (COD)';
$old_st = $fo['status'] ?? $ord['status'] ?? 'pending';

// -------------------------------------------------------------
// 7. RIDER ACCEPTANCE / PICKUP / ON_DELIVERY
// -------------------------------------------------------------
$is_accept_action = in_array($action, ['accept', 'claim', 'pickup', 'accept_order', 'on_delivery', 'claim_order'], true) 
                 || $target_status === 'on_delivery' 
                 || $target_status === 'out_for_delivery';

if ($is_accept_action) {
    // 1. Update food_orders
    $stmt = $conn->prepare("UPDATE food_orders SET rider_id = :rid, status = 'on_delivery', delivery_status = 'on_the_way', updated_at = NOW() WHERE order_id = :oid");
    $stmt->execute([':rid' => $rid, ':oid' => $order_id]);

    // 2. Update riders table
    $stmt = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref, active_order_id = :oid WHERE rider_id = :rid");
    $stmt->execute([':ref' => $full_order_number, ':oid' => $order_id, ':rid' => $rid]);

    // 3. Update orders compatibility table (both status, rider_id, and ensure total_amount & total_price match)
    try {
        $conn->prepare("UPDATE orders SET status = 'on_delivery', rider_id = :rid, total_price = :tot, total_amount = :tot2, updated_at = NOW() WHERE id = :oid OR order_id = :oid2")->execute([
            ':rid'  => $rid,
            ':tot'  => $real_total_amount,
            ':tot2' => $real_total_amount,
            ':oid'  => $order_id,
            ':oid2' => $order_id
        ]);
    } catch (Throwable $e) {}

    // 4. Construct dynamic customer notification message with exact required details
    $dynamic_notif_title = "Order Out for Delivery";
    $dynamic_notif_message = "Order #{$clean_order_number} has been picked up! Total due: ₱{$formatted_total_amount}";

    // Record order audit log
    record_order_audit_log($conn, $order_id, $old_st, 'on_delivery', $rider_user_id, 'rider', 'Order accepted and picked up by rider ' . $rider_name);

    // 5. Insert notification into notifications table for customer
    if ($actual_customer_id > 0) {
        create_notification(
            $conn,
            $actual_customer_id,
            'rider_assigned',
            $dynamic_notif_title,
            $dynamic_notif_message,
            $order_id
        );
    }

    // Notify Admin / Kitchen
    notify_role($conn, 'admin', 'Rider Dispatched', "Rider {$rider_name} confirmed pickup of order #{$clean_order_number} (Total: ₱{$formatted_total_amount})", $order_id);

    // Automated departure chat message in order_messages
    $auto_chat_text = "Hi! Your order has been picked up by the rider and is now on the way. Total amount due: ₱{$formatted_total_amount}.";
    try {
        $chkMsg = $conn->prepare("SELECT message_id FROM order_messages WHERE order_id = :oid AND (message_text LIKE '%picked up by the rider%' OR message_text LIKE '%Total amount due:%') LIMIT 1");
        $chkMsg->execute([':oid' => $order_id]);
        if (!$chkMsg->fetch()) {
            $insMsg = $conn->prepare("
                INSERT INTO order_messages (order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at)
                VALUES (:oid, 'rider', :uid, :rec, :name, :txt, NOW())
            ");
            $insMsg->execute([
                ':oid'  => $order_id,
                ':uid'  => (int)$rider_user_id,
                ':rec'  => (int)$actual_customer_id,
                ':name' => $rider_name ?: 'Rider',
                ':txt'  => $auto_chat_text
            ]);
        }
    } catch (Throwable $e) {
        error_log('Error inserting auto chat message in rider_action: ' . $e->getMessage());
    }

    try {
        if (function_exists('send_on_delivery_chat_message')) {
            send_on_delivery_chat_message($conn, $order_id, $rider_user_id, $rider_name);
        }
    } catch (Throwable $e) {}

    // Mirror to Supabase if configured
    try {
        if (file_exists(__DIR__ . '/supabase_lib.php')) {
            require_once __DIR__ . '/supabase_lib.php';
            if (function_exists('sync_order_to_supabase')) {
                sync_order_to_supabase($order_id, $conn);
            }
        }
    } catch (Throwable $e) {}

    $_SESSION['active_order_id'] = $order_id;
    $_SESSION['rider_current_order'] = $full_order_number;

    // Return key order details in its JSON response (including order_number and total_amount)
    rider_json_out(true, 'Order picked up! Status transitioned to On Delivery.', [
        'order_id'               => $order_id,
        'order_number'           => $full_order_number,
        'order_ref'              => $full_order_number,
        'clean_order_number'     => $clean_order_number,
        'total_amount'           => (float)$real_total_amount,
        'total_price'            => (float)$real_total_amount,
        'formatted_total_amount' => '₱' . $formatted_total_amount,
        'formatted_total'        => '₱' . $formatted_total_amount,
        'status'                 => 'on_delivery',
        'delivery_status'        => 'on_the_way',
        'customer_id'            => $actual_customer_id,
        'customer_name'          => $customer_name,
        'store_name'             => $store_name,
        'delivery_address'       => $delivery_address,
        'payment_method'         => $payment_method,
        'rider_id'               => $rid,
        'rider_name'             => $rider_name,
        'data'                   => [
            'order_id'               => $order_id,
            'order_number'           => $full_order_number,
            'order_ref'              => $full_order_number,
            'clean_order_number'     => $clean_order_number,
            'total_amount'           => (float)$real_total_amount,
            'total_price'            => (float)$real_total_amount,
            'formatted_total_amount' => '₱' . $formatted_total_amount,
            'formatted_total'        => '₱' . $formatted_total_amount,
            'status'                 => 'on_delivery',
            'delivery_status'        => 'on_the_way'
        ]
    ]);
}

// -------------------------------------------------------------
// 8. RIDER SUB-STATES & COMPLETION
// -------------------------------------------------------------
if ($action === 'on_way' || $action === 'on_the_way') {
    $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', status = 'on_delivery', arrived_notified_at = NULL, arrival_acknowledged_at = NULL, arrival_ack_response = NULL, updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
    try {
        $conn->prepare("UPDATE orders SET status = 'on_delivery', arrived_notified_at = NULL, updated_at = NOW() WHERE order_id = :oid OR id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
    } catch (Throwable $e) {}
    record_order_audit_log($conn, $order_id, 'on_delivery', 'on_delivery', $rider_user_id, 'rider', 'Rider is en route to customer');
    create_notification($conn, $actual_customer_id, 'rider_on_way', 'Rider On the Way', 'Rider ' . $rider_name . ' is heading towards your delivery address.', $order_id);

    rider_json_out(true, 'Customer notified that you are on the way.', [
        'order_id'        => $order_id,
        'order_number'    => $full_order_number,
        'total_amount'    => (float)$real_total_amount,
        'status'          => 'on_delivery',
        'delivery_status' => 'on_the_way'
    ]);
}

if ($action === 'near' || $action === 'near_customer') {
    $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', status = 'on_delivery', arrived_notified_at = NULL, arrival_acknowledged_at = NULL, arrival_ack_response = NULL, updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
    try {
        $conn->prepare("UPDATE orders SET status = 'on_delivery', arrived_notified_at = NULL, updated_at = NOW() WHERE order_id = :oid OR id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
    } catch (Throwable $e) {}
    record_order_audit_log($conn, $order_id, 'on_delivery', 'on_delivery', $rider_user_id, 'rider', 'Rider is near customer drop-off');
    create_notification($conn, $actual_customer_id, 'rider_near', 'Rider Arriving Soon', 'Your rider is near your delivery address. Please prepare to receive ' . $full_order_number . '.', $order_id);

    rider_json_out(true, 'Customer notified that you are near.', [
        'order_id'        => $order_id,
        'order_number'    => $full_order_number,
        'total_amount'    => (float)$real_total_amount,
        'status'          => 'on_delivery',
        'delivery_status' => 'near_customer'
    ]);
}

if ($action === 'reset_to_store' || $action === 'reset_store' || $action === 'reset') {
    $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', status = 'on_delivery', arrived_notified_at = NULL, arrival_acknowledged_at = NULL, arrival_ack_response = NULL, updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
    try {
        $conn->prepare("UPDATE orders SET status = 'on_delivery', arrived_notified_at = NULL, updated_at = NOW() WHERE order_id = :oid OR id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
    } catch (Throwable $e) {}
    try {
        require_once __DIR__ . '/supabase_lib.php';
        sync_order_to_supabase($order_id, $conn);
    } catch (Throwable $e) {}
    rider_json_out(true, 'Rider reset to store origin.', [
        'order_id'        => $order_id,
        'order_number'    => $full_order_number,
        'total_amount'    => (float)$real_total_amount,
        'status'          => 'on_delivery',
        'delivery_status' => 'on_the_way'
    ]);
}

if ($action === 'arrived' || $action === 'arrived_destination') {
    $conn->prepare("UPDATE food_orders SET delivery_status = 'arrived', status = 'ARRIVED', arrived_notified_at = NOW(), updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
    try {
        $conn->prepare("UPDATE orders SET status = 'arrived', arrived_notified_at = NOW() WHERE order_id = :oid OR id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
    } catch (Throwable $e) {}

    record_order_audit_log($conn, $order_id, 'on_delivery', 'ARRIVED', $rider_user_id, 'rider', 'Rider arrived at customer delivery address');
    $arrived_msg = "📍 Your rider has arrived at your delivery address! Please meet outside to receive your order.";
    create_notification($conn, $actual_customer_id, 'rider_arrived', 'Your rider has arrived!', $arrived_msg, $order_id);

    rider_json_out(true, 'Customer notified that you have arrived at destination!', [
        'order_id'        => $order_id,
        'order_number'    => $full_order_number,
        'total_amount'    => (float)$real_total_amount,
        'status'          => 'arrived',
        'delivery_status' => 'arrived'
    ]);
}

if ($action === 'delivered') {
    $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', updated_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
    try {
        $conn->prepare("UPDATE orders SET status = 'delivered', updated_at = NOW() WHERE order_id = :oid OR id = :oid2")->execute([':oid' => $order_id, ':oid2' => $order_id]);
    } catch (Throwable $e) {}

    record_order_audit_log($conn, $order_id, 'on_delivery', 'DELIVERED', $rider_user_id, 'rider', 'Order successfully delivered to customer by ' . $rider_name);
    create_notification($conn, $actual_customer_id, 'delivered', 'Order Delivered!', 'Your order ' . $full_order_number . ' has been marked delivered. Enjoy your meal!', $order_id);

    rider_json_out(true, 'Order marked as delivered.', [
        'order_id'        => $order_id,
        'order_number'    => $full_order_number,
        'total_amount'    => (float)$real_total_amount,
        'status'          => 'delivered',
        'delivery_status' => 'delivered'
    ]);
}

rider_json_out(false, 'Unsupported action: ' . $action, [], 400);
