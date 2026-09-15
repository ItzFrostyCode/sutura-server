# How to Run SUTURA (Shop Owner System)

SUTURA consists of two separate projects running **at the same time**:

- `sutura-server` — Laravel API → `http://127.0.0.1:8000`
- `sutura-client` — Next.js dashboard → `http://localhost:3000`

**Always start the backend first, then the frontend.**

---

# 🪟 WINDOWS SETUP (Using XAMPP)

> [!IMPORTANT]
> **Correction, and a project-wide fix:** an earlier version of this doc said to "download XAMPP with PHP 8.3" — **that build never existed.** XAMPP for Windows only ever shipped PHP 8.0.30, 8.1.25, or 8.2.12 (checked directly against [apachefriends.org](https://www.apachefriends.org)'s download page). Rather than force every Windows teammate to install a standalone PHP outside XAMPP, this project **downgraded from Laravel 13 to Laravel 12** (which only requires PHP ^8.2, not ^8.3 — [confirmed against Laravel's own release notes](https://laravel.com/docs/12.x/releases)) specifically so XAMPP's stock **PHP 8.2.12** build works out of the box. If you already have XAMPP with PHP 8.2.12 installed, you're done — no separate PHP install needed.

### Requirements
- **XAMPP with PHP 8.2.12** — [download it here](https://www.apachefriends.org/download.html) if you don't have it (used for PHP **and** MySQL/phpMyAdmin). `php -v` should print `PHP 8.2.12`.
  - If your XAMPP has PHP 8.0 or 8.1 instead, reinstall XAMPP choosing the **8.2.12** build specifically — 8.0/8.1 are still too old for Laravel 12.
  - Ensure PHP is added to your Windows Environment System PATH (e.g. `C:\xampp\php`).
- **Composer** (`composer -V` to check)
- **Node.js 20+** (`node -v` to check)

### Important: Enable Required PHP Extensions
In `C:\xampp\php\php.ini`, make sure the following lines do **NOT** have a semicolon `;` in front:
```ini
extension=pdo_mysql
extension=mysqli
extension=fileinfo
extension=curl
extension=mbstring
extension=openssl
extension=zip
extension=gd
```

> [!NOTE]
> **Don't run `composer update`** on this project — only `composer install`. `composer.lock` is pinned (`config.platform.php` = `8.2.0` in `composer.json`) so dependency versions stay PHP-8.2-compatible. Running `composer update` on a machine with a PHP newer than 8.2 can silently re-resolve packages to versions that require that newer PHP — the exact class of bug this pin exists to prevent (it happened once already, at the 8.3→8.4 boundary, before this project moved to Laravel 12).

### 1. Clone the Repositories
Run in your desired folder (e.g. `C:\Projects`):
```bash
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

### 2. Start MySQL in XAMPP & Create Database
1. Open **XAMPP Control Panel** and click **Start** next to **MySQL** (port 3306).
2. Click **Admin** next to MySQL (opens phpMyAdmin at `http://localhost/phpmyadmin`).
3. Click **New** in the left sidebar, name the database `sutura`, and click **Create**.
   *(Default user is `root` with no password — `.env.example` already matches this!)*

### 3. Backend Setup (Terminal 1)
Open a terminal in `sutura-server`:
```bash
cd "C:\path\to\sutura-server"
```
You can either run the automated script:
```bash
setup-windows.bat
```
Or run the commands manually:
```bash
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```
> **Note on `storage:link`**: Turn **Developer Mode ON** in Windows Settings (*Settings → System → For Developers*) or run your terminal as Administrator so Windows allows symlinks.

### 4. Start the Backend
```bash
php artisan serve
```
Leave this terminal running. It will output `Server running on [http://127.0.0.1:8000]`.

### 5. Frontend Setup (Terminal 2 — new window)
Open a second terminal in `sutura-client`:
```bash
cd "C:\path\to\sutura-client"
npm install
copy .env.example .env.local
npm run dev
```
Leave this terminal running. Open **http://localhost:3000** in your browser.

---

# 🍎 macOS SETUP

### Requirements
- **PHP 8.2+**, **Composer**, **Node.js 20+**
- **MySQL 8.4** via Homebrew:
```bash
brew install mysql@8.4
brew services start mysql@8.4
```

### 1. Clone
```bash
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

### 2. Create the Database
```bash
/opt/homebrew/opt/mysql@8.4/bin/mysql -u root -e "CREATE DATABASE IF NOT EXISTS sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
```

### 3. Backend Setup (Terminal 1)
```bash
cd /path/to/sutura-server
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

### 4. Frontend Setup (Terminal 2)
```bash
cd /path/to/sutura-client
npm install
cp .env.example .env.local
npm run dev
```
Open **http://localhost:3000** in your browser.

---

# Test Logins

| Role | Email | Password |
|---|---|---|
| Shop Owner | `owner@sutura.com` | `password` |
| Staff | `staff@sutura.com` | `password` |
| Admin | `admin@sutura.com` | `password` |

---

# Common Troubleshooting

| Issue | Solution |
|---|---|
| "Root composer.json requires php ^8.2" | Your XAMPP has PHP 8.0 or 8.1. Reinstall XAMPP choosing the **8.2.12** build from [apachefriends.org](https://www.apachefriends.org/download.html). |
| "Specified key was too long; max key length is 1000 bytes" | Already resolved by `Schema::defaultStringLength(191)` in `AppServiceProvider.php`. |
| "could not find driver (Connection: mysql)" | Open `C:\xampp\php\php.ini`, uncomment `extension=pdo_mysql`, save and restart terminal. |
| "Access denied for user 'root'@'localhost'" | Ensure `DB_PASSWORD=` is blank in `.env` if using default XAMPP. |
| "Port 8000 or 3000 already in use" | Kill the stuck process:<br>• Windows: `netstat -ano \| findstr :8000` then `taskkill /PID <PID> /F`<br>• Mac: `lsof -i :8000` then `kill -9 <PID>` |
| Images not loading | Run `php artisan storage:link` (with Developer Mode ON in Windows). |
