<?php
/**
 * Agrega el filtro global `__split = all` a las vistas base que ya existen.
 *
 * POR QUÉ. Los CSV del GPS traen una fila por jugador Y POR TRAMO, anidados: en un partido `all`,
 * `game`, `1st.half` y `2nd.half`, donde las dos mitades suman `game`. Todo widget que sume o
 * promedie sin recortar el tramo cuenta lo mismo tres o cuatro veces — para Juan Ara en la fecha 1,
 * 24.235 m en lugar de los 8.088 m que corrió.
 *
 * Las vistas base que se generen de ahora en adelante ya nacen con este filtro
 * (BaseViewGenerator::filtroTramoEntero). Este script es para las que se crearon antes, y evita
 * tener que regenerarlas con IA solo para corregir un conteo.
 *
 * Es idempotente: no agrega el filtro dos veces.
 *
 *   php sql/backfill_filtro_split.php          # muestra qué haría, no escribe
 *   php sql/backfill_filtro_split.php --write  # aplica
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';
require __DIR__ . '/../public_html/app/Auth.php';
require __DIR__ . '/../public_html/app/WidgetRenderer.php';   // splitColumn()

const CLUB_ID = 1;

$write = in_array('--write', $argv, true);

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

printf("modo: %s\n\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)');

// Qué datasets tienen tramos.
$conTramos = [];
$stmt = $pdo->prepare('SELECT id, nombre, column_schema FROM datasets WHERE club_id = :club');
$stmt->execute(['club' => CLUB_ID]);
foreach ($stmt->fetchAll() as $d) {
    $schema = json_decode((string) $d['column_schema'], true) ?: [];
    if (WidgetRenderer::splitColumn($schema) !== null) {
        $conTramos[(int) $d['id']] = $d['nombre'];
    }
}
printf("datasets con tramos: %d (%s)\n\n", count($conTramos), implode(', ', array_slice($conTramos, 0, 4)) . (count($conTramos) > 4 ? '…' : ''));

if (!$conTramos) {
    exit("Ningún dataset tiene columna de tramo. Nada que hacer.\n");
}

// Vistas BASE del club (user_id IS NULL) que usan alguno de esos datasets.
$ph = implode(',', array_fill(0, count($conTramos), '?'));
$vistas = $pdo->prepare(
    "SELECT DISTINCT v.id, v.nombre, v.tipo
     FROM views v
     INNER JOIN view_datasets vd ON vd.view_id = v.id AND vd.club_id = v.club_id
     WHERE v.club_id = ? AND v.user_id IS NULL AND vd.dataset_id IN ($ph)
     ORDER BY v.id"
);
$vistas->execute([CLUB_ID, ...array_keys($conTramos)]);
$vistas = $vistas->fetchAll();

$yaTiene = $pdo->prepare(
    'SELECT COUNT(*) FROM view_filters WHERE view_id = :v AND club_id = :club AND column_name = "__split"'
);
$ins = $pdo->prepare(
    'INSERT INTO view_filters (club_id, view_id, dataset_id, column_name, filter_type, config)
     VALUES (:club, :view, NULL, "__split", "valores", :config)'
);
$config = json_encode(['operator' => 'eq', 'value' => 'all'], JSON_UNESCAPED_UNICODE);

$agregados = 0;
$saltados  = 0;

foreach ($vistas as $v) {
    $yaTiene->execute(['v' => $v['id'], 'club' => CLUB_ID]);
    if ((int) $yaTiene->fetchColumn() > 0) {
        $saltados++;
        continue;
    }

    $agregados++;
    if ($agregados <= 6) {
        printf("  + vista %-4d %-10s %s\n", $v['id'], $v['tipo'], mb_substr((string) $v['nombre'], 0, 44));
    } elseif ($agregados === 7) {
        printf("  + ...\n");
    }

    if ($write) {
        $ins->execute(['club' => CLUB_ID, 'view' => $v['id'], 'config' => $config]);
    }
}

printf(
    "\nvistas base afectadas: %d   ya tenían el filtro: %d\n%s\n",
    $agregados,
    $saltados,
    $write ? 'aplicado.' : 'nada escrito.'
);
