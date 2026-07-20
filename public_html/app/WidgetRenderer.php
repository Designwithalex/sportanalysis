<?php

/**
 * Unico lugar que convierte config JSON (generado por IA o por el editor manual) en
 * HTML + datos para Chart.js. Ni la IA ni el editor tocan HTML directamente.
 */
class WidgetRenderer
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * @param array{id:int,type:string,config:array,dataset_id:int} $widget
     * @param array<int,array{column:string,operator:string,value:mixed}> $viewFilters filtros a nivel vista+dataset, se aplican ademas del filtro propio del widget
     * @return array{html:string, chart_type:?string, chart_data:?array, excluded_count:int, scale_base:?float}
     */
    public function render(array $widget, array $viewFilters = []): array
    {
        $type = $widget['type'];
        $config = $widget['config'];
        $datasetIds = self::datasetIds($config);

        [$rows, $excludedCount] = $this->loadRows($datasetIds);
        $customMetrics = $this->loadCustomMetricsById($datasetIds);

        foreach ($viewFilters as $filter) {
            $rows = $this->applyFilter($rows, $filter);
        }
        if (!empty($config['filter']['column'])) {
            $rows = $this->applyFilter($rows, $config['filter']);
        }

        switch ($type) {
            case 'kpi_card':
                $result = $this->renderKpiCard($config, $rows, $customMetrics);
                break;
            case 'table':
                $result = $this->renderTable($config, $rows, $customMetrics);
                break;
            case 'line_chart':
                $result = $this->renderLineChart($config, $rows, $customMetrics);
                break;
            case 'bar_chart':
                $result = $this->renderBarChart($config, $rows, $customMetrics);
                break;
            case 'stacked_bar':
                $result = $this->renderStackedBar($config, $rows, $customMetrics);
                break;
            default:
                $result = ['html' => '<div class="alert alert-error">Tipo de widget desconocido.</div>', 'chart_type' => null, 'chart_data' => null, 'scale_base' => null];
        }

        $result['excluded_count'] = $excludedCount;
        return $result;
    }

    /**
     * Normaliza el target de datasets de un widget. Acepta el formato nuevo (dataset_ids: array)
     * y el viejo (dataset_id: int) para retrocompatibilidad con widgets ya guardados.
     * @return int[]
     */
    public static function datasetIds(array $config): array
    {
        if (!empty($config['dataset_ids']) && is_array($config['dataset_ids'])) {
            return array_values(array_unique(array_map('intval', $config['dataset_ids'])));
        }
        if (isset($config['dataset_id'])) {
            return [(int) $config['dataset_id']];
        }
        return [];
    }

    /**
     * Carga las filas matcheadas de uno o varios datasets, inyectando columnas sintéticas.
     * __dataset (nombre del dataset de origen) es la que permite "cruzar partidos": al abarcar
     * varios datasets, agrupar por __dataset = agrupar por partido/sesión.
     * @param int[] $datasetIds
     * @return array{0: array<int,array<string,mixed>>, 1:int} [rows, excluded_count]
     */
    private function loadRows(array $datasetIds): array
    {
        if (empty($datasetIds)) {
            return [[], 0];
        }
        $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));

        $stmt = $this->pdo->prepare(
            "SELECT r.raw_data, r.player_id, d.nombre AS dataset_nombre,
                    p.nombre AS player_nombre, p.familia, p.sub_familia
             FROM dataset_rows r
             INNER JOIN datasets d ON d.id = r.dataset_id
             LEFT JOIN players p ON p.id = r.player_id
             WHERE r.dataset_id IN ($placeholders) AND r.match_status = 'matched'"
        );
        $stmt->execute($datasetIds);
        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $data = json_decode($row['raw_data'], true) ?? [];
            $data['__player_id'] = $row['player_id'];
            $data['__player_nombre'] = $row['player_nombre'];
            $data['__familia'] = $row['familia'];
            $data['__sub_familia'] = $row['sub_familia'];
            $data['__dataset'] = $row['dataset_nombre'];
            $rows[] = $data;
        }

        $excludedStmt = $this->pdo->prepare(
            "SELECT COUNT(*) FROM dataset_rows WHERE dataset_id IN ($placeholders) AND match_status != 'matched'"
        );
        $excludedStmt->execute($datasetIds);
        $excluded = (int) $excludedStmt->fetchColumn();

        return [$rows, $excluded];
    }

    /**
     * @param int[] $datasetIds
     * @return array<int,array{id:int,formula:array}>
     */
    private function loadCustomMetricsById(array $datasetIds): array
    {
        if (empty($datasetIds)) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($datasetIds), '?'));
        $stmt = $this->pdo->prepare("SELECT id, formula FROM custom_metrics WHERE dataset_id IN ($placeholders)");
        $stmt->execute($datasetIds);
        $byId = [];
        foreach ($stmt->fetchAll() as $m) {
            $byId[(int) $m['id']] = json_decode($m['formula'], true);
        }
        return $byId;
    }

    /** @param array<int,array<string,mixed>> $rows */
    private function applyFilter(array $rows, array $filter): array
    {
        $column = $filter['column'];
        $operator = $filter['operator'] ?? 'eq';
        $value = $filter['value'] ?? null;

        return array_values(array_filter($rows, function ($row) use ($column, $operator, $value) {
            $cell = $row[$column] ?? null;
            return self::compare($cell, $operator, $value);
        }));
    }

    private static function compare($cell, string $operator, $value): bool
    {
        $numCell = is_numeric($cell) ? (float) str_replace(',', '.', (string) $cell) : null;
        $numValue = is_numeric($value) ? (float) $value : null;

        return match ($operator) {
            'eq' => (string) $cell === (string) $value,
            'neq' => (string) $cell !== (string) $value,
            'gt' => $numCell !== null && $numValue !== null && $numCell > $numValue,
            'gte' => $numCell !== null && $numValue !== null && $numCell >= $numValue,
            'lt' => $numCell !== null && $numValue !== null && $numCell < $numValue,
            'lte' => $numCell !== null && $numValue !== null && $numCell <= $numValue,
            default => true,
        };
    }

    /** Extrae el valor numerico de una fila para un metric ref (columna directa o metrica configurable). */
    private static function extractValue(array $ref, array $row, array $customMetricsById): ?float
    {
        if ($ref['source'] === 'column') {
            $raw = $row[$ref['column']] ?? null;
            if ($raw === null || $raw === '') {
                return null;
            }
            if (($ref['aggregation'] ?? null) === 'count') {
                return 1.0;
            }
            return is_numeric(str_replace(',', '.', (string) $raw)) ? (float) str_replace(',', '.', (string) $raw) : null;
        }

        if ($ref['source'] === 'custom_metric') {
            $formula = $customMetricsById[(int) $ref['metric_id']] ?? null;
            if ($formula === null) {
                return null;
            }
            return self::evaluateFormula($formula, $row);
        }

        return null;
    }

    private static function evaluateFormula(array $formula, array $row): ?float
    {
        $operation = $formula['operation'];
        $values = [];
        foreach ($formula['columns'] as $col) {
            $raw = $row[$col] ?? null;
            if ($raw === null || $raw === '' || !is_numeric(str_replace(',', '.', (string) $raw))) {
                return null;
            }
            $values[] = (float) str_replace(',', '.', (string) $raw);
        }
        if (empty($values)) {
            return null;
        }

        return match ($operation) {
            'sum' => array_sum($values),
            'multiply' => array_product($values),
            'subtract' => $values[0] - ($values[1] ?? 0),
            'divide' => (isset($values[1]) && $values[1] != 0) ? $values[0] / $values[1] : null,
            'ratio' => (isset($values[1]) && $values[1] != 0) ? round(($values[0] / $values[1]) * 100, 2) : null,
            default => null,
        };
    }

    private static function aggregate(array $values, string $aggregation): ?float
    {
        $values = array_values(array_filter($values, fn($v) => $v !== null));
        if (empty($values)) {
            return null;
        }
        return match ($aggregation) {
            'sum' => array_sum($values),
            'avg' => array_sum($values) / count($values),
            'min' => min($values),
            'max' => max($values),
            'count' => (float) count($values),
            default => null,
        };
    }

    private function renderKpiCard(array $config, array $rows, array $customMetrics): array
    {
        $values = array_map(fn($row) => self::extractValue($config['metric'], $row, $customMetrics), $rows);
        $value = self::aggregate($values, $config['aggregation']);

        $decimals = $config['number_format']['decimals'] ?? 1;
        $unit = $config['number_format']['unit'] ?? '';
        $displayValue = $value !== null ? number_format($value, $decimals, ',', '.') : 's/d';

        $comparisonHtml = '';
        if (!empty($config['comparison']['enabled']) && $value !== null) {
            $ref = (float) ($config['comparison']['reference_value'] ?? 0);
            $label = htmlspecialchars($config['comparison']['label'] ?? 'vs referencia');
            $delta = $value - $ref;
            $cls = $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'warn');
            $sign = $delta > 0 ? '+' : '';
            $comparisonHtml = '<span class="kpi-delta ' . $cls . '">' . $sign . number_format($delta, $decimals, ',', '.') . ' ' . $label . '</span>';
        }

        $scaleHtml = '';
        if (!empty($config['scale_selector'])) {
            $scaleHtml = self::scaleSelectorHtml();
        }

        $html = '<div class="kpi-label">' . htmlspecialchars($config['title'] ?? '') . '</div>'
            . '<div class="kpi-value" data-scale-base="' . htmlspecialchars((string) ($value ?? 0)) . '" data-decimals="' . (int) $decimals . '">'
            . '<span class="scale-target">' . htmlspecialchars($displayValue) . '</span><span class="kpi-unit">' . htmlspecialchars($unit) . '</span></div>'
            . $comparisonHtml . $scaleHtml;

        return ['html' => $html, 'chart_type' => null, 'chart_data' => null, 'scale_base' => $value];
    }

    private function renderTable(array $config, array $rows, array $customMetrics): array
    {
        $columns = $config['columns'];
        $grain = $config['row_grain'] ?? 'player_session';

        // Encabezado de la primera columna según cómo se agrupan las filas.
        $grainHeaders = [
            'player' => 'Jugador',
            'player_session' => 'Jugador',
            '__player_nombre' => 'Jugador',
            '__familia' => 'Familia',
            '__sub_familia' => 'Sub-familia',
            '__dataset' => 'Partido',
        ];
        $labelHeader = $grainHeaders[$grain] ?? $grain;

        if ($grain === 'player_session') {
            // Sin agrupar: una fila por fila de origen.
            $computed = [];
            foreach ($rows as $row) {
                $entry = ['__label' => $row['__player_nombre'] ?? '(sin asignar)'];
                foreach ($columns as $col) {
                    if (($col['aggregation'] ?? '') === 'text') {
                        $raw = $row[$col['column'] ?? ''] ?? null;
                        $entry[$col['label']] = ($raw === null || $raw === '') ? null : (string) $raw;
                    } else {
                        $entry[$col['label']] = self::extractValue($col, $row, $customMetrics);
                    }
                }
                $computed[] = $entry;
            }
        } else {
            // Agrupar por jugador (preset) o por cualquier columna/dimensión elegida.
            $keyCol = $grain === 'player' ? '__player_id' : $grain;
            $labelCol = $grain === 'player' ? '__player_nombre' : $grain;

            $groups = [];
            foreach ($rows as $row) {
                $key = (string) ($row[$keyCol] ?? 'N/D');
                if (!isset($groups[$key])) {
                    $groups[$key] = ['__label' => (string) ($row[$labelCol] ?? '(sin asignar)'), '__rows' => []];
                }
                $groups[$key]['__rows'][] = $row;
            }

            $computed = [];
            foreach ($groups as $group) {
                $entry = ['__label' => $group['__label']];
                foreach ($columns as $col) {
                    if (($col['aggregation'] ?? '') === 'text') {
                        $entry[$col['label']] = self::firstText($group['__rows'], $col['column'] ?? '');
                    } else {
                        $vals = array_map(fn($r) => self::extractValue($col, $r, $customMetrics), $group['__rows']);
                        $entry[$col['label']] = self::aggregate($vals, $col['aggregation'] ?? 'avg');
                    }
                }
                $computed[] = $entry;
            }
        }

        // Etiquetas de columnas de texto: se muestran tal cual, sin formato numérico ni escala.
        $textLabels = [];
        foreach ($columns as $col) {
            if (($col['aggregation'] ?? '') === 'text') {
                $textLabels[$col['label']] = true;
            }
        }

        if (!empty($config['default_sort']['column'])) {
            $sortCol = $config['default_sort']['column'];
            $dir = $config['default_sort']['direction'] ?? 'desc';
            usort($computed, function ($a, $b) use ($sortCol, $dir) {
                $va = $a[$sortCol] ?? 0;
                $vb = $b[$sortCol] ?? 0;
                return $dir === 'asc' ? $va <=> $vb : $vb <=> $va;
            });
        }

        $rules = $config['conditional_rules'] ?? [];

        $searchAttr = !empty($config['search_enabled']) ? ' data-searchable="1"' : '';
        $html = '<div class="table-scroll"><table class="data-table"' . $searchAttr . '><thead><tr><th>' . htmlspecialchars($labelHeader) . '</th>';
        foreach ($columns as $col) {
            $html .= '<th>' . htmlspecialchars($col['label']) . '</th>';
        }
        $html .= '</tr></thead><tbody>';

        foreach ($computed as $entry) {
            $html .= '<tr><td>' . htmlspecialchars($entry['__label']) . '</td>';
            foreach ($columns as $col) {
                $val = $entry[$col['label']] ?? null;

                // Columna de dimensión (texto): se muestra tal cual, sin número ni escala ni reglas.
                if (isset($textLabels[$col['label']])) {
                    $html .= '<td>' . htmlspecialchars($val !== null ? (string) $val : 's/d') . '</td>';
                    continue;
                }

                $displayVal = $val !== null ? number_format($val, 1, ',', '.') : 's/d';
                $cellClass = self::matchConditionalRule($rules, $col['label'], $val);
                $scaleAttr = !empty($config['scale_selector']) && $val !== null
                    ? ' data-scale-base="' . htmlspecialchars((string) $val) . '"'
                    : '';
                if ($cellClass) {
                    $html .= '<td class="num"' . $scaleAttr . '><span class="cell-flag ' . $cellClass . ' scale-target">' . htmlspecialchars($displayVal) . '</span></td>';
                } else {
                    $html .= '<td class="num scale-target"' . $scaleAttr . '>' . htmlspecialchars($displayVal) . '</td>';
                }
            }
            $html .= '</tr>';
        }
        $html .= '</tbody></table></div>';

        if (!empty($config['search_enabled'])) {
            $html = '<input type="text" class="table-search-input" placeholder="Buscar...">' . $html;
        }
        if (!empty($config['scale_selector'])) {
            $html .= self::scaleSelectorHtml();
        }

        return ['html' => $html, 'chart_type' => null, 'chart_data' => null, 'scale_base' => null];
    }

    /** Primer valor no vacío de una columna en un grupo de filas (para columnas de dimensión/texto). */
    private static function firstText(array $rows, string $column): ?string
    {
        foreach ($rows as $r) {
            $v = $r[$column] ?? null;
            if ($v !== null && $v !== '') {
                return (string) $v;
            }
        }
        return null;
    }

    private static function matchConditionalRule(array $rules, string $columnLabel, ?float $value): ?string
    {
        if ($value === null) {
            return null;
        }
        foreach ($rules as $rule) {
            if ($rule['column'] !== $columnLabel) {
                continue;
            }
            $ok = match ($rule['operator']) {
                'gt' => $value > $rule['value'],
                'gte' => $value >= $rule['value'],
                'lt' => $value < $rule['value'],
                'lte' => $value <= $rule['value'],
                'eq' => $value == $rule['value'],
                'between' => $value >= $rule['value'][0] && $value <= $rule['value'][1],
                default => false,
            };
            if ($ok) {
                return $rule['color'];
            }
        }
        return null;
    }

    private function renderLineChart(array $config, array $rows, array $customMetrics): array
    {
        $xColumn = $config['x_column'];
        $groupBy = $config['group_by'] ?? null;
        $aggregation = $config['aggregation'] ?? 'avg';

        $buckets = []; // [xValue][seriesKey][] = value

        foreach ($rows as $row) {
            $x = $row[$xColumn] ?? null;
            if ($x === null || $x === '') {
                continue;
            }
            $series = $groupBy ? ($row[$groupBy] ?? 'N/D') : ($config['y_metrics'][0]['label'] ?? 'Serie');

            if ($groupBy) {
                $value = self::extractValue($config['y_metrics'][0], $row, $customMetrics);
                $buckets[$x][$series][] = $value;
            } else {
                foreach ($config['y_metrics'] as $metric) {
                    $value = self::extractValue($metric, $row, $customMetrics);
                    $buckets[$x][$metric['label']][] = $value;
                }
            }
        }

        $labels = array_keys($buckets);
        natsort($labels);
        $labels = array_values($labels);

        $seriesNames = [];
        foreach ($buckets as $seriesMap) {
            foreach (array_keys($seriesMap) as $name) {
                $seriesNames[$name] = true;
            }
        }
        $seriesNames = array_slice(array_keys($seriesNames), 0, 6);

        $datasets = [];
        foreach ($seriesNames as $name) {
            $data = [];
            foreach ($labels as $x) {
                $data[] = self::aggregate($buckets[$x][$name] ?? [], $aggregation);
            }
            $datasets[] = ['label' => $name, 'data' => $data];
        }

        return [
            'html' => '<canvas></canvas>',
            'chart_type' => 'line',
            'chart_data' => ['labels' => $labels, 'datasets' => $datasets, 'style' => $config['style'] ?? 'line'],
            'scale_base' => null,
        ];
    }

    private function renderBarChart(array $config, array $rows, array $customMetrics): array
    {
        $categoryColumn = $config['category_column'];
        $aggregation = $config['aggregation'] ?? 'avg';

        $buckets = [];
        foreach ($rows as $row) {
            $cat = $row[$categoryColumn] ?? 'N/D';
            $buckets[$cat][] = self::extractValue($config['metric'], $row, $customMetrics);
        }

        $categories = array_keys($buckets);
        $values = [];
        foreach ($categories as $cat) {
            $values[$cat] = self::aggregate($buckets[$cat], $aggregation) ?? 0;
        }

        if (($config['order'] ?? 'alphabetical') === 'ranking') {
            arsort($values);
        } else {
            ksort($values);
        }

        $datasets = [['label' => $config['title'] ?? 'Valor', 'data' => array_values($values)]];

        if (!empty($config['reference_line']['value'])) {
            $refValue = (float) $config['reference_line']['value'];
            $datasets[] = [
                'label' => $config['reference_line']['label'] ?? 'Referencia',
                'data' => array_fill(0, count($values), $refValue),
                'type' => 'line',
            ];
        }

        return [
            'html' => '<canvas></canvas>',
            'chart_type' => ($config['orientation'] ?? 'vertical') === 'horizontal' ? 'horizontalBar' : 'bar',
            'chart_data' => ['labels' => array_keys($values), 'datasets' => $datasets],
            'scale_base' => null,
        ];
    }

    private function renderStackedBar(array $config, array $rows, array $customMetrics): array
    {
        $categoryColumn = $config['category_column'];
        $segmentColumn = $config['segment_column'];

        $buckets = []; // [category][segment] = [values]
        foreach ($rows as $row) {
            $cat = $row[$categoryColumn] ?? 'N/D';
            $seg = $row[$segmentColumn] ?? 'N/D';
            $buckets[$cat][$seg][] = self::extractValue($config['base_metric'], $row, $customMetrics);
        }

        $categories = array_keys($buckets);
        sort($categories);

        $segments = [];
        foreach ($buckets as $segMap) {
            foreach (array_keys($segMap) as $seg) {
                $segments[$seg] = true;
            }
        }
        $segments = array_slice(array_keys($segments), 0, 6);

        $datasets = [];
        foreach ($segments as $seg) {
            $data = [];
            foreach ($categories as $cat) {
                $data[] = self::aggregate($buckets[$cat][$seg] ?? [], 'sum') ?? 0;
            }
            $datasets[] = ['label' => $seg, 'data' => $data];
        }

        if (($config['mode'] ?? 'absolute') === 'percent') {
            foreach ($categories as $i => $cat) {
                $total = 0;
                foreach ($datasets as $ds) {
                    $total += $ds['data'][$i] ?? 0;
                }
                if ($total > 0) {
                    foreach ($datasets as &$ds) {
                        $ds['data'][$i] = round(($ds['data'][$i] / $total) * 100, 1);
                    }
                    unset($ds);
                }
            }
        }

        return [
            'html' => '<canvas></canvas>',
            'chart_type' => 'bar',
            'chart_data' => ['labels' => $categories, 'datasets' => $datasets, 'stacked' => true],
            'scale_base' => null,
        ];
    }

    private static function scaleSelectorHtml(): string
    {
        return '<div class="scale-selector">'
            . '<label>Escala:</label>'
            . '<select class="scale-select">'
            . '<option value="100" selected>100%</option>'
            . '<option value="25">25%</option>'
            . '<option value="50">50%</option>'
            . '<option value="75">75%</option>'
            . '<option value="125">125%</option>'
            . '<option value="custom">Personalizado...</option>'
            . '</select>'
            . '<input type="number" class="scale-custom-input" style="display:none;" placeholder="%">'
            . '</div>';
    }
}
