<?php
require __DIR__ . '/../app/bootstrap_page.php';
require_once __DIR__ . '/../app/Categorias.php';
require_once __DIR__ . '/../app/CategoryPermission.php';
requireAuth();

$pageTitle = 'Datos — SportAnalysis';
$currentStep = 2;

$pdo = Database::get();
// El club va en los dos lados del LEFT JOIN: el WHERE acota los datasets listados, y la condición
// del JOIN evita que un dataset_row de otro club infle los conteos de filas/matcheadas si alguna
// vez se cruzaran los ids.
$stmt = $pdo->prepare(
    'SELECT d.id, d.nombre, d.categoria, d.original_filename, d.column_schema, d.player_column_name, d.uploaded_at,
            COUNT(r.id) AS row_count,
            SUM(CASE WHEN r.match_status = "matched" THEN 1 ELSE 0 END) AS matched_count
     FROM datasets d
     LEFT JOIN dataset_rows r ON r.dataset_id = d.id AND r.club_id = d.club_id
     WHERE d.club_id = :club
     GROUP BY d.id
     ORDER BY d.categoria, d.uploaded_at DESC'
);
$stmt->execute(['club' => Auth::clubId()]);
$datasets = $stmt->fetchAll();

// Catálogo completo, del único lugar donde vive. Se muestran TODAS las categorías, también las
// que este usuario no puede escribir: si las de otro rubro desaparecieran, el kinesiólogo no
// entendería por qué "Fuerza" no existe para él y pensaría que la app está rota o incompleta.
$categorias = Categorias::labels();

/**
 * Categorías que esta sesión puede ESCRIBIR. Lista vacía = usuario de solo lectura.
 *
 * Esto no protege nada: api/datasets.php y api/manual_dataset.php validan igual con
 * CategoryPermission::requireCategoria(). Sirve para no ofrecer botones que van a terminar en un
 * 403 después de que el usuario eligió un archivo y esperó la subida.
 *
 * @var array<string,int> $editables Set para chequear con isset().
 */
$editablesLista = CategoryPermission::categoriasEditables();
$editables      = array_flip($editablesLista);
$puedeCargar    = $editablesLista !== [];
// Categoría preseleccionada del uploader: la primera que el usuario PUEDA escribir. Dejar
// 'partidos' fijo haría que el kinesiólogo abra la pantalla con un chip elegido que no puede usar.
$catPorDefecto  = $editablesLista[0] ?? null;
// Las bloqueadas, para el renglón que explica el estado sin depender de un title (que en la
// tablet del borde de la cancha no existe).
$bloqueadas = array_values(array_diff(array_keys($categorias), $editablesLista));

