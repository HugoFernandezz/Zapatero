<?php

declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Autenticación de la tienda (tabla users): clientes (role = 'customer') y administradores (role = 'admin').
 * El administrador de prueba se define en .env (ADMIN_EMAIL / ADMIN_PASSWORD).
 */
final class AuthService
{
    public const MIN_PASSWORD = 8;

    public function __construct(
        private readonly PDO $pdo,
        private readonly ?string $defaultEmail,
        private readonly ?string $defaultPassword
    ) {}

    /**
     * Crea el administrador de prueba definido en .env si aún no existe.
     * Así el hosting por FTP no necesita ningún comando. No sobrescribe contraseñas ya creadas.
     */
    public function ensureDefaultAdmin(): void
    {
        $email = strtolower(trim((string) $this->defaultEmail));
        if ($email === '' || (string) $this->defaultPassword === '') {
            return;
        }
        $exists = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email');
        $exists->execute(['email' => $email]);
        if ($exists->fetchColumn() !== false) {
            return;
        }
        $this->pdo->prepare("INSERT INTO users (email, name, password_hash, role) VALUES (:email, 'Administrador', :hash, 'admin')")
            ->execute(['email' => $email, 'hash' => password_hash((string) $this->defaultPassword, PASSWORD_DEFAULT)]);
    }

    /** @return array{id: int, email: string, name: string, role: string}|null */
    public function attempt(string $email, string $password): ?array
    {
        $statement = $this->pdo->prepare('SELECT id, email, name, role, password_hash FROM users WHERE email = :email');
        $statement->execute(['email' => strtolower(trim($email))]);
        $user = $statement->fetch();

        if ($user === false) {
            password_verify($password, password_hash('dummy', PASSWORD_DEFAULT)); // tiempo similar si el usuario no existe
            return null;
        }
        if (!password_verify($password, (string) $user['password_hash'])) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'email' => (string) $user['email'],
            'name' => (string) $user['name'],
            'role' => (string) $user['role'],
        ];
    }

    /**
     * Valida los datos de registro de un cliente.
     *
     * @return array{data: array{name: string, email: string}, errors: array<string,string>}
     */
    public function validateRegistration(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $email = strtolower(trim((string) ($input['email'] ?? '')));
        $password = (string) ($input['password'] ?? '');
        $confirm = (string) ($input['password_confirm'] ?? '');
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'El nombre es obligatorio.';
        } elseif (mb_strlen($name) > 120) {
            $errors['name'] = 'El nombre es demasiado largo.';
        }

        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 254) {
            $errors['email'] = 'Introduce un email válido.';
        } elseif ($this->emailExists($email)) {
            $errors['email'] = 'Ya existe una cuenta con ese email.';
        }

        if (mb_strlen($password) < self::MIN_PASSWORD) {
            $errors['password'] = 'La contraseña debe tener al menos ' . self::MIN_PASSWORD . ' caracteres.';
        } elseif (strlen($password) > 72) {
            $errors['password'] = 'La contraseña es demasiado larga (máximo 72 caracteres).';
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'Las contraseñas no coinciden.';
        }

        return ['data' => ['name' => $name, 'email' => $email], 'errors' => $errors];
    }

    /** Crea un cliente y devuelve su fila de sesión. */
    public function register(string $name, string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $this->pdo->prepare("INSERT INTO users (email, name, password_hash, role) VALUES (:email, :name, :hash, 'customer')")
            ->execute(['email' => $email, 'name' => trim($name), 'hash' => password_hash($password, PASSWORD_DEFAULT)]);

        return [
            'id' => (int) $this->pdo->lastInsertId(),
            'email' => $email,
            'name' => trim($name),
            'role' => 'customer',
        ];
    }

    private function emailExists(string $email): bool
    {
        $statement = $this->pdo->prepare('SELECT 1 FROM users WHERE email = :email');
        $statement->execute(['email' => $email]);

        return $statement->fetchColumn() !== false;
    }
}
