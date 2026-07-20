<?php

class CsvParser
{
    /**
     * Parses a CSV file into headers + associative rows.
     * Auto-detects comma vs semicolon delimiter (common in AR/ES locale exports)
     * and strips a UTF-8 BOM if present.
     *
     * @return array{headers: string[], rows: array<int, array<string, string>>}
     */
    public static function parse(string $filePath): array
    {
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new RuntimeException('No se pudo leer el archivo CSV.');
        }

        if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
            $content = substr($content, 3);
        }

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn($line) => trim($line) !== ''));

        if (count($lines) < 1) {
            throw new RuntimeException('El archivo CSV está vacío.');
        }

        $delimiter = self::detectDelimiter($lines);

        // Parseamos cada línea a celdas.
        $matrix = [];
        foreach ($lines as $line) {
            $matrix[] = array_map('trim', str_getcsv($line, $delimiter, '"', ''));
        }

        // La primera fila de DATOS es la primera con varias celdas numéricas (los valores). Todo lo
        // de arriba (título, encabezados de grupo con celdas combinadas, encabezados de detalle) es
        // encabezado. Así soportamos planillas con título + doble fila de encabezados por mes.
        $dataStart = null;
        foreach ($matrix as $i => $cells) {
            if (self::countNumeric($cells) >= 2) {
                $dataStart = $i;
                break;
            }
        }

        if ($dataStart === null) {
            // Sin filas numéricas claras: caemos al caso simple (primera fila con >=2 celdas = encabezado).
            $dataStart = null;
            foreach ($matrix as $i => $cells) {
                if (count(array_filter($cells, fn($c) => $c !== '')) >= 2) {
                    $dataStart = $i + 1;
                    $headerBlock = [$cells];
                    break;
                }
            }
            if ($dataStart === null) {
                throw new RuntimeException(self::unreadableMessage());
            }
        } else {
            // Filas de encabezado = las de arriba de los datos que tengan >=2 celdas no vacías
            // (descartamos filas de título tipo "PLANTEL SUPERIOR" que traen una sola celda).
            $headerBlock = [];
            for ($i = 0; $i < $dataStart; $i++) {
                if (count(array_filter($matrix[$i], fn($c) => $c !== '')) >= 2) {
                    $headerBlock[] = $matrix[$i];
                }
            }
            if (empty($headerBlock)) {
                $headerBlock = [$matrix[$dataStart - 1] ?? $matrix[0]];
            }
        }

        $headers = self::normalizeHeaders(self::combineHeaderRows($headerBlock));

        $rows = [];
        for ($i = $dataStart; $i < count($matrix); $i++) {
            $values = $matrix[$i];
            $row = [];
            foreach ($headers as $idx => $header) {
                $row[$header] = isset($values[$idx]) ? trim($values[$idx]) : '';
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Combina una o varias filas de encabezado en un nombre por columna. Las filas "sparse"
     * (encabezados de grupo con celdas combinadas, ej: "…ABRIL" que abarca varias columnas) se
     * rellenan hacia la derecha; las filas densas (detalle) no. Resultado: "ABRIL · PRESS PLANO".
     * @param array<int, string[]> $headerBlock
     * @return string[]
     */
    private static function combineHeaderRows(array $headerBlock): array
    {
        $maxCols = 0;
        foreach ($headerBlock as $row) {
            $maxCols = max($maxCols, count($row));
        }

        $filled = [];
        foreach ($headerBlock as $row) {
            $span = max(count($row), 1);
            $nonEmpty = count(array_filter($row, fn($c) => $c !== ''));
            $isSparse = ($nonEmpty / $span) < 0.5;
            if ($isSparse) {
                $last = '';
                for ($c = 0; $c < $maxCols; $c++) {
                    if (($row[$c] ?? '') !== '') {
                        $last = $row[$c];
                    } else {
                        $row[$c] = $last;
                    }
                }
            }
            $filled[] = $row;
        }

        $headers = [];
        for ($c = 0; $c < $maxCols; $c++) {
            $parts = [];
            foreach ($filled as $row) {
                $v = trim($row[$c] ?? '');
                if ($v !== '' && !in_array($v, $parts, true)) {
                    $parts[] = $v;
                }
            }
            $headers[$c] = implode(' · ', $parts);
        }
        return $headers;
    }

    /** @param string[] $cells */
    private static function countNumeric(array $cells): int
    {
        $n = 0;
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell === '') {
                continue;
            }
            if (is_numeric(str_replace(',', '.', $cell))) {
                $n++;
            }
        }
        return $n;
    }

    private static function unreadableMessage(): string
    {
        return 'No pudimos leer el archivo como una tabla. Revisá que sea un CSV con una fila de encabezados y columnas '
            . 'separadas (coma, punto y coma o tabulación). Las planillas en formato de matriz (jugadores como columnas) '
            . 'no se pueden leer directamente — exportá una versión simple: una fila por jugador.';
    }

    /**
     * Nombra columnas sin encabezado ("Columna N") y desambigua encabezados repetidos, para que
     * no colisionen como claves de fila (lo que colapsaría todo en una sola columna vacía).
     * @param string[] $rawHeaders
     * @return string[]
     */
    private static function normalizeHeaders(array $rawHeaders): array
    {
        $seen = [];
        $headers = [];
        foreach ($rawHeaders as $idx => $raw) {
            $h = trim((string) $raw);
            if ($h === '') {
                $h = 'Columna ' . ($idx + 1);
            }
            $base = $h;
            $n = 1;
            while (isset($seen[$h])) {
                $n++;
                $h = $base . ' (' . $n . ')';
            }
            $seen[$h] = true;
            $headers[] = $h;
        }
        return $headers;
    }

    /**
     * Detecta el delimitador (coma, punto y coma o tabulación) contando sobre las primeras filas,
     * no solo la primera (que puede ser un título sin delimitadores).
     * @param string[] $lines líneas no vacías
     */
    private static function detectDelimiter(array $lines): string
    {
        $sample = array_slice($lines, 0, 10);
        $counts = [',' => 0, ';' => 0, "\t" => 0];
        foreach ($sample as $line) {
            $counts[','] += substr_count($line, ',');
            $counts[';'] += substr_count($line, ';');
            $counts["\t"] += substr_count($line, "\t");
        }
        arsort($counts);
        $best = array_key_first($counts);
        return $counts[$best] > 0 ? $best : ',';
    }
}
