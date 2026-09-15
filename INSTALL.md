# SUCFRMS — Local Setup Guide

**SUC Faculty Reclassification Management System**
Built with PHP 8.2, MySQL 5.7+ / MariaDB 10.3+, and Bootstrap 5.

---

## Requirements

| Software | Version | Download |
|----------|---------|----------|
| XAMPP    | 8.2.x (includes Apache + MySQL + PHP) | https://www.apachefriends.org |
| Web Browser | Chrome, Edge, or Firefox (latest) | — |

> **No Node.js required.** This is a pure PHP/MySQL application.
> All front-end assets (icons, Chart.js, and the app's own CSS/JS) are self-hosted inside the project —
> no internet connection or CDN access is needed for the app to load and look right.

---

## 1. Install XAMPP

1. Download and install XAMPP from https://www.apachefriends.org
2. During installation, make sure **Apache** and **MySQL** components are selected.
3. After installation, open the **XAMPP Control Panel** and start both:
   - **Apache**
   - **MySQL**

---

## 2. Copy the Project Files

1. Locate your XAMPP installation folder. The default is:
   - Windows: `C:\xampp\htdocs\`
   - macOS/Linux: `/opt/lampp/htdocs/`

2. Copy the entire `SUCFRMS` folder into `htdocs`:
   ```
   C:\xampp\htdocs\SUCFRMS\
   ```

3. The folder structure should look like this:
   ```
   SUCFRMS/
   ├── admin/
   ├── assets/
   ├── config/
   │   └── db.php          ← database connection settings
   ├── fpdf/
   ├── includes/
   ├── modules/
   ├── pages/
   ├── uploads/            ← created automatically on first use
   ├── database.sql        ← database schema + seed data
   └── index.php
   ```

---

## 3. Create the Database

### Option A — Using phpMyAdmin (recommended)

1. Open your browser and go to: http://localhost/phpmyadmin
2. Click **New** in the left sidebar.
3. Enter the database name: `SUCFRMS`
4. Set collation to: `utf8mb4_unicode_ci`
5. Click **Create**.
6. With the `SUCFRMS` database selected, click the **Import** tab.
7. Click **Choose File** and select:
   ```
   C:\xampp\htdocs\SUCFRMS\database.sql
   ```
8. Click **Go** at the bottom. You should see a success message.

### Option B — Using MySQL Command Line

```bash
mysql -u root -p
```
Then run:
```sql
CREATE DATABASE SUCFRMS CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE SUCFRMS;
SOURCE C:/xampp/htdocs/SUCFRMS/database.sql;
```

---

## 4. Configure the Database Connection

Open the file:
```
C:\xampp\htdocs\SUCFRMS\config\db.php
```

Default settings (works out of the box with a standard XAMPP install):
```php
$host = 'localhost';   // MySQL host — usually localhost
$db   = 'SUCFRMS';    // Database name — must match what you created in Step 3
$user = 'root';        // MySQL username — XAMPP default is root
$pass = '';            // MySQL password — XAMPP default is empty
```

**If your MySQL has a password set**, change `$pass = '';` to:
```php
$pass = 'your_mysql_password';
```

**If you're running MySQL on a different port** (e.g. 3307), change `$host`:
```php
$host = 'localhost:3307';
```

---

## 5. Set Up the Admin Account

The database seed creates a placeholder admin account. You must set a real password before logging in.

1. Make sure Apache and MySQL are running in XAMPP.
2. Open your browser and go to:
   ```
   http://localhost/SUCFRMS/pages/setup_admin.php
   ```
3. Follow the on-screen instructions to set the admin password.
4. **Delete or restrict access to `setup_admin.php` after use** — it should not be publicly accessible.

---

## 6. Access the System

Open your browser and go to:
```
http://localhost/SUCFRMS/
```

Default admin credentials (after running setup_admin.php):
- **Email:** `admin@chmsuft.edu.ph`
- **Password:** *(the password you set in Step 5)*

---

## 7. File Upload Permissions

The system saves uploaded files (school IDs, KRA evidence) to the `uploads/` folder.
XAMPP on Windows handles this automatically. On **Linux/macOS**, you may need to set permissions:

```bash
chmod -R 755 /opt/lampp/htdocs/SUCFRMS/uploads/
chown -R daemon:daemon /opt/lampp/htdocs/SUCFRMS/uploads/
```

---

## 8. Troubleshooting

| Problem | Solution |
|---------|----------|
| `Connection failed: Access denied` | Check `$user` and `$pass` in `config/db.php` |
| `Connection failed: Unknown database 'SUCFRMS'` | You haven't created the database yet — go to Step 3 |
| Browser shows **"Index of /..."** (a plain file listing) instead of the app | You're one folder too deep, or `index.php` isn't in the folder you're opening. Extracting the ZIP usually creates `SUCFRMS/SUCFRMS/...` if you unzip it *into* a folder already named `SUCFRMS`/`sucfrms`. Open the folder you copied into `htdocs` and confirm `index.php` sits directly inside it (next to `config/`, `assets/`, etc.) — if there's a nested `SUCFRMS` folder inside, move everything up one level so `index.php` is at `htdocs\SUCFRMS\index.php`, then visit `http://localhost/SUCFRMS/`. A `.htaccess` in this project also disables folder listing so this shows a normal 404 instead once the files are in the right place. |
| Blank page or 500 error | Enable PHP error display: in `php.ini` set `display_errors = On` |
| Page loads but has no styling / looks unstyled | The CSS failed to load — open the browser console (F12 → Network tab), reload, and check that `assets/css/app.css` returns `200 OK` and not `404`. A `404` almost always means the folder nesting issue above. |
| Uploaded files not saving | Check that `uploads/` folder exists and is writable |
| Port conflict on Apache (port 80) | In XAMPP Control Panel → Apache → Config → change `Listen 80` to `Listen 8080`, then access via `http://localhost:8080/SUCFRMS/` |
| Port conflict on MySQL (port 3306) | In XAMPP Control Panel → MySQL → Config → change port, then update `$host` in `db.php` |
| `Class "PDO" not found` | Enable the `pdo_mysql` extension in `php.ini`: uncomment `extension=pdo_mysql` |

---

## 9. Default Seed Data

The `database.sql` file includes the following seed data out of the box:

- **4 campuses:** CHMSU-Fortune Towne, CHMSU-Binalbagan, CHMSU-Alijis, CHMSU-Talisay
- **1 admin account:** `admin@chmsuft.edu.ph` (password set via setup_admin.php)
- **Full scoring criteria** per DBM-CHED Joint Circular No. 3, s. 2022

---

## 10. Production Deployment Notes

> These steps are only needed if deploying to a live server — skip for local use.

- Change `$host`, `$user`, `$pass` in `config/db.php` to your production database credentials.
- Set `display_errors = Off` in `php.ini`.
- Restrict access to `pages/setup_admin.php` via `.htaccess` or delete it after setup.
- Ensure the `uploads/` directory is outside the web root or protected via `.htaccess` to prevent direct file access.
- Use HTTPS — configure an SSL certificate on your web server.

---

*SUCFRMS — SUC Faculty Reclassification Management System*
*DBM-CHED Joint Circular No. 3, s. 2022 & JC No. 1, s. 2023*
