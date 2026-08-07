<?php

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/WidgetRenderer.php';   // splitColumn(): qué columna trae el tramo
require_once __DIR__ . '/Puestos.php';          // orden 1-15 de las líneas

/**
 * Planificación semanal de carga: cuánto se le pide al plantel cada día, en % de un partido.
 *
 * EL MODELO EN UNA LÍNEA. El cuerpo técnico dice "lunes al 60%, miércoles al 70%, viernes al 60%".
 * Eso son 190% ≈ 1,9 partidos de carga en la semana. El 100% de referencia sale de los partidos
 * ya jugados, POR LÍNEA: el 100% de un Front Row son 4.332 m y el de un Outside Back 6.373 m,
 * así que "al 60%" no es el mismo número para los dos.
 *
 * EL BASELINE NO SE GUARDA, SE CALCULA. Es tentador congelarlo en una tabla y es un error: cada
 * partido nuevo lo mueve, y una copia vieja haría que "60%" dejara de significar el 60% de lo que
 * el plantel realmente corre. Se recalcula siempre desde `partidos`. El costo es una consulta
 * agregada por métrica, sobre datos que ya están indexados por club.
 *
 * POR QUÉ SIEMPRE `__split = all`. Los CSV del GPS traen una fila por jugador Y POR TRAMO, y los
 * tramos están anidados (1er tiempo + 2do tiempo = game ⊂ all). Promediar sin recortar da el
 * triple de metros y todos los objetivos saldrían inflados. Es el mismo recorte que hacen las
 * vistas base; acá se aplica en SQL porque estas consultas no pasan por WidgetRenderer.
 */
final class Planificacion
{
    /**
     * Banda de tolerancia del semáforo, en porcentaje del objetivo.
     *
     * El pedido original decía "verde: cumplió/superó" y "rojo: se pasó", que son la misma cosa
     * dicha de dos formas: sin una banda, superar el objetivo tendría que pintar verde y rojo a la
     * vez. Con ±10%, verde es "dio en el blanco", amarillo es "faltó" y rojo es "se pasó", que es
     * lo que interesa vigilar por carga y lesiones. 10% es además el orden de la variación normal
     * de una sesión: más angosto y el tablero se llena de colores que no significan nada.
     */
    public const TOLERANCIA = 10.0;

    /** Métricas por defecto de un plan nuevo: las tres que nombra el cuerpo técnico. */
    public const METRICAS_DEFAULT = [
        ['columna' => 'Distance (metres)',  'label' => 'Distancia',      'unidad' => 'm'],
        ['columna' => 'Sprint Distance (m)', 'label' => 'Dist. sprint',  'unidad' => 'm'],
        ['columna' => 'Player Load',        'label' => 'Player Load',    'unidad' => ''],
    ];

    // ── Semanas ──────────────────────────────────────────────────────────────────────────────

    /**
     * El lunes de la semana en la que cae una fecha.
     *
     * Todo el módulo indexa las semanas por su lunes: es lo que hace que `uq_planes_club_semana`
     * signifique "una planificación por semana". Sin normalizar, dos personas planificando la misma
     * semana desde días distintos crearían dos planes que no se ven entre sí.
     */
    public static function lunesDe(string $fecha): string
    {
        $d = new DateTimeImmutable($fecha);

        // 'N' es 1 (lunes) a 7 (domingo), así que restar N-1 días siempre cae en el lunes. Con 'w'
        // (0 = domingo) el domingo se iría a la semana siguiente.
        return $d->modify('-' . ((int) $d->format('N') - 1) . ' days')->format('Y-m-d');
    }

    /** Los 7 días de la semana que arranca ese lunes, en ISO. @return string[] */
    public static function diasDeLaSemana(string $lunes): array
    {
        $d = new DateTimeImmutable($lunes);
        $out = [];
        for ($i = 0; $i < 7; $i++) {
            $out[] = $d->modify("+$i days")->format('Y-m-d');
        }

        return $out;
    }

    // ── Baseline y realizado ─────────────────────────────────────────────────────────────────

    /**
     * El 100%: promedio POR JUGADOR Y POR PARTIDO de cada métrica, agrupado por línea.
     *
     * @param  array<int,array{columna:string}> $metricas
     * @return array<string,array<string,float>> [linea][columna] = valor
     */
    public static function baselinePorLinea(PDO $pdo, int $clubId, array $metricas): array
    {
        return self::promedioPorLinea($pdo, $clubId, $metricas, 'partidos', null);
    }

    /**
     * Lo REALIZADO en un día: promedio por jugador de las sesiones de entrenamiento de esa fecha.
     *
     * Es comparable contra el objetivo porque las dos puntas son lo mismo —promedio por jugador
     * dentro de la línea— y las dos recortan al tramo `all`.
     *
     * @param  array<int,array{columna:string}> $metricas
     * @return array<string,array<string,float>> [linea][columna] = valor
     */
    public static function realizadoPorLinea(PDO $pdo, int $clubId, string $fecha, array $metricas): array
    {
        return self::promedioPorLinea($pdo, $clubId, $metricas, 'entrenamientos', $fecha);
    }

