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
        $stmt = $conn->prepare("SELECT order_id, customer_id, customer_name, order_ref, status, delivery_status, rider_id, total_amount, payment_method, delivery_address, delivery_lat, delivery_lng, customer_lat, customer_lng FROM food_orders WHERE order_id = :oid LIMIT 1");
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

                    // Sync to orders table
                    try {
                        $conn->prepare("UPDATE orders SET status = 'out_for_delivery', rider_id = :rid WHERE order_id = :oid")->execute([':rid' => $rid, ':oid' => $order_id]);
                    } catch (Throwable $e) {}

                    record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order picked up / confirmed by rider ' . $user_name);
                    create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . ' and is on the way!', $order_id);
                    notify_role($conn, 'admin', 'Rider Dispatched', 'Rider ' . $user_name . ' confirmed pickup and dispatched order ' . $ref, $order_id);

                    // Automated departure & amount notification into chat
                    $bill_amt = number_format((float)($order['total_amount'] ?? 0), 2);
                    $bill_pm = !empty($order['payment_method']) ? trim($order['payment_method']) : 'Cash on Delivery (COD)';
                    $auto_dep_msg = "Hello! I have picked up your order and I'm on the way. Your total bill is ₱{$bill_amt} ({$bill_pm}). Please have the payment or recipient ready!";

                    try {
                        $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'rider', :uid, :rname, :txt, NOW())");
                        $ins_m->execute([
                            ':oid'   => $order_id,
                            ':uid'   => $user_id,
                            ':rname' => $user_name,
                            ':txt'   => $auto_dep_msg
                        ]);
                        if ($customer_id > 0) {
                            create_notification($conn, $customer_id, 'rider_message', 'Message from Rider (' . $user_name . ')', $auto_dep_msg, $order_id);
                        }
                    } catch (Throwable $e) {}

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

                        // Sync to orders table
                        try {
                            $conn->prepare("UPDATE orders SET status = 'out_for_delivery', rider_id = :rid WHERE order_id = :oid")->execute([':rid' => $rid, ':oid' => $order_id]);
                        } catch (Throwable $e) {}

                        record_order_audit_log($conn, $order_id, $old_st, 'OUT_FOR_DELIVERY', $user_id, 'rider', 'Order picked up / confirmed by rider ' . $user_name);
                        create_notification($conn, $customer_id, 'rider_assigned', 'Order Out for Delivery', 'Rider ' . $user_name . ' has picked up your order ' . $ref . '.', $order_id);

                        // Automated departure & amount notification into chat
                        $bill_amt = number_format((float)($order['total_amount'] ?? 0), 2);
                        $bill_pm = !empty($order['payment_method']) ? trim($order['payment_method']) : 'Cash on Delivery (COD)';
                        $auto_dep_msg = "Hello! I have picked up your order and I'm on the way. Your total bill is ₱{$bill_amt} ({$bill_pm}). Please have the payment or recipient ready!";

                        try {
                            $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'rider', :uid, :rname, :txt, NOW())");
                            $ins_m->execute([
                                ':oid'   => $order_id,
                                ':uid'   => $user_id,
                                ':rname' => $user_name,
                                ':txt'   => $auto_dep_msg
                            ]);
                            if ($customer_id > 0) {
                                create_notification($conn, $customer_id, 'rider_message', 'Message from Rider (' . $user_name . ')', $auto_dep_msg, $order_id);
                            }
                        } catch (Throwable $e) {}

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
            o.order_id, o.order_ref, o.customer_id, o.customer_name, 
            COALESCE(o.customer_phone, cu.phone, '+63 917 123 4567') AS customer_phone,
            o.store_name, o.items_summary, o.total_amount, o.payment_method,
            o.status, o.delivery_status, o.rider_id, o.delivery_address, 
            o.delivery_lat, o.delivery_lng, o.customer_lat, o.customer_lng, o.created_at, o.shop_id,
            COALESCE(o.store_lat, s.latitude, s.lat) AS store_lat, 
            COALESCE(o.store_lng, s.longitude, s.lng) AS store_lng,
            s.address AS store_address,
            s.shop_name AS shop_record_name
        FROM food_orders o
        LEFT JOIN shops s ON (o.shop_id = s.shop_id OR (o.shop_id = 0 AND LOWER(TRIM(o.store_name)) = LOWER(TRIM(s.shop_name)) COLLATE utf8mb4_unicode_ci))
        LEFT JOIN users cu ON (o.customer_id = cu.user_id)
        WHERE LOWER(TRIM(o.status)) IN ('ready_for_pickup', 'ready', 'assigned') 
          AND (o.rider_id IS NULL OR o.rider_id = 0) 
        ORDER BY o.order_id DESC LIMIT 30");
    $stmt_ready->execute();
    $ready_orders = $stmt_ready->fetchAll(PDO::FETCH_ASSOC);

    // 2. My Delivery: Joined with shops table for real store coordinates and riders table for true live rider GPS
    $stmt_my = $conn->prepare("SELECT 
            o.order_id, o.order_ref, o.customer_id, o.customer_name, 
            COALESCE(o.customer_phone, cu.phone, '+63 917 123 4567') AS customer_phone,
            o.store_name, o.items_summary, o.total_amount, o.payment_method,
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
        LEFT JOIN users cu ON (o.customer_id = cu.user_id)
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

/* PC Testing Simulator Floating Controls Bar */
.pc-simulator-panel {
  position: fixed;
  bottom: 24px;
  right: 24px;
  width: 320px;
  max-width: calc(100vw - 32px);
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(10px);
  -webkit-backdrop-filter: blur(10px);
  border: 1.5px solid var(--sandstone);
  border-radius: 16px;
  box-shadow: 0 12px 36px rgba(75, 83, 32, 0.22), 0 2px 8px rgba(0,0,0,0.06);
  z-index: 1500;
  font-family: 'Poppins', sans-serif;
  overflow: hidden;
  transition: all 0.25s ease;
}
@media (max-width: 480px) {
  .pc-simulator-panel {
    bottom: 74px;
    right: 14px;
    left: 14px;
    width: auto;
  }
}
.pc-simulator-panel.collapsed .sim-body {
  display: none !important;
}
.sim-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 10px 14px;
  background: #fdfbf7;
  border-bottom: 1px solid var(--line);
  cursor: pointer;
  user-select: none;
}
.sim-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 0.78rem;
  font-weight: 700;
  color: var(--olive-shadow);
}
.sim-title i {
  color: var(--harvest-sun);
  font-size: 0.9rem;
}
.sim-header-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}
.sim-badge {
  font-size: 0.62rem;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 8px;
  background: rgba(216, 143, 42, 0.15);
  color: #b45309;
  letter-spacing: 0.3px;
}
.sim-toggle-btn {
  background: none;
  border: none;
  color: var(--muted);
  cursor: pointer;
  padding: 2px 4px;
  font-size: 0.8rem;
  transition: transform 0.2s;
  display: flex;
  align-items: center;
}
.pc-simulator-panel.collapsed .sim-toggle-btn i {
  transform: rotate(-90deg);
}
.sim-body {
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.sim-desc {
  font-size: 0.68rem;
  color: var(--muted);
  line-height: 1.35;
}
.sim-btn-group {
  display: flex;
  flex-direction: column;
  gap: 7px;
  margin-top: 2px;
}
.sim-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  border: 1px solid var(--sandstone);
  border-radius: 10px;
  padding: 8px 12px;
  font-family: inherit;
  font-size: 0.72rem;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.2s ease;
  background: #ffffff;
  color: var(--olive-shadow);
}
.sim-btn:hover {
  background: #f8f5eb;
  border-color: var(--olive-shadow);
}
.sim-btn-primary {
  background: var(--olive-shadow);
  color: #ffffff;
  border-color: var(--olive-shadow);
}
.sim-btn-primary:hover {
  filter: brightness(1.12);
  background: var(--olive-shadow);
}
.sim-btn.sim-btn-active {
  background: #2e7d32 !important;
  color: #ffffff !important;
  border-color: #2e7d32 !important;
  box-shadow: 0 3px 12px rgba(46, 125, 50, 0.40);
}
.sim-btn.sim-btn-active i {
  animation: fa-beat 1.5s infinite ease-in-out;
}
.sim-status-bar {
  display: flex;
  align-items: center;
  gap: 7px;
  font-size: 0.66rem;
  color: var(--muted);
  background: #fbf9f4;
  padding: 6px 9px;
  border-radius: 8px;
  border: 1px dashed var(--sandstone);
  margin-top: 2px;
  word-break: break-word;
}
.sim-status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: #9ca3af;
  flex-shrink: 0;
}
.sim-status-dot.active {
  background: #16a34a;
  box-shadow: 0 0 6px #16a34a;
}
.sim-status-dot.driving {
  background: #d97706;
  box-shadow: 0 0 6px #d97706;
}

