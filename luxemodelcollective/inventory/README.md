# My Furry Companion — Inventory Dashboard

Self-hosted PHP + MySQL dashboard for managing your pet supplies inventory.

## Tables Used

All tables are prefixed `fc_` so they won't collide with anything in your
existing database:

- `fc_users` — login accounts (Super Admin / Admin)
- `fc_products` — one row per product
- `fc_variants` — variants tied to products
- `fc_upload_history` — record of CSV imports

If you ever want to remove this app, drop those four tables and delete the
directory.

---

## Deployment

You'll do two things: upload files via **WinSCP**, then run two commands via
**SSH**.

### Step 1 — Upload files (WinSCP)

1. Open WinSCP, connect to your server
2. Navigate to wherever you want to host the dashboard
   (e.g., `/var/www/yourdomain.com/public_html/inventory/`)
3. Drag the contents of this `furry_dashboard/` folder into that directory
4. Confirm structure on the server:

   ```
   inventory/
   ├── index.php
   ├── setup.php
   ├── login.php
   ├── logout.php
   ├── dashboard.php
   ├── upload.php
   ├── api.php
   ├── schema.sql
   ├── .htaccess
   ├── README.md
   ├── includes/
   │   ├── config.php       <-- edit this in step 3
   │   ├── db.php
   │   ├── auth.php
   │   ├── header.php
   │   └── footer.php
   └── assets/
       └── style.css
   ```

   **Make sure `.htaccess` uploaded.** WinSCP sometimes hides dotfiles by
   default. In WinSCP: Options → Preferences → Panels → check "Show hidden
   files."

### Step 2 — Import the database schema (SSH)

SSH into your server:

```
ssh your-user@yourdomain.com
```

Navigate to where you uploaded the files:

```
cd /var/www/yourdomain.com/public_html/inventory/
```

Import the schema into your existing database:

```
mysql -u YOUR_DB_USER -p YOUR_DB_NAME < schema.sql
```

It will prompt for the database password. Paste it. If no error, the four
tables were created.

Verify by listing tables:

```
mysql -u YOUR_DB_USER -p YOUR_DB_NAME -e "SHOW TABLES LIKE 'fc_%';"
```

You should see four rows: `fc_products`, `fc_upload_history`, `fc_users`,
`fc_variants`. If you see them, you can move on.

(Optional) Delete `schema.sql` from the server so the file isn't sitting
around — it's already imported and the `.htaccess` blocks public access,
but cleanup is good housekeeping:

```
rm schema.sql
```

### Step 3 — Configure database connection

Open `includes/config.php` in WinSCP (right-click → Edit). Replace the
`CHANGE_ME_*` values:

```php
return [
    'host'     => 'localhost',          // usually fine
    'database' => 'your_db_name',       // your existing database
    'username' => 'your_db_user',
    'password' => 'your_db_password',   // type carefully, don't paste anywhere else
    'charset'  => 'utf8mb4',
];
```

Save and close. WinSCP will upload the modified file back to the server
automatically.

### Step 4 — First-run setup in browser

Visit `https://yourdomain.com/inventory/` (or whatever path you put it at).

The dashboard sees there are no users yet and redirects you to a setup page.
Create your Super Admin account:

- **Username:** 3-50 characters, letters/numbers/dots/dashes/underscores only
- **Password:** at least 10 characters, longer is better

**Critical:** type the password directly into the browser. Do not paste it
into chat windows, emails, screenshots, or text files. Keep it in your own
password manager only.

After submitting, you're logged in. The dashboard is empty — click the
**Upload CSV** button (top-right, orange) and pick your Shopify export.

---

## Daily Use

### Editing a product

Click **Edit** on any row. Modal opens with editable fields for Title,
Retail, Wholesale, Quantity, Status, Promoted, and Description.

### Quick status changes

Click the Promoted or Status dropdown directly in any row. Saves instantly.

### Viewing variants

Click the product name. Read-only modal showing description, pricing
(with calculated profit), and a table of all variants with their SKUs,
options, costs, and prices.

### Deleting a product

Super Admin only. Click **Delete**, confirm in the popup. Removes the
product and all its variants from the database.

> **Note:** Deleting here does NOT affect your eBay listings. This dashboard
> is your local inventory tool, separate from eBay. To delete on eBay, do
> that in Seller Hub.

### Re-uploading a CSV

Upload a new Shopify CSV anytime. Merge logic:

- **New Handle** → creates a new product (Status defaults to Active)
- **Existing Handle** → updates Title, Retail, Wholesale, Image. Preserves
  Status and Promoted settings you've manually changed.
- **Handle in DB but missing from CSV** → left alone. The dashboard never
  auto-deletes. Use the Delete button for that.
- **Variants** → replaced on every upload (Shopify CSV is the authoritative
  source for variants).

