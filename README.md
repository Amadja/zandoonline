# Zandoonline — Marketplace Starter

A PHP 8.1+ / MySQL starter for a multi-vendor marketplace. Current features include:
- Customer and seller registration
- Secure login/logout
- Login with email address or Ugandan phone number
- Password hashing
- PDO prepared statements
- CSRF protection
- Role-based dashboard access
- Seller profile creation
- CLI-only administrator creation
- Database-backed sample catalog and stock-aware session cart
- Guest checkout with Uganda delivery details and order confirmation
- Customer inbox for order receipt and delivery/payment notifications
- Optional customer-shared map coordinates or Google Maps links on delivery addresses
- Cash on delivery and manual MTN/Airtel Mobile Money confirmation
- Mobile Money payment requests with a four-minute customer approval window
- Admin order queue for payment and fulfillment status updates
- Sequential order processing: pending, processing, ready for pickup, assigned, accepted, picked up, out for delivery, delivered
- Admin operations overview with low-stock alerts and product inventory management
- Private delivery staff accounts, assigned-delivery app, and in-app route map
- Four-digit customer inbox PIN to confirm delivery completion (10-minute expiry, five attempts, 60-second resend cooldown)
- Product detail editing, descriptions, image uploads, and deletion

## Requirements
- PHP 8.1 or newer
- MySQL 8 (or compatible MariaDB)
- Apache with `mod_rewrite` enabled (optional; direct PHP URLs work too)
- XAMPP is suitable for local development

## Install with XAMPP
1. Extract this folder into `C:\xampp\htdocs\zandoonline`.
2. Start Apache and MySQL in XAMPP.
3. Open phpMyAdmin and create a database named `zandoonline`.
4. Import `database/schema.sql`, then import `database/marketplace.sql`.
5. If upgrading an existing database created before delivery location sharing, import `database/add_delivery_location.sql` once.
6. If upgrading an existing database created before phone login, import `database/add_user_phone.sql` once.
7. For existing databases, import `database/add_customer_inbox.sql` once.
8. For existing databases, import `database/add_delivery_role.sql` once after `add_customer_inbox.sql`.
9. For existing databases, import `database/simplify_order_lifecycle.sql` once after the delivery-role migration.
10. For existing databases, import `database/add_delivery_confirmation_pin.sql` once.
11. For existing databases, import `database/add_mobile_money_prompt.sql` once.
12. Copy `config/config.example.php` to `config/config.php`.
13. Edit `config/config.php` with your database username/password.
14. Open `http://localhost/zandoonline/public/`.

For the cleanest setup, configure Apache's document root to the project's `public/` directory. If you keep the project under `htdocs`, the included `public/.htaccess` works for the public folder, but do not expose `config/`, `app/`, or `database/` as web-accessible directories in production.

## Create the first administrator
Open a terminal in the project root and run:

```bash
php scripts/create_admin.php "Marketplace Admin" admin@example.com "Use-A-Strong-Unique-Password" "0770 123 456"
```

The phone argument is optional. The script refuses duplicate email addresses or phone numbers. Run it from the command line only; do not put it in the public folder.

## Important deployment notes
- Change `APP_ENV` to `production` and `APP_DEBUG` to `false`.
- Use HTTPS.
- Keep `config/config.php` outside the public web root where possible.
- Set restrictive permissions on `storage/`.
- Configure PHP error logging; never display errors to customers.
- Add rate limiting, email verification, password reset, audit logging and stronger seller KYC workflows before public launch.
- The starter delivery fee is UGX 5,000 per order and can be changed in `app/cart.php`.
- Ensure `public/assets/uploads/products/` is writable by PHP for product image uploads. JPEG, PNG, and WebP files up to 5 MB are accepted.
- Admins create delivery-only accounts from the Delivery team console; delivery staff cannot register publicly. The delivery workspace can be installed as a PWA.
- Embedded delivery maps use OpenStreetMap tiles and the public OSRM demo router. Map and routing need internet access; configure a production routing provider or host your own router before launch.
- Cash on delivery is recorded as unpaid until fulfillment. Mobile Money orders are recorded as pending; connect a payment gateway before collecting online payments.
- This is a development foundation, not a finished production marketplace or payment system.
