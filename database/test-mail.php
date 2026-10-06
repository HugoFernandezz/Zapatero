<?php

declare(strict_types=1);

/**
 * Prueba rápida del correo, sin hacer pedidos:
 *   php database/test-mail.php tucorreo@gmail.com
 * Usa la misma configuración del .env que la tienda y muestra el motivo exacto si falla.
 */

use App\Services\MailService;
use Dotenv\Dotenv;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Dotenv::createImmutable($root)->safeLoad();

$to = $argv[1] ?? '';
if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
    fwrite(STDERR, "Uso: php database/test-mail.php destino@correo.com\n");
    exit(1);
}

$driver = (string) ($_ENV['MAIL_DRIVER'] ?? 'log');
echo "Driver: $driver\n";
echo 'Host:   ' . ($_ENV['SMTP_HOST'] ?? '-') . ':' . ($_ENV['SMTP_PORT'] ?? '-') . ' (' . ($_ENV['SMTP_SECURE'] ?? '-') . ")\n";
echo 'Usuario: ' . ($_ENV['SMTP_USER'] ?? '-') . "\n";
echo 'Remitente: ' . ($_ENV['MAIL_FROM'] ?? '-') . "\n";
echo 'Longitud de la contraseña SMTP: ' . strlen((string) ($_ENV['SMTP_PASSWORD'] ?? '')) . " (una contraseña de aplicación tiene 16)\n\n";

$mail = new MailService(
    $driver,
    (string) ($_ENV['MAIL_FROM'] ?? 'no-reply@zapatero.test'),
    (string) ($_ENV['MAIL_FROM_NAME'] ?? 'Zapatero'),
    $root . '/storage/mail',
    [
        'host' => (string) ($_ENV['SMTP_HOST'] ?? ''),
        'port' => (int) ($_ENV['SMTP_PORT'] ?? 587),
        'user' => (string) ($_ENV['SMTP_USER'] ?? ''),
        'password' => (string) ($_ENV['SMTP_PASSWORD'] ?? ''),
        'secure' => (string) ($_ENV['SMTP_SECURE'] ?? 'tls'),
        'verify_peer' => (string) ($_ENV['SMTP_VERIFY_PEER'] ?? 'true'),
    ]
);

$ok = $mail->send($to, 'Prueba de correo - Zapatero', '<p>Si lees esto, el correo funciona.</p>', 'Si lees esto, el correo funciona.');
echo $ok ? "OK: mensaje entregado al servidor de correo. Revisa la bandeja (y Spam) de $to.\n" : "FALLO: mira la línea \"Zapatero SMTP: ...\" de arriba.\n";
exit($ok ? 0 : 1);
