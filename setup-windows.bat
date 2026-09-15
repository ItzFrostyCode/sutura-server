@echo off
setlocal enabledelayedexpansion
echo ========================================================
echo        SUTURA Backend Setup for Windows (XAMPP)
echo ========================================================
echo.

:: 1. Check PHP is on PATH at all
php -v >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] PHP is not recognized in your terminal.
    echo Please add your PHP path ^(e.g. C:\xampp\php^) to your Windows System PATH.
    pause
    exit /b 1
)

:: 2. Check the ACTUAL PHP version, not just that "php" runs.
:: This project requires PHP 8.2+ ^(Laravel 12^). XAMPP for Windows' stock
:: PHP 8.2.12 build satisfies this out of the box -- if your XAMPP is on
:: 8.0/8.1 instead, "php -v" succeeds here but `composer install` fails
:: later with a much more confusing platform-requirement error, well after
:: the user thinks setup is already working.
for /f "tokens=1,2 delims=." %%a in ('php -r "echo PHP_VERSION;"') do (
    set PHP_MAJOR=%%a
    set PHP_MINOR=%%b
)
for /f "delims=" %%v in ('php -r "echo PHP_VERSION;"') do set PHP_FULL_VERSION=%%v

set PHP_OK=1
if %PHP_MAJOR% LSS 8 set PHP_OK=0
if %PHP_MAJOR% EQU 8 if %PHP_MINOR% LSS 2 set PHP_OK=0

if !PHP_OK! EQU 0 (
    echo [ERROR] Detected PHP !PHP_FULL_VERSION! -- this project requires PHP 8.2 or higher ^(Laravel 12^).
    echo.
    echo Your XAMPP is likely on an older bundled PHP ^(8.0 or 8.1^). Fix:
    echo   Reinstall XAMPP choosing the 8.2.12 build from https://www.apachefriends.org/download.html
    echo After fixing, close and reopen this terminal, then re-run this script.
    pause
    exit /b 1
)

echo [OK] PHP !PHP_FULL_VERSION! detected ^(matches this project's ^php: ^8.2^ requirement^).
echo.

:: 3. Check Composer
composer -V >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Composer is not installed or not in PATH.
    echo Please install Composer from https://getcomposer.org/
    pause
    exit /b 1
)
echo [OK] Composer is installed.
echo.

:: 4. Check required PHP extensions are actually enabled.
:: XAMPP ships these but frequently with a leading ";" in php.ini disabling
:: them -- "php -v" says nothing about this, so the first symptom is usually
:: a cryptic "could not find driver" or "call to undefined function" deep
:: inside migrate/seed. Checking up front turns that into one clear message.
php -m > "%TEMP%\sutura_php_modules.txt" 2>nul
set MISSING_EXT=
for %%E in (pdo_mysql mbstring fileinfo curl openssl zip gd) do (
    findstr /I /C:"%%E" "%TEMP%\sutura_php_modules.txt" >nul
    if errorlevel 1 set MISSING_EXT=!MISSING_EXT! %%E
)
del "%TEMP%\sutura_php_modules.txt" >nul 2>&1

if not "!MISSING_EXT!"=="" (
    echo [ERROR] Missing required PHP extensions:!MISSING_EXT!
    echo.
    echo Open C:\xampp\php\php.ini in Notepad and remove the leading ";" from these lines:
    for %%E in (!MISSING_EXT!) do echo    extension=%%E
    echo Then restart your terminal and re-run this script.
    pause
    exit /b 1
)
echo [OK] Required PHP extensions ^(pdo_mysql, mbstring, fileinfo, curl, openssl, zip, gd^) are enabled.
echo.

:: 5. Copy .env if not exists
if not exist .env (
    echo Copying .env.example to .env...
    copy .env.example .env >nul
) else (
    echo [OK] .env already exists.
)
echo.

:: 6. Install Composer dependencies
echo Installing Composer dependencies...
call composer install
if %errorlevel% neq 0 (
    echo [ERROR] Composer install failed. See the error above.
    pause
    exit /b 1
)
echo.

:: 7. Generate Application Key
echo Generating application key...
php artisan key:generate
echo.

:: 8. Make sure MySQL is reachable, then try to auto-create the database.
where mysql >nul 2>&1
if %errorlevel% equ 0 (
    echo Attempting to auto-create the 'sutura' database...
    mysql -u root -e "CREATE DATABASE IF NOT EXISTS sutura CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" 2>nul
    if !errorlevel! equ 0 (
        echo [OK] Database 'sutura' is ready.
    ) else (
        echo [WARNING] Could not reach MySQL to auto-create the database.
        echo Make sure MySQL is started in the XAMPP Control Panel, then create it
        echo manually at http://localhost/phpmyadmin ^(New -^> type "sutura" -^> Create^).
    )
) else (
    echo [INFO] 'mysql' command not found in PATH -- create the database manually:
    echo    Open http://localhost/phpmyadmin, click New, type "sutura", click Create.
)
echo.
echo ========================================================
echo IMPORTANT: Confirm MySQL is running in XAMPP before continuing!
echo ========================================================
echo.
pause

:: 9. Run Migrations and Seed
echo Running migrations and seeders...
php artisan migrate --seed
if %errorlevel% neq 0 (
    echo.
    echo [WARNING] Migration failed!
    echo Common fixes:
    echo 1. Did you start MySQL in XAMPP Control Panel?
    echo 2. Does the 'sutura' database actually exist ^(check phpMyAdmin^)?
    echo 3. Check that DB_USERNAME=root and DB_PASSWORD= in .env
    pause
    exit /b 1
)
echo.

:: 10. Create Storage Symlink
echo Linking storage directory...
php artisan storage:link
if %errorlevel% neq 0 (
    echo [WARNING] storage:link failed -- uploaded images/files won't display.
    echo This is usually because Windows Developer Mode is off. Fix:
    echo    Settings -^> System -^> For Developers -^> turn ON Developer Mode
    echo Then re-run: php artisan storage:link
)
echo.

echo ========================================================
echo        Setup Complete! You can now start the server:
echo        php artisan serve
echo ========================================================
pause
