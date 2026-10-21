# Data snapshot

A copy of the app's data (every table, as JSON in `data/`) and of the uploaded files (`storage/`), so another computer
can have exactly the same shops, appointments, orders, photos and accounts. Made with `php artisan app:snapshot-export`.

Not included, on purpose: sign-in tokens, sessions, caches and queues (they belong to one computer). Sign in again on the
new computer with the same email and password.

## On the other computer (macOS or Windows)

1. `git pull` in both `sutura-server` and `sutura-client`.
2. Server: `composer install`, copy `.env.example` to `.env` (Windows: `copy .env.example .env`) and set the database
   (`DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD`) and `APP_URL` (e.g. `http://localhost:8000`), then `php artisan key:generate`
   if `.env` is new. Create the empty database first (e.g. `CREATE DATABASE sutura;`).
3. `php artisan migrate:fresh --seed` (an empty or throwaway database: builds the tables, then the seeder loads this
   snapshot, copies the files and links `public/storage`). To keep the database and just replace the rows, use
   `php artisan app:snapshot-import` instead. Either way, links that pointed at the old computer's `APP_URL` are
   rewritten to this one's.
4. Client: `npm install`, copy `.env.example` to `.env.local` and check the API address, then `npm run dev`.

Windows: `php artisan storage:link` needs a terminal opened as Administrator (or Developer Mode on). If photos do not
show, run it once in such a terminal.

## Taking a new snapshot

`php artisan app:snapshot-export` replaces this folder with the current data. Commit it so the others get it with
`git pull` and `php artisan migrate:fresh --seed`.

`SEED_FROM=demo php artisan migrate:fresh --seed` still seeds the older generated demo shop instead of this snapshot.
On a production server the seeder loads only roles and plans unless `ALLOW_DEMO_SEED=true`.
