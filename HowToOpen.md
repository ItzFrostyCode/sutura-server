# How to Run SUTURA

Two projects, two terminals, running **at the same time**:

- `sutura-server` — Laravel API → `http://127.0.0.1:8000`
- `sutura-client` — Next.js frontend → `http://localhost:3000`

**Start the backend first, then the frontend.**

> 📖 For full setup instructions (first-time clone), see [README.md](./README.md)

---

# 🪟 WINDOWS (Quick Start)

### Requirements
- PHP 8.3+, Composer, Node.js 20+
- **XAMPP** for MySQL → https://www.apachefriends.org/

### 1. Clone
```
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

### 2. Create the database
Open XAMPP → Start MySQL → click **Admin** (phpMyAdmin) → **SQL tab** → paste and run:
```sql
CREATE DATABASE sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sutura'@'localhost' IDENTIFIED BY 'sutura_local_dev';
GRANT ALL PRIVILEGES ON sutura.* TO 'sutura'@'localhost';
FLUSH PRIVILEGES;
```

### 3. Backend setup (Terminal 1)
```cmd
cd "C:\Users\yourname\Desktop\sutura-server"
composer install
copy .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
```
> `storage:link` needs **Developer Mode ON** (Windows Settings → For Developers) or run CMD **as Administrator**.

### 4. Start backend
```cmd
php artisan serve
```
Leave running.

### 5. Frontend (Terminal 2)
```cmd
cd "C:\Users\yourname\Desktop\sutura-client"
npm install
copy .env.example .env.local
npm run dev
```
Open **http://localhost:3000**

### If port is stuck
```cmd
netstat -ano | findstr :8000
taskkill /PID <PID> /F
```

---

# 🍎 macOS (Quick Start)

### Requirements
- PHP 8.3+, Composer, Node.js 20+
- MySQL 8.4 via Homebrew:
```bash
brew install mysql@8.4
brew services start mysql@8.4
```

### 1. Clone
```bash
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

### 2. Create the database
```bash
/opt/homebrew/opt/mysql@8.4/bin/mysql -u root -e "
CREATE DATABASE sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sutura'@'localhost' IDENTIFIED BY 'sutura_local_dev';
GRANT ALL PRIVILEGES ON sutura.* TO 'sutura'@'localhost';
FLUSH PRIVILEGES;
"
```

### 3. Backend setup (Terminal 1)
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

### 4. Frontend (Terminal 2)
```bash
cd sutura-client
npm install
cp .env.example .env.local
npm run dev
```
Open **http://localhost:3000**

### If port is stuck
```bash
lsof -i :8000
kill -9 <PID>
```

---

# Login

| Role | Email | Password |
|---|---|---|
| Shop Owner | `owner@sutura.com` | `password` |
| Staff | `staff@sutura.com` | `password` |
| Admin | `admin@sutura.com` | `password` |
| Customer | `customer@sutura.com` | `password` |

---

# Common Errors

| Error | Fix |
|---|---|
| "Connection refused" on `migrate` | MySQL isn't running — start it (XAMPP / `brew services start mysql@8.4`) |
| "Access denied for user 'sutura'" | `.env`'s `DB_PASSWORD` must be `sutura_local_dev` and the user must exist (re-run Step 2 SQL) |
| Login fails / no accounts | Run `php artisan migrate:fresh --seed` |
| Uploaded images show broken | Run `php artisan storage:link` |
| `storage:link` fails on Windows | Run CMD as Administrator OR enable Developer Mode |
| `php`/`composer` not recognized (Windows) | Add PHP folder (e.g. `C:\xampp\php`) to system PATH, reopen terminal |
| Port already in use | See "If port is stuck" above |
| `npm install` fails | Install Node.js LTS v20 from https://nodejs.org/ |
