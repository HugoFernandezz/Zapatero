<?php

// Escenarios 1 y 2 de la Tarea 2 por HTTP, de la tienda al back-office:  php tests/integracion.php
// Reproduce lo que hace el workflow WF1 de n8n (n8n/workflows/wf1-pedidos-sincronizacion.json) contra las
// rutas reales de las dos aplicaciones, con bases SQLite temporales y datos ficticios.

declare(strict_types=1);

use App\BackOffice\BoDatabase;
use App\Database;
use App\Repositories\CartRepository;
use App\Repositories\OrderRepository;
use App\Services\CartService;
use App\Services\EventService;
use App\Services\InvoiceService;
use App\Services\MailService;
use App\Services\OrderNotifier;
use App\Services\OrderService;
use App\Support\OrderStatus;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\PhpRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$dir = $root . '/storage/test-integracion-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$previousEnv = $_ENV;
$_ENV['BO_DB_PATH'] = 'storage/' . basename($dir) . '/bo.sqlite';
$_ENV['BO_API_TOKEN'] = bin2hex(random_bytes(24));
$_ENV['API_TOKEN'] = bin2hex(random_bytes(24));
$_ENV['MAIL_DRIVER'] = 'log';
$_SESSION = [];
$shopToken = $_ENV['API_TOKEN'];
$boToken = $_ENV['BO_API_TOKEN'];

$pdo = Database::connect($dir . '/shop.sqlite');
$boPdo = BoDatabase::connect($dir . '/bo.sqlite');
$view = new PhpRenderer($root . '/templates');
$view->setLayout('layout.php');
$app = AppFactory::create();
$app->addRoutingMiddleware();
(require $root . '/src/routes.php')($app, $pdo, $view);

$events = new EventService($pdo);
$recordEvent = static fn (string $type, array $payload, mixed $context = null) => $events->record($type, $payload, is_string($context) ? $context : null);
$cart = new CartService(new CartRepository($pdo), $recordEvent);
$orders = new OrderService($pdo, new OrderRepository($pdo), $events, new InvoiceService(new PhpRenderer($root . '/templates')),
    new MailService('log', 'no-reply@zapatero.test', 'Zapatero', $dir . '/mail'), new OrderNotifier());

$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '  OK    ' : '  FALLO ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
};
/** @return array{0: int, 1: array<mixed>} código HTTP y cuerpo JSON */
$send = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($app): array {
    $req = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);
    if ($body !== null) {
        $req->getBody()->write((string) json_encode($body));
        $req = $req->withHeader('Content-Type', 'application/json');
    }
    if ($token !== null) {
        $req = $req->withHeader('Authorization', 'Bearer ' . $token);
    }
    $res = $app->handle($req);

    return [$res->getStatusCode(), json_decode((string) $res->getBody(), true) ?? []];
};
$bo = static fn (string $sql) => $boPdo->query($sql)->fetchColumn();

// Pedido ficticio pagado en la tienda, como lo crea el flujo de pago.
$comprar = static function (string $email) use ($pdo, $cart, $orders): string {
    $variant = (int) $pdo->query('SELECT id FROM product_variants WHERE stock >= 2 ORDER BY id LIMIT 1')->fetchColumn();
    $session = ['cart' => []];
    $cart->add($session, $variant, 2, 'sess-integracion');
    $shipping = ['full_name' => '  Persona   Ficticia ', 'email' => $email, 'address' => 'Calle Falsa 1',
        'city' => 'Murcia', 'postal_code' => '30001', 'province' => 'Murcia'];

    return $orders->placeOrder($cart->summary($session), $shipping, ['approved' => true, 'last4' => '4242'], 'sess-integracion')['code'];
};

// Nodo «Validar contrato» de WF1: errores del evento (lista vacía = válido).
$validar = static function (array $e): array {
    $errors = [];
    if (preg_match('/^evt_[0-9a-f]{32}$/', (string) ($e['event_id'] ?? '')) !== 1) {
        $errors[] = 'event_id';
    }
    if (($e['schema_version'] ?? null) !== 1) {
        $errors[] = 'schema_version';
    }
    if (!in_array($e['type'] ?? null, ['order.created', 'order.status_changed'], true)) {
        $errors[] = 'type';
    }
    if (strtotime((string) ($e['occurred_at'] ?? '')) === false) {
        $errors[] = 'occurred_at';
    }
    $p = is_array($e['payload'] ?? null) ? $e['payload'] : [];
    if (preg_match('/^ZAP-\d{8}-\d{4}$/', (string) ($p['order_code'] ?? '')) !== 1) {
        $errors[] = 'payload.order_code';
    }
    if (($e['type'] ?? '') === 'order.created' && (!is_int($p['total_cents'] ?? null) || !is_int($p['items'] ?? null) || $p['items'] < 1)) {
        $errors[] = 'payload.total_cents/items';
    }
    if (($e['type'] ?? '') === 'order.status_changed' && !in_array($p['to'] ?? null, OrderStatus::all(), true)) {
        $errors[] = 'payload.to';
    }

    return $errors;
};