/* Contact Customer Action Card */
.rider-contact-card {
  background: #ffffff;
  border: 1.5px solid var(--sandstone);
  border-radius: 12px;
  padding: 10px 12px;
  margin-top: 10px;
  box-shadow: 0 2px 8px rgba(75, 83, 32, 0.08);
}
.rider-contact-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}
.contact-user-info {
  display: flex;
  align-items: center;
  gap: 8px;
}
.contact-avatar {
  width: 34px;
  height: 34px;
  border-radius: 50%;
  background: rgba(75, 83, 32, 0.12);
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.85rem;
}
.contact-name {
  font-size: 0.76rem;
  font-weight: 700;
  color: var(--olive-shadow);
  line-height: 1.2;
}
.contact-phone {
  font-size: 0.68rem;
  color: var(--muted);
  display: flex;
  align-items: center;
  gap: 4px;
  margin-top: 2px;
}
.contact-badge {
  font-size: 0.62rem;
  font-weight: 600;
  color: var(--harvest-sun);
  background: #fff8e6;
  border: 1px solid #fed7aa;
  padding: 2px 7px;
  border-radius: 12px;
}
.rider-contact-actions {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.contact-action-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  padding: 8px 10px;
  border-radius: 8px;
  font-family: inherit;
  font-size: 0.72rem;
  font-weight: 600;
  text-decoration: none;
  cursor: pointer;
  transition: all 0.2s ease;
  border: none;
}
.call-action-btn {
  background: #2e7d32;
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(46, 125, 50, 0.25);
}
.call-action-btn:hover {
  background: #256628;
}
.msg-action-btn {
  background: var(--harvest-sun);
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(216, 143, 42, 0.25);
}
.msg-action-btn:hover {
  filter: brightness(1.08);
}

