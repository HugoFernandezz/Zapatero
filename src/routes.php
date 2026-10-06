<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AdminEventController;
use App\Controllers\AdminProductController;
use App\Controllers\AuthController;
use App\Controllers\CatalogController;
use App\Controllers\CartController;
use App\Controllers\CheckoutController;
use App\Controllers\EventApiController;
use App\Controllers\HomeController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\ProductController;
use App\Middleware\AdminAuth;
use App\Repositories\CatalogRepository;
use App\Repositories\EventRepository;
use App\Repositories\CartRepository;
use App\Repositories\ProductAdminRepository;
use App\Repositories\OrderRepository;
use App\Services\AuthService;
use App\Services\CatalogService;
use App\Services\CartService;
use App\Services\CheckoutService;
use App\Services\EventService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\OrderNotifier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\ProductAdminService;
use App\Services\SupportService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Routing\RouteCollectorProxy;
use Slim\Views\PhpRenderer;

return function (App $app, PDO $pdo, PhpRenderer $view): void {
    $root = dirname(__DIR__);

    $catalogService = new CatalogService(new CatalogRepository($pdo));
    $events = new EventService($pdo);
    // Compatible con ProductController (pasa Request) y con CartService (pasa el id de sesión).
    $recordEvent = static fn (string $type, array $payload, mixed $context = null) =>
        $events->record($type, $payload, is_string($context) ? $context : session_id());
    $cartService = new CartService(new CartRepository($pdo), $recordEvent);
    $checkoutService = new CheckoutService($cartService);

    // M4: pedidos, pago simulado, factura por email y back-office.
    $mail = new MailService(
        (string) ($_ENV['MAIL_DRIVER'] ?? 'log'),
        (string) ($_ENV['MAIL_FROM'] ?? 'no-reply@zapatero.test'),
        (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Zapatero'),
        $root . '/storage/mail',
        [
            'host' => (string) ($_ENV['SMTP_HOST'] ?? ''),
            'port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
            'user' => (string) ($_ENV['SMTP_USER'] ?? ''),
            'password' => (string) ($_ENV['SMTP_PASSWORD'] ?? ''),
            'secure' => (string) ($_ENV['SMTP_SECURE'] ?? 'tls'),
            'verify_peer' => (string) ($_ENV['SMTP_VERIFY_PEER'] ?? 'true'),
        ]
    );
    $invoices = new InvoiceService($view);
    $orders = new OrderService($pdo, new OrderRepository($pdo), $events, $invoices, $mail, new OrderNotifier());
    $admin = new AdminController($view, $orders);
    $adminEvents = new AdminEventController($view, new EventRepository($pdo));
    $eventApi = new EventApiController($adminEvents, (string) ($_ENV['EVENTS_API_TOKEN'] ?? ''));
    $adminProducts = new AdminProductController($view, new ProductAdminService(new ProductAdminRepository($pdo), $root . '/public'));
    $auth = new AuthController(
        $view,
        new AuthService($pdo, $_ENV['ADMIN_EMAIL'] ?? null, $_ENV['ADMIN_PASSWORD'] ?? null),
        $cartService
    );

    $app->get('/', [new HomeController($view, $catalogService), 'index']);
    $app->get('/catalogo', [new CatalogController($view, $catalogService), 'index']);
    $app->get('/producto/{slug}', [new ProductController($view, $catalogService, $recordEvent), 'show']);

    $app->get('/carrito', [new CartController($view, $cartService), 'show']);
    $app->post('/carrito/agregar', [new CartController($view, $cartService), 'add']);
    $app->post('/carrito/actualizar', [new CartController($view, $cartService), 'update']);
    $app->post('/carrito/descuento', [new CartController($view, $cartService), 'discount']);
    $checkout = new CheckoutController($view, $checkoutService, $events);
    $app->get('/checkout', [$checkout, 'show']);
    $app->post('/checkout', [$checkout, 'submit']);

    $payment = new PaymentController($view, $checkoutService, new PaymentService(), $orders);
    $app->get('/pago', [$payment, 'show']);
    $app->post('/pago', [$payment, 'submit']);

    $orderPages = new OrderController($view, $orders, $invoices, new SupportService(new OrderRepository($pdo), $events));
    $app->get('/pedido/{code}', [$orderPages, 'show']);
    $app->get('/pedido/{code}/estado', [$orderPages, 'status']);
    $app->post('/pedido/{code}/soporte', [$orderPages, 'support']);
    $app->get('/pedido/{code}/factura', [$orderPages, 'invoice']);
    $app->get('/mis-pedidos', [$orderPages, 'mine']);
    $app->get('/mis-pedidos/estados', [$orderPages, 'statuses']);

    // Login único (cliente o administrador), registro de clientes y cierre de sesión.
    $app->get('/login', [$auth, 'loginForm']);
    $app->post('/login', [$auth, 'login']);
    $app->get('/registro', [$auth, 'registerForm']);
    $app->post('/registro', [$auth, 'register']);
    $app->post('/logout', [$auth, 'logout']);
    $app->get('/admin/login', static fn (Request $req, Response $res): Response => $res->withHeader('Location', '/login')->withStatus(303));

    $app->group('/admin', function (RouteCollectorProxy $group) use ($admin, $adminProducts, $adminEvents): void {
        $group->get('', [$admin, 'home']);
        $group->get('/pedidos', [$admin, 'orders']);
        $group->get('/pedidos/{code}', [$admin, 'order']);
        $group->post('/pedidos/{code}/estado', [$admin, 'changeStatus']);
        $group->post('/pedidos/{code}/factura', [$admin, 'resendInvoice']);

        // CRUD de productos
        $group->get('/eventos', [$adminEvents, 'index']);
        $group->get('/eventos/exportar/{format:json|csv}', [$adminEvents, 'export']);

        $group->get('/productos', [$adminProducts, 'index']);
        $group->get('/productos/nuevo', [$adminProducts, 'createForm']);
        $group->post('/productos', [$adminProducts, 'create']);
        $group->get('/productos/{id:[0-9]+}', [$adminProducts, 'editForm']);
        $group->post('/productos/{id:[0-9]+}', [$adminProducts, 'update']);
        $group->post('/productos/{id:[0-9]+}/eliminar', [$adminProducts, 'delete']);
    })->add(new AdminAuth());

    // API de eventos para la Tarea 2 (§7 del plan).
    $app->get('/api/events', [$eventApi, 'json']);
    $app->get('/api/events.csv', [$eventApi, 'csv']);

    $app->get('/health', function (Request $req, Response $res) use ($pdo) {
        $res->getBody()->write(json_encode([
            'status' => 'ok',
            'php' => PHP_VERSION,
            'sqlite' => $pdo->query('SELECT sqlite_version()')->fetchColumn(),
            'tables' => (int) $pdo->query("SELECT count(*) FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchColumn(),
        ]));

        return $res->withHeader('Content-Type', 'application/json');
    });
};
