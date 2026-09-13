# How to Run SUTURA (Shop Owner System)

SUTURA consists of two separate projects running **at the same time**:

- `sutura-server` — Laravel API → `http://127.0.0.1:8000`
- `sutura-client` — Next.js dashboard → `http://localhost:3000`

**Always start the backend first, then the frontend.**

---

# 🪟 WINDOWS SETUP (Using XAMPP)

### Requirements
- **PHP 8.3+** (`php -v` to check)
  - *Notice*: Laravel 13 strictly requires PHP 8.3 or higher. If your XAMPP has PHP 8.1 or 8.2, download XAMPP with PHP 8.3 from [apachefriends.org](https://www.apachefriends.org) or install PHP 8.3 from [windows.php.net](https://windows.php.net/download/).
  - Ensure PHP is added to your Windows Environment System PATH (e.g. `C:\xampp\php`).
- **Composer** (`composer -V` to check)
- **Node.js 20+** (`node -v` to check)
- **XAMPP** (used for MySQL & phpMyAdmin)

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
- **PHP 8.3+**, **Composer**, **Node.js 20+**
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
| "Root composer.json requires php ^8.3" | Your XAMPP / PHP version is outdated (8.1 or 8.2). Install XAMPP with PHP 8.3+ or download PHP 8.3 directly. |
| "Specified key was too long; max key length is 1000 bytes" | Already resolved by `Schema::defaultStringLength(191)` in `AppServiceProvider.php`. |
| "could not find driver (Connection: mysql)" | Open `C:\xampp\php\php.ini`, uncomment `extension=pdo_mysql`, save and restart terminal. |
| "Access denied for user 'root'@'localhost'" | Ensure `DB_PASSWORD=` is blank in `.env` if using default XAMPP. |
| "Port 8000 or 3000 already in use" | Kill the stuck process:<br>• Windows: `netstat -ano \| findstr :8000` then `taskkill /PID <PID> /F`<br>• Mac: `lsof -i :8000` then `kill -9 <PID>` |
| Images not loading | Run `php artisan storage:link` (with Developer Mode ON in Windows). |
