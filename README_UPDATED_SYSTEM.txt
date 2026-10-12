FOOD DASH — MOBILE DASHBOARD UPDATE
====================================

Updated role dashboards:
- dashboard.php           = ADMIN
- customer_dashboard.php  = CUSTOMER
- chef_dashboard.php      = CHEF
- rider_dashboard.php     = RIDER
- logout.php              = shared logout confirmation

WHAT WAS UPDATED
----------------
1. Admin dashboard now has a real mobile-app layout at phone width, with a bottom navigation bar.
2. Admin Analytics, Riders, Partner Stores, Menus, and Post Log controls remain functional.
3. Admin Add/Edit/Delete buttons remain connected to the existing PHP/database actions.
4. Customer dashboard keeps the original login page and uses the Food Dash mobile food-delivery style.
5. Customer cart, store browsing, search, item details, order creation (local browser storage), and order filters are functional.
6. Chef dashboard bottom navigation now scrolls to working Home/Menu/Store sections.
7. Rider dashboard bottom navigation now scrolls to working Home/Delivery/Guide sections.
8. All four roles have logout confirmation.
9. logout.php also shows a confirmation page if opened directly.
10. Admin dashboard now enforces the admin role; Chef/Rider/Customer already enforce their roles.

INSTALLATION
------------
1. Open C:\xampp\htdocs\food_dash
2. BACK UP your current PHP files first.
3. Copy these files into the food_dash folder and choose Replace:
   - dashboard.php
   - customer_dashboard.php
   - chef_dashboard.php
   - rider_dashboard.php
   - logout.php
4. Do NOT replace index.php if you want to keep your existing login UI.
5. Keep your existing db_connect.php and database.
6. Start Apache/MySQL in XAMPP.
7. Open:
   http://localhost/food_dash/

IMPORTANT
---------
The customer checkout/order history in this UI uses browser localStorage because the supplied project did not provide a complete customer orders table/workflow. It is ready to be connected to a real orders table later.

The mobile design activates automatically on small screens. On desktop, the admin dashboard keeps its desktop sidebar while the Chef/Rider/Customer portals remain phone-style layouts.
