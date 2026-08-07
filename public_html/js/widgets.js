let currentWidgets = [];
let customMetricsCache = [];
const chartInstances = {};

// Columnas sintéticas que el renderer inyecta en cada fila (espejo de WidgetSchema::SYNTHETIC_COLUMNS).
// __dataset es el eje "por partido" cuando el widget abarca varios datasets.
const SYNTHETIC_COLUMNS = {
    '__dataset': 'categorica',
    '__familia': 'categorica',
    '__sub_familia': 'categorica',
    '__player_nombre': 'texto',
    // Fecha real de la sesión (no la de carga). Único eje temporal confiable del sistema.
    '__fecha': 'fecha',
    // Parte de la sesión ('all', 'game', '1st.half', 'Activacion'...). Los tramos del GPS están
    // anidados: sin filtrar por esto, los totales se cuentan varias veces.
    '__split': 'categorica',
    // Puesto concreto del jugador. Más fino que __sub_familia (que es la línea).
    '__puesto': 'categorica',
};

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function getDataset(id) {
    return DATASETS.find(d => String(d.id) === String(id));
}

/** Normaliza el target de datasets de un config (dataset_ids nuevo o dataset_id viejo). */
function configDatasetIds(config) {
    if (Array.isArray(config?.dataset_ids) && config.dataset_ids.length) return config.dataset_ids.map(Number);
    if (config?.dataset_id != null) return [Number(config.dataset_id)];
    return [];
}

/** Schema efectivo (intersección de columnas comunes + sintéticas) para un conjunto de datasets. */
function effectiveSchema(datasetIds) {
    const schemas = datasetIds.map(id => getDataset(id)?.column_schema).filter(Boolean);
    if (!schemas.length) return { ...SYNTHETIC_COLUMNS };
    let common = { ...schemas[0] };
    schemas.slice(1).forEach(schema => {
        Object.keys(common).forEach(col => {
            if (!(col in schema)) delete common[col];
            else if (schema[col] !== common[col]) common[col] = 'texto';
        });
    });
    return { ...SYNTHETIC_COLUMNS, ...common };
}

/** Pseudo-dataset para alimentar los SECTION_BUILDERS del editor manual con varios datasets. */
function virtualDataset(datasetIds) {
    return { column_schema: effectiveSchema(datasetIds) };
}

function columnsOfType(dataset, types) {
    if (!dataset) return [];
    return Object.entries(dataset.column_schema)
        .filter(([, t]) => types === 'all' || types.includes(t))
        .map(([name]) => name);
}

// ---------- Filtro rápido ----------
//
// Recorta TODOS los widgets de la vista a la vez: elegir "Forwards" deja el tablero entero en
// forwards, y los totales se recalculan porque los recalcula el servidor sobre las filas que
// quedan. No confundir con los "Filtros" del modal, que son configuración guardada de la vista y
// la ve todo el club: esto es una lente del momento, vive en memoria y se va al recargar.
//
// Espejo de FILTROS_RAPIDOS en api/widgets.php. Una clave de más acá no rompe nada —el servidor la
// descarta— pero el usuario vería un control que no hace nada.
const quickFilters = {};

function quickFilterQuery() {
    return Object.entries(quickFilters)
        .filter(([, v]) => v)
        .map(([k, v]) => `&q[${encodeURIComponent(k)}]=${encodeURIComponent(v)}`)
        .join('');
}

/** Cuántas dimensiones están recortando ahora mismo. Alimenta el contador del botón "Limpiar". */
function quickFilterCount() {
    return Object.values(quickFilters).filter(Boolean).length;
}

function wireQuickFilters() {
    const bar = document.getElementById('quick-filters');
    if (!bar) return;

    const limpiar = document.getElementById('quick-filters-clear');

    const refrescar = () => {
        const n = quickFilterCount();
        limpiar.hidden = n === 0;
        limpiar.textContent = n === 1 ? 'Limpiar filtro' : `Limpiar ${n} filtros`;
        bar.classList.toggle('is-active', n > 0);
    };

    bar.querySelectorAll('select[data-dim]').forEach((sel) => {
        sel.addEventListener('change', () => {
            quickFilters[sel.dataset.dim] = sel.value;
            refrescar();
            loadWidgets();
        });
    });

    limpiar.addEventListener('click', () => {
        bar.querySelectorAll('select[data-dim]').forEach((sel) => {
            sel.value = '';
            quickFilters[sel.dataset.dim] = '';
        });
        refrescar();
        loadWidgets();
    });

    refrescar();
}

// ---------- Exportar PDF ----------
//
// "Exportar" es imprimir a PDF con el diálogo del navegador. No hay librería de PDF: el hosting es
// PHP compartido sin Composer y la única dependencia de frontend permitida es Chart.js. A cambio
// sirve para CUALQUIER vista con un solo mecanismo —el tablero de partidos, el de fuerza por
// familia, el de kinesiología— en vez de un exportador por tablero.

/** Texto de qué recorte está aplicado, para que el PDF no mienta sobre a qué se refieren los números. */
function printFilterSummary() {
    const partes = [];
    document.querySelectorAll('#quick-filters select[data-dim]').forEach((sel) => {
        if (!sel.value) return;
        const etiqueta = sel.closest('.qf-item')?.querySelector('.qf-label')?.textContent ?? sel.dataset.dim;
        const valor = sel.options[sel.selectedIndex]?.textContent ?? sel.value;
        partes.push(`${etiqueta}: ${valor}`);
    });
    return partes.length ? partes.join('  ·  ') : 'Sin filtros — plantel completo';
}

/**
 * Los gráficos se imprimen tal cual: Chrome renderiza el <canvas> de Chart.js en el PDF y, como
 * Chart.js es responsive, hasta se redibuja al ancho de la hoja.
 *
 * La receta habitual para esto es cambiar cada canvas por un <img> sacado con toDataURL(). Acá no
 * se hace, y conviene dejarlo escrito para no "arreglarlo" de nuevo: probado contra este mismo
 * tablero, la lectura del canvas devolvió un bitmap vacío (cero píxeles opacos) aunque el gráfico
 * se viera perfecto en pantalla, así que el PDF salía con todos los recuadros en blanco. El canvas
 * directo funciona y además es una pieza móvil menos.
 */
function setupPrint() {
    const btn = document.getElementById('export-pdf-btn');
    if (!btn) return;

    // Sobre beforeprint y no adentro del click: así el PDF también sale bien si el usuario imprime
    // con Ctrl+P en vez de usar el botón.
    window.addEventListener('beforeprint', () => {
        const head = document.getElementById('print-header');
        if (!head) return;
        head.querySelector('.print-meta').textContent =
            `${PRINT_CLUB}  ·  ${printFilterSummary()}  ·  emitido ${new Date().toLocaleDateString('es-AR')}`;
    });

    btn.addEventListener('click', () => window.print());
}

// ---------- Widget grid ----------

async function loadWidgets() {
    const grid = document.getElementById('widget-grid');
    if (!grid) return;

    grid.innerHTML = '<div class="empty-state">Cargando...</div>';

    try {
        const result = await Api.get(`../api/widgets.php?view_id=${ACTIVE_VIEW_ID}${quickFilterQuery()}`);
        currentWidgets = result.widgets;
        grid.innerHTML = '';
        // El rótulo del botón de generación depende de cuántos widgets hay: recién acá se sabe.
        refreshGenerateButton();

        if (currentWidgets.length === 0) {
            renderAiHero(grid);
            return;
        }

        currentWidgets.forEach(renderWidgetCard);
        wireScaleSelectors(grid);
        wireTableSearch(grid);
    } catch (err) {
        grid.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
        // Sin saber cuántos widgets hay no se puede ofrecer "Regenerar": el confirm mentiría
        // sobre cuántos va a reemplazar. El botón queda escondido hasta que la grilla cargue.
    }
}

// Ejemplos de prompts que venden la promesa "describí y la IA lo arma" y arrancan el compositor.
const AI_HERO_EXAMPLES = [
    { label: 'Carga semanal por jugador', prompt: 'Metros totales por jugador en la última semana' },
    { label: 'Ranking de sprint', prompt: 'Ranking de jugadores por metros de sprint, de mayor a menor' },
    { label: 'Backs vs forwards', prompt: 'Comparar la distancia promedio por partido entre backs y forwards' },
    { label: 'Evolución de la carga', prompt: 'Evolución de la distancia total del plantel por sesión' },
];

function renderAiHero(grid) {
    const hero = document.createElement('div');
    hero.className = 'ai-hero';
    hero.innerHTML = `
        <div class="ai-hero-mark"><span aria-hidden="true">✦</span></div>
        <h2 class="ai-hero-title">Preguntale a tus datos</h2>
        <p class="ai-hero-sub">Describí el análisis que querés en lenguaje natural y la IA arma el widget desde tu propio plantel — cruzando los datasets que haga falta.</p>
        <button class="ai-hero-box" type="button" id="ai-hero-open">
            <span class="ai-hero-box-spark" aria-hidden="true">✦</span>
            <span>Ej: promedio de metros por partido, backs vs forwards…</span>
            <span class="ai-hero-box-cta" aria-hidden="true">Describir</span>
        </button>
        <div class="ai-hero-examples">
            <div class="ai-hero-eyebrow">Probá con</div>
            ${AI_HERO_EXAMPLES.map((ex, i) => `
                <button class="ai-chip" type="button" data-i="${i}">
                    <span class="ai-chip-bolt" aria-hidden="true">⚡</span> ${escapeHtml(ex.label)}
                </button>`).join('')}
        </div>`;
    grid.appendChild(hero);

    document.getElementById('ai-hero-open').addEventListener('click', () => openPromptModal());
    hero.querySelectorAll('.ai-chip').forEach((chip) => {
        chip.addEventListener('click', () => openPromptModal(AI_HERO_EXAMPLES[Number(chip.dataset.i)].prompt));
    });
}

const SPAN_BY_TYPE = { kpi_card: '3', table: '12', line_chart: '6', bar_chart: '6', stacked_bar: '6' };

