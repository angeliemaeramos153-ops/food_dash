<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'payment_helper.php';

header('Content-Type: application/json; charset=utf-8');

function order_api_out(bool $success, string $message = '', array $extra = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['user_id'])) {
    order_api_out(false, 'Unauthorized. Please log in.', [], 401);
}

$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';
$role = strtolower(trim((string)($_SESSION['role'] ?? 'customer')));

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$action = $_GET['action'] ?? '';

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$payload = is_array($json_input) ? $json_input : $_POST;

if ($method === 'POST' && !empty($payload['action'])) {
    $action = $payload['action'];
}

// 1. CANCEL ORDER
if ($action === 'cancel_order' || $action === 'cancel') {
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);
    $reason = trim((string)($payload['reason'] ?? $_GET['reason'] ?? 'Changed mind, duplicate order, wrong address'));

    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $conn->beginTransaction();
    try {
        $stmt = $conn->prepare("SELECT order_id, customer_id, order_ref, status, delivery_status, rider_id, store_name, total_amount FROM food_orders WHERE order_id = :oid LIMIT 1 FOR UPDATE");
        $stmt->execute([':oid' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $conn->rollBack();
            order_api_out(false, 'Order not found.', [], 404);
        }

        // Must be the owner or admin
        if ((int)$order['customer_id'] !== $user_id && $role !== 'admin') {
            $conn->rollBack();
            order_api_out(false, 'You are not authorized to cancel this order.', [], 403);
        }

        $raw_status = strtoupper(trim(str_replace([' ', '-'], '_', $order['status'] ?? '')));
        $del_status = strtolower(trim($order['delivery_status'] ?? ''));

        // Business Rule Guardrail: If an order is already "On Delivery" or "Delivered", disable cancellation
        $is_out_for_delivery = in_array($raw_status, ['OUT_FOR_DELIVERY', 'ON_THE_WAY', 'ON_DELIVERY', 'NEAR_CUSTOMER', 'ARRIVED'], true) 
                            || in_array($del_status, ['out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer', 'arrived'], true);
        $is_delivered = in_array($raw_status, ['DELIVERED', 'COMPLETED', 'SETTLED'], true) 
                     || in_array($del_status, ['delivered', 'completed'], true);

        if ($is_out_for_delivery || $is_delivered) {
            $conn->rollBack();
            order_api_out(false, 'Orders out for delivery cannot be cancelled. Please contact support or rider.', [
                'order_id'       => $order_id,
                'status'         => $order['status'],
                'delivery_status'=> $order['delivery_status']
            ], 422);
        }

        // Verify active status is Pending or Preparing
        $can_cancel = in_array($raw_status, ['PENDING', 'SUBMITTED', 'PREPARING', 'ACCEPTED', 'READY_FOR_PICKUP', 'READY'], true);
        if (!$can_cancel) {
            $conn->rollBack();
            order_api_out(false, 'This order cannot be cancelled in its current status (' . $order['status'] . ').', [
                'order_id' => $order_id,
                'status'   => $order['status']
            ], 422);
        }

        // a. Update the order status in food_orders and orders table to 'Cancelled'
        $upd = $conn->prepare("UPDATE food_orders SET status = 'CANCELLED', delivery_status = 'cancelled', updated_at = NOW() WHERE order_id = :oid");
        $upd->execute([':oid' => $order_id]);

        try {
            $conn->prepare("UPDATE orders SET status = 'Cancelled' WHERE order_id = :oid")->execute([':oid' => $order_id]);
        } catch (Throwable $e) {}

        // b. Post an entry to the order status history/timeline (order_audit_logs)
        $audit_note = "Order cancelled by customer. Reason: " . $reason;
        record_order_audit_log($conn, $order_id, $raw_status, 'CANCELLED', $user_id, 'customer', $audit_note);

        // c. If a rider was assigned or viewing, notify the rider view to release the order back to available status
        $rider_id_val = !empty($order['rider_id']) ? (int)$order['rider_id'] : 0;
        if ($rider_id_val > 0) {
            try {
                $u_rider = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid OR user_id = :rid");
                $u_rider->execute([':rid' => $rider_id_val]);

                create_notification(
                    $conn, 
                    $rider_id_val, 
                    'order_cancelled', 
                    'Order Cancelled', 
                    "Order {$order['order_ref']} was cancelled by the customer. The delivery has been released.", 
                    $order_id
                );
            } catch (Throwable $e) {}
        }

        // Create contextual notifications for kitchen, admin, and customer
        $ref = '#' . ltrim($order['order_ref'] ?: ('FD-' . $order_id), '#');
        try {
            create_notification(
                $conn, 
                $user_id, 
                'order_cancelled', 
                'Order Cancelled', 
                "Your order {$ref} has been cancelled successfully. Reason: {$reason}", 
                $order_id
            );
            notify_role($conn, 'chef', 'order_cancelled', 'Order Cancelled', "Order {$ref} was cancelled by customer: {$reason}", $order_id);
            notify_role($conn, 'admin', 'order_cancelled', 'Customer Cancelled Order', "Order {$ref} cancelled by {$user_name}. Reason: {$reason}", $order_id);
            log_activity($conn, $user_id, 'ORDER_CANCELLED', "Order {$ref} cancelled by customer {$user_name}. Reason: {$reason}");
        } catch (Throwable $e) {
            error_log("Cancellation notification/audit note: " . $e->getMessage());
        }

        // Post cancellation announcement in order chat
        try {
            $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'system', :uid, 'System', :txt, NOW())");
            $ins_m->execute([
                ':oid' => $order_id,
                ':uid' => $user_id,
                ':txt' => "❌ Order {$ref} has been cancelled by customer. Reason: {$reason}"
            ]);
        } catch (Throwable $e) {}

        $conn->commit();

        order_api_out(true, 'Order cancelled successfully.', [
            'order_id'  => $order_id,
            'order_ref' => $ref,
            'status'    => 'Cancelled',
            'reason'    => $reason
        ]);
    } catch (Throwable $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        order_api_out(false, 'Cancellation failed: ' . $e->getMessage(), [], 500);
    }
}

