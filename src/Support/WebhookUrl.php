<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Validación de URLs de webhook con protección básica contra SSRF.
 * Rechaza esquemas no HTTP, credenciales embebidas y destinos privados/reservados.
 */
final class WebhookUrl
{
    public static function normalize(string $url): string
    {
        $url = trim($url);
        if ($url === '' || mb_strlen($url) > 500) {
            throw new InvalidArgumentException('La URL del webhook está vacía o supera 500 caracteres.');
        }
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('La URL del webhook no es válida.');
        }

        $parts = parse_url($url);
        $scheme = strtolower((string)($parts['scheme'] ?? ''));
        if ($scheme !== 'https') {
            throw new InvalidArgumentException('El webhook debe usar HTTPS.');
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            throw new InvalidArgumentException('La URL del webhook no puede incluir credenciales.');
        }

        $host = strtolower(trim((string)($parts['host'] ?? '')));
        if ($host === '') {
            throw new InvalidArgumentException('La URL del webhook no incluye un host válido.');
        }
        if (self::isBlockedHostname($host)) {
            throw new InvalidArgumentException('El webhook no puede apuntar a localhost ni a una red interna.');
        }
        if (self::isIp($host)) {
            if (!self::isPublicIp($host)) {
                throw new InvalidArgumentException('El webhook no puede apuntar a una IP privada o reservada.');
            }
        } else {
            $ips = self::resolveHost($host);
            if ($ips === []) {
                throw new InvalidArgumentException('No se pudo resolver el host del webhook.');
            }
            foreach ($ips as $ip) {
                if (!self::isPublicIp($ip)) {
                    throw new InvalidArgumentException('El webhook resuelve a una IP privada o reservada.');
                }
            }
        }

        return $url;
    }

    /** Primera IP pública resuelta, para fijarla en cURL y mitigar DNS rebinding. */
    public static function publicIp(string $url): ?string
    {
        $host = strtolower(trim((string)(parse_url($url, PHP_URL_HOST) ?? '')));
        if ($host === '') {
            return null;
        }
        if (self::isIp($host)) {
            return self::isPublicIp($host) ? $host : null;
        }
        foreach (self::resolveHost($host) as $ip) {
            if (self::isPublicIp($ip)) {
                return $ip;
            }
        }
        return null;
    }

    public static function isPublic(string $url): bool
    {
        try {
            self::normalize($url);
            return true;
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function isBlockedHostname(string $host): bool
    {
        return $host === 'localhost'
            || $host === 'localhost.localdomain'
            || $host === 'metadata.google.internal'
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal');
    }

    private static function isIp(string $host): bool
    {
        return filter_var($host, FILTER_VALIDATE_IP) !== false;
    }

    private static function isPublicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false;
    }

    /** @return string[] */
    private static function resolveHost(string $host): array
    {
        $ips = [];
        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $record) {
                if (!empty($record['ip'])) {
                    $ips[] = (string)$record['ip'];
                }
                if (!empty($record['ipv6'])) {
                    $ips[] = (string)$record['ipv6'];
                }
            }
        }
        if ($ips === []) {
            $v4 = @gethostbynamel($host);
            if (is_array($v4)) {
                $ips = array_merge($ips, $v4);
            }
        }
        return array_values(array_unique($ips));
    }
}