function renderWidgetCard(widget) {
    const grid = document.getElementById('widget-grid');
    const card = document.createElement('div');
    card.className = 'widget-card';
    card.dataset.span = SPAN_BY_TYPE[widget.type] || '6';
    card.dataset.widgetId = widget.id;
    card.classList.toggle('is-chart', !!widget.chart_type);

    const warning = widget.excluded_count > 0
        ? `<div class="widget-warning"><span aria-hidden="true">⚠</span> ${widget.excluded_count} fila(s) excluida(s) por datos sin asignar</div>`
        : '';

    const canUndo = widget.version_count > 1;
    const menuId = `wmenu-${widget.id}`;

    card.innerHTML = `
        <div class="widget-head">
            <div>
                <div class="widget-title">${escapeHtml(widget.title)}</div>
                ${warning}
            </div>
            <div class="widget-controls">
                <button class="widget-action drag-handle" type="button" aria-label="Arrastrar para reordenar" title="Arrastrar para reordenar">⠿</button>
                <button class="widget-action edit-btn" type="button">Editar</button>
                <button class="widget-action widget-action-more more-btn" type="button"
                        aria-haspopup="menu" aria-expanded="false" aria-controls="${menuId}"
                        aria-label="Más acciones" title="Más acciones">⋯</button>
                <div class="widget-menu" id="${menuId}" role="menu" hidden>
                    <button class="widget-menu-item ai-btn" type="button" role="menuitem"><span class="wm-icon" aria-hidden="true">✦</span> Modificar con IA</button>
                    <button class="widget-menu-item dup-btn" type="button" role="menuitem"><span class="wm-icon" aria-hidden="true">⧉</span> Duplicar</button>
                    <button class="widget-menu-item undo-btn" type="button" role="menuitem" ${canUndo ? '' : 'disabled'}><span class="wm-icon" aria-hidden="true">↺</span> Deshacer</button>
                    <div class="widget-menu-sep" role="separator"></div>
                    <button class="widget-menu-item destructive del-btn" type="button" role="menuitem"><span class="wm-icon" aria-hidden="true">🗑</span> Eliminar widget</button>
                </div>
            </div>
        </div>
        <div class="widget-body"></div>
    `;

    const body = card.querySelector('.widget-body');
    if (widget.chart_type) {
        const canvas = document.createElement('canvas');
        body.appendChild(canvas);
        if (chartInstances[widget.id]) chartInstances[widget.id].destroy();
        chartInstances[widget.id] = initChart(canvas, widget.chart_type, widget.chart_data);
    } else {
        body.innerHTML = widget.html;
    }

    // Menú de overflow: abrir/cerrar, cerrar al elegir, al clickear afuera o con Escape.
    const moreBtn = card.querySelector('.more-btn');
    const menu = card.querySelector('.widget-menu');
    const closeMenu = () => {
        if (menu.hidden) return;
        menu.hidden = true;
        moreBtn.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', onOutside, true);
        document.removeEventListener('keydown', onEsc, true);
    };
    const onOutside = (e) => { if (!card.querySelector('.widget-controls').contains(e.target)) closeMenu(); };
    const onEsc = (e) => { if (e.key === 'Escape') { closeMenu(); moreBtn.focus(); } };
    moreBtn.addEventListener('click', (e) => {
        e.stopPropagation();
        if (menu.hidden) {
            closeOtherWidgetMenus(menu);
            menu.hidden = false;
            moreBtn.setAttribute('aria-expanded', 'true');
            menu.querySelector('.widget-menu-item:not(:disabled)')?.focus();
            document.addEventListener('click', onOutside, true);
            document.addEventListener('keydown', onEsc, true);
        } else {
            closeMenu();
        }
    });

    card.querySelector('.edit-btn').addEventListener('click', () => openWidgetModal(widget));
    card.querySelector('.ai-btn').addEventListener('click', () => { closeMenu(); openAiModal(widget); });
    card.querySelector('.dup-btn').addEventListener('click', () => { closeMenu(); runAction('duplicate', { id: widget.id }); });
    const undoBtn = card.querySelector('.undo-btn');
    if (canUndo) undoBtn.addEventListener('click', () => { closeMenu(); runAction('undo', { id: widget.id }); });
    card.querySelector('.del-btn').addEventListener('click', async () => {
        closeMenu();
        if (!confirm('¿Eliminar este widget? No se puede deshacer.')) return;
        await Api.del(`../api/widgets.php?id=${widget.id}`);
        loadWidgets();
    });

    // Vista del club + usuario sin permiso de admin: TODO lo que vive en .widget-controls
    // (arrastrar, Editar, Modificar con IA, Duplicar, Deshacer, Eliminar) termina en 403 del lado
    // del servidor — api/widgets.php, api/build_widget.php y api/ai_patch_widget.php validan por
    // vista, no solo por widget. Se saca el bloque entero acá, después de armar la card y antes de
    // setupCardDrag(), que ya sabe salir solo si no encuentra el handle. El resto del render
    // (gráfico, tabla, selector de escala %) no cambia: la vista se lee igual, solo no se edita.
    if (!canEditActiveView()) card.querySelector('.widget-controls')?.remove();

    setupCardDrag(card);

    // Entrada escalonada (el CSS define el keyframe; acá sólo el retardo por índice, con tope).
    const idx = grid.querySelectorAll('.widget-card').length;
    card.style.animationDelay = `${Math.min(idx * 45, 315)}ms`;

    grid.appendChild(card);
}

// Reordenar widgets por arrastre (desde el handle ⠿). Mueve la card en el DOM y persiste el nuevo
// orden. Mover el nodo no destruye el Chart.js del canvas, así que no hace falta recargar.
function setupCardDrag(card) {
    const grid = document.getElementById('widget-grid');
    const handle = card.querySelector('.drag-handle');
    if (!handle || !grid) return;

    // El HTML5 drag arranca sólo si la card es draggable; lo activamos únicamente desde el handle
    // para no romper la selección de texto ni el scroll del resto de la card.
    handle.addEventListener('mousedown', () => { card.draggable = true; });
    handle.addEventListener('mouseup', () => { card.draggable = false; });

    card.addEventListener('dragstart', (e) => {
        card.classList.add('dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', card.dataset.widgetId); } catch (_) {}
    });
    card.addEventListener('dragend', () => {
        card.classList.remove('dragging');
        card.draggable = false;
        persistWidgetOrder();
    });
    card.addEventListener('dragover', (e) => {
        e.preventDefault();
        const dragging = grid.querySelector('.widget-card.dragging');
        if (!dragging || dragging === card) return;
        const rect = card.getBoundingClientRect();
        // Arriba de la card → antes; abajo → después; misma franja vertical → según mitad horizontal.
        const before = e.clientY < rect.top ? true
            : e.clientY > rect.bottom ? false
            : e.clientX < rect.left + rect.width / 2;
        grid.insertBefore(dragging, before ? card : card.nextSibling);
    });
}

async function persistWidgetOrder() {
    const grid = document.getElementById('widget-grid');
    const ids = [...grid.querySelectorAll('.widget-card')].map((c) => c.dataset.widgetId);
    const fd = new FormData();
    fd.append('action', 'reorder');
    ids.forEach((id) => fd.append('ids[]', id));
    try {
        await Api.postForm('../api/widgets.php', fd);
    } catch (err) {
        showAlert(document.getElementById('alert-box'), 'No se pudo guardar el nuevo orden: ' + err.message, 'error');
    }
}

// Sólo un menú abierto a la vez (widgets + toolbar comparten la clase .widget-menu).
function closeOtherWidgetMenus(except) {
    document.querySelectorAll('.widget-menu:not([hidden])').forEach((m) => {
        if (m === except) return;
        m.hidden = true;
        const trigger = m.parentElement.querySelector('.more-btn, .tb-more-btn');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    });
}

// Menú de overflow genérico (usado por la toolbar de la vista).
function setupOverflowMenu(triggerId, menuId) {
    const trigger = document.getElementById(triggerId);
    const menu = document.getElementById(menuId);
    if (!trigger || !menu) return;
    const wrap = trigger.parentElement;
    const close = () => {
        if (menu.hidden) return;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', onOutside, true);
        document.removeEventListener('keydown', onEsc, true);
    };
    const onOutside = (e) => { if (!wrap.contains(e.target)) close(); };
    const onEsc = (e) => { if (e.key === 'Escape') { close(); trigger.focus(); } };
    trigger.addEventListener('click', (e) => {
        e.stopPropagation();
        if (menu.hidden) {
            closeOtherWidgetMenus(menu);
            menu.hidden = false;
            trigger.setAttribute('aria-expanded', 'true');
            menu.querySelector('.widget-menu-item:not(:disabled)')?.focus();
            document.addEventListener('click', onOutside, true);
            document.addEventListener('keydown', onEsc, true);
        } else {
            close();
        }
    });
    // Al elegir un ítem, cerrar el menú (el handler de la acción se engancha aparte por id).
    menu.querySelectorAll('.widget-menu-item').forEach((it) => it.addEventListener('click', close));
}

async function runAction(action, params) {
    const formData = new FormData();
    formData.append('action', action);
    Object.entries(params).forEach(([k, v]) => formData.append(k, v));
    try {
        await Api.postForm('../api/widgets.php', formData);
        loadWidgets();
    } catch (err) {
        showAlert(document.getElementById('alert-box'), err.message, 'error');
    }
}

// ---------- Manual editor modal ----------

function metricOptionsHtml(dataset, customMetrics, selectedValue) {
    const numericCols = columnsOfType(dataset, ['numerica']);
    let html = '<optgroup label="Columnas">';
    numericCols.forEach(c => {
        const val = `col::${c}`;
        html += `<option value="${escapeHtml(val)}" ${val === selectedValue ? 'selected' : ''}>${escapeHtml(c)}</option>`;
    });
    html += '</optgroup>';
    if (customMetrics.length > 0) {
        html += '<optgroup label="Métricas configurables">';
        customMetrics.forEach(m => {
            const val = `metric::${m.id}`;
            html += `<option value="${escapeHtml(val)}" ${val === selectedValue ? 'selected' : ''}>${escapeHtml(m.nombre)}</option>`;
        });
        html += '</optgroup>';
    }
    return html;
}

function parseMetricValue(value) {
    const [kind, rest] = value.split('::');
    return kind === 'metric' ? { source: 'custom_metric', metric_id: parseInt(rest, 10) } : { source: 'column', column: rest };
}

function metricRefToValue(ref) {
    if (!ref) return '';
    return ref.source === 'custom_metric' ? `metric::${ref.metric_id}` : `col::${ref.column}`;
}

async function loadAllMetrics() {
    if (!customMetricsCache.length) {
        const result = await Api.get(`../api/metrics.php?view_id=${ACTIVE_VIEW_ID}`);
        customMetricsCache = result.metrics;
    }
    return customMetricsCache;
}

async function getMetricsForDataset(datasetId) {
    const all = await loadAllMetrics();
    return all.filter(m => String(m.dataset_id) === String(datasetId));
}

async function getMetricsForDatasets(datasetIds) {
    const all = await loadAllMetrics();
    const set = new Set(datasetIds.map(String));
    return all.filter(m => set.has(String(m.dataset_id)));
}

let editingWidget = null;

function checkedDatasetIds() {
    return Array.from(document.querySelectorAll('#wf-datasets input[type="checkbox"]:checked')).map(cb => Number(cb.value));
}

function renderDatasetCheckboxes(selectedIds) {
    const selected = new Set((selectedIds || []).map(String));
    // Etiquetas del servidor (Categorias::labels()), no una copia local en el JS.
    const catLabels = (typeof CATEGORIA_LABELS !== 'undefined' && CATEGORIA_LABELS)
        ? CATEGORIA_LABELS
        : {};

    const byCat = {};
    DATASETS.forEach(d => { (byCat[d.categoria] = byCat[d.categoria] || []).push(d); });

    // Se recorre la UNIÓN de las etiquetas conocidas y las categorías que traen los datos, no
    // solo las etiquetas. Antes esto iteraba una lista hardcodeada, y un dataset de una categoría
    // que no estuviera en ella NO SE VEÍA en el editor: no es que saliera sin nombre, es que
    // desaparecía. Un dato que existe siempre tiene que poder elegirse, aunque no sepamos cómo
    // llamar a su grupo — de ahí el fallback a la clave cruda.
    const orden = Object.keys(catLabels);
    Object.keys(byCat).forEach(cat => { if (!orden.includes(cat)) orden.push(cat); });

    let html = '';
    orden.forEach(cat => {
        if (!byCat[cat]) return;
        html += `<div class="ds-group-label">${escapeHtml(catLabels[cat] || cat)}</div>`;
        byCat[cat].forEach(d => {
            const checked = selected.has(String(d.id)) ? 'checked' : '';
            html += `<label class="checkbox-item"><input type="checkbox" value="${d.id}" ${checked}> ${escapeHtml(d.nombre)}</label>`;
        });
    });
    document.getElementById('wf-datasets').innerHTML = html;
    document.querySelectorAll('#wf-datasets input[type="checkbox"]').forEach(cb => {
        cb.addEventListener('change', buildTypeSection);
    });
}

async function openWidgetModal(widget) {
    editingWidget = widget || null;
    document.getElementById('widget-modal-title').textContent = widget ? 'Editar widget' : 'Nuevo widget';
    document.getElementById('wf-id').value = widget ? widget.id : '';
    document.getElementById('wf-type').value = widget ? widget.type : 'kpi_card';
    document.getElementById('wf-type').disabled = false; // se puede cambiar el tipo también al editar
    document.getElementById('wf-title').value = widget ? widget.title : '';
    showAlert(document.getElementById('widget-modal-alert'), null);

    const ids = widget ? configDatasetIds(widget.config) : (DATASETS[0] ? [Number(DATASETS[0].id)] : []);
    renderDatasetCheckboxes(ids);

    await buildTypeSection();
    document.getElementById('widget-modal').classList.remove('hidden');
    focusModal('widget-modal');
}

