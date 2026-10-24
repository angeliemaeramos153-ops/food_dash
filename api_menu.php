<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

try {
    $stmt = $conn->prepare("
        SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.image_url, m.is_recommended, m.is_available 
        FROM menu_items m 
        LEFT JOIN shops s ON m.shop_id = s.shop_id 
        WHERE m.is_available = TRUE 
        ORDER BY m.shop_id ASC, m.is_recommended DESC, m.item_id ASC
    ");
    $stmt->execute();
    $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $normalized = array_map(function($item) {
        return [
            'item_id'     => (int)($item['item_id'] ?? 0),
            'shop_id'     => isset($item['shop_id']) ? (int)$item['shop_id'] : null,
            'shop_name'   => $item['shop_name'] ?? 'Partner Store',
            'name'        => $item['item_name'] ?? 'Unnamed Item',
            'description' => $item['description'] ?? '',
            'price'       => (float)($item['price'] ?? 0),
            'category'    => $item['category'] ?? 'General',
            'icon'        => $item['icon'] ?? '🍽️',
            'image_url'   => $item['image_url'] ?? null,
            'is_recommended' => !empty($item['is_recommended']) ? 1 : 0
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