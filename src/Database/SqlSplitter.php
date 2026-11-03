<?php

namespace App\Database;

final class SqlSplitter
{
    /**
     * Divide un script SQL en sentencias por ';', respetando cadenas entre comillas,
     * identificadores con acento grave y comentarios de línea (--).
     */
    public static function split(string $sql): array
    {
        $statements = [];
        $current = '';
        $len = strlen($sql);
        $quote = null;

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($quote !== null) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    $current .= $sql[++$i];
                } elseif ($ch === $quote) {
                    $quote = null;
                }
                continue;
            }

            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                while ($i < $len && $sql[$i] !== "\n") {
                    $i++;
                }
                $current .= "\n";
                continue;
            }

            if ($ch === "'" || $ch === '"' || $ch === '`') {
                $quote = $ch;
            }

            if ($ch === ';') {
                if (trim($current) !== '') {
                    $statements[] = trim($current);
                }
                $current = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $statements[] = trim($current);
        }
        return $statements;
    }
}
