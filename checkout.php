<?php
// Bypass checkout.php - All-in-one express checkout executes directly within cart.php
header("Location: cart.php", true, 302);
exit;
