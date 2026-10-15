<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'geocode_lib.php';

if (!isset($_SESSION['user_id']) || strtolower($_SESSION['role'] ?? '') !== 'rider') {
    header('Location: index.php'); exit();
}

$user_id = (int)$_SESSION['user_id'];
$username = $_SESSION['username'] ?? $_SESSION['user']['username'] ?? $_SESSION['full_name'] ?? 'Rider';
$user_name = $_SESSION['full_name'] ?? $_SESSION['username'] ?? $_SESSION['user']['username'] ?? 'Rider';
$msg = ''; $error = '';

// Match the rider account to riders table: user_id first, then name/Alex Gonzaga, fallback to 1
$r = null;
$stmt = $conn->prepare("SELECT rider_id, rider_name, status, current_lat, current_lng, current_order FROM riders WHERE user_id = :uid LIMIT 1");
$stmt->execute([':uid' => $user_id]);
$r = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$r) {
    $full = trim($user_name);
    $stmt = $conn->prepare("SELECT rider_id, rider_name, status, current_lat, current_lng, current_order FROM riders WHERE LOWER(TRIM(rider_name)) = LOWER(:rname) OR rider_name LIKE '%Alex%' LIMIT 1");
    $stmt->execute([':rname' => $full]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
}
if (!$r) {
    $q = $conn->query("SELECT rider_id, rider_name, status, current_lat, current_lng, current_order FROM riders ORDER BY rider_id ASC LIMIT 1");
    $r = $q ? $q->fetch(PDO::FETCH_ASSOC) : null;
}
$rid = $r ? (int)$r['rider_id'] : 1;
if ($r && !empty($r['rider_name'])) {
    $user_name = $r['rider_name'];
}

// Delivery actions: claim a ready order, start the trip, mark near, then complete.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rid > 0) {
    // Check if this is an AJAX request
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) $input = $_POST;
    
    $is_ajax = (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
        || (isset($input['ajax']) && $input['ajax'] === 'true');
    
    $action = $input['delivery_action'] ?? '';
    $order_id = (int)($input['order_id'] ?? 0);
    
    if ($order_id > 0) {
        $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, order_ref, status, delivery_status, rider_id, total_amount, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng FROM food_orders WHERE order_id = :oid LIMIT 1");
        $stmt->execute([':oid' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($is_ajax) {
            try {
                if (!$order) throw new Exception('Order not found');
                $customer_id = (int)$order['customer_id'];
                $ref = $order['order_ref'] ? $order['order_ref'] : '#' . $order['order_id'];
                $old_st = strtoupper(trim($order['status'] ?? ''));
                $norm_st = strtolower(trim($order['status'] ?? ''));

                if ($action === 'claim' || $action === 'pickup') {
                    $assigned_rid = (int)($order['rider_id'] ?? 0);
                    if (!in_array($norm_st, ['ready_for_pickup', 'ready', 'assigned', 'out_for_delivery']) || (!empty($assigned_rid) && $assigned_rid !== $rid)) {
                        throw new Exception('This order is not ready for pickup or has already been assigned.');
                    }
                    $stmt = $conn->prepare("UPDATE food_orders SET rider_id = :rid, delivery_status = 'on_the_way', status = 'OUT_FOR_DELIVERY', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':rid' => $rid, ':oid' => $order_id]);

                    $stmt = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE rider_id = :rid");
                    $stmt->execute([':ref' => $ref, ':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order picked up / confirmed by rider ' . $user_name);
                    create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . ' and is on the way!', $order_id);
                    notify_role($conn, 'admin', 'Rider Dispatched', 'Rider ' . $user_name . ' confirmed pickup and dispatched order ' . $ref, $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Order picked up! Status transitioned to On Delivery.', 'order_id' => $order_id]);
                    exit();
                } elseif ($action === 'on_way') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider is en route to customer');
                    create_notification($conn, $customer_id, 'rider_on_way', 'Rider On the Way', 'Rider ' . $user_name . ' is actively heading towards your delivery address.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Customer notified that you are on the way.']);
                    exit();
                } elseif ($action === 'near') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider is near customer drop-off');
                    create_notification($conn, $customer_id, 'rider_near', 'Rider Arriving Soon', 'Your rider is near your delivery address. Please prepare to receive ' . $ref . '.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Customer notified that you are near.']);
                    exit();
                } elseif ($action === 'delivered') {
                    if ((int)($order['rider_id'] ?? 0) !== $rid) throw new Exception('You are not assigned to this order.');
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', updated_at = NOW() WHERE order_id = :oid AND rider_id = :rid");
                    $stmt->execute([':oid' => $order_id, ':rid' => $rid]);

                    $stmt = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid");
                    $stmt->execute([':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'DELIVERED', $user_id, 'rider', 'Order successfully delivered to customer by ' . $user_name);
                    create_notification($conn, $customer_id, 'delivered', 'Order Delivered!', 'Your order ' . $ref . ' has been marked delivered. Enjoy your meal!', $order_id);
                    notify_role($conn, 'admin', 'Order Delivered - Ready for Settlement', 'Order ' . $ref . ' delivered by ' . $user_name . '. Awaiting audit reconciliation.', $order_id);

                    if (ob_get_level()) ob_clean();
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Delivery completed! Customer and Admin notified.']);
                    exit();
                } else {
                    throw new Exception('Unknown delivery action.');
                }
            } catch (Exception $e) {
                if (ob_get_level()) ob_clean();
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => $e->getMessage()]);
                exit();
            }
        } else {
            // Non-AJAX fallback
            if (!$order) {
                $error = 'Order not found.';
            } else {
                $customer_id = (int)$order['customer_id'];
                $ref = $order['order_ref'] ? $order['order_ref'] : '#' . $order['order_id'];
                $old_st = strtoupper(trim($order['status'] ?? ''));
                $norm_st = strtolower(trim($order['status'] ?? ''));

                if ($action === 'claim' || $action === 'pickup') {
                    $assigned_rid = (int)($order['rider_id'] ?? 0);
                    if (in_array($norm_st, ['ready_for_pickup', 'ready', 'assigned', 'out_for_delivery']) && (empty($assigned_rid) || $assigned_rid === $rid)) {
                        $stmt = $conn->prepare("UPDATE food_orders SET rider_id = :rid, delivery_status = 'on_the_way', status = 'OUT_FOR_DELIVERY', updated_at = NOW() WHERE order_id = :oid");
                        $stmt->execute([':rid' => $rid, ':oid' => $order_id]);

                        $stmt = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE rider_id = :rid");
                        $stmt->execute([':ref' => $ref, ':rid' => $rid]);

                        record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order picked up / confirmed by rider ' . $user_name);
                        create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . '.', $order_id);
                        $msg = 'Order picked up! Status transitioned to On Delivery.';
                    } else {
                        $error = 'Order is already assigned or not ready.';
                    }
                } elseif ($action === 'on_way' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'on_the_way', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider en route');
                    create_notification($conn, $customer_id, 'rider_on_way', 'Rider on the way', 'Your order ' . $ref . ' is on the way.', $order_id);
                    $msg = 'Customer notified that you are on the way.';
                } elseif ($action === 'near' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'near_customer', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Rider near customer');
                    create_notification($conn, $customer_id, 'rider_near', 'Rider near', 'Your rider is near.', $order_id);
                    $msg = 'Customer notified that you are near.';
                } elseif ($action === 'delivered' && (int)($order['rider_id'] ?? 0) === $rid) {
                    $stmt = $conn->prepare("UPDATE food_orders SET delivery_status = 'delivered', status = 'DELIVERED', updated_at = NOW() WHERE order_id = :oid");
                    $stmt->execute([':oid' => $order_id]);

                    $stmt = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE rider_id = :rid");
                    $stmt->execute([':rid' => $rid]);

                    record_order_audit_log($conn, $order_id, 'OUT_FOR_DELIVERY', 'DELIVERED', $user_id, 'rider', 'Order delivered by ' . $user_name);
                    create_notification($conn, $customer_id, 'delivered', 'Order delivered', 'Your order ' . $ref . ' has been delivered.', $order_id);
                    notify_role($conn, 'admin', 'Order Delivered', 'Order ' . $ref . ' delivered by ' . $user_name, $order_id);
                    $msg = 'Delivery completed and customer notified.';
                }
            }
        }
    }

    // Preserve the original manual rider status control.
    if (isset($input['action_status'])) {
        $status = ($input['status'] ?? '') === 'on_delivery' ? 'on_delivery' : 'waiting';
        $current = trim($input['current_order'] ?? ''); if ($status==='waiting') $current='None';
        $stmt=$conn->prepare("UPDATE riders SET status = :st, current_order = :cur WHERE rider_id = :rid");
        $stmt->execute([':st' => $status, ':cur' => $current, ':rid' => $rid]);
        $msg='Delivery status updated.';
    }
}

