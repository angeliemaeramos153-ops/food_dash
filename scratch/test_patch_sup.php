<?php
$sup_url = 'https://ijxbecblutgwfmuguxkg.supabase.co/rest/v1/orders?or=(id.eq.8117,order_id.eq.8117)&select=*';
$sup_key = 'sb_publishable_vtkNEJpgxhN2xp-hbTio8g_iXS4nxz5';
$sup_body = json_encode([
    'current_lat' => 11.012555,
    'current_lng' => 124.607888,
    'rider_lat'   => 11.012555,
    'rider_lng'   => 124.607888
]);
$sch = curl_init($sup_url);
curl_setopt($sch, CURLOPT_CUSTOMREQUEST, 'PATCH');
curl_setopt($sch, CURLOPT_POSTFIELDS, $sup_body);
curl_setopt($sch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($sch, CURLOPT_HTTPHEADER, [
    'apikey: ' . $sup_key,
    'Authorization: Bearer ' . $sup_key,
    'Content-Type: application/json',
    'Prefer: return=representation'
]);
$res = curl_exec($sch);
$code = curl_getinfo($sch, CURLINFO_HTTP_CODE);
curl_close($sch);

echo "HTTP Code: $code\n";
echo "Response: $res\n";
