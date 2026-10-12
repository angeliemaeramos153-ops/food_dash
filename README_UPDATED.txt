Food Dash — iPhone 17 Pro Max mobile dashboard update

This package updates all four dashboard roles and shared logout flow.

Replace these files in C:\xampp\htdocs\food_dash\:
- admin_dashboard.php
- customer_dashboard.php
- chef_dashboard.php
- rider_dashboard.php
- logout.php
- index.php
- process.php

Keep your existing db_connect.php and logo.jpg. Do not replace your database connection. Your original login UI in index.php is preserved; only its role-routing lines were updated so Admin opens admin_dashboard.php.

UI behavior:
- 440px app shell sized for an iPhone 17 Pro Max-style mobile layout.
- Admin, Customer, Chef and Rider all use the same cream/olive Food Dash visual language.
- Logout confirmation is centered in the middle of the app, not at the top of the browser.
- Logout confirmation is available from every dashboard.
- Direct logout.php also opens the same centered confirmation screen unless ?confirm=1 is supplied.

Admin functions retained: rider CRUD/status, store CRUD, menu CRUD, delivery broadcast/post log.
Chef functions retained: store selection and menu item add/edit/delete.
Rider functions retained: delivery status and order reference update.
Customer functions retained: store browsing, search, item details, cart, checkout, orders and account navigation.
