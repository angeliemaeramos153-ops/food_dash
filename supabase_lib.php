<?php
/**
 * Food Dash - Supabase Cloud Synchronization Library
 * Seamlessly mirrors and updates orders, order items, and rider telemetry to Supabase Cloud.
 */

if (!function_exists('sync_order_to_supabase')) {
    function sync_order_to_supabase(int $order_id, ?PDO $conn = null): bool {
        if ($order_id <= 0) return false;
        if (!$conn) {
            global $pdo;
            $conn = $pdo;
        }
        if (!$conn) return false;

        try {
            // 1. Fetch order details from MySQL food_orders
            $stmt = $conn->prepare("
                SELECT o.*, 
                       r.rider_name AS assigned_rider_name, 
                       r.phone AS assigned_rider_phone
                FROM food_orders o
                LEFT JOIN riders r ON o.rider_id = r.rider_id
                WHERE o.order_id = :oid
                LIMIT 1
            ");
            $stmt->execute([':oid' => $order_id]);
            $ord = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ord) return false;

            if (file_exists(__DIR__ . '/geocode_lib.php')) {
                require_once __DIR__ . '/geocode_lib.php';
            }
            if (function_exists('apply_dynamic_geocoding_to_order')) {
                apply_dynamic_geocoding_to_order($ord);
            }

            // Resolve delivery coordinates
            $del_lat = (isset($ord['delivery_lat']) && is_numeric($ord['delivery_lat']) && (float)$ord['delivery_lat'] != 0) 
                ? (float)$ord['delivery_lat'] 
                : ((isset($ord['customer_lat']) && is_numeric($ord['customer_lat']) && (float)$ord['customer_lat'] != 0) ? (float)$ord['customer_lat'] : null);
            $del_lng = (isset($ord['delivery_lng']) && is_numeric($ord['delivery_lng']) && (float)$ord['delivery_lng'] != 0) 
                ? (float)$ord['delivery_lng'] 
                : ((isset($ord['customer_lng']) && is_numeric($ord['customer_lng']) && (float)$ord['customer_lng'] != 0) ? (float)$ord['customer_lng'] : null);

            // Persist corrected coordinates back to MySQL if they were corrupted or missing
            if ($del_lat !== null && $del_lng !== null) {
                try {
                    $conn->prepare("UPDATE food_orders SET delivery_lat = ?, delivery_lng = ?, customer_lat = ?, customer_lng = ? WHERE order_id = ?")
                         ->execute([$del_lat, $del_lng, $del_lat, $del_lng, $order_id]);
                    $conn->prepare("UPDATE orders SET delivery_lat = ?, delivery_lng = ? WHERE id = ? OR order_id = ?")
                         ->execute([$del_lat, $del_lng, $order_id, $order_id]);
                } catch (Throwable $ignore) {}
            }

            // Rider GPS coordinates
            $rider_lat = (isset($ord['rider_lat']) && is_numeric($ord['rider_lat']) && (float)$ord['rider_lat'] != 0) ? (float)$ord['rider_lat'] : null;
            $rider_lng = (isset($ord['rider_lng']) && is_numeric($ord['rider_lng']) && (float)$ord['rider_lng'] != 0) ? (float)$ord['rider_lng'] : null;

            // Normalize status
            $raw_st = strtolower(trim((string)($ord['delivery_status'] ?: $ord['status'] ?: 'pending')));
            $status_map = match($raw_st) {
                'waiting', 'placed' => 'pending',
                'preparing' => 'preparing',
                'on_the_way', 'on_way', 'on_delivery' => 'on_delivery',
                'near_customer', 'near' => 'near_customer',
                'arrived' => 'arrived',
                'delivered' => 'delivered',
                'cancelled' => 'cancelled',
                default => $raw_st
            };

            $total_amount = (float)($ord['total_amount'] ?? $ord['total_price'] ?? 0);
            if ($total_amount <= 0) {
                try {
                    $t_stmt = $conn->prepare("SELECT COALESCE(SUM(line_total), 0) FROM food_order_items WHERE order_id = :oid");
                    $t_stmt->execute([':oid' => $order_id]);
                    $items_sum = (float)$t_stmt->fetchColumn();
                    $fee = (float)($ord['delivery_fee'] ?? 30.00);
                    if ($items_sum > 0) {
                        $total_amount = round($items_sum + $fee, 2);
                    }
                } catch (Throwable $e) {}
            }

            $del_address = trim((string)($ord['delivery_address'] ?? ''));
            if ($del_address === '') {
                try {
                    $o_stmt = $conn->prepare("SELECT delivery_address FROM orders WHERE order_id = :oid OR id = :oid2 LIMIT 1");
                    $o_stmt->execute([':oid' => $order_id, ':oid2' => $order_id]);
                    $del_address = trim((string)($o_stmt->fetchColumn() ?: ''));
                } catch (Throwable $e) {}
            }

            $order_ref = $ord['order_ref'] ?? ('#FD-' . $order_id);
            $rider_name = $ord['assigned_rider_name'] ?? ($ord['rider_name'] ?? null);
            $rider_phone = $ord['assigned_rider_phone'] ?? ($ord['rider_phone'] ?? null);

            $sup_base = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1';
            $sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';

            // Check if order exists in Supabase
            $chk_url = $sup_base . '/orders?or=(id.eq.' . $order_id . ',order_id.eq.' . $order_id . ')&select=id';
            $ch = curl_init($chk_url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 3);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'apikey: ' . $sup_key,
                'Authorization: Bearer ' . $sup_key
            ]);
            $chk_res = curl_exec($ch);
            curl_close($ch);
            $existing = json_decode($chk_res, true);

            $order_payload = [
                'order_number'        => $order_ref,
                'order_id'            => $order_id,
                'user_id'             => (int)($ord['customer_id'] ?? $ord['user_id'] ?? 0),
                'store_id'            => (int)($ord['shop_id'] ?? 0),
                'store_lat'           => 11.0062,
                'store_lng'           => 124.6068,
                'status'              => $status_map,
                'payment_method'      => $ord['payment_method'] ?? 'Cash on Delivery (COD)',
                'delivery_address'    => $del_address,
                'total_amount'        => $total_amount,
                'payment_status'      => $ord['payment_status'] ?? 'pending',
                'order_status'        => $status_map
            ];
            if ($del_lat !== null) $order_payload['delivery_lat'] = $del_lat;
            if ($del_lng !== null) $order_payload['delivery_lng'] = $del_lng;
            if ($rider_lat !== null) {
                $order_payload['rider_lat'] = $rider_lat;
                $order_payload['current_lat'] = $rider_lat;
            }
            if ($rider_lng !== null) {
                $order_payload['rider_lng'] = $rider_lng;
                $order_payload['current_lng'] = $rider_lng;
            }
            if (!empty($rider_name)) $order_payload['rider_name'] = $rider_name;
            if (!empty($rider_phone)) $order_payload['rider_phone'] = $rider_phone;

            $supabase_row_id = $order_id;
            if (!empty($existing) && is_array($existing) && count($existing) > 0) {
                // PATCH update
                $supabase_row_id = (int)$existing[0]['id'];
                $patch_url = $sup_base . '/orders?id=eq.' . $supabase_row_id;
                $pch = curl_init($patch_url);
                curl_setopt($pch, CURLOPT_CUSTOMREQUEST, 'PATCH');
                curl_setopt($pch, CURLOPT_POSTFIELDS, json_encode($order_payload));
                curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($pch, CURLOPT_TIMEOUT, 3);
                curl_setopt($pch, CURLOPT_HTTPHEADER, [
                    'apikey: ' . $sup_key,
                    'Authorization: Bearer ' . $sup_key,
                    'Content-Type: application/json'
                ]);
                curl_exec($pch);
                curl_close($pch);
            } else {
                // POST insert with explicit id
                $order_payload['id'] = $order_id;
                $post_url = $sup_base . '/orders';
                $pch = curl_init($post_url);
                curl_setopt($pch, CURLOPT_POST, true);
                curl_setopt($pch, CURLOPT_POSTFIELDS, json_encode($order_payload));
                curl_setopt($pch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($pch, CURLOPT_TIMEOUT, 3);
                curl_setopt($pch, CURLOPT_HTTPHEADER, [
                    'apikey: ' . $sup_key,
                    'Authorization: Bearer ' . $sup_key,
                    'Content-Type: application/json',
                    'Prefer: return=representation'
                ]);
                $post_res = curl_exec($pch);
                curl_close($pch);
                $inserted = json_decode($post_res, true);
                if (!empty($inserted) && is_array($inserted) && isset($inserted[0]['id'])) {
                    $supabase_row_id = (int)$inserted[0]['id'];
                }
            }

            // Sync items into Supabase order_items using the verified supabase_row_id
            $item_stmt = $conn->prepare("SELECT * FROM food_order_items WHERE order_id = :oid");
            $item_stmt->execute([':oid' => $order_id]);
            $items = $item_stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!empty($items)) {
                $ichk = curl_init($sup_base . '/order_items?order_id=eq.' . $supabase_row_id . '&select=id');
                curl_setopt($ichk, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ichk, CURLOPT_TIMEOUT, 3);
                curl_setopt($ichk, CURLOPT_HTTPHEADER, [
                    'apikey: ' . $sup_key,
                    'Authorization: Bearer ' . $sup_key
                ]);
                $ex_items = json_decode(curl_exec($ichk), true);
                curl_close($ichk);

                if (empty($ex_items) || count($ex_items) === 0) {
                    $insert_items = [];
                    foreach ($items as $it) {
                        $u_price = (float)($it['unit_price'] ?? $it['price'] ?? 0);
                        $qty = (int)($it['quantity'] ?? 1);
                        $insert_items[] = [
                            'order_id'   => $supabase_row_id,
                            'item_name'  => $it['item_name'] ?? 'Item',
                            'price'      => $u_price,
                            'unit_price' => $u_price,
                            'quantity'   => $qty,
                            'line_total' => (float)($it['line_total'] ?? ($u_price * $qty)),
                            'store_name' => $it['store_name'] ?? ($ord['store_name'] ?? 'Restaurant')
                        ];
                    }
                    if (!empty($insert_items)) {
                        $ich = curl_init($sup_base . '/order_items');
                        curl_setopt($ich, CURLOPT_POST, true);
                        curl_setopt($ich, CURLOPT_POSTFIELDS, json_encode($insert_items));
                        curl_setopt($ich, CURLOPT_RETURNTRANSFER, true);
                        curl_setopt($ich, CURLOPT_TIMEOUT, 3);
                        curl_setopt($ich, CURLOPT_HTTPHEADER, [
                            'apikey: ' . $sup_key,
                            'Authorization: Bearer ' . $sup_key,
                            'Content-Type: application/json'
                        ]);
                        curl_exec($ich);
                        curl_close($ich);
                    }
                }
            }

            return true;
        } catch (Throwable $e) {
            error_log('Supabase sync error: ' . $e->getMessage());
            return false;
        }
    }
}
