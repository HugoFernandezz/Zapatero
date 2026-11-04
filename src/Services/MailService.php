<?php

declare(strict_types=1);

namespace App\Services;

use RuntimeException;

/**
 * Envío de correo HTML + texto.
 *  - driver "smtp": envía de verdad por un servidor SMTP (Gmail, Outlook, el buzón de tu hosting...). Sin dependencias.
 *  - driver "mail": usa mail() de PHP (solo suele funcionar en un hosting con correo configurado).
 *  - driver "log":  no envía nada; guarda el mensaje como .eml en storage/mail (desarrollo local).
 */
final class MailService
{
    /**
     * @param array{host?: string, port?: int|string, user?: string, password?: string, secure?: string} $smtp
     *        secure: "tls" (STARTTLS, puerto 587), "ssl" (puerto 465) o "" (sin cifrar, solo para pruebas locales).
     */
    public function __construct(
        private readonly string $driver,
        private readonly string $from,
        private readonly string $fromName,
        private readonly string $logDir,
        private readonly array $smtp = []
    ) {}

    public function send(string $to, string $subject, string $html, string $text): bool
    {
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        $boundary = 'zap_' . bin2hex(random_bytes(12));
        $domain = substr((string) (strrchr($this->from, '@') ?: '@localhost'), 1);
        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        $headers = [
            'MIME-Version: 1.0',
            'From: =?UTF-8?B?' . base64_encode($this->fromName) . '?= <' . $this->from . '>',
            'Reply-To: ' . $this->from,
            'Date: ' . date('r'),
            'Message-ID: <' . bin2hex(random_bytes(10)) . '@' . $domain . '>',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($text))
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($html))
            . '--' . $boundary . "--\r\n";

        if ($this->driver === 'mail') {
            return @mail($to, $encodedSubject, $body, implode("\r\n", $headers));
        }
        if ($this->driver === 'smtp') {
            return $this->sendSmtp($to, $encodedSubject, $headers, $body);
        }

        return $this->writeLog($to, $encodedSubject, $headers, $body);
    }

    private function sendSmtp(string $to, string $subject, array $headers, string $body): bool
    {
        $host = trim((string) ($this->smtp['host'] ?? ''));
        $port = (int) ($this->smtp['port'] ?? 587);
        $secure = strtolower(trim((string) ($this->smtp['secure'] ?? 'tls')));
        $user = (string) ($this->smtp['user'] ?? '');
        // Google muestra la contraseña de aplicación en bloques con espacios; hay que quitarlos.
        $password = preg_replace('/\s+/', '', (string) ($this->smtp['password'] ?? '')) ?? '';
        $verify = filter_var($this->smtp['verify_peer'] ?? true, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true;
        if ($host === '') {
            error_log('Zapatero SMTP: falta SMTP_HOST.');

            return false;
        }

        $transport = ($secure === 'ssl' ? 'ssl' : 'tcp') . '://' . $host . ':' . $port;
        $context = stream_context_create(['ssl' => [
            'verify_peer' => $verify,
            'verify_peer_name' => $verify,
            'peer_name' => $host,
        ]]);
        $socket = @stream_socket_client($transport, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            error_log("Zapatero SMTP: no se pudo conectar a $host:$port ($errstr)");

            return false;
        }
        stream_set_timeout($socket, 20);

        try {
            $ehloHost = preg_replace('/[^A-Za-z0-9.\-]/', '', (string) ($_SERVER['SERVER_NAME'] ?? '')) ?: 'localhost';
            $ehlo = 'EHLO ' . $ehloHost;
            $this->smtpRead($socket, [220]);
            $this->smtpCommand($socket, $ehlo, [250]);

            if ($secure === 'tls') {
                $this->smtpCommand($socket, 'STARTTLS', [220]);
                if (!@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT | STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT)) {
                    throw new RuntimeException('No se pudo activar TLS.');
                }
                $this->smtpCommand($socket, $ehlo, [250]);
            }

            if ($user !== '') {
                if ($password === '') {
                    throw new RuntimeException('Falta SMTP_PASSWORD.');
                }
                $this->smtpCommand($socket, 'AUTH LOGIN', [334]);
                $this->smtpCommand($socket, base64_encode($user), [334]);
                $this->smtpCommand($socket, base64_encode($password), [235]);
            }

            $this->smtpCommand($socket, 'MAIL FROM:<' . $this->from . '>', [250]);
            $this->smtpCommand($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->smtpCommand($socket, 'DATA', [354]);

            $message = implode("\r\n", $headers) . "\r\nTo: " . $to . "\r\nSubject: " . $subject . "\r\n\r\n" . $body;
            $message = preg_replace('/^\./m', '..', $message) ?? $message; // "dot-stuffing" del protocolo SMTP
            $this->smtpCommand($socket, rtrim($message, "\r\n") . "\r\n.", [250]);
            @fwrite($socket, "QUIT\r\n");

            return true;
        } catch (RuntimeException $e) {
            error_log('Zapatero SMTP: ' . $e->getMessage());

            return false;
        } finally {
            fclose($socket);
        }
    }

    /** @param resource $socket @param list<int> $expected */
    private function smtpCommand($socket, string $command, array $expected): void
    {
        if (@fwrite($socket, $command . "\r\n") === false) {
            throw new RuntimeException('Conexión SMTP cerrada.');
        }
        $this->smtpRead($socket, $expected);
    }

    /** Lee una respuesta (posiblemente multilínea: "250-..." hasta "250 ...") y comprueba el código. */
    private function smtpRead($socket, array $expected): void
    {
        $line = '';
        do {
            $line = fgets($socket, 1024);
            if ($line === false) {
                throw new RuntimeException('El servidor SMTP no respondió.');
            }
        } while (strlen($line) >= 4 && $line[3] === '-');

        $code = (int) substr($line, 0, 3);
        if (!in_array($code, $expected, true)) {
            throw new RuntimeException('Respuesta inesperada: ' . trim($line));
        }
    }

    private function writeLog(string $to, string $subject, array $headers, string $body): bool
    {
        if (!is_dir($this->logDir) && !@mkdir($this->logDir, 0775, true) && !is_dir($this->logDir)) {
            return false;
        }
        $file = $this->logDir . '/' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3)) . '.eml';
        $message = 'To: ' . $to . "\r\nSubject: " . $subject . "\r\n" . implode("\r\n", $headers) . "\r\n\r\n" . $body;

        return file_put_contents($file, $message) !== false;
    }
}
