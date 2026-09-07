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
define('APP_URL', 'http://localhost/thirasara-max');
define('APP_VERSION', '1.0.0');

// Database Settings
define('DB_HOST', $_ENV['DB_HOST']);
define('DB_USER', $_ENV['DB_USER']);
define('DB_PASS', $_ENV['DB_PASS']);
define('DB_NAME', $_ENV['DB_NAME']);
define('DB_PORT', $_ENV['DB_PORT']);

// Session Initialization
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Business Settings
define('DEFAULT_CURRENCY', 'LKR');
define('LOW_STOCK_THRESHOLD', 5);
define('DEFAULT_WARRANTY_MONTHS', 6);