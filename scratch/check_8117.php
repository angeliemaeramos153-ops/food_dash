<?php
require_once __DIR__ . '/../db_connect.php';

echo "=== CHECKING MySQL food_orders for 8117 ===\n";
$stmt = $conn->query("SELECT * FROM food_orders WHERE order_id = 8117 OR order_ref LIKE '%8117%'");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "food_orders: id=" . $r['order_id'] . ", ref=" . ($r['order_ref'] ?? '') . ", rider_id=" . ($r['rider_id'] ?? '') . ", rider_lat=" . ($r['rider_lat'] ?? '') . ", rider_lng=" . ($r['rider_lng'] ?? '') . "\n";
}

echo "\n=== CHECKING MySQL orders for 8117 ===\n";
$stmt2 = $conn->query("SELECT * FROM orders WHERE id = 8117 OR order_id = 8117 OR order_id = 51 OR id = 51");
while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
    echo "orders: id=" . $r['id'] . ", order_id=" . ($r['order_id'] ?? '') . ", current_lat=" . ($r['current_lat'] ?? '') . ", current_lng=" . ($r['current_lng'] ?? '') . "\n";
}

echo "\n=== CHECKING Supabase orders for 8117 ===\n";
$sup_url = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1/orders?or=(id.eq.8117,order_id.eq.8117,id.eq.51)&select=*';
$sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';
$ch = curl_init($sup_url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $sup_key,
    'Authorization: Bearer ' . $sup_key
]);
$res = curl_exec($ch);
curl_close($ch);
echo "Supabase response: " . $res . "\n";
