<?php

namespace App\Support;

use RuntimeException;
use Throwable;

/**
 * Envío de correos.
 * - Con SMTP configurado (SMTP_HOST/SMTP_PORT/SMTP_USER/SMTP_PASS) envía por SMTP autenticado
 *   (SSL implícito en 465 o STARTTLS en 587/25).
 * - Sin SMTP usa mail() del servidor.
 * - Si MAIL_ENABLED está desactivado, escribe el correo en storage/mail.log (entorno local).
 */
final class Mailer
{
    /**
     * @param array{host?: string, port?: int, user?: string, pass?: string, secure?: string, name?: string} $smtp
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly string $from,
        private readonly string $logFile,
        private readonly array $smtp = [],
    ) {
    }

    /**
     * @param string|null $html Versión HTML del mensaje; si se indica, se envía como alternativa del texto.
     * @param array<int, array{filename?: string, content?: string, mime?: string}> $attachments Adjuntos binarios.
     */
    public function send(string $to, string $subject, string $body, ?string $html = null, array $attachments = []): bool
    {
        $to = trim(str_replace(["\r", "\n"], '', $to));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        if (!$this->enabled) {
            $this->log($to, $subject, $body);
            return false;
        }

        [$headers, $payload] = $this->buildMessage($to, $subject, $body, $html, $attachments);

        if (($this->smtp['host'] ?? '') !== '') {
            try {
                $this->sendSmtp($headers, $payload, $to);
                return true;
            } catch (Throwable $e) {
                error_log('SMTP: ' . $e->getMessage());
                $this->log($to, '[SMTP falló] ' . $subject, $body);
                return false;
            }
        }

        // mail() añade To y Subject: se quitan de las cabeceras para no duplicarlos
        $mailHeaders = array_values(array_filter(
            $headers,
            static fn(string $header): bool => !preg_match('/^(To|Subject):/i', $header)
        ));

        return @mail($to, $this->encodeSubject($subject), $payload, implode("\r\n", $mailHeaders));
    }

    /**
     * Cabeceras y cuerpo completos del mensaje (multipart/alternative si hay HTML y multipart/mixed si hay adjuntos).
     *
     * @param array<int, array{filename?: string, content?: string, mime?: string}> $attachments
     * @return array{0: array<int, string>, 1: string}
     */
    private function buildMessage(string $to, string $subject, string $body, ?string $html, array $attachments = []): array
    {
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $this->fromHeader(),
            'Reply-To: ' . $this->fromHeader(),
            'To: <' . $to . '>',
            'Subject: ' . $this->encodeSubject($subject),
            'Message-ID: <' . bin2hex(random_bytes(16)) . '@' . $this->fromDomain() . '>',
            'MIME-Version: 1.0',
            'X-Mailer: Vertice',
        ];

        $hasHtml = $html !== null && trim($html) !== '';
        if ($hasHtml) {
            $boundary = '=_vertice_alt_' . bin2hex(random_bytes(12));
            $contentType = 'multipart/alternative; boundary="' . $boundary . '"';
            $content = '--' . $boundary . "\r\n"
                . "Content-Type: text/plain; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $this->normalizeBody($body)
                . "\r\n--" . $boundary . "\r\n"
                . "Content-Type: text/html; charset=UTF-8\r\n"
                . "Content-Transfer-Encoding: 8bit\r\n\r\n"
                . $this->normalizeBody($html)
                . "\r\n--" . $boundary . '--';
        } else {
            $contentType = 'text/plain; charset=UTF-8';
            $content = $this->normalizeBody($body);
        }

        $attachments = array_values(array_filter(
            $attachments,
            static fn($file): bool => is_array($file) && isset($file['content']) && (string)$file['content'] !== ''
        ));

        if ($attachments === []) {
            $headers[] = 'Content-Type: ' . $contentType;
            if (!$hasHtml) {
                $headers[] = 'Content-Transfer-Encoding: 8bit';
            }
            return [$headers, $content];
        }

        $mixedBoundary = '=_vertice_mix_' . bin2hex(random_bytes(12));
        $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

        $payload = '--' . $mixedBoundary . "\r\n"
            . 'Content-Type: ' . $contentType . "\r\n"
            . ($hasHtml ? "\r\n" : "Content-Transfer-Encoding: 8bit\r\n\r\n")
            . $content . "\r\n";

        foreach ($attachments as $file) {
            $filename = $this->safeFilename((string)($file['filename'] ?? 'adjunto'));
            $mime = (string)($file['mime'] ?? 'application/octet-stream');
            $payload .= '--' . $mixedBoundary . "\r\n"
                . 'Content-Type: ' . $mime . '; name="' . $filename . "\"\r\n"
                . "Content-Transfer-Encoding: base64\r\n"
                . 'Content-Disposition: attachment; filename="' . $filename . "\"\r\n\r\n"
                . chunk_split(base64_encode((string)$file['content']), 76, "\r\n") . "\r\n";
        }

        $payload .= '--' . $mixedBoundary . '--';

