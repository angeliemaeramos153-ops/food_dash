<?php
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once 'db_connect.php';

try {
    $shop_id = isset($_GET['shop_id']) ? (int)$_GET['shop_id'] : (isset($_POST['shop_id']) ? (int)$_POST['shop_id'] : 0);
    $shop_name = trim((string)($_GET['shop_name'] ?? ($_POST['shop_name'] ?? '')));
    $category = trim((string)($_GET['category'] ?? ($_POST['category'] ?? '')));

    $sql = "
        SELECT m.item_id, m.shop_id, s.shop_name, m.item_name, m.category, m.price, m.description, m.icon, m.is_recommended, m.is_available 
        FROM menu_items m 
        LEFT JOIN shops s ON m.shop_id = s.shop_id 
        WHERE m.is_available = TRUE 
    ";
    $params = [];
    if ($shop_id > 0) {
        $sql .= " AND m.shop_id = ? ";
        $params[] = $shop_id;
    } elseif ($shop_name !== '') {
        $sql .= " AND (LOWER(s.shop_name) = LOWER(?) OR s.shop_name LIKE CONCAT('%', ?, '%')) ";
        $params[] = $shop_name;
        $params[] = $shop_name;
    }
    if ($category !== '' && strtolower($category) !== 'all') {
        $sql .= " AND LOWER(m.category) = LOWER(?) ";
        $params[] = $category;
    }

    $sql .= " ORDER BY m.shop_id ASC, m.is_recommended DESC, m.item_name ASC ";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $rawItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $normalized = array_map(function($item) {
        return [
            'id'          => (int)($item['item_id'] ?? 0),
            'item_id'     => (int)($item['item_id'] ?? 0),
            'shop_id'     => isset($item['shop_id']) ? (int)$item['shop_id'] : null,
            'shop_name'   => $item['shop_name'] ?? 'Partner Store',
            'store'       => $item['shop_name'] ?? 'Partner Store',
            'name'        => $item['item_name'] ?? 'Unnamed Item',
            'description' => $item['description'] ?? '',
            'desc'        => $item['description'] ?? '',
            'price'       => (float)($item['price'] ?? 0),
            'category'    => !empty($item['category']) ? $item['category'] : 'Main',
            'icon'        => !empty($item['icon']) ? $item['icon'] : '🍽️',
            'image_url'   => $item['image_url'] ?? null,
            'is_recommended' => !empty($item['is_recommended']) ? 1 : 0
        ];
    }, $rawItems);

    echo json_encode([
        'status'  => 'success',
        'success' => true,
        'count'   => count($normalized),
        'data'    => $normalized,
        'items'   => $normalized
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'status'  => 'error',
        'success' => false,
        'message' => $e->getMessage()
    ]);
}