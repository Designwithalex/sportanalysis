<?php
/**
 * Ingesta de un CSV como dataset, desde la línea de comandos.
 *
 * Es el equivalente CLI de `handleUpload()` en api/datasets.php, y usa LAS MISMAS clases
 * (CsvParser, ColumnTypeDetector, ExcelDate, NameMatcher) a propósito: si la carga por consola
 * detectara tipos o matcheara nombres distinto que la carga por la web, tendríamos dos verdades
 * sobre los mismos datos y los tableros dependerían de por dónde entró el archivo.
 *
 * Lo único que NO replica es el permiso por categoría (CategoryPermission), que se apoya en la
 * sesión: acá no hay usuario logueado, hay alguien con acceso al servidor y a las credenciales.
 *
 *   php sql/import_dataset.php --categoria=nutricion --nombre="Antropometrías" archivo.csv
 *   php sql/import_dataset.php --categoria=partidos archivo.csv --write
 *
 * Sin --write no escribe nada: muestra qué detectó, cuántas filas entran y cuántas quedan sin
 * matchear contra el plantel.
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';
require __DIR__ . '/../public_html/app/CsvParser.php';
require __DIR__ . '/../public_html/app/ColumnTypeDetector.php';   // arrastra ExcelDate
require __DIR__ . '/../public_html/app/NameMatcher.php';
require __DIR__ . '/../public_html/app/Categorias.php';

const CLUB_ID = 1;   // GEBA

// ── Argumentos ───────────────────────────────────────────────────────────────────────────────
$write     = false;
$categoria = null;
$nombre    = null;
$rutas     = [];

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--write') {
        $write = true;
    } elseif (str_starts_with($arg, '--categoria=')) {
        $categoria = substr($arg, 12);
    } elseif (str_starts_with($arg, '--nombre=')) {
        $nombre = substr($arg, 9);
    } elseif (!str_starts_with($arg, '--')) {
        $rutas[] = $arg;
    }
}

if (!$rutas) {
    exit("Falta el archivo CSV.\n  php sql/import_dataset.php --categoria=<cat> [--nombre=\"...\"] archivo.csv [--write]\n");
}
if ($categoria === null || !Categorias::esValida($categoria)) {
    exit('Categoría inválida. Válidas: ' . implode(', ', Categorias::todas()) . "\n");
}
if (count($rutas) > 1 && $nombre !== null) {
    exit("--nombre solo vale para un archivo a la vez: con varios, cada uno toma el suyo del archivo.\n");
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

printf("modo: %s   categoria: %s\n\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)', $categoria);

// Plantel del club, una sola vez: es el índice contra el que se matchea cada fila.
$players = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club');
$players->execute(['club' => CLUB_ID]);
$players = $players->fetchAll();
$indice  = NameMatcher::buildIndex($players);

foreach ($rutas as $ruta) {
    importar($pdo, $ruta, $categoria, $nombre, $indice, $write);
}

/**
 * @param array<string,int> $indice nombre normalizado -> player_id
 */
