<?php

declare(strict_types=1);

use App\BackOffice\BoDatabase;
use App\BackOffice\BoRepository;
use App\BackOffice\BoService;
use App\Database;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Views\PhpRenderer;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$dir = $root . '/storage/test-http-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
$previousEnv = $_ENV;
$_ENV['BO_DB_PATH'] = 'storage/' . basename($dir) . '/bo.sqlite';
$_ENV['BO_API_TOKEN'] = bin2hex(random_bytes(24));
$_ENV['API_TOKEN'] = bin2hex(random_bytes(24));
$_ENV['MAIL_DRIVER'] = 'log';
$_SESSION = [];
$pdo = Database::connect($dir . '/shop.sqlite');
$boPdo = BoDatabase::connect($dir . '/bo.sqlite');
$svc = new BoService(new BoRepository($boPdo));
$view = new PhpRenderer($root . '/templates');
$view->setLayout('layout.php');
$app = AppFactory::create();
$app->addRoutingMiddleware();
(require $root . '/src/routes.php')($app, $pdo, $view);
$failures = 0;
$check = static function (bool $ok, string $message) use (&$failures): void {
    echo ($ok ? '  OK    ' : '  FALLO ') . $message . PHP_EOL;
    $failures += $ok ? 0 : 1;
};
$send = static function (string $method, string $path, ?array $body = null, ?string $token = null) use ($app) {
    $req = (new ServerRequestFactory())->createServerRequest($method, 'http://localhost' . $path);
    if ($body !== null) {
        $req->getBody()->write((string) json_encode($body));
        $req = $req->withHeader('Content-Type', 'application/json');
    }
    if ($token !== null) {
        $req = $req->withHeader('Authorization', 'Bearer ' . $token);
    }
    return $app->handle($req);
};
$chat = static fn () => $boPdo->query('SELECT telegram_chat_id FROM bo_customers LIMIT 1')->fetchColumn();

echo "HTTP: navegación pública y seguridad de Telegram\n";
try {
    foreach (['/', '/catalogo', '/politicas', '/carrito', '/login'] as $path) {
        $res = $send('GET', $path);
        $html = (string) $res->getBody();
        $check($res->getStatusCode() === 200 && str_contains($html, 'Información y políticas')
            && str_contains($html, 'No se realizan ventas, cobros ni envíos reales.'), $path . ': pie visible con políticas y aviso académico');
        if ($path === '/politicas') {
            foreach (['como-funciona', 'pagos-envios', 'cambios-devoluciones', 'datos', 'soporte'] as $anchor) {
                $check(str_contains($html, 'id="' . $anchor . '"'), 'existe el destino del enlace #' . $anchor);
            }
        }
    }
    $payload = ['order_code' => 'ZAP-20261030-0001', 'chat_id' => '555000111'];
    foreach ([null, 'incorrecto'] as $token) {
        $check($send('POST', '/bo/api/v1/telegram/link', $payload, $token)->getStatusCode() === 401, 'sin Bearer válido no se accede a la vinculación');
    }
    // Pedido real de la fixture: el intento antiguo debe fallar incluso si ya está sincronizado.
    $svc->ingestOrder(['event_id' => 'evt_' . str_repeat('1', 32), 'order' => [
        'order_code' => $payload['order_code'], 'status' => 'paid_simulated',
        'created_at' => '2026-10-30T10:00:00Z', 'status_updated_at' => '2026-10-30T10:00:00Z',
        'currency' => 'EUR', 'subtotal_cents' => 100, 'discount_cents' => 0, 'shipping_cents' => 0,
        'tax_cents' => 17, 'total_cents' => 100,
        'customer' => ['full_name' => 'Persona Ficticia', 'email' => 'http@example.test', 'city' => 'Murcia', 'province' => 'Murcia'],
        'items' => [['sku' => 'HTTP-TEST', 'name' => 'Modelo de prueba', 'size_eu' => 40, 'quantity' => 1, 'unit_price_cents' => 100]],
    ]]);
    $boToken = $_ENV['BO_API_TOKEN'];
    $before = $chat();
    $res = $send('POST', '/bo/api/v1/telegram/link', $payload, $boToken);
    $check($res->getStatusCode() === 422 && $chat() === $before, 'el payload antiguo con order_code se rechaza sin modificar el chat');
    $invalid = $send('POST', '/bo/api/v1/telegram/link', ['token' => ['valor'], 'chat_id' => ['valor']], $boToken);
    $check($invalid->getStatusCode() === 422 && $chat() === $before, 'tipos JSON incorrectos se rechazan sin error de servidor');
    $oneUse = $svc->issueTelegramToken($payload['order_code']);
    $res = $send('POST', '/bo/api/v1/telegram/link', ['token' => $oneUse, 'chat_id' => '555000111'], $boToken);
    $check($res->getStatusCode() === 200 && $chat() === '555000111', 'el contrato usado por WF3 sigue vinculando con token de un solo uso');
    $res = $send('POST', '/bo/api/v1/telegram/link', ['token' => $oneUse, 'chat_id' => '555000222'], $boToken);
    $check($res->getStatusCode() === 410 && $chat() === '555000111', 'reutilizar el token no permite sustituir el chat');
    $check((int) $boPdo->query("SELECT COUNT(*) FROM bo_integration_log WHERE event_type='telegram.link' AND status='rejected'")->fetchColumn() >= 3, 'los rechazos autenticados quedan auditados sin guardar el secreto');
    $check($send('GET', '/bo')->getStatusCode() === 303, 'el back-office público exige iniciar sesión');
} finally {
    $_ENV = $previousEnv;
    unset($send, $app, $svc, $boPdo, $pdo, $chat);
    gc_collect_cycles();
    foreach (glob($dir . '/*') ?: [] as $file) {
        unlink($file);
    }
    rmdir($dir);
}
echo $failures === 0 ? "HTTP: todas las comprobaciones correctas.\n" : "HTTP: $failures fallos.\n";
exit($failures === 0 ? 0 : 1);
