<?php
// Test calling api_order.php
$url = 'http://localhost/food_dash/api_order.php';
$payload = [
    'action' => 'update_rider_location',
    'order_id' => 8117,
    'current_lat' => 11.013456,
    'current_lng' => 124.606789
];
$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

echo "HTTP Code: $code\n";
echo "Response: $res\n";
