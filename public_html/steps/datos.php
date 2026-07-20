<?php
define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

$pageTitle = 'Datos — SportAnalysis';
$currentStep = 2;

$pdo = Database::get();
$datasets = $pdo->query(
    'SELECT d.id, d.nombre, d.categoria, d.original_filename, d.column_schema, d.player_column_name, d.uploaded_at,
            COUNT(r.id) AS row_count,
            SUM(CASE WHEN r.match_status = "matched" THEN 1 ELSE 0 END) AS matched_count
     FROM datasets d
     LEFT JOIN dataset_rows r ON r.dataset_id = d.id
     GROUP BY d.id
     ORDER BY d.categoria, d.uploaded_at DESC'
)->fetchAll();

$categorias = [
    'partidos' => 'Partidos',
    'entrenamientos' => 'Entrenamientos',
    'fuerza' => 'Fuerza',
    'nutricion' => 'Nutrición',
    'otros' => 'Otros datos',
];

$byCategoria = array_fill_keys(array_keys($categorias), []);
foreach ($datasets as $d) {
    $byCategoria[$d['categoria']][] = $d;
}

require __DIR__ . '/../app/views/head.php';
$appbarAction = ['href' => 'analysis.php', 'label' => 'Ir a SportAnalysis', 'icon' => '→', 'primary' => true];
require __DIR__ . '/../app/views/appbar.php';
?>
<div class="page">
    <?php require __DIR__ . '/../app/views/confignav.php'; ?>

    <div class="page-header">
        <h1 class="page-title">Datos</h1>
        <p class="page-sub">Subí cada partido, entrenamiento o test como su propio CSV, dentro de una de las categorías. Después, en SportAnalysis, la IA cruza los que necesites (ej: promediar entre varios partidos). Los datos se guardan crudos.</p>
    </div>

    <?php if (!empty($datasets)): ?>
        <div class="card base-cta-card">
            <div>
                <div class="card-title">✦ Generá tus vistas base con IA</div>
                <div class="card-sub" style="margin-bottom:0;">Ya tenés datos cargados. Dejá que la IA arme un tablero por cada tipo de dato (partidos, entrenamientos, fuerza…) más un overview por jugador. Podés hacerlo automático o guiándola vos.</div>
            </div>
            <a class="btn" href="analysis.php?base_views=1"><span class="btn-spark" aria-hidden="true">✦</span> Generar vistas base →</a>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-title">Subir datos</div>
        <div class="card-sub">Elegí la categoría y subí uno o varios CSV. Detectamos el tipo de cada columna y cuál identifica al jugador.</div>

        <div id="alert-box"></div>

        <form id="upload-form">
            <div class="field">
                <label>Categoría</label>
                <div class="category-picker" id="category-picker">
                    <?php foreach ($categorias as $key => $label): ?>
                        <label class="category-chip">
                            <input type="radio" name="categoria" value="<?= $key ?>" <?= $key === 'partidos' ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($label) ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
            </div>
            <div class="field" id="name-field">
                <label for="dataset-name">Nombre del dataset (opcional)</label>
                <input type="text" id="dataset-name" placeholder="Ej: vs. Newman — Jul 2026">
            </div>
            <div class="dropzone" id="dropzone" tabindex="0">
                <input type="file" id="csv-input" name="csv" accept=".csv" multiple>
                <div class="dropzone-label" id="dropzone-label">Arrastrá uno o varios CSV acá o hacé click para elegirlos</div>
                <div class="dropzone-hint">Podés seleccionar varios archivos a la vez</div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn" id="submit-btn" disabled>Subir datos</button>
            </div>
        </form>
    </div>

    <!-- Reconciliación inline: aparece cuando hay nombres sin resolver en algún dataset -->
    <div class="card" id="recon-card" style="display:none;">
        <div class="card-title">Nombres por resolver</div>
        <div class="card-sub">Estos nombres no matchearon con el plantel. Resolvelos para que sus datos entren en los análisis.</div>
        <div id="recon-container"></div>
    </div>

    <div class="card">
        <div class="card-title">Datasets por categoría</div>
        <div class="card-sub"><span id="dataset-count"><?= count($datasets) ?></span> datasets en total. En cada categoría podés subir un CSV (arriba) o cargar los datos a mano sobre el plantel.</div>

        <?php foreach ($categorias as $key => $label): ?>
            <div class="dataset-group">
                <div class="dataset-group-head">
                    <div class="dataset-group-title"><?= htmlspecialchars($label) ?> <span class="dataset-group-count"><?= count($byCategoria[$key]) ?></span></div>
                    <a class="btn-secondary btn btn-sm" href="carga_manual.php?categoria=<?= $key ?>">+ Cargar a mano</a>
                </div>
                <?php if (empty($byCategoria[$key])): ?>
                    <div class="dataset-empty-note">Sin datasets todavía.</div>
                <?php else: ?>
                    <div class="dataset-list">
                        <?php foreach ($byCategoria[$key] as $d): ?>
                            <?php
                            $schema = json_decode($d['column_schema'], true);
                            $colCount = count($schema);
                            $unmatched = $d['row_count'] - $d['matched_count'];
                            ?>
                            <div class="dataset-row" data-id="<?= $d['id'] ?>">
                                <div>
                                    <div class="dataset-name"><?= htmlspecialchars($d['nombre']) ?></div>
                                    <div class="dataset-meta">
                                        <?= $d['row_count'] ?> filas · <?= $colCount ?> columnas ·
                                        <?= $d['player_column_name'] ? htmlspecialchars($d['player_column_name']) . ' como columna de jugador' : 'columna de jugador ambigua' ?>
                                        <?php if ($unmatched > 0): ?>
                                            · <span class="badge badge-unmatched"><?= $unmatched ?> sin matchear</span>
                                        <?php else: ?>
                                            · <span class="badge badge-matched">todo matcheado</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dataset-actions">
                                    <button class="btn-icon btn-delete" data-id="<?= $d['id'] ?>">Eliminar</button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <div class="btn-row">
        <a class="btn-secondary btn" href="plantel.php">← Plantel</a>
        <a class="btn" href="analysis.php">Ir a SportAnalysis →</a>
    </div>
