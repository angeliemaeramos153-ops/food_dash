<?php
if (session_status() === PHP_SESSION_NONE) session_start();
ob_start();
require_once __DIR__ . '/db_connect.php';
require_once __DIR__ . '/geocode_lib.php';

// Strict no-cache headers to prevent mobile browsers from reading stale database states
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function status_json_out(bool $success, string $message = '', array $extra = [], int $status_code = 200): never {
    if (ob_get_level()) ob_clean();
    http_response_code($status_code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-cache, no-store, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Customer';
$role = strtolower(trim((string)($_SESSION['role'] ?? 'customer')));
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$raw_input = file_get_contents('php://input');
$json_input = json_decode($raw_input, true);
$payload = is_array($json_input) ? $json_input : $_POST;

$order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);

// If no order_id provided, automatically resolve latest active order
if ($order_id <= 0) {
    $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer' || $user_id === 0);
    $scope_active = $is_demo 
        ? "(customer_id = :cid OR user_id = :uid OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0))" 
        : "(customer_id = :cid OR user_id = :uid)";
    try {
        $stmt_act = $conn->prepare("SELECT order_id FROM food_orders 
            WHERE {$scope_active} 
            ORDER BY CASE 
                WHEN LOWER(TRIM(status)) IN ('out_for_delivery', 'on_the_way', 'on_delivery', 'near_customer', 'arrived', 'arrived_destination') THEN 1 
                WHEN LOWER(TRIM(status)) IN ('preparing', 'cooking', 'in_kitchen', 'ready_for_pickup', 'ready', 'accepted', 'claimed', 'pending') THEN 2 
                ELSE 3 END ASC, order_id DESC LIMIT 1");
        $stmt_act->execute([':cid' => $user_id, ':uid' => $user_id]);
        $row_act = $stmt_act->fetch(PDO::FETCH_ASSOC);
        if ($row_act) {
            $order_id = (int)$row_act['order_id'];
        }
    } catch (Throwable $e) {}
}

if ($order_id <= 0) {
    status_json_out(false, 'Valid order_id is required or no active order found.', [
        'order_id'        => 0,
        'status'          => 'none',
        'delivery_status' => 'none',
        'is_arrived'      => false
    ], 404);
}

// Fetch order details
$is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer' || $user_id === 0);
$scope_single = $is_demo 
    ? "(o.customer_id = :cid OR o.user_id = :uid OR o.customer_id IN (15, 26, 0) OR o.user_id IN (15, 26, 0) OR :is_admin = 1)" 
    : "(o.customer_id = :cid OR o.user_id = :uid OR :is_admin = 1)";

$stmt = $conn->prepare("SELECT 
    o.order_id, o.order_ref, o.customer_id, o.customer_name,
    COALESCE(NULLIF(TRIM(cu.phone), ''), NULLIF(TRIM(o.customer_phone), ''), NULLIF(TRIM(ord.customer_phone), ''), '') AS customer_phone,
    COALESCE(NULLIF(TRIM(o.store_name), ''), NULLIF(TRIM(st.store_name), ''), NULLIF(TRIM(s.shop_name), ''), 'SM Center Ormoc') AS store_name,
    COALESCE(NULLIF(TRIM(o.delivery_address), ''), NULLIF(TRIM(ord.delivery_address), ''), 'Western Leyte College (A. Bonifacio St.), Ormoc City') AS delivery_address,
    COALESCE(o.status, ord.status, 'PENDING') AS status,
    COALESCE(o.delivery_status, ord.status, o.status, 'waiting') AS delivery_status,
    COALESCE(NULLIF(o.total_amount, 0), NULLIF(ord.total_price, 0), NULLIF(ord.total_amount, 0), 0.00) AS total_amount,
    COALESCE(o.payment_method, ord.payment_method, 'Cash on Delivery (COD)') AS payment_method,
    o.near_notified_at, o.location_updated_at, o.arrived_notified_at, o.arrival_acknowledged_at, o.arrival_ack_response,
    COALESCE(st.lat, s.lat, o.store_lat, ord.store_lat, 11.00620000) AS store_lat,
    COALESCE(st.lng, s.lng, o.store_lng, ord.store_lng, 124.60680000) AS store_lng,
    COALESCE(o.delivery_lat, o.customer_lat, ord.delivery_lat, 11.00760000) AS destination_lat,
    COALESCE(o.delivery_lng, o.customer_lng, ord.delivery_lng, 124.60890000) AS destination_lng,
    COALESCE(NULLIF(o.rider_id, 0), NULLIF(ord.rider_id, 0), NULL) AS rider_id,
    COALESCE(o.rider_lat, ord.current_lat, ord.rider_lat, r.current_lat, NULL) AS rider_lat,
    COALESCE(o.rider_lng, ord.current_lng, ord.rider_lng, r.current_lng, NULL) AS rider_lng,
    COALESCE(NULLIF(TRIM(r.rider_name), ''), NULLIF(TRIM(ru.full_name), ''), NULLIF(TRIM(ord.rider_name), ''), '') AS rider_name,
    COALESCE(NULLIF(TRIM(r.phone), ''), NULLIF(TRIM(ru.phone), ''), NULLIF(TRIM(ord.rider_phone), ''), NULLIF(TRIM(o.rider_phone), ''), '') AS rider_phone,
    o.updated_at
FROM food_orders o
LEFT JOIN orders ord ON (o.order_id = ord.id OR o.order_id = ord.order_id)
LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name))))
LEFT JOIN stores st ON (o.shop_id = st.shop_id OR o.shop_id = st.id OR LOWER(TRIM(o.store_name)) = LOWER(TRIM(st.store_name)))
LEFT JOIN riders r ON (o.rider_id = r.rider_id OR o.rider_id = r.user_id OR ord.rider_id = r.rider_id OR ord.rider_id = r.user_id)
LEFT JOIN users ru ON (r.user_id = ru.user_id OR o.rider_id = ru.user_id OR ord.rider_id = ru.user_id)
LEFT JOIN users cu ON (o.customer_id = cu.user_id)
WHERE o.order_id = :oid AND {$scope_single}
LIMIT 1");

