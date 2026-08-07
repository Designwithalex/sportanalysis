/**
 * Planificación semanal: cuánto se le pide al plantel cada día, en % de un partido.
 *
 * TODO SE RENDERIZA ACÁ, con el JSON que devuelve api/planificacion.php ya calculado (objetivo,
 * realizado y color por línea y por métrica). El servidor manda solo el cascarón. Es a propósito:
 * la alternativa —renderizar las tablas en PHP y además re-renderizarlas en JS al editar un
 * porcentaje— serían dos implementaciones del mismo semáforo que se van separando.
 */

let planSemana = PLAN_SEMANA;
let planData = null;

const fmt = (n, dec = 0) =>
    n === null || n === undefined ? '—' : Number(n).toLocaleString('es-AR', { minimumFractionDigits: dec, maximumFractionDigits: dec });

const DIAS = ['Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];

/** 'YYYY-MM-DD' → Date en hora LOCAL. new Date('2026-06-01') la interpreta UTC y en Argentina cae un día antes. */
function aFecha(iso) {
    const [a, m, d] = iso.split('-').map(Number);
    return new Date(a, m - 1, d);
}

function nombreDia(iso) {
    const d = aFecha(iso);
    return `${DIAS[(d.getDay() + 6) % 7]} ${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}`;
}

function tituloSemana(iso) {
    const ini = aFecha(iso);
    const fin = new Date(ini);
    fin.setDate(fin.getDate() + 6);
    const mes = (d) => d.toLocaleDateString('es-AR', { month: 'long' });
    const mismoMes = ini.getMonth() === fin.getMonth();
    return mismoMes
        ? `Semana del ${ini.getDate()} al ${fin.getDate()} de ${mes(fin)}`
        : `Semana del ${ini.getDate()} de ${mes(ini)} al ${fin.getDate()} de ${mes(fin)}`;
}

/** Api.postForm espera un FormData; el token anti-CSRF lo agrega api.js por header. */
function formData(obj) {
    const fd = new FormData();
    Object.entries(obj).forEach(([k, v]) => fd.append(k, v));
    return fd;
}

function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str ?? '';
    return div.innerHTML;
}

function alerta(msg, tipo = 'error') {
    const box = document.getElementById('plan-alert');
    box.innerHTML = msg ? `<div class="alert alert-${tipo}">${escapeHtml(msg)}</div>` : '';
}

// ---------- Carga ----------

async function cargar() {
    try {
        planData = await Api.get(`../api/planificacion.php?semana=${planSemana}`);
    } catch (err) {
        alerta(err.message);
        return;
    }

    alerta('');
    planSemana = planData.semana;

    document.getElementById('plan-titulo').textContent = tituloSemana(planSemana);
    document.getElementById('print-title').textContent = tituloSemana(planSemana);
    document.getElementById('plan-tol').textContent = planData.tolerancia;

    // El link lleva la semana: así se puede compartir y el botón atrás del navegador funciona.
    history.replaceState(null, '', `?semana=${planSemana}`);

    renderResumen();
    renderMetricas();
    renderDias();
    renderSemana();
}

function renderResumen() {
    const box = document.getElementById('plan-resumen');
    box.hidden = false;
    document.getElementById('plan-suma').textContent = planData.suma_porcentaje;
    document.getElementById('plan-equiv').textContent = fmt(planData.equivalente_partidos, 1);
}

// ---------- Métricas ----------

function renderMetricas() {
    const cont = document.getElementById('plan-metricas');
    if (!planData.metricas.length) {
        cont.innerHTML = '<div class="empty-state">Sin métricas: agregá al menos una para ver objetivos.</div>';
        return;
    }
    cont.innerHTML = planData.metricas.map((m) => `
        <span class="plan-chip">
            ${escapeHtml(m.label)}
            ${PLAN_EDITABLE ? `<button type="button" class="plan-chip-x" data-col="${escapeHtml(m.columna)}" aria-label="Quitar ${escapeHtml(m.label)}">&times;</button>` : ''}
        </span>
    `).join('');

    cont.querySelectorAll('.plan-chip-x').forEach((b) => {
        b.addEventListener('click', () => guardarMetricas(planData.metricas.map((m) => m.columna).filter((c) => c !== b.dataset.col)));
    });
}