        return [$headers, $payload];
    }

    /** Nombre de archivo seguro para usar en cabeceras MIME. */
    private function safeFilename(string $filename): string
    {
        $filename = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?? 'adjunto';
        return trim($filename, '-.') !== '' ? trim($filename, '-.') : 'adjunto';
    }

    /** Envía el mensaje por SMTP autenticado. Lanza RuntimeException si el servidor rechaza algo. */
    private function sendSmtp(array $headers, string $payload, string $to): void
    {
        $host = (string)$this->smtp['host'];
        $port = (int)($this->smtp['port'] ?? 0) ?: 465;
        $secure = strtolower((string)(($this->smtp['secure'] ?? '') ?: ($port === 465 ? 'ssl' : 'none')));

        $remote = ($secure === 'ssl' ? 'ssl://' : 'tcp://') . $host . ':' . $port;
        $context = stream_context_create([
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
                'SNI_enabled' => true,
            ],
        ]);

        $socket = @stream_socket_client($remote, $errno, $errstr, 20, STREAM_CLIENT_CONNECT, $context);
        if ($socket === false) {
            throw new RuntimeException("No se pudo conectar a {$host}:{$port} ({$errstr})");
        }
        stream_set_timeout($socket, 20);

        try {
            $this->expect($socket, [220]);

            $ehlo = $this->command($socket, 'EHLO localhost', [250]);

            if ($secure === 'tls') {
                $this->command($socket, 'STARTTLS', [220]);
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new RuntimeException('No se pudo iniciar TLS con el servidor SMTP');
                }
                $ehlo = $this->command($socket, 'EHLO localhost', [250]);
            }

            if (($this->smtp['user'] ?? '') !== '') {
                $this->authenticate($socket, $ehlo);
            }

            $this->command($socket, 'MAIL FROM:<' . $this->fromAddress() . '>', [250]);
            $this->command($socket, 'RCPT TO:<' . $to . '>', [250, 251]);
            $this->command($socket, 'DATA', [354]);

            $message = implode("\r\n", $headers) . "\r\n\r\n" . $payload;
            fwrite($socket, $this->dotStuff($message) . "\r\n.\r\n");
            $this->expect($socket, [250]);

            $this->command($socket, 'QUIT', [221]);
        } finally {
            fclose($socket);
        }
    }

    private function authenticate($socket, string $ehloResponse): void
    {
        $supportsPlain = stripos($ehloResponse, 'PLAIN') !== false;
        $supportsLogin = stripos($ehloResponse, 'LOGIN') !== false;

        if ($supportsLogin || !$supportsPlain) {
            $this->command($socket, 'AUTH LOGIN', [334]);
            $this->command($socket, base64_encode((string)$this->smtp['user']), [334]);
            $this->command($socket, base64_encode((string)$this->smtp['pass']), [235]);
            return;
        }

        $token = base64_encode("\0" . $this->smtp['user'] . "\0" . $this->smtp['pass']);
        $this->command($socket, 'AUTH PLAIN ' . $token, [235]);
    }

    /** @param resource $socket */
    private function command($socket, string $command, array $expectedCodes): string
    {
        fwrite($socket, $command . "\r\n");
        return $this->expect($socket, $expectedCodes);
    }

    /**
     * Lee una respuesta SMTP completa (admite respuestas multilínea).
     *
     * @param resource $socket
     * @param int[] $expectedCodes
     */
    private function expect($socket, array $expectedCodes): string
    {
        $response = '';

        while (($line = fgets($socket, 1024)) !== false) {
            $response .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        if ($response === '') {
            $meta = stream_get_meta_data($socket);
            throw new RuntimeException($meta['timed_out'] ? 'El servidor SMTP no respondió a tiempo' : 'Sin respuesta del servidor SMTP');
        }

        $code = (int)substr($response, 0, 3);
        if (!in_array($code, $expectedCodes, true)) {
            throw new RuntimeException('SMTP respondió: ' . trim($response));
        }

        return $response;
    }

    /** Cuerpo con saltos CRLF normalizados. */
    private function normalizeBody(string $body): string
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        return str_replace("\n", "\r\n", $body);
    }

    /** Escapa las líneas que empiezan por punto, como exige el protocolo SMTP. */
    private function dotStuff(string $payload): string
    {
        return preg_replace('/^\./m', '..', $payload) ?? $payload;
    }

    private function encodeSubject(string $subject): string
    {
        $subject = str_replace(["\r", "\n"], ' ', $subject);
        return '=?UTF-8?B?' . base64_encode($subject) . '?=';
    }

    private function fromAddress(): string
    {
        if (preg_match('/<([^>]+)>/', $this->from, $m)) {
            return $m[1];
        }
        return filter_var($this->from, FILTER_VALIDATE_EMAIL) ? $this->from : 'no-reply@localhost';
    }

    private function fromDomain(): string
    {
        $parts = explode('@', $this->fromAddress());
        return $parts[1] ?? 'localhost';
    }

    private function fromHeader(): string
    {
        $name = (string)($this->smtp['name'] ?? 'Vertice');
        return '=?UTF-8?B?' . base64_encode($name) . '?= <' . $this->fromAddress() . '>';
    }

    private function log(string $to, string $subject, string $body): void
    {
        $dir = dirname($this->logFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $entry = sprintf(
            "[%s] To: %s\nSubject: %s\n%s\n%s\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            $body,
            str_repeat('-', 60)
        );
        @file_put_contents($this->logFile, $entry, FILE_APPEND | LOCK_EX);
    }
}
