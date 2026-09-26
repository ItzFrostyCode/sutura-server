# How to Run SUTURA

SUTURA consists of two separate projects running **at the same time**:

- `sutura-server` — Laravel API → `http://127.0.0.1:8000`
- `sutura-client` — Next.js frontend → `http://localhost:3000`

**Always start the backend first, then the frontend.**

> 📖 For full setup instructions (first-time clone), see [README.md](./README.md)

---

# 🪟 WINDOWS SETUP (Using XAMPP)

> [!IMPORTANT]
> This project uses **Laravel 12** which requires **PHP ^8.2** (compatible with XAMPP's stock **PHP 8.2.12** build). If you already have XAMPP with PHP 8.2.12 installed, you're ready — no separate standalone PHP install needed.

### Requirements
- **XAMPP with PHP 8.2.12** — [download here](https://www.apachefriends.org/download.html) (used for PHP **and** MySQL/phpMyAdmin). `php -v` should print `PHP 8.2.12`.
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
> **Don't run `composer update`** on this project — only `composer install`. `composer.lock` is pinned (`config.platform.php` = `8.2.0` in `composer.json`) so dependency versions stay PHP-8.2-compatible.

### 1. Clone the Repositories
```bash
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

### 2. Start MySQL in XAMPP & Create Database
1. Open **XAMPP Control Panel** and click **Start** next to **MySQL** (port 3306).
2. Click **Admin** next to MySQL (opens phpMyAdmin at `http://localhost/phpmyadmin`).
3. Click **New** in the left sidebar, name the database `sutura`, and click **Create**.
   *(Alternatively, run the SQL script in phpMyAdmin SQL tab to create database and user)*:
   ```sql
   CREATE DATABASE IF NOT EXISTS sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```

### 3. Backend Setup (Terminal 1)
Open a terminal in `sutura-server`:
```bash
cd "C:\path\to\sutura-server"
```
You can run the automated script:
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

> **Note on `storage:link`**: Turn **Developer Mode ON** in Windows Settings (*Settings → System → For Developers*) or run CMD as Administrator so Windows allows symlinks.

### 4. Start the Backend
```bash
php artisan serve
```
Leave this terminal running. It will output `Server running on [http://127.0.0.1:8000]`.
*(Note: If port 8000 is occupied by another process, run `php artisan serve --port=8080` instead, and set `NEXT_PUBLIC_API_URL=http://127.0.0.1:8080/api/v1` in `sutura-client/.env.local`).*

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
cd sutura-server
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```
Leave running.

### 4. Frontend Setup (Terminal 2)
```bash
cd sutura-client
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
| Customer | `customer@sutura.com` | `password` |

---

# Common Troubleshooting

| Issue | Solution |
|---|---|
| "Root composer.json requires php ^8.2" | Your XAMPP has PHP 8.0 or 8.1. Reinstall XAMPP choosing the **8.2.12** build from [apachefriends.org](https://www.apachefriends.org/download.html). |
| "Specified key was too long; max key length is 1000 bytes" | Already resolved by `Schema::defaultStringLength(191)` in `AppServiceProvider.php`. |
| "could not find driver (Connection: mysql)" | Open `C:\xampp\php\php.ini`, uncomment `extension=pdo_mysql`, save and restart terminal. |
| "Access denied for user 'root'@'localhost'" | Ensure `DB_PASSWORD=` matches your MySQL setup (blank for default XAMPP). |
| "Port 8000 or 3000 already in use" | Kill the stuck process:<br>• Windows: `netstat -ano \| findstr :8000` then `taskkill /PID <PID> /F`<br>• Mac: `lsof -i :8000` then `kill -9 <PID>` |
| Images not loading | Run `php artisan storage:link` (with Developer Mode ON in Windows). |
| `npm install` fails | Install Node.js LTS v20 from https://nodejs.org/ |
