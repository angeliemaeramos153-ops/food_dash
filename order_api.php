<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'payment_helper.php';
require_once 'geocode_lib.php';

header('Content-Type: application/json; charset=utf-8');

function order_api_out(bool $success, string $message = '', array $extra = [], int $status = 200): never {
    if (ob_get_level()) ob_clean();
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
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
            require_once __DIR__ . '/supabase_lib.php';
            sync_order_to_supabase($order_id, $conn);
        } catch (Throwable $e) {}

        // b. Post an entry to the order status history/timeline (order_audit_logs)
        $audit_note = "Order cancelled by customer. Reason: " . $reason;
        record_order_audit_log($conn, $order_id, $raw_status, 'CANCELLED', $user_id, 'customer', $audit_note);

        // c. If a rider was assigned or viewing, notify the rider view to release the order back to available status
        $rider_id_val = !empty($order['rider_id']) ? (int)$order['rider_id'] : 0;
        if ($rider_id_val > 0) {
            try {
                $u_rider = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid OR user_id = :uid");
                $u_rider->execute([':rid' => $rider_id_val, ':uid' => $rider_id_val]);

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
if ($action === 'get_orders' || $action === 'my_orders' || $action === 'get_customer_orders' || ($method === 'GET' && empty($action))) {
    $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    $orders = [];

    if ($driver === 'pgsql') {
        try {
            $stmt = $conn->prepare("
                SELECT 
                    o.id AS order_id,
                    o.id,
                    o.order_number AS order_ref,
                    COALESCE(s.shop_name, 'Partner Store') AS store_name,
                    COALESCE(
                        (SELECT string_agg(CONCAT(oi.quantity, 'x ', oi.item_name), ', ') FROM order_items oi WHERE oi.order_id = o.id),
                        'Food Dash Items'
                    ) AS items_summary,
                    COALESCE(o.total_amount, 0.00) AS total_amount,
                    COALESCE(o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                    COALESCE(o.status, 'pending') AS status,
                    COALESCE(o.status, 'pending') AS delivery_status,
                    'delivery' AS order_type,
                    COALESCE(o.delivery_address, '') AS delivery_address,
                    o.delivery_lat,
                    o.delivery_lng,
                    o.rider_name,
                    o.rider_phone,
                    o.current_lat,
                    o.current_lng,
                    o.created_at,
                    COALESCE(o.rider_cleared, 0) AS rider_cleared
                FROM orders o
                LEFT JOIN shops s ON o.store_id = s.shop_id
                WHERE (o.user_id = :uid OR o.customer_id::text = :uid_str)
                  AND UPPER(COALESCE(o.status, '')) != 'CANCELLED'
                  AND COALESCE(o.rider_cleared, 0) = 0
                ORDER BY o.id DESC LIMIT 50
            ");
            $stmt->execute([':uid' => $user_id, ':uid_str' => (string)$user_id]);
            $raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($raw_orders as $ro) {
                $ro['order_ref'] = '#' . ltrim($ro['order_ref'] ?? ('FD-' . $ro['order_id']), '#');
                $ro['payment_meta'] = get_payment_method_meta($ro['payment_method'] ?? 'Cash on Delivery (COD)');
                $orders[] = $ro;
            }
        } catch (Throwable $e) {
            $orders = [];
        }
    } else {
        try {
            $stmt = $conn->prepare("
                SELECT 
                    COALESCE(fo.order_id, o.order_id, o.id) AS order_id,
                    COALESCE(fo.order_id, o.order_id, o.id) AS id,
                    COALESCE(fo.order_ref, o.order_number, CONCAT('#FD-', COALESCE(fo.order_id, o.order_id, o.id))) AS order_ref,
                    COALESCE(fo.store_name, s.shop_name, o.store_name, 'Partner Store') AS store_name,
                    COALESCE(fo.items_summary, o.items_summary, 'Food Dash Items') AS items_summary,
                    COALESCE(fo.total_amount, o.total_price, 0.00) AS total_amount,
                    COALESCE(fo.payment_method, o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                    COALESCE(fo.status, o.status, 'Pending') AS status,
                    COALESCE(fo.delivery_status, o.status, 'waiting') AS delivery_status,
                    'delivery' AS order_type,
                    COALESCE(fo.delivery_address, o.delivery_address, '') AS delivery_address,
                    COALESCE(fo.delivery_lat, o.delivery_lat) AS delivery_lat,
                    COALESCE(fo.delivery_lng, o.delivery_lng) AS delivery_lng,
                    COALESCE(r.rider_name, ru.full_name, o.rider_name, '') AS rider_name,
                    COALESCE(r.phone, ru.phone, fo.rider_phone, o.rider_phone, '') AS rider_phone,
                    COALESCE(fo.rider_lat, o.current_lat) AS current_lat,
                    COALESCE(fo.rider_lng, o.current_lng) AS current_lng,
                    COALESCE(fo.created_at, o.created_at) AS created_at,
                    COALESCE(fo.rider_cleared, o.rider_cleared, 0) AS rider_cleared
                FROM food_orders fo
                LEFT JOIN orders o ON (fo.order_id = o.order_id OR fo.order_id = o.id)
                LEFT JOIN shops s ON (fo.shop_id = s.shop_id OR (fo.shop_id = 0 AND LOWER(TRIM(fo.store_name)) = LOWER(TRIM(s.shop_name))))
                LEFT JOIN riders r ON (fo.rider_id = r.rider_id OR fo.rider_id = r.user_id)
                LEFT JOIN users ru ON (r.user_id = ru.user_id)
                WHERE (fo.customer_id = :uid OR fo.user_id = :uid2)
                  AND COALESCE(fo.rider_cleared, 0) = 0
                ORDER BY fo.order_id DESC LIMIT 50
            ");
            $stmt->execute([':uid' => $user_id, ':uid2' => $user_id]);
            $raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
            foreach ($raw_orders as $ro) {
                $ro['order_ref'] = '#' . ltrim($ro['order_ref'] ?? ('FD-' . $ro['order_id']), '#');
                $ro['payment_meta'] = get_payment_method_meta($ro['payment_method'] ?? 'Cash on Delivery (COD)');
                $orders[] = $ro;
            }
        } catch (Throwable $e) {
            $orders = [];
        }

        // Fallback to legacy orders table if food_orders returned empty
        if (empty($orders)) {
            try {
                $stmt = $conn->prepare("
                    SELECT 
                        COALESCE(o.id, o.order_id) AS order_id,
                        COALESCE(o.id, o.order_id) AS id,
                        COALESCE(o.order_number, CONCAT('#FD-', COALESCE(o.id, o.order_id))) AS order_ref,
                        COALESCE(s.shop_name, o.store_name, 'Partner Store') AS store_name,
                        COALESCE(o.items_summary, 'Food Dash Items') AS items_summary,
                        COALESCE(o.total_price, 0.00) AS total_amount,
                        COALESCE(o.payment_method, 'Cash on Delivery (COD)') AS payment_method,
                        COALESCE(o.status, 'Pending') AS status,
                        COALESCE(o.status, 'Pending') AS delivery_status,
                        'delivery' AS order_type,
                        COALESCE(o.delivery_address, '') AS delivery_address,
                        o.delivery_lat,
                        o.delivery_lng,
                        o.rider_name,
                        o.rider_phone,
                        o.current_lat,
                        o.current_lng,
                        o.created_at,
                        COALESCE(o.rider_cleared, 0) AS rider_cleared
                    FROM orders o
                    LEFT JOIN shops s ON (o.store_id = s.shop_id OR o.shop_id = s.shop_id)
                    WHERE (o.user_id = :uid OR o.customer_id = :uid2)
                      AND COALESCE(o.rider_cleared, 0) = 0
                    ORDER BY o.order_id DESC LIMIT 50
                ");
                $stmt->execute([':uid' => $user_id, ':uid2' => $user_id]);
                $raw_orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
                foreach ($raw_orders as $ro) {
                    $ro['order_ref'] = '#' . ltrim($ro['order_ref'] ?? ('FD-' . $ro['order_id']), '#');
                    $ro['payment_meta'] = get_payment_method_meta($ro['payment_method'] ?? 'Cash on Delivery (COD)');
                    $orders[] = $ro;
                }
            } catch (Throwable $e) {}
        }
    }

    $is_empty = empty($orders);
    order_api_out(true, $is_empty ? 'No orders placed yet!' : 'Orders retrieved.', [
        'orders'      => $orders,
        'count'       => count($orders),
        'empty_state' => $is_empty ? 'No orders placed yet! [Browse Stores]' : null
    ]);
}

// 2.5 FAST ORDER STATUS & TRACKING POLLING ENDPOINT
if ($action === 'get_order_status' || $action === 'poll_status') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
    $scope_status = $is_demo 
        ? "(fo.customer_id = :cid OR fo.user_id = :uid OR fo.customer_id IN (15, 26, 0) OR fo.user_id IN (15, 26, 0) OR :is_admin = 1)" 
        : "(fo.customer_id = :cid OR fo.user_id = :uid OR :is_admin = 1)";

    $stmt = $conn->prepare("SELECT 
        fo.order_id, 
        fo.order_ref,
        COALESCE(fo.status, o.status, 'PENDING') AS status,
        COALESCE(fo.delivery_status, o.status, 'waiting') AS delivery_status,
        COALESCE(NULLIF(fo.total_amount, 0), NULLIF(o.total_price, 0), NULLIF(o.total_amount, 0), 0.00) AS total_amount,
        fo.store_name,
        COALESCE(r.rider_name, ru.full_name, '') AS rider_name,
        COALESCE(r.phone, ru.phone, fo.rider_phone, '') AS rider_phone,
        fo.rider_lat,
        fo.rider_lng,
        fo.updated_at
    FROM food_orders fo
    LEFT JOIN orders o ON (fo.order_id = o.order_id OR fo.order_id = o.id)
    LEFT JOIN riders r ON (fo.rider_id = r.rider_id OR fo.rider_id = r.user_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    WHERE fo.order_id = :oid AND {$scope_status}
    LIMIT 1");
    $stmt->execute([
        ':oid'      => $order_id,
        ':cid'      => $user_id,
        ':uid'      => $user_id,
        ':is_admin' => ($role === 'admin' ? 1 : 0)
    ]);
    $res = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$res) {
        order_api_out(false, 'Order not found.', [], 404);
    }

    $clean_status_ref = ltrim($res['order_ref'] ?? ('FD-' . $res['order_id']), '#');
    $status_tot_val = (float)($res['total_amount'] ?? 0);
    $res_st = strtolower(trim((string)($res['status'] ?? '')));
    $res_dst = strtolower(trim((string)($res['delivery_status'] ?? '')));
    $is_arrived_flag = in_array($res_dst, ['arrived', 'arrived_destination'], true) || in_array($res_st, ['arrived', 'arrived_destination'], true);

    order_api_out(true, 'Order status retrieved.', [
        'order_id'               => (int)$res['order_id'],
        'order_ref'              => '#' . $clean_status_ref,
        'order_number'           => '#' . $clean_status_ref,
        'clean_order_number'     => $clean_status_ref,
        'total_amount'           => $status_tot_val,
        'formatted_total_amount' => '₱' . number_format($status_tot_val, 2),
        'formatted_total'        => '₱' . number_format($status_tot_val, 2),
        'status'                 => $res['status'],
        'delivery_status'        => $res['delivery_status'],
        'is_arrived'             => $is_arrived_flag,
        'store_name'             => $res['store_name'],
        'rider_name'             => $res['rider_name'],
        'rider_phone'            => $res['rider_phone'],
        'rider_lat'              => $res['rider_lat'],
        'rider_lng'              => $res['rider_lng'],
        'updated_at'             => $res['updated_at']
    ]);
}

// 3. ORDER DETAILS & TIMELINE
if ($action === 'order_details') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $scope_single = "(customer_id = :cid OR :is_admin = 1)";
    $stmt = $conn->prepare("SELECT * FROM food_orders WHERE order_id = :oid AND {$scope_single} LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':is_admin' => ($role === 'admin' ? 1 : 0)]);
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
        COALESCE(NULLIF(TRIM(cu.phone), ''), NULLIF(TRIM(o.customer_phone), ''), NULLIF(TRIM(ord.customer_phone), ''), '') AS customer_phone,
        COALESCE(NULLIF(TRIM(o.store_name), ''), NULLIF(TRIM(st.store_name), ''), NULLIF(TRIM(s.shop_name), ''), 'SM Center Ormoc') AS store_name,
        COALESCE(NULLIF(TRIM(o.delivery_address), ''), NULLIF(TRIM(ord.delivery_address), ''), 'Western Leyte College (A. Bonifacio St.), Ormoc City') AS delivery_address,
        COALESCE(o.status, ord.status, 'on_delivery') AS status,
        COALESCE(o.delivery_status, ord.status, o.status, 'on_delivery') AS delivery_status,
        o.near_notified_at, o.location_updated_at,
        COALESCE(st.lat, s.lat, o.store_lat, ord.store_lat, 11.00620000) AS store_lat,
        COALESCE(st.lng, s.lng, o.store_lng, ord.store_lng, 124.60680000) AS store_lng,
        COALESCE(o.delivery_lat, o.customer_lat, ord.delivery_lat, 11.00760000) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng, ord.delivery_lng, 124.60890000) AS destination_lng,
        COALESCE(o.customer_lat, o.delivery_lat, 11.00760000) AS customer_lat,
        COALESCE(o.customer_lng, o.delivery_lng, 124.60890000) AS customer_lng,
        COALESCE(NULLIF(o.rider_id, 0), NULLIF(ord.rider_id, 0), NULL) AS rider_id,
        COALESCE(o.rider_lat, ord.current_lat, ord.rider_lat, r.current_lat, NULL) AS rider_lat,
        COALESCE(o.rider_lng, ord.current_lng, ord.rider_lng, r.current_lng, NULL) AS rider_lng,
        COALESCE(NULLIF(TRIM(r.rider_name), ''), NULLIF(TRIM(ru.full_name), ''), NULLIF(TRIM(ord.rider_name), ''), '') AS rider_name,
        COALESCE(NULLIF(TRIM(r.phone), ''), NULLIF(TRIM(ru.phone), ''), NULLIF(TRIM(ord.rider_phone), ''), NULLIF(TRIM(o.rider_phone), ''), '') AS rider_phone
    FROM food_orders o
    LEFT JOIN orders ord ON (o.order_id = ord.id OR o.order_id = ord.order_id)
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name))))
    LEFT JOIN stores st ON (o.shop_id = st.shop_id OR o.shop_id = st.id OR LOWER(TRIM(o.store_name)) = LOWER(TRIM(st.store_name)))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id OR o.rider_id = r.user_id OR ord.rider_id = r.rider_id OR ord.rider_id = r.user_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id OR o.rider_id = ru.user_id OR ord.rider_id = ru.user_id)
    LEFT JOIN users cu ON (o.customer_id = cu.user_id)
    WHERE o.order_id = :oid AND {$scope_single}
    LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':uid' => $user_id, ':is_admin' => ($role === 'admin' ? 1 : 0)]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$o) {
        try {
            $o_stmt = $conn->prepare("SELECT 
                COALESCE(ord.id, ord.order_id) AS order_id,
                COALESCE(ord.order_number, CONCAT('#FD-', COALESCE(ord.id, ord.order_id))) AS order_ref,
                ord.user_id AS customer_id,
                COALESCE(cu.full_name, cu.username, 'Customer') AS customer_name,
                COALESCE(cu.phone, ord.customer_phone, '') AS customer_phone,
                COALESCE(
                  (SELECT oi.store_name FROM order_items oi WHERE oi.order_id = ord.id AND oi.store_name IS NOT NULL AND oi.store_name != '' LIMIT 1),
                  s.shop_name,
                  'SM Center Ormoc'
                ) AS store_name,
                COALESCE(ord.delivery_address, 'Western Leyte College (A. Bonifacio St.), Ormoc City') AS delivery_address,
                COALESCE(ord.status, 'on_delivery') AS status,
                COALESCE(ord.status, 'on_delivery') AS delivery_status,
                NULL AS near_notified_at,
                NULL AS location_updated_at,
                COALESCE(ord.store_lat, s.lat, 11.00620000) AS store_lat,
                COALESCE(ord.store_lng, s.lng, 124.60680000) AS store_lng,
                COALESCE(ord.delivery_lat, 11.00760000) AS destination_lat,
                COALESCE(ord.delivery_lng, 124.60890000) AS destination_lng,
                COALESCE(ord.delivery_lat, 11.00760000) AS customer_lat,
                COALESCE(ord.delivery_lng, 124.60890000) AS customer_lng,
                ord.rider_id,
                COALESCE(ord.current_lat, ord.rider_lat, r.current_lat, NULL) AS rider_lat,
                COALESCE(ord.current_lng, ord.rider_lng, r.current_lng, NULL) AS rider_lng,
                COALESCE(NULLIF(TRIM(r.rider_name), ''), NULLIF(TRIM(ru.full_name), ''), NULLIF(TRIM(ord.rider_name), ''), '') AS rider_name,
                COALESCE(NULLIF(TRIM(r.phone), ''), NULLIF(TRIM(ru.phone), ''), NULLIF(TRIM(ord.rider_phone), ''), '') AS rider_phone
            FROM orders ord
            LEFT JOIN shops s ON (ord.store_id = s.shop_id OR ord.shop_id = s.shop_id)
            LEFT JOIN riders r ON (ord.rider_id = r.rider_id OR ord.rider_id = r.user_id)
            LEFT JOIN users ru ON (r.user_id = ru.user_id OR ord.rider_id = ru.user_id)
            LEFT JOIN users cu ON ord.user_id = cu.user_id
            WHERE (ord.id = :oid OR ord.order_id = :oid2)
            LIMIT 1");
            $o_stmt->execute([':oid' => $order_id, ':oid2' => $order_id]);
            $o = $o_stmt->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    if (!$o) {
        order_api_out(false, 'Order not found.', [], 404);
    }

    // Dynamic geocoding and rider resolution
    apply_dynamic_geocoding_to_order($o, $conn);
    resolve_order_rider_info($conn, $o);

    // Guaranteed NEVER null numeric coordinates (high-precision floats)
    $store_lat       = round((float)$o['store_lat'], 7);
    $store_lng       = round((float)$o['store_lng'], 7);
    $destination_lat = round((float)$o['destination_lat'], 7);
    $destination_lng = round((float)$o['destination_lng'], 7);
    $current_lat     = round((float)$o['current_lat'], 7);
    $current_lng     = round((float)$o['current_lng'], 7);
    $rider_lat       = $current_lat;
    $rider_lng       = $current_lng;
    $dest_lat        = $destination_lat;
    $dest_lng        = $destination_lng;

    // Synchronize inside $o so nested order object has ZERO null coordinates
    $o['store_lat']       = $store_lat;
    $o['store_lng']       = $store_lng;
    $o['destination_lat'] = $destination_lat;
    $o['destination_lng'] = $destination_lng;
    $o['dest_lat']        = $dest_lat;
    $o['dest_lng']        = $dest_lng;
    $o['delivery_lat']    = $dest_lat;
    $o['delivery_lng']    = $dest_lng;
    $o['customer_lat']    = $dest_lat;
    $o['customer_lng']    = $dest_lng;
    $o['current_lat']     = $current_lat;
    $o['current_lng']     = $current_lng;
    $o['rider_lat']       = $rider_lat;
    $o['rider_lng']       = $rider_lng;
    $o['lat']             = $current_lat;
    $o['lng']             = $current_lng;

    // Haversine distance
    $r = 6371.0088;
    $p = pi() / 180;
    $dlat = ($destination_lat - $rider_lat) * $p;
    $dlon = ($destination_lng - $rider_lng) * $p;
    $a = sin($dlat / 2) ** 2 + cos($rider_lat * $p) * cos($destination_lat * $p) * sin($dlon / 2) ** 2;
    $distance_km = round($r * 2 * asin(min(1, sqrt($a))), 3);

    // Active call on this order
    $active_call = null;
    try {
        $c_stmt = $conn->prepare("SELECT call_id, order_id, caller_role, COALESCE(caller, caller_role) AS caller, caller_id, caller_name, caller_phone, receiver_role, receiver_id, status, call_status, created_at, TIMESTAMPDIFF(SECOND, updated_at, NOW()) AS seconds_since_update FROM order_calls WHERE (order_id = :oid OR (receiver_id = :cid AND receiver_role = 'customer')) AND (status IN ('ringing', 'calling', 'accepted', 'connected') OR call_status IN ('calling', 'ringing', 'connected', 'accepted')) ORDER BY call_id DESC LIMIT 1");
        $c_stmt->execute([':oid' => $order_id, ':cid' => $user_id]);
        $call_row = $c_stmt->fetch(PDO::FETCH_ASSOC);
        if ($call_row && (int)$call_row['seconds_since_update'] <= 45) {
            $c_role = strtolower(trim((string)($call_row['caller_role'] ?? 'rider')));
            if ($c_role !== 'customer') $c_role = 'rider';
            $call_row['caller'] = $c_role;
            $call_row['caller_role'] = $c_role;
            $call_row['receiver_role'] = ($c_role === 'rider') ? 'customer' : 'rider';
            if ($c_role === 'rider') {
                $call_row['caller_name'] = !empty($o['rider_name']) ? $o['rider_name'] : (!empty($call_row['caller_name']) ? $call_row['caller_name'] : 'Alex Rider');
                $call_row['caller_phone'] = !empty($o['rider_phone']) ? $o['rider_phone'] : (!empty($call_row['caller_phone']) ? $call_row['caller_phone'] : '+63 917 555 0192');
                $call_row['receiver_name'] = !empty($o['customer_name']) ? $o['customer_name'] : 'Customer';
                $call_row['receiver_phone'] = !empty($o['customer_phone']) ? $o['customer_phone'] : '+63 917 123 4567';
            } else {
                $call_row['caller_name'] = !empty($o['customer_name']) ? $o['customer_name'] : (!empty($call_row['caller_name']) ? $call_row['caller_name'] : 'Customer');
                $call_row['caller_phone'] = !empty($o['customer_phone']) ? $o['customer_phone'] : (!empty($call_row['caller_phone']) ? $call_row['caller_phone'] : '+63 917 123 4567');
                $call_row['receiver_name'] = !empty($o['rider_name']) ? $o['rider_name'] : 'Alex Rider';
                $call_row['receiver_phone'] = !empty($o['rider_phone']) ? $o['rider_phone'] : '+63 917 555 0192';
            }
            $active_call = $call_row;
        }
    } catch (Throwable $e) {}

    // Latest message on this order
    $latest_message = null;
    try {
        $m_stmt = $conn->prepare("SELECT message_id, order_id, sender_role, sender_id, receiver_id, sender_name, message_text, created_at FROM order_messages WHERE order_id = :oid ORDER BY message_id DESC LIMIT 1");
        $m_stmt->execute([':oid' => $order_id]);
        $latest_message = $m_stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {}

    $o_st = strtolower(trim((string)($o['status'] ?? '')));
    $o_dst = strtolower(trim((string)($o['delivery_status'] ?? '')));
    $is_arrived_loc = in_array($o_dst, ['arrived', 'arrived_destination'], true) || in_array($o_st, ['arrived', 'arrived_destination'], true);

    order_api_out(true, 'Rider location retrieved.', [
        'order'           => $o,
        'order_id'        => $order_id,
        'order_ref'       => '#' . ltrim($o['order_ref'] ?: ('FD-' . $o['order_id']), '#'),
        'lat'             => $current_lat,
        'lng'             => $current_lng,
        'current_lat'     => $current_lat,
        'current_lng'     => $current_lng,
        'rider_lat'       => $rider_lat,
        'rider_lng'       => $rider_lng,
        'store_lat'       => $store_lat,
        'store_lng'       => $store_lng,
        'destination_lat' => $destination_lat,
        'destination_lng' => $destination_lng,
        'dest_lat'        => $dest_lat,
        'dest_lng'        => $dest_lng,
        'distance_km'     => $distance_km,
        'status'          => $o['status'],
        'delivery_status' => $o['delivery_status'],
        'is_arrived'      => $is_arrived_loc,
        'rider_name'      => $o['rider_name'],
        'rider_phone'     => $o['rider_phone'],
        'customer_phone'  => $o['customer_phone'],
        'active_call'     => $active_call,
        'latest_message'  => $latest_message
    ]);
}

order_api_out(false, 'Invalid order API action.', [], 400);



