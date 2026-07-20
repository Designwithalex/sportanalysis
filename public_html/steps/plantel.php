<?php
define('PL_APP', true);
require __DIR__ . '/../app/config.php';
require __DIR__ . '/../app/Database.php';

$pageTitle = 'Plantel — SportAnalysis';
$currentStep = 1;

$players = Database::get()->query('SELECT id, nombre, familia, sub_familia FROM players ORDER BY nombre')->fetchAll();

require __DIR__ . '/../app/views/head.php';
$appbarAction = ['href' => 'analysis.php', 'label' => 'Ir a SportAnalysis', 'icon' => '→', 'primary' => true];
require __DIR__ . '/../app/views/appbar.php';
?>
<div class="page">
    <?php require __DIR__ . '/../app/views/confignav.php'; ?>

    <div class="page-header">
        <h1 class="page-title">Plantel</h1>
        <p class="page-sub">La nómina del equipo es la tabla maestra: todo dato que cargues después se vincula a un jugador por nombre. Podés cargarla por CSV o editarla a mano acá.</p>
    </div>

    <div id="alert-box"></div>

    <div class="card">
        <div class="card-title" id="form-title">Agregar jugador</div>
        <div class="card-sub">Alta y edición individual, sin necesidad de subir un CSV.</div>

        <form id="player-form">
            <input type="hidden" id="pf-id" value="">
            <div class="field-row">
                <div class="field">
                    <label for="pf-nombre">Nombre</label>
                    <input type="text" id="pf-nombre" placeholder="Ej: Alejandro Acosta" required>
                </div>
                <div class="field">
                    <label for="pf-familia">Familia</label>
                    <select id="pf-familia">
                        <option value="back">back</option>
                        <option value="forward">forward</option>
                    </select>
                </div>
                <div class="field">
                    <label for="pf-subfamilia">Sub-familia</label>
                    <input type="text" id="pf-subfamilia" placeholder="Ej: Front Row">
                </div>
            </div>
            <div class="btn-row">
                <button type="button" class="btn-secondary btn" id="pf-cancel" style="display:none;">Cancelar</button>
                <button type="submit" class="btn" id="pf-submit">Agregar jugador</button>
            </div>
        </form>
    </div>

    <div class="card">
        <div class="card-title">Plantel actual</div>
        <div class="card-sub"><span id="player-count"><?= count($players) ?></span> jugadores cargados</div>

        <div class="table-scroll">
            <table class="data-table" id="players-table">
                <thead>
                    <tr>
                        <th>Nombre</th>
                        <th>Familia</th>
                        <th>Sub-familia</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="players-tbody">
                    <?php if (empty($players)): ?>
                        <tr><td colspan="4" class="empty-state">Todavía no cargaste el plantel.</td></tr>
                    <?php else: ?>
                        <?php foreach ($players as $p): ?>
                            <tr data-id="<?= $p['id'] ?>"
                                data-nombre="<?= htmlspecialchars($p['nombre']) ?>"
                                data-familia="<?= htmlspecialchars($p['familia']) ?>"
                                data-subfamilia="<?= htmlspecialchars($p['sub_familia'] ?? '') ?>">
                                <td><?= htmlspecialchars($p['nombre']) ?></td>
                                <td><span class="badge badge-<?= htmlspecialchars($p['familia']) ?>"><?= htmlspecialchars($p['familia']) ?></span></td>
                                <td><?= htmlspecialchars($p['sub_familia'] ?? '') ?></td>
                                <td class="row-actions">
                                    <button class="btn-icon btn-edit-player" type="button">Editar</button>
                                    <button class="btn-icon btn-del-player" type="button">Eliminar</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card">
        <div class="card-title">Cargar plantel por CSV</div>
        <div class="card-sub">Columnas obligatorias: Nombre, Familia (back/forward), Sub-familia. Columnas extra se guardan como metadata. <strong>Subir un CSV reemplaza el plantel actual.</strong></div>

        <form id="upload-form">
            <div class="dropzone" id="dropzone" tabindex="0">
                <input type="file" id="csv-input" name="csv" accept=".csv">
                <div class="dropzone-label" id="dropzone-label">Arrastrá el CSV acá o hacé click para elegirlo</div>
                <div class="dropzone-hint">Solo archivos .csv</div>
            </div>
            <div class="btn-row">
                <button type="submit" class="btn-secondary btn" id="submit-btn" disabled>Reemplazar plantel con CSV</button>
            </div>
        </form>
    </div>

    <div class="btn-row">
        <a class="btn" href="datos.php">Continuar a Datos →</a>
    </div>
