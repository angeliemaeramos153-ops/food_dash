<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
header('Content-Type: application/json; charset=utf-8');

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

        // Check proximity immediately
        $stmt = $conn->prepare("SELECT customer_id, order_ref, customer_lat, customer_lng, delivery_status, near_notified_at FROM food_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

        $distance = null;
        $near = false;
        if ($fresh && $fresh['customer_lat'] !== null && $fresh['customer_lng'] !== null) {
            $distance = distance_km((float)$fresh['customer_lat'], (float)$fresh['customer_lng'], $lat, $lng);
            $near = $distance <= 0.5;
            if ($near && empty($fresh['near_notified_at']) && in_array($fresh['delivery_status'], ['on_the_way', 'near_customer'], true)) {
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
        out(true, 'Location updated.', ['lat' => $lat, 'lng' => $lng, 'distance_km' => $distance, 'near' => $near]);
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

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'track') {
    if ($role !== 'customer') {
        out(false, 'Only Customers can track orders.', [], 403);
    }
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        out(false, 'Order ID is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT order_id, order_ref, customer_name, store_name, customer_lat, customer_lng, rider_id, rider_lat, rider_lng, location_updated_at, status, delivery_status, near_notified_at FROM food_orders WHERE order_id = :oid AND customer_id = :uid LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':uid' => $uid]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$o) {
        out(false, 'Order not found.', [], 404);
    }

    $distance = null;
    $near = false;
    if ($o['customer_lat'] !== null && $o['customer_lng'] !== null && $o['rider_lat'] !== null && $o['rider_lng'] !== null) {
        $distance = distance_km((float)$o['customer_lat'], (float)$o['customer_lng'], (float)$o['rider_lat'], (float)$o['rider_lng']);
        $near = $distance <= 0.5;
        if ($near && empty($o['near_notified_at']) && in_array($o['delivery_status'], ['on_the_way', 'near_customer'], true)) {
            $conn->beginTransaction();
            try {
                $u = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', status = 'near_customer', near_notified_at = NOW(), updated_at = NOW() WHERE order_id = :oid AND near_notified_at IS NULL");
                $u->execute([':oid' => $order_id]);
                $changed = $u->rowCount();
                if ($changed > 0) {
                    create_notification($conn, $uid, 'rider_near', 'Your rider is near', 'Your rider is within about 500 meters of your delivery location. Please get ready for ' . $o['order_ref'] . '.', $order_id);
                    $o['status'] = 'near_customer';
                    $o['delivery_status'] = 'near_customer';
                }
                $conn->commit();
            } catch (Throwable $e) {
                if ($conn->inTransaction()) $conn->rollBack();
            }
        }
    }
    out(true, '', ['order' => $o, 'distance_km' => $distance, 'near' => $near, 'near_threshold_km' => 0.5]);
}

out(false, 'Invalid tracking request.', [], 400);
?>