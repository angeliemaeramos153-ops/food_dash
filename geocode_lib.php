<?php
/**
 * Food Dash - Geocoding Helper Library
 * Provides geocoding fallback for customer delivery addresses using OpenStreetMap Nominatim
 * and a localized Ormoc City barangay directory.
 */

if (!function_exists('geocode_address_fallback')) {
    function geocode_address_fallback(?string $address): ?array {
        if ($address === null) return null;
        $clean_addr = trim($address);
        if ($clean_addr === '') return null;

        // 1. Try free OpenStreetMap Nominatim endpoint with User-Agent header
        $queries = [
            $clean_addr . ', Ormoc City, Leyte',
            preg_replace('/Ormoc(\s+City)?/i', '', $clean_addr) . ', Ormoc, Leyte',
            $clean_addr . ', Ormoc, Leyte',
            $clean_addr
        ];

        foreach ($queries as $q) {
            $q_clean = trim(preg_replace('/,\s*,+/', ',', $q), " ,\t\n\r");
            if ($q_clean === '') continue;

            $url = 'https://nominatim.openstreetmap.org/search?format=json&limit=1&q=' . urlencode($q_clean);
            $json_str = null;

            if (function_exists('curl_init')) {
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_USERAGENT, 'FoodDash-App/1.0 (Delivery Geocoder; contact@fooddash.local)');
                curl_setopt($ch, CURLOPT_TIMEOUT, 3);
                curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
                $json_str = curl_exec($ch);
                curl_close($ch);
            }

            if (!$json_str) {
                $opts = [
                    'http' => [
                        'method' => 'GET',
                        'header' => "User-Agent: FoodDash-App/1.0 (Delivery Geocoder)\r\nAccept: application/json\r\n",
                        'timeout' => 3
                    ]
                ];
                $json_str = @file_get_contents($url, false, stream_context_create($opts));
            }

            if ($json_str) {
                $data = json_decode($json_str, true);
                if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                    $lat = (float)$data[0]['lat'];
                    $lng = (float)$data[0]['lon'];
                    if ($lat >= -90 && $lat <= 90 && $lng >= -180 && $lng <= 180) {
                        return [
                            'lat'         => round($lat, 7),
                            'lng'         => round($lng, 7),
                            'source'      => 'nominatim',
                            'query_used'  => $q_clean,
                            'display_name'=> $data[0]['display_name'] ?? null
                        ];
                    }
                }
            }
        }

        // 2. Local Ormoc barangay dictionary mapping if external API is unreachable or rate-limited
        $norm = strtolower($clean_addr);
        $barangays = [
            'western leyte college' => [11.0076, 124.6089],
            'wlc'                   => [11.0076, 124.6089],
            'lilia'                 => [11.0185, 124.6062],
            'combado'               => [11.0185, 124.6062],
            'cogon combado'         => [11.0185, 124.6062],
            'batuan'                => [11.0185, 124.6062],
            'alta vista'       => [11.0118026, 124.6203237],
            'altavista'        => [11.0118026, 124.6203237],
            'cogon'            => [11.0233014, 124.6040868],
            'valencia'         => [11.1071389, 124.5782215],
            'ipil'             => [10.9754111, 124.6331304],
            'san pedro'        => [11.0057362, 124.6108008],
            'linao'            => [11.0159798, 124.5908042],
            'camp downes'      => [10.9966183, 124.6182589],
            'bantigue'         => [10.9895862, 124.6222361],
            'tambulilid'       => [11.0231637, 124.5846183],
            'can-adieng'       => [11.0025430, 124.6139237],
            'can adieng'       => [11.0025430, 124.6139237],
            'canadieng'        => [11.0025430, 124.6139237],
            'punta'            => [11.0092018, 124.6003072],
            'alegria'          => [11.0055905, 124.6038316],
            'san pablo'        => [11.0150000, 124.6050000],
            'don felipe'       => [11.0120000, 124.6150000],
            'naungan'          => [10.9980000, 124.5820000],
            'san isidro'       => [11.0200000, 124.6080000],
            'dayhagan'         => [11.0010000, 124.6300000],
            'donghol'          => [11.0300000, 124.6300000],
            'curva'            => [11.0600000, 124.5900000],
            'dolores'          => [11.0800000, 124.6300000],
            'milagro'          => [11.0900000, 124.6500000],
            'sabang'           => [11.0030000, 124.6090000],
            'mabini'           => [11.0060000, 124.6100000],
            'nadurayan'        => [11.0400000, 124.6000000],
            'lourdes'          => [11.0200000, 124.6200000],
            'suman'            => [11.0250000, 124.6350000],
            'patag'            => [11.0500000, 124.6100000],
            'bagong buhay'     => [11.0450000, 124.6200000],
            'macabug'          => [11.0350000, 124.6150000],
            'tongonan'         => [11.1300000, 124.6500000],
            'simangan'         => [10.9700000, 124.6400000],
            'san jose'         => [11.0100000, 124.6200000],
            'san juan'         => [11.0080000, 124.6120000],
            'salvacion'        => [11.0650000, 124.6250000],
            'san vicente'      => [11.0150000, 124.6180000],
            'licuma'           => [11.0220000, 124.5950000],
            'ormoc'            => [11.0090349, 124.6093940],
        ];

        foreach ($barangays as $name => $coords) {
            if (strpos($norm, $name) !== false) {
                return [
                    'lat'    => $coords[0],
                    'lng'    => $coords[1],
                    'source' => 'local_dictionary'
                ];
            }
        }

        // 3. Fallback for Metro Manila or general Ormoc if explicitly mentioned
        if (strpos($norm, 'ortigas') !== false || strpos($norm, 'pasig') !== false) {
            return ['lat' => 14.5871500, 'lng' => 121.0612400, 'source' => 'local_dictionary'];
        }
        if (strpos($norm, 'mandaluyong') !== false || strpos($norm, 'edsa') !== false) {
            return ['lat' => 14.5732100, 'lng' => 121.0478200, 'source' => 'local_dictionary'];
        }

        // Default Ormoc City central coordinates as ultimate safety net
        return ['lat' => 11.0054, 'lng' => 124.6078, 'source' => 'default_ormoc'];
    }
}


