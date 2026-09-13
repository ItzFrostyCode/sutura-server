@echo off
echo ========================================================
echo        SUTURA Backend Setup for Windows (XAMPP)
echo ========================================================
echo.

:: 1. Check PHP version
php -v >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] PHP is not recognized in your terminal.
    echo Please add your PHP path (e.g. C:\xampp\php) to your Windows System PATH.
    pause
    exit /b 1
)

echo [OK] PHP is installed.
echo.

:: 2. Check Composer
composer -V >nul 2>&1
if %errorlevel% neq 0 (
    echo [ERROR] Composer is not installed or not in PATH.
    echo Please install Composer from https://getcomposer.org/
    pause
    exit /b 1
)

echo [OK] Composer is installed.
echo.

:: 3. Copy .env if not exists
if not exist .env (
    echo Copying .env.example to .env...
    copy .env.example .env
) else (
    echo [OK] .env already exists.
)
echo.

:: 4. Install Composer dependencies
echo Installing Composer dependencies...
call composer install
if %errorlevel% neq 0 (
    echo [ERROR] Composer install failed.
    echo If it mentions PHP version ^8.3, make sure your XAMPP or PHP is version 8.3 or higher.
    pause
    exit /b 1
)
echo.

:: 5. Generate Application Key
echo Generating application key...
php artisan key:generate
echo.

:: 6. Remind MySQL
echo ========================================================
echo IMPORTANT: Make sure MySQL is running in XAMPP!
echo And that the database 'sutura' is created in phpMyAdmin:
echo    http://localhost/phpmyadmin
echo ========================================================
echo.
pause

:: 7. Run Migrations and Seed
echo Running migrations and seeders...
php artisan migrate --seed
if %errorlevel% neq 0 (
    echo.
    echo [WARNING] Migration failed!
    echo Common fixes:
    echo 1. Did you start MySQL in XAMPP Control Panel?
    echo 2. Did you create the 'sutura' database in phpMyAdmin?
    echo 3. Check that DB_USERNAME=root and DB_PASSWORD= in .env
    pause
    exit /b 1
)
echo.

:: 8. Create Storage Symlink
echo Linking storage directory...
php artisan storage:link
echo.

echo ========================================================
echo        Setup Complete! You can now start the server:
echo        php artisan serve
echo ========================================================
pause
