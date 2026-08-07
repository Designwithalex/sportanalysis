<?php
/**
 * Backfill de `datasets.fecha_sesion` para los datasets ya cargados.
 * Complemento de sql/migration_2026_08_fecha_sesion.sql, que solo agrega la columna vacía.
 *
 * POR QUÉ NO ES SQL. La fecha vive adentro del JSON de `dataset_rows.raw_data` y llega como número
 * de serie de Excel. Decodificarla en SQL sería reimplementar ExcelDate en MySQL y quedarse con dos
 * copias de la misma regla; acá se reusa la clase real.
 *
 * QUÉ TOCA, Y QUÉ NO. No vuelve a detectar el schema entero: eso podría reclasificar columnas por
 * motivos que no tienen que ver con esta migración. Solo da vuelta las columnas que cumplen las
 * tres condiciones a la vez —hoy `numerica`, nombre de fecha, y todos sus valores son series en
 * rango— y escribe `fecha_sesion`. Cualquier otra columna queda igual.
 *
 * Es idempotente: correrlo dos veces da el mismo resultado.
 *
 *   php sql/backfill_fecha_sesion.php          # muestra qué haría, no escribe
 *   php sql/backfill_fecha_sesion.php --write  # aplica
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';
require __DIR__ . '/../public_html/app/ExcelDate.php';

$write = in_array('--write', $argv, true);

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

printf("modo: %s\n\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)');

$datasets = $pdo->query(
    'SELECT id, club_id, nombre, categoria, column_schema, fecha_sesion FROM datasets ORDER BY id'
)->fetchAll();

$updSchema = $pdo->prepare('UPDATE datasets SET column_schema = :schema WHERE id = :id AND club_id = :club');
$updFecha  = $pdo->prepare('UPDATE datasets SET fecha_sesion = :fecha WHERE id = :id AND club_id = :club');

$tocados = 0;

foreach ($datasets as $d) {
    $schema = json_decode((string) $d['column_schema'], true) ?: [];

    // Candidatas: numéricas con nombre de fecha. Es el patrón exacto del bug — la columna `Date`
    // del GPS, que traía la serie de Excel y ganaba el chequeo de "es numérica".
    $candidatas = [];
    foreach ($schema as $col => $tipo) {
        if (($tipo === 'numerica' || $tipo === 'fecha') && ExcelDate::headerLooksLikeDate((string) $col)) {
            $candidatas[] = (string) $col;
        }
    }

    if (!$candidatas) {
        printf("ds%-3d %-42s  sin columna de fecha\n", $d['id'], mb_substr((string) $d['nombre'], 0, 42));
        continue;
    }

    // Los valores salen de las filas guardadas, que es lo único que queda del CSV original.
    $stmt = $pdo->prepare('SELECT raw_data FROM dataset_rows WHERE dataset_id = :id AND club_id = :club');
    $stmt->execute(['id' => $d['id'], 'club' => $d['club_id']]);

    $porColumna = array_fill_keys($candidatas, []);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        $fila = json_decode((string) $raw, true) ?: [];
        foreach ($candidatas as $col) {
            if (isset($fila[$col]) && trim((string) $fila[$col]) !== '') {
                $porColumna[$col][] = (string) $fila[$col];
            }
        }
    }

    $schemaNuevo = $schema;
    $fecha = null;

    foreach ($candidatas as $col) {
        $valores = $porColumna[$col];
        if (!$valores) {
            continue;
        }

        // Todos los valores tienen que ser series en rango. Con uno solo que no lo sea, la columna
        // no es una fecha y se deja como está: mejor no tocarla que inventarle un tipo.
        $todasSerie = true;
        foreach ($valores as $v) {
            if (!ExcelDate::isSerial($v)) {
                $todasSerie = false;
                break;
            }
        }

        if ($todasSerie) {
            $schemaNuevo[$col] = 'fecha';
        }

        // La fecha del dataset sale de la PRIMERA columna candidata que se pueda leer: en los CSV
        // del GPS es `Date`, que va antes que cualquier fecha secundaria.
        $fecha ??= ExcelDate::fechaDeSesion($valores);
    }

    $cambiaSchema = $schemaNuevo !== $schema;
    $cambiaFecha  = $fecha !== null && $fecha !== $d['fecha_sesion'];

    printf(
        "ds%-3d %-42s  %-12s fecha=%s%s\n",
        $d['id'],
        mb_substr((string) $d['nombre'], 0, 42),
        $d['categoria'],
        $fecha ?? '(no legible)',
        $cambiaSchema ? '  [schema: ' . implode(', ', array_keys(array_diff_assoc($schemaNuevo, $schema))) . ' -> fecha]' : ''
    );

    if (!$write || (!$cambiaSchema && !$cambiaFecha)) {
        continue;
    }

    if ($cambiaSchema) {
        $updSchema->execute([
            'schema' => json_encode($schemaNuevo, JSON_UNESCAPED_UNICODE),
            'id'     => $d['id'],
            'club'   => $d['club_id'],
        ]);
    }
    if ($cambiaFecha) {
        $updFecha->execute(['fecha' => $fecha, 'id' => $d['id'], 'club' => $d['club_id']]);
    }
    $tocados++;
}

printf("\n%s\n", $write ? "datasets actualizados: $tocados" : 'nada escrito.');
