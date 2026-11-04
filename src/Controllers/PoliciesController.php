<?php

declare(strict_types=1);

namespace App\Controllers;

use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class PoliciesController
{
    public function __construct(private readonly PhpRenderer $view) {}

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'policies.php', [
            'pageTitle' => 'Cómo funciona y políticas · Zapatero',
        ]);
    }
}