    /**
     * Motor común de las dos anteriores.
     *
     * @param  array<int,array{columna:string}> $metricas
     * @return array<string,array<string,float>>
     */
    private static function promedioPorLinea(
        PDO $pdo,
        int $clubId,
        array $metricas,
        string $categoria,
        ?string $fecha
    ): array {
        $datasets = self::datasetsDe($pdo, $clubId, $categoria, $fecha);
        if (!$datasets || !$metricas) {
            return [];
        }

        // Los datasets se agrupan por su columna de tramo: casi siempre es una sola ("Split Name"),
        // pero nada garantiza que dos exportadores distintos la llamen igual, y el recorte tiene
        // que aplicarse con el nombre que le corresponde a cada archivo.
        $porSplit = [];
        foreach ($datasets as $d) {
            $porSplit[(string) $d['split_col']][] = (int) $d['id'];
        }

        $out = [];
        foreach ($porSplit as $splitCol => $ids) {
            $ph = implode(',', array_fill(0, count($ids), '?'));

            // El recorte al tramo entero. Los datasets sin tramos no lo llevan: pedirles
            // `Split Name = all` no devolvería ninguna fila.
            $filtroSplit = $splitCol !== ''
                ? "AND JSON_UNQUOTE(JSON_EXTRACT(r.raw_data, CONCAT('$.\"', ?, '\"'))) = 'all'"
                : '';

            foreach ($metricas as $m) {
                // El nombre de columna viaja como PARÁMETRO dentro del path JSON, nunca concatenado
                // al SQL: sale de plan_metricas, que se edita desde el cliente.
                $sql = "SELECT p.sub_familia AS linea,
                               AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(r.raw_data, CONCAT('$.\"', ?, '\"'))) AS DECIMAL(18,4))) AS valor
                        FROM dataset_rows r
                        INNER JOIN datasets d ON d.id = r.dataset_id AND d.club_id = r.club_id
                        INNER JOIN players  p ON p.id = r.player_id  AND p.club_id = r.club_id
                        WHERE r.club_id = ? AND r.dataset_id IN ($ph) AND r.match_status = 'matched'
                              AND p.sub_familia IS NOT NULL AND p.sub_familia <> ''
                              $filtroSplit
                        GROUP BY p.sub_familia";

                $params = [$m['columna'], $clubId, ...$ids];
                if ($splitCol !== '') {
                    $params[] = $splitCol;
                }

                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    if ($row['valor'] === null) {
                        continue;
                    }
                    // Varios grupos de split suman al mismo casillero: se promedian ponderando por
                    // igual, que es lo correcto mientras haya un solo grupo (el caso real).
                    $linea = (string) $row['linea'];
                    $col   = $m['columna'];
                    $out[$linea][$col] = isset($out[$linea][$col])
                        ? ($out[$linea][$col] + (float) $row['valor']) / 2
                        : (float) $row['valor'];
                }
            }
        }

        return $out;
    }

    /**
     * Datasets de una categoría, con la columna que lleva el tramo de cada uno.
     *
     * @return array<int,array{id:int,split_col:string}>
     */
    private static function datasetsDe(PDO $pdo, int $clubId, string $categoria, ?string $fecha): array
    {
        $sql = 'SELECT id, column_schema FROM datasets WHERE club_id = :club AND categoria = :cat';
        $params = ['club' => $clubId, 'cat' => $categoria];

        if ($fecha !== null) {
            // La fecha REAL de la sesión, no la de carga: es todo el punto del módulo.
            $sql .= ' AND fecha_sesion = :fecha';
            $params['fecha'] = $fecha;
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $d) {
            $schema = json_decode((string) $d['column_schema'], true) ?: [];
            $out[] = [
                'id'        => (int) $d['id'],
                'split_col' => WidgetRenderer::splitColumn($schema) ?? '',
            ];
        }

        return $out;
    }

    // ── Semáforo ─────────────────────────────────────────────────────────────────────────────

    /**
     * Estado de un realizado contra su objetivo.
     *
     * @return string 'verde' (en el objetivo), 'amarillo' (faltó), 'rojo' (se pasó), 'sd' (sin dato)
     */
    public static function estado(?float $realizado, ?float $objetivo): string
    {
        if ($realizado === null || $objetivo === null || $objetivo <= 0.0) {
            return 'sd';
        }

        $pct = $realizado / $objetivo * 100.0;

        if ($pct < 100.0 - self::TOLERANCIA) {
            return 'amarillo';
        }
        if ($pct > 100.0 + self::TOLERANCIA) {
            return 'rojo';
        }

        return 'verde';
    }

    /** Las líneas del plantel en orden de camiseta, para que las tablas se lean 1-15. */
    public static function lineasOrdenadas(PDO $pdo, int $clubId): array
    {
        $stmt = $pdo->prepare(
            'SELECT DISTINCT sub_familia FROM players
             WHERE club_id = :club AND sub_familia IS NOT NULL AND sub_familia <> ""'
        );
        $stmt->execute(['club' => $clubId]);
        $lineas = $stmt->fetchAll(PDO::FETCH_COLUMN);

        usort($lineas, fn ($a, $b) => Puestos::comparar((string) $a, (string) $b) ?? strcasecmp((string) $a, (string) $b));

        return $lineas;
    }
}