/* Chat Drawer / Modal */
.chat-modal-box {
  width: min(400px, 100%);
  background: #ffffff;
  border: 1.5px solid var(--sandstone);
  border-radius: 20px;
  box-shadow: 0 20px 60px rgba(75, 83, 32, 0.25);
  display: flex;
  flex-direction: column;
  max-height: 85vh;
  overflow: hidden;
}
.chat-modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: #fdfbf7;
  border-bottom: 1px solid var(--line);
}
.chat-header-avatar {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  background: rgba(75, 83, 32, 0.15);
  color: var(--olive-shadow);
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.8rem;
}
.chat-close-btn {
  background: transparent;
  border: none;
  font-size: 1rem;
  color: var(--muted);
  cursor: pointer;
  padding: 4px;
}
.quick-status-section {
  padding: 10px 14px 6px;
  background: #faf8f2;
  border-bottom: 1px solid var(--line);
}
.quick-title {
  font-size: 0.66rem;
  font-weight: 700;
  color: var(--olive-shadow);
  margin-bottom: 6px;
  display: flex;
  align-items: center;
  gap: 5px;
}
.quick-pills {
  display: flex;
  gap: 5px;
  overflow-x: auto;
  padding-bottom: 4px;
  scrollbar-width: thin;
}
.quick-pill {
  white-space: nowrap;
  background: #ffffff;
  border: 1px solid var(--sandstone);
  border-radius: 14px;
  padding: 5px 10px;
  font-size: 0.66rem;
  font-weight: 500;
  color: var(--olive-shadow);
  cursor: pointer;
  transition: all 0.15s ease;
  flex-shrink: 0;
}
.quick-pill:hover {
  background: var(--harvest-sun);
  color: #ffffff;
  border-color: var(--harvest-sun);
}
.chat-messages-container {
  padding: 12px 14px;
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  gap: 8px;
  min-height: 180px;
  max-height: 280px;
  background: #fffdf9;
}
.chat-empty-hint {
  font-size: 0.7rem;
  color: var(--muted);
  text-align: center;
  margin: auto;
  padding: 18px 10px;
}
.msg-bubble {
  max-width: 82%;
  padding: 7px 11px;
  border-radius: 12px;
  font-size: 0.72rem;
  line-height: 1.4;
  word-break: break-word;
}
.msg-bubble.me {
  align-self: flex-end;
  background: var(--olive-shadow);
  color: #ffffff;
  border-bottom-right-radius: 2px;
}
.msg-bubble.them {
  align-self: flex-start;
  background: #f1ebd8;
  color: var(--ink);
  border-bottom-left-radius: 2px;
}
.msg-meta {
  font-size: 0.58rem;
  margin-top: 3px;
  opacity: 0.8;
  text-align: right;
}
.msg-bubble.them .msg-meta {
  text-align: left;
}
.chat-input-bar {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 10px 14px;
  background: #ffffff;
  border-top: 1px solid var(--line);
}
.chat-input-bar input {
  flex: 1;
  border: 1.5px solid var(--sandstone);
  border-radius: 20px;
  padding: 8px 12px;
  font-size: 0.72rem;
  outline: none;
}
.chat-input-bar input:focus {
  border-color: var(--olive-shadow);
}
.chat-send-btn {
  background: var(--olive-shadow);
  color: #ffffff;
  border: none;
  border-radius: 50%;
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  flex-shrink: 0;
  transition: transform 0.15s ease;
}
.chat-send-btn:hover {
  transform: scale(1.08);
}
.chat-sms-btn {
  background: #15803d;
  color: #ffffff;
  border-radius: 16px;
  padding: 6px 10px;
  font-size: 0.68rem;
  font-weight: 700;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
  box-shadow: 0 2px 6px rgba(21, 128, 61, 0.25);
}
/* Outgoing Call Modal */
.rider-call-box {
  text-align: center;
  padding: 24px 20px;
  background: #ffffff;
  border-radius: 18px;
  max-width: 360px;
  width: 90%;
  margin: auto;
  box-shadow: 0 16px 40px rgba(0,0,0,0.22);
}
.call-avatar-pulse {
  position: relative;
  width: 72px;
  height: 72px;
  margin: 0 auto 14px;
}
.call-pulse-circle {
  position: absolute;
  inset: -8px;
  border-radius: 50%;
  background: rgba(46, 125, 50, 0.2);
  animation: callPulse 1.8s infinite;
}
@keyframes callPulse {
  0% { transform: scale(0.9); opacity: 0.8; }
  50% { transform: scale(1.25); opacity: 0.3; }
  100% { transform: scale(0.9); opacity: 0.8; }
}
.call-avatar-icon {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: #16a34a;
  color: #ffffff;
  font-size: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  box-shadow: 0 6px 18px rgba(22, 163, 74, 0.45);
}
.call-badge-live {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #dcfce7;
  color: #166534;
  font-size: 0.72rem;
  font-weight: 800;
  padding: 4px 10px;
  border-radius: 20px;
  margin-bottom: 8px;
  text-transform: uppercase;
  letter-spacing: 0.5px;
}
.modal-call-phone {
  font-size: 1.15rem;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 6px;
}
.modal-call-status {
  font-size: 0.82rem;
  color: #64748b;
  line-height: 1.45;
  margin-bottom: 16px;
}
.modal-call-timer {
  font-size: 1.4rem;
  font-weight: 800;
  color: #16a34a;
  font-variant-numeric: tabular-nums;
  margin-bottom: 14px;
}
.call-modal-buttons {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.call-sim-btn {
  background: #f8fafc;
  color: #0f172a;
  border: 1.5px solid #cbd5e1;
  font-weight: 600;
  padding: 10px;
  border-radius: 8px;
  cursor: pointer;
}
.call-sim-btn:hover { background: #f1f5f9; }
.call-native-btn {
  background: #2563eb;
  color: #ffffff;
  text-decoration: none;
  font-weight: 600;
  padding: 10px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
}
.call-native-btn:hover { background: #1d4ed8; }
.call-end-btn {
  background: #dc2626 !important;
  color: #ffffff !important;
  font-weight: 700;
  padding: 10px;
  border-radius: 8px;
  border: none;
  cursor: pointer;
}
.call-end-btn:hover { background: #b91c1c !important; }
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
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?><br>Status: <b><?=htmlspecialchars(ucwords(str_replace('_',' ',$ord_st)))?></b> (<?=htmlspecialchars(ucwords(str_replace('_',' ',$del_st)))?>)</div><div style="margin:5px 0 8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="rider-payment-badge" style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 9px;border-radius:6px;font-size:0.75rem;font-weight:700;"><i class="fa-solid fa-wallet"></i> <?=htmlspecialchars(!empty($o['payment_method']) ? $o['payment_method'] : 'Cash on Delivery (COD)')?></span><span style="font-weight:800;color:#0f172a;font-size:0.82rem;">₱<?=number_format((float)$o['total_amount'],2)?></span></div><div class="rider-address-card"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Customer Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No street address provided (using coordinates / default)')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><?php
  $c_phone_val = !empty($o['customer_phone']) ? trim($o['customer_phone']) : '+63 917 123 4567';
  $c_dial = preg_replace('/[^\+0-9]/', '', $c_phone_val);
  if (empty($c_dial)) $c_dial = '+639171234567';
  $c_name_val = !empty($o['customer_name']) ? trim($o['customer_name']) : 'Customer';
?><div class="rider-contact-card"><div class="rider-contact-header"><div class="contact-user-info"><div class="contact-avatar"><i class="fa-solid fa-user"></i></div><div class="contact-details"><div class="contact-name"><?=htmlspecialchars($c_name_val)?></div><div class="contact-phone"><i class="fa-solid fa-phone-volume"></i> <?=htmlspecialchars($c_phone_val)?></div></div></div><span class="contact-badge"><i class="fa-solid fa-circle-user"></i> Customer</span></div><div class="rider-contact-actions"><button type="button" class="contact-action-btn call-action-btn" onclick="initiateCustomerCall(<?=(int)$o['order_id']?>, '<?=htmlspecialchars(addslashes($c_name_val))?>', '<?=htmlspecialchars(addslashes($c_phone_val))?>')" title="Call customer directly or test in-app incoming call"><i class="fa-solid fa-phone"></i> Call Customer</button><button type="button" class="contact-action-btn msg-action-btn" onclick="openOrderChat(<?=(int)$o['order_id']?>, '<?=htmlspecialchars(addslashes($c_name_val))?>', '<?=htmlspecialchars(addslashes($c_dial))?>')"><i class="fa-solid fa-comment-dots"></i> Message / Chat</button></div></div><div id="delivery-map" style="height: 320px; width: 100%; border-radius: 12px; margin-top: 12px;"></div><div class="map-status-bar"><span><i class="fa-solid fa-route" style="color:var(--harvest-sun)"></i> <b id="mapRouteDistance">Calculating route...</b></span><span id="mapGpsLiveTag" style="font-weight:600; color:#2e7d32; display:none;"><i class="fa-solid fa-circle-dot fa-beat-fade"></i> Live GPS Tracking</span></div><div id="gpsNoticeBanner" style="display:none;margin-top:8px;padding:9px 12px;background:#fff8e6;border:1.5px solid #d97706;border-radius:10px;font-size:0.73rem;color:#78350f;line-height:1.45;"><div style="display:flex;align-items:flex-start;gap:8px;"><i class="fa-solid fa-triangle-exclamation" style="color:#d97706;font-size:0.9rem;margin-top:2px;"></i><div style="flex:1;" id="gpsNoticeText">GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.</div></div></div><div class="actions" style="display:flex;flex-direction:column;gap:8px;margin-top:10px;"><?php if($is_ready_pickup): ?><button type="button" class="btn warn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'pickup')"><i class="fa-solid fa-box-open"></i> Pick Up / Confirm Order</button><?php else: ?><button type="button" class="btn secondary full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'pickup')" title="Order is picked up and on delivery"><i class="fa-solid fa-check"></i> Order Picked Up (On Delivery)</button><?php if($del_st === 'on_the_way'): ?><button type="button" class="btn warn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'near')"><i class="fa-solid fa-location-dot"></i> I'm Near Customer</button><?php endif; ?><?php endif; ?><button type="button" id="gpsToggleBtn_<?=$o['order_id']?>" class="btn secondary full gps-toggle-btn" onclick="toggleLiveLocation(<?=$o['order_id']?>)"><i class="fa-solid fa-satellite-dish"></i> <span id="gpsToggleText_<?=$o['order_id']?>">Share Live Location / GPS</span></button><button type="button" class="btn success full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'delivered')"><i class="fa-solid fa-circle-check"></i> Complete Delivery</button></div></div><?php endforeach; else: ?><div class="empty">No active delivery yet. Accept a ready order below.</div><?php endif; ?></div></section>
<section class="section" id="available"><div class="section-title"><h2>Orders Ready for Pickup</h2></div><div class="card"><?php if($ready_orders): foreach($ready_orders as $o): 
  $addr = trim((string)($o['delivery_address'] ?? ''));
  $lat = (isset($o['delivery_lat']) && is_numeric($o['delivery_lat'])) ? $o['delivery_lat'] : ($o['customer_lat'] ?? null);
  $lng = (isset($o['delivery_lng']) && is_numeric($o['delivery_lng'])) ? $o['delivery_lng'] : ($o['customer_lng'] ?? null);
  $has_coords = ($lat !== null && $lng !== null && is_numeric($lat) && is_numeric($lng) && (float)$lat != 0 && (float)$lng != 0);
?><div class="order"><div class="order-ref"><?=htmlspecialchars($o['order_ref'])?></div><div class="meta"><b><?=htmlspecialchars($o['store_name'])?></b> • <?=htmlspecialchars($o['customer_name'])?><br><?=htmlspecialchars($o['items_summary'])?></div><div style="margin:5px 0 8px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;"><span class="rider-payment-badge" style="display:inline-flex;align-items:center;gap:5px;background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;padding:3px 9px;border-radius:6px;font-size:0.75rem;font-weight:700;"><i class="fa-solid fa-wallet"></i> <?=htmlspecialchars(!empty($o['payment_method']) ? $o['payment_method'] : 'Cash on Delivery (COD)')?></span><span style="font-weight:800;color:#0f172a;font-size:0.82rem;">₱<?=number_format((float)$o['total_amount'],2)?></span></div><div class="rider-address-card" style="margin:8px 0 10px;"><div class="rider-address-title"><i class="fa-solid fa-location-dot"></i> Delivery Address</div><div class="rider-address-text"><?=htmlspecialchars(!empty($addr) ? $addr : 'No delivery address provided')?></div><?php if($has_coords): ?><div class="rider-gps-pill"><i class="fa-solid fa-crosshairs"></i> GPS: <?=htmlspecialchars(number_format((float)$lat, 5))?>, <?=htmlspecialchars(number_format((float)$lng, 5))?></div><?php endif; ?></div><div style="margin-top:8px;"><button type="button" class="btn full" onclick="doDeliveryAction(<?=$o['order_id']?>, 'claim')"><i class="fa-solid fa-motorcycle"></i> Accept / Pick Up Order</button></div></div><?php endforeach; else: ?><div class="empty">No unassigned ready orders.</div><?php endif; ?></div></section>
<section class="section" id="live"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">📡 Live Location</h2><div id="gpsStatus" class="meta">Live location is off. Tap Share Live Location after starting a delivery.</div><button type="button" class="btn full" style="margin-top:10px" onclick="stopLiveLocation()">Stop Sharing</button></div></section><section class="section" id="guide"><div class="card"><h2 style="font-size:1rem;margin:0 0 8px">Customer Notification Flow</h2><div class="meta">When you accept an order, the customer is notified. When you start the trip, they receive an <b>On the Way</b> notification. When you tap <b>I'm Near Customer</b>, they receive the near-arrival notification. When delivered, the customer receives a final delivery notice.</div></div></section></main><nav class="bottom"><a class="active" href="#delivery"><i class="fa-solid fa-box"></i>Delivery</a><a href="#available"><i class="fa-solid fa-motorcycle"></i>Pickup</a><a href="#live"><i class="fa-solid fa-location-crosshairs"></i>Live</a><a href="#guide"><i class="fa-solid fa-circle-info"></i>Guide</a><a href="#" onclick="openLogout();return false"><i class="fa-solid fa-right-from-bracket"></i>Logout</a></nav></div>
<div class="modal" id="noteModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Notifications</h3><div id="notificationList" class="notification-list"><div class="empty">Loading...</div></div><div class="box-actions"><button class="close" onclick="markAllRead()">Mark all read</button><button class="close" onclick="closeNotifications()">Close</button></div></div></div>
<div class="modal" id="logoutModal"><div class="box"><img src="logo.jpg" alt="Food Dash"><h3>Log out of Food Dash?</h3><p>Your current session will be signed out.</p><div class="box-actions"><button class="close" onclick="closeLogout()">Cancel</button><a class="btn" style="display:flex;align-items:center;justify-content:center;text-decoration:none" href="logout.php?confirm=1">Yes, Log Out</a></div></div></div>
<!-- Rider Chat / Quick Message Modal -->
<div class="modal" id="riderChatModal"><div class="chat-modal-box"><div class="chat-modal-header"><div style="display:flex;align-items:center;gap:8px;"><div class="chat-header-avatar"><i class="fa-solid fa-user"></i></div><div><h4 id="chatCustomerName" style="margin:0;font-size:13px;color:var(--olive-shadow);">Customer</h4><small id="chatCustomerPhone" style="font-size:10px;color:var(--muted);">+63 ...</small></div></div><button class="chat-close-btn" onclick="closeOrderChat()"><i class="fa-solid fa-xmark"></i></button></div><div class="quick-status-section"><div class="quick-title"><i class="fa-solid fa-bolt"></i> Quick Delivery Updates:</div><div class="quick-pills"><button type="button" class="quick-pill" onclick="sendQuickUpdate('I\'ve arrived outside with your order! 🛵')">🛵 I've arrived outside</button><button type="button" class="quick-pill" onclick="sendQuickUpdate('Traffic delay, on my way! 🚦')">🚦 Traffic delay, on my way</button><button type="button" class="quick-pill" onclick="sendQuickUpdate('Food picked up! Heading to your address now 🍱')">🍱 Heading to your address</button><button type="button" class="quick-pill" onclick="sendQuickUpdate('Please be ready at the gate/lobby 🚪')">🚪 Please be ready at gate</button></div></div><div id="chatMessagesList" class="chat-messages-container"><div class="chat-empty-hint">Loading conversation...</div></div><div class="chat-input-bar"><input type="text" id="chatInputText" placeholder="Type delivery message..." oninput="updateChatSmsLink(this.value)" onkeydown="if(event.key==='Enter') sendChatMessage()"><button type="button" class="chat-send-btn" onclick="sendChatMessage()" title="Send in App"><i class="fa-solid fa-paper-plane"></i></button><a href="#" id="chatSmsLink" class="chat-sms-btn" target="_blank" title="Send via native SMS"><i class="fa-solid fa-comment-dots"></i> SMS</a></div></div></div>
<!-- Desktop / Simulator Outgoing Call Modal -->
<div class="modal" id="riderCallModal" onclick="if(event.target===this)endRiderCall()"><div class="box rider-call-box"><div class="call-avatar-pulse"><div class="call-pulse-circle"></div><div class="call-avatar-icon"><i class="fa-solid fa-phone-volume"></i></div></div><div class="call-badge-live"><i class="fa-solid fa-circle-dot fa-beat-fade"></i> IN-APP CALL ACTIVE</div><h3 id="modalCallCustName" style="margin:0 0 4px;font-size:1.25rem;">Customer</h3><div class="modal-call-phone" id="modalCallCustPhone">+63 917 123 4567</div><p id="modalCallStatusText" class="modal-call-status">Ringing customer's device in real-time... (Incoming call banner displayed on customer map)</p><div class="modal-call-timer" id="modalCallTimer" style="display:none;">00:00</div><div class="call-modal-buttons"><button type="button" class="btn call-sim-btn" id="btnSimulateConnect" onclick="simulateCallConnect()"><i class="fa-solid fa-headset"></i> Test Connected Call</button><a href="#" id="modalCallNativeTelBtn" class="btn call-native-btn" title="Open native phone dialer"><i class="fa-solid fa-mobile-screen-button"></i> Open Native Dialer</a><button type="button" class="btn call-end-btn" onclick="endRiderCall()"><i class="fa-solid fa-phone-slash"></i> End Call</button></div></div></div>
<!-- PC Testing Simulator Floating Controls Bar -->
<div id="pcSimulatorPanel" class="pc-simulator-panel" style="display:none;">
  <div class="sim-header" onclick="toggleSimulatorCollapse()">
    <div class="sim-title">
      <i class="fa-solid fa-gamepad"></i>
      <span>PC Testing Simulator</span>
    </div>
    <div class="sim-header-actions">
      <span class="sim-badge">Dev / PC</span>
      <button type="button" class="sim-toggle-btn" aria-label="Toggle simulator collapse">
        <i class="fa-solid fa-chevron-down"></i>
      </button>
    </div>
  </div>
  <div class="sim-body" id="simBody">
    <div class="sim-desc">Simulate live delivery without GPS hardware. Actions sync to backend in real time.</div>
    <div class="sim-btn-group">
      <button type="button" id="btnAutoDrive" class="sim-btn sim-btn-primary" onclick="toggleAutoDriveSimulation()">
        <i class="fa-solid fa-play"></i> <span id="autoDriveLabel">Start Auto-Drive Simulation</span>
      </button>
      <button type="button" id="btnTapRelocate" class="sim-btn" onclick="toggleMapTapRelocate()">
        <i class="fa-solid fa-location-crosshairs"></i> <span id="tapRelocateLabel">Tap Map to Relocate</span>
      </button>
      <button type="button" id="btnResetStore" class="sim-btn" onclick="resetRiderToStore()">
        <i class="fa-solid fa-rotate-left"></i> <span>Reset to Store</span>
      </button>
    </div>
    <div class="sim-status-bar">
      <span class="sim-status-dot" id="simStatusDot"></span>
      <span id="simStatusText">Simulator ready</span>
    </div>
  </div>
</div>
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
let autoDriveTimer = null;
let autoDriveWaypoints = [];
let autoDriveIndex = 0;
const AUTO_DRIVE_INTERVAL_MS = 250; // Smooth 250ms interval along OSRM street network (200-300ms)
let isTapRelocateActive = false;

// PC Testing Simulator Controls
function shouldShowSimulator() {
  const host = (location.hostname || '').toLowerCase();
  const isLocal = ['localhost', '127.0.0.1', '::1'].includes(host) || host.endsWith('.local');
  const isTunnelOrDev = isLocal || host.endsWith('.trycloudflare.com') || host.includes('tunnel');
  const isQueryForced = location.search.includes('sim=1') || location.search.includes('dev=1');
  const noTouchHardware = !('ontouchstart' in window) || (navigator.maxTouchPoints === 0);
  const noGpsHardware = !navigator.geolocation;
  return isTunnelOrDev || isQueryForced || noTouchHardware || noGpsHardware;
}

function initSimulator() {
  const panel = document.getElementById('pcSimulatorPanel');
  if (!panel) return;
  if (shouldShowSimulator()) {
    panel.style.display = 'block';
    if (!activeDeliveryData) {
      updateSimStatus('No active delivery order claimed', 'ready');
    } else {
      updateSimStatus('PC Simulator ready (OSRM Road Routing)', 'ready');
    }
  }
}

function toggleSimulatorCollapse() {
  const panel = document.getElementById('pcSimulatorPanel');
  if (panel) panel.classList.toggle('collapsed');
}

function updateSimStatus(text, type = 'ready') {
  const txtEl = document.getElementById('simStatusText');
  const dot = document.getElementById('simStatusDot');
  if (txtEl) txtEl.textContent = text;
  if (dot) {
    dot.className = 'sim-status-dot';
    if (type === 'driving') dot.classList.add('driving');
    else if (type === 'active') dot.classList.add('active');
  }
}

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

// Calculate sum of road segments along a waypoints polyline (in meters)
function calculatePathDistanceMeters(pts) {
  if (!pts || pts.length < 2) return 0;
  let totalKm = 0;
  for (let i = 0; i < pts.length - 1; i++) {
    totalKm += calculateHaversineDistanceKm(pts[i][0], pts[i][1], pts[i + 1][0], pts[i + 1][1]);
  }
  return totalKm * 1000;
}

function updateRoadDistanceDisplay(distMeters) {
  const distEl = document.getElementById('mapRouteDistance');
  if (distEl) {
    if (distMeters === null || isNaN(distMeters)) {
      distEl.textContent = 'Distance: Calculating...';
    } else if (distMeters < 1000) {
      distEl.textContent = `Road Distance: ${Math.round(distMeters)} meters`;
    } else {
      distEl.textContent = `Road Distance: ${(distMeters / 1000).toFixed(2)} km`;
    }
  }
}

// Fallback street corridor generator if OSRM is unreachable
function generateFallbackStreetCorridor(startLat, startLng, destLat, destLng) {
  const midLat = startLat + (destLat - startLat) * 0.5;
  const midLng = startLng + (destLng - startLng) * 0.5;
  return [
    [startLat, startLng],
    [startLat, midLng],
    [destLat, midLng],
    [destLat, destLng]
  ];
}

// Densify street road coordinates into smooth 6-10 meter steps for realistic auto-drive animation
function densifyRoadCoordinates(coords, maxStepMeters = 8) {
  if (!coords || coords.length === 0) return [];
  if (coords.length === 1) return [coords[0], coords[0]];

  const densified = [];
  for (let i = 0; i < coords.length - 1; i++) {
    const p1 = coords[i];
    const p2 = coords[i + 1];
    densified.push(p1);

    const distMeters = calculateHaversineDistanceKm(p1[0], p1[1], p2[0], p2[1]) * 1000;
    if (distMeters > maxStepMeters) {
      const steps = Math.ceil(distMeters / maxStepMeters);
      for (let s = 1; s < steps; s++) {
        const fraction = s / steps;
        densified.push([
          p1[0] + (p2[0] - p1[0]) * fraction,
          p1[1] + (p2[1] - p1[1]) * fraction
        ]);
      }
    }
  }
  densified.push(coords[coords.length - 1]);
  return densified;
}

// 1. Real-Street Road Routing via free public OSRM Driving Routing API
async function fetchOsrmDrivingRoute(startLat, startLng, destLat, destLng) {
  const osrmUrl = `https://router.project-osrm.org/route/v1/driving/${startLng},${startLat};${destLng},${destLat}?overview=full&geometries=geojson`;

  try {
    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 6000);

    const response = await fetch(osrmUrl, {
      signal: controller.signal,
      headers: { 'Accept': 'application/json' }
    });
    clearTimeout(timeoutId);

    if (response.ok) {
      const data = await response.json();
      if (data && data.code === 'Ok' && data.routes && data.routes.length > 0) {
        const route = data.routes[0];
        if (route.geometry && Array.isArray(route.geometry.coordinates) && route.geometry.coordinates.length > 0) {
          // OSRM returns [longitude, latitude], convert to Leaflet [latitude, longitude]
          const leafletCoords = route.geometry.coordinates.map(pt => [pt[1], pt[0]]);
          const distanceMeters = (typeof route.distance === 'number') ? route.distance : calculatePathDistanceMeters(leafletCoords);
          return {
            ok: true,
            coordinates: leafletCoords,
            distanceMeters: distanceMeters,
            source: 'osrm'
          };
        }
      }
    }
  } catch (err) {
    console.warn('OSRM routing fetch failed or timed out, using street corridor fallback:', err);
  }

  // Fallback: Generate intermediate street corridor waypoints
  const fallbackCorridor = generateFallbackStreetCorridor(startLat, startLng, destLat, destLng);
  return {
    ok: false,
    coordinates: fallbackCorridor,
    distanceMeters: calculatePathDistanceMeters(fallbackCorridor),
    source: 'fallback'
  };
}

// Load and draw visual road polyline on map using OSRM geometry
async function loadAndDrawRoadRoute(startLat, startLng, destLat, destLng, fitBounds = false) {
  if (!deliveryMap) return null;

  const result = await fetchOsrmDrivingRoute(startLat, startLng, destLat, destLng);
  const roadCoords = result.coordinates;
  const distMeters = result.distanceMeters;

  if (!routePolyline) {
    routePolyline = L.polyline(roadCoords, {
      color: '#D88F2A',
      weight: 5,
      opacity: 0.9,
      lineCap: 'round',
      lineJoin: 'round'
    }).addTo(deliveryMap);
  } else {
    routePolyline.setLatLngs(roadCoords);
  }

  updateRoadDistanceDisplay(distMeters);

  if (fitBounds && roadCoords.length > 0) {
    const bounds = L.latLngBounds(roadCoords);
    deliveryMap.fitBounds(bounds, { padding: [40, 40], maxZoom: 16 });
  }

  return result;
}

function toggleAutoDriveSimulation() {
  if (autoDriveTimer !== null) {
    stopAutoDriveSimulation();
  } else {
    startAutoDriveSimulation();
  }
}

function stopAutoDriveSimulation() {
  if (autoDriveTimer !== null) {
    clearInterval(autoDriveTimer);
    autoDriveTimer = null;
  }
  const btn = document.getElementById('btnAutoDrive');
  if (btn) {
    btn.classList.remove('sim-btn-active');
    btn.innerHTML = '<i class="fa-solid fa-play"></i> <span id="autoDriveLabel">Start Auto-Drive Simulation</span>';
  }
  updateSimStatus('Auto-drive stopped', 'ready');
}

// 2. Snap Auto-Drive Simulation to Street Geometry
async function startAutoDriveSimulation() {
  if (!activeDeliveryData) {
    toastRider('No active delivery order to simulate.', true);
    return;
  }

  const custCoord = getActiveCustomerCoord();
  if (!custCoord) {
    toastRider('Customer delivery coordinates not available.', true);
    return;
  }

  // Deactivate hardware GPS watch if active so simulator doesn't conflict
  if (gpsWatchId !== null) {
    navigator.geolocation.clearWatch(gpsWatchId);
    gpsWatchId = null;
  }

  // Turn off tap relocate mode if active
  if (isTapRelocateActive) {
    toggleMapTapRelocate();
  }

  // Retrieve dynamic store pickup coordinates
  let sLat = (activeDeliveryData.store_lat !== null && !isNaN(activeDeliveryData.store_lat)) ? Number(activeDeliveryData.store_lat) : (storeMarker ? storeMarker.getLatLng().lat : null);
  let sLng = (activeDeliveryData.store_lng !== null && !isNaN(activeDeliveryData.store_lng)) ? Number(activeDeliveryData.store_lng) : (storeMarker ? storeMarker.getLatLng().lng : null);

  if (sLat === null || sLng === null || isNaN(sLat) || isNaN(sLng) || sLat === 0) {
    sLat = custCoord[0] - 0.005;
    sLng = custCoord[1] - 0.005;
  }

  // Determine starting point:
  // If riderMarker is already placed and not immediately at the customer destination (> 40m), start from rider position;
  // otherwise start from store pickup.
  let startLat = sLat;
  let startLng = sLng;

  const destLat = custCoord[0];
  const destLng = custCoord[1];

  if (riderMarker) {
    const cur = riderMarker.getLatLng();
    const distToCust = calculateHaversineDistanceKm(cur.lat, cur.lng, destLat, destLng);
    if (distToCust > 0.04) {
      startLat = cur.lat;
      startLng = cur.lng;
    }
  }

  const btn = document.getElementById('btnAutoDrive');
  if (btn) {
    btn.classList.add('sim-btn-active');
    btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> <span id="autoDriveLabel">Routing Street...</span>';
  }

  updateSimStatus('Fetching OSRM street route...', 'driving');
  toastRider('Fetching actual road network via OSRM...');

  // 1. Fetch real-street road route via OSRM (or fallback)
  const routeResult = await fetchOsrmDrivingRoute(startLat, startLng, destLat, destLng);
  
  // 2. Densify street geometry into smooth road waypoints so marker stays along street corridors
  autoDriveWaypoints = densifyRoadCoordinates(routeResult.coordinates, 8);
  if (autoDriveWaypoints.length < 2) {
    autoDriveWaypoints = [[startLat, startLng], [destLat, destLng]];
  }

  autoDriveIndex = 0;
  const totalWaypoints = autoDriveWaypoints.length;

  if (btn) {
    btn.innerHTML = '<i class="fa-solid fa-pause"></i> <span id="autoDriveLabel">Pause Auto-Drive</span>';
  }

  const routeLabel = routeResult.source === 'osrm' ? 'OSRM Road' : 'Street Corridor';
  updateSimStatus(`Auto-driving via ${routeLabel} (0/${totalWaypoints - 1})...`, 'driving');
  toastRider(`Auto-drive started via ${routeLabel} (${totalWaypoints} road waypoints)!`);

  // Initial step at waypoint 0
  const [initLat, initLng] = autoDriveWaypoints[0];
  handleRiderPositionUpdate(initLat, initLng, 'simulation');
  if (routePolyline) {
    routePolyline.setLatLngs(autoDriveWaypoints);
  }
  const initRemainingDist = calculatePathDistanceMeters(autoDriveWaypoints);
  updateRoadDistanceDisplay(initRemainingDist);

  // Sequential advance along OSRM street waypoints at smooth 250ms interval (200-300ms)
  autoDriveTimer = setInterval(() => {
    autoDriveIndex++;
    if (autoDriveIndex >= autoDriveWaypoints.length) {
      stopAutoDriveSimulation();
      // Snap marker cleanly to customer destination
      handleRiderPositionUpdate(destLat, destLng, 'simulation');
      if (routePolyline) {
        routePolyline.setLatLngs([[destLat, destLng], [destLat, destLng]]);
      }
      updateRoadDistanceDisplay(0);
      updateSimStatus('Arrived at customer destination! (100%)', 'active');
      toastRider('Auto-drive completed: Rider arrived at customer!');
      return;
    }

    const [currLat, currLng] = autoDriveWaypoints[autoDriveIndex];

    // Post to tracking_api.php and update marker popup & badge
    handleRiderPositionUpdate(currLat, currLng, 'simulation');

    // Dynamically shorten remaining route polyline ahead of the rider
    const remainingWaypoints = autoDriveWaypoints.slice(autoDriveIndex);
    if (routePolyline) {
      routePolyline.setLatLngs(remainingWaypoints);
    }

    // Calculate remaining road distance
    const remainingDist = calculatePathDistanceMeters(remainingWaypoints);
    updateRoadDistanceDisplay(remainingDist);

    const percent = Math.round((autoDriveIndex / (totalWaypoints - 1)) * 100);
    updateSimStatus(`Auto-driving (${routeLabel}): ${percent}% (${autoDriveIndex}/${totalWaypoints - 1})`, 'driving');

    if (deliveryMap) {
      deliveryMap.panTo([currLat, currLng], { animate: true, duration: 0.2 });
    }
  }, AUTO_DRIVE_INTERVAL_MS);
}

function toggleMapTapRelocate() {
  isTapRelocateActive = !isTapRelocateActive;
  const btn = document.getElementById('btnTapRelocate');
  const lbl = document.getElementById('tapRelocateLabel');

  if (isTapRelocateActive) {
    if (autoDriveTimer !== null) {
      stopAutoDriveSimulation();
    }
    if (btn) btn.classList.add('sim-btn-active');
    if (lbl) lbl.textContent = 'Tap Mode Active (Click Map)';
    if (deliveryMap && deliveryMap.getContainer()) {
      deliveryMap.getContainer().style.cursor = 'crosshair';
    }
    if (riderMarker && riderMarker.dragging) {
      riderMarker.dragging.enable();
    }
    updateSimStatus('Click anywhere on map to relocate rider', 'active');
    toastRider('Tap mode active: Click anywhere on map to drop rider pin.');
  } else {
    if (btn) btn.classList.remove('sim-btn-active');
    if (lbl) lbl.textContent = 'Tap Map to Relocate';
    if (deliveryMap && deliveryMap.getContainer()) {
      deliveryMap.getContainer().style.cursor = '';
    }
    updateSimStatus('Tap mode deactivated', 'ready');
  }
}

async function onMapTapRelocateHandler(e) {
  if (!isTapRelocateActive && !isManualSimulationActive) return;
  const { lat, lng } = e.latlng;
  if (autoDriveTimer !== null) {
    stopAutoDriveSimulation();
  }
  handleRiderPositionUpdate(lat, lng, isTapRelocateActive ? 'simulation' : 'manual');
  updateSimStatus(`Relocated to ${lat.toFixed(5)}, ${lng.toFixed(5)}`, 'active');
  toastRider(`Rider pin relocated (${lat.toFixed(4)}, ${lng.toFixed(4)})`);

  const custCoord = getActiveCustomerCoord();
  if (custCoord) {
    await loadAndDrawRoadRoute(lat, lng, custCoord[0], custCoord[1], false);
  }
}

async function resetRiderToStore() {
  stopAutoDriveSimulation();
  if (!activeDeliveryData) {
    toastRider('No active delivery order to reset.', true);
    return;
  }

  const sLat = (activeDeliveryData.store_lat !== null && !isNaN(activeDeliveryData.store_lat)) ? Number(activeDeliveryData.store_lat) : (storeMarker ? storeMarker.getLatLng().lat : null);
  const sLng = (activeDeliveryData.store_lng !== null && !isNaN(activeDeliveryData.store_lng)) ? Number(activeDeliveryData.store_lng) : (storeMarker ? storeMarker.getLatLng().lng : null);

  if (sLat === null || sLng === null || isNaN(sLat) || isNaN(sLng) || sLat === 0) {
    toastRider('Store pickup coordinates not available.', true);
    return;
  }

  handleRiderPositionUpdate(sLat, sLng, 'reset');

  if (deliveryMap) {
    deliveryMap.panTo([sLat, sLng], { animate: true, duration: 0.6 });
  }

  const custCoord = getActiveCustomerCoord();
  if (custCoord) {
    await loadAndDrawRoadRoute(sLat, sLng, custCoord[0], custCoord[1], false);
  }

  const storeName = activeDeliveryData.store_name || 'Store';
  updateSimStatus(`Reset to ${storeName}`, 'active');
  toastRider(`Rider reset to store (${storeName})`);
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

  riderMarker.on('dragend', async (e) => {
    const { lat, lng } = e.target.getLatLng();
    if (autoDriveTimer !== null) {
      stopAutoDriveSimulation();
    }
    handleRiderPositionUpdate(lat, lng, 'manual');
    toastRider(`Pin moved (${lat.toFixed(4)}, ${lng.toFixed(4)})`);
    const custCoord = getActiveCustomerCoord();
    if (custCoord) {
      await loadAndDrawRoadRoute(lat, lng, custCoord[0], custCoord[1], false);
    }
  });
}

function enableManualSimulation(noticeText) {
  isManualSimulationActive = true;
  
  const defaultNotice = "GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.";
  const msg = noticeText || defaultNotice;
  
  showGpsNotice(msg);
  toastRider(msg);

  const panel = document.getElementById('pcSimulatorPanel');
  if (panel) panel.style.display = 'block';

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
    deliveryMap.on('click', onMapTapRelocateHandler);
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
      riderMarker = L.marker([numLat, numLng], { icon: greenRiderIcon, draggable: (isManualSimulationActive || isTapRelocateActive) }).addTo(deliveryMap);
      attachRiderMarkerDragHandlers();
    }
  } else {
    riderMarker.setIcon(greenRiderIcon);
    riderMarker.setLatLng([numLat, numLng]);
  }

  if (riderMarker) {
    const accuracyText = accuracy ? `<br><small style="color:#666">Accuracy: ±${Math.round(accuracy)}m</small>` : '';
    let modeText = 'Live Hardware GPS';
    if (source === 'simulation') modeText = 'PC Auto-Drive Simulation';
    else if (source === 'manual') modeText = 'Manual Map Tap / Drag';
    else if (source === 'reset') modeText = 'Reset to Store Origin';

    riderMarker.bindPopup(`
      <b><i class="fa-solid fa-motorcycle" style="color:#2e7d32"></i> Rider (${modeText})</b><br>
      <span>Lat: ${numLat.toFixed(5)}, Lng: ${numLng.toFixed(5)}</span>${accuracyText}
    `);
  }

  // If NOT in auto-drive simulation (e.g. live GPS fix), update the road polyline via OSRM
  if (source !== 'simulation') {
    const custCoord = getActiveCustomerCoord();
    if (custCoord && deliveryMap) {
      loadAndDrawRoadRoute(numLat, numLng, custCoord[0], custCoord[1], false);
    }
  }

  // Live status badge & tags
  const liveTag = document.getElementById('mapGpsLiveTag');
  if (liveTag) {
    liveTag.style.display = 'inline-flex';
    if (source === 'simulation' || source === 'manual' || source === 'reset') {
      liveTag.innerHTML = '<i class="fa-solid fa-gamepad"></i> PC Simulator Active';
    } else {
      liveTag.innerHTML = '<i class="fa-solid fa-circle-dot fa-beat-fade"></i> Live GPS Tracking';
    }
  }

  const gpsStatusEl = document.getElementById('gpsStatus');
  if (gpsStatusEl) {
    if (source === 'simulation') {
      gpsStatusEl.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-gamepad"></i> Auto-drive simulation active • ${numLat.toFixed(5)}, ${numLng.toFixed(5)}</span>`;
    } else if (source === 'manual') {
      gpsStatusEl.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-hand-pointer"></i> Manual simulation active • ${numLat.toFixed(5)}, ${numLng.toFixed(5)}</span>`;
    } else if (source === 'reset') {
      gpsStatusEl.innerHTML = `<span style="color:#b45309;font-weight:600;"><i class="fa-solid fa-rotate-left"></i> Reset to store • ${numLat.toFixed(5)}, ${numLng.toFixed(5)}</span>`;
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

  // Route Polyline & Bounds: Between active origin (rider live GPS or store pickup) and customer destination via OSRM
  const activeOriginCoord = (hasRiderCoord && !data.is_initial_shop_origin) ? [rLat, rLng] : (hasStoreCoord ? [sLat, sLng] : null);

  if (hasCustomerCoord && activeOriginCoord) {
    const customerCoord = [cLat, cLng];
    // Asynchronously fetch and render real-street OSRM road geometry
    loadAndDrawRoadRoute(activeOriginCoord[0], activeOriginCoord[1], customerCoord[0], customerCoord[1], true);
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

  if (deliveryMap) {
    deliveryMap.on('click', onMapTapRelocateHandler);
  }
  initSimulator();

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
  stopAutoDriveSimulation();
  if (isTapRelocateActive) {
    toggleMapTapRelocate();
  }
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
  stopAutoDriveSimulation();
  if (isTapRelocateActive) {
    toggleMapTapRelocate();
  }
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
    maximumAge: 3000,
    timeout: 15000
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
        if (error.code === error.PERMISSION_DENIED || !window.isSecureContext) {
          enableManualSimulation("GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.");
        } else if (error.code === error.TIMEOUT) {
          const gpsStatusEl = document.getElementById('gpsStatus');
          if (gpsStatusEl) gpsStatusEl.textContent = 'Acquiring satellite lock… tap or drag marker to simulate if indoors.';
          enableManualSimulation("Acquiring GPS fix… tap or drag marker to simulate if indoors.");
        } else {
          enableManualSimulation("GPS unavailable on insecure HTTP or permission denied. Tap or drag the rider marker on the map to simulate live movement.");
        }
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
    initSimulator();
  });
} else {
  if (activeDeliveryData && document.getElementById('delivery-map')) {
    initDeliveryMap(activeDeliveryData);
  }
  initSimulator();
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
// Rider & Customer Chat Integration
let activeChatOrderId = null;
let activeChatCustomerPhone = '';
let chatPollTimer = null;
let lastKnownMessageId = 0;

function openOrderChat(orderId, customerName, customerPhone) {
  activeChatOrderId = orderId;
  activeChatCustomerPhone = customerPhone || '+639285550148';
  lastKnownMessageId = 0;

  const nameEl = document.getElementById('chatCustomerName');
  if (nameEl) nameEl.textContent = customerName || 'Customer';
  const phoneEl = document.getElementById('chatCustomerPhone');
  if (phoneEl) phoneEl.textContent = activeChatCustomerPhone;

  updateChatSmsLink('');

  const modal = document.getElementById('riderChatModal');
  if (modal) modal.classList.add('show');

  fetchOrderMessages(orderId, true);

  if (chatPollTimer) clearInterval(chatPollTimer);
  chatPollTimer = setInterval(() => {
    if (activeChatOrderId) {
      fetchOrderMessages(activeChatOrderId, false);
    }
  }, 2500);
}

function closeOrderChat() {
  const modal = document.getElementById('riderChatModal');
  if (modal) modal.classList.remove('show');
  if (chatPollTimer) {
    clearInterval(chatPollTimer);
    chatPollTimer = null;
  }
  activeChatOrderId = null;
}

function updateChatSmsLink(text) {
  const smsBtn = document.getElementById('chatSmsLink');
  if (!smsBtn) return;
  const msgText = (text && text.trim()) ? text.trim() : "Hello! This is your Food Dash delivery rider regarding your order.";
  const body = encodeURIComponent(msgText);
  smsBtn.href = `sms:${activeChatCustomerPhone}?body=${body}`;
}

async function fetchOrderMessages(orderId, scrollToBottom) {
  try {
    const res = await fetch(`order_messages_api.php?order_id=${orderId}&after_id=${lastKnownMessageId}`);
    const data = await res.json();
    if (data.success && Array.isArray(data.messages)) {
      const container = document.getElementById('chatMessagesList');
      if (!container) return;

      if (lastKnownMessageId === 0) {
        container.innerHTML = '';
      }

      if (data.messages.length > 0) {
        const hint = container.querySelector('.chat-empty-hint');
        if (hint) hint.remove();

        data.messages.forEach(msg => {
          if (msg.message_id > lastKnownMessageId) {
            lastKnownMessageId = msg.message_id;
            const isMe = msg.sender_role === 'rider';
            const bubble = document.createElement('div');
            bubble.className = `msg-bubble ${isMe ? 'me' : 'them'}`;
            bubble.innerHTML = `
              <div>${escapeHtml(msg.message_text)}</div>
              <div class="msg-meta">${escapeHtml(msg.sender_name)} • ${escapeHtml(msg.time_formatted || '')}</div>
            `;
            container.appendChild(bubble);
          }
        });

        if (scrollToBottom || data.messages.length > 0) {
          container.scrollTop = container.scrollHeight;
        }
      } else if (lastKnownMessageId === 0) {
        container.innerHTML = '<div class="chat-empty-hint">No messages yet. Send a quick update or custom note!</div>';
      }
    }
  } catch (err) {
    console.warn('Failed to load chat messages:', err);
  }
}

async function sendChatMessage() {
  const input = document.getElementById('chatInputText');
  if (!input) return;
  const text = input.value.trim();
  if (!text || !activeChatOrderId) return;

  input.value = '';
  await submitMessageToServer(activeChatOrderId, text);
}

function sendQuickUpdate(text) {
  if (!activeChatOrderId) return;
  updateChatSmsLink(text);
  submitMessageToServer(activeChatOrderId, text);
}

async function submitMessageToServer(orderId, text) {
  try {
    const res = await fetch('order_messages_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ order_id: orderId, message_text: text })
    });
    const data = await res.json();
    if (data.success) {
      fetchOrderMessages(orderId, true);
    } else {
      toastRider(data.message || 'Failed to send message', true);
    }
  } catch (err) {
    console.error('Error sending message:', err);
    toastRider('Network error sending message', true);
  }
}

function escapeHtml(str) {
  if (!str) return '';
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');
}

let currentRiderCallId = null;
let riderCallTimer = null;
let riderCallSeconds = 0;

async function initiateCustomerCall(orderId, customerName, customerPhone) {
  const cleanPhone = String(customerPhone || '+639171234567').replace(/[^\+0-9]/g, '') || '+639171234567';

  // 1. Trigger active incoming call state in the database for that order_id
  try {
    const res = await fetch('order_communication_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        action: 'trigger_call',
        order_id: orderId
      })
    });
    const data = await res.json();
    if (data.success && data.call_id) {
      currentRiderCallId = data.call_id;
    }
  } catch (err) {
    console.warn('Failed to trigger call in communication API:', err);
  }

  // 2. Mobile vs Desktop behavior
  const isMobile = /Android|webOS|iPhone|iPad|iPod|BlackBerry|IEMobile|Opera Mini/i.test(navigator.userAgent);
  if (isMobile) {
    // Native phone dialer on mobile
    window.location.href = `tel:${cleanPhone}`;
  } else {
    // Desktop / PC preview: Show clean modal without browser raw prompt
    openDesktopCallModal(orderId, customerName, customerPhone, cleanPhone);
  }
}

