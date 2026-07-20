<?php
define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

$categorias = [
    'partidos'       => ['label' => 'Partidos',       'cols' => ['Distancia (m)', 'Sprints', 'Distancia sprint (m)', 'Vel. máx (km/h)', 'Player Load', 'Minutos']],
    'entrenamientos' => ['label' => 'Entrenamientos', 'cols' => ['Duración (min)', 'RPE', 'Carga', 'Distancia (m)', 'Player Load']],
    'fuerza'         => ['label' => 'Fuerza',          'cols' => ['Peso corporal (kg)', 'Press plano (kg)', 'Sentadilla (kg)', 'Peso muerto (kg)', 'Dominadas (reps)']],
    'nutricion'      => ['label' => 'Nutrición',       'cols' => ['Peso (kg)', '% graso', 'Masa muscular (kg)', 'Hidratación (L)']],
    'otros'          => ['label' => 'Otros datos',     'cols' => ['Valor 1', 'Valor 2']],
];

$categoria = $_GET['categoria'] ?? 'otros';
if (!isset($categorias[$categoria])) {
    $categoria = 'otros';
}
$cat = $categorias[$categoria];

$pageTitle = 'Cargar a mano — ' . $cat['label'];
$currentStep = 2;

$pdo = Database::get();
$players = $pdo->query('SELECT id, nombre, familia, sub_familia FROM players ORDER BY nombre')->fetchAll();

require __DIR__ . '/../app/views/head.php';
?>
<div class="page page-wide">
    <?php require __DIR__ . '/../app/views/confignav.php'; ?>

    <div class="page-header">
        <h1 class="page-title">Cargar a mano — <?= htmlspecialchars($cat['label']) ?></h1>
        <p class="page-sub">Las filas ya son los jugadores del plantel (quedan matcheados solos). Ajustá las columnas si querés, completá los valores y guardá. Los jugadores que dejes en blanco no se guardan.</p>
    </div>

    <?php if (empty($players)): ?>
        <div class="card">
            <div class="empty-state">Primero cargá el plantel. <a href="plantel.php">Ir a Plantel</a>.</div>
        </div>
    <?php else: ?>
        <div id="alert-box"></div>

        <div class="card">
            <div class="field-row">
                <div class="field">
                    <label for="ds-nombre">Nombre del dataset</label>
                    <input type="text" id="ds-nombre" placeholder="Ej: <?= htmlspecialchars($cat['label']) ?> — Mayo 2026">
                </div>
                <div class="field" style="align-self:flex-end;">
                    <button type="button" class="btn-secondary btn" id="add-col-btn">+ Agregar columna</button>
                </div>
            </div>

            <div class="grid-scroll">
                <table class="entry-grid" id="entry-grid"></table>
            </div>

            <div class="btn-row">
                <a class="btn-secondary btn" href="datos.php">Cancelar</a>
                <button type="button" class="btn" id="save-btn">Guardar dataset</button>
            </div>
        </div>
    <?php endif; ?>
</div>

<script src="<?= asset('../js/api.js') ?>"></script>
<script src="<?= asset('../js/wizard.js') ?>"></script>
<script>
const CATEGORIA = <?= json_encode($categoria) ?>;
const PLAYERS = <?= json_encode(array_map(fn($p) => ['id' => (int) $p['id'], 'nombre' => $p['nombre'], 'sub_familia' => $p['sub_familia']], $players), JSON_UNESCAPED_UNICODE) ?>;
let columns = <?= json_encode($cat['cols'], JSON_UNESCAPED_UNICODE) ?>;
const values = {}; // values[playerId][colName] = string

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

// Antes de re-renderizar, guardamos lo tipeado en el objeto values.
function captureInputs() {
    document.querySelectorAll('#entry-grid input.cell-input').forEach(inp => {
        const pid = inp.dataset.pid, col = inp.dataset.col;
        if (!values[pid]) values[pid] = {};
        values[pid][col] = inp.value;
    });
}

function renderGrid() {
    const grid = document.getElementById('entry-grid');
    let html = '<thead><tr><th class="sticky-col">Jugador</th>';
    columns.forEach((col, i) => {
        html += `<th>
            <input type="text" class="col-name" data-i="${i}" value="${escapeHtml(col)}">
            <button type="button" class="col-remove" data-i="${i}" title="Quitar columna">&times;</button>
        </th>`;
    });
    html += '</tr></thead><tbody>';

    PLAYERS.forEach(p => {
        html += `<tr><td class="sticky-col">${escapeHtml(p.nombre)}<span class="cell-sub">${escapeHtml(p.sub_familia || '')}</span></td>`;
        columns.forEach(col => {
            const v = (values[p.id] && values[p.id][col] != null) ? values[p.id][col] : '';
            html += `<td><input type="text" class="cell-input" data-pid="${p.id}" data-col="${escapeHtml(col)}" value="${escapeHtml(v)}"></td>`;
        });
        html += '</tr>';
    });
    html += '</tbody>';
    grid.innerHTML = html;

    // Renombrar columna
    grid.querySelectorAll('.col-name').forEach(inp => {
        inp.addEventListener('change', () => {
            captureInputs();
            const i = parseInt(inp.dataset.i, 10);
            const oldName = columns[i];
            const newName = inp.value.trim() || oldName;
            if (newName !== oldName) {
                // migrar valores de la columna vieja a la nueva
                PLAYERS.forEach(p => {
                    if (values[p.id] && values[p.id][oldName] != null) {
                        values[p.id][newName] = values[p.id][oldName];
                        delete values[p.id][oldName];
                    }
                });
                columns[i] = newName;
            }
            renderGrid();
        });
    });
    // Quitar columna
    grid.querySelectorAll('.col-remove').forEach(btn => {
        btn.addEventListener('click', () => {
            captureInputs();
            columns.splice(parseInt(btn.dataset.i, 10), 1);
            renderGrid();
        });
    });
}

document.getElementById('add-col-btn')?.addEventListener('click', () => {
    captureInputs();
    let n = columns.length + 1;
    let name = 'Columna ' + n;
    while (columns.includes(name)) { n++; name = 'Columna ' + n; }
    columns.push(name);
    renderGrid();
});

document.getElementById('save-btn')?.addEventListener('click', async () => {
    captureInputs();
    const nombre = document.getElementById('ds-nombre').value.trim();
    const alertBox = document.getElementById('alert-box');
    if (!nombre) { showAlert(alertBox, 'Poné un nombre para el dataset.', 'error'); return; }
    if (columns.length === 0) { showAlert(alertBox, 'Agregá al menos una columna.', 'error'); return; }

    const rows = PLAYERS.map(p => ({ player_id: p.id, values: values[p.id] || {} }));
    const payload = { categoria: CATEGORIA, nombre, columns, rows };

    const btn = document.getElementById('save-btn');
    btn.disabled = true;
    btn.textContent = 'Guardando...';
    const fd = new FormData();
    fd.append('payload', JSON.stringify(payload));
    try {
        const result = await Api.postForm('../api/manual_dataset.php', fd);
        window.location.href = 'datos.php';
    } catch (err) {
        showAlert(alertBox, err.message, 'error');
        btn.disabled = false;
        btn.textContent = 'Guardar dataset';
    }
});

renderGrid();
</script>
</body>
</html>
