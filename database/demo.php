<?php

// Carga datos de DEMOSTRACIÓN (todos ficticios) pasando por los servicios reales de la tienda,
// de modo que pedidos, pagos y eventos son coherentes con lo que generaría un uso normal.
//   php database/demo.php            (no hace nada si ya hay pedidos)
//   php database/demo.php --force    (añade los pedidos de demo aunque ya existan otros)
// Los correos se escriben en la carpeta temporal del sistema: no se envía nada a nadie.

declare(strict_types=1);

use App\Database;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\EventService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\OrderNotifier;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Services\SupportService;
use App\Support\OrderStatus;
use Dotenv\Dotenv;
use Slim\Views\PhpRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$pdo = Database::connect($root . '/' . ($_ENV['DB_PATH'] ?? 'storage/zapatero.sqlite'));

if ((int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() > 0 && !in_array('--force', $argv, true)) {
    echo "Ya hay pedidos en la base de datos; no se añade nada. Usa --force para añadirlos igualmente.\n";
    exit(0);
}

$events = new EventService($pdo);
$recordEvent = static fn (string $type, array $payload, mixed $context = null) =>
    $events->record($type, $payload, is_string($context) ? $context : null);
$cart = new CartService(new CartRepository($pdo), $recordEvent);
$mail = new MailService('log', 'no-reply@zapatero.test', 'Zapatero', sys_get_temp_dir() . '/zapatero-demo-mail');
$orderRepo = new OrderRepository($pdo);
$orders = new OrderService($pdo, $orderRepo, $events, new InvoiceService(new PhpRenderer($root . '/templates')), $mail, new OrderNotifier());
$support = new SupportService($orderRepo, $events);

// Usuario interno que firma los cambios de estado de la demo. Rol admin pero con contraseña aleatoria:
// nadie puede iniciar sesión con él (el administrador real se crea desde ADMIN_EMAIL / ADMIN_PASSWORD del .env).
$pdo->prepare("INSERT OR IGNORE INTO users (email, name, password_hash, role) VALUES ('sistema@zapatero.test', 'Sistema (demo)', :hash, 'admin')")
    ->execute(['hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT)]);
$systemId = (int) $pdo->query("SELECT id FROM users WHERE email = 'sistema@zapatero.test'")->fetchColumn();
$customerId = (int) $pdo->query("SELECT id FROM users WHERE email = 'cliente@zapatero.test'")->fetchColumn();

function variant(PDO $pdo, string $slug, int $size, int $qty): int
{
    $statement = $pdo->prepare(
        'SELECT pv.id FROM product_variants pv JOIN products p ON p.id = pv.product_id
         WHERE p.slug = :slug AND pv.stock >= :qty ORDER BY ABS(pv.size_eu - :size) LIMIT 1'
    );
    $statement->execute(['slug' => $slug, 'qty' => $qty, 'size' => $size]);
    $id = $statement->fetchColumn();
    if ($id === false) {
        throw new RuntimeException("Sin stock de $slug");
    }

    return (int) $id;
}

/** Recorre el embudo completo como lo haría un visitante: ver ficha → carrito → checkout → pedido. */
function buy(PDO $pdo, CartService $cart, EventService $events, OrderService $orders, string $sid, array $lines, array $customer, bool $approved, ?int $userId = null, ?string $code = null): string
{
    $session = ['cart' => [], 'user_id' => $userId, 'user_role' => $userId ? 'customer' : null];
    foreach ($lines as [$slug, $size, $qty]) {
        $product = $pdo->prepare('SELECT id, slug FROM products WHERE slug = :slug');
        $product->execute(['slug' => $slug]);
        $row = $product->fetch();
        $events->record('product.viewed', ['product_id' => (int) $row['id'], 'slug' => $row['slug']], $sid);
        $cart->add($session, variant($pdo, $slug, $size, $qty), $qty, $sid);
    }
    $quote = $cart->summary($session, $code);
    $events->record('checkout.started', ['items' => count($quote['items']), 'subtotal_cents' => $quote['subtotal_cents']], $sid);
    $card = ['approved' => $approved, 'last4' => $approved ? '4242' : '0000'];

    return $orders->placeOrder($quote, $customer, $card, $sid, $userId)['code'];
}

$lucia = ['full_name' => 'Lucía Fernández Prado', 'email' => 'lucia.fernandez@example.test', 'address' => 'Calle del Olmo 12, 3.º B', 'city' => 'Murcia', 'postal_code' => '30001', 'province' => 'Murcia'];
$marcos = ['full_name' => 'Marcos Ortega Ruiz', 'email' => 'marcos.ortega@example.test', 'address' => 'Avenida del Río 48', 'city' => 'Murcia', 'postal_code' => '30008', 'province' => 'Murcia'];
$prueba = ['full_name' => 'Cliente de Prueba', 'email' => 'cliente@zapatero.test', 'address' => 'Calle Mayor 1', 'city' => 'Murcia', 'postal_code' => '30001', 'province' => 'Murcia'];
$elena = ['full_name' => 'Elena Navarro Gil', 'email' => 'elena.navarro@example.test', 'address' => 'Plaza del Carmen 5', 'city' => 'Cartagena', 'postal_code' => '30201', 'province' => 'Murcia'];
$pablo = ['full_name' => 'Pablo Sánchez Vera', 'email' => 'pablo.sanchez@example.test', 'address' => 'Calle Ancha 27', 'city' => 'Lorca', 'postal_code' => '30800', 'province' => 'Murcia'];

// 1) Pedido completo hasta «Enviado» (envío gratis por superar 60 €).
$c1 = buy($pdo, $cart, $events, $orders, 'demo-s1', [['avenida-neon', 40, 1], ['vector-mono', 38, 1]], $lucia, true);
$orders->changeStatus($c1, OrderStatus::PENDING, $systemId, 'sistema@zapatero.test');
$orders->changeStatus($c1, OrderStatus::SHIPPED, $systemId, 'sistema@zapatero.test');

// 2) Pedido con código ZAP5 y gastos de envío, pendiente de preparar.
$c2 = buy($pdo, $cart, $events, $orders, 'demo-s2', [['spray-sunset', 43, 1]], $marcos, true, null, 'ZAP5');
$orders->changeStatus($c2, OrderStatus::PENDING, $systemId, 'sistema@zapatero.test');

// 3) Cliente registrado (descuento CLIENTE10), con incidencia y solicitud de soporte.
$c3 = buy($pdo, $cart, $events, $orders, 'demo-s3', [['liana-verde', 39, 2]], $prueba, true, $customerId);
$orders->changeStatus($c3, OrderStatus::PENDING, $systemId, 'sistema@zapatero.test');
$orders->changeStatus($c3, OrderStatus::INCIDENT, $systemId, 'sistema@zapatero.test', 'Falta el piso en la dirección de entrega');
$support->request($orderRepo->find($c3), ['subject' => 'delivery', 'message' => 'Me han dicho que hay una incidencia con mi pedido, ¿qué necesitáis de mi parte?'], 'demo-s3', $customerId);

// 4) Pago rechazado: el pedido nace cancelado y no descuenta stock.
buy($pdo, $cart, $events, $orders, 'demo-s4', [['pixel-pop', 41, 1]], $elena, false);

// 5) Pedido pagado y cancelado después por el administrador (las unidades vuelven al stock).
$c5 = buy($pdo, $cart, $events, $orders, 'demo-s5', [['arcade-runner', 42, 1], ['bit-sunset', 37, 1]], $pablo, true);
$orders->changeStatus($c5, OrderStatus::CANCELLED, $systemId, 'sistema@zapatero.test', 'Cancelado a petición del cliente');

// Navegación sin compra: visitas y carritos abandonados (alimentan el análisis del embudo).
foreach ([['demo-s6', 'muro-cobalto'], ['demo-s6', 'orquidea-noche'], ['demo-s7', 'prisma-coral'], ['demo-s8', 'jardin-claro'], ['demo-s8', 'modulo-arena']] as [$sid, $slug]) {
    $row = $pdo->query("SELECT id FROM products WHERE slug = '$slug'")->fetch();
    $events->record('product.viewed', ['product_id' => (int) $row['id'], 'slug' => $slug], $sid);
}
$abandoned = ['cart' => []];
$cart->add($abandoned, variant($pdo, 'prisma-coral', 40, 1), 1, 'demo-s7');

echo 'Datos de demostración cargados: ' . (int) $pdo->query('SELECT COUNT(*) FROM orders')->fetchColumn() . ' pedidos, '
    . (int) $pdo->query('SELECT COUNT(*) FROM events')->fetchColumn() . " eventos.\n";