function openDesktopCallModal(orderId, customerName, customerPhone, cleanPhone) {
  const modal = document.getElementById('riderCallModal');
  if (!modal) return;
  
  const nameEl = document.getElementById('modalCallCustName');
  if (nameEl) nameEl.textContent = customerName || 'Customer';

  const phoneEl = document.getElementById('modalCallCustPhone');
  if (phoneEl) phoneEl.textContent = customerPhone || '+63 917 123 4567';

  const statusEl = document.getElementById('modalCallStatusText');
  if (statusEl) statusEl.textContent = 'Ringing customer in real-time... Customer screen is displaying an animated incoming call alert.';

  const timerEl = document.getElementById('modalCallTimer');
  if (timerEl) {
    timerEl.style.display = 'none';
    timerEl.textContent = '00:00';
  }

  const telLink = document.getElementById('modalCallNativeTelBtn');
  if (telLink) telLink.href = `tel:${cleanPhone}`;

  const connectBtn = document.getElementById('btnSimulateConnect');
  if (connectBtn) {
    connectBtn.style.display = 'inline-flex';
    connectBtn.innerHTML = '<i class="fa-solid fa-headset"></i> Test Connected Call';
  }

  modal.classList.add('show');
}

function simulateCallConnect() {
  const statusEl = document.getElementById('modalCallStatusText');
  if (statusEl) statusEl.textContent = 'Call connected (Voice line simulation active).';

  const timerEl = document.getElementById('modalCallTimer');
  if (timerEl) timerEl.style.display = 'block';

  const connectBtn = document.getElementById('btnSimulateConnect');
  if (connectBtn) connectBtn.style.display = 'none';

  riderCallSeconds = 0;
  if (riderCallTimer) clearInterval(riderCallTimer);
  riderCallTimer = setInterval(() => {
    riderCallSeconds++;
    const m = String(Math.floor(riderCallSeconds / 60)).padStart(2, '0');
    const s = String(riderCallSeconds % 60).padStart(2, '0');
    if (timerEl) timerEl.textContent = `${m}:${s}`;
  }, 1000);

  // Mark call accepted in backend
  if (currentRiderCallId) {
    fetch('order_communication_api.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'respond_call', call_id: currentRiderCallId, status: 'accepted' })
    }).catch(() => {});
  }
}

async function endRiderCall() {
  if (riderCallTimer) {
    clearInterval(riderCallTimer);
    riderCallTimer = null;
  }
  if (currentRiderCallId) {
    try {
      await fetch('order_communication_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'end_call', call_id: currentRiderCallId })
      });
    } catch (e) {}
    currentRiderCallId = null;
  }
  const modal = document.getElementById('riderCallModal');
  if (modal) modal.classList.remove('show');
  toastRider('Call ended.');
}

function toastGps(msg){const el=document.getElementById('gpsStatus');if(el)el.textContent=msg;}
</script></body></html>
