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
        $ref = $order['order_ref'] ?: '#' . $order_id;
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
    $stmt = $conn->prepare("SELECT order_id, order_ref, store_name, items_summary, subtotal, delivery_fee, total_amount, payment_method, status, delivery_status, rider_id, delivery_address, created_at 
        FROM food_orders 
        WHERE customer_id = :cid 
        ORDER BY order_id DESC LIMIT 50");
    $stmt->execute([':cid' => $user_id]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as &$ord) {
        $ord['payment_meta'] = get_payment_method_meta($ord['payment_method'] ?? 'Cash on Delivery (COD)');
    }
    unset($ord);

    order_api_out(true, 'Orders retrieved.', ['orders' => $orders]);
}

// 3. ORDER DETAILS & TIMELINE
if ($action === 'order_details') {
    $order_id = (int)($_GET['order_id'] ?? $payload['order_id'] ?? 0);
    if ($order_id <= 0) {
        order_api_out(false, 'Valid order_id is required.', [], 422);
    }

    $stmt = $conn->prepare("SELECT * FROM food_orders WHERE order_id = :oid AND (customer_id = :cid OR :is_admin = 1) LIMIT 1");
    $stmt->execute([':oid' => $order_id, ':cid' => $user_id, ':is_admin' => ($role === 'admin' ? 1 : 0)]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$order) {
        order_api_out(false, 'Order not found.', [], 404);
    }

    $order['payment_meta'] = get_payment_method_meta($order['payment_method'] ?? 'Cash on Delivery (COD)');

    $l_stmt = $conn->prepare("SELECT log_id, old_status, new_status, action_by_role, notes, created_at FROM order_audit_logs WHERE order_id = :oid ORDER BY log_id ASC");
    $l_stmt->execute([':oid' => $order_id]);
    $timeline = $l_stmt->fetchAll(PDO::FETCH_ASSOC);

    order_api_out(true, 'Order details retrieved.', ['order' => $order, 'timeline' => $timeline]);
}

order_api_out(false, 'Invalid order API action.', [], 400);