function closeWidgetModal() {
    document.getElementById('widget-modal').classList.add('hidden');
}

async function buildTypeSection() {
    const type = document.getElementById('wf-type').value;
    const ids = checkedDatasetIds();
    const dataset = virtualDataset(ids);
    const customMetrics = await getMetricsForDatasets(ids);
    const config = editingWidget && editingWidget.type === type ? editingWidget.config : {};

    const container = document.getElementById('wf-sections');
    container.innerHTML = SECTION_BUILDERS[type](dataset, customMetrics, config);

    wireSectionBehavior(type, dataset, customMetrics, config);
    buildWidgetFilter(ids);
}

// Etiquetas legibles para las columnas sintéticas (comunes a todos los datasets).
const SYNTH_LABELS = {
    __familia: 'Familia (back/forward)',
    __sub_familia: 'Sub-familia',
    __player_nombre: 'Jugador',
    __dataset: 'Partido (dataset)',
    __fecha: 'Fecha de la sesión',
    __split: 'Parte de la sesión',
    __puesto: 'Puesto',
};

/** Filtro propio del widget: dropdown con las columnas del schema efectivo del widget. */
function buildWidgetFilter(datasetIds) {
    const sel = document.getElementById('wf-filter-column');
    if (!sel) return;
    const cols = Object.keys(effectiveSchema(datasetIds));
    const current = (editingWidget && editingWidget.config && editingWidget.config.filter) || {};
    sel.innerHTML = '<option value="">Sin filtro</option>' + cols.map(c =>
        `<option value="${escapeHtml(c)}" ${c === current.column ? 'selected' : ''}>${escapeHtml(SYNTH_LABELS[c] || c)}</option>`
    ).join('');
    document.getElementById('wf-filter-operator').value = current.operator || 'eq';
    document.getElementById('wf-filter-value').value = current.value != null ? current.value : '';
}

const SECTION_BUILDERS = {
    kpi_card: (dataset, metrics, cfg) => `
        <div class="field-row">
            <div class="field">
                <label>Métrica</label>
                <select id="s-metric">${metricOptionsHtml(dataset, metrics, metricRefToValue(cfg.metric))}</select>
            </div>
            <div class="field">
                <label>Agregación</label>
                <select id="s-aggregation">${aggOptions(cfg.aggregation)}</select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Decimales</label>
                <input type="number" id="s-decimals" value="${cfg.number_format?.decimals ?? 1}" min="0" max="4">
            </div>
            <div class="field">
                <label>Unidad</label>
                <input type="text" id="s-unit" value="${escapeHtml(cfg.number_format?.unit ?? '')}" placeholder="m, km/h, %">
            </div>
        </div>
        <label class="checkbox-inline"><input type="checkbox" id="s-scale" ${cfg.scale_selector ? 'checked' : ''}> Selector de escala %</label>
        <label class="checkbox-inline"><input type="checkbox" id="s-comparison" ${cfg.comparison?.enabled ? 'checked' : ''}> Comparación contra referencia</label>
        <div class="field-row">
            <div class="field"><label>Valor de referencia</label><input type="number" step="any" id="s-comparison-ref" value="${cfg.comparison?.reference_value ?? ''}"></div>
            <div class="field"><label>Etiqueta</label><input type="text" id="s-comparison-label" value="${escapeHtml(cfg.comparison?.label ?? 'vs meta')}"></div>
        </div>
    `,
    table: (dataset, metrics, cfg) => `
        <div class="field">
            <label>Fila (cómo se agrupan)</label>
            <select id="s-row-grain">${rowGrainOptions(dataset, cfg.row_grain)}</select>
        </div>
        <div class="field">
            <label>Columnas</label>
            <div id="s-columns-container"></div>
            <button type="button" class="add-row-btn" id="s-add-column">+ Agregar columna</button>
        </div>
        <div class="field">
            <label>Reglas de formato condicional (máx. 3)</label>
            <div id="s-rules-container"></div>
            <button type="button" class="add-row-btn" id="s-add-rule">+ Agregar regla</button>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Orden por defecto (label de columna)</label>
                <input type="text" id="s-sort-column" value="${escapeHtml(cfg.default_sort?.column ?? '')}">
            </div>
            <div class="field">
                <label>Dirección</label>
                <select id="s-sort-direction">
                    <option value="desc" ${cfg.default_sort?.direction !== 'asc' ? 'selected' : ''}>Descendente</option>
                    <option value="asc" ${cfg.default_sort?.direction === 'asc' ? 'selected' : ''}>Ascendente</option>
                </select>
            </div>
        </div>
        <label class="checkbox-inline"><input type="checkbox" id="s-search" ${cfg.search_enabled ? 'checked' : ''}> Búsqueda de texto libre</label>
        <label class="checkbox-inline"><input type="checkbox" id="s-scale" ${cfg.scale_selector ? 'checked' : ''}> Selector de escala %</label>
    `,
    line_chart: (dataset, metrics, cfg) => `
        <div class="field">
            <label>Métricas (eje Y)</label>
            <div id="s-ymetrics-container"></div>
            <button type="button" class="add-row-btn" id="s-add-ymetric">+ Agregar métrica</button>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Eje X (fecha/sesión)</label>
                <select id="s-xcolumn">${columnOptions(columnsOfType(dataset, ['fecha', 'categorica']), cfg.x_column)}</select>
            </div>
            <div class="field">
                <label>Agrupar por (opcional, máx. 6 líneas)</label>
                <select id="s-groupby">
                    <option value="">Ninguno</option>
                    ${columnOptions(columnsOfType(dataset, ['categorica']), cfg.group_by)}
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Agregación por punto</label>
                <select id="s-aggregation">${aggOptions(cfg.aggregation)}</select>
            </div>
            <div class="field">
                <label>Estilo</label>
                <select id="s-style">
                    <option value="line" ${cfg.style !== 'line_markers' ? 'selected' : ''}>Línea continua</option>
                    <option value="line_markers" ${cfg.style === 'line_markers' ? 'selected' : ''}>Con marcadores</option>
                </select>
            </div>
        </div>
    `,
    bar_chart: (dataset, metrics, cfg) => `
        <div class="field-row">
            <div class="field">
                <label>Métrica</label>
                <select id="s-metric">${metricOptionsHtml(dataset, metrics, metricRefToValue(cfg.metric))}</select>
            </div>
            <div class="field">
                <label>Eje de categorías</label>
                <select id="s-category">${columnOptions(columnsOfType(dataset, ['categorica', 'texto']), cfg.category_column)}</select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Agregación</label>
                <select id="s-aggregation">${aggOptions(cfg.aggregation)}</select>
            </div>
            <div class="field">
                <label>Orden</label>
                <select id="s-order">
                    <option value="alphabetical" ${cfg.order !== 'ranking' ? 'selected' : ''}>Alfabético</option>
                    <option value="ranking" ${cfg.order === 'ranking' ? 'selected' : ''}>Ranking (mayor a menor)</option>
                </select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Orientación</label>
                <select id="s-orientation">
                    <option value="vertical" ${cfg.orientation !== 'horizontal' ? 'selected' : ''}>Vertical</option>
                    <option value="horizontal" ${cfg.orientation === 'horizontal' ? 'selected' : ''}>Horizontal</option>
                </select>
            </div>
            <div class="field">
                <label>Línea de referencia (opcional)</label>
                <input type="number" step="any" id="s-refline" value="${cfg.reference_line?.value ?? ''}">
            </div>
        </div>
    `,
    stacked_bar: (dataset, metrics, cfg) => `
        <div class="field-row">
            <div class="field">
                <label>Métrica base</label>
                <select id="s-metric">${metricOptionsHtml(dataset, metrics, metricRefToValue(cfg.base_metric))}</select>
            </div>
            <div class="field">
                <label>Columna de segmentos (máx. 6)</label>
                <select id="s-segment">${columnOptions(columnsOfType(dataset, ['categorica']), cfg.segment_column)}</select>
            </div>
        </div>
        <div class="field-row">
            <div class="field">
                <label>Eje de categorías</label>
                <select id="s-category">${columnOptions(columnsOfType(dataset, ['categorica', 'texto']), cfg.category_column)}</select>
            </div>
            <div class="field">
                <label>Modo</label>
                <select id="s-mode">
                    <option value="absolute" ${cfg.mode !== 'percent' ? 'selected' : ''}>Absoluto</option>
                    <option value="percent" ${cfg.mode === 'percent' ? 'selected' : ''}>100% apilado</option>
                </select>
            </div>
        </div>
    `,
};

// Etiquetas de agregación en castellano. "máximo (mejor dato)" no es cosmético: en fuerza el número
// que le importa al preparador es el mejor levantamiento del período, no el promedio de todos los
// intentos —que incluye los de entrada en calor y lo baja—, y con el desplegable diciendo "max"
// nadie encontraba esa opción.
const AGG_OPTION_LABELS = {
    sum: 'suma',
    avg: 'promedio',
    min: 'mínimo',
    max: 'máximo (mejor dato)',
    count: 'conteo',
};

function aggOptions(selected) {
    return ['sum', 'avg', 'min', 'max', 'count'].map(a =>
        `<option value="${a}" ${a === selected ? 'selected' : ''}>${AGG_OPTION_LABELS[a]}</option>`
    ).join('');
}

// Opciones de columna para TABLAS: además de métricas numéricas y custom, permite columnas de
// dimensión (texto/categóricas: sub-familia, posición, partido) que se muestran tal cual.
function tableColumnOptionsHtml(dataset, customMetrics, selectedValue) {
    const label = (c) => (typeof SYNTH_LABELS !== 'undefined' && SYNTH_LABELS[c]) || c;
    let html = '<optgroup label="Métricas (numéricas)">';
    columnsOfType(dataset, ['numerica']).forEach(c => {
        const val = `col::${c}`;
        html += `<option value="${escapeHtml(val)}" ${val === selectedValue ? 'selected' : ''}>${escapeHtml(label(c))}</option>`;
    });
    html += '</optgroup>';
    const dimCols = columnsOfType(dataset, ['categorica', 'texto']);
    if (dimCols.length) {
        html += '<optgroup label="Dimensiones (texto)">';
        dimCols.forEach(c => {
            const val = `col::${c}`;
            html += `<option value="${escapeHtml(val)}" ${val === selectedValue ? 'selected' : ''}>${escapeHtml(label(c))}</option>`;
        });
        html += '</optgroup>';
    }
    if (customMetrics.length > 0) {
        html += '<optgroup label="Métricas configurables">';
        customMetrics.forEach(m => {
            const val = `metric::${m.id}`;
            html += `<option value="${escapeHtml(val)}" ${val === selectedValue ? 'selected' : ''}>${escapeHtml(m.nombre)}</option>`;
        });
        html += '</optgroup>';
    }
    return html;
}

// Opciones de agrupación de filas de una tabla: presets + cualquier dimensión (sub-familia, etc.).
function rowGrainOptions(dataset, selected) {
    const sel = selected || 'player_session';
    const label = (c) => (typeof SYNTH_LABELS !== 'undefined' && SYNTH_LABELS[c]) || c;
    let html =
        `<option value="player_session" ${sel === 'player_session' ? 'selected' : ''}>Por jugador + sesión (una fila por registro)</option>` +
        `<option value="player" ${sel === 'player' ? 'selected' : ''}>Por jugador (agrupado)</option>`;
    const dims = columnsOfType(dataset, ['categorica', 'texto']);
    if (dims.length) {
        html += '<optgroup label="Agrupar por dimensión">';
        dims.forEach(c => {
            html += `<option value="${escapeHtml(c)}" ${c === sel ? 'selected' : ''}>${escapeHtml(label(c))}</option>`;
        });
        html += '</optgroup>';
    }
    return html;
}

function tableAggOptions(selected) {
    const labels = { ...AGG_OPTION_LABELS, text: 'texto (mostrar tal cual)' };
    return ['sum', 'avg', 'min', 'max', 'count', 'text'].map(a =>
        `<option value="${a}" ${a === selected ? 'selected' : ''}>${labels[a]}</option>`
    ).join('');
}

