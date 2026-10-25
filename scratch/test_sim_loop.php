<?php
require_once __DIR__ . '/../db_connect.php';

$test_route = [
    [11.006500, 124.607500],
    [11.008000, 124.607200],
    [11.010500, 124.606800],
    [11.013500, 124.606500],
    [11.018500, 124.606200] // customer destination
];

$order_id = 8117;

echo "=== STARTING AUTO-DRIVE SIMULATION TICK TEST ===\n";

foreach ($test_route as $index => $point) {
    $lat = $point[0];
    $lng = $point[1];
    
    echo "\n[Tick $index / " . (count($test_route) - 1) . "] Moving rider to $lat, $lng...\n";
    
    // 1. AJAX fetch request to api_order.php
    $ch = curl_init('http://localhost/food_dash/api_order.php');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'action' => 'update_rider_location',
        'order_id' => $order_id,
        'current_lat' => $lat,
        'current_lng' => $lng,
        'lat' => $lat,
        'lng' => $lng,
        'is_simulation' => 1
    ]));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    $res = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $json = json_decode($res, true);
    echo "  -> api_order.php HTTP $http_code: " . ($json['success'] ? 'SUCCESS' : 'FAILED') . "\n";
    echo "  -> Supabase synced: " . ($json['supabase_synced'] ? 'YES' : 'NO') . "\n";
    
    // Verify MySQL food_orders
    $s1 = $conn->prepare("SELECT rider_lat, rider_lng FROM food_orders WHERE order_id = :oid");
    $s1->execute([':oid' => $order_id]);
    $fo = $s1->fetch(PDO::FETCH_ASSOC);
    echo "  -> MySQL food_orders: lat={$fo['rider_lat']}, lng={$fo['rider_lng']}\n";
    
    // Verify MySQL orders
    $s2 = $conn->prepare("SELECT current_lat, current_lng FROM orders WHERE id = :oid OR order_id = :oid2");
    $s2->execute([':oid' => 51, ':oid2' => $order_id]);
    $mo = $s2->fetch(PDO::FETCH_ASSOC);
    echo "  -> MySQL orders: current_lat={$mo['current_lat']}, current_lng={$mo['current_lng']}\n";
    
    // Verify Supabase Cloud
    $sup_ch = curl_init('https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1/orders?order_id=eq.' . $order_id . '&select=id,order_number,current_lat,current_lng,rider_lat,rider_lng');
    curl_setopt($sup_ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($sup_ch, CURLOPT_HTTPHEADER, [
        'apikey: sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5',
        'Authorization: Bearer sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5'
    ]);
    $sup_raw = curl_exec($sup_ch);
    curl_close($sup_ch);
    $sup_arr = json_decode($sup_raw, true);
    if (!empty($sup_arr)) {
        $so = $sup_arr[0];
        echo "  -> Supabase Cloud: order={$so['order_number']}, current_lat={$so['current_lat']}, current_lng={$so['current_lng']}\n";
    } else {
        echo "  -> Supabase Cloud: NOT FOUND\n";
    }
}

echo "\n=== SIMULATION TEST COMPLETED SUCCESSFULLY ===\n";