</div>

<script src="<?= asset('../js/api.js') ?>"></script>
<script src="<?= asset('../js/wizard.js') ?>"></script>
<script>
const dropzone = document.getElementById('dropzone');
const input = document.getElementById('csv-input');
const label = document.getElementById('dropzone-label');
const submitBtn = document.getElementById('submit-btn');
const alertBox = document.getElementById('alert-box');
const form = document.getElementById('upload-form');
const nameField = document.getElementById('name-field');
const nameInput = document.getElementById('dataset-name');
let selectedFiles = [];

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

setupDropzone(dropzone, input, (files) => {
    selectedFiles = files;
    label.textContent = files.length === 1
        ? files[0].name
        : `${files.length} archivos seleccionados: ${files.map(f => f.name).join(', ')}`;
    submitBtn.disabled = false;

    const multi = files.length > 1;
    nameField.style.display = multi ? 'none' : '';
    if (multi) nameInput.value = '';
});

form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (selectedFiles.length === 0) return;

    submitBtn.disabled = true;

    const customName = nameInput.value.trim();
    const categoria = document.querySelector('input[name="categoria"]:checked').value;
    const results = [];
    const errors = [];

    for (let i = 0; i < selectedFiles.length; i++) {
        const file = selectedFiles[i];
        submitBtn.textContent = selectedFiles.length > 1
            ? `Subiendo ${i + 1}/${selectedFiles.length}...`
            : 'Subiendo...';

        const formData = new FormData();
        formData.append('csv', file);
        formData.append('categoria', categoria);
        if (customName && selectedFiles.length === 1) formData.append('nombre', customName);

        try {
            const result = await Api.postForm('../api/datasets.php', formData);
            results.push(result);
        } catch (err) {
            errors.push(`${file.name}: ${err.message}`);
        }
    }

    const totalUnmatched = results.reduce((s, r) => s + (r.unmatched_count || 0), 0);
    let msg = `${results.length} dataset(s) cargado(s).`;
    if (totalUnmatched > 0) msg += ` ${totalUnmatched} fila(s) con nombres por resolver más abajo.`;
    if (errors.length > 0) msg += `\n${errors.join('\n')}`;
    showAlert(alertBox, msg, errors.length > 0 && results.length === 0 ? 'error' : 'success');

    submitBtn.disabled = false;
    submitBtn.textContent = 'Subir datos';
    selectedFiles = [];
    input.value = '';
    label.textContent = 'Arrastrá uno o varios CSV acá o hacé click para elegirlos';

    // Refrescamos la lista de datasets y la reconciliación sin recargar toda la página.
    await loadReconciliation();
    setTimeout(() => window.location.reload(), 1200);
});

document.querySelectorAll('.btn-delete').forEach((btn) => {
    btn.addEventListener('click', async () => {
        if (!confirm('¿Eliminar este dataset? Esta acción no se puede deshacer.')) return;
        try {
            await Api.del(`../api/datasets.php?id=${btn.dataset.id}`);
            window.location.reload();
        } catch (err) {
            showAlert(alertBox, err.message, 'error');
        }
    });
});

// ---------- Reconciliación inline (misma API que usaba el paso Validación) ----------

const reconCard = document.getElementById('recon-card');
const reconContainer = document.getElementById('recon-container');
let playersCache = [];

function renderPlayerOptions(selectedId) {
    return playersCache.map(p =>
        `<option value="${p.id}" ${p.id === selectedId ? 'selected' : ''}>${escapeHtml(p.nombre)}</option>`
    ).join('');
}