function importar(PDO $pdo, string $ruta, string $categoria, ?string $nombre, array $indice, bool $write): void
{
    if (!is_file($ruta)) {
        printf("!! no existe: %s\n\n", $ruta);
        return;
    }

    try {
        $parsed = CsvParser::parse($ruta);
    } catch (RuntimeException $e) {
        printf("!! %s: %s\n\n", basename($ruta), $e->getMessage());
        return;
    }

    $headers = $parsed['headers'];
    $filas   = $parsed['rows'];

    $schema       = ColumnTypeDetector::detect($headers, $filas);
    $playerColumn = ColumnTypeDetector::guessPlayerColumn($headers);
    $dateColumn   = ColumnTypeDetector::guessDateColumn($schema);
    $fechaSesion  = $dateColumn !== null ? ExcelDate::fechaDeSesion(array_column($filas, $dateColumn)) : null;

    $nombreFinal = $nombre ?? pathinfo($ruta, PATHINFO_FILENAME);

    // Conteo previo, con la MISMA regla que aplica la escritura: una fila sin nombre cuando hay
    // columna de jugador es relleno de la planilla, no un registro.
    $aInsertar = 0;
    $sinMatch  = 0;
    $nombresSinMatch = [];
    foreach ($filas as $fila) {
        $rawName = $playerColumn !== null ? trim((string) ($fila[$playerColumn] ?? '')) : '';
        if ($playerColumn !== null && $rawName === '') {
            continue;
        }
        $aInsertar++;
        if ($rawName === '' || NameMatcher::findExact($rawName, $indice) === null) {
            $sinMatch++;
            if ($rawName !== '') {
                $nombresSinMatch[$rawName] = ($nombresSinMatch[$rawName] ?? 0) + 1;
            }
        }
    }

    printf("%s\n", basename($ruta));
    printf("  nombre dataset : %s\n", $nombreFinal);
    printf("  columnas       : %d\n", count($headers));
    printf("  col. jugador   : %s\n", $playerColumn ?? '(ninguna — las filas no se van a poder matchear)');
    printf("  col. fecha     : %s\n", $dateColumn ?? '(ninguna)');
    printf("  fecha sesion   : %s\n", $fechaSesion ?? '(sin fecha)');
    printf("  filas a cargar : %d de %d\n", $aInsertar, count($filas));
    printf("  sin matchear   : %d\n", $sinMatch);

    if ($nombresSinMatch) {
        arsort($nombresSinMatch);
        $muestra = array_slice($nombresSinMatch, 0, 8, true);
        foreach ($muestra as $n => $c) {
            printf("      - %-32s x%d\n", $n, $c);
        }
        if (count($nombresSinMatch) > count($muestra)) {
            printf("      ... y %d nombres más\n", count($nombresSinMatch) - count($muestra));
        }
    }

    if (!$write) {
        printf("\n");
        return;
    }

    $pdo->beginTransaction();
    try {
        $ins = $pdo->prepare(
            'INSERT INTO datasets (club_id, nombre, categoria, original_filename, column_schema, player_column_name, fecha_sesion)
             VALUES (:club, :nombre, :categoria, :archivo, :schema, :player_col, :fecha)'
        );
        $ins->execute([
            'club'       => CLUB_ID,
            'nombre'     => mb_substr($nombreFinal, 0, 150),
            'categoria'  => $categoria,
            'archivo'    => basename($ruta),
            'schema'     => json_encode($schema, JSON_UNESCAPED_UNICODE),
            'player_col' => $playerColumn,
            'fecha'      => $fechaSesion,
        ]);
        $datasetId = (int) $pdo->lastInsertId();

        $insFila = $pdo->prepare(
            'INSERT INTO dataset_rows (club_id, dataset_id, player_id, raw_name, raw_data, match_status)
             VALUES (:club, :dataset, :player, :raw_name, :raw_data, :estado)'
        );

        foreach ($filas as $fila) {
            $rawName = $playerColumn !== null ? trim((string) ($fila[$playerColumn] ?? '')) : '';
            if ($playerColumn !== null && $rawName === '') {
                continue;
            }

            $playerId = $rawName !== '' ? NameMatcher::findExact($rawName, $indice) : null;

            $insFila->execute([
                'club'     => CLUB_ID,
                'dataset'  => $datasetId,
                'player'   => $playerId,
                'raw_name' => $rawName !== '' ? $rawName : null,
                'raw_data' => json_encode($fila, JSON_UNESCAPED_UNICODE),
                'estado'   => $playerId !== null ? 'matched' : 'unmatched',
            ]);
        }

        $pdo->commit();
        printf("  -> dataset %d creado\n\n", $datasetId);
    } catch (PDOException $e) {
        $pdo->rollBack();
        printf("  !! FALLO, no se escribió nada: %s\n\n", $e->getMessage());
    }
}
