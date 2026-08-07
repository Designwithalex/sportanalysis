<?php
/**
 * Alta de los jugadores que aparecen en el registro de lesiones y no están en el plantel.
 *
 * POR QUÉ HACE FALTA. El registro de lesiones cubre el club entero (57 nombres distintos) y el
 * plantel cargado son 37. Sin dar de alta a los que faltan, dos de cada tres filas de lesión no
 * matchean contra ningún jugador y el tablero de kinesiología queda mostrando un tercio de la
 * realidad.
 *
 * EL PUESTO SE INFIERE, Y SE DICE. La columna POSITION del Excel es más gruesa que el vocabulario
 * del plantel: "Front Row (Pillars)" no distingue pilar izquierdo de derecho, y "Halves" no
 * distingue medio scrum de apertura. En esos casos NO se inventa un puesto — se deja `Posicion`
 * sin definir y se guarda el valor crudo del Excel en la metadata, para que el preparador lo
 * complete sabiendo qué se supo y qué no. Inventar "Pilar Izquierdo" para alguien que juega de
 * derecho es peor que no saberlo: se ordena mal y nadie se entera.
 *
 *   php sql/import_plantel_kinesio.php          # muestra qué haría, no escribe
 *   php sql/import_plantel_kinesio.php --write  # aplica
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';
require __DIR__ . '/../public_html/app/CsvParser.php';
require __DIR__ . '/../public_html/app/NameMatcher.php';

const CLUB_ID = 1;   // GEBA, dueño de todo lo cargado hasta ahora

/**
 * POSITION del Excel -> [familia, sub_familia, Posicion].
 * `Posicion` en null = el Excel no alcanza para determinarla (ver encabezado).
 */
const MAPA_POSICION = [
    'Front Row (Hooker)'        => ['forward', 'Front Row',     'Hooker'],
    'Front Row (Pillars)'       => ['forward', 'Front Row',     null],
    'Second Row'                => ['forward', 'Locks',         'Segunda Linea'],
    'Back Row'                  => ['forward', 'Back Row',      'Tercera Linea'],
    'Halves'                    => ['back',    'Inside Backs',  null],
    'Inside Backs'              => ['back',    'Inside Backs',  'Centro'],
    'Outside Backs (Wing)'      => ['back',    'Outside Backs', 'Wing'],
    'Outside Backs (Full Back)' => ['back',    'Outside Backs', 'Fullback'],
];

/**
 * Nombres del Excel que NO se dan de alta porque son un jugador que ya está, escrito distinto.
 *
 * No se resuelven automáticamente asignándoles el player_id: eso es exactamente lo que el Paso 3.7
 * prohíbe —un match candidato es una sugerencia y la confirma una persona—. Lo único que se hace
 * acá es no crear un jugador duplicado; las filas de lesión de estos nombres van a quedar sin
 * matchear y aparecen en la pantalla de validación para que alguien las confirme.
 *
 * El criterio para entrar a esta lista es compartir APELLIDO con alguien del plantel. La similitud
 * a secas no sirve: "VEDOYA SANTIAGO" da 86% contra "Santiago Vera" y son dos personas distintas
 * —lo que se parece es el nombre de pila—, mientras que "PUSSI IGNACIO" da 83% contra "Nacho
 * Pussi" y es el mismo, porque Nacho es el diminutivo de Ignacio y hay un solo Pussi en el club.
 */
const NO_CREAR = [
    'PUSSI IGNACIO' => 'mismo apellido que "Nacho Pussi" (Nacho = Ignacio); hay un solo Pussi en el plantel',
];

$write = in_array('--write', $argv, true);

// La ruta es el primer argumento que NO sea una opción: si no se filtran los flags, `--write`
// termina tomado como nombre de archivo.
$posicionales = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));
$csv = $posicionales[0] ?? __DIR__ . '/../../data/kinesio/injuries.csv';

