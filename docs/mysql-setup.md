# MySQL and MariaDB setup

The application now uses Laravel's `mysql` connection to XAMPP's MariaDB service at `127.0.0.1:3306`, database `icrc_orientation`, with `utf8mb4_unicode_ci` encoding. Both `.env` and `.env.example` are configured for this database. Existing `icrc_main` and `icrc_official` databases are unrelated and were not modified.

## Local use

Start MySQL from the XAMPP control panel before starting the application. XAMPP labels its MariaDB service as MySQL. For the current local XAMPP configuration, the database username is `root` with an empty password. This is a local development setting; create a dedicated user and supply its password in `.env` for deployment.

For a new installation, create an empty database:

```sql
CREATE DATABASE icrc_orientation CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Then run:

```powershell
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan icrc:create-admin
php artisan serve
```

Do not replace an existing `.env` or regenerate an existing application's key during an upgrade. Preserve its secrets and set `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=3306`, `DB_DATABASE=icrc_orientation`, and the appropriate `DB_USERNAME` and `DB_PASSWORD`. Run `php artisan config:clear` after changing connection settings.

## Existing records

The initial SQLite records were copied into the new MySQL database with their original IDs, using a transaction and foreign key checks. The six weeks, 30 days, 187 timetable entries and 16 supplement proposals were preserved. No user accounts existed at migration time. Session and cache records were not transferred; sign in again if necessary. Original JSON source values were compared after copying.

The original `database/database.sqlite` remains available but is no longer used by the running application. A second copy is in the private `storage/app/private/backups/before-mysql-20261027.sqlite` location. Protect both files as database backups and never expose the project root through the web server. The web document root must be `public`.

## Tests

The default `php artisan test` uses isolated in-memory SQLite for quick tests. The additional MySQL configuration uses a separate disposable database, **never the application database**:

```sql
CREATE DATABASE icrc_orientation_testing CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

```powershell
php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml
```

Tests recreate tables inside `icrc_orientation_testing`. Override the test configuration credentials if your local database requires a password. Do not point this configuration at any database containing records you need to retain.

## Backups and deployment

Back up the database with `mysqldump --single-transaction` and back up private uploads and the application key separately. Use a password prompt or protected client configuration rather than putting production passwords in command history. Restore into an empty database, restore uploads, set `.env` credentials, run migrations, and verify login and record counts before serving users. Test restores regularly. Configure HTTPS, `APP_DEBUG=false`, secure cookies, an application-specific database user and queued mail before production use.
