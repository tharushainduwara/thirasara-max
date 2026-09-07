<?php

// Load Composer
require_once __DIR__ . '/../vendor/autoload.php';

// Load .env 
use Dotenv\Dotenv; 

$dotenv = Dotenv::createImmutable( 
    dirname(__DIR__) 
); 

$dotenv->load();

// Application Constants
define('APP_NAME', 'Thirasara Max Mobile');
define('APP_TAGLINE', 'Business Automation System');
define('APP_URL', 'http://localhost/thirasara-max-mobile');
define('APP_VERSION', '1.0.0');

// Database Settings
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_NAME', 'thirasara_max_db');
define('DB_PORT', '3306');

// Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Business Settings
define('DEFAULT_CURRENCY', 'LKR');
define('LOW_STOCK_THRESHOLD', 5);
define('DEFAULT_WARRANTY_MONTHS', 6);