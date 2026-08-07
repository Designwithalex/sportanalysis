<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/Planificacion.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$pdo = Database::get();
requireMethod(['GET', 'POST']);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    handleSemana($pdo);
    exit;
}

// ESCRIBIR ES DE ADMIN. La planificación es UNA por semana y por club: el que la edita le cambia
// los objetivos a todo el plantel, igual que quien regenera una vista base. Leerla, en cambio, la
// puede leer cualquier miembro — para eso está.
//
// esAdminClub() y no requireNivel('admin_club'): requireNivel compara EXACTO y dejaría afuera al
// superadmin.
if (!Auth::esAdminClub()) {
    respondError(403, 'Solo un administrador del club puede editar la planificación.');
}

switch ($_POST['action'] ?? '') {
    case 'guardar_dia':
        handleGuardarDia($pdo);
        break;
    case 'metricas':
        handleMetricas($pdo);
        break;
    default:
        respondError(400, 'Acción desconocida.');
}

/**
 * El plan de una semana, con objetivos y realizado ya calculados.
 *
 * Devuelve la semana ARMADA aunque no exista el plan en la base: una semana sin planificar tiene
 * los siete días en 0%, y eso es una respuesta válida, no un 404. Crear la fila recién cuando
 * alguien escribe un porcentaje evita llenar la tabla de semanas vacías por navegar.
 */
function handleSemana(PDO $pdo): void
{
    $clubId = Auth::clubId();
    $lunes  = Planificacion::lunesDe(trim((string) ($_GET['semana'] ?? date('Y-m-d'))));

    $plan = planDeLaSemana($pdo, $clubId, $lunes, false);

    $metricas = $plan ? metricasDelPlan($pdo, $clubId, (int) $plan['id']) : Planificacion::METRICAS_DEFAULT;
    $porDia   = $plan ? diasDelPlan($pdo, $clubId, (int) $plan['id']) : [];

    $baseline = Planificacion::baselinePorLinea($pdo, $clubId, $metricas);
    $lineas   = Planificacion::lineasOrdenadas($pdo, $clubId);

    $dias = [];
    $sumaPct = 0;
    foreach (Planificacion::diasDeLaSemana($lunes) as $fecha) {
        $d   = $porDia[$fecha] ?? null;
        $pct = (int) ($d['porcentaje'] ?? 0);
        $sumaPct += $pct;

        // El realizado solo se busca si el día tiene carga planificada: sin plan no hay contra qué
        // comparar, y sería una consulta agregada por métrica al pedo por cada día vacío.
        $realizado = $pct > 0 ? Planificacion::realizadoPorLinea($pdo, $clubId, $fecha, $metricas) : [];

        $filas = [];
        foreach ($lineas as $linea) {
            $celdas = [];
            foreach ($metricas as $m) {
                $base = $baseline[$linea][$m['columna']] ?? null;
                $obj  = $base !== null ? $base * $pct / 100 : null;
                $real = $realizado[$linea][$m['columna']] ?? null;

                $celdas[$m['columna']] = [
                    'objetivo'  => $obj,
                    'realizado' => $real,
                    'estado'    => Planificacion::estado($real, $obj),
                ];
            }
            $filas[] = ['linea' => $linea, 'celdas' => $celdas];
        }

        $dias[] = [
            'fecha'      => $fecha,
            'porcentaje' => $pct,
            'nota'       => $d['nota'] ?? '',
            'tiene_datos' => $realizado !== [],
            'lineas'     => $filas,
        ];
    }

    echo json_encode([
        'ok'       => true,
        'semana'   => $lunes,
        'plan_id'  => $plan['id'] ?? null,
        'metricas' => $metricas,
        'lineas'   => $lineas,
        'baseline' => $baseline,
        'dias'     => $dias,
        // La equivalencia del ítem 3: 60+70+60 = 190% ≈ 1,9 partidos de carga en la semana.
        'suma_porcentaje' => $sumaPct,
        'equivalente_partidos' => round($sumaPct / 100, 2),
        'tolerancia' => Planificacion::TOLERANCIA,
        'puede_editar' => Auth::esAdminClub(),
    ], JSON_UNESCAPED_UNICODE);
}

