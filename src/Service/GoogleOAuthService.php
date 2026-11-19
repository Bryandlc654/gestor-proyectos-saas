<?php

namespace App\Service;

use RuntimeException;

/**
 * Flujo OAuth 2.0 / OpenID Connect de Google sin dependencias externas.
 * Solo intercambia el código en el servidor y valida el ID token con Google.
 */
final class GoogleOAuthService
{
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';
    private const TOKENINFO_ENDPOINT = 'https://oauth2.googleapis.com/tokeninfo';
    private const USERINFO_ENDPOINT = 'https://openidconnect.googleapis.com/v1/userinfo';
    private const ISSUERS = ['accounts.google.com', 'https://accounts.google.com'];

    public function __construct(
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $redirectUri
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->clientId !== '' && $this->clientSecret !== '' && $this->redirectUri !== '';
    }

    public function authorizationUrl(string $state, ?string $loginHint = null): string
    {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'openid email profile',
            'state' => $state,
            'access_type' => 'online',
            'include_granted_scopes' => 'true',
            'prompt' => 'select_account',
        ];
        if ($loginHint !== null && $loginHint !== '') {
            $params['login_hint'] = $loginHint;
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @return array{access_token:string,expires_in?:int,id_token?:string,scope?:string,token_type?:string}
     */
    public function exchangeCode(string $code): array
    {
        [$status, $body] = $this->request('POST', self::TOKEN_ENDPOINT, [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code',
        ]);

        $data = $this->decodeJson($body);
        if ($status < 200 || $status >= 300 || empty($data['access_token'])) {
            $reason = (string)($data['error_description'] ?? $data['error'] ?? 'respuesta inválida');
            throw new RuntimeException('Google no devolvió un token válido: ' . $reason);
        }

        return $data;
    }

    /**
     * Valida el ID token con el endpoint oficial de Google y comprueba los claims
     * críticos (audiencia, emisor, caducidad, correo verificado y subject).
     *
     * @return array<string, mixed>
     */
    public function validateIdToken(string $idToken): array
    {
        if ($idToken === '') {
            throw new RuntimeException('Google no devolvió el ID token.');
        }

        [$status, $body] = $this->request('GET', self::TOKENINFO_ENDPOINT . '?id_token=' . urlencode($idToken));
        $claims = $this->decodeJson($body);
        if ($status !== 200) {
            throw new RuntimeException('No se pudo validar el ID token de Google.');
        }

        $this->assertClaims($claims);
        return $claims;
    }

    /**
     * Alternativa al ID token: perfil del usuario usando el access token.
     *
     * @return array<string, mixed>
     */
    public function fetchUserInfo(string $accessToken): array
    {
        if ($accessToken === '') {
            throw new RuntimeException('Google no devolvió un access token.');
        }

        [$status, $body] = $this->request('GET', self::USERINFO_ENDPOINT, [], [
            'Authorization: Bearer ' . $accessToken,
        ]);
        $claims = $this->decodeJson($body);
        if ($status !== 200) {
            throw new RuntimeException('No se pudo obtener el perfil de Google.');
        }

        // El endpoint userinfo no incluye aud/iss; el access token ya está ligado a este cliente.
        $this->assertClaims($claims, false);
        return $claims;
    }

    /** @param array<string, mixed> $claims */
    private function assertClaims(array $claims, bool $validateTokenMetadata = true): void
    {
        if ($validateTokenMetadata) {
            $audience = $claims['aud'] ?? '';
            $audiences = is_array($audience) ? $audience : [(string)$audience];
            if (!in_array($this->clientId, array_map('strval', $audiences), true)) {
                throw new RuntimeException('El token de Google no pertenece a esta aplicación.');
            }

            if (!in_array((string)($claims['iss'] ?? ''), self::ISSUERS, true)) {
                throw new RuntimeException('Emisor de Google no válido.');
            }

            if (isset($claims['exp']) && (int)$claims['exp'] < time() - 30) {
                throw new RuntimeException('El token de Google ha caducado.');
            }
        }

        $verified = $claims['email_verified'] ?? false;
        if ($verified !== true && $verified !== 'true' && $verified !== 1 && $verified !== '1') {
            throw new RuntimeException('Google no ha verificado el correo de esta cuenta.');
        }

        $email = trim((string)($claims['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new RuntimeException('Google no devolvió un correo válido.');
        }

        if (trim((string)($claims['sub'] ?? '')) === '') {
            throw new RuntimeException('Google no devolvió el identificador de la cuenta.');
        }
    }

    /**
     * @param array<string, string> $data
     * @param string[] $headers
     * @return array{0:int,1:string}
     */
    private function request(string $method, string $url, array $data = [], array $headers = []): array
    {
        $headers = array_merge(['Accept: application/json', 'User-Agent: Vertice/1.0'], $headers);

        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 20,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HTTPHEADER => $headers,
            ];
            if ($method === 'POST') {
                $headers[] = 'Content-Type: application/x-www-form-urlencoded';
                $options[CURLOPT_HTTPHEADER] = $headers;
                $options[CURLOPT_POST] = true;
                $options[CURLOPT_POSTFIELDS] = http_build_query($data);
            }
            curl_setopt_array($ch, $options);
            $body = curl_exec($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            if ($body === false) {
                $error = curl_error($ch);
                curl_close($ch);
                throw new RuntimeException('No se pudo conectar con Google: ' . $error);
            }
            curl_close($ch);
            return [$status, (string)$body];
        }

        // Fallback para servidores sin ext-curl.
        $headerLines = $headers;
        $content = '';
        if ($method === 'POST') {
            $headerLines[] = 'Content-Type: application/x-www-form-urlencoded';
            $content = http_build_query($data);
        }
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'content' => $content,
                'timeout' => 20,
                'ignore_errors' => true,
                'follow_location' => 0,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        if ($body === false) {
            throw new RuntimeException('No se pudo conectar con Google.');
        }
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $matches)) {
                $status = (int)$matches[1];
            }
        }
        return [$status, (string)$body];
    }

    /** @return array<string, mixed> */
    private function decodeJson(string $body): array
    {
        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new RuntimeException('Google devolvió una respuesta no válida.');
        }
        return $data;
    }
}