if (!function_exists('normalize_query_str')) {
    function normalize_query_str(?string $str): string {
        if ($str === null) return '';
        $s = strtolower(trim($str));
        $s = preg_replace('/[.,\/#!$%\^&\*;:{}=\-_\x60~()\'"?]/', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', $s));
    }
}

if (!function_exists('is_valid_ormoc_coord')) {
    function is_valid_ormoc_coord($lat, $lng): bool {
        if ($lat === null || $lng === null || !is_numeric($lat) || !is_numeric($lng)) return false;
        $fLat = (float)$lat;
        $fLng = (float)$lng;
        // Ormoc City envelope: Lat 10.95 to 11.15, Lng 124.50 to 124.75
        // Strictly reject corrupted values like 5.0, 0, or stale Alta Vista defaults (11.0138, 124.6040)
        if ($fLat < 10.95 || $fLat > 11.15 || $fLng < 124.50 || $fLng > 124.75) return false;
        if (abs($fLat - 11.0138) < 0.001 && abs($fLng - 124.6040) < 0.001) return false;
        return true;
    }
}

if (!function_exists('smart_geocode_ormoc')) {
    function smart_geocode_ormoc(?string $text, string $type = 'any'): ?array {
        if (empty($text)) return null;
        $norm = strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', ' ', $text)));
        $norm = preg_replace('/\s+/', ' ', $norm);
        if ($norm === '') return null;

        // 1. Destination / Street Matching
        if ($type === 'destination' || $type === 'any') {
            if (
                strpos($norm, 'western') !== false ||
            strpos($norm, 'college') !== false ||
            strpos($norm, 'wlc') !== false ||
            strpos($norm, 'bonifacio') !== false
            ) {
                return ['lat' => 11.0076, 'lng' => 124.6089, 'name' => 'Western Leyte College (A. Bonifacio St.)', 'type' => 'destination'];
            }
            if (strpos($norm, 'city hall') !== false) {
                return ['lat' => 11.0088, 'lng' => 124.6050, 'name' => 'Ormoc City Hall', 'type' => 'destination'];
            }
            if (strpos($norm, 'superdome') !== false) {
                return ['lat' => 11.0035, 'lng' => 124.6095, 'name' => 'Ormoc Superdome', 'type' => 'destination'];
            }
            if (strpos($norm, 'plaza') !== false) {
                return ['lat' => 11.0065, 'lng' => 124.6070, 'name' => 'Ormoc City Plaza', 'type' => 'destination'];
            }
        }

        // 2. Store / Mall Matching
        if ($type === 'store' || $type === 'any') {
            $hasRobinsons = (strpos($norm, 'robinson') !== false || strpos($norm, 'rob') !== false);
            $hasSm = (strpos($norm, 'sm') !== false);
            $isJollibee = (strpos($norm, 'jollibee') !== false);
            $isInasal = (strpos($norm, 'inasal') !== false);

            $isRobinsonsBranch = ($isJollibee || $isInasal) && $hasRobinsons;
            $isSmBranch = ($isJollibee || $isInasal) && $hasSm;

            $isRobinsonsStore = (
                strpos($norm, 'shakey') !== false ||
                strpos($norm, 'mcdonald') !== false ||
                strpos($norm, 'mcdo') !== false ||
                strpos($norm, 'chowking') !== false ||
                strpos($norm, 'greenwich') !== false ||
                $isRobinsonsBranch ||
                ($hasRobinsons && !$isSmBranch)
            );

            $isSmStore = (
                $isSmBranch ||
                strpos($norm, 'ribshack') !== false ||
                strpos($norm, 'rib shack') !== false ||
                strpos($norm, 'sm ormoc') !== false ||
                strpos($norm, 'sm center') !== false ||
                ($hasSm && !$isRobinsonsStore) ||
                ($isJollibee && !$isRobinsonsStore)
            );

            if ($isRobinsonsStore && !$isSmBranch && strpos($norm, 'ribshack') === false) {
                return ['lat' => 11.0215, 'lng' => 124.6155, 'name' => 'Robinsons Place Ormoc', 'mall' => 'Robinsons Place Ormoc', 'is_partner' => true, 'type' => 'store'];
            }

            if ($isSmStore) {
                return ['lat' => 11.0062, 'lng' => 124.6068, 'name' => 'SM Center Ormoc', 'mall' => 'SM Center Ormoc', 'is_partner' => true, 'type' => 'store'];
            }
        }

        return null;
    }
}

if (!function_exists('resolve_ormoc_store_coords')) {
    function resolve_ormoc_store_coords(?string $store_name = null, ?string $store_address = '', $raw_lat = null, $raw_lng = null, ?PDO $conn = null, ?int $store_id = null): array {
        if (!$conn) {
            global $conn, $pdo;
            if (!$conn && isset($pdo)) $conn = $pdo;
            if (!$conn && file_exists(__DIR__ . '/db_connect.php')) {
                require_once __DIR__ . '/db_connect.php';
            }
        }

        // Query dynamically from database (`stores` or `restaurants` table where store name matches SM Ormoc or by selected store ID)
        if ($conn instanceof PDO) {
            try {
                if ($store_id && $store_id > 0) {
                    $stmt = $conn->prepare("SELECT lat, lng, COALESCE(store_name, name, shop_name) AS store_name FROM stores WHERE id = :sid OR shop_id = :sid2 LIMIT 1");
                    $stmt->execute([':sid' => $store_id, ':sid2' => $store_id]);
                    $row = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($row && !empty($row['lat']) && !empty($row['lng'])) {
                        return [
                            'lat' => (float)$row['lat'],
                            'lng' => (float)$row['lng'],
                            'name' => $row['store_name'] ?: ($store_name ?: 'SM Center Ormoc'),
                            'mall' => 'SM Center Ormoc',
                            'is_partner' => true,
                            'source' => 'db_stores_by_id'
                        ];
                    }
                }

                // Query stores or restaurants table where store name matches SM Ormoc or matches selected store name
                $stmt = $conn->prepare("
                    SELECT lat, lng, COALESCE(store_name, name, shop_name) AS store_name
                    FROM stores
                    WHERE (LOWER(store_name) LIKE '%sm%ormoc%' OR LOWER(name) LIKE '%sm%ormoc%' OR LOWER(shop_name) LIKE '%sm%ormoc%' OR LOWER(address) LIKE '%sm%ormoc%')
                       OR (LOWER(store_name) = LOWER(:sname) OR LOWER(name) = LOWER(:sname2))
                    ORDER BY (CASE WHEN (LOWER(store_name) = LOWER(:sname3) OR LOWER(name) = LOWER(:sname4)) THEN 1 WHEN LOWER(store_name) LIKE '%sm%ormoc%' THEN 2 ELSE 3 END) ASC
                    LIMIT 1
                ");
                $stmt->execute([
                    ':sname' => (string)$store_name,
                    ':sname2' => (string)$store_name,
                    ':sname3' => (string)$store_name,
                    ':sname4' => (string)$store_name,
                ]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($row && !empty($row['lat']) && !empty($row['lng'])) {
                    return [
                        'lat' => (float)$row['lat'],
                        'lng' => (float)$row['lng'],
                        'name' => $row['store_name'] ?: ($store_name ?: 'SM Center Ormoc'),
                        'mall' => 'SM Center Ormoc',
                        'is_partner' => true,
                        'source' => 'db_stores_dynamic'
                    ];
                }

                // Also check restaurants table/view
                $stmtR = $conn->query("SELECT lat, lng, store_name FROM restaurants WHERE LOWER(store_name) LIKE '%sm%ormoc%' LIMIT 1");
                if ($stmtR) {
                    $rowR = $stmtR->fetch(PDO::FETCH_ASSOC);
                    if ($rowR && !empty($rowR['lat']) && !empty($rowR['lng'])) {
                        return [
                            'lat' => (float)$rowR['lat'],
                            'lng' => (float)$rowR['lng'],
                            'name' => $rowR['store_name'] ?: ($store_name ?: 'SM Center Ormoc'),
                            'mall' => 'SM Center Ormoc',
                            'is_partner' => true,
                            'source' => 'db_restaurants_dynamic'
                        ];
                    }
                }
            } catch (Throwable $e) {}
        }

        // If raw coordinates from database row exist and are valid, use them directly
        if (is_valid_ormoc_coord($raw_lat, $raw_lng)) {
            return [
                'lat' => (float)$raw_lat,
                'lng' => (float)$raw_lng,
                'name' => $store_name ?: 'SM Center Ormoc',
                'mall' => 'SM Center Ormoc',
                'is_partner' => true,
                'source' => 'db_row_coords'
            ];
        }

        return [
            'lat' => (float)($raw_lat ?: 11.0062),
            'lng' => (float)($raw_lng ?: 124.6068),
            'name' => !empty($store_name) ? $store_name : 'SM Center Ormoc',
            'mall' => 'SM Center Ormoc',
            'is_partner' => true,
            'source' => 'db_lookup'
        ];
    }
}

if (!function_exists('resolve_ormoc_dest_coords')) {
    function resolve_ormoc_dest_coords(?string $address, $raw_lat = null, $raw_lng = null): array {
        $clean = trim((string)($address ?? ''));
        $norm = strtolower($clean);

        // 1. ABSOLUTE PRIORITY: Case-insensitive text-matching override
        // If delivery address contains "western", "leyte", "college", "wlc", or "bonifacio",
        // strictly override destination to WLC's exact campus center (11.0076, 124.6089), completely discarding corrupted values (e.g. 5.00000).
        $is_wlc_or_bonifacio = (
            strpos($norm, 'western') !== false ||
            strpos($norm, 'college') !== false ||
            strpos($norm, 'wlc') !== false ||
            strpos($norm, 'bonifacio') !== false
        );

        if ($is_wlc_or_bonifacio) {
            return [
                'lat' => 11.0076,
                'lng' => 124.6089,
                'name' => !empty($clean) ? $clean : 'Western Leyte College (A. Bonifacio St.)',
                'source' => 'wlc_campus_override'
            ];
        }

        $fLat = (isset($raw_lat) && is_numeric($raw_lat)) ? (float)$raw_lat : null;
        $fLng = (isset($raw_lng) && is_numeric($raw_lng)) ? (float)$raw_lng : null;

        // Discard corrupted coordinates (like 5.0, 11.01039, or Alta Vista default 11.0138)
        $is_invalid = (
            $fLat === null ||
            $fLng === null ||
            !is_valid_ormoc_coord($fLat, $fLng) ||
            abs($fLat - 5.0) < 0.0001 ||
            (abs($fLat - 11.0138) < 0.001 && abs($fLng - 124.6040) < 0.001) ||
            (abs($fLat - 11.01039) < 0.001)
        );

        if ($is_invalid) {
            $fallback = geocode_address_fallback($clean);
            if ($fallback && !empty($fallback['lat']) && !empty($fallback['lng'])) {
                return [
                    'lat' => (float)$fallback['lat'],
                    'lng' => (float)$fallback['lng'],
                    'name' => !empty($clean) ? $clean : 'Customer Destination',
                    'source' => 'ormoc_fallback'
                ];
            }
            return [
                'lat' => 11.0076,
                'lng' => 124.6089,
                'name' => !empty($clean) ? $clean : 'Western Leyte College (A. Bonifacio St.)',
                'source' => 'wlc_campus_override'
            ];
        }

        return [
            'lat' => $fLat,
            'lng' => $fLng,
            'name' => !empty($clean) ? $clean : 'Customer Destination',
            'source' => 'db_verified'
        ];
    }
}

/**
 * Robust dynamic geocoding check:
 * 1. Whenever order details or coordinates are loaded (specifically customer_lat, customer_lng, or delivery_address), normalize address text (lowercase, trimmed).
 * 2. If delivery address contains "western", "leyte", "college", "wlc", or "bonifacio", strictly override destination coordinates to WLC's precise campus center: latitude 11.0076, longitude 124.6089, completely discarding corrupted database coordinates like 5.00000.
 * 3. If the store is Jollibee SM, override store coordinates to SM Ormoc: latitude 11.0062, longitude 124.6068.
 * 4. Ensure zero hardcoded order IDs - evaluates all orders dynamically.
 */
if (!function_exists('apply_dynamic_geocoding_to_order')) {
    function apply_dynamic_geocoding_to_order(array &$order): void {
        // 1. Destination / Customer address normalization (lowercase, trimmed)
        $raw_addr = trim((string)($order['delivery_address'] ?? $order['destination_name'] ?? $order['address'] ?? ''));
        $norm_addr = strtolower($raw_addr);

        $raw_c_lat = null;
        if (isset($order['delivery_lat']) && is_numeric($order['delivery_lat'])) {
            $raw_c_lat = (float)$order['delivery_lat'];
        } elseif (isset($order['destination_lat']) && is_numeric($order['destination_lat'])) {
            $raw_c_lat = (float)$order['destination_lat'];
        } elseif (isset($order['dest_lat']) && is_numeric($order['dest_lat'])) {
            $raw_c_lat = (float)$order['dest_lat'];
        } elseif (isset($order['customer_lat']) && is_numeric($order['customer_lat'])) {
            $raw_c_lat = (float)$order['customer_lat'];
        }

        $raw_c_lng = null;
        if (isset($order['delivery_lng']) && is_numeric($order['delivery_lng'])) {
            $raw_c_lng = (float)$order['delivery_lng'];
        } elseif (isset($order['destination_lng']) && is_numeric($order['destination_lng'])) {
            $raw_c_lng = (float)$order['destination_lng'];
        } elseif (isset($order['dest_lng']) && is_numeric($order['dest_lng'])) {
            $raw_c_lng = (float)$order['dest_lng'];
        } elseif (isset($order['customer_lng']) && is_numeric($order['customer_lng'])) {
            $raw_c_lng = (float)$order['customer_lng'];
        }

        // Check keywords
        $is_wlc = (
            strpos($norm_addr, 'western') !== false ||
            strpos($norm_addr, 'college') !== false ||
            strpos($norm_addr, 'wlc') !== false ||
            strpos($norm_addr, 'bonifacio') !== false
        );

        $dest_res = resolve_ormoc_dest_coords($raw_addr, $raw_c_lat, $raw_c_lng);
        $d_lat = $dest_res['lat'];
        $d_lng = $dest_res['lng'];

        $order['delivery_lat'] = $d_lat;
        $order['delivery_lng'] = $d_lng;
        $order['customer_lat'] = $d_lat;
        $order['customer_lng'] = $d_lng;

        if (isset($order['destination_lat']) || array_key_exists('destination_lat', $order)) {
            $order['destination_lat'] = $d_lat;
        }
        if (isset($order['destination_lng']) || array_key_exists('destination_lng', $order)) {
            $order['destination_lng'] = $d_lng;
        }
        if (isset($order['dest_lat']) || array_key_exists('dest_lat', $order)) {
            $order['dest_lat'] = $d_lat;
        }
        if (isset($order['dest_lng']) || array_key_exists('dest_lng', $order)) {
            $order['dest_lng'] = $d_lng;
        }

        // 2. Dynamic Store Location: Query database (`stores` or `restaurants` table) based on the order's selected store ID
        if (!isset($conn) || !$conn) {
            global $conn, $pdo;
            if (!$conn && isset($pdo)) $conn = $pdo;
            if (!$conn && file_exists(__DIR__ . '/db_connect.php')) {
                require_once __DIR__ . '/db_connect.php';
            }
        }

        $sid = (int)($order['shop_id'] ?? $order['store_id'] ?? 0);
        $sname = trim((string)($order['store_name'] ?? ''));
        $db_store = null;

        if (isset($conn) && $conn instanceof PDO) {
            try {
                if ($sid > 0) {
                    $stmtS = $conn->prepare("SELECT lat, lng, store_name FROM stores WHERE id = :sid OR shop_id = :sid2 LIMIT 1");
                    $stmtS->execute([':sid' => $sid, ':sid2' => $sid]);
                    $db_store = $stmtS->fetch(PDO::FETCH_ASSOC);
                }
                if (!$db_store) {
                    $stmtS2 = $conn->prepare("
                        SELECT lat, lng, store_name FROM stores
                        WHERE (LOWER(store_name) LIKE '%sm%ormoc%' OR LOWER(name) LIKE '%sm%ormoc%')
                           OR (LOWER(store_name) = LOWER(:sname) OR LOWER(name) = LOWER(:sname2))
                        ORDER BY (CASE WHEN (LOWER(store_name) = LOWER(:sname3) OR LOWER(name) = LOWER(:sname4)) THEN 1 WHEN LOWER(store_name) LIKE '%sm%ormoc%' THEN 2 ELSE 3 END) ASC
                        LIMIT 1
                    ");
                    $stmtS2->execute([':sname' => $sname, ':sname2' => $sname, ':sname3' => $sname, ':sname4' => $sname]);
                    $db_store = $stmtS2->fetch(PDO::FETCH_ASSOC);
                }
                if (!$db_store) {
                    $stmtR = $conn->query("SELECT lat, lng, store_name FROM restaurants WHERE LOWER(store_name) LIKE '%sm%ormoc%' LIMIT 1");
                    if ($stmtR) $db_store = $stmtR->fetch(PDO::FETCH_ASSOC);
                }
            } catch (Throwable $e) {}
        }

        if ($db_store && !empty($db_store['lat']) && !empty($db_store['lng'])) {
            $order['store_lat'] = (float)$db_store['lat'];
            $order['store_lng'] = (float)$db_store['lng'];
            $order['pickup_lat'] = (float)$db_store['lat'];
            $order['pickup_lng'] = (float)$db_store['lng'];
        } elseif (isset($order['store_lat']) && is_numeric($order['store_lat'])) {
            $order['store_lat'] = (float)$order['store_lat'];
            $order['store_lng'] = (float)$order['store_lng'];
        }
    }
}

