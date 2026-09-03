# Binary MLM Admin Panel (PHP + MySQL)

PHP admin panel for a **Binary MLM** plan using database **`binarymlm_db`**.

## Requirements

- PHP 8.0+ (PDO MySQL)
- MySQL 5.7+ / MariaDB
- Apache / Nginx (or XAMPP / WAMP / Laragon)

## Setup

### 1. Copy project

Put this folder in your web root, e.g.:

- XAMPP: `C:\xampp\htdocs\mlm-plan`
- Or keep path and point virtual host / alias to this folder

### 2. Install database

**Option A — Installer (recommended)**

1. Start MySQL
2. Open: `http://localhost/mlm-plan/install.php`
3. Enter DB credentials (default: root, empty password)
4. Set admin username/password
5. Click **Install Now**
6. **Delete `install.php`** after success

**Option B — Manual**

```bash
mysql -u root -p < sql/binarymlm_db.sql
```

Then open `install.php` once only to set a real password hash, or update admin password from Settings after fixing login via a one-time PHP `password_hash`.

Update `config/database.php` if needed:

```php
define('DB_HOST', 'localhost');
define('DB_NAME', 'binarymlm_db');
define('DB_USER', 'root');
define('DB_PASS', '');
```

### 3. Login

URL: `http://localhost/mlm-plan/admin/login.php`

Default after installer:

| Field    | Value     |
|----------|-----------|
| Username | `admin`   |
| Password | `admin123`|

## Features

| Module        | Description                                      |
|---------------|--------------------------------------------------|
| Dashboard     | Members, commissions, wallets, today’s joins     |
| Members       | List, search, add, activate/deactivate, profile  |
| Binary Tree   | Genealogy view (left / right legs)               |
| Packages      | CRUD + BV amounts                                |
| Commissions   | Manual add, pay pending, filter by type/status   |
| Withdrawals   | Approve / reject / mark paid                     |
| Reports       | Date-range joins, package sales, top earners     |
| Settings      | Company, commission %, currency, admin password  |

## Folder structure

```
├── admin/           # Admin pages
├── assets/css/      # Styles
├── assets/js/       # Scripts
├── config/          # DB config
├── includes/        # Header / footer
├── sql/             # Schema + seed
├── install.php      # One-time installer
└── index.php        # Redirects to admin login
```

## Notes

- New members under a sponsor are placed on the chosen **left/right** leg (first free slot).
- Referral commission uses `referral_commission_percent` from Settings when a package is selected.
- Sample root member: `MLM00001` / `rootuser` (password set by installer: `member123`).
