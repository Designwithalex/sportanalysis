<?php
/**
 * Corre un archivo .sql de migración sentencia por sentencia.
 *
 * PDO no ejecuta un archivo entero de una: con DDL mezclado y la emulación de prepares activada,
 * el multi-query falla o corre a medias. Y separar a ojo es donde se cuela el error — la versión
 * ingenua (explode por ";") descarta cualquier sentencia que venga precedida por un comentario,
 * que en este proyecto son todas, y termina reportando éxito sin haber creado nada.
 *
 * Por eso el orden es: primero sacar los comentarios de línea, DESPUÉS separar por ";".
 *
 *   php sql/correr_migracion.php sql/migration_2026_08_planificacion.sql
 *   php sql/correr_migracion.php archivo.sql --write
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';

$write = in_array('--write', $argv, true);
$rutas = array_values(array_filter(array_slice($argv, 1), fn ($a) => !str_starts_with($a, '--')));

if (!$rutas || !is_file($rutas[0])) {
    exit("Uso: php sql/correr_migracion.php <archivo.sql> [--write]\n");
}

$sql = file_get_contents($rutas[0]);

// 1) Fuera los comentarios de línea. Van primero: si se separa antes, cada sentencia arrastra el
//    comentario que la precede y cualquier chequeo de "esto empieza con --" la descarta entera.
$sinComentarios = implode("\n", array_filter(
    array_map(fn ($l) => rtrim($l), explode("\n", $sql)),
    fn ($l) => !str_starts_with(ltrim($l), '--')
));

// 2) Recién ahora, separar. Los COMMENT '...' de este proyecto no contienen ";".
$sentencias = array_values(array_filter(
    array_map('trim', explode(';', $sinComentarios)),
    fn ($s) => $s !== ''
));

printf("%s — %d sentencias en %s\n\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)', count($sentencias), basename($rutas[0]));

foreach ($sentencias as $i => $s) {
    $primera = strtok($s, "\n");
    printf("  %2d. %s\n", $i + 1, mb_substr(trim($primera), 0, 88));
}

if (!$write) {
    printf("\nnada ejecutado.\n");
    exit;
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);

printf("\n");
foreach ($sentencias as $i => $s) {
    try {
        $pdo->exec($s);
        printf("  ok  %2d\n", $i + 1);
    } catch (PDOException $e) {
        // No se sigue: una migración a medias es peor que una que no corrió, porque la próxima
        // corrida choca con lo que sí se creó y no se sabe en qué estado quedó.
        printf("  FALLO en la sentencia %d: %s\n", $i + 1, $e->getMessage());
        printf("\n%s\n", mb_substr($s, 0, 400));
        exit(1);
    }
}

printf("\nmigración aplicada.\n");
