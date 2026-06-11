@echo off
echo ===================================
echo   Alke Clothes - XAMPP Setup
echo ===================================

:: Check XAMPP MySQL is running
echo Checking MySQL...
"C:\xampp\mysql\bin\mysql.exe" -u root -e "SELECT 1;" >nul 2>&1
if errorlevel 1 (
    echo ERROR: MySQL is not running. Please start it in XAMPP Control Panel first.
    pause
    exit /b 1
)

echo MySQL OK. Setting up database...
"C:\xampp\mysql\bin\mysql.exe" -u root < "%~dp0schema.sql"
if errorlevel 1 (
    echo ERROR: Database setup failed.
    pause
    exit /b 1
)

echo.
echo ===================================
echo   SUCCESS! Database is ready.
echo ===================================
echo.
echo Now open your browser and go to:
echo   http://localhost/alke
echo.
echo Admin panel:
echo   http://localhost/alke/admin
echo   Username: admin
echo   Password: admin123
echo.
pause
