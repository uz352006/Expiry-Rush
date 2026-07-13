# Expiry Rush

A web-based marketplace for near-expiry food products, built as a database systems course project. Sellers list products, and prices drop automatically the closer the product gets to its expiry date (up to 90% off).

Course project for CS 2005: Database Systems, FAST-NUCES.

## What it does

Stores end up with food that's about to expire and either throw it out or sell it manually at a discount. This app automates that: as a product's expiry gets closer, the price keeps dropping based on a formula in the database, so buyers can find deals and sellers can recover some revenue instead of losing all of it.

There are three types of users:

- **Customers** – browse products with live countdown timers, add to cart, checkout, view past orders, and set alerts for when a product hits a certain discount.
- **Sellers** – add/edit products, see incoming orders, check stock.
- **Admin** – manage users and categories, view revenue reports.

## Tech stack

- MySQL 8.0 (tables, views, stored procedures)
- PHP 8.x (backend)
- HTML + Bootstrap 5 (frontend)
- JavaScript / Fetch API (live countdown timers and price refresh)
- XAMPP for local dev

## How the pricing works

The discount is calculated in a SQL view (`active_products`), not in PHP:

```
discount_percent = GREATEST(0, LEAST(90,ROUND((1 - (seconds_remaining / total_product_lifespan_seconds)) * 90)))
current_price = base_price * (1 - discount_percent / 100)
```

So a product starts at 0% off when listed, and the discount climbs toward 90% as it gets closer to expiry. It's capped so it never goes negative or above 90%.

When a customer adds something to their cart, the current price gets locked in for 10 minutes (`cart.locked_price`, `cart.lock_expires_at`). If they take too long to check out, the lock expires and they have to refresh the cart before ordering — this stops someone from grabbing a low price and sitting on it while the real price keeps dropping.

## Database

Main tables: `users`, `categories`, `products`, `price_tiers`, `cart`, `orders`, `order_items`, `payments`, `rush_alerts`

Views:
- `active_products` – live price/discount for all in-stock, non-expired products
- `expiring_soon` – products expiring in the next 2 hours
- `order_summaries` – joined order/user/payment data for dashboards

Stored procedures:
- `place_order(customer_id, method)` – checks cart locks and stock, deducts stock, inserts order + payment, all in one transaction (rolls back if anything fails)
- `upsert_product(id, seller_id, ...)` – insert or update a product, only if it belongs to that seller
- `get_revenue_report(days)` – daily revenue breakdown for the admin dashboard

The schema was normalized in steps (UNF → 1NF → 2NF → 3NF/BCNF) — full walkthrough is in the project report. One deliberate exception: `order_items.product_name` is stored as a snapshot rather than looked up, since order rows are never edited and we wanted the order to reflect what the product was called at the time of purchase.

## Project structure

```
├── index.php                  # login / registration
├── config/db.php              # DB connection + constants
├── includes/                  # auth, header/footer, shared helpers
├── customer/                  # browse, cart, checkout, orders, alerts
├── seller/                    # dashboard, product management, orders
├── admin/                     # users, categories, reports
├── api/price.php              # JSON endpoint used by the live price refresh
├── assets/css, assets/js      # styling + countdown/refresh scripts
└── database.sql               # schema, views, procedures, seed data
```

## Running it locally

1. Install XAMPP and start Apache + MySQL.
2. Put the project folder in `htdocs`.
3. Create a database in phpMyAdmin and import `database.sql` (this sets up all tables, views, procedures, and some seed/demo data).
4. Update the DB credentials in `config/db.php` if needed.
5. Open `http://localhost/<folder-name>/index.php`.

Demo accounts for each role are in the seed data in `database.sql`, or you can just register a new account.

## Testing

Tested the stored procedures and views directly with different inputs (normal order, expired cart lock, insufficient stock, product insert/update, revenue report over N days), and then tested the actual user flows in the browser (register, login as each role, add to cart, checkout, seller adding a product, admin viewing reports, price auto-refreshing every 30s). Everything listed passed at time of submission — details and specific test cases are in the project report.

## Known limitations / what we'd improve

- Expired products are filtered out by the view's WHERE clause rather than being marked inactive on a schedule — a MySQL event to flip `is_active` would be cleaner.
- Rush Alerts are checked on page load, not pushed via email/notification.
- No real geolocation — can't filter by "near me."
- The 10-minute cart lock is a fixed value; in a real product this should probably be configurable.
- No automated test suite — testing was manual.

## Team

| Name | Student ID | Main contribution |
|---|---|---|
| Umama Zubair (lead) | 24K-0621 | Seller/admin modules, frontend styling, docs |
| Rameen Ramzan | 24K-0557 | Schema design, ERD, database.sql, stored procedures |
| Amna Sami | 24K-0561 | Customer module (browse, cart, checkout, orders, alerts) |
