FOOD DASH - LIVE RIDER TRACKING + AUTOMATIC NEAR-ARRIVAL

WHAT THIS ADDS
1. Customer places an order and their GPS location is stored when permission is granted.
2. Rider can share live GPS location from the Rider dashboard while on a delivery.
3. Customer can open Track Order to see a live map with the Rider and delivery location.
4. The system calculates distance using GPS coordinates. At 500 meters or less, the system automatically changes the delivery status to near_customer and creates the customer notification once.
5. Customer notification polling remains active every 5 seconds while the Customer app is open.

INSTALL
1. Back up C:\xampp\htdocs\food_dash.
2. Replace customer_dashboard.php, rider_dashboard.php, order_transaction.php.
3. Add tracking_api.php.
4. Import the updated database_2nd_checking.sql into food_dash.
5. Keep index.php, admin_dashboard.php, chef_dashboard.php, db_connect.php, logout.php and logo.jpg.

TEST
Customer: place an order and allow location permission.
Chef: Start Preparing -> Mark Ready.
Rider: Accept Delivery -> I'm On The Way -> Share Live Location.
Customer: My Orders -> tap location icon -> Use My Location if needed.
Move the Rider device/emulator within about 500 meters of the Customer location. The server will automatically create the near-arrival notification.

IMPORTANT BROWSER LIMITATION
The Rider's browser must remain open and have location permission while sharing GPS. A normal localhost PHP website cannot reliably track a Rider in the background after the browser/app is closed. For a school project demo, keeping the Rider dashboard open is sufficient.

MAP
The tracker uses Leaflet with OpenStreetMap tiles. Internet access is needed for map tiles. GPS data and notification records are stored in MySQL.