$stmt->execute([
    ':oid'      => $order_id,
    ':cid'      => $user_id,
    ':uid'      => $user_id,
    ':is_admin' => ($role === 'admin' ? 1 : 0)
]);
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
            COALESCE(ord.status, 'PENDING') AS status,
            COALESCE(ord.status, 'waiting') AS delivery_status,
            COALESCE(NULLIF(ord.total_price, 0), NULLIF(ord.total_amount, 0), 0.00) AS total_amount,
            COALESCE(ord.payment_method, 'Cash on Delivery (COD)') AS payment_method,
            NULL AS near_notified_at,
            NULL AS location_updated_at,
            NULL AS arrived_notified_at,
            NULL AS arrival_acknowledged_at,
            NULL AS arrival_ack_response,
            COALESCE(ord.store_lat, s.lat, 11.00620000) AS store_lat,
            COALESCE(ord.store_lng, s.lng, 124.60680000) AS store_lng,
            COALESCE(ord.delivery_lat, 11.00760000) AS destination_lat,
            COALESCE(ord.delivery_lng, 124.60890000) AS destination_lng,
            ord.rider_id,
            COALESCE(ord.current_lat, ord.rider_lat, r.current_lat, NULL) AS rider_lat,
            COALESCE(ord.current_lng, ord.rider_lng, r.current_lng, NULL) AS rider_lng,
            COALESCE(NULLIF(TRIM(r.rider_name), ''), NULLIF(TRIM(ru.full_name), ''), NULLIF(TRIM(ord.rider_name), ''), '') AS rider_name,
            COALESCE(NULLIF(TRIM(r.phone), ''), NULLIF(TRIM(ru.phone), ''), NULLIF(TRIM(ord.rider_phone), ''), '') AS rider_phone,
            ord.updated_at
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
    status_json_out(false, 'Order not found.', [], 404);
}

// Dynamic geocoding & rider resolution
if (function_exists('apply_dynamic_geocoding_to_order')) {
    apply_dynamic_geocoding_to_order($o);
}
if (function_exists('resolve_order_rider_info')) {
    resolve_order_rider_info($conn, $o);
}

$clean_ref = ltrim($o['order_ref'] ?? ('FD-' . $o['order_id']), '#');
$total_val = (float)($o['total_amount'] ?? 0);