// 2. GET CUSTOMER ORDERS
if ($action === 'get_orders' || $action === 'my_orders' || ($method === 'GET' && empty($action))) {
    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    
    // Strictly scope query to the logged-in user (or demo accounts):
    $scope_orders = $is_demo ? "(customer_id = :uid OR customer_id IN (15, 26, 0))" : "(customer_id = :uid)";
    $stmt = $conn->prepare("SELECT * FROM orders WHERE {$scope_orders} ORDER BY created_at DESC");
    $stmt->execute([':uid' => $user_id]);
    $raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also fetch food_orders details if available to enrich items_summary, store_name, delivery_address
    $scope_food = $is_demo 
        ? "(customer_id = :cid OR user_id = :uid OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0))" 
        : "(customer_id = :cid OR user_id = :uid)";
    $f_stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, status, delivery_status, rider_id, delivery_address, created_at 
        FROM food_orders 
        WHERE {$scope_food} 
        ORDER BY order_id DESC LIMIT 50");
    $f_stmt->execute([':cid' => $user_id, ':uid' => $user_id]);
    $food_orders = $f_stmt->fetchAll(PDO::FETCH_ASSOC);
    $fo_map = [];
    foreach ($food_orders as $fo) {
        $fo['order_ref'] = '#' . ltrim($fo['order_ref'] ?: ('FD-' . $fo['order_id']), '#');
        $fo_map[(int)$fo['order_id']] = $fo;
    }

    $orders = [];
    if (!empty($raw_orders)) {
        foreach ($raw_orders as $ro) {
            $oid = (int)($ro['order_id'] ?? $ro['id'] ?? 0);
            $merged = isset($fo_map[$oid]) ? array_merge($fo_map[$oid], $ro) : $ro;
            $merged['order_ref'] = '#' . ltrim($merged['order_ref'] ?? ('FD-' . $oid), '#');
            $merged['payment_meta'] = get_payment_method_meta($merged['payment_method'] ?? 'Cash on Delivery (COD)');
            $orders[] = $merged;
        }
    } elseif (!empty($food_orders)) {
        foreach ($food_orders as $fo) {
            $fo['order_ref'] = '#' . ltrim($fo['order_ref'] ?: ('FD-' . $fo['order_id']), '#');
            $fo['payment_meta'] = get_payment_method_meta($fo['payment_method'] ?? 'Cash on Delivery (COD)');
            $orders[] = $fo;
        }
    }

    $is_empty = empty($orders);
    order_api_out(true, $is_empty ? 'No orders placed yet!' : 'Orders retrieved.', [
        'orders'      => $orders,
        'count'       => count($orders),
        'empty_state' => $is_empty ? 'No orders placed yet! [Browse Stores]' : null
    ]);
}

