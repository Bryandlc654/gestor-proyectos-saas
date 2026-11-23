<?php

namespace App\Support;

/**
 * Validación declarativa de la entrada de la API.
 *
 *   $data = Validator::validate($_POST, [
 *       'title'    => 'required|string|max:255',
 *       'due_date' => 'nullable|date',
 *       'priority' => 'in:baja,media,alta,urgente|default:media',
 *       'amount'   => 'required|numeric|gt:0',
 *       'email'    => 'nullable|email',
 *   ], ['title' => 'el título']);
 *
 * Devuelve solo los campos declarados, ya normalizados (texto recortado, números como int/float,
 * vacíos opcionales como null). Si algo no cumple, lanza ValidationException (422).
 *
 * Reglas: required, nullable, string, int, numeric, bool, email, url, date, color, array,
 *         max:N (longitud o valor), min:N, gt:N, between:A,B, in:a,b,c, default:valor.
 */
final class Validator
{
    /**
     * @param array<string, mixed>  $input
     * @param array<string, string> $rules
     * @param array<string, string> $labels Nombre legible del campo para los mensajes
     * @return array<string, mixed>
     */
    public static function validate(array $input, array $rules, array $labels = []): array
    {
        $data = [];
        $errors = [];

        foreach ($rules as $field => $spec) {
            $label = $labels[$field] ?? str_replace('_', ' ', $field);
            $ruleList = self::parse($spec);
            $raw = $input[$field] ?? null;
            $value = is_string($raw) ? trim($raw) : $raw;
            $isEmpty = $value === null || $value === '' || (is_array($value) && $value === []);

            if ($isEmpty) {
                if (array_key_exists('default', $ruleList)) {
                    $data[$field] = self::cast($ruleList['default'], $ruleList);
                    continue;
                }
                if (array_key_exists('required', $ruleList)) {
                    $errors[$field] = self::ucfirst($label) . ' es obligatorio.';
                    continue;
                }
                $data[$field] = null;
                continue;
            }

            $error = self::check($value, $ruleList, $label);
            if ($error !== null) {
                // Una lista cerrada con valor por defecto acepta valores desconocidos y usa el defecto
                if (isset($ruleList['in'], $ruleList['default']) && str_starts_with($error, 'IN:')) {
                    $data[$field] = self::cast($ruleList['default'], $ruleList);
                    continue;
                }
                $errors[$field] = str_starts_with($error, 'IN:') ? substr($error, 3) : $error;
                continue;
            }
            $data[$field] = self::cast($value, $ruleList);
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $data;
    }

    /** @return array<string, string|null> */
    private static function parse(string $spec): array
    {
        $rules = [];
        foreach (array_filter(explode('|', $spec), 'strlen') as $rule) {
            [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
            $rules[$name] = $arg;
        }
        return $rules;
    }

    /** @param array<string, string|null> $rules */
    private static function check(mixed $value, array $rules, string $label): ?string
    {
        $name = self::ucfirst($label);

        if (isset($rules['array']) || array_key_exists('array', $rules)) {
            return is_array($value) ? null : $name . ' no es válido.';
        }
        if (is_array($value)) {
            return $name . ' no es válido.';
        }

        $string = (string)$value;
        if (array_key_exists('int', $rules) && filter_var($string, FILTER_VALIDATE_INT) === false) {
            return $name . ' debe ser un número entero.';
        }
        if (array_key_exists('numeric', $rules) && !is_numeric($string)) {
            return $name . ' debe ser un número.';
        }
        if (array_key_exists('email', $rules) && filter_var($string, FILTER_VALIDATE_EMAIL) === false) {
            return $name . ' no es un correo válido.';
        }
        if (array_key_exists('url', $rules) && !preg_match('#^https?://[^\s]+$#i', $string)) {
            return $name . ' no es una URL válida (incluye http:// o https://).';
        }
        if (array_key_exists('date', $rules) && !Format::isIsoDate($string)) {
            return $name . ' no es una fecha válida.';
        }
        if (array_key_exists('color', $rules) && !preg_match('/^#[0-9a-fA-F]{6}$/', $string)) {
            return $name . ' no es un color válido.';
        }
        if (array_key_exists('in', $rules)) {
            $allowed = explode(',', (string)$rules['in']);
            if (!in_array($string, $allowed, true)) {
                return 'IN:' . $name . ' no es un valor permitido.';
            }
        }

        $isNumber = array_key_exists('int', $rules) || array_key_exists('numeric', $rules);
        $measure = $isNumber ? (float)$string : mb_strlen($string);
        if (array_key_exists('max', $rules) && $measure > (float)$rules['max']) {
            return $isNumber
                ? $name . ' no puede ser mayor que ' . $rules['max'] . '.'
                : $name . ' admite como máximo ' . $rules['max'] . ' caracteres.';
        }
        if (array_key_exists('min', $rules) && $measure < (float)$rules['min']) {
            return $isNumber
                ? $name . ' no puede ser menor que ' . $rules['min'] . '.'
                : $name . ' debe tener al menos ' . $rules['min'] . ' caracteres.';
        }
        if (array_key_exists('gt', $rules) && (float)$string <= (float)$rules['gt']) {
            return $name . ' debe ser mayor que ' . $rules['gt'] . '.';
        }
        if (array_key_exists('between', $rules)) {
            [$low, $high] = array_map('floatval', explode(',', (string)$rules['between']) + [0, 0]);
            if ((float)$string < $low || (float)$string > $high) {
                return $name . ' debe estar entre ' . $low . ' y ' . $high . '.';
            }
        }

        return null;
    }

    /** @param array<string, string|null> $rules */
    private static function cast(mixed $value, array $rules): mixed
    {
        if (is_array($value)) {
            return $value;
        }
        if (array_key_exists('int', $rules)) {
            return (int)$value;
        }
        if (array_key_exists('numeric', $rules)) {
            return round((float)$value, 2);
        }
        if (array_key_exists('bool', $rules)) {
            return in_array(strtolower((string)$value), ['1', 'true', 'on', 'yes', 'si', 'sí'], true);
        }
        if (array_key_exists('email', $rules)) {
            return mb_strtolower((string)$value);
        }
        return is_string($value) ? trim($value) : $value;
    }

    private static function ucfirst(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)) . mb_substr($text, 1);
    }
}
