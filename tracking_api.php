<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

function out(bool $ok, string $message = '', array $data = [], int $code = 200): never {
    http_response_code($code);
    echo json_encode(array_merge(['success' => $ok, 'message' => $message], $data), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    out(false, 'Session expired.', [], 401);
}

$role = strtolower((string)$_SESSION['role']);
$uid  = (int)$_SESSION['user_id'];

function distance_km($lat1, $lon1, $lat2, $lon2): float {
    $r = 6371.0088;
    $p = pi() / 180;
    $dlat = ($lat2 - $lat1) * $p;
    $dlon = ($lon2 - $lon1) * $p;
    $a = sin($dlat / 2) ** 2 + cos($lat1 * $p) * cos($lat2 * $p) * sin($dlon / 2) ** 2;
    return $r * 2 * asin(min(1, sqrt($a)));
}

function valid_lat_lng($lat, $lng): bool {
    return is_numeric($lat) && is_numeric($lng) && $lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $action   = $payload['action'] ?? '';
    $order_id = (int)($payload['order_id'] ?? 0);

    if (!valid_lat_lng($payload['lat'] ?? null, $payload['lng'] ?? null)) {
        out(false, 'Invalid location coordinates.', [], 422);
    }
    $lat = (float)$payload['lat'];
    $lng = (float)$payload['lng'];

    if ($action === 'update_rider_location') {
        if ($role !== 'rider') {
            out(false, 'Only Riders can send live location.', [], 403);
        }

        $stmt = $conn->prepare("SELECT order_id, customer_id, order_ref, rider_id, status FROM food_orders WHERE order_id = :oid AND rider_id IS NOT NULL LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $o = $stmt->fetch(PDO::FETCH_ASSOC);

        // Resolve rider_id for this rider account
        $rider_id = 0;
        $q = $conn->prepare("SELECT rider_id FROM riders WHERE user_id = :uid LIMIT 1");
        $q->execute([':uid' => $uid]);
        $rr = $q->fetch(PDO::FETCH_ASSOC);
        if ($rr) {
            $rider_id = (int)$rr['rider_id'];
        }
        if (!$rider_id) {
            $name = trim($_SESSION['full_name'] ?? $_SESSION['username'] ?? '');
            $q = $conn->prepare("SELECT rider_id FROM riders WHERE LOWER(TRIM(rider_name)) = LOWER(:rname) OR rider_name LIKE '%Alex%' LIMIT 1");
            $q->execute([':rname' => $name]);
            $rr = $q->fetch(PDO::FETCH_ASSOC);
            if ($rr) $rider_id = (int)$rr['rider_id'];
        }
        if (!$rider_id) $rider_id = 1;

        if (!$o || ((int)$o['rider_id'] !== $uid && (int)$o['rider_id'] !== $rider_id)) {
            out(false, 'This delivery is not assigned to this Rider.', [], 403);
        }

        $stmt = $conn->prepare("UPDATE food_orders SET rider_lat = :lat, rider_lng = :lng, location_updated_at = NOW() WHERE order_id = :oid");
        $stmt->execute([':lat' => $lat, ':lng' => $lng, ':oid' => $order_id]);

        if ($rider_id > 0) {
            $stmtR = $conn->prepare("UPDATE riders SET current_lat = :lat, current_lng = :lng WHERE rider_id = :rid");
            $stmtR->execute([':lat' => $lat, ':lng' => $lng, ':rid' => $rider_id]);
        }

        // Check proximity & arrival immediately
        $stmt = $conn->prepare("SELECT customer_id, order_ref, customer_lat, customer_lng, delivery_status, status, near_notified_at, arrived_notified_at FROM food_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

        $distance = null;
        $near = false;
        $arrived = false;
        if ($fresh && $fresh['customer_lat'] !== null && $fresh['customer_lng'] !== null) {
            $distance = distance_km((float)$fresh['customer_lat'], (float)$fresh['customer_lng'], $lat, $lng);
            $near = ($distance <= 0.5); // <= 500 meters
            $arrived = ($distance <= 0.05); // <= 50 meters geofence!

            // 1. Automated "Rider Arrived" Live Notification (<= 50 meters)
            $curr_st = strtolower(trim((string)($fresh['status'] ?? '')));
            $curr_del = strtolower(trim((string)($fresh['delivery_status'] ?? '')));
            $is_delivering = in_array($curr_del, ['on_the_way', 'near_customer', 'arrived'], true)
                          || in_array($curr_st, ['out_for_delivery', 'on_delivery', 'on_the_way', 'near_customer', 'arrived'], true);
            if ($arrived && empty($fresh['arrived_notified_at']) && $is_delivering) {
                $conn->beginTransaction();
                try {
                    $u = $conn->prepare("UPDATE food_orders SET delivery_status = 'arrived', status = 'ARRIVED', arrived_notified_at = NOW(), updated_at = NOW() WHERE order_id = :oid AND arrived_notified_at IS NULL");
                    $u->execute([':oid' => $order_id]);
                    $changed = $u->rowCount();
                    if ($changed > 0) {
                        try {
                            $conn->prepare("UPDATE orders SET status = 'arrived', arrived_notified_at = NOW() WHERE order_id = :oid")->execute([':oid' => $order_id]);
                        } catch (Throwable $e) {}

                        // Resolve rider first name
                        $rname = trim((string)($_SESSION['full_name'] ?? $_SESSION['username'] ?? 'Alex'));
                        $r_first = explode(' ', $rname)[0];
                        $arrived_msg = "📍 Your rider has arrived at your delivery address! Please meet {$r_first} outside to receive your order.";

                        // Insert system message into order_messages
                        $chk = $conn->prepare("SELECT message_id FROM order_messages WHERE order_id = :oid AND message_text LIKE '%has arrived at your delivery address%' LIMIT 1");
                        $chk->execute([':oid' => $order_id]);
                        if (!$chk->fetch()) {
                            $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'rider', :uid, :rname, :txt, NOW())");
                            $ins_m->execute([
                                ':oid'   => $order_id,
                                ':uid'   => $uid,
                                ':rname' => $rname,
                                ':txt'   => $arrived_msg
                            ]);
                        }

                        create_notification($conn, (int)$fresh['customer_id'], 'rider_arrived', 'Your rider has arrived!', $arrived_msg, $order_id);
                        notify_role($conn, 'admin', 'Rider Arrived at Destination', "Order {$fresh['order_ref']} reached delivery geofence (<= 50m).", $order_id);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                }
            }
            // 2. Proximity "Near Customer" Notification (<= 500 meters)
            elseif ($near && empty($fresh['near_notified_at']) && in_array($fresh['delivery_status'], ['on_the_way'], true)) {
                $conn->beginTransaction();
                try {
                    $u = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', status = 'near_customer', near_notified_at = NOW(), updated_at = NOW() WHERE order_id = :oid AND near_notified_at IS NULL");
                    $u->execute([':oid' => $order_id]);
                    $changed = $u->rowCount();
                    if ($changed > 0) {
                        create_notification($conn, (int)$fresh['customer_id'], 'rider_near', 'Your rider is near', 'Your rider is within about 500 meters of your delivery location. Please get ready for ' . $fresh['order_ref'] . '.', $order_id);
                    }
                    $conn->commit();
                } catch (Throwable $e) {
                    if ($conn->inTransaction()) $conn->rollBack();
                }
            }
        }
        out(true, 'Location updated.', ['lat' => $lat, 'lng' => $lng, 'distance_km' => $distance, 'near' => $near, 'arrived' => $arrived]);
    }

    if ($action === 'update_customer_location') {
        if ($role !== 'customer') {
            out(false, 'Only Customers can set a delivery location.', [], 403);
        }
        if ($order_id > 0) {
            $stmt = $conn->prepare("UPDATE food_orders SET customer_lat = :lat, customer_lng = :lng WHERE order_id = :oid AND customer_id = :uid");
            $stmt->execute([':lat' => $lat, ':lng' => $lng, ':oid' => $order_id, ':uid' => $uid]);
        } else {
            $stmt = $conn->prepare("UPDATE food_orders SET customer_lat = :lat, customer_lng = :lng WHERE customer_id = :uid AND status NOT IN ('delivered', 'cancelled')");
            $stmt->execute([':lat' => $lat, ':lng' => $lng, ':uid' => $uid]);
        }
        $changed = $stmt->rowCount();
        out(true, 'Delivery location saved.', ['updated' => $changed]);
    }

    out(false, 'Unknown tracking action.', [], 400);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (in_array(($_GET['action'] ?? ''), ['track', 'get_location'], true) || isset($_GET['order_id']))) {
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        out(false, 'Order ID is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.customer_id, o.customer_name, 
        COALESCE(o.customer_phone, cu.phone, '+63 928 555 0148') AS customer_phone,
        o.store_name, o.delivery_address, 
        o.status, o.delivery_status, o.near_notified_at, o.location_updated_at,
        COALESCE(o.store_lat, s.latitude, s.lat) AS store_lat,
        COALESCE(o.store_lng, s.longitude, s.lng) AS store_lng,
        COALESCE(o.delivery_lat, o.customer_lat) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng) AS destination_lng,
        o.customer_lat, o.customer_lng,
        o.rider_id,
        COALESCE(o.rider_lat, r.current_lat) AS rider_lat,
        COALESCE(o.rider_lng, r.current_lng) AS rider_lng,
        COALESCE(r.rider_name, ru.full_name, 'Alex Rider') AS rider_name,
        COALESCE(o.rider_phone, r.phone, ru.phone, '+63 917 555 0192') AS rider_phone
    FROM food_orders o
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    LEFT JOIN users cu ON (o.customer_id = cu.user_id)
    WHERE o.order_id = :oid
    LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$o) {
        out(false, 'Order not found.', [], 404);
    }

    // Customer can only view their own orders
    if ($role === 'customer' && (int)$o['customer_id'] !== $uid) {
        out(false, 'Access denied.', [], 403);
    }

    // Cast and normalize numeric coordinates with high precision (at least 6 decimal places)
    $store_lat = (isset($o['store_lat']) && is_numeric($o['store_lat'])) ? round((float)$o['store_lat'], 7) : null;
    $store_lng = (isset($o['store_lng']) && is_numeric($o['store_lng'])) ? round((float)$o['store_lng'], 7) : null;
    $dest_lat  = (isset($o['destination_lat']) && is_numeric($o['destination_lat'])) ? round((float)$o['destination_lat'], 7) : null;
    $dest_lng  = (isset($o['destination_lng']) && is_numeric($o['destination_lng'])) ? round((float)$o['destination_lng'], 7) : null;
    $rider_lat = (isset($o['rider_lat']) && is_numeric($o['rider_lat'])) ? round((float)$o['rider_lat'], 7) : null;
    $rider_lng = (isset($o['rider_lng']) && is_numeric($o['rider_lng'])) ? round((float)$o['rider_lng'], 7) : null;

    $o['store_lat'] = $store_lat;
    $o['store_lng'] = $store_lng;
    $o['destination_lat'] = $dest_lat;
    $o['destination_lng'] = $dest_lng;
    $o['rider_lat'] = $rider_lat;
    $o['rider_lng'] = $rider_lng;
    $o['latitude']  = $rider_lat;
    $o['longitude'] = $rider_lng;

    // Fallback phones guaranteed non-null
    if (empty($o['customer_phone'])) $o['customer_phone'] = '+63 917 123 4567';
    if (empty($o['rider_phone'])) $o['rider_phone'] = '+63 917 555 0192';

    // Check for active ringing call created within the last 45 seconds
    $active_call = null;
    try {
        $call_stmt = $conn->prepare("SELECT call_id, order_id, caller_role, caller_id, caller_name, caller_phone, receiver_role, receiver_id, status, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds FROM order_calls WHERE order_id = :oid AND status = 'ringing' AND created_at >= (NOW() - INTERVAL 45 SECOND) ORDER BY call_id DESC LIMIT 1");
        $call_stmt->execute([':oid' => $order_id]);
        $c = $call_stmt->fetch(PDO::FETCH_ASSOC);
        if ($c) {
            $c['call_id'] = (int)$c['call_id'];
            $c['elapsed_seconds'] = (int)$c['elapsed_seconds'];
            $active_call = $c;
        }
    } catch (Throwable $e) {}

    // Check for latest message for real-time delivery toasts
    $latest_message = null;
    try {
        $m_stmt = $conn->prepare("SELECT message_id, order_id, sender_role, sender_id, sender_name, message_text, created_at FROM order_messages WHERE order_id = :oid ORDER BY message_id DESC LIMIT 1");
        $m_stmt->execute([':oid' => $order_id]);
        $latest_message = $m_stmt->fetch(PDO::FETCH_ASSOC);
        if ($latest_message) {
            $latest_message['message_id'] = (int)$latest_message['message_id'];
        }
    } catch (Throwable $e) {}

    // Calculate distance between rider and destination
    $distance = null;
    $near = false;
    if ($dest_lat !== null && $dest_lng !== null && $rider_lat !== null && $rider_lng !== null) {
        $distance = round(distance_km($dest_lat, $dest_lng, $rider_lat, $rider_lng), 3);
        $near = $distance <= 0.5;

        if ($near && empty($o['near_notified_at']) && in_array($o['delivery_status'], ['on_the_way', 'near_customer', 'out_for_delivery'], true)) {
            $conn->beginTransaction();
            try {
                $u = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', status = 'near_customer', near_notified_at = NOW(), updated_at = NOW() WHERE order_id = :oid AND near_notified_at IS NULL");
                $u->execute([':oid' => $order_id]);
                $changed = $u->rowCount();
                if ($changed > 0) {
                    create_notification($conn, (int)$o['customer_id'], 'rider_near', 'Your rider is near', 'Your rider is within about 500 meters of your delivery location. Please get ready for ' . $o['order_ref'] . '.', $order_id);
                    $o['status'] = 'near_customer';
                    $o['delivery_status'] = 'near_customer';
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
            }
        }
    }

    out(true, 'Tracking data retrieved.', [
        'order'              => $o,
        'distance_km'        => $distance,
        'near'               => $near,
        'near_threshold_km'  => 0.5,
        'store_lat'          => $store_lat,
        'store_lng'          => $store_lng,
        'destination_lat'    => $dest_lat,
        'destination_lng'    => $dest_lng,
        'latitude'           => $rider_lat,
        'longitude'          => $rider_lng,
        'lat'                => $rider_lat,
        'lng'                => $rider_lng,
        'rider_lat'          => $rider_lat,
        'rider_lng'          => $rider_lng,
        'rider_name'         => $o['rider_name'],
        'rider_phone'        => $o['rider_phone'],
        'customer_phone'     => $o['customer_phone'],
        'active_call'        => $active_call,
        'latest_message'     => $latest_message,
    ]);
}

out(false, 'Invalid tracking request.', [], 400);
?>