$byCategoria = array_fill_keys(array_keys($categorias), []);
foreach ($datasets as $d) {
    // Una fila con una categoría fuera del catálogo (dato viejo, ENUM tocado a mano) no se pierde
    // en silencio: cae en el bolsón en vez de crear una clave suelta que ningún foreach recorre.
    $key = isset($byCategoria[$d['categoria']]) ? $d['categoria'] : Categorias::DEFAULT;
    $byCategoria[$key][] = $d;
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

    <!-- El CTA de "Generá tus vistas base con IA" vivía acá y se fue: la generación es una acción
         del club, no de Configuración, y solo un admin_club puede correrla. Ahora vive en
         steps/analysis.php (estado vacío + menú ⋯). El parámetro analysis.php?base_views=1 sigue
         abriendo ese modal directo, por si quedó algún link viejo dando vueltas. -->

    <?php if (!$puedeCargar): ?>
        <?php /* Sin ninguna categoría habilitada no se dibuja el uploader. Un formulario completo
                 que responde 403 recién después de elegir el archivo y esperar la subida no es
                 "seguro por el servidor": es una pantalla que le miente al usuario. Se le dice qué
                 puede hacer (todo lo de leer) y a quién pedirle lo que le falta. */ ?>
        <div class="card">
            <div class="card-title">Podés ver los datos, todavía no cargarlos</div>
            <div class="card-sub">
                Ves todos los datasets de tu club y todos los análisis, pero no tenés ninguna categoría
                habilitada para subir o editar datos. Las habilitaciones las da un administrador de tu
                club desde su panel de solicitudes: pedile la de tu especialidad (por ejemplo
                <?= htmlspecialchars(Categorias::label('kinesiologia')) ?>) y este formulario aparece solo.
            </div>
        </div>
        <div id="alert-box"></div>
    <?php else: ?>
    <div class="card">
        <div class="card-title">Subir datos</div>
        <div class="card-sub">Elegí la categoría y subí uno o varios CSV. Detectamos el tipo de cada columna y cuál identifica al jugador.</div>

        <div id="alert-box"></div>

        <form id="upload-form">
            <div class="field">
                <label id="categoria-label">Categoría</label>
                <?php /* Las categorías sin habilitación se muestran DESHABILITADAS, no escondidas:
                         una lista que cambia de tamaño según quién mire hace pensar que faltan
                         datos. El motivo va en tres capas porque ninguna alcanza sola — candado
                         visible (se ve de un vistazo), texto para lector de pantalla (el title no
                         se anuncia sobre un input deshabilitado) y el renglón de abajo (el title
                         no existe en touch, que es la mitad del uso real de esta pantalla). */ ?>
                <div class="category-picker" id="category-picker" role="group" aria-labelledby="categoria-label">
                    <?php foreach ($categorias as $key => $label): ?>
                        <?php $habilitada = isset($editables[$key]); ?>
                        <?php /* El <span> se emite en UNA línea: `.category-chip span` es inline-block
                                 y los saltos de línea del template se convertirían en espacios
                                 visibles antes y después de la etiqueta, descentrándola. */ ?>
                        <label class="category-chip<?= $habilitada ? '' : ' is-locked' ?>"<?= $habilitada ? '' : ' title="Necesitás la habilitación de ' . htmlspecialchars($label, ENT_QUOTES) . '"' ?>>
                            <input type="radio" name="categoria" value="<?= htmlspecialchars($key, ENT_QUOTES) ?>"<?= $key === $catPorDefecto ? ' checked' : '' ?><?= $habilitada ? '' : ' disabled' ?>>
                            <span><?= htmlspecialchars($label) ?><?php if (!$habilitada): ?><span class="chip-lock" aria-hidden="true">&#128274;</span><span class="sr-only"> — no habilitada: necesitás la habilitación de <?= htmlspecialchars($label) ?></span><?php endif; ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <?php if ($bloqueadas): ?>
                    <?php /* Se enumera lo que SÍ, no lo que no: con una sola habilitación —el caso
                             del kinesiólogo, que es para quien se hizo esto— la lista negativa son
                             cinco nombres y la positiva uno. Cuáles están bloqueadas ya lo dicen
                             los candados de arriba; lo que este renglón agrega es a quién pedirle
                             lo que falta, que es la única acción posible desde acá. */ ?>
                    <p class="field-hint">
                        Podés cargar en:
                        <?= htmlspecialchars(implode(', ', array_map(static fn (string $c): string => Categorias::label($c), $editablesLista))) ?>.
                        Las demás necesitan que un administrador de tu club te las habilite.
                    </p>
                <?php endif; ?>
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
    <?php endif; ?>

    <!-- Reconciliación inline: aparece cuando hay nombres sin resolver en algún dataset -->
    <div class="card" id="recon-card" style="display:none;">
        <div class="card-title">Nombres por resolver</div>
        <div class="card-sub">Estos nombres no matchearon con el plantel. Resolvelos para que sus datos entren en los análisis.</div>
        <div id="recon-container"></div>
    </div>

    <div class="card">
        <div class="card-title">Datasets por categoría</div>
        <div class="card-sub"><span id="dataset-count"><?= count($datasets) ?></span> datasets en total.
            <?= $puedeCargar
                ? 'En las categorías que tenés habilitadas podés subir un CSV (arriba) o cargar los datos a mano sobre el plantel.'
                : 'Los ves todos; para cargar hace falta tener habilitada la categoría.' ?></div>

        <?php foreach ($categorias as $key => $label): ?>
            <?php $habilitada = isset($editables[$key]); ?>
            <div class="dataset-group">
                <div class="dataset-group-head">
                    <div class="dataset-group-title"><?= htmlspecialchars($label) ?> <span class="dataset-group-count"><?= count($byCategoria[$key]) ?></span></div>
                    <?php if ($habilitada): ?>
                        <a class="btn-secondary btn btn-sm" href="carga_manual.php?categoria=<?= htmlspecialchars($key, ENT_QUOTES) ?>">+ Cargar a mano</a>
                    <?php elseif ($puedeCargar): ?>
                        <?php /* El grupo se lista igual —los datos se leen— pero sin la acción que
                                 va a rebotar. El motivo va como texto y no como botón gris: un
                                 botón deshabilitado invita a volver a intentar.
                                 Solo cuando el usuario tiene ALGUNA habilitación: si no tiene
                                 ninguna, la card de arriba ya lo dijo una vez y repetirlo en las
                                 seis categorías es ruido que no agrega ni un dato. */ ?>
                        <span class="dataset-empty-note">Sin habilitación</span>
                    <?php endif; ?>
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
                                <?php if ($habilitada): ?>
                                    <?php /* Borrar un dataset es una escritura sobre esa categoría
                                             (api/datasets.php la valida con requireCategoria). Para
                                             quien no la tiene, el botón sería un 403 con confirm()
                                             de por medio. */ ?>
                                    <div class="dataset-actions">
                                        <button class="btn-icon btn-delete" data-id="<?= $d['id'] ?>">Eliminar</button>
                                    </div>
                                <?php endif; ?>
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

// Un usuario sin ninguna categoría habilitada no recibe el formulario de subida, así que acá no
// hay nada que cablear. Sin este corte, el primer getElementById nulo tira el script entero y se
// lleva puesta también la reconciliación de nombres de más abajo — que sí tiene que funcionar.
if (form) {

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

} // fin del bloque del uploader

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