// 3. ORDER DETAILS & TIMELINE
if ($action === 'order_details') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    $scope_single = $is_demo 
        ? "(customer_id = :cid OR user_id = :uid OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0) OR :is_admin = 1)" 
        : "(customer_id = :cid OR user_id = :uid OR :is_admin = 1)";

    $stmt = $conn->prepare("SELECT * FROM food_orders WHERE order_id = :oid AND {$scope_single} LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':uid' => $user_id, ':is_admin' => ($role === 'admin' ? 1 : 0)]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        order_api_out(false, 'Order not found.', [], 404);
    }

    $order['order_ref'] = '#' . ltrim($order['order_ref'] ?: ('FD-' . $order['order_id']), '#');
    $order['payment_meta'] = get_payment_method_meta($order['payment_method'] ?? 'Cash on Delivery (COD)');

    $l_stmt = $conn->prepare("SELECT log_id, old_status, new_status, action_by_role, notes, created_at FROM order_audit_logs WHERE order_id = :oid ORDER BY log_id ASC");
    $l_stmt->execute([':oid' => $order_id]);
    $timeline = $l_stmt->fetchAll(PDO::FETCH_ASSOC);

    order_api_out(true, 'Order details retrieved.', ['order' => $order, 'timeline' => $timeline]);
}

