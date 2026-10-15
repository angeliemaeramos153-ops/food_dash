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
