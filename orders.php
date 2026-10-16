<?php
if (session_status() === PHP_SESSION_NONE) session_start();

// If this is an API call (POST or action query), delegate to order_api.php
$isApi = ($_SERVER['REQUEST_METHOD'] === 'POST') 
    || isset($_GET['action']) 
    || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
    || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

if ($isApi) {
    require 'order_api.php';
    exit();
}

header("Location: customer_dashboard.php?screen=orders");
exit();