---

## Adding More Users (Admins)

There's no user-management UI in this version. To add an Admin user, do it
via SSH:

```
ssh your-user@yourdomain.com
```

Generate a bcrypt password hash for the new user:

```
php -r "echo password_hash('TheirPassword', PASSWORD_BCRYPT, ['cost'=>12]) . PHP_EOL;"
```

Copy the output (starts with `$2y$12$...`). Then:

```
mysql -u YOUR_DB_USER -p YOUR_DB_NAME
```

Once at the `mysql>` prompt:

```
INSERT INTO fc_users (username, password_hash, role)
VALUES ('admin_username', '$2y$12$...the-hash-you-copied...', 'admin');
```

Type `exit;` to leave the MySQL prompt.

Role options: `super_admin` (full access) or `admin` (view + edit only).

---

## Role Permissions

| Action | Super Admin | Admin |
|---|:-:|:-:|
| View dashboard | ✓ | ✓ |
| Edit products (price, description, etc.) | ✓ | ✓ |
| Change Status / Promoted dropdowns | ✓ | ✓ |
| Upload Shopify CSVs | ✓ |   |
| Delete products | ✓ |   |
| Create or remove users | ✓ |   |

---

## Troubleshooting

**"Database connection failed"**
Wrong credentials in `includes/config.php`. Double-check database name, user,
and password. Test from SSH with `mysql -u user -p dbname` — if that works,
the credentials are right.

**Browser shows raw PHP code instead of rendering the page**
PHP isn't installed or the file extension handler isn't set on your web
server. Try visiting `info.php` to confirm — if you don't have PHP, install
it (`apt install php php-mysql` on Debian/Ubuntu, then restart Apache/nginx).

**"CSRF token validation failed"**
Session expired. Refresh the page.

**"Account locked due to failed login attempts"**
5 wrong passwords in a row → 15 minute lockout. Wait it out. If you genuinely
forgot the password, reset via SSH:

```
mysql -u DB_USER -p DB_NAME -e "UPDATE fc_users SET locked_until=NULL, failed_attempts=0 WHERE username='your_username';"
```

If you need to reset the password entirely, generate a new hash (the bcrypt
one-liner from the user-management section) and:

```
mysql -u DB_USER -p DB_NAME -e "UPDATE fc_users SET password_hash='\$2y\$12\$...' WHERE username='your_username';"
```

Note the backslashes before `$` — required in shell context.

**Upload fails silently or with a generic error**
Check PHP error log for the real cause. Usually at `/var/log/php_errors.log`,
`/var/log/apache2/error.log`, or `/var/log/nginx/error.log`. Most common
cause: PHP `upload_max_filesize` set lower than the CSV size.

**Page redirects to setup.php every time you visit**
`fc_users` table is empty. Either you skipped step 2, or the SQL didn't run.
Re-run schema.sql or insert a user manually via SSH (see "Adding More Users").

---

## Security Notes

- **Passwords:** bcrypt with cost factor 12 (years to crack one with current
  hardware).
- **CSRF tokens** on every form and AJAX endpoint.
- **Brute-force lockout** — 5 failed logins → 15 minute lock.
- **Session cookies** httponly, SameSite=Lax, secure (HTTPS-only).
- **.htaccess** denies direct access to `includes/`, `.sql`, and `.md` files,
  and forces HTTPS.
- **HTTPS-only:** the `.htaccess` rewrites all HTTP requests to HTTPS.

Don't share your database password or login passwords. There's no automated
recovery — only manual reset via SSH/MySQL command line.

---

## File Structure

```
inventory/
├── index.php          Entry point - routes to setup/login/dashboard
├── setup.php          First-run Super Admin creation
├── login.php          Auth form
├── logout.php         Session destroy
├── dashboard.php      Main table view, modals, inline editing
├── upload.php         Shopify CSV import (Super Admin only)
├── api.php            JSON endpoints for AJAX (detail/edit/delete/dropdown)
├── schema.sql         Database schema (delete after import)
├── .htaccess          HTTPS redirect, security headers, include protection
├── README.md          This file
├── includes/
│   ├── config.php     Database credentials (edit this)
│   ├── db.php         PDO connection helper
│   ├── auth.php       Session, login, CSRF, lockout
│   ├── header.php     Shared page header with EST clock
│   └── footer.php     Shared page footer
└── assets/
    └── style.css      Dark theme styling
```

---

## Possible Future Additions

- User management UI (add/remove admins from the dashboard itself)
- Bulk edit (change status for multiple products at once)
- Variant-level editing in the modal
- Export back to Shopify or eBay CSV format
- Activity log (who changed what, when)
- Inventory alerts (low stock notifications)

Tell me if any of those would be useful and I'll build them.
