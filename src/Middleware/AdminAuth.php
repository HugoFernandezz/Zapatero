<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/** Protege las rutas /admin: solo entra un usuario con rol administrador. */
final class AdminAuth implements MiddlewareInterface
{
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (empty($_SESSION['admin_id'])) {
            if (!empty($_SESSION['user_id'])) {
                // Cliente con sesión: no tiene acceso al back-office.
                return (new Response())->withHeader('Location', '/')->withStatus(303);
            }
            $to = '/login';
            if ($request->getMethod() === 'GET') {
                $to .= '?next=' . rawurlencode($request->getUri()->getPath());
            }

            return (new Response())->withHeader('Location', $to)->withStatus(303);
        }

        // Datos de pedidos y clientes: que el navegador no los guarde en caché.
        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }
}
