<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CartService;
use DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class CartController
{
    public function __construct(private readonly PhpRenderer $view, private readonly CartService $cart) {}

    public function show(Request $request, Response $response): Response
    {
        $summary = $this->cart->summary($_SESSION, (string) ($_SESSION['discount_code'] ?? ''));
        $data = $this->takeFlash();
        return $this->view->render($response, 'cart.php', [
            'pageTitle' => 'Carrito - Zapatero', 'summary' => $summary,
            'csrfToken' => $this->csrfToken(), ...$data,
        ]);
    }

    public function add(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!$this->validCsrf($body)) { $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.'; return $this->redirect($response, '/carrito'); }
        try {
            $variantId = filter_var($body['variant_id'] ?? null, FILTER_VALIDATE_INT);
            if (!$variantId || $variantId < 1) throw new DomainException('Selecciona una talla válida.');
            $this->cart->add($_SESSION, $variantId, 1, session_id());
            $_SESSION['_flash_success'] = 'Producto añadido al carrito.';
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
        }
        return $this->redirect($response, '/carrito');
    }

    public function update(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!$this->validCsrf($body)) { $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.'; return $this->redirect($response, '/carrito'); }
        try {
            $id = filter_var($body['variant_id'] ?? null, FILTER_VALIDATE_INT);
            $qty = filter_var($body['quantity'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1 || $qty === false) throw new DomainException('Datos de cantidad no válidos.');
            $this->cart->update($_SESSION, $id, $qty);
            $_SESSION['_flash_success'] = $qty === 0 ? 'Producto eliminado.' : 'Carrito actualizado.';
        } catch (DomainException $e) { $_SESSION['_flash_error'] = $e->getMessage(); }
        return $this->redirect($response, '/carrito');
    }

    public function discount(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!$this->validCsrf($body)) { $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.'; return $this->redirect($response, '/carrito'); }
        $code = strtoupper(trim((string) ($body['discount_code'] ?? '')));
        if (!in_array($code, ['', 'BIENVENIDA10', 'ZAP5'], true)) {
            $_SESSION['_flash_error'] = 'El código introducido no es válido.';
            unset($_SESSION['discount_code']);
        } else {
            $_SESSION['discount_code'] = $code;
            $_SESSION['_flash_success'] = $code === '' ? 'Código de descuento eliminado.' : 'Código de descuento aplicado.';
        }
        return $this->redirect($response, '/carrito');
    }

    private function csrfToken(): string { return $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)); }
    private function validCsrf(array $body): bool
    {
        return isset($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], (string) ($body['_csrf'] ?? ''));
    }
    private function takeFlash(): array
    {
        return ['success' => $_SESSION['_flash_success'] ?? null, 'error' => $_SESSION['_flash_error'] ?? null]
            + (function () { unset($_SESSION['_flash_success'], $_SESSION['_flash_error']); return []; })();
    }
    private function redirect(Response $response, string $path): Response { return $response->withHeader('Location', $path)->withStatus(303); }
}