if ($r) {
    $stmt=$conn->prepare("SELECT rider_id, rider_name, status, current_lat, current_lng, current_order FROM riders WHERE rider_id = :rid");
    $stmt->execute([':rid' => $rid]);
    $r = $stmt->fetch(PDO::FETCH_ASSOC);
}

$ready_orders = [];
$my_orders = [];
if ($rid > 0) {
    // 1. Orders Ready for Pickup: Joined with shops table for real store coordinates
    $stmt_ready = $conn->prepare("SELECT 
            o.order_id, o.order_ref, o.customer_name, o.store_name, o.items_summary, o.total_amount, 
            o.status, o.delivery_status, o.rider_id, o.delivery_address, 
            o.delivery_lat, o.delivery_lng, o.customer_lat, o.customer_lng, o.created_at, o.shop_id,
            COALESCE(o.store_lat, s.latitude, s.lat) AS store_lat, 
            COALESCE(o.store_lng, s.longitude, s.lng) AS store_lng,
            s.address AS store_address,
            s.shop_name AS shop_record_name
        FROM food_orders o
        LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
        WHERE LOWER(TRIM(o.status)) IN ('ready_for_pickup', 'ready', 'assigned') 
          AND (o.rider_id IS NULL OR o.rider_id = 0) 
        ORDER BY o.order_id DESC LIMIT 30");
    $stmt_ready->execute();
    $ready_orders = $stmt_ready->fetchAll(PDO::FETCH_ASSOC);

    // 2. My Delivery: Joined with shops table for real store coordinates and riders table for true live rider GPS
    $stmt_my = $conn->prepare("SELECT 
            o.order_id, o.order_ref, o.customer_name, o.store_name, o.items_summary, o.total_amount, 
            o.status, o.delivery_status, o.rider_id, o.delivery_address, 
            o.delivery_lat, o.delivery_lng, o.customer_lat, o.customer_lng, 
            o.rider_lat, o.rider_lng, o.created_at, o.shop_id,
            COALESCE(o.store_lat, s.latitude, s.lat) AS store_lat, 
            COALESCE(o.store_lng, s.longitude, s.lng) AS store_lng,
            s.address AS store_address,
            s.shop_name AS shop_record_name,
            r.current_lat AS rider_current_lat,
            r.current_lng AS rider_current_lng
        FROM food_orders o
        LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
        LEFT JOIN riders r ON (o.rider_id = r.rider_id)
        WHERE o.rider_id = :rid 
          AND (LOWER(TRIM(o.status)) IN ('ready_for_pickup', 'ready', 'assigned', 'out_for_delivery', 'on_delivery', 'on_the_way', 'near_customer')) 
        ORDER BY o.order_id DESC LIMIT 30");
    $stmt_my->execute([':rid' => $rid]);
    $my_orders = $stmt_my->fetchAll(PDO::FETCH_ASSOC);
}

$active_order_for_map = null;
$first_order = !empty($my_orders) ? $my_orders[0] : (!empty($ready_orders) ? $ready_orders[0] : null);
if ($first_order) {

    // 1. Customer Destination: strictly from order delivery coordinates without arbitrary offsets
    $c_lat = (isset($first_order['delivery_lat']) && is_numeric($first_order['delivery_lat'])) 
        ? (float)$first_order['delivery_lat'] 
        : ((isset($first_order['customer_lat']) && is_numeric($first_order['customer_lat'])) ? (float)$first_order['customer_lat'] : null);
    $c_lng = (isset($first_order['delivery_lng']) && is_numeric($first_order['delivery_lng'])) 
        ? (float)$first_order['delivery_lng'] 
        : ((isset($first_order['customer_lng']) && is_numeric($first_order['customer_lng'])) ? (float)$first_order['customer_lng'] : null);

    if ($c_lat !== null && ((float)$c_lat == 0 || $c_lat < -90 || $c_lat > 90)) $c_lat = null;
    if ($c_lng !== null && ((float)$c_lng == 0 || $c_lng < -180 || $c_lng > 180)) $c_lng = null;

    // 2. Dynamic Store Origin: retrieved from store/order record; if missing or 0, dynamically geocode on the fly
    $s_lat = (isset($first_order['store_lat']) && is_numeric($first_order['store_lat'])) ? (float)$first_order['store_lat'] : null;
    $s_lng = (isset($first_order['store_lng']) && is_numeric($first_order['store_lng'])) ? (float)$first_order['store_lng'] : null;
    if ($s_lat !== null && ((float)$s_lat == 0 || $s_lat < -90 || $s_lat > 90)) $s_lat = null;
    if ($s_lng !== null && ((float)$s_lng == 0 || $s_lng < -180 || $s_lng > 180)) $s_lng = null;

    // If store coordinates are missing or zero, dynamically fetch coordinates on the fly using Nominatim OpenStreetMap
    if ($s_lat === null || $s_lng === null) {
        $store_search_name = !empty($first_order['shop_record_name']) ? $first_order['shop_record_name'] : ($first_order['store_name'] ?? '');
        $store_search_addr = !empty($first_order['store_address']) ? $first_order['store_address'] : '';
        
        $queries_to_try = [];
        if (!empty($store_search_name) && !empty($store_search_addr)) {
            $queries_to_try[] = $store_search_name . ', ' . $store_search_addr . ', Ormoc City, Leyte';
            $queries_to_try[] = $store_search_addr . ', Ormoc City, Leyte';
        } elseif (!empty($store_search_addr)) {
            $queries_to_try[] = $store_search_addr . ', Ormoc City, Leyte';
        }
        if (!empty($store_search_name)) {
            $queries_to_try[] = $store_search_name . ', Ormoc City, Leyte';
        }

        foreach ($queries_to_try as $query_str) {
            $geo = geocode_address_fallback($query_str);
            // Strictly require valid coordinates and reject default city center fallback
            if ($geo && isset($geo['lat'], $geo['lng']) && is_numeric($geo['lat']) && is_numeric($geo['lng']) && ($geo['source'] ?? '') !== 'default_ormoc') {
                $s_lat = (float)$geo['lat'];
                $s_lng = (float)$geo['lng'];

                // Persist the verified coordinates back to database
                if (!empty($first_order['shop_id'])) {
                    $u_stmt = $conn->prepare("UPDATE shops SET latitude = ?, longitude = ?, lat = ?, lng = ? WHERE shop_id = ?");
                    $u_stmt->execute([$s_lat, $s_lng, $s_lat, $s_lng, (int)$first_order['shop_id']]);
                }
                if (!empty($first_order['order_id'])) {
                    $u_stmt2 = $conn->prepare("UPDATE food_orders SET store_lat = ?, store_lng = ? WHERE order_id = ?");
                    $u_stmt2->execute([$s_lat, $s_lng, (int)$first_order['order_id']]);
                }
                break;
            }
        }
    }

    // 3. True Live Rider GPS: from riders table or food_orders table
    $live_rider_lat = null;
    $live_rider_lng = null;

    if (isset($first_order['rider_current_lat']) && is_numeric($first_order['rider_current_lat'])) {
        $live_rider_lat = (float)$first_order['rider_current_lat'];
    } elseif (isset($r['current_lat']) && is_numeric($r['current_lat'])) {
        $live_rider_lat = (float)$r['current_lat'];
    } elseif (isset($first_order['rider_lat']) && is_numeric($first_order['rider_lat'])) {
        $live_rider_lat = (float)$first_order['rider_lat'];
    }

    if (isset($first_order['rider_current_lng']) && is_numeric($first_order['rider_current_lng'])) {
        $live_rider_lng = (float)$first_order['rider_current_lng'];
    } elseif (isset($r['current_lng']) && is_numeric($r['current_lng'])) {
        $live_rider_lng = (float)$r['current_lng'];
    } elseif (isset($first_order['rider_lng']) && is_numeric($first_order['rider_lng'])) {
        $live_rider_lng = (float)$first_order['rider_lng'];
    }

    if ($live_rider_lat !== null && ((float)$live_rider_lat == 0 || $live_rider_lat < -90 || $live_rider_lat > 90)) $live_rider_lat = null;
    if ($live_rider_lng !== null && ((float)$live_rider_lng == 0 || $live_rider_lng < -180 || $live_rider_lng > 180)) $live_rider_lng = null;

    $r_lat = null;
    $r_lng = null;
    $is_initial_shop_origin = false;

    if ($live_rider_lat !== null && $live_rider_lng !== null) {
        // True Live Rider GPS: Rider has transmitted live coordinates -> use those exact values
        $r_lat = $live_rider_lat;
        $r_lng = $live_rider_lng;
        $is_initial_shop_origin = false;
    } elseif ($s_lat !== null && $s_lng !== null) {
        // Dynamic Store Origin: Rider has not transmitted live coordinates yet -> default starting point of the route and initial pickup marker strictly to store's actual database coordinates
        $r_lat = $s_lat;
        $r_lng = $s_lng;
        $is_initial_shop_origin = true;
    }
    // If neither exists, $r_lat and $r_lng remain null. NO phantom markers, NO offsets!

    $active_order_for_map = [
        'order_id' => (int)$first_order['order_id'],
        'order_ref' => (string)$first_order['order_ref'],
        'customer_name' => (string)($first_order['customer_name'] ?? 'Customer'),
        'store_name' => (string)($first_order['store_name'] ?? 'Restaurant'),
        'store_address' => (string)($first_order['store_address'] ?? ''),
        'delivery_address' => (string)($first_order['delivery_address'] ?? ''),
        'customer_lat' => $c_lat,
        'customer_lng' => $c_lng,
        'delivery_lat' => $c_lat,
        'delivery_lng' => $c_lng,
        'store_lat' => $s_lat,
        'store_lng' => $s_lng,
        'rider_lat' => $r_lat,
        'rider_lng' => $r_lng,
        'is_initial_shop_origin' => $is_initial_shop_origin
    ];
}
$active_delivery_json = $active_order_for_map ? json_encode($active_order_for_map, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) : 'null';

$total_riders = (int)($conn->query("SELECT COUNT(*) AS c FROM riders")->fetchColumn() ?: 0);
$waiting = (int)($conn->query("SELECT COUNT(*) AS c FROM riders WHERE status='waiting'")->fetchColumn() ?: 0);
$on_delivery = (int)($conn->query("SELECT COUNT(*) AS c FROM riders WHERE status='on_delivery'")->fetchColumn() ?: 0);
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Food Dash - Rider</title><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
@import url('https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap');
:root{
  --harvest-sun:#D88F2A;
  --sagebrush:#788A6E;
  --earth-clay:#923D32;
  --olive-shadow:#4B5320;
  --sandstone:#D4C5A5;
  --green:#4B5320;
  --green2:#788A6E;
  --cream:#f6f4e8;
  --gold:#D88F2A;
  --ink:#283510;
  --line:#e5e0cf;
  --white:#ffffff;
  --muted:#727568;
}
*{box-sizing:border-box}
html, body {
  min-height: 100vh;
  width: 100%;
  background:
    radial-gradient(circle at 15% 10%, rgba(216,143,42,.12), transparent 30%),
    radial-gradient(circle at 85% 90%, rgba(75,83,32,.13), transparent 35%),
    #f4f0df;
  font-family: 'Poppins', sans-serif;
  color: var(--ink);
  display: flex;
  justify-content: center;
  align-items: flex-start;
  margin: 0;
  padding: 20px 0;
}
.app {
  width: 100%;
  max-width: 440px;
  min-height: 100vh;
  margin: 0 auto;
  background: var(--white);
  display: flex;
  flex-direction: column;
  position: relative;
  box-shadow: 0 10px 30px rgba(75,83,32,.12);
}
@media(min-width: 600px){
  .app {
    min-height: 720px;
    height: auto;
    border: 1px solid #ddd3ad;
    border-radius: 26px;
    box-shadow: 0 22px 60px rgba(55,65,22,.15);
    overflow: hidden;
  }
}
@media(max-width: 599px){
  html, body { padding: 0; }
  .app { border: none; border-radius: 0; box-shadow: none; min-height: 100vh; }
}
.hero{background:var(--olive-shadow);color:white;padding:20px 18px 23px;border-radius:0 0 25px 25px}
.brand{display:flex;align-items:center;gap:10px}
.brand img{width:42px;height:42px;border-radius:50%;object-fit:cover;border:2px solid white}
.brand b{letter-spacing:1px}
.topline{display:flex;justify-content:space-between;align-items:center}
.bell{position:relative;border:0;background:transparent;color:#fff;font-size:18px;cursor:pointer}
.bell-badge{position:absolute;top:-5px;right:-6px;background:var(--earth-clay);color:#fff;border-radius:9px;font-size:7px;padding:2px 5px;display:none}
.hello{margin-top:18px}
.hello small{opacity:.75}
.hello h1{margin:4px 0;font-size:1.28rem}
.status{display:inline-flex;align-items:center;gap:7px;background:#ffffff1b;padding:7px 10px;border-radius:99px;font-size:.7rem;margin-top:8px}
.dot{width:8px;height:8px;border-radius:50%;background:var(--sandstone)}
.dot.live{background:#a9d26a}
main{padding:16px;flex:1;overflow-x:hidden}
.alert{padding:11px 13px;background:#edf3d8;color:#43531b;border-radius:12px;margin-bottom:12px;font-size:.8rem}
.alert.err{background:#fff0ed;color:var(--earth-clay)}
.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:9px}
.stat{background:#fff;border:1px solid var(--line);border-radius:15px;padding:12px 8px;text-align:center}
.stat i{color:var(--harvest-sun)}
.stat strong{display:block;font-size:1.1rem;margin-top:4px}
.stat span{font-size:.6rem;color:var(--muted)}
.section{margin-top:18px}
.section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}
.section-title h2{font-size:1rem;margin:0}
.card{background:#fff;border:1px solid var(--line);border-radius:17px;padding:14px;box-shadow:0 5px 18px rgba(75,83,32,0.06)}
.order{background:#f8f6f0;border:1px solid var(--sandstone);border-radius:14px;padding:13px;margin-bottom:10px}
.order:last-child{margin-bottom:0}
.order-ref{font-weight:700;font-size:.98rem;color:var(--olive-shadow)}
.meta{font-size:.7rem;color:#555;margin-top:3px;line-height:1.55}
.actions{display:grid;grid-template-columns:1fr 1fr;gap:7px;margin-top:10px}
.btn{border:0;border-radius:10px;padding:10px;background:var(--olive-shadow);color:#fff;font-family:inherit;font-size:.72rem;font-weight:700;cursor:pointer;transition:.2s}
.btn:hover{filter:brightness(1.1)}
.btn.secondary{background:#ece8d7;color:var(--olive-shadow)}
.btn.warn{background:var(--harvest-sun);color:#fff}
.btn.success{background:var(--sagebrush);color:#fff}
.btn.full{grid-column:1/-1}

/* Prominent Delivery Address Card & Google Maps Navigation */
.rider-address-card {
  background: #fdfbf7;
  border: 1.5px solid #d4c5a5;
  border-left: 4.5px solid var(--harvest-sun);
  border-radius: 12px;
  padding: 10px 12px;
  margin: 10px 0 8px;
  text-align: left;
  box-shadow: 0 2px 8px rgba(75, 83, 32, 0.05);
}
.rider-address-title {
  font-size: 0.65rem;
  font-weight: 700;
  text-transform: uppercase;
  letter-spacing: 0.6px;
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 4px;
}
.rider-address-title i {
  color: var(--harvest-sun);
  font-size: 0.8rem;
}
.rider-address-text {
  font-size: 0.84rem;
  font-weight: 600;
  color: var(--ink);
  line-height: 1.45;
  word-break: break-word;
}
.rider-gps-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 0.65rem;
  color: var(--olive-shadow);
  background: rgba(120, 138, 110, 0.15);
  border: 1px solid rgba(120, 138, 110, 0.25);
  padding: 3px 8px;
  border-radius: 6px;
  margin-top: 6px;
  font-weight: 600;
}
/* Embedded Delivery Map & Custom Markers */
#delivery-map {
  height: 320px;
  width: 100%;
  border-radius: 12px;
  margin-top: 12px;
  border: 1.5px solid #d4c5a5;
  box-shadow: inset 0 2px 6px rgba(0,0,0,0.06);
  position: relative;
  z-index: 1;
}
.leaflet-control-zoom {
  border-radius: 8px !important;
  overflow: hidden;
  box-shadow: 0 2px 8px rgba(0,0,0,0.15) !important;
  border: none !important;
}
.leaflet-popup-content-wrapper {
  border-radius: 10px !important;
  font-family: 'Poppins', sans-serif !important;
  box-shadow: 0 4px 16px rgba(75,83,32,0.2) !important;
}
.leaflet-popup-content {
  margin: 10px 12px !important;
  font-size: 0.72rem !important;
  line-height: 1.45 !important;
  color: var(--ink) !important;
}
.custom-map-marker {
  background: transparent !important;
  border: none !important;
}
@keyframes riderPulse {
  0% { transform: scale(0.8); opacity: 0.8; }
  70% { transform: scale(1.6); opacity: 0; }
  100% { transform: scale(1.6); opacity: 0; }
}
.map-status-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fdfbf7;
  border: 1px solid #d4c5a5;
  border-radius: 8px;
  padding: 7px 11px;
  margin-top: 8px;
  font-size: 0.72rem;
  color: var(--olive-shadow);
}
.btn-gps-active {
  background: #2e7d32 !important;
  color: #ffffff !important;
  border-color: #2e7d32 !important;
  box-shadow: 0 4px 14px rgba(46, 125, 50, 0.40) !important;
}

.empty{font-size:.76rem;color:var(--muted);text-align:center;padding:18px}
.bottom {
  position: sticky;
  bottom: 0;
  width: 100%;
  height: 64px;
  background: rgba(255, 255, 255, 0.98);
  backdrop-filter: blur(8px);
  border-top: 1px solid var(--line);
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  z-index: 100;
  margin-top: auto;
}
.bottom a {
  color: var(--muted);
  text-decoration: none;
  font-size: 8px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 3px;
}
.bottom i { font-size: 15px; margin-bottom: 0; }
.bottom a.active { color: var(--olive-shadow); font-weight: 700; }
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);backdrop-filter:blur(3px);align-items:center;justify-content:center;padding:18px;z-index:1000}
.modal.show{display:flex}
.box{width:min(360px,100%);background:var(--white);border:1px solid var(--sandstone);border-radius:22px;padding:20px;text-align:center;box-shadow:0 20px 60px rgba(75,83,32,0.25)}
.box img{width:58px;height:58px;border-radius:50%;border:2px solid var(--sandstone)}
.box h3{font-size:16px;margin:8px 0 5px;color:var(--olive-shadow)}
.box p{font-size:9px;color:var(--muted);line-height:1.5}
.box-actions{display:flex;gap:8px;margin-top:14px}
.box-actions>*{flex:1}
.notification-list{max-height:55vh;overflow:auto;text-align:left;margin-top:10px}
.note{padding:11px;border-bottom:1px solid var(--line);font-size:9px}
.note.unread{background:#fdf8ef;border-left:3px solid var(--harvest-sun)}
.note b{display:block;font-size:10px;color:var(--olive-shadow)}
.note small{display:block;color:var(--muted);margin-top:3px}
.close{border:0;background:var(--sandstone);color:var(--olive-shadow);border-radius:10px;padding:10px;font-family:inherit;font-weight:700;width:100%;margin-top:10px;cursor:pointer}

.toast-rider{position:fixed;left:50%;bottom:88px;transform:translateX(-50%) translateY(20px);background:var(--olive-shadow);color:#fff;padding:10px 18px;border-radius:20px;font-size:11px;font-weight:600;opacity:0;pointer-events:none;transition:.25s;z-index:200;white-space:nowrap;box-shadow:0 4px 15px rgba(75,83,32,.30)}
.toast-rider.show{opacity:1;transform:translateX(-50%) translateY(0)}
.toast-rider.err{background:var(--earth-clay)}
</style></head><body><div class="app"><header class="hero"><div class="topline"><div class="brand"><img src="logo.jpg" alt="Food Dash"><b>FOOD DASH</b></div><button class="bell" onclick="openNotifications()"><i class="fa-regular fa-bell"></i><span id="noteBadge" class="bell-badge">0</span></button></div><div class="hello"><small>Rider Portal</small><h1>Ready to dash, <?=htmlspecialchars($user_name)?>?</h1><small>Pick up orders and keep customers updated.</small><div class="status"><span class="dot <?=$r && $r['status']==='on_delivery'?'live':''?>"></span><?=$r && $r['status']==='on_delivery'?'On Delivery':'Waiting for Delivery'?></div></div></header><main><?php if($msg):?><div class="alert">✓ <?=htmlspecialchars($msg)?></div><?php endif;?><?php if($error):?><div class="alert err">⚠ <?=htmlspecialchars($error)?></div><?php endif;?><div class="stats"><div class="stat"><i class="fa-solid fa-motorcycle"></i><strong><?=$total_riders?></strong><span>Total Riders</span></div><div class="stat"><i class="fa-solid fa-clock"></i><strong><?=$waiting?></strong><span>Waiting</span></div><div class="stat"><i class="fa-solid fa-route"></i><strong><?=$on_delivery?></strong><span>On Delivery</span></div></div>
<section class="section" id="delivery"><div class="section-title"><h2><i class="fa-solid fa-box"></i> My Delivery</h2></div><div class="card"><?php if($my_orders): foreach($my_orders as $o): 
  $ord_st = strtoupper($o['status'] ?? '');
  $norm_st = strtolower(trim($o['status'] ?? ''));
  $del_st = strtolower($o['delivery_status'] ?? '');
  $addr = trim((string)($o['delivery_address'] ?? ''));
  $lat = (isset($o['delivery_lat']) && is_numeric($o['delivery_lat'])) ? $o['delivery_lat'] : ($o['customer_lat'] ?? null);
  $lng = (isset($o['delivery_lng']) && is_numeric($o['delivery_lng'])) ? $o['delivery_lng'] : ($o['customer_lng'] ?? null);
  $has_coords = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && (float)$lat != 0 && (float)$lng != 0);
  $is_ready_pickup = in_array($norm_st, ['ready_for_pickup', 'ready', 'assigned']);
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?><br>Status: <b><?=htmlspecialchars(ucwords(str_replace('_',' ',$ord_st)))?></b> (<?=htmlspecialchars(ucwords(str_replace('_',' ',$del_st)))?>)</div><div class="rider-address-card"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Customer Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No street address provided (using coordinates / default)')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><div id="delivery-map" style="height: 320px; width: 100%; border-radius: 12px; margin-top: 12px;"></div><div class="map-status-bar"><span><i class="fa-solid fa-route" style="color:var(--harvest-sun)"></i> <b id="mapRouteDistance">Calculating route...</b></span><span id="mapGpsLiveTag" style="font-weight:600; color:#2e7d32; display:none;"><i class="fa-solid fa-circle-dot fa-beat-fade"></i> Live GPS Tracking</span></div><div id="gpsNoticeBanner" style="display:none;margin-top:8px;padding:9px 12px;background:#fff8e6;border:1.5px solid #d97706;border-radius:10px;font-size:0.73rem;color:#78350f;line-height:1.45;"><div style="display:flex;align-items:flex-start;gap:8px;"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:0.9rem;margin-top:2px;"></i><div style="flex:1;" id="gpsNoticeText">GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.</div></div></div><div class="actions" style="display:flex;flex-direction:column;gap:8px;margin-top:10px;"><?php if($is_ready_pickup): ?><button type="button" class="btn warn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'pickup')"><i class="fa-solid fa-box-open"></i> Pick Up / Confirm Order</button><?php else: ?><button type="button" class="btn secondary full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'pickup')" title="Order is picked up and on delivery"><i class="fa-solid fa-check"></i> Order Picked Up (On Delivery)</button><?php if($del_st === 'on_the_way'): ?><button type="button" class="btn warn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'near')"><i class="fa-solid fa-location-dot"></i> I'm Near Customer</button><?php endif; ?><?php endif; ?><button type="button" id="gpsToggleBtn_<?=$o['order_id']?>" class="btn secondary full gps-toggle-btn" onclick="toggleLiveLocation(<?=$o['order_id']?>)"><i class="fa-solid fa-satellite-dish"></i> <span id="gpsToggleText_<?=$o['order_id']?>">Share Live Location / GPS</span></button><button type="button" class="btn success full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'delivered')"><i class="fa-solid fa-circle-check"></i> Complete Delivery</button></div></div><?php endforeach; else: ?><div class="empty">No active delivery yet. Accept a ready order below.</div><?php endif; ?></div></section>
<section class="section" id="available"><div class="section-title"><h2>Orders Ready for Pickup</h2></div><div class="card"><?php if($ready_orders): foreach($ready_orders as $o): 
  $addr = trim((string)($o['delivery_address'] ?? ''));
  $lat = (isset($o['delivery_lat']) && is_numeric($o['delivery_lat'])) ? $o['delivery_lat'] : ($o['customer_lat'] ?? null);
  $lng = (isset($o['delivery_lng']) && is_numeric($o['delivery_lng'])) ? $o['delivery_lng'] : ($o['customer_lng'] ?? null);
  $has_coords = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && (float)$lat != 0 && (float)$lng != 0);
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?><br>₱<?=number_format((float)$o['total_amount'],2)?></div><div class="rider-address-card" style="margin:8px 0 10px;"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No delivery address provided')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><div style="margin-top:8px;"><button type="button" class="btn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'claim')"><i class="fa-solid fa-motorcycle"></i> Accept / Pick Up Order</button></div></div><?php endforeach; else: ?><div class="empty">No unassigned ready orders.</div><?php endif; ?></div></section>
<section class="section" id="live"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">📡 Live Location</h2><div id="gpsStatus" class="meta">Live location is off. Tap Share Live Location after starting a delivery.</div><button type="button" class="btn full" style="margin-top:10px" onclick="stopLiveLocation()">Stop Sharing</button></div></section><section class="section" id="guide"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">Customer Notification Flow</h2><div class="meta">When you accept an order, the customer is notified. When you start the trip, they receive an <b>On the Way</b> notification. When you tap <b>I'm Near Customer</b>, they receive the near-arrival notification. When delivered, the customer receives a final delivery notice.</div></div></section></main><nav class="bottom"><a class="active" href="#delivery"><i class="fa-solid fa-box"></i>Delivery</a><a href="#available"><i class="fa-solid fa-motorcycle"></i>Pickup</a><a href="#live"><i class="fa-solid fa-location-crosshairs"></i>Live</a><a href="#guide"><i class="fa-solid fa-circle-info"></i>Guide</a><a href="#" onclick="openLogout();return false"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></nav></div>
<div class="modal" id="noteModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Notifications</h3><div id="notificationList" class="notification-list"><div class="empty">Loading...</div></div><div class="box-actions"><button class="close" onclick="markAllRead()">Mark all read</button><button class="close" onclick="closeNotifications()">Close</button></div></div></div>
<div class="modal" id="logoutModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out.</p><div class="box-actions"><button class="close" onclick="closeLogout()">Cancel</button><a class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="logout.php?confirm=1">Yes, Log Out</a></div></div></div>
<div class="toast-rider" id="riderToast"></div>
<script>
function toastRider(msg, isError) {
  const el = document.getElementById('riderToast');
  el.textContent = msg;
  el.classList.toggle('err', !!isError);
  el.classList.add('show');
  clearTimeout(window._riderToast);
  window._riderToast = setTimeout(() => el.classList.remove('show'), 3500);
}

async function doDeliveryAction(orderId, action) {
  try {
    const fd = new FormData();
    fd.append('delivery_action', action);
    fd.append('order_id', orderId);
    fd.append('ajax', 'true');
    const r = await fetch(window.location.href, { method: 'POST', body: fd });
    
    if (!r.ok) {
      const text = await r.text();
      throw new Error('Server error ' + r.status + ': ' + text);
    }
    
    const data = await r.json();
    if (data.success) {
      toastRider(data.message || 'Action completed');
      setTimeout(() => location.reload(), 1500);
    } else {
      toastRider(data.message || 'Action failed', true);
      console.error('Delivery action failed:', data.message || 'No message from server');
    }
  } catch (e) {
    toastRider(e.message || ('Network error during ' + action), true);
    console.error('Delivery action error:', e);
  }
}

async function loadNotifications(){try{const r=await fetch('notification_api.php?limit=30',{cache:'no-store'}),d=await r.json();if(!d.success)return;const b=document.getElementById('noteBadge');b.textContent=d.unread;b.style.display=d.unread?'block':'none';const el=document.getElementById('notificationList');el.innerHTML=d.notifications.length?d.notifications.map(n=>`<div class="note ${n.is_read==0 || n.is_read===false || n.is_read=='f' || n.status==='unread'?'unread':''}" onclick="readNote(${n.notification_id})"><b>${esc(n.title)}</b><span>${esc(n.message)}</span><small>${esc(n.created_at)}</small></div>`).join(''):'<div class="empty">No notifications yet.</div>';}catch(e){}}
function esc(v){return String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]))}
async function readNote(id){await fetch('notification_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_read',notification_id:id})});loadNotifications()}
async function markAllRead(){await fetch('notification_api.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'mark_all_read'})});loadNotifications()}
function openNotifications(){document.getElementById('noteModal').classList.add('show');loadNotifications()}function closeNotifications(){document.getElementById('noteModal').classList.remove('show')}
function openLogout(){document.getElementById('logoutModal').classList.add('show')}function closeLogout(){document.getElementById('logoutModal').classList.remove('show')}
loadNotifications();setInterval(loadNotifications,5000);document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeNotifications();closeLogout()}});

// Active Delivery Data passed from backend
const activeDeliveryData = <?= $active_delivery_json ?>;

let deliveryMap = null;
let customerMarker = null;
let storeMarker = null;
let riderMarker = null;
let routePolyline = null;
let gpsWatchId = null;
let activeGpsOrderId = null;
let isManualSimulationActive = false;

// Real-world Haversine distance calculation in kilometers
function calculateHaversineDistanceKm(lat1, lon1, lat2, lon2) {
  const R = 6371; // Earth's radius in kilometers
  const dLat = (lat2 - lat1) * Math.PI / 180;
  const dLon = (lon2 - lon1) * Math.PI / 180;
  const a = 
    Math.sin(dLat / 2) * Math.sin(dLat / 2) +
    Math.cos(lat1 * Math.PI / 180) * Math.cos(lat2 * Math.PI / 180) *
    Math.sin(dLon / 2) * Math.sin(dLon / 2);
  const c = 2 * Math.atan2(Math.sqrt(a), Math.sqrt(1 - a));
  return R * c;
}

function getActiveCustomerCoord() {
  const cLat = activeDeliveryData ? (activeDeliveryData.delivery_lat ?? activeDeliveryData.customer_lat) : null;
  const cLng = activeDeliveryData ? (activeDeliveryData.delivery_lng ?? activeDeliveryData.customer_lng) : null;
  if (cLat !== null && cLng !== null && !isNaN(cLat) && !isNaN(cLng) && Number(cLat) !== 0) {
    return [Number(cLat), Number(cLng)];
  }
  return null;
}

function updateRouteUI(rLat, rLng, cLat, cLng) {
  if (routePolyline) {
    routePolyline.setLatLngs([[rLat, rLng], [cLat, cLng]]);
  }
  const distKm = calculateHaversineDistanceKm(rLat, rLng, cLat, cLng);
  const distEl = document.getElementById('mapRouteDistance');
  if (distEl) {
    if (distKm < 1) {
      distEl.textContent = `Distance: ${Math.round(distKm * 1000)} meters`;
    } else {
      distEl.textContent = `Distance: ${distKm.toFixed(2)} km`;
    }
  }
}

function showGpsNotice(msg) {
  const banner = document.getElementById('gpsNoticeBanner');
  const textEl = document.getElementById('gpsNoticeText');
  if (banner && textEl) {
    textEl.textContent = msg;
    banner.style.display = 'block';
  }
}

function hideGpsNotice() {
  const banner = document.getElementById('gpsNoticeBanner');
  if (banner) {
    banner.style.display = 'none';
  }
}

function createRiderIcon(isStore) {
  const iconClass = isStore ? 'fa-store' : 'fa-motorcycle';
  const bg = isStore ? '#D88F2A' : '#2e7d32';
  const pulse = isStore ? '' : '<div id="riderPulseRing" style="position:absolute;top:-4px;left:-4px;width:42px;height:42px;border-radius:50%;background:rgba(46,125,50,0.3);animation:riderPulse 2s infinite ease-out;"></div>';
  return L.divIcon({
    className: 'custom-map-marker',
    html: `
      <div style="position:relative;display:flex;flex-direction:column;align-items:center;">
        ${pulse}
        <div style="background:${bg};color:#ffffff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(0,0,0,0.3);border:2.5px solid #ffffff;font-size:15px;position:relative;z-index:2;">
          <i class="fa-solid ${iconClass}"></i>
        </div>
        <div style="width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:8px solid ${bg};margin-top:-2px;position:relative;z-index:2;"></div>
        <div style="background:rgba(0,0,0,0.2);width:16px;height:4px;border-radius:50%;margin-top:1px;"></div>
      </div>
    `,
    iconSize: [34, 46],
    iconAnchor: [17, 43],
    popupAnchor: [0, -42]
  });
}

function attachRiderMarkerDragHandlers() {
  if (!riderMarker || riderMarker._dragHandlersAttached) return;
  riderMarker._dragHandlersAttached = true;

  riderMarker.on('drag', (e) => {
    const { lat, lng } = e.target.getLatLng();
    const custCoord = getActiveCustomerCoord();
    if (custCoord) {
      updateRouteUI(lat, lng, custCoord[0], custCoord[1]);
    }
  });

  riderMarker.on('dragend', (e) => {
    const { lat, lng } = e.target.getLatLng();
    handleRiderPositionUpdate(lat, lng, 'manual');
    toastRider(`Pin moved (${lat.toFixed(4)}, ${lng.toFixed(4)})`);
  });
}

function enableManualSimulation(noticeText) {
  isManualSimulationActive = true;
  
  const defaultNotice = "GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.";
  const msg = noticeText || defaultNotice;
  
  showGpsNotice(msg);
  toastRider(msg);

  const gpsStatusEl = document.getElementById('gpsStatus');
  if (gpsStatusEl) {
    gpsStatusEl.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-hand-pointer"></i> ${esc(msg)}</span>`;
  }

  // Ensure green motorcycle marker exists on map
  const sLat = activeDeliveryData ? activeDeliveryData.store_lat : null;
  const sLng = activeDeliveryData ? activeDeliveryData.store_lng : null;
  const cCoord = getActiveCustomerCoord();

  if (!riderMarker && deliveryMap) {
    const initialCoord = (sLat && sLng) ? [sLat, sLng] : (cCoord ? cCoord : deliveryMap.getCenter());
    const greenRiderIcon = createRiderIcon(false);
    riderMarker = L.marker(initialCoord, { icon: greenRiderIcon, draggable: true }).addTo(deliveryMap);
  }

  // Enable dragging on the green motorcycle marker
  if (riderMarker) {
    if (riderMarker.dragging) {
      riderMarker.dragging.enable();
    }
    attachRiderMarkerDragHandlers();
    riderMarker.bindPopup(`
      <b><i class="fa-solid fa-hand-pointer" style="color:#2e7d32"></i> Rider (Simulated Movement)</b><br>
      <span>Drag this pin or tap anywhere on the map to simulate live movement.</span>
    `).openPopup();
  }

  // Allow clicking anywhere on the map to position the rider
  if (deliveryMap && !deliveryMap._clickSimulationAttached) {
    deliveryMap._clickSimulationAttached = true;
    deliveryMap.on('click', (e) => {
      if (isManualSimulationActive) {
        const { lat, lng } = e.latlng;
        handleRiderPositionUpdate(lat, lng, 'manual');
      }
    });
  }

  if (activeGpsOrderId) {
    updateGpsButtonUI(activeGpsOrderId, true, true);
  }
}

function handleRiderPositionUpdate(latitude, longitude, source = 'gps', accuracy = null) {
  const numLat = parseFloat(latitude);
  const numLng = parseFloat(longitude);
  if (isNaN(numLat) || isNaN(numLng)) return;

  const greenRiderIcon = createRiderIcon(false);

  // Update or create rider marker
  if (!riderMarker) {
    if (deliveryMap) {
      riderMarker = L.marker([numLat, numLng], { icon: greenRiderIcon, draggable: isManualSimulationActive }).addTo(deliveryMap);
      attachRiderMarkerDragHandlers();
    }
  } else {
    riderMarker.setIcon(greenRiderIcon);
    riderMarker.setLatLng([numLat, numLng]);
  }

  if (riderMarker) {
    const accuracyText = accuracy ? `<br><small style="color:#666">Accuracy: ±${Math.round(accuracy)}m</small>` : '';
    const modeText = source === 'manual' ? 'Manual Movement (Drag/Tap)' : 'Live Hardware GPS';
    riderMarker.bindPopup(`
      <b><i class="fa-solid fa-motorcycle" style="color:#2e7d32"></i> Rider (${modeText})</b><br>
      <span>Lat: ${numLat.toFixed(5)}, Lng: ${numLng.toFixed(5)}</span>${accuracyText}
    `);
  }

  // Recalculate and update the polyline route directly between [latitude, longitude] and the customer's [delivery_lat, delivery_lng]
  const custCoord = getActiveCustomerCoord();
  if (custCoord && deliveryMap) {
    const riderCoord = [numLat, numLng];
    if (!routePolyline) {
      routePolyline = L.polyline([riderCoord, custCoord], {
        color: '#D88F2A',
        weight: 4,
        opacity: 0.85,
        dashArray: '7, 8',
        lineCap: 'round',
        lineJoin: 'round'
      }).addTo(deliveryMap);
    } else {
      routePolyline.setLatLngs([riderCoord, custCoord]);
    }

    // Dynamic Haversine distance
    const distKm = calculateHaversineDistanceKm(numLat, numLng, custCoord[0], custCoord[1]);
    const distEl = document.getElementById('mapRouteDistance');
    if (distEl) {
      if (distKm < 1) {
        distEl.textContent = `Distance: ${Math.round(distKm * 1000)} meters`;
      } else {
        distEl.textContent = `Distance: ${distKm.toFixed(2)} km`;
      }
    }
  }

  // Live status badge & tags
  const liveTag = document.getElementById('mapGpsLiveTag');
  if (liveTag) {
    liveTag.style.display = 'inline-flex';
    if (source === 'manual') {
      liveTag.innerHTML = '<i class="fa-solid fa-hand-pointer"></i> Manual Drag Movement';
    } else {
      liveTag.innerHTML = '<i class="fa-solid fa-circle-dot fa-beat-fade"></i> Live GPS Tracking';
    }
  }

  const gpsStatusEl = document.getElementById('gpsStatus');
  if (gpsStatusEl) {
    if (source === 'manual') {
      gpsStatusEl.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-hand-pointer"></i> Manual simulation active • ${numLat.toFixed(5)}, ${numLng.toFixed(5)}</span>`;
    } else {
      gpsStatusEl.textContent = `Live GPS active • ${numLat.toFixed(5)}, ${numLng.toFixed(5)}` + (accuracy ? ` (±${Math.round(accuracy)}m)` : '');
    }
  }

  // Post the updated coordinates to tracking_api.php
  const orderId = activeGpsOrderId || (activeDeliveryData ? activeDeliveryData.order_id : null);
  if (orderId) {
    sendRiderLocation(orderId, numLat, numLng);
  }
}

async function initDeliveryMap(data) {
  if (!data || !document.getElementById('delivery-map')) return;
  if (typeof L === 'undefined') {
    console.error('Leaflet library is not loaded');
    return;
  }

  // 3. Customer Pin: Always render strictly from order delivery_lat & delivery_lng
  const cLat = (data.delivery_lat !== null && !isNaN(data.delivery_lat)) ? Number(data.delivery_lat) : ((data.customer_lat !== null && !isNaN(data.customer_lat)) ? Number(data.customer_lat) : null);
  const cLng = (data.delivery_lng !== null && !isNaN(data.delivery_lng)) ? Number(data.delivery_lng) : ((data.customer_lng !== null && !isNaN(data.customer_lng)) ? Number(data.customer_lng) : null);
  const hasCustomerCoord = (cLat !== null && cLng !== null && cLat !== 0 && cLat >= -90 && cLat <= 90 && cLng >= -180 && cLng <= 180);

  // Store coordinates
  let sLat = (data.store_lat !== null && !isNaN(data.store_lat)) ? Number(data.store_lat) : null;
  let sLng = (data.store_lng !== null && !isNaN(data.store_lng)) ? Number(data.store_lng) : null;
  let hasStoreCoord = (sLat !== null && sLng !== null && sLat !== 0 && sLat >= -90 && sLat <= 90 && sLng >= -180 && sLng <= 180);

  // 1. Dynamic Store Pickup Pin: If store coordinates are missing or 0, dynamically fetch on the fly via Nominatim OpenStreetMap
  if (!hasStoreCoord && (data.store_name || data.store_address)) {
    const storeQuery = [data.store_name, data.store_address, 'Ormoc City, Leyte'].filter(Boolean).join(', ');
    try {
      const res = await fetch('https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' + encodeURIComponent(storeQuery), {
        headers: { 'Accept': 'application/json' }
      });
      const results = await res.json();
      if (results && results.length > 0 && results[0].lat && results[0].lon) {
        sLat = parseFloat(results[0].lat);
        sLng = parseFloat(results[0].lon);
        data.store_lat = sLat;
        data.store_lng = sLng;
        hasStoreCoord = true;
      }
    } catch (e) {
      console.warn('Frontend dynamic store geocoding warning:', e);
    }
  }

  // Initial rider coordinates if previously recorded
  const rLat = (data.rider_lat !== null && !isNaN(data.rider_lat)) ? Number(data.rider_lat) : null;
  const rLng = (data.rider_lng !== null && !isNaN(data.rider_lng)) ? Number(data.rider_lng) : null;
  const hasRiderCoord = (rLat !== null && rLng !== null && rLat !== 0 && rLat >= -90 && rLat <= 90 && rLng >= -180 && rLng <= 180);

  // If absolutely no valid coordinates exist yet, do not render a phantom marker in the sea
  if (!hasCustomerCoord && !hasStoreCoord && !hasRiderCoord) {
    const distEl = document.getElementById('mapRouteDistance');
    if (distEl) distEl.textContent = 'Awaiting delivery coordinates…';
    return;
  }

  // Initialize Map
  deliveryMap = L.map('delivery-map', {
    zoomControl: true,
    scrollWheelZoom: true,
    attributionControl: false
  });

  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 19,
    attribution: '&copy; OpenStreetMap contributors'
  }).addTo(deliveryMap);

  // 3. Customer Destination Custom Marker strictly from delivery_lat / delivery_lng
  if (hasCustomerCoord) {
    const customerCoord = [cLat, cLng];
    const customerIcon = L.divIcon({
      className: 'custom-map-marker',
      html: `
        <div style="position:relative;display:flex;flex-direction:column;align-items:center;">
          <div style="background:#923D32;color:#ffffff;width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;box-shadow:0 3px 10px rgba(146,61,50,0.45);border:2.5px solid #ffffff;font-size:15px;">
            <i class="fa-solid fa-house-chimney"></i>
          </div>
          <div style="width:0;height:0;border-left:6px solid transparent;border-right:6px solid transparent;border-top:8px solid #923D32;margin-top:-2px;"></div>
          <div style="background:rgba(0,0,0,0.2);width:16px;height:4px;border-radius:50%;margin-top:1px;"></div>
        </div>
      `,
      iconSize: [34, 46],
      iconAnchor: [17, 43],
      popupAnchor: [0, -42]
    });

    customerMarker = L.marker(customerCoord, { icon: customerIcon }).addTo(deliveryMap);
    customerMarker.bindPopup(`
      <b><i class="fa-solid fa-location-dot" style="color:#923D32"></i> Customer Destination</b><br>
      <strong>${esc(data.customer_name)}</strong><br>
      <span>${esc(data.delivery_address || 'Delivery Address')}</span>
    `);
  }

  // 1. Dynamic Starting Store Pickup Marker directly at store's real location
  if (hasStoreCoord) {
    const storeCoord = [sLat, sLng];
    const storeIcon = createRiderIcon(true);
    storeMarker = L.marker(storeCoord, { icon: storeIcon }).addTo(deliveryMap);
    storeMarker.bindPopup(`<b><i class="fa-solid fa-store" style="color:#D88F2A"></i> ${esc(data.store_name)}</b><br><small>Store Pickup Origin</small>`);
  }

  // Live Rider Location Marker
  if (hasRiderCoord && !data.is_initial_shop_origin) {
    const riderCoord = [rLat, rLng];
    const icon = createRiderIcon(false);
    riderMarker = L.marker(riderCoord, { icon: icon }).addTo(deliveryMap);
    riderMarker.bindPopup(`<b><i class="fa-solid fa-motorcycle" style="color:#2e7d32"></i> Rider Location</b><br>On Route`);
    attachRiderMarkerDragHandlers();
  }

  // Route Polyline & Bounds: Between active origin (rider live GPS or store pickup) and customer destination
  const activeOriginCoord = (hasRiderCoord && !data.is_initial_shop_origin) ? [rLat, rLng] : (hasStoreCoord ? [sLat, sLng] : null);

  if (hasCustomerCoord && activeOriginCoord) {
    const customerCoord = [cLat, cLng];

    routePolyline = L.polyline([activeOriginCoord, customerCoord], {
      color: '#D88F2A',
      weight: 4,
      opacity: 0.85,
      dashArray: '7, 8',
      lineCap: 'round',
      lineJoin: 'round'
    }).addTo(deliveryMap);

    const bounds = L.latLngBounds([activeOriginCoord, customerCoord]);
    deliveryMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
    updateRouteUI(activeOriginCoord[0], activeOriginCoord[1], customerCoord[0], customerCoord[1]);
  } else if (hasCustomerCoord) {
    // Keep map centered strictly on customer destination until live GPS is acquired
    deliveryMap.setView([cLat, cLng], 16);
    const distEl = document.getElementById('mapRouteDistance');
    if (distEl) distEl.textContent = 'Waiting for live rider GPS…';
  } else if (activeOriginCoord) {
    deliveryMap.setView(activeOriginCoord, 16);
    const distEl = document.getElementById('mapRouteDistance');
    if (distEl) distEl.textContent = 'Store pickup location';
  }

  setTimeout(() => {
    if (deliveryMap) deliveryMap.invalidateSize();
  }, 250);
}

