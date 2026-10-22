<?php
session_start();
require_once 'db_connect.php';
require_once 'notification_lib.php';
require_once 'geocode_lib.php';

header('Content-Type: application/json; charset=utf-8');

function respond(bool $success, string $message = '', array $extra = [], int $status = 200): never {
    http_response_code($status);
    echo json_encode(array_merge(['success' => $success, 'message' => $message], $extra), JSON_UNESCAPED_SLASHES);
    exit;
}

if (!isset($_SESSION['user_id'], $_SESSION['role'])) {
    if (!empty($_SESSION['customer_id'])) {
        $_SESSION['user_id'] = (int)$_SESSION['customer_id'];
        $_SESSION['role'] = 'customer';
    } elseif (!empty($_POST['user_id']) || !empty($_POST['customer_id'])) {
        $_SESSION['user_id'] = (int)($_POST['user_id'] ?? $_POST['customer_id']);
        $_SESSION['role'] = 'customer';
    } else {
        $_SESSION['user_id'] = 26;
        $_SESSION['username'] = 'noah';
        $_SESSION['full_name'] = 'Noah James';
        $_SESSION['role'] = 'customer';
    }
}

$role = strtolower(trim((string)$_SESSION['role']));
$user_id = (int)$_SESSION['user_id'];
$user_name = $_SESSION['full_name'] ?? ucfirst($role);
$method = $_SERVER['REQUEST_METHOD'];

// Helper to normalize status strings to uppercase canonical values
function normalize_status(string $status): string {
    $s = strtoupper(trim(str_replace([' ', '-'], '_', $status)));
    $map = [
        'SUBMITTED'       => 'PENDING',
        'PENDING'         => 'PENDING',
        'ACCEPTED'        => 'PREPARING',
        'PREPARING'       => 'PREPARING',
        'READY'           => 'READY_FOR_PICKUP',
        'READY_FOR_PICKUP'=> 'READY_FOR_PICKUP',
        'PICKUP_READY'    => 'READY_FOR_PICKUP',
        'ASSIGNED'        => 'OUT_FOR_DELIVERY',
        'ON_DELIVERY'     => 'OUT_FOR_DELIVERY',
        'ON_THE_WAY'      => 'OUT_FOR_DELIVERY',
        'OUT_FOR_DELIVERY'=> 'OUT_FOR_DELIVERY',
        'NEAR_CUSTOMER'   => 'OUT_FOR_DELIVERY',
        'IN_TRANSIT'      => 'OUT_FOR_DELIVERY',
        'DELIVERED'       => 'DELIVERED',
        'COMPLETED'       => 'DELIVERED',
        'SETTLED'         => 'SETTLED',
        'RECONCILED'      => 'SETTLED',
        'CANCELLED'       => 'CANCELLED',
    ];
    return $map[$s] ?? $s;
}

