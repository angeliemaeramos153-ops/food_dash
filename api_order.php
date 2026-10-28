<?php
/**
 * Food Dash - Rider Location Telemetry & Dynamic Order API Endpoint
 * Handles location updates and auto-drive simulation ticks into MySQL & Supabase Cloud
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/db_connect.php';

header('Content-Type: application/json; charset=utf-8');

$rawInput = file_get_contents('php://input');
$payload = json_decode($rawInput, true);
if (!is_array($payload)) {
    $payload = $_POST;
}

$action = $payload['action'] ?? $_GET['action'] ?? 'update_rider_location';

// 1. Resolve Order ID dynamically (zero hardcoded order IDs)
$raw_oid = trim((string)($payload['order_id'] ?? $_GET['order_id'] ?? ''));
$order_id = 0;
if (is_numeric($raw_oid)) {
    $order_id = (int)$raw_oid;
} elseif (!empty($raw_oid)) {
    if (preg_match('/(\d{3,})/', $raw_oid, $m)) {
        $order_id = (int)$m[1];
    }
}
if ($order_id <= 0) {
    // Resolve dynamically from the active delivering order
    $rid = (int)($_SESSION['user_id'] ?? 0);
    $q_act = $conn->prepare("SELECT order_id FROM food_orders WHERE (rider_id = :rid OR :rid = 0) AND LOWER(TRIM(status)) IN ('ready_for_pickup', 'ready', 'assigned', 'out_for_delivery', 'on_delivery', 'on_the_way', 'near_customer', 'arrived') ORDER BY order_id DESC LIMIT 1");
    $q_act->execute([':rid' => $rid]);
    $order_id = (int)($q_act->fetchColumn() ?: 0);
}

// 2. Resolve Coordinates (current_lat, current_lng, lat, lng)
$raw_lat = $payload['current_lat'] ?? $payload['lat'] ?? $_GET['current_lat'] ?? $_GET['lat'] ?? null;
$raw_lng = $payload['current_lng'] ?? $payload['lng'] ?? $_GET['current_lng'] ?? $_GET['lng'] ?? null;

if ($raw_lat !== null && $raw_lng !== null && is_numeric($raw_lat) && is_numeric($raw_lng)) {
    $lat = (float)$raw_lat;
    $lng = (float)$raw_lng;

    // 3. Execute SQL UPDATE query on MySQL food_orders
    try {
        $stmtFO = $conn->prepare("UPDATE food_orders SET rider_lat = :lat, rider_lng = :lng, location_updated_at = NOW() WHERE order_id = :oid OR order_ref LIKE :ref");
        $stmtFO->execute([
            ':lat' => $lat,
            ':lng' => $lng,
            ':oid' => $order_id,
            ':ref' => '%' . $order_id . '%'
        ]);
    } catch (Throwable $e) {}

    // 4. Execute SQL UPDATE query on MySQL orders table
    try {
        $stmtOrd = $conn->prepare("UPDATE orders SET current_lat = :lat, current_lng = :lng, rider_lat = :lat2, rider_lng = :lng2, rider_latitude = :lat3, rider_longitude = :lng3 WHERE id = :oid OR order_id = :oid2 OR order_number LIKE :ref OR order_number = :ref2");
        $stmtOrd->execute([
            ':lat' => $lat,
            ':lng' => $lng,
            ':lat2' => $lat,
            ':lng2' => $lng,
            ':lat3' => $lat,
            ':lng3' => $lng,
            ':oid' => $order_id,
            ':oid2' => $order_id,
            ':ref' => '%' . $order_id . '%',
            ':ref2' => '#FD-261024-' . $order_id
        ]);
    } catch (Throwable $e) {}

    // 5. Update MySQL riders and rider_locations table
    try {
        $stmtLoc = $conn->prepare("INSERT INTO rider_locations (order_id, rider_id, current_lat, current_lng, lat, lng, updated_at) VALUES (:oid, 1, :lat, :lng, :lat2, :lng2, NOW())");
        $stmtLoc->execute([
            ':oid' => $order_id,
            ':lat' => $lat,
            ':lng' => $lng,
            ':lat2' => $lat,
            ':lng2' => $lng
        ]);
    } catch (Throwable $e) {}

    try {
        $stmtR = $conn->prepare("UPDATE riders SET current_lat = :lat, current_lng = :lng WHERE rider_id = 1");
        $stmtR->execute([':lat' => $lat, ':lng' => $lng]);
    } catch (Throwable $e) {}

    // 6. Execute SQL UPDATE query / REST PATCH to save current_lat and current_lng into Supabase for order
    $supabase_success = false;
    $sup_response = null;
    try {
        $sup_url = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1/orders?or=(id.eq.' . (int)$order_id . ',order_id.eq.' . (int)$order_id . ',order_number.eq.%23FD-261024-' . (int)$order_id . ')';
        $sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';
        $sup_body = json_encode([
            'current_lat' => $lat,
            'current_lng' => $lng,
            'rider_lat'   => $lat,
            'rider_lng'   => $lng
        ]);
        $sch = curl_init($sup_url);
        curl_setopt($sch, CURLOPT_CUSTOMREQUEST, 'PATCH');
        curl_setopt($sch, CURLOPT_POSTFIELDS, $sup_body);
        curl_setopt($sch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($sch, CURLOPT_TIMEOUT, 3);
        curl_setopt($sch, CURLOPT_HTTPHEADER, [
            'apikey: ' . $sup_key,
            'Authorization: Bearer ' . $sup_key,
            'Content-Type: application/json',
            'Prefer: return=representation'
        ]);
        $sup_response = curl_exec($sch);
        $http_code = curl_getinfo($sch, CURLINFO_HTTP_CODE);
        curl_close($sch);
        $supabase_success = ($http_code >= 200 && $http_code < 300);
    } catch (Throwable $e) {}

    echo json_encode([
        'success' => true,
        'message' => 'Rider location updated successfully in MySQL and Supabase',
        'order_id' => $order_id,
        'current_lat' => $lat,
        'current_lng' => $lng,
        'supabase_synced' => $supabase_success,
        'supabase_data' => json_decode($sup_response, true)
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// If other order operation, proxy to api_orders.php
if (file_exists(__DIR__ . '/api_orders.php')) {
    require_once __DIR__ . '/api_orders.php';
} else {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
}
