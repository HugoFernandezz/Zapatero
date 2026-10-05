<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\CatalogService;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

final class HomeController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly CatalogService $catalog
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $this->view->render($response, 'home.php', [
            'pageTitle' => 'Zapatero - zapatillas con diseño gráfico',
            ...$this->catalog->homeData(),
        ]);
    }
}
