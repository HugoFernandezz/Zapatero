<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CheckoutService;
use App\Services\OrderService;
use App\Services\PaymentService;
use App\Support\Csrf;
use DomainException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** Paso final del checkout: pago simulado con tarjeta de prueba y creación del pedido. */
final class PaymentController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly CheckoutService $checkout,
        private readonly PaymentService $payments,
        private readonly OrderService $orders
    ) {}

    public function show(Request $request, Response $response): Response
    {
        $guard = $this->guard($response);
        if ($guard instanceof Response) {
            return $guard;
        }
        $error = $_SESSION['_flash_error'] ?? null;
        unset($_SESSION['_flash_error']);

        return $this->renderForm($response, $guard['summary'], [], [], $error);
    }

    public function submit(Request $request, Response $response): Response
    {
        $guard = $this->guard($response);
        if ($guard instanceof Response) {
            return $guard;
        }
        ['summary' => $summary, 'shipping' => $shipping] = $guard;
        $body = (array) $request->getParsedBody();

        if (!Csrf::valid($body)) {
            return $this->renderForm($response->withStatus(400), $summary, [], [], 'La sesión del formulario caducó. Inténtalo de nuevo.');
        }

        $card = $this->payments->evaluate($body);
        if ($card['errors'] !== []) {
            return $this->renderForm($response->withStatus(422), $summary, ['card_holder' => $card['holder'], 'card_expiry' => $card['expiry']], $card['errors'], null);
        }

        try {
            $userId = (int) ($_SESSION['user_id'] ?? 0);
            $result = $this->orders->placeOrder($summary, $shipping, $card, session_id(), $userId > 0 ? $userId : null);
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
            return $this->redirect($response, '/carrito');
        }

        $owned = array_values(array_unique([...($_SESSION['orders'] ?? []), $result['code']]));
        $_SESSION['orders'] = array_slice($owned, -20);

        if (!$result['approved']) {
            $_SESSION['_flash_error'] = 'Pago rechazado por la pasarela simulada. El pedido ' . $result['code']
                . ' se ha cancelado. Tu carrito sigue intacto: puedes reintentarlo con otra tarjeta.';
            return $this->redirect($response, '/pago');
        }

        unset($_SESSION['cart'], $_SESSION['discount_code'], $_SESSION['checkout_quote'], $_SESSION['shipping_data']);
        $_SESSION['_flash_success'] = $result['invoice_sent']
            ? 'Pago aprobado. Te hemos enviado la factura a ' . $shipping['email'] . '.'
            : 'Pago aprobado, pero no hemos podido enviar la factura por email. Puedes verla desde esta página.';

        return $this->redirect($response, '/pedido/' . $result['code']);
    }

    /** @return Response|array{summary: array, shipping: array} Redirección si falta carrito o datos de envío. */
    private function guard(Response $response): Response|array
    {
        try {
            $summary = $this->checkout->start($_SESSION, session_id());
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
            return $this->redirect($response, '/carrito');
        }

        $shipping = $_SESSION['shipping_data'] ?? null;
        if (!is_array($shipping) || $this->checkout->validate($shipping)['errors'] !== []) {
            $_SESSION['_flash_error'] = 'Completa primero los datos de envío.';
            return $this->redirect($response, '/checkout');
        }

        return ['summary' => $summary, 'shipping' => $shipping];
    }

    private function renderForm(Response $response, array $summary, array $formData, array $errors, ?string $error): Response
    {
        return $this->view->render($response, 'payment.php', [
            'pageTitle' => 'Pago - Zapatero',
            'summary' => $summary,
            'shipping' => $_SESSION['shipping_data'] ?? [],
            'formData' => $formData,
            'errors' => $errors,
            'error' => $error,
            'csrfToken' => Csrf::token(),
        ]);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $path)->withStatus(303);
    }
}
