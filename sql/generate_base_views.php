<?php
/**
 * Genera vistas base del club desde la línea de comandos.
 *
 * Es el equivalente CLI de api/base_views.php, y usa el MISMO BaseViewGenerator: la vista que sale
 * de acá es indistinguible de la que sale del botón de la app. Existe porque generar las cinco
 * categorías más los overviews por jugador desde el navegador son seis esperas de un minuto, y
 * porque cuando algo falla acá se ve el error entero en vez de un 422 en una alerta.
 *
 * HACE LLAMADAS REALES A LA API DE ANTHROPIC (una por categoría, más una para la plantilla de
 * jugador). Sin --write no llama a nadie: solo dice qué haría.
 *
 * OJO, `players` NO ES ADITIVO: regenerar los overviews borra y rehace los que existan
 * (upsertView hace UPDATE + tres DELETE), así que se pierde cualquier retoque manual sobre esas
 * vistas base.
 *
 *   php sql/generate_base_views.php
 *   php sql/generate_base_views.php --write entrenamientos kinesiologia nutricion
 *   php sql/generate_base_views.php --write players
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/bootstrap.php';
require __DIR__ . '/../public_html/app/Auth.php';

// BaseViewGenerator y CategoryPermission resuelven permisos contra la sesión. Acá no hay request:
// se la damos armada con el usuario que corre la migración, y el resto del código no se entera.
//
// DESPUÉS del bootstrap, no antes: bootstrap.php llama a session_start(), que reemplaza $_SESSION
// por el contenido de la sesión (vacía) y se llevaría puesta esta línea.
$_SESSION['user_id'] = (int) (getenv('PL_USER_ID') ?: 6);
require __DIR__ . '/../public_html/app/Categorias.php';
require __DIR__ . '/../public_html/app/BaseViewGenerator.php';

/**
 * `respondError()` vive en bootstrap_api.php, que no se puede cargar acá (manda headers HTTP y
 * corta con un JSON). CategoryPermission la llama cuando el usuario no tiene la categoría, así que
 * la definimos con la misma firma para que el fallo se lea como texto en la consola.
 */
if (!function_exists('respondError')) {
    function respondError(int $code, string $message): void
    {
        exit("\n  BLOQUEADO ($code): $message\n");
    }
}

$write   = in_array('--write', $argv, true);
$pedidos = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));

$user = Auth::user();
if (!$user) {
    exit("No existe el usuario " . $_SESSION['user_id'] . " (probá con PL_USER_ID=<id>).\n");
}
printf(
    "usuario: %s (%s, club %s)\nmodo: %s\n\n",
    $user['email'],
    $user['nivel'],
    $user['club_nombre'],
    $write ? 'ESCRITURA — hace llamadas reales a la IA' : 'simulacion (--write para generar)'
);

// Sin argumentos: todas las categorías con vista base que tengan datasets cargados.
if (!$pedidos) {
    $pdo = Database::get();
    $conDatos = $pdo->prepare('SELECT DISTINCT categoria FROM datasets WHERE club_id = :club');
    $conDatos->execute(['club' => Auth::clubId()]);
    $conDatos = $conDatos->fetchAll(PDO::FETCH_COLUMN);

    $pedidos = array_values(array_filter(
        array_keys(Categorias::conVistaBase()),
        fn ($c) => in_array($c, $conDatos, true)
    ));
    printf("categorias con vista base y datos: %s\n\n", implode(', ', $pedidos));
}

$pdo       = Database::get();
$generator = new BaseViewGenerator($pdo);

foreach ($pedidos as $pedido) {
    if ($pedido === 'players') {
        printf("overviews por jugador\n");
        $n = $pdo->prepare('SELECT COUNT(*) FROM players WHERE club_id = :club');
        $n->execute(['club' => Auth::clubId()]);
        // A una variable: fetchColumn() consume el resultado, así que llamarlo dos veces en el
        // mismo printf devuelve el conteo y después false (que se imprime como 0).
        $cuantos = (int) $n->fetchColumn();
        printf("  plantel: %d jugadores -> 1 llamada IA (plantilla) + %d clones\n", $cuantos, $cuantos);
        if (!$write) {
            printf("\n");
            continue;
        }
        try {
            $inicio   = microtime(true);
            $template = $generator->generatePlayerTemplate();
            printf("  plantilla: %d widgets (%.0fs)\n", count($template), microtime(true) - $inicio);
            $views = $generator->instantiatePlayerViews($template);
            printf("  -> %d vistas de jugador\n\n", count($views));
        } catch (RuntimeException $e) {
            printf("  !! %s\n\n", $e->getMessage());
        }
        continue;
    }

    if (!Categorias::esValida($pedido)) {
        printf("!! categoria desconocida: %s\n\n", $pedido);
        continue;
    }
    if (!Categorias::tieneVistaBase($pedido)) {
        printf("!! %s no tiene vista base (Categorias::DEFS)\n\n", $pedido);
        continue;
    }

    printf("%s\n", Categorias::label($pedido));
    if (!$write) {
        printf("  1 llamada IA\n\n");
        continue;
    }

    try {
        $inicio = microtime(true);
        $r      = $generator->generateCluster($pedido, '');
        printf("  -> vista %d, %d widgets (%.0fs)\n", $r['view_id'], $r['created'], microtime(true) - $inicio);
        foreach ($r['skipped'] as $s) {
            printf("     descartado: %s\n", $s);
        }
        printf("\n");
    } catch (RuntimeException $e) {
        printf("  !! %s\n\n", $e->getMessage());
    }
}
