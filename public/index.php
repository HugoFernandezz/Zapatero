<?php

declare(strict_types=1);

use App\Database;
use Dotenv\Dotenv;
use Slim\Factory\AppFactory;
use Slim\Views\PhpRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

Dotenv::createImmutable($root)->safeLoad();

$debug = ($_ENV['APP_DEBUG'] ?? 'false') === 'true';
$pdo = Database::connect($root . '/' . ($_ENV['DB_PATH'] ?? 'storage/zapatero.sqlite'));

$view = new PhpRenderer($root . '/templates');
$view->setLayout('layout.php');

$app = AppFactory::create();
$app->addRoutingMiddleware();
$app->addErrorMiddleware($debug, true, true);

(require $root . '/src/routes.php')($app, $pdo, $view);

$app->run();