function handleGuardarDia(PDO $pdo): void
{
    $clubId = Auth::clubId();
    $fecha  = trim((string) ($_POST['fecha'] ?? ''));

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha) || !strtotime($fecha)) {
        respondError(422, 'Fecha inválida.');
    }

    $pct = (int) ($_POST['porcentaje'] ?? 0);
    if ($pct < 0 || $pct > 500) {
        respondError(422, 'El porcentaje tiene que estar entre 0 y 500.');
    }

    $nota = mb_substr(trim((string) ($_POST['nota'] ?? '')), 0, 255);

    // La semana se deduce de la fecha, NO viene del cliente: si viniera, se podría colgar un lunes
    // de la semana de otro plan y el día aparecería en una semana que no le corresponde.
    $lunes = Planificacion::lunesDe($fecha);
    $plan  = planDeLaSemana($pdo, $clubId, $lunes, true);

    $stmt = $pdo->prepare(
        'INSERT INTO plan_dias (club_id, plan_id, fecha, porcentaje, nota)
         VALUES (:club, :plan, :fecha, :pct, :nota)
         ON DUPLICATE KEY UPDATE porcentaje = VALUES(porcentaje), nota = VALUES(nota)'
    );
    $stmt->execute([
        'club'  => $clubId,
        'plan'  => $plan['id'],
        'fecha' => $fecha,
        'pct'   => $pct,
        'nota'  => $nota !== '' ? $nota : null,
    ]);

    echo json_encode(['ok' => true, 'plan_id' => (int) $plan['id']], JSON_UNESCAPED_UNICODE);
}

