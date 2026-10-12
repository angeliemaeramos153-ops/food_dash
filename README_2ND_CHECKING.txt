FOOD DASH - 2ND CHECKING UPDATE

Functional two-role transaction implemented:
Customer -> Chef

1. Run database_2nd_checking.sql in phpMyAdmin using the food_dash database.
2. Keep the existing index.php login UI.
3. Replace customer_dashboard.php, chef_dashboard.php, process.php with these updated files.
4. Add order_transaction.php to the same food_dash folder.
5. Log in as a Customer and add food to Cart.
6. Press Checkout. The order is written to food_orders and food_order_items.
7. Log out, then log in as Chef.
8. The Chef dashboard shows the real customer order.
9. Chef clicks Start Preparing, then Mark Ready. The status is stored in food_orders.
10. Log back in as Customer and open My Orders. The database status is displayed.

This is the transaction/module to demonstrate during the 2nd checking:
Customer places order -> database stores order -> Chef retrieves order -> Chef processes order/status -> Customer can retrieve the updated order.

No login UI was redesigned by this update.
The existing Admin dashboard and existing database connection are preserved.