function columnOptions(cols, selected) {
    return cols.map(c => `<option value="${escapeHtml(c)}" ${c === selected ? 'selected' : ''}>${escapeHtml(c)}</option>`).join('');
}

function wireSectionBehavior(type, dataset, metrics, cfg) {
    if (type === 'kpi_card') {
        return;
    }
    if (type === 'table') {
        const colContainer = document.getElementById('s-columns-container');
        const addColumnRow = (col = {}) => {
            const row = document.createElement('div');
            row.className = 'repeatable-row';
            row.innerHTML = `
                <select class="col-metric">${tableColumnOptionsHtml(dataset, metrics, metricRefToValue(col.source ? col : null))}</select>
                <input type="text" class="col-label" placeholder="Etiqueta" value="${escapeHtml(col.label ?? '')}">
                <select class="col-agg">${tableAggOptions(col.aggregation ?? 'avg')}</select>
                <button type="button" class="remove-row-btn">&times;</button>
            `;
            row.querySelector('.remove-row-btn').addEventListener('click', () => row.remove());
            colContainer.appendChild(row);
        };
        (cfg.columns || []).forEach(addColumnRow);
        if (!cfg.columns || cfg.columns.length === 0) addColumnRow();
        document.getElementById('s-add-column').addEventListener('click', () => addColumnRow());

        const rulesContainer = document.getElementById('s-rules-container');
        const addRuleRow = (rule = {}) => {
            const row = document.createElement('div');
            row.className = 'repeatable-row';
            row.innerHTML = `
                <input type="text" class="rule-col" placeholder="Label de columna" value="${escapeHtml(rule.column ?? '')}">
                <select class="rule-op">
                    ${['gt', 'gte', 'lt', 'lte', 'eq'].map(o => `<option value="${o}" ${o === rule.operator ? 'selected' : ''}>${o}</option>`).join('')}
                </select>
                <input type="number" step="any" class="rule-val" placeholder="Valor" value="${rule.value ?? ''}">
                <select class="rule-color">
                    <option value="moss" ${rule.color === 'moss' ? 'selected' : ''}>Verde (on-target)</option>
                    <option value="amber" ${rule.color === 'amber' ? 'selected' : ''}>Ámbar</option>
                    <option value="clay" ${rule.color === 'clay' ? 'selected' : ''}>Rojo (off-target)</option>
                </select>
                <button type="button" class="remove-row-btn">&times;</button>
            `;
            row.querySelector('.remove-row-btn').addEventListener('click', () => row.remove());
            rulesContainer.appendChild(row);
        };
        (cfg.conditional_rules || []).forEach(addRuleRow);
        document.getElementById('s-add-rule').addEventListener('click', () => {
            if (rulesContainer.children.length >= 3) return alert('Máximo 3 reglas.');
            addRuleRow();
        });
    }
    if (type === 'line_chart') {
        const container = document.getElementById('s-ymetrics-container');
        const addRow = (m = {}) => {
            const row = document.createElement('div');
            row.className = 'repeatable-row';
            row.innerHTML = `
                <select class="ym-metric">${metricOptionsHtml(dataset, metrics, metricRefToValue(m.source ? m : null))}</select>
                <input type="text" class="ym-label" placeholder="Etiqueta" value="${escapeHtml(m.label ?? '')}">
                <button type="button" class="remove-row-btn">&times;</button>
            `;
            row.querySelector('.remove-row-btn').addEventListener('click', () => row.remove());
            container.appendChild(row);
        };
        (cfg.y_metrics || []).forEach(addRow);
        if (!cfg.y_metrics || cfg.y_metrics.length === 0) addRow();
        document.getElementById('s-add-ymetric').addEventListener('click', () => addRow());
    }
}

function collectTypeConfig(type) {
    if (type === 'kpi_card') {
        const config = {
            metric: parseMetricValue(document.getElementById('s-metric').value),
            aggregation: document.getElementById('s-aggregation').value,
            number_format: {
                decimals: parseInt(document.getElementById('s-decimals').value || '1', 10),
                unit: document.getElementById('s-unit').value,
            },
            scale_selector: document.getElementById('s-scale').checked,
        };
        if (document.getElementById('s-comparison').checked) {
            config.comparison = {
                enabled: true,
                reference_value: parseFloat(document.getElementById('s-comparison-ref').value || '0'),
                label: document.getElementById('s-comparison-label').value,
            };
        }
        return config;
    }

    if (type === 'table') {
        const columns = Array.from(document.querySelectorAll('#s-columns-container .repeatable-row')).map(row => ({
            ...parseMetricValue(row.querySelector('.col-metric').value),
            label: row.querySelector('.col-label').value,
            aggregation: row.querySelector('.col-agg').value,
        }));
        const rules = Array.from(document.querySelectorAll('#s-rules-container .repeatable-row')).map(row => ({
            column: row.querySelector('.rule-col').value,
            operator: row.querySelector('.rule-op').value,
            value: parseFloat(row.querySelector('.rule-val').value || '0'),
            color: row.querySelector('.rule-color').value,
        }));
        const config = {
            columns,
            row_grain: document.getElementById('s-row-grain').value,
            search_enabled: document.getElementById('s-search').checked,
            scale_selector: document.getElementById('s-scale').checked,
        };
        if (rules.length > 0) config.conditional_rules = rules;
        const sortCol = document.getElementById('s-sort-column').value;
        if (sortCol) config.default_sort = { column: sortCol, direction: document.getElementById('s-sort-direction').value };
        return config;
    }

    if (type === 'line_chart') {
        const yMetrics = Array.from(document.querySelectorAll('#s-ymetrics-container .repeatable-row')).map(row => ({
            ...parseMetricValue(row.querySelector('.ym-metric').value),
            label: row.querySelector('.ym-label').value,
        }));
        const config = {
            y_metrics: yMetrics,
            x_column: document.getElementById('s-xcolumn').value,
            aggregation: document.getElementById('s-aggregation').value,
            style: document.getElementById('s-style').value,
        };
        const groupBy = document.getElementById('s-groupby').value;
        if (groupBy) config.group_by = groupBy;
        return config;
    }

    if (type === 'bar_chart') {
        const config = {
            metric: parseMetricValue(document.getElementById('s-metric').value),
            category_column: document.getElementById('s-category').value,
            aggregation: document.getElementById('s-aggregation').value,
            order: document.getElementById('s-order').value,
            orientation: document.getElementById('s-orientation').value,
        };
        const refVal = document.getElementById('s-refline').value;
        if (refVal) config.reference_line = { value: parseFloat(refVal), label: 'Referencia' };
        return config;
    }

    if (type === 'stacked_bar') {
        return {
            base_metric: parseMetricValue(document.getElementById('s-metric').value),
            segment_column: document.getElementById('s-segment').value,
            category_column: document.getElementById('s-category').value,
            mode: document.getElementById('s-mode').value,
        };
    }

    return {};
}

function setupWidgetModal() {
    // "+ Agregar widget" abre el flujo por prompt; el editor manual es el fallback ("Crear a mano").
    document.getElementById('add-widget-btn')?.addEventListener('click', openPromptModal);
    document.getElementById('widget-modal-close').addEventListener('click', closeWidgetModal);
    document.getElementById('wf-type').addEventListener('change', buildTypeSection);

    document.getElementById('widget-form').addEventListener('submit', async (e) => {
        e.preventDefault();
        const type = document.getElementById('wf-type').value;
        const datasetIds = checkedDatasetIds();
        if (datasetIds.length === 0) {
            showAlert(document.getElementById('widget-modal-alert'), 'Elegí al menos un dataset.', 'error');
            return;
        }
        const config = collectTypeConfig(type);
        config.dataset_ids = datasetIds;
        config.title = document.getElementById('wf-title').value;

        const filterCol = document.getElementById('wf-filter-column').value;
        if (filterCol) {
            config.filter = {
                column: filterCol,
                operator: document.getElementById('wf-filter-operator').value,
                value: document.getElementById('wf-filter-value').value,
            };
        }

        const formData = new FormData();
        const id = document.getElementById('wf-id').value;
        if (id) formData.append('id', id);
        formData.append('view_id', ACTIVE_VIEW_ID);
        formData.append('type', type);
        formData.append('config', JSON.stringify(config));

        try {
            await Api.postForm('../api/widgets.php', formData);
            closeWidgetModal();
            loadWidgets();
        } catch (err) {
            showAlert(document.getElementById('widget-modal-alert'), err.message, 'error');
        }
    });
}

// ---------- Prompt modal: agregar widget con IA (multi-turno) ----------

let promptAnswers = [];   // [{question, answer}] acumuladas
let promptQuestions = []; // preguntas del turno actual
let promptWidget = null;  // widget propuesto listo para confirmar

function openPromptModal(prefill) {
    promptAnswers = [];
    promptQuestions = [];
    promptWidget = null;
    document.getElementById('pm-name').value = '';
    document.getElementById('pm-prompt').value = typeof prefill === 'string' ? prefill : '';
    document.getElementById('pm-questions').innerHTML = '';
    document.getElementById('pm-preview').innerHTML = '';
    renderPromptExamples();
    document.getElementById('pm-submit-btn').style.display = '';
    document.getElementById('pm-submit-btn').textContent = 'Generar con IA';
    showAlert(document.getElementById('prompt-modal-alert'), null);
    document.getElementById('prompt-modal').classList.remove('hidden');
    // Con ejemplo pre-cargado, el foco va al nombre (el pedido ya está escrito); si no, al pedido.
    requestAnimationFrame(() => {
        document.getElementById(prefill ? 'pm-name' : 'pm-prompt')?.focus();
    });
}

function closePromptModal() {
    document.getElementById('prompt-modal').classList.add('hidden');
}

// Chips de ejemplo dentro del compositor: rellenan el pedido con un click.
function renderPromptExamples() {
    const box = document.getElementById('pm-examples');
    if (!box) return;
    box.innerHTML = `<span class="pm-examples-label">Probá:</span>` + AI_HERO_EXAMPLES.map((ex, i) =>
        `<button class="ai-chip" type="button" data-i="${i}"><span class="ai-chip-bolt" aria-hidden="true">⚡</span> ${escapeHtml(ex.label)}</button>`
    ).join('');
    box.querySelectorAll('.ai-chip').forEach((chip) => {
        chip.addEventListener('click', () => {
            const pr = document.getElementById('pm-prompt');
            pr.value = AI_HERO_EXAMPLES[Number(chip.dataset.i)].prompt;
            pr.focus();
        });
    });
}

function renderPromptQuestions(questions) {
    promptQuestions = questions;
    document.getElementById('pm-preview').innerHTML = '';
    document.getElementById('pm-questions').innerHTML = `
        <div class="pm-questions-box">
            <div class="pm-questions-title">La IA necesita un par de aclaraciones:</div>
            ${questions.map((q, i) => `
                <div class="field">
                    <label>${escapeHtml(q)}</label>
                    <input type="text" class="pm-answer" data-i="${i}" placeholder="Tu respuesta">
                </div>`).join('')}
        </div>`;
    document.getElementById('pm-submit-btn').textContent = 'Enviar respuestas';
}

const WIDGET_TYPE_LABELS = { kpi_card: 'KPI card', table: 'Tabla', line_chart: 'Línea temporal', bar_chart: 'Barra por categoría', stacked_bar: 'Barra apilada' };
const AGG_LABELS = { sum: 'suma', avg: 'promedio', min: 'mínimo', max: 'máximo', count: 'conteo' };

/** Etiqueta legible de una referencia de métrica ({source:'column'|'custom_metric', ...}). */
function metricLabel(ref) {
    if (!ref) return null;
    if (ref.source === 'custom_metric') {
        const m = customMetricsCache.find(x => String(x.id) === String(ref.metric_id));
        return m ? m.nombre : 'métrica configurable';
    }
    const c = ref.column;
    return c ? (SYNTH_LABELS[c] || c) : null;
}

