<?php
/**
 * Food Dash - Checkout & Order Placement Gateway
 * Seamlessly delegates order creation requests (POST) directly to api_orders.php
 * Redirects browsing requests (GET) to cart.php
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once __DIR__ . '/api_orders.php';
    exit;
}

header("Location: cart.php");
exit;