async function sendRiderLocation(orderId, lat, lng) {
  try {
    const r = await fetch('tracking_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'update_rider_location', order_id: orderId, lat: lat, lng: lng })
    });
    const d = await r.json();
    const gpsStatusEl = document.getElementById('gpsStatus');
    if (d.success) {
      if (gpsStatusEl) {
        const modeLabel = isManualSimulationActive ? 'Manual pin sharing' : 'Live GPS sharing';
        gpsStatusEl.textContent = `${modeLabel} • ${lat.toFixed(5)}, ${lng.toFixed(5)}` + 
          (d.distance_km != null ? ` • ${(d.distance_km < 1 ? (d.distance_km * 1000).toFixed(0) + ' m' : d.distance_km.toFixed(2) + ' km')} from customer` : '');
      }
      return true;
    } else {
      if (gpsStatusEl) gpsStatusEl.textContent = 'GPS update warning: ' + (d.message || 'Could not save location');
      return false;
    }
  } catch (e) {
    console.error('GPS transmission error:', e);
    const gpsStatusEl = document.getElementById('gpsStatus');
    if (gpsStatusEl) gpsStatusEl.textContent = 'Location update failed. Retrying…';
    return false;
  }
}

function updateGpsButtonUI(orderId, isSharing, isManual = false) {
  const btn = document.getElementById('gpsToggleBtn_' + orderId);
  const text = document.getElementById('gpsToggleText_' + orderId);
  if (!btn) return;
  if (isSharing) {
    btn.classList.remove('secondary');
    btn.classList.add('btn-gps-active');
    if (text) {
      if (isManual) {
        text.innerHTML = '<i class="fa-solid fa-hand-pointer fa-beat-fade"></i> Manual GPS Active (Tap or Drag Pin)';
      } else {
        text.innerHTML = '<i class="fa-solid fa-satellite fa-beat-fade"></i> Sharing Live GPS (Tap to Stop)';
      }
    }
  } else {
    btn.classList.remove('btn-gps-active');
    btn.classList.add('secondary');
    if (text) text.innerHTML = '<i class="fa-solid fa-satellite-dish"></i> Share Live Location / GPS';
  }
}

