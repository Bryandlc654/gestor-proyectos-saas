<?php

namespace App\Support;

/**
 * TOTP (RFC 6238) sin dependencias externas, compatible con Google Authenticator,
 * Authy, 1Password, etc. y códigos de recuperación de un solo uso.
 */
final class Totp
{
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const RECOVERY_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    public static function generateSecret(int $bytes = 20): string
    {
        return self::base32Encode(random_bytes($bytes));
    }

    public static function code(string $secret, ?int $timestamp = null, int $digits = 6, int $period = 30): string
    {
        $key = self::base32Decode($secret);
        $counter = intdiv($timestamp ?? time(), $period);
        $binary = pack('N*', intdiv($counter, 0x100000000), $counter & 0xFFFFFFFF);
        $hash = hash_hmac('sha1', $binary, $key, true);
        $offset = ord(substr($hash, -1)) & 0x0F;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;
        $otp = $value % (10 ** $digits);
        return str_pad((string)$otp, $digits, '0', STR_PAD_LEFT);
    }

    public static function verify(string $secret, string $code, int $window = 1, ?int $timestamp = null): bool
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6 || $secret === '') {
            return false;
        }
        $timestamp ??= time();
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::code($secret, $timestamp + ($offset * 30)), $code)) {
                return true;
            }
        }
        return false;
    }

    public static function provisioningUri(string $secret, string $account, string $issuer = 'Vertice'): string
    {
        $label = rawurlencode($issuer . ':' . $account);
        return 'otpauth://totp/' . $label . '?secret=' . rawurlencode($secret)
            . '&issuer=' . rawurlencode($issuer) . '&algorithm=SHA1&digits=6&period=30';
    }

    /** @return array<int, string> */
    public static function generateRecoveryCodes(int $count = 8): array
    {
        $codes = [];
        for ($i = 0; $i < $count; $i++) {
            $raw = '';
            for ($j = 0; $j < 8; $j++) {
                $raw .= self::RECOVERY_ALPHABET[random_int(0, strlen(self::RECOVERY_ALPHABET) - 1)];
            }
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4);
        }
        return $codes;
    }

    /** @param array<int, string> $codes @return array<int, string> */
    public static function hashRecoveryCodes(array $codes): array
    {
        return array_map(static fn(string $code) => hash('sha256', self::normalizeRecoveryCode($code)), $codes);
    }

    /** Devuelve el JSON restante si el código es válido, o null si no lo es. */
    public static function consumeRecoveryCode(?string $storedJson, string $code): ?string
    {
        $hashes = $storedJson ? json_decode($storedJson, true) : null;
        if (!is_array($hashes) || empty($hashes)) {
            return null;
        }

        $candidate = hash('sha256', self::normalizeRecoveryCode($code));
        foreach ($hashes as $index => $hash) {
            if (hash_equals((string)$hash, $candidate)) {
                unset($hashes[$index]);
                return json_encode(array_values($hashes));
            }
        }
        return null;
    }

    public static function normalizeRecoveryCode(string $code): string
    {
        return strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private static function base32Encode(string $data): string
    {
        $bits = '';
        foreach (str_split($data) as $char) {
            $bits .= str_pad(decbin(ord($char)), 8, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 5) as $chunk) {
            $output .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $output;
    }

    private static function base32Decode(string $secret): string
    {
        $secret = strtoupper(preg_replace('/[^A-Z2-7]/', '', $secret));
        if ($secret === '') {
            return '';
        }

        $bits = '';
        foreach (str_split($secret) as $char) {
            $position = strpos(self::ALPHABET, $char);
            if ($position === false) {
                continue;
            }
            $bits .= str_pad(decbin($position), 5, '0', STR_PAD_LEFT);
        }

        $output = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $output .= chr(bindec($byte));
            }
        }
        return $output;
    }
}