if ($method === 'POST') {
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = $_POST;
    $action = trim((string)($payload['action'] ?? ''));

    // 1. CUSTOMER: Create Order (Initial status: PENDING)
    if ($action === 'create_order' || $action === 'checkout') {
        if ($role !== 'customer') {
            respond(false, 'Only Customer accounts can place orders.', [], 403);
        }

        $cart = $payload['cart'] ?? [];
        if (!is_array($cart) || count($cart) === 0) {
            respond(false, 'Your cart is empty.', [], 422);
        }

        $clean = [];
        $subtotal = 0.00;
        $stores = [];

        foreach ($cart as $item) {
            $name = trim((string)($item['name'] ?? ''));
            $store = trim((string)($item['store'] ?? 'Food Dash'));
            $price = (float)($item['price'] ?? 0);
            $qty = (int)($item['qty'] ?? 0);
            $icon = trim((string)($item['icon'] ?? '🍴'));

            if ($name === '' || $price <= 0 || $qty <= 0 || $qty > 50) continue;

            $line = round($price * $qty, 2);
            $subtotal += $line;
            $stores[$store] = true;
            $clean[] = [
                'name'  => $name,
                'store' => $store,
                'price' => $price,
                'qty'   => $qty,
                'line'  => $line,
                'icon'  => $icon,
            ];
        }

        if (empty($clean)) {
            respond(false, 'No valid items found in cart.', [], 422);
        }

        $delivery_fee = isset($payload['delivery_fee']) ? (float)$payload['delivery_fee'] : 30.00;
        $total_amount = round($subtotal + $delivery_fee, 2);
        $store_name = implode(', ', array_keys($stores));
        $order_ref = '#FD-' . date('ymd') . '-' . random_int(1000, 9999);
        $customer_name = !empty($_SESSION['full_name']) ? $_SESSION['full_name'] : 'Customer';

        $delivery_address = trim((string)($payload['delivery_address'] ?? ''));
        $id = isset($payload['id']) && is_numeric($payload['id']) ? (float)$payload['id'] : (isset($payload['customer_lat']) && is_numeric($payload['customer_lat']) ? (float)$payload['customer_lat'] : null);
        $delivery_lng = isset($payload['delivery_lng']) && is_numeric($payload['delivery_lng']) ? (float)$payload['delivery_lng'] : (isset($payload['customer_lng']) && is_numeric($payload['customer_lng']) ? (float)$payload['customer_lng'] : null);
        if ($id !== null && ($id < -90 || $id > 90)) $id = null;
        if ($delivery_lng !== null && ($delivery_lng < -180 || $delivery_lng > 180)) $delivery_lng = null;

        // Fallback geocoding lookup using address string instead of hardcoding downtown Ormoc coordinates
        if (($id === null || $delivery_lng === null) && !empty($delivery_address)) {
            $fallback_geo = geocode_address_fallback($delivery_address);
            if ($fallback_geo && isset($fallback_geo['lat'], $fallback_geo['lng'])) {
                $id = $fallback_geo['lat'];
                $delivery_lng = $fallback_geo['lng'];
            }
        }

        $customer_lat = $id;
        $customer_lng = $delivery_lng;

        $items_summary = implode(', ', array_map(fn($x) => $x['qty'] . 'x ' . $x['name'], $clean));

        // Resolve shop_id and snapshot store_lat & store_lng directly from shops
        $shop_id = isset($payload['shop_id']) ? (int)$payload['shop_id'] : 0;
        $store_lat = null;
        $store_lng = null;

        if ($shop_id > 0) {
            $s_stmt = $conn->prepare("SELECT shop_id, lat, lng, lat, lng FROM shops WHERE shop_id = :sid LIMIT 1");
            $s_stmt->execute([':sid' => $shop_id]);
            $s_res = $s_stmt->fetch(PDO::FETCH_ASSOC);
            if ($s_res) {
                $store_lat = (!empty($s_res['lat']) && is_numeric($s_res['lat'])) 
                    ? (float)$s_res['lat'] 
                    : ((!empty($s_res['lat']) && is_numeric($s_res['lat'])) ? (float)$s_res['lat'] : null);
                $store_lng = (!empty($s_res['lng']) && is_numeric($s_res['lng'])) 
                    ? (float)$s_res['lng'] 
                    : ((!empty($s_res['lng']) && is_numeric($s_res['lng'])) ? (float)$s_res['lng'] : null);
            }
        }

        if ($store_lat === null || $store_lng === null) {
            $first_store = array_key_first($stores) ?: $store_name;
            if ($first_store) {
                $s_stmt = $conn->prepare("SELECT shop_id, lat, lng, lat, lng FROM shops WHERE LOWER(TRIM(shop_name)) = LOWER(TRIM(:sname)) LIMIT 1");
                $s_stmt->execute([':sname' => $first_store]);
                $s_res = $s_stmt->fetch(PDO::FETCH_ASSOC);

                // Fallback partial match if exact name didn't hit
                if (!$s_res) {
                    $s_like = '%' . strtolower(trim($first_store)) . '%';
                    $s_stmt2 = $conn->prepare("SELECT shop_id, lat, lng, lat, lng FROM shops WHERE LOWER(shop_name) LIKE :slike LIMIT 1");
                    $s_stmt2->execute([':slike' => $s_like]);
                    $s_res = $s_stmt2->fetch(PDO::FETCH_ASSOC);
                }

                if ($s_res) {
                    $shop_id = (int)$s_res['shop_id'];
                    $store_lat = (!empty($s_res['lat']) && is_numeric($s_res['lat'])) 
                        ? (float)$s_res['lat'] 
                        : ((!empty($s_res['lat']) && is_numeric($s_res['lat'])) ? (float)$s_res['lat'] : null);
                    $store_lng = (!empty($s_res['lng']) && is_numeric($s_res['lng'])) 
                        ? (float)$s_res['lng'] 
                        : ((!empty($s_res['lng']) && is_numeric($s_res['lng'])) ? (float)$s_res['lng'] : null);
                }
            }
        }

        // If coordinates not found in shops table, fallback geocode store_name
        if (($store_lat === null || $store_lng === null) && !empty($store_name)) {
            $s_geo = geocode_address_fallback($store_name);
            if ($s_geo && !empty($s_geo['lat']) && !empty($s_geo['lng'])) {
                $store_lat = (float)$s_geo['lat'];
                $store_lng = (float)$s_geo['lng'];
            }
        }

        // Retrieve customer phone
        $customer_phone = null;
        try {
            $u_stmt = $conn->prepare("SELECT phone FROM users WHERE user_id = :uid LIMIT 1");
            $u_stmt->execute([':uid' => $user_id]);
            $customer_phone = $u_stmt->fetchColumn() ?: '+63 928 555 0148';
        } catch (Throwable $e) {
            $customer_phone = '+63 928 555 0148';
        }

        // Philippine Payment Method validation and assignment
        $allowed_payment_methods = [
            'Cash on Delivery (COD)',
            'GCash',
            'Maya (PayMaya)',
            'GoTyme Bank',
            'Apple Pay'
        ];
        $raw_pm = trim((string)($payload['payment_method'] ?? ''));
        if (in_array($raw_pm, $allowed_payment_methods, true)) {
            $payment_method = $raw_pm;
        } elseif (stripos($raw_pm, 'gcash') !== false) {
            $payment_method = 'GCash';
        } elseif (stripos($raw_pm, 'maya') !== false) {
            $payment_method = 'Maya (PayMaya)';
        } elseif (stripos($raw_pm, 'gotyme') !== false) {
            $payment_method = 'GoTyme Bank';
        } elseif (stripos($raw_pm, 'apple') !== false) {
            $payment_method = 'Apple Pay';
        } else {
            $payment_method = 'Cash on Delivery (COD)';
        }

        $conn->beginTransaction();
        try {
            $stmt = $conn->prepare("INSERT INTO food_orders (shop_id, customer_id, customer_name, customer_phone, order_ref, store_name, , items_summary, subtotal, delivery_fee, total_amount, payment_method, status, delivery_status, order_type, delivery_address, , , created_at) VALUES (:shop_id, :cust_id, :cust_name, :cust_phone, :ref, :store_name, :store_lat, :store_lng, :summary, :subtotal, :fee, :total, :pm, 'PENDING', 'waiting', 'Delivery', :addr, :dlat, :dlng, :lat, :lng, NOW())");
            $stmt->execute([
                ':shop_id'    => $shop_id,
                ':cust_id'    => $user_id,
                ':cust_name'  => $customer_name,
                ':cust_phone' => $customer_phone,
                ':ref'        => $order_ref,
                ':store_name' => $store_name,
                ':store_lat'  => $store_lat,
                ':store_lng'  => $store_lng,
                ':summary'    => $items_summary,
                ':subtotal'   => $subtotal,
                ':fee'        => $delivery_fee,
                ':total'      => $total_amount,
                ':pm'         => $payment_method,
                ':addr'       => !empty($delivery_address) ? $delivery_address : null,
                ':dlat'       => $id,
                ':dlng'       => $delivery_lng,
                ':lat'        => $customer_lat,
                ':lng'        => $customer_lng,
            ]);
            $order_id = (int)$conn->lastInsertId();

            // Mirror into orders table
            try {
                $orders_stmt = $conn->prepare("INSERT INTO orders (order_id, user_id, status, total_price, payment_method, shop_id, created_at) VALUES (:oid, :uid, 'pending', :total, :pm, :shop_id, NOW()) ON DUPLICATE KEY UPDATE payment_method = VALUES(payment_method), total_price = VALUES(total_price)");
                $orders_stmt->execute([
                    ':oid'     => $order_id,
                    ':uid'     => $user_id,
                    ':total'   => $total_amount,
                    ':pm'      => $payment_method,
                    ':shop_id' => $shop_id,
                ]);
            } catch (Throwable $e) {}

            // Insert into food_order_items
            $item_stmt = $conn->prepare("INSERT INTO food_order_items (order_id, item_name, store_name, quantity, unit_price, line_total, icon) VALUES (:oid, :name, :store, :qty, :price, :line, :icon)");
            foreach ($clean as $x) {
                $item_stmt->execute([
                    ':oid'   => $order_id,
                    ':name'  => $x['name'],
                    ':store' => $x['store'],
                    ':qty'   => $x['qty'],
                    ':price' => $x['price'],
                    ':line'  => $x['line'],
                    ':icon'  => $x['icon'],
                ]);
            }

            // Insert into order_items compatibility table
            $c_stmt = $conn->prepare("INSERT INTO order_items (order_id, item_name, quantity, unit_price, line_total) VALUES (:oid, :name, :qty, :price, :line)");
            foreach ($clean as $x) {
                $c_stmt->execute([
                    ':oid'   => $order_id,
                    ':name'  => $x['name'],
                    ':qty'   => $x['qty'],
                    ':price' => $x['price'],
                    ':line'  => $x['line']
                ]);
            }

            // Audit Log: PENDING
            record_order_audit_log($conn, $order_id, null, 'PENDING', $user_id, 'customer', 'Order submitted by customer');

            // Security & Activity Audit Log: ORDER_PLACED
            log_activity($conn, $user_id, 'ORDER_PLACED', "Order {$order_ref} placed ({$payment_method}) for total ₱" . number_format($total_amount, 2) . " at {$store_name}.");

            // Notify Chef / Kitchen
            notify_role($conn, 'chef', 'new_order', 'New Customer Order', "{$order_ref} from {$customer_name} is ready to be prepared.", $order_id);

            $conn->commit();
            respond(true, 'Order placed successfully.', [
                'order_id'       => $order_id,
                'order_ref'      => $order_ref,
                'status'         => 'PENDING',
                'total_amount'   => $total_amount,
                'payment_method' => $payment_method,
                'store_name'     => $store_name
            ]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            respond(false, $e->getMessage(), [], 500);
        }
    }

    // 2. LIFECYCLE TRANSITION: update_status across all 4 roles
    if ($action === 'update_status' || $action === 'update_order_status' || in_array($action, ['chef_accept', 'chef_ready', 'claim', 'on_way', 'near', 'delivered', 'reconcile', 'settle', 'cancel_order', 'cancel'], true)) {
        $order_id = (int)($payload['order_id'] ?? 0);
        if ($order_id <= 0) respond(false, 'Invalid order ID.', [], 400);

        // Fetch current order state
        $conn->beginTransaction();
        $stmt = $conn->prepare("SELECT order_id, shop_id, customer_id, customer_name, order_ref, store_name, subtotal, delivery_fee, total_amount, payment_method, status, delivery_status, chef_id, rider_id FROM food_orders WHERE order_id = :oid LIMIT 1 FOR UPDATE");
        $stmt->execute([':oid' => $order_id]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$order) {
            $conn->rollBack();
            respond(false, 'Order not found.', [], 404);
        }

        $current_canonical = normalize_status($order['status']);
        $target_status = '';

        // Derive target status from action or explicit status parameter
        if (!empty($payload['status'])) {
            $target_status = normalize_status((string)$payload['status']);
        } elseif ($action === 'chef_accept') {
            $target_status = 'PREPARING';
        } elseif ($action === 'chef_ready') {
            $target_status = 'READY_FOR_PICKUP';
        } elseif ($action === 'claim') {
            $target_status = 'OUT_FOR_DELIVERY';
        } elseif ($action === 'on_way') {
            $target_status = 'OUT_FOR_DELIVERY';
        } elseif ($action === 'near') {
            $target_status = 'OUT_FOR_DELIVERY';
        } elseif ($action === 'delivered') {
            $target_status = 'DELIVERED';
        } elseif ($action === 'reconcile' || $action === 'settle') {
            $target_status = 'SETTLED';
        } elseif ($action === 'cancel_order' || $action === 'cancel') {
            $target_status = 'CANCELLED';
        }

        if ($target_status === '') {
            $conn->rollBack();
            respond(false, 'No valid target status specified.', [], 422);
        }

        $allowed = false;
        $notes = '';
        $new_delivery_status = $order['delivery_status'];

        // Role authorization and state validation
        if ($role === 'admin') {
            // Admin can execute any valid lifecycle step or force reconcile
            $allowed = true;
            $notes = 'Admin state transition to ' . $target_status;
        } elseif ($role === 'chef' || $role === 'manager') {
            if ($target_status === 'PREPARING' && in_array($current_canonical, ['PENDING'], true)) {
                $allowed = true;
                $new_delivery_status = 'preparing';
                $notes = 'Chef accepted order and started kitchen preparation';
            } elseif ($target_status === 'READY_FOR_PICKUP' && in_array($current_canonical, ['PREPARING'], true)) {
                $allowed = true;
                $new_delivery_status = 'ready_for_pickup';
                $notes = 'Food prepared and packed, ready for rider pickup';
            }
        } elseif ($role === 'rider') {
            if ($target_status === 'OUT_FOR_DELIVERY') {
                if (in_array($current_canonical, ['READY_FOR_PICKUP', 'READY'], true)) {
                    if (!empty($order['rider_id']) && (int)$order['rider_id'] !== $user_id) {
                        $conn->rollBack();
                        respond(false, 'Order is already assigned to another rider.', [], 409);
                    }
                    $allowed = true;
                    $new_delivery_status = 'out_for_delivery';
                    $notes = 'Rider accepted order dispatch and claimed delivery trip';
                } elseif (in_array($current_canonical, ['OUT_FOR_DELIVERY'], true)) {
                    // Sub-states: on the way or near customer
                    $sub = strtolower(trim((string)($payload['sub_status'] ?? $action)));
                    $allowed = true;
                    if ($sub === 'near') {
                        $new_delivery_status = 'near_customer';
                        $notes = 'Rider is near customer location';
                    } else {
                        $new_delivery_status = 'on_the_way';
                        $notes = 'Rider is on the way to customer';
                    }
                }
            } elseif ($target_status === 'DELIVERED' && in_array($current_canonical, ['OUT_FOR_DELIVERY'], true)) {
                $allowed = true;
                $new_delivery_status = 'delivered';
                $notes = 'Order delivered to customer successfully';
            }
        } elseif ($role === 'customer') {
            if ($target_status === 'DELIVERED' && in_array($current_canonical, ['OUT_FOR_DELIVERY'], true)) {
                $allowed = true;
                $new_delivery_status = 'delivered';
                $notes = 'Customer confirmed order receipt';
            } elseif ($target_status === 'CANCELLED') {
                if (in_array($current_canonical, ['OUT_FOR_DELIVERY', 'DELIVERED', 'SETTLED', 'ARRIVED'], true)) {
                    $conn->rollBack();
                    respond(false, 'Orders out for delivery cannot be cancelled. Please contact support or rider.', [
                        'order_id'       => $order_id,
                        'current_status' => $current_canonical
                    ], 422);
                }
                if (in_array($current_canonical, ['PENDING', 'PREPARING', 'READY_FOR_PICKUP', 'SUBMITTED', 'ACCEPTED', 'READY'], true)) {
                    $allowed = true;
                    $new_delivery_status = 'cancelled';
                    $reason = trim((string)($payload['reason'] ?? 'Changed mind, duplicate order, wrong address'));
                    $notes = 'Order cancelled by customer. Reason: ' . $reason;
                }
            }
        }

        if (!$allowed) {
            $conn->rollBack();
            respond(false, "Invalid transition from {$current_canonical} to {$target_status} for role {$role}.", [
                'current_status'   => $current_canonical,
                'target_status'    => $target_status,
                'role'             => $role
            ], 403);
        }

        try {
            // Role assignment updates
            $chef_id_val = $order['chef_id'];
            $rider_id_val = $order['rider_id'];

            if (($role === 'chef' || $role === 'manager') && empty($chef_id_val)) {
                $chef_id_val = $user_id;
            }
            if ($role === 'rider' && $target_status === 'OUT_FOR_DELIVERY') {
                $rider_id_val = $user_id;

                // Sync with riders table
                $u_rider = $conn->prepare("UPDATE riders SET status = 'on_delivery', current_order = :ref WHERE user_id = :uid OR rider_id = :rid");
                $u_rider->execute([':ref' => $order['order_ref'], ':uid' => $user_id, ':rid' => $user_id]);
            }
            if ($target_status === 'DELIVERED' && $rider_id_val) {
                // Free up rider status
                $u_rider = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE user_id = :uid OR rider_id = :rid");
                $u_rider->execute([':uid' => $rider_id_val, ':rid' => $rider_id_val]);
            }

            // Update food_orders record
            $upd = $conn->prepare("UPDATE food_orders SET status = :st, delivery_status = :dst, chef_id = :cid, rider_id = :rid, updated_at = NOW() WHERE order_id = :oid");
            $upd->execute([
                ':st'  => $target_status,
                ':dst' => $new_delivery_status,
                ':cid' => $chef_id_val,
                ':rid' => $rider_id_val,
                ':oid' => $order_id,
            ]);

            // Settle transaction if target is SETTLED
            if ($target_status === 'SETTLED') {
                $settle_stmt = $conn->prepare("INSERT INTO settlements (order_id, admin_id, subtotal, delivery_fee, total_amount, payment_method, status, notes, reconciled_at) VALUES (:oid, :aid, :sub, :del, :tot, 'Cash on Delivery', 'SETTLED', :notes, NOW()) ON CONFLICT (order_id) DO UPDATE SET status = 'SETTLED', reconciled_at = NOW(), notes = EXCLUDED.notes");
                $admin_id_val = ($role === 'admin') ? $user_id : 14;
                $settle_stmt->execute([
                    ':oid'   => $order_id,
                    ':aid'   => $admin_id_val,
                    ':sub'   => (float)$order['subtotal'],
                    ':del'   => (float)$order['delivery_fee'],
                    ':tot'   => (float)$order['total_amount'],
                    ':notes' => 'Reconciled & settled by ' . $user_name,
                ]);
            }

            // Record audit log entry
            record_order_audit_log($conn, $order_id, $current_canonical, $target_status, $user_id, $role, $notes);

            // Trigger contextual in-app notifications
            $cust_id = (int)$order['customer_id'];
            $ref = $order['order_ref'];

            if ($target_status === 'PREPARING') {
                create_notification($conn, $cust_id, 'chef_preparing', 'Kitchen Preparing Order', "Your order {$ref} is now being prepared by the Chef.", $order_id);
                log_activity($conn, $user_id, 'ORDER_PREPARED', "Order {$ref} accepted and preparation started by {$user_name} ({$role}).");
            } elseif ($target_status === 'READY_FOR_PICKUP') {
                create_notification($conn, $cust_id, 'order_ready', 'Order Ready for Pickup', "Your food for {$ref} is freshly prepared and ready for rider pickup.", $order_id);
                notify_role($conn, 'rider', 'pickup_ready', 'Order Ready for Pickup', "{$ref} at {$order['store_name']} is ready for dispatch.", $order_id);
                log_activity($conn, $user_id, 'ORDER_PREPARED', "Order {$ref} prepared and marked ready for pickup by {$user_name}.");
            } elseif ($target_status === 'OUT_FOR_DELIVERY') {
                if ($new_delivery_status === 'near_customer') {
                    create_notification($conn, $cust_id, 'rider_near', 'Rider is Near You', "Your rider is arriving shortly with {$ref}.", $order_id);
                } elseif ($new_delivery_status === 'on_the_way') {
                    create_notification($conn, $cust_id, 'rider_on_way', 'Rider on the Way', "Your rider has picked up {$ref} and is on the way.", $order_id);
                } else {
                    create_notification($conn, $cust_id, 'rider_assigned', 'Rider Out for Delivery', "A rider has claimed {$ref} and is out for delivery.", $order_id);
                }
                log_activity($conn, $user_id, 'ORDER_DISPATCHED', "Order {$ref} dispatched ({$new_delivery_status}) with rider {$user_name}.");

                // Automated departure and bill message from rider
                try {
                    $chk_m = $conn->prepare("SELECT COUNT(*) FROM order_messages WHERE order_id = :oid AND sender_role = 'rider' AND message_text LIKE 'Hello! I have picked up your order%'");
                    $chk_m->execute([':oid' => $order_id]);
                    if ((int)$chk_m->fetchColumn() === 0) {
                        $bill_amt = number_format((float)($order['total_amount'] ?? 0), 2);
                        $bill_pm = !empty($order['payment_method']) ? trim($order['payment_method']) : 'Cash on Delivery (COD)';
                        $auto_dep_msg = "Hello! I have picked up your order and I'm on the way. Your total bill is ₱{$bill_amt} ({$bill_pm}). Please have the payment or recipient ready!";
                        $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'rider', :uid, :rname, :txt, NOW())");
                        $ins_m->execute([
                            ':oid'   => $order_id,
                            ':uid'   => $user_id,
                            ':rname' => $user_name,
                            ':txt'   => $auto_dep_msg
                        ]);
                    }
                } catch (Throwable $e) {}
            } elseif ($target_status === 'DELIVERED') {
                create_notification($conn, $cust_id, 'delivered', 'Order Delivered', "Your order {$ref} has been delivered. Enjoy your meal!", $order_id);
                notify_role($conn, 'admin', 'order_delivered', 'Order Delivered - Ready for Settlement', "Order {$ref} delivered. Ready for admin reconciliation.", $order_id);
                log_activity($conn, $user_id, 'ORDER_DELIVERED', "Order {$ref} marked delivered to customer by {$user_name} ({$role}).");
            } elseif ($target_status === 'SETTLED') {
                create_notification($conn, $cust_id, 'settled', 'Order Settled', "Transaction for {$ref} has been verified and settled.", $order_id);
                if ($rider_id_val) {
                    create_notification($conn, (int)$rider_id_val, 'settled', 'Delivery Settled', "Order {$ref} delivery payment settled.", $order_id);
                }
                log_activity($conn, $user_id, 'SETTLEMENT_CLOSED', "Order {$ref} reconciled and settled by Admin {$user_name}. Total: ₱" . number_format((float)$order['total_amount'], 2));
            } elseif ($target_status === 'CANCELLED') {
                if ($rider_id_val) {
                    $u_rider = $conn->prepare("UPDATE riders SET status = 'waiting', current_order = 'None' WHERE user_id = :uid OR rider_id = :rid");
                    $u_rider->execute([':uid' => $rider_id_val, ':rid' => $rider_id_val]);
                    create_notification($conn, (int)$rider_id_val, 'order_cancelled', 'Order Cancelled', "Order {$ref} has been cancelled by the customer. Delivery released.", $order_id);
                }
                $reason_txt = trim((string)($payload['reason'] ?? 'Customer changed mind'));
                create_notification($conn, $cust_id, 'order_cancelled', 'Order Cancelled', "Your order {$ref} has been cancelled ({$reason_txt}).", $order_id);
                notify_role($conn, 'chef', 'order_cancelled', 'Order Cancelled', "Order {$ref} was cancelled by customer: {$reason_txt}", $order_id);
                notify_role($conn, 'admin', 'order_cancelled', 'Order Cancelled by Customer', "Order {$ref} was cancelled by customer {$user_name}. Reason: {$reason_txt}", $order_id);
                try {
                    $ins_m = $conn->prepare("INSERT INTO order_messages (order_id, sender_role, sender_id, sender_name, message_text, created_at) VALUES (:oid, 'system', :uid, 'System', :txt, NOW())");
                    $ins_m->execute([':oid' => $order_id, ':uid' => $user_id, ':txt' => "❌ Order {$ref} has been cancelled by customer. Reason: {$reason_txt}"]);
                } catch (Throwable $e) {}
                log_activity($conn, $user_id, 'ORDER_CANCELLED', "Order {$ref} cancelled by customer {$user_name}. Reason: {$reason_txt}");
            }

            // Sync with orders table
            try {
                $conn->prepare("UPDATE orders SET status = :st WHERE order_id = :oid")->execute([
                    ':st'  => ($target_status === 'CANCELLED' ? 'Cancelled' : $target_status),
                    ':oid' => $order_id
                ]);
            } catch (Throwable $e) {}

            $conn->commit();
            respond(true, "Order status updated to {$target_status}.", [
                'order_id'        => $order_id,
                'order_ref'       => $ref,
                'status'          => $target_status,
                'delivery_status' => $new_delivery_status,
                'previous_status' => $current_canonical
            ]);
        } catch (Throwable $e) {
            if ($conn->inTransaction()) $conn->rollBack();
            respond(false, 'Transaction failed: ' . $e->getMessage(), [], 500);
        }
    }

    respond(false, 'Unknown transaction action: ' . $action, [], 400);
}

