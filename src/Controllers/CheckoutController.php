<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CheckoutService;
use App\Services\EventService;
use DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class CheckoutController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly CheckoutService $checkout,
        private readonly EventService $events
    ) {}

    public function show(Request $request, Response $response): Response
    {
        try {
            $summary = $this->checkout->start($_SESSION, session_id());
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/carrito')->withStatus(303);
        }
        $this->events->record('checkout.started', [
            'items' => count($summary['items']), 'subtotal_cents' => $summary['subtotal_cents'],
        ], session_id());
        return $this->view->render($response, 'checkout.php', [
            'pageTitle' => 'Checkout - Zapatero', 'summary' => $summary,
            'formData' => $_SESSION['shipping_data'] ?? [], 'errors' => [],
            'csrfToken' => $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)),
            'success' => null,
        ]);
    }

    public function submit(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!isset($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], (string) ($body['_csrf'] ?? ''))) {
            return $this->renderForm($response->withStatus(400), [], [], 'La sesión del formulario caducó. Recarga el checkout.');
        }
        try { $summary = $this->checkout->start($_SESSION, session_id()); }
        catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
            return $response->withHeader('Location', '/carrito')->withStatus(303);
        }
        $result = $this->checkout->validate($body);
        if ($result['errors'] !== []) return $this->renderForm($response->withStatus(422), $result['data'], $result['errors'], null, $summary);

        // M4 puede consumir este dato de sesión al crear orders y order_items.
        $_SESSION['shipping_data'] = $result['data'];
        $_SESSION['checkout_quote'] = $summary;
        return $this->renderForm($response, $result['data'], [], null, $summary, 'Datos de envío validados. Ya puedes continuar con la integración del pedido/pago simulado.');
    }

    private function renderForm(Response $response, array $formData, array $errors, ?string $error, ?array $summary = null, ?string $success = null): Response
    {
        $summary ??= $this->checkout->start($_SESSION, session_id());
        return $this->view->render($response, 'checkout.php', [
            'pageTitle' => 'Checkout - Zapatero', 'summary' => $summary,
            'formData' => $formData, 'errors' => $errors, 'error' => $error,
            'success' => $success, 'csrfToken' => $_SESSION['_csrf'] ??= bin2hex(random_bytes(32)),
        ]);
    }
}
