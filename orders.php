<?php
if (session_status() === PHP_SESSION_NONE) session_start();
header("Location: customer_dashboard.php?screen=orders");
exit();
