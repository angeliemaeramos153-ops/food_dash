<?php
require_once __DIR__ . '/../db_connect.php';
$r = $conn->query("SELECT id, order_number, current_lat, current_lng FROM orders WHERE order_number LIKE '%8117%'")->fetch(PDO::FETCH_ASSOC);
print_r($r);
