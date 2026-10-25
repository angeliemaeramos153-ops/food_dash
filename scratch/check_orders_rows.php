<?php
require_once __DIR__ . '/../db_connect.php';
$stmt = $conn->query("SELECT id, order_id, order_number, current_lat, current_lng, rider_lat, rider_lng FROM orders WHERE order_number LIKE '%8117%' OR order_id = 8117 OR id = 51 OR order_id = 51");
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    print_r($r);
}
