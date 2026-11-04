<?php

declare(strict_types=1);

namespace App\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Protege /api/v1 con un token Bearer (variable API_TOKEN del .env, nunca en el repositorio).
 * Si no hay token configurado la API queda desactivada (503) en lugar de quedar abierta.
 */
final class ApiToken implements MiddlewareInterface
{
    private const MIN_LENGTH = 24;

    public function __construct(private readonly string $token) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        if (strlen($this->token) < self::MIN_LENGTH) {
            return $this->error(503, 'api_disabled', 'API desactivada: configura API_TOKEN (mínimo 24 caracteres).');
        }
        $header = $request->getHeaderLine('Authorization');
        $given = preg_match('/^Bearer\s+(\S+)$/i', $header, $m) === 1 ? $m[1] : '';
        if ($given === '') {
            // Algunos hosting (FastCGI) eliminan la cabecera Authorization: se admite también X-Api-Token.
            $given = trim($request->getHeaderLine('X-Api-Token'));
        }
        if ($given === '' || !hash_equals($this->token, $given)) {
            return $this->error(401, 'unauthorized', 'Token ausente o inválido.')->withHeader('WWW-Authenticate', 'Bearer');
        }

        return $handler->handle($request)->withHeader('Cache-Control', 'no-store');
    }

    private function error(int $status, string $code, string $message): ResponseInterface
    {
        $response = new Response($status);
        $response->getBody()->write(json_encode(['error' => $code, 'message' => $message], JSON_UNESCAPED_UNICODE));

        return $response->withHeader('Content-Type', 'application/json')->withHeader('Cache-Control', 'no-store');
    }
}