// 4. MARK ARRIVAL ACKNOWLEDGEMENT SEEN
if ($action === 'mark_ack_seen' || $action === 'dismiss_arrival_ack') {
    $order_id = (int)($payload['order_id'] ?? $_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    try {
        $conn->prepare("UPDATE food_orders SET acknowledged_seen = 1 WHERE order_id = :oid")->execute([':oid' => $order_id]);
    } catch (Throwable $e) {}
    try {
        $conn->prepare("UPDATE orders SET acknowledged_seen = 1 WHERE order_id = :oid")->execute([':oid' => $order_id]);
    } catch (Throwable $e) {}
    try {
        $conn->prepare("UPDATE notifications SET is_read = TRUE WHERE order_id = :oid AND notification_type = 'arrival_ack'")->execute([':oid' => $order_id]);
    } catch (Throwable $e) {}

    order_api_out(true, 'Arrival acknowledgement marked as seen.', ['order_id' => $order_id, 'acknowledged_seen' => true]);
}

// 5. GET RIDER LOCATION (Live GPS Tracking)
if ($action === 'get_rider_location' || $action === 'rider_location') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    $scope_single = $is_demo 
        ? "(o.customer_id = :cid OR o.user_id = :uid OR o.customer_id IN (15, 26, 0) OR o.user_id IN (15, 26, 0) OR :is_admin = 1)" 
        : "(o.customer_id = :cid OR o.user_id = :uid OR :is_admin = 1)";

    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.customer_id, o.customer_name,
        COALESCE(o.customer_phone, cu.phone, '+63 928 555 0148') AS customer_phone,
        o.store_name, o.delivery_address,
        o.status, o.delivery_status, o.near_notified_at, o.location_updated_at,
        COALESCE(o.store_lat, s.latitude, s.lat, 11.006500) AS store_lat,
        COALESCE(o.store_lng, s.longitude, s.lng, 124.607500) AS store_lng,
        COALESCE(o.delivery_lat, o.customer_lat, 11.004200) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng, 124.605000) AS destination_lng,
        o.customer_lat, o.customer_lng,
        o.rider_id,
        COALESCE(o.rider_lat, r.current_lat, 11.006500) AS rider_lat,
        COALESCE(o.rider_lng, r.current_lng, 124.607500) AS rider_lng,
        COALESCE(r.rider_name, ru.full_name, 'Alex Rider') AS rider_name,
        COALESCE(o.rider_phone, r.phone, ru.phone, '+63 917 555 0192') AS rider_phone
    FROM food_orders o
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    LEFT JOIN users cu ON (o.customer_id = cu.user_id)
    WHERE o.order_id = :oid AND {$scope_single}
    LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':uid' => $user_id, ':is_admin' => ($role === 'admin' ? 1 : 0)]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$o) {
        order_api_out(false, 'Order not found.', [], 404);
    }

    $rider_lat = (isset($o['rider_lat']) && is_numeric($o['rider_lat'])) ? round((float)$o['rider_lat'], 7) : 11.0065;
    $rider_lng = (isset($o['rider_lng']) && is_numeric($o['rider_lng'])) ? round((float)$o['rider_lng'], 7) : 124.6075;
    $dest_lat  = (isset($o['destination_lat']) && is_numeric($o['destination_lat'])) ? round((float)$o['destination_lat'], 7) : 11.0042;
    $dest_lng  = (isset($o['destination_lng']) && is_numeric($o['destination_lng'])) ? round((float)$o['destination_lng'], 7) : 124.6050;
    $store_lat = (isset($o['store_lat']) && is_numeric($o['store_lat'])) ? round((float)$o['store_lat'], 7) : 11.0065;
    $store_lng = (isset($o['store_lng']) && is_numeric($o['store_lng'])) ? round((float)$o['store_lng'], 7) : 124.6075;

    // Haversine distance
    $r = 6371.0088;
    $p = pi() / 180;
    $dlat = ($dest_lat - $rider_lat) * $p;
    $dlon = ($dest_lng - $rider_lng) * $p;
    $a = sin($dlat / 2) ** 2 + cos($rider_lat * $p) * cos($dest_lat * $p) * sin($dlon / 2) ** 2;
    $distance_km = round($r * 2 * asin(min(1, sqrt($a))), 3);

    // Active call on this order
    $active_call = null;
    try {
        $c_stmt = $conn->prepare("SELECT call_id, order_id, caller_role, COALESCE(caller, caller_role) AS caller, caller_id, caller_name, caller_phone, receiver_role, receiver_id, status, call_status, created_at, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_update FROM order_calls WHERE order_id = :oid AND (status IN ('ringing', 'calling', 'accepted', 'connected') OR call_status IN ('calling', 'ringing', 'connected', 'accepted')) ORDER BY call_id DESC LIMIT 1");
        $c_stmt->execute([':oid' => $order_id]);
        $call_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
        if ($call_row && (int)$call_row['seconds_since_update'] <= 45) {
            $active_call = $call_row;
        }
    } catch (Throwable $e) {}

    // Latest message on this order
    $latest_message = null;
    try {
        $m_stmt = $conn->prepare("SELECT message_id, order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at FROM order_messages WHERE order_id = :oid AND (sender_id = :uid OR receiver_id = :uid) ORDER BY message_id DESC LIMIT 1");
        $m_stmt->execute([':oid' => $order_id, ':uid' => $user_id]);
        $latest_message = $m_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    order_api_out(true, 'Rider location retrieved.', [
        'order'           => $o,
        'order_id'        => $order_id,
        'order_ref'       => '#' . ltrim($o['order_ref'] ?: ('FD-' . $o['order_id']), '#'),
        'lat'             => $rider_lat,
        'lng'             => $rider_lng,
        'latitude'        => $rider_lat,
        'longitude'       => $rider_lng,
        'rider_lat'       => $rider_lat,
        'rider_lng'       => $rider_lng,
        'destination_lat' => $dest_lat,
        'destination_lng' => $dest_lng,
        'dest_lat'        => $dest_lat,
        'dest_lng'        => $dest_lng,
        'store_lat'       => $store_lat,
        'store_lng'       => $store_lng,
        'distance_km'     => $distance_km,
        'status'          => $o['status'],
        'delivery_status' => $o['delivery_status'],
        'rider_name'      => $o['rider_name'],
        'rider_phone'     => $o['rider_phone'],
        'customer_phone'  => $o['customer_phone'],
        'active_call'     => $active_call,
        'latest_message'  => $latest_message
    ]);
}

order_api_out(false, 'Invalid order API action.', [], 400);