function renderReconDataset(dataset) {
    if (!dataset.player_column_name) {
        const card = document.createElement('div');
        card.className = 'recon-dataset';
        card.innerHTML = `
            <div class="dataset-name">${escapeHtml(dataset.nombre)}</div>
            <div class="alert alert-error">No pudimos identificar la columna de jugador.</div>
            <div class="field">
                <label>Elegí la columna que identifica al jugador</label>
                <select class="col-select">
                    <option value="">Seleccionar columna...</option>
                    ${dataset.columns.map(c => `<option value="${escapeHtml(c)}">${escapeHtml(c)}</option>`).join('')}
                </select>
            </div>
            <div class="btn-row"><button class="btn confirm-col-btn">Confirmar columna</button></div>
        `;
        card.querySelector('.confirm-col-btn').addEventListener('click', async () => {
            const col = card.querySelector('.col-select').value;
            if (!col) return;
            const fd = new FormData();
            fd.append('action', 'set_player_column');
            fd.append('dataset_id', dataset.dataset_id);
            fd.append('column_name', col);
            await Api.postForm('../api/reconciliation.php', fd);
            loadReconciliation();
        });
        reconContainer.appendChild(card);
        return;
    }

    if (dataset.pending.length === 0) return;

    const card = document.createElement('div');
    card.className = 'recon-dataset';
    card.innerHTML = `
        <div class="recon-head">
            <div class="dataset-name">${escapeHtml(dataset.nombre)} <span class="badge badge-unmatched">${dataset.pending.length}</span></div>
            <button class="btn-secondary btn btn-ai-reconcile" type="button" data-id="${dataset.dataset_id}">Reconocer con IA</button>
        </div>`;
    card.querySelector('.btn-ai-reconcile').addEventListener('click', async (e) => {
        const btn = e.currentTarget;
        btn.disabled = true;
        btn.textContent = 'Analizando...';
        try {
            const result = await Api.postForm('../api/ai_reconcile.php', (() => { const fd = new FormData(); fd.append('dataset_id', dataset.dataset_id); return fd; })());
            showAlert(alertBox, result.message, 'success');
            loadReconciliation();
        } catch (err) {
            showAlert(alertBox, err.message, 'error');
            btn.disabled = false;
            btn.textContent = 'Reconocer con IA';
        }
    });
    const list = document.createElement('div');
    list.className = 'dataset-list';

    dataset.pending.forEach(item => {
        const row = document.createElement('div');
        row.className = 'dataset-row';
        row.innerHTML = `
            <div>
                <div class="dataset-name">${escapeHtml(item.raw_name)}</div>
                <div class="dataset-meta">${item.suggested_nombre ? `Sugerido: ${escapeHtml(item.suggested_nombre)}` : 'Sin sugerencia'}</div>
            </div>
            <div class="dataset-actions" style="align-items:center;">
                ${item.suggested_nombre ? '<button class="btn-icon confirm-btn">Sí, es este</button>' : ''}
                <select class="manual-select" style="padding:6px 8px;border-radius:6px;border:1px solid var(--border);background:var(--surface);">
                    <option value="">Elegir jugador...</option>
                    ${renderPlayerOptions(null)}
                </select>
                <button class="btn-icon manual-btn">Asignar</button>
                <button class="btn-icon discard-btn">Descartar</button>
            </div>
        `;
        row.querySelector('.confirm-btn')?.addEventListener('click', () => resolveName(item.id, 'confirmed', null));
        row.querySelector('.manual-btn').addEventListener('click', () => {
            const val = row.querySelector('.manual-select').value;
            if (!val) return;
            resolveName(item.id, 'manual', val);
        });
        row.querySelector('.discard-btn').addEventListener('click', () => resolveName(item.id, 'discarded', null));
        list.appendChild(row);
    });
    card.appendChild(list);
    reconContainer.appendChild(card);
}

async function resolveName(id, resolution, resolvedPlayerId) {
    const fd = new FormData();
    fd.append('action', 'resolve');
    fd.append('id', id);
    fd.append('resolution', resolution);
    if (resolvedPlayerId) fd.append('resolved_player_id', resolvedPlayerId);
    await Api.postForm('../api/reconciliation.php', fd);
    loadReconciliation();
}

async function loadReconciliation() {
    try {
        const result = await Api.get('../api/reconciliation.php');
        playersCache = result.players;
        const needsAttention = result.datasets.filter(d => !d.player_column_name || d.pending.length > 0);
        reconContainer.innerHTML = '';
        if (needsAttention.length === 0) {
            reconCard.style.display = 'none';
            return;
        }
        reconCard.style.display = '';
        needsAttention.forEach(renderReconDataset);
    } catch (err) {
        reconContainer.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
    }
}

loadReconciliation();
</script>
</body>
</html>