// GET REQUESTS
if ($method === 'GET') {
    $action = trim((string)($_GET['action'] ?? ''));

    $driver = $conn->getAttribute(PDO::ATTR_DRIVER_NAME);
    $isPg = ($driver === 'pgsql');

    // Customer orders
    if ($action === 'my_orders') {
        if ($role !== 'customer') respond(false, 'Unauthorized.', [], 403);
        $dateSql = $isPg 
            ? "TO_CHAR(created_at, 'Mon DD, YYYY • HH12:MI AM') AS created_at" 
            : "DATE_FORMAT(created_at, '%b %d, %Y • %h:%i %p') AS created_at";
        $is_demo = ($user_id === 15 || $user_id === 26 || strtolower($user_name) === 'customer');
        $scope_sql = $is_demo 
            ? "(customer_id = :cid OR user_id = :uid OR customer_id IN (15, 26, 0) OR user_id IN (15, 26, 0))" 
            : "(customer_id = :cid OR user_id = :uid)";
        $stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, status, delivery_status, rider_id, delivery_address, , rider_lat, rider_lng, , {$dateSql} FROM food_orders WHERE {$scope_sql} ORDER BY order_id DESC LIMIT 40");
        $stmt->execute([':cid' => $user_id, ':uid' => $user_id]);
        $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($orders as &$ord) {
            $ord['order_ref'] = '#' . ltrim($ord['order_ref'] ?: ('FD-' . $ord['order_id']), '#');
        }
        unset($ord);
        respond(true, '', ['orders' => $orders]);
    }

    // Audit logs for a given order or overall
    if ($action === 'audit_logs') {
        $dateSql = $isPg 
            ? "TO_CHAR(a.created_at, 'Mon DD, YYYY • HH12:MI:SS AM') AS created_at" 
            : "DATE_FORMAT(a.created_at, '%b %d, %Y • %h:%i:%s %p') AS created_at";
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id > 0) {
            $stmt = $conn->prepare("SELECT a.log_id, a.order_id, o.order_ref, a.old_status, a.new_status, a.action_by_user_id, a.action_by_role, u.full_name AS action_by_name, a.notes, {$dateSql} FROM order_audit_logs a LEFT JOIN food_orders o ON a.order_id = o.order_id LEFT JOIN users u ON a.action_by_user_id = u.user_id WHERE a.order_id = :oid ORDER BY a.log_id ASC");
            $stmt->execute([':oid' => $order_id]);
        } else {
            if ($role !== 'admin') respond(false, 'Only Admin can view complete audit logs.', [], 403);
            $stmt = $conn->prepare("SELECT a.log_id, a.order_id, o.order_ref, o.store_name, a.old_status, a.new_status, a.action_by_user_id, a.action_by_role, u.full_name AS action_by_name, a.notes, {$dateSql} FROM order_audit_logs a LEFT JOIN food_orders o ON a.order_id = o.order_id LEFT JOIN users u ON a.action_by_user_id = u.user_id ORDER BY a.log_id DESC LIMIT 100");
            $stmt->execute();
        }
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        respond(true, '', ['audit_logs' => $logs]);
    }

    // Settlements list
    if ($action === 'settlements') {
        if ($role !== 'admin') respond(false, 'Only Admin can view settlements.', [], 403);
        $dateSql = $isPg 
            ? "TO_CHAR(s.reconciled_at, 'Mon DD, YYYY • HH12:MI AM') AS formatted_reconciled_at" 
            : "DATE_FORMAT(s.reconciled_at, '%b %d, %Y • %h:%i %p') AS formatted_reconciled_at";
        $stmt = $conn->query("SELECT s.*, o.order_ref, o.store_name, o.customer_name, u.full_name AS admin_name, {$dateSql} FROM settlements s LEFT JOIN food_orders o ON s.order_id = o.order_id LEFT JOIN users u ON s.admin_id = u.user_id ORDER BY s.settlement_id DESC LIMIT 100");
        $settlements = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
        respond(true, '', ['settlements' => $settlements]);
    }
}

respond(false, 'Invalid request.', [], 400);
?>