// Rama correcta de WF1 para un evento ya validado: devuelve [HTTP del back-office, resultado].
$sincronizar = static function (array $e) use ($send, $shopToken, $boToken): array {
    $code = $e['payload']['order_code'];
    if ($e['type'] === 'order.status_changed') {
        [$http, $body] = $send('POST', "/bo/api/v1/orders/$code/status", [
            'event_id' => $e['event_id'], 'event_type' => $e['type'], 'workflow' => 'wf1-pedidos',
            'execution_id' => 'test', 'occurred_at' => $e['occurred_at'], 'status' => $e['payload']['to'],
        ], $boToken);

        return [$http, $body['result'] ?? $body['error'] ?? null];
    }
    // «Consultar pedido en la tienda» + «Transformar a contrato back-office».
    [$http, $body] = $send('GET', "/api/v1/orders/$code", null, $shopToken);
    if ($http !== 200) {
        return [$http, 'shop_' . $http];
    }
    $o = $body['order'];
    $c = $o['customer'];
    $o['customer'] = ['full_name' => trim((string) $c['full_name']), 'email' => strtolower(trim((string) $c['email'])),
        'city' => $c['city'] ?? null, 'province' => $c['province'] ?? null];
    [$http, $body] = $send('POST', '/bo/api/v1/orders', [
        'event_id' => $e['event_id'], 'event_type' => $e['type'], 'workflow' => 'wf1-pedidos',
        'execution_id' => 'test', 'occurred_at' => $e['occurred_at'], 'order' => $o,
    ], $boToken);

    return [$http, $body['result'] ?? $body['error'] ?? null];
};

// Sondeo de WF1: eventos nuevos desde el cursor, solo los de pedidos.
$sondear = static function (int $after) use ($send, $shopToken): array {
    [, $body] = $send('GET', "/api/v1/events?after_id=$after&limit=50", null, $shopToken);
    $relevantes = array_values(array_filter($body['events'] ?? [], static fn (array $e): bool
        => in_array($e['type'], ['order.created', 'order.status_changed'], true)));

    return [$relevantes, (int) ($body['next_after_id'] ?? $after)];
};

