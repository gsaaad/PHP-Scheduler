<?php

// Simple Autoloader
spl_autoload_register(function ($class) {
    $prefix = 'App\\';
    $base_dir = __DIR__ . '/../src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

use App\Database;
use App\Controllers\RobotController;

$db = (new Database())->getConnection();
$requestMethod = $_SERVER["REQUEST_METHOD"];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$uri = explode('/', $uri);

// Simple Routing
if ($uri[1] === 'api' && $uri[2] === 'robots') {
    $controller = new RobotController($db);
    if ($requestMethod === 'GET') {
        $controller->index();
    } elseif ($requestMethod === 'POST') {
        $controller->store();
    }
} else {
    header("HTTP/1.1 404 Not Found");
    exit();
}
