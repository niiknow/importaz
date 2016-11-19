<?php
// Define path to application directory
defined('APP_PATH')
|| define('APP_PATH', realpath(dirname(__FILE__) . '/../'));
// Define application environment: dev, tst, uat, stg, prd
defined('APP_ENV')
|| define('APP_ENV', (getenv('APP_ENV') ? getenv('APP_ENV') : 'prd'));
// enable debug
if (APP_ENV !== 'prd') {
    ini_set('display_errors', 'On');
    error_reporting(E_ALL | E_STRICT);
}
if (PHP_SAPI === 'cli-server') {
    // To help the built-in PHP dev server, check if the request was actually for
    // something which should probably be served as a static file
    $file = __DIR__ . $_SERVER['REQUEST_URI'];
    if (is_file($file)) {
        return false;
    }
}

$classLoader = require_once APP_PATH . '/vendor/autoload.php';
