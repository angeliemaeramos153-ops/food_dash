FOOD DASH CUSTOMER — NEW MOBILE FOOD-DELIVERY UI

Replace only:
C:\xampp\htdocs\food_dash\customer_dashboard.php

Keep your existing:
- index.php (your original login UI)
- db_connect.php
- logout.php
- logo.jpg
- process.php
- other role dashboards

The customer portal is now a Foodpanda-style mobile ordering experience:
Home, Stores, Store Menu, Item Details, Search, Cart, Orders, Account.
It includes Filipino fast-food demo catalog content for Jollibee, McDonald's,
Mang Inasal, Ribshack, Shakey's, Chowking, and Greenwich.

The cart is stored in the browser (localStorage) for the UI demo. Checkout is
not connected to an orders database yet because the supplied project did not
contain an orders table in the customer source.
