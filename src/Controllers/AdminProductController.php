<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\ProductAdminService;
use App\Support\Csrf;
use DomainException;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** CRUD de productos del back-office: listado, alta, edición (datos y stock por talla) y borrado. */
final class AdminProductController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly ProductAdminService $products
    ) {}

    public function index(Request $request, Response $response): Response
    {
        [$success, $error] = $this->takeFlash();

        return $this->view->render($response, 'admin/products.php', [
            'pageTitle' => 'Productos - Administración',
            'rows' => $this->products->all(),
            'csrfToken' => Csrf::token(),
            'success' => $success,
            'error' => $error,
        ]);
    }

    public function createForm(Request $request, Response $response): Response
    {
        return $this->renderForm($response, null, $this->products->emptyForm(), [], null);
    }

    public function create(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::valid($body)) {
            return $this->renderForm($response->withStatus(400), null, $this->products->emptyForm(), [], 'La sesión del formulario caducó. Inténtalo de nuevo.');
        }

        $result = $this->products->validate($body, $this->upload($request), null, null);
        if ($result['clean'] === null) {
            return $this->renderForm($response->withStatus(422), null, $result['form'], $result['errors'], null);
        }

        try {
            $this->products->create($result['clean']);
        } catch (DomainException | PDOException $e) {
            return $this->renderForm($response->withStatus(500), null, $result['form'], [], $this->saveError($e));
        }

        $_SESSION['_flash_success'] = 'Producto «' . $result['clean']['data']['name'] . '» creado.';

        return $this->redirect($response, '/admin/productos');
    }

    public function editForm(Request $request, Response $response, array $args): Response
    {
        $product = $this->products->find((int) $args['id']);
        if ($product === null) {
            return $this->notFound($response);
        }

        return $this->renderForm($response, $product, $this->products->formFromProduct($product), [], null);
    }

    public function update(Request $request, Response $response, array $args): Response
    {
        $id = (int) $args['id'];
        $product = $this->products->find($id);
        if ($product === null) {
            return $this->notFound($response);
        }

        $body = (array) $request->getParsedBody();
        if (!Csrf::valid($body)) {
            return $this->renderForm($response->withStatus(400), $product, $this->products->formFromProduct($product), [], 'La sesión del formulario caducó. Inténtalo de nuevo.');
        }

        $oldImage = $product['image'] !== null ? (string) $product['image'] : null;
        $result = $this->products->validate($body, $this->upload($request), $id, $oldImage);
        if ($result['clean'] === null) {
            return $this->renderForm($response->withStatus(422), $product, $result['form'], $result['errors'], null);
        }

        try {
            $this->products->update($id, $result['clean'], $oldImage);
        } catch (DomainException | PDOException $e) {
            return $this->renderForm($response->withStatus(500), $product, $result['form'], [], $this->saveError($e));
        }

        $_SESSION['_flash_success'] = 'Producto «' . $result['clean']['data']['name'] . '» actualizado.';

        return $this->redirect($response, '/admin/productos');
    }

    public function delete(Request $request, Response $response, array $args): Response
    {
        if (!Csrf::valid((array) $request->getParsedBody())) {
            $_SESSION['_flash_error'] = 'Sesión caducada. Recarga e inténtalo de nuevo.';
            return $this->redirect($response, '/admin/productos');
        }

        try {
            $this->products->delete((int) $args['id']);
            $_SESSION['_flash_success'] = 'Producto eliminado.';
        } catch (DomainException $e) {
            $_SESSION['_flash_error'] = $e->getMessage();
        }

        return $this->redirect($response, '/admin/productos');
    }

    private function upload(Request $request): ?\Psr\Http\Message\UploadedFileInterface
    {
        $file = $request->getUploadedFiles()['image'] ?? null;

        return $file instanceof \Psr\Http\Message\UploadedFileInterface ? $file : null;
    }

    private function saveError(\Throwable $e): string
    {
        if ($e instanceof DomainException) {
            return $e->getMessage();
        }
        error_log('Zapatero: error al guardar producto: ' . $e->getMessage());

        return 'No se pudo guardar el producto. Revisa que el slug no coincida con otro producto.';
    }

    private function renderForm(Response $response, ?array $product, array $form, array $errors, ?string $error): Response
    {
        return $this->view->render($response, 'admin/product-form.php', [
            'pageTitle' => ($product === null ? 'Nuevo producto' : 'Editar producto') . ' - Administración',
            'product' => $product,
            'form' => $form,
            'errors' => $errors,
            'error' => $error,
            'csrfToken' => Csrf::token(),
        ] + $this->products->formOptions());
    }

    private function notFound(Response $response): Response
    {
        return $this->view->render($response->withStatus(404), 'not-found.php', ['pageTitle' => 'Producto no encontrado - Zapatero']);
    }

    /** @return array{0: ?string, 1: ?string} */
    private function takeFlash(): array
    {
        $flash = [$_SESSION['_flash_success'] ?? null, $_SESSION['_flash_error'] ?? null];
        unset($_SESSION['_flash_success'], $_SESSION['_flash_error']);

        return $flash;
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $path)->withStatus(303);
    }
}
