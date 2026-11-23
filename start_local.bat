@echo off
title Axonite Expense Tracking System - Local Server
cd /d "%~dp0"

echo ========================================================
echo Starting Axonite Expense Tracking System (PHP + MySQL)
echo ========================================================

:: Check if MySQL is already listening on port 3306
netstat -ano | findstr ":3306 " >nul
if %errorlevel% neq 0 (
    echo Starting MySQL Server from XAMPP...
    start "" /b "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini" --standalone
    timeout /t 2 /nobreak >nul
) else (
    echo MySQL Server is already running on port 3306.
)

echo.
echo ========================================================
echo SERVER STARTED SUCCESSFULLY!
echo Local URL: http://127.0.0.1:8000/login
echo ========================================================
echo.

:: Automatically open browser
start "" "http://127.0.0.1:8000/login"

:: Start PHP web server
"C:\xampp\php\php.exe" -S 127.0.0.1:8000 router.php
pause