if (!is_file($csv)) {
    exit("No encuentro el CSV de lesiones: $csv\n(pasalo como primer argumento)\n");
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

printf("modo: %s\n\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)');

// ── Plantel actual ───────────────────────────────────────────────────────────────────────────
$players = $pdo->prepare('SELECT id, nombre FROM players WHERE club_id = :club');
$players->execute(['club' => CLUB_ID]);
$players = $players->fetchAll();
$indice  = NameMatcher::buildIndex($players);

printf("plantel actual: %d jugadores\n", count($players));

// ── Nombres del registro de lesiones ─────────────────────────────────────────────────────────
$parsed = CsvParser::parse($csv);

$delExcel = [];
foreach ($parsed['rows'] as $row) {
    $nombre = trim((string) ($row['SURNAME AND NAME'] ?? ''));
    if ($nombre === '') {
        continue;
    }
    // Primera aparición gana: si un jugador tiene varias lesiones con el puesto cargado en una sola,
    // igual queremos quedarnos con la que trae dato.
    $pos = trim((string) ($row['POSITION'] ?? ''));
    if (!isset($delExcel[$nombre]) || ($delExcel[$nombre]['position'] === '' && $pos !== '')) {
        $delExcel[$nombre] = ['position' => $pos, 'fb' => trim((string) ($row['F/B'] ?? ''))];
    }
}

printf("registro de lesiones: %d nombres distintos\n\n", count($delExcel));

// ── Separar los que ya están de los que faltan ───────────────────────────────────────────────
$yaEstan  = [];
$nuevos   = [];
$omitidos = [];

foreach ($delExcel as $nombre => $info) {
    // Match exacto normalizado: NameMatcher ordena los tokens alfabéticamente, así que
    // "ASCORTI FEDERICO" y "Federico Ascorti" son el mismo jugador.
    $id = NameMatcher::findExact($nombre, $indice);
    if ($id !== null) {
        $yaEstan[$nombre] = $id;
        continue;
    }

    if (isset(NO_CREAR[$nombre])) {
        $omitidos[$nombre] = NO_CREAR[$nombre];
        continue;
    }

    // Sin match exacto: ¿hay alguno parecido? Solo para AVISAR — un alta duplicada por una
    // diferencia de tipeo es peor que un alta de más, porque parte el historial del jugador.
    $sug = NameMatcher::suggest($nombre, $players, 80.0);

    [$familia, $subFamilia, $posicion] = MAPA_POSICION[$info['position']]
        ?? [$info['fb'] === 'B' ? 'back' : 'forward', null, null];

    $nuevos[$nombre] = [
        'familia'     => $familia,
        'sub_familia' => $subFamilia,
        'posicion'    => $posicion,
        'raw'         => $info['position'],
        'sugerencia'  => $sug,
    ];
}

printf("ya en el plantel: %d\n", count($yaEstan));
foreach ($yaEstan as $n => $id) {
    printf("   = %-30s -> player %d\n", $n, $id);
}

printf("\nse dan de alta: %d\n", count($nuevos));
$dudosos = [];
foreach ($nuevos as $n => $d) {
    printf(
        "   + %-30s %-8s %-14s %-16s [%s]\n",
        $n,
        $d['familia'],
        $d['sub_familia'] ?? '(sin)',
        $d['posicion'] ?? '(a definir)',
        $d['raw'] !== '' ? $d['raw'] : 'sin POSITION'
    );
    if ($d['sugerencia'] !== null) {
        $dudosos[$n] = $d['sugerencia'];
    }
}

if ($omitidos) {
    printf("\nNO se dan de alta (ya están con otro nombre): %d\n", count($omitidos));
    foreach ($omitidos as $n => $motivo) {
        printf("   ~ %-30s %s\n", $n, $motivo);
    }
    printf("     Sus filas de lesión van a quedar sin matchear, a resolver en el Paso 3.7.\n");
}

if ($dudosos) {
    printf("\nOJO — parecidos a alguien que YA está. Se dan de alta igual: fusionar dos personas\n");
    printf("distintas le cuelga la lesión de otro a un jugador real y no se detecta después,\n");
    printf("mientras que un duplicado se fusiona a mano cuando aparece. Revisar:\n");
    foreach ($dudosos as $n => $s) {
        printf("   ? %-30s se parece a %-28s (%.0f%%)\n", $n, $s['nombre'], $s['score']);
    }
}

if (!$write) {
    printf("\nnada escrito.\n");
    exit;
}

// ── Alta ─────────────────────────────────────────────────────────────────────────────────────
$ins = $pdo->prepare(
    'INSERT INTO players (club_id, nombre, familia, sub_familia, metadata)
     VALUES (:club, :nombre, :familia, :sub_familia, :metadata)'
);

$pdo->beginTransaction();
try {
    foreach ($nuevos as $nombre => $d) {
        // La metadata deja rastro de de dónde salió el jugador y qué decía el Excel: sin esto,
        // dentro de un mes nadie sabe por qué este tiene puesto y aquel no.
        $metadata = ['origen' => 'registro de lesiones'];
        if ($d['posicion'] !== null) {
            $metadata['Posicion'] = $d['posicion'];
        }
        if ($d['raw'] !== '') {
            $metadata['POSITION (registro de lesiones)'] = $d['raw'];
        }

        $ins->execute([
            'club'        => CLUB_ID,
            'nombre'      => $nombre,
            'familia'     => $d['familia'],
            'sub_familia' => $d['sub_familia'],
            'metadata'    => json_encode($metadata, JSON_UNESCAPED_UNICODE),
        ]);
    }
    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    exit("\nFALLO, no se escribió nada: " . $e->getMessage() . "\n");
}

$total = $pdo->prepare('SELECT COUNT(*) FROM players WHERE club_id = :club');
$total->execute(['club' => CLUB_ID]);
printf("\naltas: %d.  plantel ahora: %d jugadores\n", count($nuevos), $total->fetchColumn());