const colLabel = (c) => (c ? (SYNTH_LABELS[c] || c) : null);
const opText = { eq: 'es igual a', neq: 'es distinto de', gt: 'es mayor que', gte: 'es mayor o igual a', lt: 'es menor que', lte: 'es menor o igual a' };

/** Traduce un config de widget a filas de resumen en español plano (clave → valor HTML). */
function describeWidgetConfig(type, config) {
    const rows = [];
    const add = (key, val) => { if (val) rows.push({ key, val }); };
    const b = (s) => `<strong>${escapeHtml(String(s))}</strong>`;

    const dsNames = configDatasetIds(config).map(id => getDataset(id)?.nombre || `#${id}`);
    add('Datos', dsNames.length ? dsNames.map(b).join(', ') : null);

    if (type === 'kpi_card') {
        const agg = AGG_LABELS[config.aggregation] || config.aggregation;
        const met = metricLabel(config.metric);
        if (met) add('Muestra', `${agg ? escapeHtml(agg) + ' de ' : ''}${b(met)}`);
        const unit = config.number_format?.unit;
        if (unit) add('Unidad', escapeHtml(unit));
        if (config.comparison?.enabled) add('Compara', `contra ${b(config.comparison.reference_value ?? '—')} (${escapeHtml(config.comparison.label || 'referencia')})`);
    } else if (type === 'table') {
        const grainMap = { player_session: 'una fila por jugador y sesión', player: 'una fila por jugador (agrupado)' };
        add('Filas', escapeHtml(grainMap[config.row_grain] || `agrupadas por ${colLabel(config.row_grain) || 'registro'}`));
        const cols = Array.isArray(config.columns) ? config.columns.map(c => c.label || metricLabel(c) || colLabel(c.column)).filter(Boolean) : [];
        if (cols.length) add('Columnas', cols.map(b).join(', '));
        if (config.default_sort?.column) add('Orden', `por ${b(config.default_sort.column)} (${config.default_sort.direction === 'asc' ? 'ascendente' : 'descendente'})`);
        if (Array.isArray(config.conditional_rules) && config.conditional_rules.length) add('Formato', `${b(config.conditional_rules.length)} regla(s) de color condicional`);
        if (config.search_enabled) add('Extra', 'con búsqueda de texto');
    } else if (type === 'line_chart') {
        const metrics = Array.isArray(config.y_metrics) ? config.y_metrics.map(metricLabel).filter(Boolean) : [];
        add('Evolución de', metrics.length ? metrics.map(b).join(', ') : null);
        add('Eje X', colLabel(config.x_column));
        if (config.group_by) add('Una línea por', b(colLabel(config.group_by)));
        add('Por punto', AGG_LABELS[config.aggregation] ? escapeHtml(AGG_LABELS[config.aggregation]) : null);
    } else if (type === 'bar_chart') {
        const agg = AGG_LABELS[config.aggregation] || config.aggregation;
        const met = metricLabel(config.metric);
        if (met) add('Muestra', `${agg ? escapeHtml(agg) + ' de ' : ''}${b(met)}`);
        add('Por', colLabel(config.category_column) ? b(colLabel(config.category_column)) : null);
        add('Orden', config.order === 'ranking' ? 'ranking (mayor a menor)' : 'alfabético');
        if (config.orientation === 'horizontal') add('Orientación', 'horizontal');
        if (config.reference_line?.value != null && config.reference_line.value !== '') add('Línea de ref.', b(config.reference_line.value));
    } else if (type === 'stacked_bar') {
        add('Muestra', metricLabel(config.base_metric) ? b(metricLabel(config.base_metric)) : null);
        add('Por', colLabel(config.category_column) ? b(colLabel(config.category_column)) : null);
        add('Segmentado por', colLabel(config.segment_column) ? b(colLabel(config.segment_column)) : null);
        add('Modo', config.mode === 'percent' ? '100% apilado' : 'valores absolutos');
    }

    if (config.filter?.column) {
        add('Filtro', `${b(colLabel(config.filter.column))} ${escapeHtml(opText[config.filter.operator] || config.filter.operator)} ${b(config.filter.value)}`);
    }
    return rows;
}

function renderWidgetPreview(type, config) {
    promptWidget = { type, config };
    const rows = describeWidgetConfig(type, config);
    const summaryHtml = rows.length
        ? `<div class="pm-summary">${rows.map(r => `<div class="pm-summary-row"><div class="pm-summary-key">${escapeHtml(r.key)}</div><div class="pm-summary-val">${r.val}</div></div>`).join('')}</div>`
        : '<div class="pm-summary"><div class="pm-summary-row"><div class="pm-summary-val">Widget listo para agregar.</div></div></div>';

    document.getElementById('pm-questions').innerHTML = '';
    document.getElementById('pm-preview').innerHTML = `
        <div class="pm-preview-box">
            <div class="pm-preview-title">${escapeHtml(config.title || 'Widget')}</div>
            <div class="pm-preview-type"><span aria-hidden="true">◧</span> ${escapeHtml(WIDGET_TYPE_LABELS[type] || type)}</div>
            ${summaryHtml}
            <details class="pm-preview-tech">
                <summary>Ver configuración técnica</summary>
                <pre class="pm-preview-json">${escapeHtml(JSON.stringify(config, null, 2))}</pre>
            </details>
        </div>
        <div class="btn-row">
            <button class="btn-secondary btn" id="pm-discard-btn" type="button">Descartar</button>
            <button class="btn" id="pm-confirm-btn" type="button">Agregar al tablero</button>
        </div>`;
    document.getElementById('pm-submit-btn').style.display = 'none';
    document.getElementById('pm-discard-btn').addEventListener('click', () => {
        promptWidget = null;
        document.getElementById('pm-preview').innerHTML = '';
        document.getElementById('pm-submit-btn').style.display = '';
        renderPromptExamples();
    });
    document.getElementById('pm-confirm-btn').addEventListener('click', applyPromptWidget);
}

async function submitPrompt() {
    const name = document.getElementById('pm-name').value.trim();
    const prompt = document.getElementById('pm-prompt').value.trim();
    if (!prompt) {
        showAlert(document.getElementById('prompt-modal-alert'), 'Escribí qué querés ver.', 'error');
        return;
    }

    // Si hay preguntas en pantalla, sumamos las respuestas a lo acumulado.
    document.querySelectorAll('.pm-answer').forEach(inp => {
        promptAnswers.push({ question: promptQuestions[inp.dataset.i], answer: inp.value.trim() });
    });
    promptQuestions = [];

    const btn = document.getElementById('pm-submit-btn');
    btn.disabled = true;
    btn.textContent = 'Pensando…';
    document.getElementById('pm-examples').innerHTML = '';
    document.getElementById('pm-preview').innerHTML = `
        <div class="ai-thinking" role="status" aria-live="polite">
            <span class="ai-thinking-spark" aria-hidden="true">✦</span>
            <div class="ai-thinking-lines">
                <div class="ai-thinking-text">Armando tu análisis…</div>
                <div class="ai-thinking-bar"></div>
                <div class="ai-thinking-bar short"></div>
            </div>
        </div>`;
    showAlert(document.getElementById('prompt-modal-alert'), null);

    const fd = new FormData();
    fd.append('action', 'propose');
    fd.append('view_id', ACTIVE_VIEW_ID);
    fd.append('name', name);
    fd.append('prompt', prompt);
    fd.append('answers', JSON.stringify(promptAnswers));

    try {
        const result = await Api.postForm('../api/build_widget.php', fd);
        if (result.status === 'question') {
            renderPromptQuestions(result.questions);
        } else if (result.status === 'widget') {
            renderWidgetPreview(result.type, result.config);
        }
    } catch (err) {
        document.getElementById('pm-preview').innerHTML = '';
        renderPromptExamples();
        showAlert(document.getElementById('prompt-modal-alert'), err.message, 'error');
    } finally {
        btn.disabled = false;
        if (btn.textContent === 'Pensando…') btn.textContent = promptAnswers.length ? 'Enviar respuestas' : 'Generar con IA';
    }
}

async function applyPromptWidget() {
    if (!promptWidget) return;
    const fd = new FormData();
    fd.append('action', 'apply');
    fd.append('view_id', ACTIVE_VIEW_ID);
    fd.append('type', promptWidget.type);
    fd.append('config', JSON.stringify(promptWidget.config));
    try {
        await Api.postForm('../api/build_widget.php', fd);
        closePromptModal();
        loadWidgets();
    } catch (err) {
        showAlert(document.getElementById('prompt-modal-alert'), err.message, 'error');
    }
}

function setupPromptModal() {
    document.getElementById('prompt-modal-close')?.addEventListener('click', closePromptModal);
    document.getElementById('pm-submit-btn')?.addEventListener('click', submitPrompt);
    document.getElementById('pm-manual-btn')?.addEventListener('click', () => {
        closePromptModal();
        openWidgetModal(null);
    });
}

// ---------- Vistas: crear / renombrar / eliminar ----------

// Los flags de permiso los emite steps/analysis.php:
//   IS_CLUB_ADMIN         — admin_club o superadmin.
//   ACTIVE_VIEW_IS_CLUB   — la vista abierta es base del club (la ven todos) o privada.
//   CAN_EDIT_ACTIVE_VIEW  — puede renombrarla/eliminarla (propia siempre; del club solo el admin).
//
// El default cuando no llegan es PERMISIVO a propósito. Esto no es control de acceso: el servidor
// valida el permiso en cada endpoint y devuelve un error legible. Un default restrictivo por una
// constante que no se emitió le rompería la pantalla a un admin_club en silencio, que es peor que
// ofrecer un botón que el servidor va a rechazar con un mensaje claro.
function isClubAdmin() {
    return typeof IS_CLUB_ADMIN === 'undefined' ? true : IS_CLUB_ADMIN;
}
function canEditActiveView() {
    return typeof CAN_EDIT_ACTIVE_VIEW === 'undefined' ? true : CAN_EDIT_ACTIVE_VIEW;
}
function activeViewIsClub() {
    return typeof ACTIVE_VIEW_IS_CLUB === 'undefined' ? false : ACTIVE_VIEW_IS_CLUB;
}

/**
 * ¿Puede este usuario generar alguna vista base?
 *
 * NO es lo mismo que ser admin. El gate del servidor (api/base_views.php) es POR CATEGORÍA: un
 * miembro con la habilitación de kinesiología genera la vista base de kinesiología sin ser
 * administrador. Atar el botón a isClubAdmin() le escondía la función justo a quien la necesita,
 * que es el caso de uso que motivó todo el sistema de habilitaciones.
 *
 * Los overviews por jugador sí siguen siendo admin-only: no tienen categoría, así que ninguna
 * habilitación puede acotarlos.
 */
function puedeGenerarVistasBase() {
    if (isClubAdmin()) return true;
    return typeof CATEGORIAS_EDITABLES !== 'undefined'
        && Array.isArray(CATEGORIAS_EDITABLES)
        && CATEGORIAS_EDITABLES.length > 0;
}

const VIEW_PERMISSION_MSG = 'Es una vista del club: solo un administrador del club puede renombrarla o eliminarla. Sobre tus vistas propias podés hacer todo.';
const BASE_VIEWS_PERMISSION_MSG = 'Las vistas base del club las genera un administrador. Pedile a un administrador de tu club que las genere.';

// Avisos a nivel vista. En el estado vacío (club sin vistas todavía) #alert-box no existe y
// showAlert() explotaría con null: antes que perder el mensaje del servidor, cae a un alert().
function showViewAlert(message, type = 'error') {
    const box = document.getElementById('alert-box');
    if (box) {
        showAlert(box, message, type);
        if (message) box.scrollIntoView({ block: 'nearest' });
        return;
    }
    if (message) window.alert(message);
}

