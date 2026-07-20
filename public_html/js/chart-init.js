const CHART_COLORS = ['#2e7a9e', '#3f7d55', '#b1741f', '#a8412e', '#7d8590', '#8ccae6'];

function initChart(canvas, chartType, chartData) {
    const datasets = chartData.datasets.map((ds, i) => {
        const color = CHART_COLORS[i % CHART_COLORS.length];
        const base = {
            label: ds.label,
            data: ds.data,
            borderColor: color,
            backgroundColor: ds.type === 'line' ? color : color + 'cc',
        };
        if (chartType === 'line' || ds.type === 'line') {
            base.fill = false;
            base.tension = 0.3;
            base.pointRadius = (chartData.style === 'line_markers') ? 3 : 0;
        }
        if (ds.type) base.type = ds.type;
        return base;
    });

    const isHorizontal = chartType === 'horizontalBar';

    // Eje de valores: en línea auto-escala al rango de los datos (beginAtZero:false) para que la
    // evolución se vea y no quede una línea aplastada entre 0 y el máximo. En barras se arranca en
    // 0 (empezar barras arriba de 0 exagera visualmente las diferencias). El "grace" deja aire
    // arriba y abajo, y maxTicksLimit fuerza varias divisiones intermedias.
    const valueAxisKey = isHorizontal ? 'x' : 'y';
    const zoomToData = chartType === 'line';

    const scales = {
        x: { stacked: !!chartData.stacked, grid: { display: isHorizontal } },
        y: { stacked: !!chartData.stacked, grid: { color: 'rgba(128,128,128,0.15)', display: !isHorizontal } },
    };
    scales[valueAxisKey] = {
        ...scales[valueAxisKey],
        beginAtZero: !zoomToData,
        grace: zoomToData ? '8%' : 0,
        ticks: { maxTicksLimit: 8 },
    };

    // Dibujado de entrada intencional, pero apagado si el usuario pidió menos movimiento.
    const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const animation = reduceMotion ? false : { duration: 600, easing: 'easeOutQuart' };

    return new Chart(canvas, {
        type: isHorizontal ? 'bar' : chartType,
        data: { labels: chartData.labels, datasets },
        options: {
            indexAxis: isHorizontal ? 'y' : 'x',
            responsive: true,
            maintainAspectRatio: false,
            animation,
            interaction: { mode: 'index', intersect: false },
            scales,
            plugins: {
                legend: { display: datasets.length > 1, labels: { boxWidth: 10, font: { size: 11 } } },
            },
        },
    });
}

function wireScaleSelectors(root) {
    root.querySelectorAll('.scale-selector').forEach((selector) => {
        const select = selector.querySelector('.scale-select');
        const customInput = selector.querySelector('.scale-custom-input');
        const container = selector.closest('.widget-body') || selector.parentElement;

        function apply() {
            let pct = select.value === 'custom' ? parseFloat(customInput.value || '100') : parseFloat(select.value);
            if (isNaN(pct)) pct = 100;
            container.querySelectorAll('[data-scale-base]').forEach((el) => {
                const base = parseFloat(el.dataset.scaleBase);
                const decimals = parseInt(el.dataset.decimals || '1', 10);
                const target = el.classList.contains('scale-target') ? el : el.querySelector('.scale-target');
                if (target && !isNaN(base)) {
                    target.textContent = (base * pct / 100).toLocaleString('es-AR', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });
                }
            });
        }

        select.addEventListener('change', () => {
            customInput.style.display = select.value === 'custom' ? '' : 'none';
            apply();
        });
        customInput.addEventListener('input', apply);
    });
}

function wireTableSearch(root) {
    root.querySelectorAll('table[data-searchable]').forEach((table) => {
        const input = table.parentElement.parentElement.querySelector('.table-search-input');
        if (!input) return;
        input.addEventListener('input', () => {
            const term = input.value.toLowerCase();
            table.querySelectorAll('tbody tr').forEach((tr) => {
                tr.style.display = tr.textContent.toLowerCase().includes(term) ? '' : 'none';
            });
        });
    });
}
