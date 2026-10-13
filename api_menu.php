<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

try {
    $stmt = $conn->prepare("
        SELECT 
            m.item_id,
            m.name,
            m.description,
            m.price,
            m.category,
            m.image_url,
            s.name AS shop_name
        FROM menu_items m
        LEFT JOIN shops s ON m.shop_id = s.shop_id
        WHERE m.is_available = TRUE
        ORDER BY m.category ASC, m.name ASC
    ");
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'status' => 'success',
        'count'  => count($items),
        'data'   => $items
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}