function stopLiveLocation() {
  if (gpsWatchId !== null) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
  }
  isManualSimulationActive = false;
  if (riderMarker && riderMarker.dragging) {
    riderMarker.dragging.disable();
  }
  hideGpsNotice();
  if (activeGpsOrderId !== null) {
    updateGpsButtonUI(activeGpsOrderId, false);
    activeGpsOrderId = null;
  }
  const liveTag = document.getElementById('mapGpsLiveTag');
  if (liveTag) liveTag.style.display = 'none';

  const gpsStatusEl = document.getElementById('gpsStatus');
  if (gpsStatusEl) gpsStatusEl.textContent = 'Live location is off.';
  toastRider('Live GPS sharing stopped');
}

// 2. True Live Rider GPS (Hardware-driven over HTTPS, with non-blocking manual simulation fallback)
function startLiveLocation(orderId) {
  if (gpsWatchId !== null) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
    if (activeGpsOrderId !== null && activeGpsOrderId !== orderId) {
      updateGpsButtonUI(activeGpsOrderId, false);
    }
  }
  
  activeGpsOrderId = orderId;
  hideGpsNotice();

  // If geolocation service is unsupported
  if (!navigator.geolocation) {
    enableManualSimulation("GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.");
    return;
  }
  
  updateGpsButtonUI(orderId, true, false);
  
  const gpsStatusEl = document.getElementById('gpsStatus');
  if (gpsStatusEl) gpsStatusEl.textContent = 'Acquiring hardware GPS location…';
  toastRider('Connecting to live GPS…');
  
  const options = {
    enableHighAccuracy: true,
    maximumAge: 0,
    timeout: 10000
  };

  try {
    gpsWatchId = navigator.geolocation.watchPosition(
      (position) => {
        // Hardware GPS success: streaming live hardware coordinates over secure HTTPS/localhost
        hideGpsNotice();
        isManualSimulationActive = false;
        if (riderMarker && riderMarker.dragging) {
          riderMarker.dragging.disable();
        }

        const { latitude, longitude, accuracy } = position.coords;
        handleRiderPositionUpdate(latitude, longitude, 'gps', accuracy);
      },
      (error) => {
        // When navigator.geolocation fails or throws "Origin does not have permission to use Geolocation service"
        console.warn('Geolocation error:', error);
        enableManualSimulation("GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.");
      },
      options
    );
  } catch (err) {
    console.warn('watchPosition invocation threw:', err);
    enableManualSimulation("GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.");
  }
}

function toggleLiveLocation(orderId) {
  if ((gpsWatchId !== null || isManualSimulationActive) && activeGpsOrderId === orderId) {
    stopLiveLocation();
  } else {
    startLiveLocation(orderId);
  }
}

// In-app map initialization on load & responsiveness
if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', () => {
    if (activeDeliveryData && document.getElementById('delivery-map')) {
      initDeliveryMap(activeDeliveryData);
    }
  });
} else {
  if (activeDeliveryData && document.getElementById('delivery-map')) {
    initDeliveryMap(activeDeliveryData);
  }
}

window.addEventListener('resize', () => {
  if (deliveryMap) deliveryMap.invalidateSize();
});
window.addEventListener('hashchange', () => {
  setTimeout(() => {
    if (deliveryMap) deliveryMap.invalidateSize();
  }, 200);
});
window.addEventListener('beforeunload', () => stopLiveLocation());
function toastGps(msg){const el=document.getElementById('gpsStatus');if(el)el.textContent=msg;}
</script></body></html>
