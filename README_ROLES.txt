FOOD DASH - NEW CHEF AND RIDER PORTALS

Files added:
- chef_dashboard.php
- rider_dashboard.php

File updated:
- process.php (role-based login routing)

Existing files to keep:
- db_connect.php
- logout.php
- logo.jpg
- index.php
- customer_dashboard.php
- dashboard.php

Chef portal:
- Uses the existing shops and menu_items tables.
- Chef can choose a store and add/edit/delete its menu items.
- Recommended flag is supported.
- It does NOT pretend to manage orders because the supplied database/code does not contain an orders table or chef-to-shop user mapping.

Rider portal:
- Uses the existing riders table.
- Rider can update waiting/on_delivery and current order reference.
- Since the supplied schema has no users-to-riders foreign key, the page matches rider_name to the logged-in full_name; if no match exists, it shows the first rider record and asks the admin to create the correct record.

No database schema changes are required for these two pages.