/** Reemplaza la lista de métricas del plan. */
function handleMetricas(PDO $pdo): void
{
    $clubId = Auth::clubId();
    $lunes  = Planificacion::lunesDe(trim((string) ($_POST['semana'] ?? date('Y-m-d'))));
    $plan   = planDeLaSemana($pdo, $clubId, $lunes, true);

    $columnas = json_decode((string) ($_POST['columnas'] ?? '[]'), true);
    if (!is_array($columnas)) {
        respondError(422, 'Lista de métricas inválida.');
    }
    if (count($columnas) > 6) {
        respondError(422, 'Máximo 6 métricas por plan: más no entran a lo ancho de una hoja.');
    }

    // Las columnas se validan contra las que REALMENTE existen en los datasets de partidos del
    // club: si no, un plan podría quedar apuntando a una columna inexistente y todos sus objetivos
    // saldrían vacíos sin explicación.
    $validas = columnasNumericasDePartidos($pdo, $clubId);

    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM plan_metricas WHERE plan_id = :plan AND club_id = :club')
            ->execute(['plan' => $plan['id'], 'club' => $clubId]);

        $ins = $pdo->prepare(
            'INSERT INTO plan_metricas (club_id, plan_id, columna, label, unidad, position)
             VALUES (:club, :plan, :col, :label, :unidad, :pos)'
        );

        $pos = 0;
        foreach ($columnas as $col) {
            $col = trim((string) $col);
            if (!isset($validas[$col])) {
                continue;
            }
            $ins->execute([
                'club'   => $clubId,
                'plan'   => $plan['id'],
                'col'    => $col,
                'label'  => mb_substr($validas[$col]['label'], 0, 80),
                'unidad' => $validas[$col]['unidad'],
                'pos'    => $pos++,
            ]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'No se pudieron guardar las métricas: ' . $e->getMessage());
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}

// ── Ayudas ───────────────────────────────────────────────────────────────────────────────────

/**
 * El plan de esa semana. Con $crear, lo da de alta si no existe, heredando las métricas de la
 * última semana planificada — que es la "preferencia guardada" del pedido: se elige una vez y las
 * semanas siguientes arrancan igual, sin una tabla de preferencias aparte.
 *
 * @return array{id:int}|null
 */
function planDeLaSemana(PDO $pdo, int $clubId, string $lunes, bool $crear): ?array
{
    $stmt = $pdo->prepare('SELECT id FROM planes_semana WHERE club_id = :club AND fecha_inicio = :lunes');
    $stmt->execute(['club' => $clubId, 'lunes' => $lunes]);
    $plan = $stmt->fetch();

    if ($plan || !$crear) {
        return $plan ?: null;
    }

    $pdo->prepare('INSERT INTO planes_semana (club_id, fecha_inicio) VALUES (:club, :lunes)')
        ->execute(['club' => $clubId, 'lunes' => $lunes]);
    $planId = (int) $pdo->lastInsertId();

    // Hereda de la última semana planificada; si es la primera de todas, los defaults.
    $prev = $pdo->prepare(
        'SELECT id FROM planes_semana WHERE club_id = :club AND fecha_inicio < :lunes
         ORDER BY fecha_inicio DESC LIMIT 1'
    );
    $prev->execute(['club' => $clubId, 'lunes' => $lunes]);
    $prevId = $prev->fetchColumn();

    $metricas = $prevId ? metricasDelPlan($pdo, $clubId, (int) $prevId) : Planificacion::METRICAS_DEFAULT;

    $ins = $pdo->prepare(
        'INSERT INTO plan_metricas (club_id, plan_id, columna, label, unidad, position)
         VALUES (:club, :plan, :col, :label, :unidad, :pos)'
    );
    foreach (array_values($metricas) as $i => $m) {
        $ins->execute([
            'club'   => $clubId,
            'plan'   => $planId,
            'col'    => $m['columna'],
            'label'  => $m['label'],
            'unidad' => $m['unidad'] ?? '',
            'pos'    => $i,
        ]);
    }

    return ['id' => $planId];
}

/** @return array<int,array{columna:string,label:string,unidad:string}> */
function metricasDelPlan(PDO $pdo, int $clubId, int $planId): array
{
    $stmt = $pdo->prepare(
        'SELECT columna, label, unidad FROM plan_metricas
         WHERE plan_id = :plan AND club_id = :club ORDER BY position, id'
    );
    $stmt->execute(['plan' => $planId, 'club' => $clubId]);
    $out = $stmt->fetchAll();

    return $out ?: Planificacion::METRICAS_DEFAULT;
}

/** @return array<string,array{porcentaje:int,nota:string}> indexado por fecha */
function diasDelPlan(PDO $pdo, int $clubId, int $planId): array
{
    $stmt = $pdo->prepare(
        'SELECT fecha, porcentaje, nota FROM plan_dias WHERE plan_id = :plan AND club_id = :club'
    );
    $stmt->execute(['plan' => $planId, 'club' => $clubId]);

    $out = [];
    foreach ($stmt->fetchAll() as $d) {
        $out[(string) $d['fecha']] = ['porcentaje' => (int) $d['porcentaje'], 'nota' => (string) ($d['nota'] ?? '')];
    }

    return $out;
}

/**
 * Columnas numéricas de los datasets de `partidos`, que son las que pueden ser un objetivo.
 * @return array<string,array{label:string,unidad:string}>
 */
function columnasNumericasDePartidos(PDO $pdo, int $clubId): array
{
    $stmt = $pdo->prepare('SELECT column_schema FROM datasets WHERE club_id = :club AND categoria = "partidos"');
    $stmt->execute(['club' => $clubId]);

    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
        foreach ((json_decode((string) $raw, true) ?: []) as $col => $tipo) {
            if ($tipo !== 'numerica') {
                continue;
            }
            // La unidad se deduce del propio encabezado del GPS, que la trae entre paréntesis.
            $unidad = '';
            if (preg_match('/\((m|metres|km\/h|kg|min|secs)\)/i', (string) $col, $mm)) {
                $unidad = strtolower($mm[1]) === 'metres' ? 'm' : strtolower($mm[1]);
            }
            $out[(string) $col] = ['label' => (string) $col, 'unidad' => $unidad];
        }
    }

    return $out;
}