async function guardarMetricas(columnas) {
    if (!columnas.length) {
        alerta('Dejá al menos una métrica: sin ninguna no hay objetivo que comparar.');
        return;
    }
    try {
        await Api.postForm('../api/planificacion.php', formData({
            action: 'metricas', semana: planSemana, columnas: JSON.stringify(columnas),
        }));
        await cargar();
    } catch (err) {
        alerta(err.message);
    }
}

// ---------- Días ----------

function celdaSemaforo(c, unidad) {
    // 'sd' (sin dato) no se pinta: un día sin entrenamiento cargado no está ni bien ni mal, y
    // pintarlo de amarillo diría que faltó carga cuando lo que falta es el archivo.
    const clase = c.estado === 'sd' ? '' : ` sem-${c.estado}`;
    const real = c.realizado === null ? '—' : fmt(c.realizado);
    return `
        <td class="plan-obj">${c.objetivo === null ? '—' : fmt(c.objetivo)}<span class="plan-un">${escapeHtml(unidad || '')}</span></td>
        <td class="plan-real${clase}">${real}</td>
    `;
}

function renderDias() {
    const cont = document.getElementById('plan-dias');
    const conCarga = planData.dias.filter((d) => d.porcentaje > 0);

    cont.innerHTML = planData.dias.map((d) => {
        const editable = PLAN_EDITABLE;
        const vacio = d.porcentaje === 0;

        // Los días sin carga se muestran igual pero colapsados: son los días libres de la semana y
        // esconderlos haría imposible planificar uno nuevo.
        const cabecera = `
            <div class="plan-dia-head">
                <div class="plan-dia-nombre">${nombreDia(d.fecha)}</div>
                <div class="plan-dia-carga">
                    ${editable
                        ? `<input type="number" class="plan-pct" data-fecha="${d.fecha}" value="${d.porcentaje}" min="0" max="500" step="5" aria-label="Carga de ${nombreDia(d.fecha)} en porcentaje">`
                        : `<span class="plan-pct-ro">${d.porcentaje}</span>`}
                    <span class="plan-pct-sign">%</span>
                </div>
                ${editable
                    ? `<input type="text" class="plan-nota" data-fecha="${d.fecha}" value="${escapeHtml(d.nota)}" placeholder="Nota (ej: unidades + juego)" aria-label="Nota de ${nombreDia(d.fecha)}">`
                    : `<span class="plan-nota-ro">${escapeHtml(d.nota)}</span>`}
                ${vacio ? '<span class="plan-dia-libre">día libre</span>' : ''}
                ${!vacio && !d.tiene_datos ? '<span class="plan-dia-sindatos">sin sesión cargada</span>' : ''}
            </div>`;

        if (vacio) {
            return `<div class="card plan-dia plan-dia-vacio">${cabecera}</div>`;
        }

        return `<div class="card plan-dia">
            ${cabecera}
            <div class="table-scroll">
                <table class="data-table plan-tabla">
                    <thead>
                        <tr>
                            <th rowspan="2">Línea</th>
                            ${planData.metricas.map((m) => `<th colspan="2" class="plan-th-metrica">${escapeHtml(m.label)}</th>`).join('')}
                        </tr>
                        <tr>
                            ${planData.metricas.map(() => '<th class="plan-th-sub">objetivo</th><th class="plan-th-sub">realizado</th>').join('')}
                        </tr>
                    </thead>
                    <tbody>
                        ${d.lineas.map((l) => `
                            <tr>
                                <td>${escapeHtml(l.linea)}</td>
                                ${planData.metricas.map((m) => celdaSemaforo(l.celdas[m.columna], m.unidad)).join('')}
                            </tr>`).join('')}
                    </tbody>
                </table>
            </div>
        </div>`;
    }).join('');

    if (!PLAN_EDITABLE) return;

    cont.querySelectorAll('.plan-pct, .plan-nota').forEach((inp) => {
        // 'change' y no 'input': guardar en cada tecla mandaría una request por dígito.
        inp.addEventListener('change', () => guardarDia(inp.dataset.fecha));
    });
}

