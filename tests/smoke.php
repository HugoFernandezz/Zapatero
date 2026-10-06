<?php

// Test de humo sin dependencias adicionales:  composer test   (o: php tests/smoke.php)
// Crea una base de datos temporal y comprueba el flujo de compra, los estados, los eventos y el soporte.

declare(strict_types=1);

use App\Controllers\AdminEventController;
use App\Database;
use App\Repositories\CartRepository;
use App\Repositories\EventRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\EventService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\OrderNotifier;
use App\Services\OrderService;
use App\Services\SupportService;
use App\Support\OrderStatus;
use Slim\Views\PhpRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$tmp = sys_get_temp_dir() . '/zapatero-test-' . bin2hex(random_bytes(4));
mkdir($tmp);
$pdo = Database::connect($tmp . '/test.sqlite');

$failures = 0;
$check = static function (bool $condition, string $message) use (&$failures): void {
    echo ($condition ? '  OK    ' : '  FALLO ') . $message . PHP_EOL;
    if (!$condition) {
        $failures++;
    }
};
$scalar = static fn (string $sql) => $pdo->query($sql)->fetchColumn();

$events = new EventService($pdo);
$recordEvent = static fn (string $type, array $payload, mixed $context = null) => $events->record($type, $payload, is_string($context) ? $context : null);
$cart = new CartService(new CartRepository($pdo), $recordEvent);
$orderRepo = new OrderRepository($pdo);
$mail = new MailService('log', 'no-reply@zapatero.test', 'Zapatero', $tmp . '/mail');
$orders = new OrderService($pdo, $orderRepo, $events, new InvoiceService(new PhpRenderer($root . '/templates')), $mail, new OrderNotifier());
$support = new SupportService($orderRepo, $events);

echo "Datos maestros\n";
$check((int) $scalar('SELECT COUNT(*) FROM products') >= 8, 'hay al menos 8 productos');
$check((int) $scalar('SELECT COUNT(*) FROM collections') >= 2, 'hay categorías (colecciones)');
$check((int) $scalar("SELECT COUNT(*) FROM users WHERE email = 'cliente@zapatero.test'") === 1, 'existe el cliente de prueba');

echo "Flujo de compra\n";
$variant = $pdo->query('SELECT pv.id, pv.stock FROM product_variants pv WHERE pv.stock >= 2 LIMIT 1')->fetch();
$session = ['cart' => []];
$cart->add($session, (int) $variant['id'], 2, 'sess-test');
$quote = $cart->summary($session);
$check($quote['total_cents'] === $quote['subtotal_cents'] + $quote['shipping_cents'], 'total = subtotal + envío (sin descuento)');
$check($quote['tax_cents'] === (int) round($quote['total_cents'] * 21 / 121), 'el IVA (21 %) está incluido en el total');
$shipping = ['full_name' => 'Persona Ficticia', 'email' => 'persona@example.test', 'address' => 'Calle Falsa 1', 'city' => 'Murcia', 'postal_code' => '30001', 'province' => 'Murcia'];
$result = $orders->placeOrder($quote, $shipping, ['approved' => true, 'last4' => '4242'], 'sess-test');
$code = $result['code'];
$check(preg_match('/^ZAP-\d{8}-\d{4}$/', $code) === 1, "se genera un identificador único ($code)");
$check($pdo->query("SELECT status FROM orders WHERE code = '$code'")->fetchColumn() === OrderStatus::PAID, 'el pedido queda en «pagado simulado»');
$check((int) $scalar('SELECT stock FROM product_variants WHERE id = ' . (int) $variant['id']) === (int) $variant['stock'] - 2, 'se descuenta el stock');
$check((int) $scalar('SELECT COUNT(*) FROM order_items') === 1 && (int) $scalar('SELECT COUNT(*) FROM payments') === 1, 'hay línea de pedido y pago simulado');

echo "Pago rechazado\n";
$session2 = ['cart' => []];
$cart->add($session2, (int) $variant['id'], 1, 'sess-test-2');
$rejected = $orders->placeOrder($cart->summary($session2), $shipping, ['approved' => false, 'last4' => '0000'], 'sess-test-2');
$check($pdo->query("SELECT status FROM orders WHERE code = '{$rejected['code']}'")->fetchColumn() === OrderStatus::CANCELLED, 'tarjeta rechazada → pedido cancelado');

echo "Estados\n";
try {
    $orders->changeStatus($code, OrderStatus::SHIPPED, 1, 'test');
    $check(false, 'no debería permitir pasar de «pagado» a «enviado»');
} catch (DomainException) {
    $check(true, 'bloquea el salto «pagado» → «enviado»');
}
$adminId = (int) $scalar("SELECT id FROM users LIMIT 1");
$check($orders->changeStatus($code, OrderStatus::PENDING, $adminId, 'test') === true, 'pendiente de preparación: se avisa al cliente por email');
$check($orders->changeStatus($code, OrderStatus::SHIPPED, $adminId, 'test') === true, 'enviado: se avisa al cliente por email');
$check((int) $scalar("SELECT COUNT(*) FROM events WHERE type = 'status_email.sent'") === 2, 'cada aviso queda registrado como evento');

echo "Soporte\n";
$order = $orderRepo->find($code);
$bad = $support->request($order, ['subject' => 'nada', 'message' => 'corto'], 'sess-test');
$check(isset($bad['errors']['subject'], $bad['errors']['message']), 'valida motivo y longitud del mensaje');
$good = $support->request($order, ['subject' => 'size', 'message' => 'Necesito cambiar la talla por una más grande.'], 'sess-test');
$check($good['errors'] === [] && $good['ticket_id'] !== null, 'registra la solicitud de soporte');
$check((int) $scalar("SELECT COUNT(*) FROM events WHERE type = 'support.requested'") === 1, 'genera el evento support.requested');
for ($i = 0; $i < SupportService::MAX_TICKETS_PER_ORDER; $i++) {
    $last = $support->request($order, ['subject' => 'other', 'message' => 'Otra consulta de prueba número ' . $i], 'sess-test');
}
$check(isset($last['errors']['message']), 'limita las solicitudes por pedido');

echo "Eventos\n";
$events->record('product.viewed', ['product_id' => 1, 'slug' => 'x'], 'sess-test');
$events->record('checkout.started', ['items' => 1, 'subtotal_cents' => 100], 'sess-test');
$repo = new EventRepository($pdo);
$counts = $repo->countsByType();
foreach (AdminEventController::REQUIRED as $type) {
    $check(($counts[$type] ?? 0) > 0, "se ha registrado $type");
}
$check($repo->count(['type' => 'order.created']) === 2, 'el filtro por tipo funciona');
$check($repo->count(['q' => $code]) >= 3, 'la búsqueda por código de pedido funciona');
$check(AdminEventController::csvCell('=1+1') === "'=1+1", 'el CSV neutraliza fórmulas');

// Limpieza
foreach (glob($tmp . '/{*,mail/*}', GLOB_BRACE) ?: [] as $file) {
    if (is_file($file)) {
        unlink($file);
    }
}
@rmdir($tmp . '/mail');
@rmdir($tmp);

echo $failures === 0 ? "\nTodo correcto.\n" : "\n$failures comprobación(es) fallida(s).\n";
exit($failures === 0 ? 0 : 1);
