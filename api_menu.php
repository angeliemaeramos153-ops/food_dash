<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

try {
    // Select all columns from menu_items dynamically to avoid missing column errors
    $stmt = $conn->prepare("
        SELECT 
            m.*,
            COALESCE(s.name, s.shop_name, 'Food Dash') AS shop_display_name
        FROM menu_items m
        LEFT JOIN shops s ON m.shop_id = s.shop_id
        WHERE m.is_available = TRUE OR m.is_available = 't' OR m.is_available = '1'
        ORDER BY m.item_id ASC
    ");
    $stmt->execute();
    $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Normalize field names so the frontend gets consistent data
    $normalized = array_map(function($item) {
        return [
            'item_id'     => $item['item_id'] ?? $item['id'] ?? null,
            'name'        => $item['item_name'] ?? $item['name'] ?? 'Unnamed Item',
            'description' => $item['description'] ?? '',
            'price'       => (float)($item['price'] ?? 0),
            'category'    => $item['category'] ?? 'General',
            'image_url'   => $item['image_url'] ?? $item['image'] ?? null,
            'shop_name'   => $item['shop_display_name'] ?? 'Food Dash'
        ];
    }, $rawItems);

    echo json_encode([
        'status' => 'success',
        'count'  => count($normalized),
        'data'   => $normalized
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'message' => $e->getMessage()
    ]);
}