function setupViewModal() {
    const modal = document.getElementById('view-modal');
    const nameInput = document.getElementById('view-name-input');
    const descField = document.getElementById('view-desc-field');
    const descInput = document.getElementById('view-desc-input');
    const idInput = document.getElementById('view-id-input');
    const title = document.getElementById('view-modal-title');
    const submitBtn = document.getElementById('view-create-btn');
    const submitLabel = submitBtn?.querySelector('.btn-label');
    const alert = document.getElementById('view-modal-alert');
    const progress = document.getElementById('view-modal-progress');

    // Cuando la creación anduvo pero la generación falló, la vista YA EXISTE: perderla porque la
    // IA se cayó sería lo peor de los dos mundos. El botón cambia a "Abrir la vista" y navega.
    let createdViewId = null;
    let busy = false;

    const setLabel = (txt) => { if (submitLabel) submitLabel.textContent = txt; };
    const reset = () => {
        createdViewId = null;
        busy = false;
        submitBtn.classList.remove('is-loading');
        submitBtn.removeAttribute('aria-busy');
        const loadingLabel = submitBtn.querySelector('.btn-loading-label');
        if (loadingLabel) loadingLabel.textContent = 'Creando…';
        progress.innerHTML = '';
    };

    const openCreate = () => {
        reset();
        idInput.value = '';
        nameInput.value = '';
        descInput.value = '';
        descField.hidden = false;
        title.textContent = 'Nueva vista';
        setLabel('Crear vista');
        showAlert(alert, null);
        modal.classList.remove('hidden');
        nameInput.focus();
    };
    const openRename = () => {
        if (typeof ACTIVE_VIEW_ID === 'undefined' || !ACTIVE_VIEW_ID) return;
        // El ítem del menú ya viene deshabilitado desde el servidor; esto cubre el otro camino
        // (doble click en la tab activa), que no tiene estado visual de "no podés".
        if (!canEditActiveView()) { showViewAlert(VIEW_PERMISSION_MSG); return; }
        reset();
        idInput.value = ACTIVE_VIEW_ID;
        nameInput.value = (typeof ACTIVE_VIEW_NAME !== 'undefined') ? ACTIVE_VIEW_NAME : '';
        // Renombrar no regenera nada: el pedido en lenguaje natural no tiene sentido acá y
        // mostrarlo vacío haría pensar que renombrar borra la descripción original.
        descField.hidden = true;
        title.textContent = 'Renombrar vista';
        setLabel('Guardar nombre');
        showAlert(alert, null);
        modal.classList.remove('hidden');
        nameInput.focus();
        nameInput.select();
    };

    document.getElementById('add-view-btn')?.addEventListener('click', openCreate);
    document.getElementById('add-view-btn-empty')?.addEventListener('click', openCreate);
    document.getElementById('rename-view-btn')?.addEventListener('click', openRename);
    // Doble click en la tab activa también renombra.
    document.querySelector('.view-tab.active')?.addEventListener('dblclick', openRename);
    document.getElementById('view-modal-close')?.addEventListener('click', () => modal.classList.add('hidden'));

    // El rótulo dice lo que va a pasar de verdad: con pedido escrito, crear también genera.
    descInput?.addEventListener('input', () => {
        if (idInput.value || createdViewId) return;
        setLabel(descInput.value.trim() ? 'Crear y generar' : 'Crear vista');
    });

    submitBtn?.addEventListener('click', async () => {
        // Bandera y no `disabled`: deshabilitar el botón enfocado le tira el foco al <body>.
        if (busy) return;
        if (createdViewId) { window.location.href = `analysis.php?view_id=${createdViewId}`; return; }

        const nombre = nameInput.value.trim();
        if (!nombre) { showAlert(alert, 'Poné un nombre.', 'error'); return; }
        const id = idInput.value;
        const descripcion = descField.hidden ? '' : descInput.value.trim();
        const fd = new FormData();

        busy = true;
        submitBtn.classList.add('is-loading');
        submitBtn.setAttribute('aria-busy', 'true');
        showAlert(alert, null);

        try {
            if (id) {
                fd.append('action', 'rename');
                fd.append('id', id);
                fd.append('nombre', nombre);
                await Api.postForm('../api/views.php', fd);
                window.location.href = `analysis.php?view_id=${id}`;
                return;
            }

            fd.append('nombre', nombre);
            fd.append('description', descripcion);
            const result = await Api.postForm('../api/views.php', fd);
            createdViewId = result.id;

            // Encadenar o no la generación lo decide el servidor (`should_generate`): la regla
            // "vista nueva + descripción no vacía" está escrita una sola vez, en api/views.php.
            if (!result.should_generate) {
                window.location.href = `analysis.php?view_id=${createdViewId}`;
                return;
            }

            // La descripción ES el pedido: se genera antes de navegar. Si redirigiéramos primero,
            // el usuario se quedaría un minuto mirando una vista vacía sin saber si pasa algo.
            const loadingLabel = submitBtn.querySelector('.btn-loading-label');
            if (loadingLabel) loadingLabel.textContent = 'Generando…';   // ya no está "creando"
            const stop = renderGenerationProgress(progress, 'Armando tu vista…');
            try {
                const gen = new FormData();
                gen.append('view_id', createdViewId);
                await Api.postForm('../api/generate.php', gen);
                window.location.href = `analysis.php?view_id=${createdViewId}`;
            } finally {
                stop();
            }
        } catch (err) {
            showAlert(alert, createdViewId
                ? 'La vista se creó, pero la IA no pudo armarla: ' + err.message + ' Abrila y probá de nuevo desde "Generar con IA".'
                : err.message, 'error');
            busy = false;
            submitBtn.classList.remove('is-loading');
            submitBtn.removeAttribute('aria-busy');
            progress.innerHTML = '';
            if (createdViewId) setLabel('Abrir la vista');
        }
    });

    document.getElementById('delete-view-btn')?.addEventListener('click', async () => {
        if (!canEditActiveView()) { showViewAlert(VIEW_PERMISSION_MSG); return; }
        // Borrar una vista del club se la borra a todo el mundo: el confirm lo dice.
        const pregunta = activeViewIsClub()
            ? '¿Eliminar esta vista del club y todos sus widgets? La pierde todo el club y no se puede deshacer.'
            : '¿Eliminar esta vista y todos sus widgets? No se puede deshacer.';
        if (!confirm(pregunta)) return;
        try {
            await Api.del(`../api/views.php?id=${ACTIVE_VIEW_ID}`);
            window.location.href = 'analysis.php';
        } catch (err) {
            // Api tira Error(data.error) con el texto del servidor: se muestra tal cual, no un
            // "ocurrió un error" que no le dice nada a nadie.
            showViewAlert(err.message);
        }
    });
}

// ---------- Barra de vistas: dos filas (club / propias) sin scroll horizontal ----------
//
// Markup: #view-rail > .vrail-row[data-row] > (.vrail-label, .vrail-tabs, .vrail-overflow,
// [Jugadores ▾], [+ Nueva vista]). Ver steps/analysis.php.
//
// Ninguna fila scrollea. Lo que no entra en el ancho real se esconde (.is-collapsed) y aparece en
// el menú "+N ▾" de esa misma fila. El servidor no puede decidir esto: depende del ancho del
// viewport, del zoom y de la fuente que terminó cargando.

/**
 * Acomoda UNA fila: decide qué tabs entran y llena su menú de desborde.
 *
 * La medición es `scrollWidth > clientWidth` sobre `.vrail-tabs`, que es `flex: 0 1 auto` con
 * `min-width: 0` y `overflow: hidden`: cuando el contenido no entra, el flex lo encoge hasta el
 * ancho disponible y scrollWidth queda por encima de clientWidth. Todos sus hermanos de fila son
 * `flex: 0 0 auto`, así que el único que cede es este.
 */
function layoutViewRow(row) {
    const tabsEl = row.querySelector('.vrail-tabs');
    const wrap = row.querySelector('.vrail-overflow');
    if (!tabsEl || !wrap) return;
    const btn = wrap.querySelector('.vrail-more');
    const menu = wrap.querySelector('.widget-menu');
    const tabs = [...tabsEl.querySelectorAll('.view-tab')];

    // Estado limpio: sin esto, un resize hacia más ancho nunca devolvería las tabs escondidas.
    tabs.forEach((t) => t.classList.remove('is-collapsed'));
    wrap.hidden = true;
    menu.hidden = true;
    btn.setAttribute('aria-expanded', 'false');

    const fits = () => tabsEl.scrollWidth <= tabsEl.clientWidth + 1;
    if (!tabs.length || fits()) { menu.innerHTML = ''; return; }

    // El propio "+N ▾" ocupa ancho: se muestra ANTES de seguir midiendo, o la cuenta daría corta
    // y la última tab quedaría medio recortada — exactamente el defecto que vinimos a arreglar.
    btn.textContent = '+' + tabs.length + ' ▾';
    wrap.hidden = false;

    // Se esconde de derecha a izquierda, saltando la tab abierta: la vista que el usuario está
    // mirando nunca desaparece de la barra.
    for (let i = tabs.length - 1; i >= 0 && !fits(); i--) {
        if (tabs[i].classList.contains('active')) continue;
        tabs[i].classList.add('is-collapsed');
    }

    const hidden = tabs.filter((t) => t.classList.contains('is-collapsed'));
    if (!hidden.length) { wrap.hidden = true; menu.innerHTML = ''; return; }

    btn.textContent = '+' + hidden.length + ' ▾';
    menu.innerHTML = hidden.map((t) => {
        // textContent y no el atributo truncado: la tab recorta con ellipsis por CSS, el texto
        // del DOM sigue siendo el nombre completo.
        const nombre = t.textContent.trim();
        return `<a class="widget-menu-item" role="menuitem" href="?view_id=${encodeURIComponent(t.dataset.viewId || '')}">`
            + `<span class="wm-icon" aria-hidden="true">▤</span> ${escapeHtml(nombre)}</a>`;
    }).join('');
}

function layoutViewRail() {
    document.querySelectorAll('#view-rail .vrail-row').forEach(layoutViewRow);
}

function setupViewRail() {
    const rail = document.getElementById('view-rail');
    if (!rail) return;

    rail.querySelectorAll(".vrail-row").forEach((row) => {
        const slug = row.dataset.row;
        setupOverflowMenu('view-more-btn-' + slug, 'view-more-menu-' + slug);
        setupOverflowMenu('players-menu-btn-' + slug, 'players-menu-' + slug);
        setupViewTabsDrag(row.querySelector('.vrail-tabs'));
    });

    layoutViewRail();

    // Las tabs se miden en px: mientras la webfont no cargó, el ancho es el de la fuente de
    // respaldo y la cuenta sale distinta. Se recalcula cuando termina el swap.
    if (document.fonts && document.fonts.ready) {
        document.fonts.ready.then(layoutViewRail).catch(() => {});
    }

    let raf = 0;
    window.addEventListener('resize', () => {
        if (raf) cancelAnimationFrame(raf);
        raf = requestAnimationFrame(() => { raf = 0; layoutViewRail(); });
    });
}

// Reordenar las tabs de vista por arrastre. Las tabs son <a> (siguen navegando al hacer click);
// el drag las reacomoda en el DOM y persiste el nuevo orden. Solo aplica a las tabs inline (las
// vistas de jugador viven en su propio desplegable) y SOLO DENTRO DE SU FILA: una vista no cambia
// de dueño porque la arrastres, así que `nav` acota el drop a las tabs del mismo origen.
function setupViewTabsDrag(nav) {
    if (!nav) return;
    nav.querySelectorAll('.view-tab').forEach((tab) => {
        tab.draggable = true;
        tab.addEventListener('dragstart', (e) => {
            tab.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', tab.dataset.viewId || ''); } catch (_) {}
        });
        tab.addEventListener('dragend', () => {
            tab.classList.remove('dragging');
            // El orden nuevo puede cambiar qué entra en la fila (los nombres no miden lo mismo).
            layoutViewRail();
            persistViewOrder();
        });
        tab.addEventListener('dragover', (e) => {
            e.preventDefault();
            // Si la tab arrastrada es de la OTRA fila, este querySelector no la encuentra y el
            // drop no hace nada: es la barrera que impide mezclar filas.
            const dragging = nav.querySelector('.view-tab.dragging');
            if (!dragging || dragging === tab) return;
            const rect = tab.getBoundingClientRect();
            const before = e.clientX < rect.left + rect.width / 2;
            nav.insertBefore(dragging, before ? tab : tab.nextSibling);
        });
    });
}