try {
    echo "Contrato de las APIs\n";
    $check($send('GET', '/api/v1/events')[0] === 401, 'la API de la tienda sin token responde 401');
    $check($send('GET', '/api/v1/events', null, 'incorrecto')[0] === 401, 'la API de la tienda con token incorrecto responde 401');
    $check($send('POST', '/bo/api/v1/orders', [], $shopToken)[0] === 401, 'el token de la tienda no abre la API del back-office');
    $check($send('GET', '/api/v1/events?after_id=-1', null, $shopToken)[0] === 400, 'after_id negativo se rechaza con 400');
    $check($send('GET', '/api/v1/orders/ZAP-20990101-9999', null, $shopToken)[0] === 404, 'un pedido inexistente en la tienda responde 404');

    echo "Escenario 1: pedido generado en la tienda e integrado en el back-office\n";
    $code = $comprar('Persona.Ficticia@Example.TEST');
    [$lote, $cursor] = $sondear(0);
    $creado = array_values(array_filter($lote, static fn (array $e): bool => $e['type'] === 'order.created'))[0] ?? null;
    $check($creado !== null && $creado['payload']['order_code'] === $code, "la tienda publica order.created de $code");
    $check($creado !== null && $validar($creado) === [], 'el evento cumple el contrato que valida WF1');
    $check(array_sum(array_map(static fn (array $e): int => $e['type'] === 'order.status_changed' ? 1 : 0, $lote)) === 1,
        'el pago aprobado publica su cambio de estado created → paid_simulated');
    $resultados = array_map($sincronizar, $lote);
    $check($resultados[0] === [201, 'created'], 'el back-office crea el pedido (HTTP 201, created)');
    $check($bo("SELECT status FROM bo_orders WHERE order_code = '$code'") === OrderStatus::PAID, 'en el back-office queda pagado (paid_simulated)');
    $shopTotal = (int) $pdo->query("SELECT total_cents FROM orders WHERE code = '$code'")->fetchColumn();
    $check((int) $bo("SELECT total_cents FROM bo_orders WHERE order_code = '$code'") === $shopTotal, 'el importe coincide con el de la tienda');
    $check((int) $bo("SELECT SUM(quantity) FROM bo_order_lines l JOIN bo_orders o ON o.id = l.order_id WHERE o.order_code = '$code'") === 2,
        'las líneas del pedido llegan con su cantidad');
    $check($bo("SELECT c.email FROM bo_customers c JOIN bo_orders o ON o.customer_id = c.id WHERE o.order_code = '$code'") === 'persona.ficticia@example.test',
        'el email del cliente llega normalizado a minúsculas');
    $check($bo("SELECT ordered_at FROM bo_orders WHERE order_code = '$code'") !== false, 'el pedido guarda su fecha');
    [$http, $vista] = $send('GET', "/bo/api/v1/orders/$code", null, $boToken);
    $check($http === 200 && $vista['order']['total_cents'] === $shopTotal && count($vista['order']['lines']) === 1,
        'la API del back-office devuelve el pedido con su línea');
    $check($sincronizar($creado) === [200, 'duplicate'] && (int) $bo('SELECT COUNT(*) FROM bo_orders') === 1,
        'repetir el mismo evento devuelve duplicate y no duplica el pedido');
    $check((int) $bo("SELECT COUNT(*) FROM bo_integration_log WHERE entity_ref = '$code' AND status = 'ok'") >= 1, 'la ejecución correcta queda en el log de integración');
    [$vacio, $mismo] = $sondear($cursor);
    $check($vacio === [] && $mismo === $cursor, 'el cursor no repite eventos ya leídos');

    echo "Escenario 1: rama de error\n";
    $malo = $creado;
    $malo['event_id'] = 'evt_' . str_repeat('a', 32);
    $malo['payload']['items'] = 0;
    $check($validar($malo) !== [], 'WF1 rechaza un order.created sin líneas');
    [$http, $body] = $send('POST', '/bo/api/v1/orders', ['event_id' => 'evt_' . str_repeat('b', 32), 'order' => ['order_code' => $code]], $boToken);
    $check($http === 422 && ($body['error'] ?? '') === 'validation_failed', 'el back-office rechaza un pedido incompleto con 422');
    $check($bo("SELECT status FROM bo_integration_log WHERE event_id = 'evt_" . str_repeat('b', 32) . "'") === 'rejected', 'el rechazo queda registrado en el log');

    echo "Escenario 2: cambio de estado en la tienda reflejado en el back-office\n";
    $adminId = (int) $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn();
    $orders->changeStatus($code, OrderStatus::PENDING, $adminId, 'admin@zapatero.test', 'preparando envío');
    [$lote, $cursor] = $sondear($cursor);
    $cambio = $lote[0] ?? null;
    $check(count($lote) === 1 && $cambio['type'] === 'order.status_changed' && $cambio['payload']['to'] === OrderStatus::PENDING,
        'la tienda publica order.status_changed → pending_preparation');
    $check($cambio !== null && $validar($cambio) === [], 'el evento de cambio cumple el contrato de WF1');
    $check($sincronizar($cambio) === [200, 'updated'], 'el back-office aplica el cambio (HTTP 200, updated)');
    $check($bo("SELECT status FROM bo_orders WHERE order_code = '$code'") === OrderStatus::PENDING, 'el pedido queda en pending_preparation en el back-office');
    $check($sincronizar($cambio) === [200, 'duplicate'], 'repetir el cambio es idempotente');
    $atrasado = $cambio;
    $atrasado['event_id'] = 'evt_' . str_repeat('c', 32);
    $atrasado['occurred_at'] = '2020-01-01T00:00:00Z';
    $atrasado['payload']['to'] = OrderStatus::CANCELLED;
    $check($sincronizar($atrasado) === [200, 'stale'], 'un evento más antiguo se ignora (stale)');
    $check($bo("SELECT status FROM bo_orders WHERE order_code = '$code'") === OrderStatus::PENDING, 'y el estado no retrocede');
    [$http] = $send('POST', "/bo/api/v1/orders/$code/status", ['event_id' => 'evt_' . str_repeat('d', 32), 'status' => 'perdido'], $boToken);
    $check($http === 422, 'un estado desconocido se rechaza con 422');

    echo "Escenario 2: cambio que llega antes que el pedido\n";
    $code2 = $comprar('otra.persona@example.test');
    $orders->changeStatus($code2, OrderStatus::PENDING, $adminId, 'admin@zapatero.test', '');
    [$lote] = $sondear($cursor);
    $delPedido2 = array_values(array_filter($lote, static fn (array $e): bool => $e['payload']['order_code'] === $code2));
    $estados = array_values(array_filter($delPedido2, static fn (array $e): bool => $e['type'] === 'order.status_changed'));
    $alta = array_values(array_filter($delPedido2, static fn (array $e): bool => $e['type'] === 'order.created'));
    $ultimo = end($estados);
    $check($sincronizar($ultimo) === [202, 'pending_sync'], 'sin el pedido en el back-office, el cambio queda pendiente (HTTP 202)');
    $check((int) $bo("SELECT COUNT(*) FROM bo_pending_sync WHERE order_code = '$code2'") === 1, 'el pendiente queda registrado');
    $check($sincronizar($alta[0])[1] === 'created', 'al llegar el pedido se crea');
    $check($bo("SELECT status FROM bo_orders WHERE order_code = '$code2'") === OrderStatus::PENDING, 'y queda con el estado más reciente');
} finally {
    $_ENV = $previousEnv;
    unset($send, $app, $boPdo, $pdo, $bo, $cart, $orders, $events, $recordEvent, $comprar, $sincronizar, $sondear);
    gc_collect_cycles();
    foreach (array_merge(glob($dir . '/mail/*') ?: [], glob($dir . '/*') ?: []) as $file) {
        is_dir($file) ? rmdir($file) : unlink($file);
    }
    rmdir($dir);
}
echo $failures === 0 ? "\nIntegración: todas las comprobaciones correctas.\n" : "\nIntegración: $failures fallos.\n";
exit($failures === 0 ? 0 : 1);