async function guardarDia(fecha) {
    const pct = document.querySelector(`.plan-pct[data-fecha="${fecha}"]`);
    const nota = document.querySelector(`.plan-nota[data-fecha="${fecha}"]`);
    try {
        await Api.postForm('../api/planificacion.php', formData({
            action: 'guardar_dia',
            fecha,
            porcentaje: pct ? pct.value : 0,
            nota: nota ? nota.value : '',
        }));
        await cargar();
    } catch (err) {
        alerta(err.message);
    }
}

// ---------- Semana consolidada ----------

function renderSemana() {
    const card = document.getElementById('plan-semana-card');
    const conCarga = planData.dias.filter((d) => d.porcentaje > 0);
    if (!conCarga.length) {
        card.hidden = true;
        return;
    }
    card.hidden = false;

    // Los totales se SUMAN sobre los días planificados, no se promedian: la pregunta de la semana
    // es cuánto acumuló el plantel, no cuánto hizo en un día típico.
    const filas = planData.lineas.map((linea) => {
        const celdas = planData.metricas.map((m) => {
            let obj = 0;
            let real = 0;
            let hayReal = false;
            conCarga.forEach((d) => {
                const c = d.lineas.find((l) => l.linea === linea)?.celdas[m.columna];
                if (!c) return;
                if (c.objetivo !== null) obj += c.objetivo;
                if (c.realizado !== null) { real += c.realizado; hayReal = true; }
            });
            const pct = obj > 0 && hayReal ? (real / obj) * 100 : null;
            const estado = pct === null ? 'sd'
                : pct < 100 - planData.tolerancia ? 'amarillo'
                : pct > 100 + planData.tolerancia ? 'rojo' : 'verde';
            return { obj, real: hayReal ? real : null, estado, unidad: m.unidad };
        });
        return { linea, celdas };
    });

    document.getElementById('plan-semana').innerHTML = `
        <div class="table-scroll">
            <table class="data-table plan-tabla">
                <thead>
                    <tr>
                        <th rowspan="2">Línea</th>
                        ${planData.metricas.map((m) => `<th colspan="2" class="plan-th-metrica">${escapeHtml(m.label)}</th>`).join('')}
                    </tr>
                    <tr>${planData.metricas.map(() => '<th class="plan-th-sub">objetivo</th><th class="plan-th-sub">realizado</th>').join('')}</tr>
                </thead>
                <tbody>
                    ${filas.map((f) => `
                        <tr>
                            <td>${escapeHtml(f.linea)}</td>
                            ${f.celdas.map((c) => `
                                <td class="plan-obj">${fmt(c.obj)}<span class="plan-un">${escapeHtml(c.unidad || '')}</span></td>
                                <td class="plan-real${c.estado === 'sd' ? '' : ` sem-${c.estado}`}">${c.real === null ? '—' : fmt(c.real)}</td>
                            `).join('')}
                        </tr>`).join('')}
                </tbody>
            </table>
        </div>`;
}

// ---------- Navegación e impresión ----------

function moverSemana(dias) {
    const d = aFecha(planSemana);
    d.setDate(d.getDate() + dias);
    planSemana = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
    cargar();
}

document.getElementById('plan-prev').addEventListener('click', () => moverSemana(-7));
document.getElementById('plan-next').addEventListener('click', () => moverSemana(7));
document.getElementById('plan-hoy').addEventListener('click', () => {
    const h = new Date();
    planSemana = `${h.getFullYear()}-${String(h.getMonth() + 1).padStart(2, '0')}-${String(h.getDate()).padStart(2, '0')}`;
    cargar();
});

document.getElementById('plan-pdf').addEventListener('click', () => window.print());

window.addEventListener('beforeprint', () => {
    const meta = document.querySelector('#print-header .print-meta');
    if (!meta || !planData) return;
    meta.textContent = `${PLAN_CLUB}  ·  ${planData.suma_porcentaje}% de carga (${fmt(planData.equivalente_partidos, 1)} partidos)  ·  emitido ${new Date().toLocaleDateString('es-AR')}`;
});

const addBtn = document.getElementById('plan-metrica-add');
if (addBtn) {
    addBtn.addEventListener('click', () => {
        const col = document.getElementById('plan-metrica-select').value;
        const actuales = planData.metricas.map((m) => m.columna);
        if (actuales.includes(col)) {
            alerta('Esa métrica ya está en el plan.', 'info');
            return;
        }
        guardarMetricas([...actuales, col]);
    });
}

cargar();