// El orden de las tabs es una PREFERENCIA DE USUARIO (tabla view_order), no un dato del club: cada
// uno acomoda las suyas y las del club como quiera, sin pisarle el orden a nadie. Por eso el drag
// NO se gatea por permiso — un miembro común puede reordenar tabs de vistas del club.
//
// Se mandan los ids de LAS DOS filas en orden de documento (club y después propias). El endpoint
// numera 0..n la lista que recibe: mandar una sola fila dejaría a la otra con posiciones viejas
// intercaladas. Las tabs escondidas en "+N ▾" siguen en el DOM y viajan en su lugar exacto.
async function persistViewOrder() {
    const rail = document.getElementById('view-rail');
    if (!rail) return;
    const ids = [...rail.querySelectorAll('.vrail-tabs .view-tab')].map((t) => t.dataset.viewId).filter(Boolean);
    if (!ids.length) return;
    const fd = new FormData();
    fd.append('action', 'reorder');
    ids.forEach((id) => fd.append('ids[]', id));
    try {
        await Api.postForm('../api/views.php', fd);
    } catch (err) {
        // err.message es el texto que mandó el servidor (Api tira Error(data.error)): se muestra
        // completo, precedido de qué se estaba intentando hacer.
        showViewAlert('No se pudo guardar el orden de las vistas: ' + err.message);
    }
}

// ---------- Generar / Regenerar la vista entera con IA ----------

// api/generate.php tarda ~60 segundos. Un spinner mudo durante un minuto se lee como colgado, así
// que el texto avanza por etapas. No son barras de progreso reales (el endpoint es una sola
// llamada, no hay porcentaje que informar): son señales de que sigue trabajando. Por eso nunca
// dicen "faltan N segundos" — mentir sobre el tiempo es peor que no decir nada.
const GENERATION_STEPS = [
    'Leyendo tus datasets…',
    'Eligiendo qué gráficos cuentan mejor la historia…',
    'Armando los widgets…',
    'Últimos detalles…',
];

/**
 * Pinta el skeleton .ai-thinking en `container` y va rotando el texto. Devuelve la función que
 * corta el temporizador (hay que llamarla sí o sí, también en el camino de error).
 */
function renderGenerationProgress(container, firstText) {
    container.innerHTML = `
        <div class="ai-thinking" role="status" aria-live="polite">
            <span class="ai-thinking-spark" aria-hidden="true">✦</span>
            <div class="ai-thinking-lines">
                <div class="ai-thinking-text">${escapeHtml(firstText)}</div>
                <div class="ai-thinking-bar"></div>
                <div class="ai-thinking-bar short"></div>
            </div>
        </div>`;
    const textEl = container.querySelector('.ai-thinking-text');
    let i = -1;
    const timer = setInterval(() => {
        i = Math.min(i + 1, GENERATION_STEPS.length - 1);
        textEl.textContent = GENERATION_STEPS[i];
    }, 13000);
    return () => clearInterval(timer);
}

let generatingView = false;

/**
 * Revela el botón con el rótulo que corresponde. Sin widgets es "Generar"; con widgets es
 * "Regenerar" y pide confirmación, porque los reemplaza a todos.
 */
function refreshGenerateButton() {
    const btn = document.getElementById('generate-view-btn');
    if (!btn) return;   // vista que este usuario no puede editar: el servidor no lo renderizó
    btn.querySelector('.btn-label').textContent = currentWidgets.length ? 'Regenerar con IA' : 'Generar con IA';
    btn.hidden = false;
}

async function runViewGeneration() {
    if (generatingView) return;
    const btn = document.getElementById('generate-view-btn');
    const addBtn = document.getElementById('add-widget-btn');
    const grid = document.getElementById('widget-grid');
    if (!btn || !grid) return;

    // Regenerar es destructivo: se dice cuántos widgets se pierden, no un "¿estás seguro?" vacío.
    const n = currentWidgets.length;
    if (n > 0) {
        const msg = n === 1
            ? 'Esto reemplaza el widget actual por un tablero nuevo generado por IA. No se puede deshacer.'
            : `Esto reemplaza los ${n} widgets actuales por un tablero nuevo generado por IA. No se puede deshacer.`;
        if (!confirm(msg)) return;
    }

    generatingView = true;
    btn.classList.add('is-loading');       // .btn.is-loading ya corta pointer-events
    btn.setAttribute('aria-busy', 'true');
    if (addBtn) addBtn.disabled = true;
    showViewAlert(null);

    const stop = renderGenerationProgress(grid, 'Armando tu tablero…');
    try {
        const fd = new FormData();
        fd.append('view_id', ACTIVE_VIEW_ID);
        const res = await Api.postForm('../api/generate.php', fd);
        stop();
        await loadWidgets();
        const creados = Number(res.created ?? 0);
        const borrados = Number(res.deleted ?? 0);
        showViewAlert(borrados
            ? `Listo: ${creados} widgets nuevos (se reemplazaron ${borrados}).`
            : `Listo: ${creados} widgets nuevos.`, 'success');
    } catch (err) {
        stop();
        // loadWidgets() repinta lo que haya quedado en la base: si el endpoint borró y falló al
        // crear, el usuario tiene que ver el estado real, no el skeleton congelado.
        await loadWidgets();
        showViewAlert(err.message);
    } finally {
        generatingView = false;
        btn.classList.remove('is-loading');
        btn.removeAttribute('aria-busy');
        if (addBtn) addBtn.disabled = false;
    }
}

// ---------- Modal: generar vistas base con IA ----------

function setupBaseModal() {
    const modal = document.getElementById('base-modal');
    if (!modal) return;

    const alertBox = document.getElementById('base-modal-alert');
    const screenMode = document.getElementById('base-screen-mode');
    const screenGuided = document.getElementById('base-screen-guided');
    const screenProgress = document.getElementById('base-screen-progress');
    const playersToggle = document.getElementById('base-players-toggle');
    document.getElementById('base-player-count').textContent = PLAYER_COUNT;

    function showScreen(which) {
        screenMode.hidden = which !== 'mode';
        screenGuided.hidden = which !== 'guided';
        screenProgress.hidden = which !== 'progress';
    }

    function openModal() {
        // Generar las vistas base reescribe el tablero de todo el club: solo admin_club. Los
        // disparadores (#gen-base-btn, #gen-base-btn-empty) ni se renderizan para un miembro;
        // esto cubre el ?base_views=1 pegado a mano y cualquier link viejo.
        if (!puedeGenerarVistasBase()) { showViewAlert(BASE_VIEWS_PERMISSION_MSG); return; }
        showAlert(alertBox, null);
        document.querySelector('input[name="base-mode"][value="auto"]').checked = true;
        document.getElementById('base-cluster-chips').innerHTML = BASE_CLUSTERS.length
            ? BASE_CLUSTERS.map((c) => `<span class="base-chip">${escapeHtml(c.label)} <span class="base-chip-count">${c.count}</span></span>`).join('')
            : '<span class="card-sub">No hay datos cargados todavía.</span>';
        showScreen('mode');
        modal.classList.remove('hidden');
    }
    function closeModal() { modal.classList.add('hidden'); }

    document.getElementById('gen-base-btn')?.addEventListener('click', openModal);
    document.getElementById('gen-base-btn-empty')?.addEventListener('click', openModal);
    // CTA inline de la fila "Del club" vacía (el club no generó sus vistas base todavía pero el
    // usuario ya tiene vistas propias, así que la card del estado vacío no se renderiza).
    document.getElementById('gen-base-btn-row')?.addEventListener('click', openModal);
    document.getElementById('base-modal-close')?.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });

    document.getElementById('base-continue-btn')?.addEventListener('click', async () => {
        const mode = document.querySelector('input[name="base-mode"]:checked').value;
        if (mode === 'auto') {
            runGeneration({});
        } else {
            await showGuided();
        }
    });
    document.getElementById('base-back-btn')?.addEventListener('click', () => showScreen('mode'));
    document.getElementById('base-generate-btn')?.addEventListener('click', () => {
        const intents = {};
        screenGuided.querySelectorAll('.base-guided-cluster').forEach((sec) => {
            const cat = sec.dataset.categoria;
            const checked = [...sec.querySelectorAll('.base-guided-chip input:checked')].map((i) => i.value);
            const free = sec.querySelector('.base-guided-text').value.trim();
            const parts = [];
            if (checked.length) parts.push('Mostrar especialmente: ' + checked.join('; '));
            if (free) parts.push(free);
            intents[cat] = parts.join('. ');
        });
        runGeneration(intents);
    });

    async function showGuided() {
        const container = document.getElementById('base-guided-clusters');
        container.innerHTML = '<div class="base-thinking">Pensando sugerencias para cada grupo…</div>';
        showScreen('guided');
        let suggestions = {};
        try {
            const fd = new FormData();
            fd.append('action', 'suggest');
            const res = await Api.postForm('../api/base_views.php', fd);
            suggestions = res.clusters || {};
        } catch (err) {
            showAlert(alertBox, 'No se pudieron cargar sugerencias, podés escribir lo que quieras ver. (' + err.message + ')', 'error');
        }
        container.innerHTML = BASE_CLUSTERS.map((c) => {
            const items = suggestions[c.categoria] || [];
            const chipsHtml = items.length
                ? items.map((it) => `<label class="base-guided-chip"><input type="checkbox" value="${escapeHtml(it)}"> ${escapeHtml(it)}</label>`).join('')
                : '<span class="card-sub">Sin sugerencias automáticas.</span>';
            return `<div class="base-guided-cluster" data-categoria="${escapeHtml(c.categoria)}">
                <div class="base-guided-title">${escapeHtml(c.label)} <span class="dataset-group-count">${c.count}</span></div>
                <div class="base-guided-chips">${chipsHtml}</div>
                <textarea class="base-guided-text" placeholder="Otra cosa que quieras ver en ${escapeHtml(c.label)} (opcional)…"></textarea>
            </div>`;
        }).join('');
    }

    async function runGeneration(intents) {
        showScreen('progress');
        const list = document.getElementById('base-progress-list');
        const steps = BASE_CLUSTERS.map((c) => ({ key: 'cluster:' + c.categoria, label: 'Vista de ' + c.label }));
        if (playersToggle.checked) steps.push({ key: 'players', label: 'Overview por jugador (' + PLAYER_COUNT + ')' });
        list.innerHTML = steps.map((s) =>
            `<div class="base-step" data-key="${escapeHtml(s.key)}"><span class="base-step-icon">◦</span> <span>${escapeHtml(s.label)}</span> <span class="base-step-status"></span></div>`
        ).join('');

        const setStatus = (key, icon, txt, cls) => {
            const el = list.querySelector(`.base-step[data-key="${CSS.escape(key)}"]`);
            if (!el) return;
            el.querySelector('.base-step-icon').textContent = icon;
            el.querySelector('.base-step-status').textContent = txt || '';
            el.className = 'base-step' + (cls ? ' ' + cls : '');
        };

        let firstViewId = null;
        for (const c of BASE_CLUSTERS) {
            const key = 'cluster:' + c.categoria;
            setStatus(key, '⟳', 'generando…', 'active');
            try {
                const fd = new FormData();
                fd.append('action', 'generate_cluster');
                fd.append('categoria', c.categoria);
                fd.append('intent', intents[c.categoria] || '');
                const res = await Api.postForm('../api/base_views.php', fd);
                if (firstViewId === null) firstViewId = res.view_id;
                setStatus(key, '✓', res.created + ' widgets', 'done');
            } catch (err) {
                setStatus(key, '⚠', err.message, 'error');
            }
        }

        if (playersToggle.checked) {
            setStatus('players', '⟳', 'generando…', 'active');
            try {
                const fd = new FormData();
                fd.append('action', 'generate_players');
                const res = await Api.postForm('../api/base_views.php', fd);
                setStatus('players', '✓', res.created + ' jugadores', 'done');
            } catch (err) {
                setStatus('players', '⚠', err.message, 'error');
            }
        }

        const done = document.createElement('div');
        done.className = 'btn-row';
        done.style.marginTop = '16px';
        done.innerHTML = '<button class="btn" id="base-done-btn" type="button">Ver mis vistas</button>';
        list.appendChild(done);
        document.getElementById('base-done-btn').addEventListener('click', () => {
            window.location = 'analysis.php' + (firstViewId ? ('?view_id=' + firstViewId) : '');
        });
    }

    // ?base_views=1 abre el modal directo. Para un miembro no abre nada y no dispara ningún aviso:
    // no llegó pidiendo esto, llegó por un link. La pantalla ya le explica a quién pedírselas.
    if (typeof OPEN_BASE_VIEWS !== 'undefined' && OPEN_BASE_VIEWS && puedeGenerarVistasBase()) openModal();
}

