<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once __DIR__ . '/geocode_lib.php';
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
    $raw_oid = trim((string)($payload['order_id'] ?? 0));
    $order_id = 0;
    if (is_numeric($raw_oid)) {
        $order_id = (int)$raw_oid;
    } elseif (!empty($raw_oid)) {
        if (preg_match('/(\d{3,})/', $raw_oid, $m)) {
            $order_id = (int)$m[1];
        }
    }

    $raw_lat = $payload['current_lat'] ?? $payload['lat'] ?? null;
    $raw_lng = $payload['current_lng'] ?? $payload['lng'] ?? null;

    if (!valid_lat_lng($raw_lat, $raw_lng)) {
        out(false, 'Invalid location coordinates.', [], 422);
    }
    $lat = (float)$raw_lat;
    $lng = (float)$raw_lng;

    if ($action === 'update_rider_location') {
        $is_sim = !empty($payload['is_simulation']) || !empty($_GET['sim']);
        if ($role !== 'rider' && $role !== 'admin' && empty($_SESSION['user_id']) && !$is_sim) {
            out(false, 'Only Riders can send live location.', [], 403);
        }

        $stmt = $conn->prepare("SELECT order_id, customer_id, order_ref, rider_id, status FROM food_orders WHERE order_id = :oid OR order_ref LIKE :ref LIMIT 1");
        $stmt->execute([':oid' => $order_id, ':ref' => '%' . $order_id . '%']);
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

        if (!$is_sim && $o && (int)$o['rider_id'] !== $uid && (int)$o['rider_id'] !== $rider_id && $role !== 'admin') {
            out(false, 'This delivery is not assigned to this Rider.', [], 403);
        }

        $stmt = $conn->prepare("UPDATE food_orders SET rider_lat = :lat, rider_lng = :lng, location_updated_at = NOW() WHERE order_id = :oid OR order_ref LIKE :ref");
        $stmt->execute([':lat' => $lat, ':lng' => $lng, ':oid' => $order_id, ':ref' => '%' . $order_id . '%']);

        // 1. RIDER GPS BROADCAST: Update orders table with current_lat and current_lng
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

        // Broadcast to Supabase orders table via REST API so React Native / Expo receives live GPS
        try {
            $sup_url = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1/orders?or=(id.eq.' . (int)$order_id . ',order_id.eq.' . (int)$order_id . ',order_number.eq.%23FD-261024-' . (int)$order_id . ')';
            $sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';
            $sup_body = json_encode([
                'current_lat' => (float)$lat,
                'current_lng' => (float)$lng,
                'rider_lat'   => (float)$lat,
                'rider_lng'   => (float)$lng
            ]);
            $sch = curl_init($sup_url);
            curl_setopt($sch, CURLOPT_CUSTOMREQUEST, 'PATCH');
            curl_setopt($sch, CURLOPT_POSTFIELDS, $sup_body);
            curl_setopt($sch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($sch, CURLOPT_TIMEOUT, 2);
            curl_setopt($sch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $sup_key,
                'Authorization: Bearer ' . $sup_key,
                'Content-Type: application/json',
                'Prefer: return=representation'
            ]);
            curl_exec($sch);
            curl_close($sch);
        } catch (Throwable $e) {}

        // 2. Insert into rider_locations table linked to order_id
        try {
            $stmtLoc = $conn->prepare("INSERT INTO rider_locations (order_id, rider_id, current_lat, current_lng, lat, lng, updated_at) VALUES (:oid, :rid, :lat, :lng, :lat2, :lng2, NOW())");
            $stmtLoc->execute([':oid' => $order_id, ':rid' => $rider_id, ':lat' => $lat, ':lng' => $lng, ':lat2' => $lat, ':lng2' => $lng]);
        } catch (Throwable $e) {}

        if ($rider_id > 0) {
            try {
                $stmtR = $conn->prepare("UPDATE riders SET current_lat = :lat, current_lng = :lng WHERE rider_id = :rid");
                $stmtR->execute([':lat' => $lat, ':lng' => $lng, ':rid' => $rider_id]);
            } catch (Throwable $e) {}
        }

        // Check proximity & arrival immediately
        $stmt = $conn->prepare("SELECT customer_id, order_ref, delivery_status, status, near_notified_at, arrived_notified_at, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng, store_name, store_lat, store_lng FROM food_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $fresh = $stmt->fetch(PDO::FETCH_ASSOC);

        $distance = null;
        $near = false;
        $arrived = false;
        if ($fresh) {
            apply_dynamic_geocoding_to_order($fresh);
        }

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
                            require_once __DIR__ . '/supabase_lib.php';
                            sync_order_to_supabase($order_id, $conn);
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

// ==============================================================
// GET: MASTER DISPATCH MAP TELEMETRY (Stores & Active Dispatched Riders)
// ==============================================================
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (in_array(($_GET['action'] ?? ''), ['get_dispatch_map', 'get_fleet', 'dispatch_map', 'fleet'], true) || isset($_GET['dispatch_map']))) {
    if ($role !== 'admin' && $role !== 'chef') {
        out(false, 'Unauthorized. Admin access required.', [], 403);
    }

    // 1. Query all partner stores with active orders count
    $store_stmt = $conn->query("SELECT 
        s.shop_id, s.shop_name, s.category, s.address,
        COALESCE(s.lat, s.lat, 11.00650000) AS lat,
        COALESCE(s.lng, s.lng, 124.60750000) AS lng,
        COALESCE(s.logo_url, 'assets/shops/default_store.png') AS logo_url,
        (SELECT COUNT(*) FROM food_orders fo 
         WHERE (fo.shop_id = s.shop_id OR (fo.shop_id = 0 AND LOWER(TRIM(fo.store_name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
           AND LOWER(TRIM(fo.status)) IN ('pending', 'preparing', 'ready_for_pickup', 'ready', 'assigned', 'on_delivery', 'out_for_delivery', 'on_the_way', 'near_customer', 'arrived')
        ) AS active_orders_count
    FROM shops s
    ORDER BY s.shop_name ASC");
    $stores = $store_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($stores as &$st) {
        $st['shop_id'] = (int)$st['shop_id'];
        $st['lat'] = (float)$st['lat'];
        $st['lng'] = (float)$st['lng'];
        $st['active_orders_count'] = (int)$st['active_orders_count'];
    }
    unset($st);

    // 2. Query active dispatched riders (status = 'on_delivery' or current_order != 'None')
    $raw_riders = [];
    try {
        $rider_stmt = $conn->query("SELECT 
            r.rider_id, r.user_id, r.rider_name,
            COALESCE(r.phone, u.phone, '+63 917 555 0192') AS rider_phone,
            r.status AS rider_status,
            COALESCE(r.account_status, 'Active') AS account_status,
            COALESCE(r.plate_number, 'ORM-8891') AS plate_number,
            COALESCE(r.vehicle_model, 'Honda Click 125i') AS vehicle_model,
            COALESCE(r.current_lat, 11.00650000) AS current_lat, 
            COALESCE(r.current_lng, 124.60750000) AS current_lng, 
            r.current_order
        FROM riders r
        LEFT JOIN users u ON (r.user_id = u.user_id)
        WHERE r.status = 'on_delivery' 
           OR (r.current_order IS NOT NULL AND r.current_order != 'None' AND r.current_order != '')
           OR EXISTS (SELECT 1 FROM food_orders fo WHERE fo.rider_id = r.rider_id AND LOWER(TRIM(fo.status)) IN ('on_delivery', 'out_for_delivery', 'on_the_way', 'near_customer', 'arrived'))
        ORDER BY r.rider_id ASC");
        if ($rider_stmt) $raw_riders = $rider_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $rider_stmt = $conn->query("SELECT 
            r.rider_id, r.user_id, r.rider_name,
            '+63 917 555 0192' AS rider_phone,
            r.status AS rider_status,
            'Active' AS account_status,
            'ORM-8891' AS plate_number,
            'Honda Click 125i' AS vehicle_model,
            11.00650000 AS current_lat, 
            124.60750000 AS current_lng, 
            r.current_order
        FROM riders r
        WHERE r.status = 'on_delivery' 
           OR (r.current_order IS NOT NULL AND r.current_order != 'None' AND r.current_order != '')
           OR EXISTS (SELECT 1 FROM food_orders fo WHERE fo.rider_id = r.rider_id AND LOWER(TRIM(fo.status)) IN ('on_delivery', 'out_for_delivery', 'on_the_way', 'near_customer', 'arrived'))
        ORDER BY r.rider_id ASC");
        if ($rider_stmt) $raw_riders = $rider_stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $riders = [];
    foreach ($raw_riders as $rd) {
        $rid = (int)$rd['rider_id'];
        $cur_ref = trim((string)$rd['current_order']);

        $o_stmt = $conn->prepare("SELECT 
            o.order_id, o.order_ref, o.store_name, o.delivery_address,
            o.items_summary, o.total_amount, o.status AS order_status, o.delivery_status,
            COALESCE(o.delivery_lat, o.customer_lat) AS dest_lat,
            COALESCE(o.delivery_lng, o.customer_lng) AS dest_lng,
            COALESCE(o.store_lat, sh.lat, sh.lat, 11.0065) AS store_lat,
            COALESCE(o.store_lng, sh.lng, sh.lng, 124.6075) AS store_lng,
            COALESCE(o.rider_lat, ?) AS live_rider_lat,
            COALESCE(o.rider_lng, ?) AS live_rider_lng
        FROM food_orders o
        LEFT JOIN shops sh ON (o.shop_id = sh.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) COLLATE utf8mb4_unicode_ci = LOWER(TRIM(sh.shop_name)) COLLATE utf8mb4_unicode_ci))
        WHERE o.rider_id = ? 
          AND (
            o.order_ref COLLATE utf8mb4_unicode_ci = ?
            OR CONCAT('#', o.order_id) COLLATE utf8mb4_unicode_ci = ?
            OR CAST(o.order_id AS CHAR) COLLATE utf8mb4_unicode_ci = ?
            OR LOWER(TRIM(o.status)) IN ('on_delivery', 'out_for_delivery', 'on_the_way', 'near_customer', 'arrived')
          )
        ORDER BY o.order_id DESC LIMIT 1");
        $o_stmt->execute([
            $rd['current_lat'],
            $rd['current_lng'],
            $rid,
            $cur_ref,
            $cur_ref,
            $cur_ref
        ]);
        $ord = $o_stmt->fetch(PDO::FETCH_ASSOC);

        if ($ord) {
            apply_dynamic_geocoding_to_order($ord);
        }

        $r_lat = (isset($ord['live_rider_lat']) && is_numeric($ord['live_rider_lat']) && (float)$ord['live_rider_lat'] != 0)
            ? (float)$ord['live_rider_lat']
            : ((isset($rd['current_lat']) && is_numeric($rd['current_lat']) && (float)$rd['current_lat'] != 0) ? (float)$rd['current_lat'] : 11.00650000);

        $r_lng = (isset($ord['live_rider_lng']) && is_numeric($ord['live_rider_lng']) && (float)$ord['live_rider_lng'] != 0)
            ? (float)$ord['live_rider_lng']
            : ((isset($rd['current_lng']) && is_numeric($rd['current_lng']) && (float)$rd['current_lng'] != 0) ? (float)$rd['current_lng'] : 124.60750000);

        $d_lat = (isset($ord['dest_lat']) && is_numeric($ord['dest_lat'])) ? (float)$ord['dest_lat'] : null;
        $d_lng = (isset($ord['dest_lng']) && is_numeric($ord['dest_lng'])) ? (float)$ord['dest_lng'] : null;

        $distance_km = null;
        $eta_mins = 'Calculating...';
        if ($d_lat !== null && $d_lng !== null) {
            $distance_km = round(distance_km($d_lat, $d_lng, $r_lat, $r_lng), 2);
            $eta_val = max(1, round(($distance_km / 28) * 60)); // ~28km/h in city traffic
            $eta_mins = $eta_val . ' mins (' . $distance_km . ' km)';
        }

        $order_ref_display = !empty($ord['order_ref']) ? ('#' . ltrim($ord['order_ref'], '#')) : (!empty($rd['current_order']) && $rd['current_order'] !== 'None' ? ('#' . ltrim($rd['current_order'], '#')) : '#FD-' . ($ord['order_id'] ?? rand(1000, 9999)));

        $riders[] = [
            'rider_id'         => $rid,
            'rider_name'       => $rd['rider_name'],
            'rider_phone'      => $rd['rider_phone'],
            'rider_status'     => $rd['rider_status'],
            'account_status'   => $rd['account_status'],
            'plate_number'     => $rd['plate_number'],
            'vehicle_model'    => $rd['vehicle_model'],
            'lat'              => $r_lat,
            'lng'              => $r_lng,
            'current_order'    => $rd['current_order'],
            'order_id'         => (int)($ord['order_id'] ?? 0),
            'order_ref'        => $order_ref_display,
            'store_name'       => (!empty($ord['store_name']) ? $ord['store_name'] : 'Partner Store'),
            'delivery_address' => (!empty($ord['delivery_address']) ? $ord['delivery_address'] : 'Ormoc City, Leyte'),
            'items_summary'    => (!empty($ord['items_summary']) ? $ord['items_summary'] : 'Food Dash Package'),
            'total_amount'     => (float)($ord['total_amount'] ?? 0.00),
            'distance_km'      => $distance_km,
            'eta'              => $eta_mins
        ];
    }

    out(true, 'Master dispatch map telemetry loaded.', [
        'stores'               => $stores,
        'riders'               => $riders,
        'total_stores'         => count($stores),
        'active_riders_count'  => count($riders),
        'timestamp'            => date('Y-m-d H:i:s')
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (in_array(($_GET['action'] ?? ''), ['track', 'get_location'], true) || isset($_GET['order_id']))) {
    $order_id = (int)($_GET['order_id'] ?? 0);
    if ($order_id <= 0) {
        out(false, 'Order ID is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT 
        o.order_id, o.order_ref, o.customer_id, o.customer_name, 
        COALESCE(cu.phone, o.customer_phone) AS customer_phone,
        o.store_name, o.delivery_address, 
        o.status, o.delivery_status, o.near_notified_at, o.location_updated_at,
        o.store_lat,
        o.store_lng,
        COALESCE(o.delivery_lat, o.customer_lat, ord.delivery_lat) AS destination_lat,
        COALESCE(o.delivery_lng, o.customer_lng, ord.delivery_lng) AS destination_lng,
        o.customer_lat, o.customer_lng,
        o.rider_id,
        COALESCE(o.rider_lat, ord.current_lat, ord.rider_lat) AS rider_lat,
        COALESCE(o.rider_lng, ord.current_lng, ord.rider_lng) AS rider_lng,
        COALESCE(r.rider_name, ru.full_name, ord.rider_name) AS rider_name,
        COALESCE(ru.phone, ord.rider_phone) AS rider_phone
    FROM food_orders o
    LEFT JOIN orders ord ON (o.order_id = ord.id OR o.order_id = ord.order_id)
    LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name))))
    LEFT JOIN riders r ON (o.rider_id = r.rider_id)
    LEFT JOIN users ru ON (r.user_id = ru.user_id)
    LEFT JOIN users cu ON (o.customer_id = cu.user_id)
    WHERE o.order_id = :oid
    LIMIT 1");
    $stmt->execute([':oid' => $order_id]);
    $o = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$o) {
        try {
            $stmtOrd = $conn->prepare("SELECT 
                ord.id AS order_id, ord.order_number AS order_ref, ord.user_id AS customer_id,
                ord.delivery_address, ord.status, ord.store_lat, ord.store_lng,
                ord.delivery_lat AS destination_lat, ord.delivery_lng AS destination_lng,
                COALESCE(ord.current_lat, ord.rider_lat, 11.009035) AS rider_lat,
                COALESCE(ord.current_lng, ord.rider_lng, 124.609394) AS rider_lng,
                COALESCE(ord.rider_name, 'Alex Gonzaga') AS rider_name,
                COALESCE(ord.rider_phone, '+63 917 555 0192') AS rider_phone,
                COALESCE(ord.store_name, 'Partner Store') AS store_name
            FROM orders ord
            WHERE ord.id = :oid OR ord.order_id = :oid2
            LIMIT 1");
            $stmtOrd->execute([':oid' => $order_id, ':oid2' => $order_id]);
            $o = $stmtOrd->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {}
    }

    if (!$o) {
        out(false, 'Order not found.', [], 404);
    }

    // Customer can only view their own orders (or demo accounts)
    $is_demo = ($uid === 15 || $uid === 26 || strtolower($uname ?? '') === 'customer');
    if ($role === 'customer' && (int)$o['customer_id'] !== $uid && (int)($o['user_id'] ?? 0) !== $uid && (!$is_demo || !in_array((int)$o['customer_id'], [15, 26, 0]))) {
        out(false, 'Access denied.', [], 403);
    }

    $o['order_ref'] = '#' . ltrim($o['order_ref'] ?: ('FD-' . $o['order_id']), '#');

    // Apply robust dynamic geocoding to evaluate order details dynamically
    apply_dynamic_geocoding_to_order($o);

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
    $o['lat']  = $rider_lat;
    $o['lng'] = $rider_lng;

    // Ensure customer and rider phones are strings
    if (empty($o['customer_phone'])) $o['customer_phone'] = '';
    if (empty($o['rider_phone'])) $o['rider_phone'] = '';

    // Check for active call within the last 45 seconds
    $active_call = null;
    try {
        $call_stmt = $conn->prepare("SELECT call_id, order_id, caller_role, COALESCE(caller, caller_role) AS caller, caller_id, caller_name, caller_phone, receiver_role, receiver_id, COALESCE(call_status, status) AS call_status, status, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS elapsed_seconds FROM order_calls WHERE order_id = :oid AND (status IN ('ringing', 'calling', 'accepted', 'connected') OR call_status IN ('calling', 'ringing', 'connected', 'accepted')) AND updated_at >= (NOW() - INTERVAL 45 SECOND) ORDER BY call_id DESC LIMIT 1");
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
        
        
        'destination_lat'    => $dest_lat,
        'destination_lng'    => $dest_lng,
        'lat'           => $rider_lat,
        'lng'          => $rider_lng,
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