$raw_st = strtolower(trim((string)($o['status'] ?? '')));
$raw_dst = strtolower(trim((string)($o['delivery_status'] ?? '')));

// Precision coordinates
$rider_lat = (float)($o['rider_lat'] ?? $o['current_lat'] ?? 0);
$rider_lng = (float)($o['rider_lng'] ?? $o['current_lng'] ?? 0);
$dest_lat  = (float)($o['destination_lat'] ?? $o['delivery_lat'] ?? 11.0076);
$dest_lng  = (float)($o['destination_lng'] ?? $o['delivery_lng'] ?? 124.6089);
$store_lat = (float)($o['store_lat'] ?? 11.0062);
$store_lng = (float)($o['store_lng'] ?? 124.6068);

// Haversine distance calculation
$distance_km = null;
if ($rider_lat != 0 && $rider_lng != 0 && $dest_lat != 0 && $dest_lng != 0) {
    $r = 6371.0088;
    $p = pi() / 180;
    $dlat = ($dest_lat - $rider_lat) * $p;
    $dlon = ($dest_lng - $rider_lng) * $p;
    $a = sin($dlat / 2) ** 2 + cos($rider_lat * $p) * cos($dest_lat * $p) * sin($dlon / 2) ** 2;
    $distance_km = round($r * 2 * asin(min(1, sqrt($a))), 3);
}

$eta_minutes = null;
if ($distance_km !== null) {
    $eta_minutes = ($distance_km <= 0.035) ? 0 : max(1, (int)round(($distance_km / 25) * 60));
}

// State evaluation: exact check for arrived status or arrival threshold
$is_arrived = in_array($raw_dst, ['arrived', 'arrived_destination'], true) 
           || in_array($raw_st, ['arrived', 'arrived_destination'], true)
           || ($distance_km !== null && $distance_km <= 0.035 && in_array($raw_dst, ['near_customer', 'on_the_way', 'on_delivery']));

// Synchronize return object
$o['store_lat']       = $store_lat;
$o['store_lng']       = $store_lng;
$o['destination_lat'] = $dest_lat;
$o['destination_lng'] = $dest_lng;
$o['dest_lat']        = $dest_lat;
$o['dest_lng']        = $dest_lng;
$o['delivery_lat']    = $dest_lat;
$o['delivery_lng']    = $dest_lng;
$o['current_lat']     = $rider_lat;
$o['current_lng']     = $rider_lng;
$o['rider_lat']       = $rider_lat;
$o['rider_lng']       = $rider_lng;
$o['lat']             = $rider_lat;
$o['lng']             = $rider_lng;

status_json_out(true, 'Order status retrieved successfully.', [
    'order_id'               => (int)$o['order_id'],
    'order_ref'              => '#' . $clean_ref,
    'order_number'           => '#' . $clean_ref,
    'clean_order_number'     => $clean_ref,
    'status'                 => $o['status'],
    'delivery_status'        => $o['delivery_status'],
    'is_arrived'             => (bool)$is_arrived,
    'arrival_acknowledged'   => !empty($o['arrival_acknowledged_at']),
    'arrival_ack_response'   => $o['arrival_ack_response'] ?? null,
    'rider_name'             => $o['rider_name'] ?? '',
    'rider_phone'            => $o['rider_phone'] ?? '',
    'store_name'             => $o['store_name'] ?? '',
    'delivery_address'       => $o['delivery_address'] ?? '',
    'total_amount'           => $total_val,
    'formatted_total_amount' => '₱' . number_format($total_val, 2),
    'formatted_total'        => '₱' . number_format($total_val, 2),
    'rider_lat'              => $rider_lat,
    'rider_lng'              => $rider_lng,
    'current_lat'            => $rider_lat,
    'current_lng'            => $rider_lng,
    'lat'                    => $rider_lat,
    'lng'                    => $rider_lng,
    'destination_lat'        => $dest_lat,
    'destination_lng'        => $dest_lng,
    'dest_lat'               => $dest_lat,
    'dest_lng'               => $dest_lng,
    'store_lat'              => $store_lat,
    'store_lng'              => $store_lng,
    'distance_km'            => $distance_km,
    'eta_minutes'            => $eta_minutes,
    'updated_at'             => $o['updated_at'] ?? null,
    'order'                  => $o
]);
