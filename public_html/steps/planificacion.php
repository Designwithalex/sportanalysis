<?php
require __DIR__ . '/../app/bootstrap_page.php';
require_once __DIR__ . '/../app/Planificacion.php';
requireAuth();

$pageTitle = 'Planificación — SportAnalysis';

$pdo    = Database::get();
$clubId = Auth::clubId();

// La semana viene por querystring para que el link sea compartible ("mirá la semana del 15").
// Se normaliza al lunes: cualquier día de la semana lleva a la misma pantalla.
$semana = Planificacion::lunesDe(trim((string) ($_GET['semana'] ?? date('Y-m-d'))));

// Solo la UI. api/planificacion.php vuelve a validar el permiso en cada escritura: cambiar esto
// desde la consola del navegador no habilita nada.
$puedeEditar = Auth::esAdminClub();

// Columnas que pueden ser objetivo: las numéricas de los partidos, que es de donde sale el 100%.
$columnasStmt = $pdo->prepare('SELECT column_schema FROM datasets WHERE club_id = :club AND categoria = "partidos"');
$columnasStmt->execute(['club' => $clubId]);
$columnasDisponibles = [];
foreach ($columnasStmt->fetchAll(PDO::FETCH_COLUMN) as $raw) {
    foreach ((json_decode((string) $raw, true) ?: []) as $col => $tipo) {
        if ($tipo === 'numerica') {
            $columnasDisponibles[(string) $col] = true;
        }
    }
}
$columnasDisponibles = array_keys($columnasDisponibles);
sort($columnasDisponibles);

$hayPartidos = $columnasDisponibles !== [];

// La acción del appbar vuelve al tablero: la planificación se abre DESDE ahí, así que el camino
// de vuelta tiene que ser el mismo botón y no el atrás del navegador.
$appbarAction = ['href' => 'analysis.php', 'label' => 'Tableros', 'icon' => '←'];

require __DIR__ . '/../app/views/head.php';
?>
<?php require __DIR__ . '/../app/views/appbar.php'; ?>

<div class="page">
    <?php if (!$hayPartidos): ?>
        <div class="card">
            <div class="card-title">Todavía no se puede planificar</div>
            <div class="card-sub">
                La planificación se expresa en <strong>% de un partido</strong>, así que necesita al menos un
                partido cargado para saber cuánto es el 100% de cada línea. Subí los datos de un partido en
                el paso de Datos y volvé acá.
            </div>
            <div class="btn-row"><a class="btn" href="datos.php">Ir a cargar datos</a></div>
        </div>
    <?php else: ?>

        <div class="plan-head">
            <div>
                <p class="plan-eyebrow">Planificación semanal</p>
                <h1 class="plan-title" id="plan-titulo">Semana del …</h1>
            </div>
            <div class="plan-nav">
                <button class="btn-secondary btn" id="plan-prev" type="button" aria-label="Semana anterior">‹ Anterior</button>
                <button class="btn-secondary btn" id="plan-hoy" type="button">Esta semana</button>
                <button class="btn-secondary btn" id="plan-next" type="button" aria-label="Semana siguiente">Siguiente ›</button>
                <button class="btn-secondary btn" id="plan-pdf" type="button" title="Exportar la planificación a PDF">Exportar PDF</button>
            </div>
        </div>

        <!-- Encabezado que solo existe en papel: el PDF va a los jugadores y tiene que decir de qué
             club y de qué semana es sin depender de la navegación, que no se imprime. -->
        <div class="print-header" id="print-header">
            <h1 class="print-title" id="print-title">Planificación</h1>
            <div class="print-meta"></div>
        </div>

        <!-- La equivalencia del pedido: 60 + 70 + 60 = 190% ≈ 1,9 partidos de carga en la semana. -->
        <div class="plan-resumen" id="plan-resumen" hidden>
            <div class="plan-resumen-num"><span id="plan-suma">0</span><span class="plan-resumen-pct">%</span></div>
            <div class="plan-resumen-txt">
                de carga planificada en la semana<br>
                <strong><span id="plan-equiv">0</span> partidos</strong> equivalentes
            </div>
            <div class="plan-leyenda" aria-label="Referencia de colores">
                <span class="sem sem-verde"></span> en el objetivo (±<span id="plan-tol">10</span>%)
                <span class="sem sem-amarillo"></span> por debajo
                <span class="sem sem-rojo"></span> se pasó
            </div>
        </div>

        <div id="plan-alert"></div>

        <div class="card plan-metricas-card print-hide">
            <div class="card-title">Métricas del plan</div>
            <div class="card-sub">
                Qué se planifica y se compara. Se guardan en esta semana y las semanas nuevas arrancan con las mismas.
            </div>
            <div class="plan-chips" id="plan-metricas"></div>
            <?php if ($puedeEditar): ?>
                <div class="field-row plan-metrica-add">
                    <div class="field">
                        <label for="plan-metrica-select">Agregar métrica</label>
                        <select id="plan-metrica-select">
                            <?php foreach ($columnasDisponibles as $c): ?>
                                <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button class="btn-secondary btn" id="plan-metrica-add" type="button">Agregar</button>
                </div>
            <?php endif; ?>
        </div>

        <div id="plan-dias"></div>

        <div class="card" id="plan-semana-card" hidden>
            <div class="card-title">Semana completa</div>
            <div class="card-sub">Suma de los días planificados: cuánto se pidió y cuánto se hizo, por línea.</div>
            <div id="plan-semana"></div>
        </div>

    <?php endif; ?>
</div>

<script src="<?= asset('../js/api.js') ?>"></script>
<script>
const PLAN_SEMANA   = <?= json_encode($semana, JSON_UNESCAPED_UNICODE) ?>;
const PLAN_EDITABLE = <?= $puedeEditar ? 'true' : 'false' ?>;
const PLAN_CLUB     = <?= json_encode(Auth::user()['club_nombre'] ?? '', JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= asset('../js/planificacion.js') ?>"></script>
</body>
</html>
