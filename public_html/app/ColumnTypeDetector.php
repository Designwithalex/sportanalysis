<?php

class ColumnTypeDetector
{
    private const PLAYER_COLUMN_HINTS = ['nombre', 'jugador', 'player', 'apellido y nombre', 'nombre y apellido'];

    private const DATE_PATTERNS = [
        '/^\d{4}-\d{2}-\d{2}$/',       // 2026-07-07
        '/^\d{1,2}\/\d{1,2}\/\d{2,4}$/', // 07/07/2026
        '/^\d{1,2}-\d{1,2}-\d{2,4}$/',   // 07-07-2026
    ];

    /**
     * @param string[] $headers
     * @param array<int, array<string, string>> $rows
     * @return array<string, string> nombre de columna -> tipo (numerica|fecha|categorica|texto)
     */
    public static function detect(array $headers, array $rows): array
    {
        $schema = [];
        foreach ($headers as $header) {
            $values = array_filter(
                array_map(fn($row) => $row[$header] ?? '', $rows),
                fn($v) => $v !== ''
            );

            if (count($values) === 0) {
                $schema[$header] = 'texto';
                continue;
            }

            $schema[$header] = self::detectColumnType($values);
        }

        return $schema;
    }

    /**
     * @param string[] $headers
     * @return string|null la columna con más confianza de ser el nombre del jugador, o null si es ambigua
     */
    public static function guessPlayerColumn(array $headers): ?string
    {
        foreach ($headers as $header) {
            $normalized = self::normalize($header);
            foreach (self::PLAYER_COLUMN_HINTS as $hint) {
                if ($normalized === $hint || str_contains($normalized, $hint)) {
                    return $header;
                }
            }
        }
        return null;
    }

    /** @param string[] $values non-empty values for one column */
    private static function detectColumnType(array $values): string
    {
        $total = count($values);

        $numericCount = 0;
        $dateCount = 0;
        foreach ($values as $value) {
            if (self::looksNumeric($value)) {
                $numericCount++;
            }
            if (self::looksDate($value)) {
                $dateCount++;
            }
        }

        if ($numericCount === $total) {
            return 'numerica';
        }
        if ($dateCount === $total) {
            return 'fecha';
        }

        $distinct = count(array_unique($values));
        if ($distinct <= 12 || $distinct / $total < 0.2) {
            return 'categorica';
        }

        return 'texto';
    }

    private static function looksNumeric(string $value): bool
    {
        $normalized = str_replace(',', '.', $value);
        return is_numeric($normalized);
    }

    private static function looksDate(string $value): bool
    {
        foreach (self::DATE_PATTERNS as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        return false;
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $transliteration = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'];
        return strtr($value, $transliteration);
    }
}
