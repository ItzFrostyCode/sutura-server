# How to Run SUTURA (Shop Owner System)

SUTURA is two separate projects that run at the same time:

- **`sutura-server`** — the Laravel backend/API (runs on `http://127.0.0.1:8000`)
- **`sutura-client`** — the Next.js frontend/dashboard (runs on `http://localhost:3000`)

These are two **independent** repos — clone each one anywhere you want on your computer, they don't need to be in the same parent folder or even near each other. They talk to each other over the network (`http://127.0.0.1:8000`), not through the filesystem, so there's no required folder structure. The only real requirement is that **both are running at the same time**, each in its own terminal, whenever you're using the app. If the backend isn't running, the dashboard will look broken/stuck loading.

## 0) Clone both repos

Open a terminal and run (this can be the same terminal for both — cloning doesn't need to stay open):

```bash
git clone https://github.com/ItzFrostyCode/sutura-server.git
git clone https://github.com/ItzFrostyCode/sutura-client.git
```

This creates two folders, `sutura-server/` and `sutura-client/`, side by side in whatever directory you ran the commands from. Everything below (`cd sutura-server`, `cd sutura-client`) assumes you're still in that same parent directory.

## Requirements

Install these first if you don't have them:

- **PHP 8.3+** (`php -v` to check)
- **Composer** (`composer -V` to check)
- **Node.js 20+** and **npm** (`node -v` to check)
- **MySQL 8.4+** — matches the thesis's documented tech stack (production runs on
  PlanetScale, a hosted MySQL). Install it locally with Homebrew:
  ```bash
  brew install mysql@8.4
  brew services start mysql@8.4
  ```

## 1) One-time backend setup (`sutura-server`) — Terminal 1

Create the local database once (only needed the first time):

```bash
/opt/homebrew/opt/mysql@8.4/bin/mysql -u root -e "
CREATE DATABASE sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'sutura'@'localhost' IDENTIFIED BY 'sutura_local_dev';
GRANT ALL PRIVILEGES ON sutura.* TO 'sutura'@'localhost';
FLUSH PRIVILEGES;
"
```

Then set up the app:

```bash
cd sutura-server
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
```

`.env.example` already points at `DB_DATABASE=sutura`, `DB_USERNAME=sutura` with
the password above — matching the database you just created. If you later get
a paid PlanetScale plan, just swap `DB_HOST`/`DB_USERNAME`/`DB_PASSWORD`/
`DB_DATABASE` in `.env` for PlanetScale's connection string — nothing else
about the app needs to change, since it's already running on real MySQL.

`--seed` loads demo data: roles, a subscription plan, and a full sample shop (services, staff, jobs, appointments, catalog items, etc.) via `LocalTestSeeder`. This is what makes sure **you and your groupmates see the same demo data**, as long as everyone runs this same command on a fresh database.

Start the backend:

```bash
php artisan serve
```

Leave this terminal running. It serves the API at `http://127.0.0.1:8000`.

## 2) One-time frontend setup (`sutura-client`) — Terminal 2

Open a **second, new terminal** (leave Terminal 1 running `php artisan serve`) and run:

```bash
cd sutura-client
npm install
npm run dev
```

Leave this running too. Open **http://localhost:3000** in your browser — that's the actual app. No `.env` file is needed on the frontend; it already points to `http://127.0.0.1:8000/api/v1` by default.

## 3) Log in as the Shop Owner

Go to `http://localhost:3000/login` and use:

| Role | Email | Password |
|---|---|---|
| Shop Owner | `owner@sutura.com` | `password` |
| Staff | `staff@sutura.com` | `password` |
| Admin | `admin@sutura.com` | `password` |

For this thesis's scope, you only need the **Shop Owner** account — that's the dashboard we've been building (Jobs, Appointments, Catalog, Payments, Staff, Reports, etc.).

## Everyday use after the first setup

`brew services start mysql@8.4` (from setup) keeps MySQL running in the
background permanently — including after a restart — so you don't need to
start it again each session. You just need:

```bash
# Terminal 1
cd sutura-server && php artisan serve

# Terminal 2
cd sutura-client && npm run dev
```

## Why `QUEUE_CONNECTION=sync` in `.env.example`?

Notifications (job status updates, new appointment/order alerts, etc.) are built as **queued** jobs in Laravel — normally that means they get written to a `jobs` table and only actually sent once a separate background process (`php artisan queue:work`) picks them up and runs them.

If you set it to `QUEUE_CONNECTION=database` and never run that separate queue worker, notifications will just silently never fire — nothing crashes, they just quietly sit there forever, which looks like a bug ("bakit walang notification?") but isn't.

`QUEUE_CONNECTION=sync` makes those jobs run **immediately**, in the same request, with no worker process needed. One less terminal to remember to keep open, and one less way for the demo to look broken when it isn't. For a class demo this is the simpler, safer default — `database` only matters if you actually need things to happen in the background without blocking the request (not something this project needs).

## Troubleshooting

- **Dashboard stuck loading / network errors**: make sure `php artisan serve` is still running in its terminal.
- **"SQLSTATE[HY000] [2002] Connection refused" or similar on `migrate`**: MySQL isn't running. Run `brew services start mysql@8.4`.
- **Login fails with "unauthorized" or accounts don't exist**: re-run `php artisan migrate --seed` (add `:fresh` — i.e. `php artisan migrate:fresh --seed` — if the database already has partial/broken data and you want a clean slate).
- **Port already in use**: something else is already running on 8000 or 3000. Stop it, or run `php artisan serve --port=8001` (and update the frontend's API URL if you do).
- **"npm run dev" errors about Node version**: update Node to 20 or newer.
