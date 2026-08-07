<?php

// `require_once` acá y no en los llamadores: ColumnTypeDetector no funciona sin ExcelDate, y son
// dos endpoints los que lo cargan (api/datasets.php y api/manual_dataset.php). Dejarlo librado a
// que cada uno se acuerde es la clase de olvido que no rompe nada visible — simplemente las fechas
// vuelven a detectarse como números.
require_once __DIR__ . '/ExcelDate.php';

class ColumnTypeDetector
{
    /**
     * `surname` y `apellido` cubren las planillas en inglés y las que traen solo el apellido:
     * el registro de lesiones exporta la columna como `SURNAME AND NAME` y sin esta pista quedaba
     * sin columna de jugador, o sea todas las filas sin matchear contra el plantel.
     *
     * `surname` es seguro como substring: no aparece dentro de `Split Name` ni de `Session Title`,
     * que son las columnas con las que podría competir en los CSV del GPS. `name` a secas, en
     * cambio, sí las agarraría — por eso no está.
     */
    private const PLAYER_COLUMN_HINTS = [
        'nombre', 'jugador', 'player', 'apellido y nombre', 'nombre y apellido', 'surname', 'apellido',
    ];

    /**
     * Valores que significan "no hay dato", no un dato.
     *
     * Sin esto, UNA celda con un guión alcanza para que una columna entera deje de ser numérica: la
     * planilla de antropometrías escribe "-" en los jugadores que no se midieron todavía, y por eso
     * "MM = ACTUAL" (masa muscular) se detectaba como texto y la vista base de nutrición no podía
     * generar un solo widget — no se puede promediar una columna de texto.
     *
     * Los `#DIV/0!` y `#VALUE!` son errores de fórmula de Excel que viajan al CSV tal cual, y son
     * igual de comunes en planillas armadas a mano.
     *
     * Filtrarlos acá es solo para DETECTAR el tipo. El valor crudo se guarda igual, y el renderer
     * ya devuelve null para cualquier celda no numérica, así que un "-" no entra en un promedio.
     */
    private const NULL_MARKERS = ['-', '--', '---', 's/d', 'n/a', 'na', 'null', 'sin dato', '#div/0!', '#value!', '#n/a', '#ref!'];

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
                fn($v) => $v !== '' && !self::esMarcadorDeVacio((string) $v)
            );

            if (count($values) === 0) {
                $schema[$header] = 'texto';
                continue;
            }

            $schema[$header] = self::detectColumnType($values, $header);
        }

        return $schema;
    }

    /**
     * La columna que lleva la fecha de la sesión, o null si el archivo no trae ninguna.
     *
     * Se usa para derivar `datasets.fecha_sesion` en la subida. Gana la primera columna que el
     * schema haya tipado `fecha`, y el orden del schema es el orden de las columnas del archivo:
     * en los CSV del GPS `Date` es la primera de todas, antes que las de fecha secundaria.
     *
     * @param array<string,string> $columnSchema resultado de detect()
     */
    public static function guessDateColumn(array $columnSchema): ?string
    {
        foreach ($columnSchema as $header => $tipo) {
            if ($tipo === 'fecha') {
                return $header;
            }
        }

        return null;
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
    private static function detectColumnType(array $values, string $header = ''): string
    {
        $total = count($values);

        $numericCount = 0;
        $dateCount = 0;
        $serialCount = 0;
        foreach ($values as $value) {
            if (self::looksNumeric($value)) {
                $numericCount++;
            }
            if (self::looksDate($value)) {
                $dateCount++;
            }
            if (ExcelDate::isSerial($value)) {
                $serialCount++;
            }
        }

        // Serie de Excel ANTES que numérica: `46095` es las dos cosas, y ganaba numérica porque el
        // chequeo llegaba primero. Por eso la fecha de la sesión terminaba guardada como el número
        // crudo y ningún dataset tenía una sola columna de tipo `fecha`. El desempate lo hace el
        // ENCABEZADO, nunca el valor: sin `Date`/`Fecha` en el nombre, un número es un número.
        if ($serialCount === $total && ExcelDate::headerLooksLikeDate($header)) {
            return 'fecha';
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

    /** ¿Esta celda es un "no hay dato" escrito a mano? Ver NULL_MARKERS. */
    public static function esMarcadorDeVacio(string $value): bool
    {
        return in_array(mb_strtolower(trim($value), 'UTF-8'), self::NULL_MARKERS, true);
    }

    private static function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $transliteration = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ñ' => 'n'];
        return strtr($value, $transliteration);
    }
}
