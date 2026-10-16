<?php
// Food Dash Official Payment Methods & Vector Branding Helper

if (!function_exists('get_payment_method_meta')) {
    function get_payment_method_meta(?string $method): array {
        $raw = strtolower(trim((string)$method));

        if (strpos($raw, 'gcash') !== false) {
            return [
                'key'         => 'gcash',
                'name'        => 'GCash',
                'short_name'  => 'GCash',
                'subtitle'    => 'GCash - E-Wallet',
                'desc'        => 'Instant 0% fee e-wallet transfer',
                'logo'        => 'assets/logos/gcash.svg',
                'badge_class' => 'pm-badge-gcash',
                'color'       => '#007DFE',
                'bg'          => '#eff6ff',
                'border'      => '#bfdbfe',
                'text'        => '#1d4ed8'
            ];
        }

        if (strpos($raw, 'maya') !== false || strpos($raw, 'paymaya') !== false) {
            return [
                'key'         => 'maya',
                'name'        => 'Maya (PayMaya)',
                'short_name'  => 'Maya',
                'subtitle'    => 'Maya - Wallet & Bank',
                'desc'        => 'Visa, Mastercard & Maya Bank',
                'logo'        => 'assets/logos/maya.svg',
                'badge_class' => 'pm-badge-maya',
                'color'       => '#05DE82',
                'bg'          => '#0f172a',
                'border'      => '#05de82',
                'text'        => '#05de82'
            ];
        }

        if (strpos($raw, 'gotyme') !== false) {
            return [
                'key'         => 'gotyme',
                'name'        => 'GoTyme Bank',
                'short_name'  => 'GoTyme',
                'subtitle'    => 'GoTyme - Digital Bank',
                'desc'        => 'Go Rewards points on every meal',
                'logo'        => 'assets/logos/gotyme.svg',
                'badge_class' => 'pm-badge-gotyme',
                'color'       => '#5925DC',
                'bg'          => '#1e1b4b',
                'border'      => '#818cf8',
                'text'        => '#38bdf8'
            ];
        }

        if (strpos($raw, 'apple') !== false) {
            return [
                'key'         => 'apple',
                'name'        => 'Apple Pay',
                'short_name'  => 'Apple Pay',
                'subtitle'    => 'Apple Pay - Instant Touch/Face ID',
                'desc'        => 'One-tap Face ID / Touch ID payment',
                'logo'        => 'assets/logos/apple-pay.svg',
                'badge_class' => 'pm-badge-apple',
                'color'       => '#000000',
                'bg'          => '#000000',
                'border'      => '#374151',
                'text'        => '#ffffff'
            ];
        }

        // Default: Cash on Delivery (COD)
        return [
            'key'         => 'cod',
            'name'        => 'Cash on Delivery (COD)',
            'short_name'  => 'COD',
            'subtitle'    => 'Cash on Delivery - Pay upon arrival',
            'desc'        => 'Pay cash directly to your rider',
            'logo'        => 'assets/logos/cod.svg',
            'badge_class' => 'pm-badge-cod',
            'color'       => '#16a34a',
            'bg'          => '#f0fdf4',
            'border'      => '#bbf7d0',
            'text'        => '#166534'
        ];
    }
}

if (!function_exists('render_payment_badge')) {
    function render_payment_badge(?string $method, string $extra_class = '', bool $show_name = false): string {
        $meta = get_payment_method_meta($method);
        // The official vector SVG asset is already a complete branded badge containing the icon & wordmark.
        // Suppress duplicate text to prevent glitches like 'GCash GCash'.
        $name_html = ($show_name && empty($meta['logo'])) ? '<span class="pm-brand-name">' . htmlspecialchars($meta['name']) . '</span>' : '';
        return '<span class="pm-brand-badge ' . htmlspecialchars($meta['badge_class']) . ' ' . htmlspecialchars($extra_class) . '" title="' . htmlspecialchars($meta['name']) . '">'
             . '<img src="' . htmlspecialchars($meta['logo']) . '" alt="' . htmlspecialchars($meta['name']) . '" class="pm-logo" loading="lazy" />'
             . $name_html
             . '</span>';
    }
}

if (!function_exists('get_all_payment_options')) {
    function get_all_payment_options(): array {
        return [
            get_payment_method_meta('Cash on Delivery (COD)'),
            get_payment_method_meta('GCash'),
            get_payment_method_meta('Maya (PayMaya)'),
            get_payment_method_meta('GoTyme Bank'),
            get_payment_method_meta('Apple Pay'),
        ];
    }
}
