<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\AuthService;
use App\Services\CartService;
use App\Support\Csrf;
use PDOException;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\Views\PhpRenderer;

/** Login único para clientes y administradores, registro de clientes y cierre de sesión. */
final class AuthController
{
    public function __construct(
        private readonly PhpRenderer $view,
        private readonly AuthService $auth,
        private readonly CartService $cart
    ) {}

    public function loginForm(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, $this->landing());
        }
        $this->auth->ensureDefaultAdmin();

        return $this->renderLogin($response, '', null, $this->safeNext((string) ($request->getQueryParams()['next'] ?? '')));
    }

    public function login(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        $email = trim((string) ($body['email'] ?? ''));
        $next = $this->safeNext((string) ($body['next'] ?? ''));

        if (!Csrf::valid($body)) {
            return $this->renderLogin($response->withStatus(400), $email, 'La sesión del formulario caducó. Inténtalo de nuevo.', $next);
        }

        $this->auth->ensureDefaultAdmin();
        $user = $this->auth->attempt($email, (string) ($body['password'] ?? ''));
        if ($user === null) {
            usleep(400000); // frena un poco la fuerza bruta
            return $this->renderLogin($response->withStatus(401), $email, 'Email o contraseña incorrectos.', $next);
        }

        $this->startSession($user);

        return $this->redirect($response, $next !== '' ? $next : $this->landing());
    }

    public function registerForm(Request $request, Response $response): Response
    {
        if (!empty($_SESSION['user_id'])) {
            return $this->redirect($response, $this->landing());
        }

        return $this->renderRegister($response, [], [], null);
    }

    public function register(Request $request, Response $response): Response
    {
        $body = (array) $request->getParsedBody();
        if (!Csrf::valid($body)) {
            return $this->renderRegister($response->withStatus(400), [], [], 'La sesión del formulario caducó. Inténtalo de nuevo.');
        }

        $result = $this->auth->validateRegistration($body);
        if ($result['errors'] !== []) {
            return $this->renderRegister($response->withStatus(422), $result['data'], $result['errors'], null);
        }

        try {
            $user = $this->auth->register($result['data']['name'], $result['data']['email'], (string) $body['password']);
        } catch (PDOException) {
            // Carrera improbable: otro registro con el mismo email entre la validación y el INSERT.
            return $this->renderRegister($response->withStatus(422), $result['data'], ['email' => 'Ya existe una cuenta con ese email.'], null);
        }

        $this->startSession($user);
        $_SESSION['_flash_success'] = 'Cuenta creada. Mientras tengas la sesión iniciada tienes un '
            . CartService::MEMBER_PERCENT . ' % de descuento en tus compras.';

        return $this->redirect($response, '/');
    }

    public function logout(Request $request, Response $response): Response
    {
        if (Csrf::valid((array) $request->getParsedBody())) {
            // El carrito de un usuario ya está guardado en la base de datos; la sesión se limpia para el siguiente visitante.
            unset(
                $_SESSION['user_id'], $_SESSION['user_name'], $_SESSION['user_email'], $_SESSION['user_role'],
                $_SESSION['admin_id'], $_SESSION['admin_email'],
                $_SESSION['cart'], $_SESSION['discount_code'], $_SESSION['shipping_data'], $_SESSION['checkout_quote'],
                $_SESSION['orders']
            );
            session_regenerate_id(true);
        }

        return $this->redirect($response, '/');
    }

    /** @param array{id: int, email: string, name: string, role: string} $user */
    private function startSession(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_name'] = $user['name'];
        $_SESSION['user_email'] = $user['email'];
        $_SESSION['user_role'] = $user['role'];
        if ($user['role'] === 'admin') {
            // El back-office sigue comprobando estas claves (AdminAuth, AdminController).
            $_SESSION['admin_id'] = $user['id'];
            $_SESSION['admin_email'] = $user['email'];
        }

        // Une el carrito de invitado con el que el usuario ya tenía guardado.
        $this->cart->mergeUserCart($_SESSION, $user['id']);
    }

    private function landing(): string
    {
        return ($_SESSION['user_role'] ?? '') === 'admin' ? '/admin/pedidos' : '/';
    }

    /** Solo rutas internas ("/algo"): evita redirecciones abiertas a otros sitios. */
    private function safeNext(string $next): string
    {
        $next = trim($next);
        if ($next === '' || $next[0] !== '/' || str_starts_with($next, '//') || str_contains($next, '\\') || preg_match('/[\r\n]/', $next)) {
            return '';
        }

        return $next;
    }

    private function renderLogin(Response $response, string $email, ?string $error, string $next): Response
    {
        return $this->view->render($response, 'auth/login.php', [
            'pageTitle' => 'Iniciar sesión - Zapatero',
            'csrfToken' => Csrf::token(),
            'error' => $error,
            'email' => $email,
            'next' => $next,
        ]);
    }

    private function renderRegister(Response $response, array $formData, array $errors, ?string $error): Response
    {
        return $this->view->render($response, 'auth/register.php', [
            'pageTitle' => 'Crear cuenta - Zapatero',
            'csrfToken' => Csrf::token(),
            'formData' => $formData,
            'errors' => $errors,
            'error' => $error,
        ]);
    }

    private function redirect(Response $response, string $path): Response
    {
        return $response->withHeader('Location', $path)->withStatus(303);
    }
}