</div>

<script src="<?= asset('../js/api.js') ?>"></script>
<script src="<?= asset('../js/wizard.js') ?>"></script>
<script>
const alertBox = document.getElementById('alert-box');

// ---------- Alta / edición individual ----------
const playerForm = document.getElementById('player-form');
const pfId = document.getElementById('pf-id');
const pfNombre = document.getElementById('pf-nombre');
const pfFamilia = document.getElementById('pf-familia');
const pfSub = document.getElementById('pf-subfamilia');
const pfSubmit = document.getElementById('pf-submit');
const pfCancel = document.getElementById('pf-cancel');
const formTitle = document.getElementById('form-title');

function resetPlayerForm() {
    pfId.value = '';
    pfNombre.value = '';
    pfFamilia.value = 'back';
    pfSub.value = '';
    formTitle.textContent = 'Agregar jugador';
    pfSubmit.textContent = 'Agregar jugador';
    pfCancel.style.display = 'none';
}

playerForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    const nombre = pfNombre.value.trim();
    if (!nombre) return;

    const formData = new FormData();
    formData.append('action', 'save');
    if (pfId.value) formData.append('id', pfId.value);
    formData.append('nombre', nombre);
    formData.append('familia', pfFamilia.value);
    formData.append('sub_familia', pfSub.value.trim());

    pfSubmit.disabled = true;
    try {
        await Api.postForm('../api/players.php', formData);
        window.location.reload();
    } catch (err) {
        showAlert(alertBox, err.message, 'error');
        pfSubmit.disabled = false;
    }
});

pfCancel.addEventListener('click', resetPlayerForm);

document.querySelectorAll('.btn-edit-player').forEach((btn) => {
    btn.addEventListener('click', () => {
        const tr = btn.closest('tr');
        pfId.value = tr.dataset.id;
        pfNombre.value = tr.dataset.nombre;
        pfFamilia.value = tr.dataset.familia;
        pfSub.value = tr.dataset.subfamilia;
        formTitle.textContent = `Editando: ${tr.dataset.nombre}`;
        pfSubmit.textContent = 'Guardar cambios';
        pfCancel.style.display = '';
        formTitle.scrollIntoView({ behavior: 'smooth' });
    });
});

document.querySelectorAll('.btn-del-player').forEach((btn) => {
    btn.addEventListener('click', async () => {
        const tr = btn.closest('tr');
        if (!confirm(`¿Eliminar a ${tr.dataset.nombre} del plantel?`)) return;
        try {
            await Api.del(`../api/players.php?id=${tr.dataset.id}`);
            window.location.reload();
        } catch (err) {
            showAlert(alertBox, err.message, 'error');
        }
    });
});

// ---------- Carga por CSV (reemplaza el plantel) ----------
const dropzone = document.getElementById('dropzone');
const input = document.getElementById('csv-input');
const label = document.getElementById('dropzone-label');
const submitBtn = document.getElementById('submit-btn');
const uploadForm = document.getElementById('upload-form');
let selectedFile = null;

setupDropzone(dropzone, input, (files) => {
    selectedFile = files[0];
    label.textContent = selectedFile.name;
    submitBtn.disabled = false;
});

uploadForm.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (!selectedFile) return;
    if (!confirm('Subir un CSV reemplaza TODO el plantel actual. ¿Continuar?')) return;

    submitBtn.disabled = true;
    submitBtn.textContent = 'Subiendo...';
    showAlert(alertBox, null);

    const formData = new FormData();
    formData.append('csv', selectedFile);

    try {
        const result = await Api.postForm('../api/players.php', formData);
        showAlert(alertBox, `Plantel cargado: ${result.count} jugadores.`, 'success');
        setTimeout(() => window.location.reload(), 900);
    } catch (err) {
        showAlert(alertBox, err.message, 'error');
        submitBtn.disabled = false;
        submitBtn.textContent = 'Reemplazar plantel con CSV';
    }
});
</script>
</body>
</html>