// ---------- AI patch modal ----------

let proposedPatch = null;

function openAiModal(widget) {
    document.getElementById('ai-widget-id').value = widget.id;
    document.getElementById('ai-instruction').value = '';
    document.getElementById('ai-preview-container').innerHTML = '';
    showAlert(document.getElementById('ai-modal-alert'), null);
    document.getElementById('ai-modal').classList.remove('hidden');
}

function setupAiModal() {
    document.getElementById('ai-modal-close').addEventListener('click', () => {
        document.getElementById('ai-modal').classList.add('hidden');
    });

    document.getElementById('ai-propose-btn').addEventListener('click', async () => {
        const widgetId = document.getElementById('ai-widget-id').value;
        const instruction = document.getElementById('ai-instruction').value.trim();
        if (!instruction) return;

        const btn = document.getElementById('ai-propose-btn');
        btn.disabled = true;
        btn.textContent = 'Pensando...';
        showAlert(document.getElementById('ai-modal-alert'), null);

        const formData = new FormData();
        formData.append('action', 'propose');
        formData.append('widget_id', widgetId);
        formData.append('instruction', instruction);

        try {
            const result = await Api.postForm('../api/ai_patch_widget.php', formData);
            proposedPatch = result.merged_config;
            const preview = document.getElementById('ai-preview-container');
            preview.innerHTML = `
                <div class="ai-preview-box"><pre style="white-space:pre-wrap;font-family:var(--font-mono);font-size:12px;">${escapeHtml(JSON.stringify(result.patch, null, 2))}</pre></div>
                <div class="btn-row">
                    <button class="btn-secondary btn" id="ai-discard-btn" type="button">Descartar</button>
                    <button class="btn" id="ai-confirm-btn" type="button">Aplicar cambio</button>
                </div>
            `;
            document.getElementById('ai-discard-btn').addEventListener('click', () => {
                preview.innerHTML = '';
                proposedPatch = null;
            });
            document.getElementById('ai-confirm-btn').addEventListener('click', async () => {
                const applyFormData = new FormData();
                applyFormData.append('action', 'apply');
                applyFormData.append('widget_id', widgetId);
                applyFormData.append('merged_config', JSON.stringify(proposedPatch));
                try {
                    await Api.postForm('../api/ai_patch_widget.php', applyFormData);
                    document.getElementById('ai-modal').classList.add('hidden');
                    loadWidgets();
                } catch (err) {
                    showAlert(document.getElementById('ai-modal-alert'), err.message, 'error');
                }
            });
        } catch (err) {
            showAlert(document.getElementById('ai-modal-alert'), err.message, 'error');
        } finally {
            btn.disabled = false;
            btn.textContent = 'Proponer cambio';
        }
    });
}

// ---------- Custom metrics modal ----------

function setupMetricsModal() {
    const modal = document.getElementById('metrics-modal');
    document.getElementById('metrics-btn')?.addEventListener('click', async () => {
        await refreshMetricsList();
        renderMetricColumnPicker();
        modal.classList.remove('hidden');
    });
    document.getElementById('metrics-modal-close').addEventListener('click', () => modal.classList.add('hidden'));
    document.getElementById('metric-dataset').addEventListener('change', renderMetricColumnPicker);
    document.getElementById('metric-operation').addEventListener('change', renderMetricColumnPicker);
    document.getElementById('metric-create-btn').addEventListener('click', createMetric);
}

function renderMetricColumnPicker() {
    const datasetId = document.getElementById('metric-dataset').value;
    const operation = document.getElementById('metric-operation').value;
    const dataset = getDataset(datasetId);
    const numericCols = columnsOfType(dataset, ['numerica']);
    const container = document.getElementById('metric-columns-container');
    const fixedTwo = ['subtract', 'divide', 'ratio'].includes(operation);

    if (fixedTwo) {
        container.innerHTML = `
            <div class="field-row">
                <select class="metric-col">${columnOptions(numericCols)}</select>
                <select class="metric-col">${columnOptions(numericCols)}</select>
            </div>`;
    } else {
        container.innerHTML = `<div id="metric-cols-list"><select class="metric-col">${columnOptions(numericCols)}</select></div>
            <button type="button" class="add-row-btn" id="add-metric-col">+ Agregar columna</button>`;
        document.getElementById('add-metric-col').addEventListener('click', () => {
            const sel = document.createElement('select');
            sel.className = 'metric-col';
            sel.innerHTML = columnOptions(numericCols);
            document.getElementById('metric-cols-list').appendChild(sel);
        });
    }
}

async function refreshMetricsList() {
    const result = await Api.get(`../api/metrics.php?view_id=${ACTIVE_VIEW_ID}`);
    customMetricsCache = result.metrics;
    const list = document.getElementById('metrics-list');
    if (result.metrics.length === 0) {
        list.innerHTML = '<div class="empty-state">No hay métricas configurables todavía.</div>';
        return;
    }
    list.innerHTML = result.metrics.map(m => `
        <div class="dataset-row">
            <div>
                <div class="dataset-name">${escapeHtml(m.nombre)}</div>
                <div class="dataset-meta">${m.formula.operation}(${m.formula.columns.join(', ')})</div>
            </div>
            <button class="btn-icon" data-id="${m.id}">Eliminar</button>
        </div>
    `).join('');
    list.querySelectorAll('button[data-id]').forEach(btn => {
        btn.addEventListener('click', async () => {
            await Api.del(`../api/metrics.php?id=${btn.dataset.id}`);
            refreshMetricsList();
        });
    });
}

async function createMetric() {
    const formData = new FormData();
    formData.append('view_id', ACTIVE_VIEW_ID);
    formData.append('dataset_id', document.getElementById('metric-dataset').value);
    formData.append('nombre', document.getElementById('metric-nombre').value);
    formData.append('operation', document.getElementById('metric-operation').value);
    document.querySelectorAll('.metric-col').forEach(sel => formData.append('columns[]', sel.value));

    try {
        await Api.postForm('../api/metrics.php', formData);
        document.getElementById('metric-nombre').value = '';
        await refreshMetricsList();
    } catch (err) {
        alert(err.message);
    }
}

// ---------- Filters modal ----------

const DIM_LABELS = { __familia: 'Familia', __sub_familia: 'Sub-familia', __player_nombre: 'Jugador', __split: 'Parte de la sesión' };

function setupFiltersModal() {
    const modal = document.getElementById('filters-modal');
    document.getElementById('filters-btn')?.addEventListener('click', async () => {
        await refreshFiltersList();
        updateFilterValueList();
        modal.classList.remove('hidden');
    });
    document.getElementById('filters-modal-close').addEventListener('click', () => modal.classList.add('hidden'));
    document.getElementById('filter-dimension').addEventListener('change', updateFilterValueList);
    document.getElementById('filter-create-btn').addEventListener('click', createFilter);
}

/** Sugiere valores posibles (del plantel) para la dimensión elegida. */
function updateFilterValueList() {
    const dim = document.getElementById('filter-dimension').value;
    const values = DIM_VALUES[dim] || [];
    document.getElementById('filter-value-list').innerHTML = values.map(v => `<option value="${escapeHtml(v)}">`).join('');
    const input = document.getElementById('filter-value');
    input.value = '';
    input.placeholder = values.length ? `Ej: ${values[0]}` : 'Valor';
}

async function refreshFiltersList() {
    const result = await Api.get(`../api/filters.php?view_id=${ACTIVE_VIEW_ID}`);
    const list = document.getElementById('filters-list');
    if (result.filters.length === 0) {
        list.innerHTML = '<div class="empty-state">No hay filtros globales en esta vista.</div>';
        return;
    }
    const opLabel = { eq: 'es', neq: 'no es' };
    list.innerHTML = result.filters.map(f => `
        <div class="dataset-row">
            <div>
                <div class="dataset-name">${escapeHtml(DIM_LABELS[f.column_name] || f.column_name)} ${escapeHtml(opLabel[f.config.operator] || f.config.operator)} <strong>${escapeHtml(String(f.config.value))}</strong></div>
                <div class="dataset-meta">Aplica a todos los widgets de la vista</div>
            </div>
            <button class="btn-icon" data-id="${f.id}">Eliminar</button>
        </div>`).join('');
    list.querySelectorAll('button[data-id]').forEach(btn => {
        btn.addEventListener('click', async () => {
            await Api.del(`../api/filters.php?id=${btn.dataset.id}`);
            refreshFiltersList();
            loadWidgets();
        });
    });
}

async function createFilter() {
    const value = document.getElementById('filter-value').value.trim();
    if (!value) { alert('Escribí un valor para el filtro.'); return; }
    const formData = new FormData();
    formData.append('view_id', ACTIVE_VIEW_ID);
    formData.append('column_name', document.getElementById('filter-dimension').value);
    formData.append('operator', document.getElementById('filter-operator').value);
    formData.append('value', value);

    try {
        await Api.postForm('../api/filters.php', formData);
        await refreshFiltersList();
        loadWidgets();
    } catch (err) {
        alert(err.message);
    }
}

// ---------- Teclado ----------

function setupKeyboard() {
    // Escape cierra el modal visible de más arriba (los menús de widget manejan su propio Escape).
    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        const open = Array.from(document.querySelectorAll('.modal-overlay:not(.hidden)'));
        if (!open.length) return;
        open[open.length - 1].classList.add('hidden');
    });

    // Enter en el nombre de la vista crea la vista (modal de un solo campo).
    document.getElementById('view-name-input')?.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); document.getElementById('view-create-btn')?.click(); }
    });
}

// Al abrir un modal, llevar el foco a su primer campo o botón (accesibilidad de teclado).
function focusModal(overlayId) {
    const overlay = document.getElementById(overlayId);
    if (!overlay) return;
    requestAnimationFrame(() => {
        const el = overlay.querySelector('input:not([type=hidden]), textarea, select, button.btn');
        el?.focus();
    });
}

// ---------- Init ----------

setupKeyboard();

// Crear/eliminar vistas: siempre disponible (los botones existen aunque no haya grilla de widgets).
setupViewModal();

// Barra de vistas en dos filas: desplegables por fila ("Jugadores ▾" y "+N ▾"), drag de tabs
// dentro de cada fila y cálculo de desborde. No-op si la barra no se renderizó (club sin vistas).
setupViewRail();

// Modal de vistas base: disponible tanto en el estado vacío como con vistas ya creadas.
setupBaseModal();

if (document.getElementById('widget-grid')) {
    setupWidgetModal();
    setupPromptModal();
    setupAiModal();
    setupMetricsModal();
    setupFiltersModal();
    // Antes de loadWidgets(): así el primer render ya sale con lo que la barra tenga seleccionado.
    wireQuickFilters();
    setupPrint();
    setupOverflowMenu('view-actions-btn', 'view-actions-menu');
    document.getElementById('generate-view-btn')?.addEventListener('click', runViewGeneration);
    loadWidgets();
}
