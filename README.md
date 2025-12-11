# PHP CSV Chat (Line-Style)

A lightweight, real-time chat application built with PHP and raw CSV files. No MySQL/MariaDB required.

## Features

* **Flat-file Database**: Uses CSV as the storage engine.
* **Robust CsvDb Class**:
    * Atomic writes (prevents data corruption).
    * File locking (handles concurrency/race conditions).
    * Standardized API responses.
    * Auto-trim & Data sanitization (prevents CSV injection).
* **Modern UI**: LINE-style chat interface with polling.
* **Security**: XSS protection (frontend) and CSV injection protection (backend).

## Requirements

* PHP 7.4 or higher
* Write permissions for the web server on the project directory.

## Installation

1.  Clone this repository.
2.  Ensure the directory is writable by your web server (e.g., `www-data`).
3.  Open `login.php` in your browser.
4.  Enter any Name and a 4-digit Password to auto-register/login.

## License

MIT License