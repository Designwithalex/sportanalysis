<?php

/**
 * Libreria fija de widgets. La IA y el editor manual comparten estas mismas reglas:
 * ningun widget se guarda si su config no valida contra el schema del dataset.
 */
class WidgetSchema
{
    public const TYPES = ['kpi_card', 'table', 'line_chart', 'bar_chart', 'stacked_bar'];

    public const AGGREGATIONS = ['sum', 'avg', 'min', 'max', 'count'];

    /**
     * Columnas sintéticas que el WidgetRenderer inyecta en cada fila, disponibles en
     * cualquier widget además de las columnas propias del CSV. __dataset es el eje que
     * permite cruzar varios datasets (ej: "por partido" cuando el widget abarca varios).
     */
    public const SYNTHETIC_COLUMNS = [
        '__dataset' => 'categorica',
        '__familia' => 'categorica',
        '__sub_familia' => 'categorica',
        '__player_nombre' => 'texto',
    ];

    /**
     * Schema efectivo para un widget que abarca uno o varios datasets: intersección de las
     * columnas comunes a todos (una columna solo es válida si existe en todos los datasets
     * seleccionados) más las columnas sintéticas. Si el tipo difiere entre datasets, se degrada
     * a "texto" (solo admite count), que es lo seguro.
     *
     * @param array<int,array<string,string>> $datasetSchemas lista de column_schema (col -> tipo)
     * @return array<string,string>
     */
    public static function effectiveSchema(array $datasetSchemas): array
    {
        $datasetSchemas = array_values(array_filter($datasetSchemas, 'is_array'));
        if (empty($datasetSchemas)) {
            return self::SYNTHETIC_COLUMNS;
        }

        $common = $datasetSchemas[0];
        foreach (array_slice($datasetSchemas, 1) as $schema) {
            foreach ($common as $col => $type) {
                if (!array_key_exists($col, $schema)) {
                    unset($common[$col]);
                } elseif ($schema[$col] !== $type) {
                    $common[$col] = 'texto';
                }
            }
        }

        return self::SYNTHETIC_COLUMNS + $common;
    }

    /**
     * @param array<string,mixed> $config
     * @param array<string,string> $columnSchema nombre de columna -> tipo (numerica/fecha/categorica/texto)
     * @param array<int,array{id:int,nombre:string}> $customMetrics metricas configurables disponibles para el dataset de este widget
     * @return string[] lista de errores (vacia = valido)
     */
    public static function validate(string $type, array $config, array $columnSchema, array $customMetrics = []): array
    {
        if (!in_array($type, self::TYPES, true)) {
            return ["Tipo de widget desconocido: $type"];
        }

        $errors = [];
        $columns = array_keys($columnSchema);
        $metricIds = array_column($customMetrics, 'id');

        $checkMetricRef = function ($ref, string $label, ?string $aggregation = null) use (&$errors, $columns, $columnSchema, $metricIds) {
            if (!is_array($ref) || !isset($ref['source'])) {
                $errors[] = "$label: falta especificar la fuente (columna o metrica).";
                return;
            }
            if ($ref['source'] === 'column') {
                if (!in_array($ref['column'] ?? null, $columns, true)) {
                    $errors[] = "$label: la columna \"" . ($ref['column'] ?? '') . "\" no existe en el dataset.";
                } elseif (($columnSchema[$ref['column']] ?? null) !== 'numerica' && ($aggregation ?? null) !== 'count') {
                    $errors[] = "$label: la columna \"{$ref['column']}\" no es numérica, solo admite \"count\".";
                }
            } elseif ($ref['source'] === 'custom_metric') {
                if (!in_array((int) ($ref['metric_id'] ?? 0), $metricIds, true)) {
                    $errors[] = "$label: la métrica configurable indicada no existe en esta vista.";
                }
            } else {
                $errors[] = "$label: fuente inválida \"{$ref['source']}\".";
            }
        };

        $checkColumn = function (?string $column, string $label, ?string $expectedType = null) use (&$errors, $columns, $columnSchema) {
            if ($column === null || !in_array($column, $columns, true)) {
                $errors[] = "$label: la columna \"$column\" no existe en el dataset.";
                return;
            }
            if ($expectedType !== null && $columnSchema[$column] !== $expectedType) {
                $errors[] = "$label: la columna \"$column\" debería ser de tipo $expectedType.";
            }
        };

        $checkAggregation = function (?string $agg, string $label) use (&$errors) {
            if (!in_array($agg, self::AGGREGATIONS, true)) {
                $errors[] = "$label: agregación inválida \"$agg\".";
            }
        };

        switch ($type) {
            case 'kpi_card':
                $checkMetricRef($config['metric'] ?? null, 'metric', $config['aggregation'] ?? null);
                $checkAggregation($config['aggregation'] ?? null, 'aggregation');
                break;

            case 'table':
                if (empty($config['columns']) || !is_array($config['columns'])) {
                    $errors[] = 'La tabla necesita al menos una columna.';
                    break;
                }
                $grain = $config['row_grain'] ?? 'player_session';
                if (!in_array($grain, ['player', 'player_session'], true) && !in_array($grain, $columns, true)) {
                    $errors[] = "row_grain: \"$grain\" no es válido (usá player, player_session, o una columna existente para agrupar).";
                }
                foreach ($config['columns'] as $i => $col) {
                    // "text" = columna de dimensión: se muestra el valor tal cual (ej: sub-familia,
                    // posición, partido), sin agregación numérica. Admite cualquier tipo de columna.
                    if (($col['aggregation'] ?? null) === 'text') {
                        if (($col['source'] ?? 'column') !== 'column') {
                            $errors[] = "columna #$i: una columna de texto debe ser una columna directa del dataset.";
                        } else {
                            $checkColumn($col['column'] ?? null, "columna #$i");
                        }
                    } else {
                        $checkMetricRef($col, "columna #$i", $col['aggregation'] ?? null);
                    }
                }
                if (!empty($config['conditional_rules']) && count($config['conditional_rules']) > 3) {
                    $errors[] = 'Máximo 3 reglas de formato condicional por tabla.';
                }
                foreach (($config['conditional_rules'] ?? []) as $rule) {
                    if (!in_array($rule['color'] ?? null, ['moss', 'amber', 'clay'], true)) {
                        $errors[] = 'Color de regla condicional inválido, debe ser moss/amber/clay.';
                    }
                }
                break;

            case 'line_chart':
                if (empty($config['y_metrics']) || !is_array($config['y_metrics'])) {
                    $errors[] = 'El gráfico de línea necesita al menos una métrica en el eje Y.';
                    break;
                }
                foreach ($config['y_metrics'] as $i => $m) {
                    $checkMetricRef($m, "métrica Y #$i", $config['aggregation'] ?? null);
                }
                $checkColumn($config['x_column'] ?? null, 'x_column');
                if (!empty($config['group_by'])) {
                    $checkColumn($config['group_by'], 'group_by', 'categorica');
                }
                break;

            case 'bar_chart':
                $checkMetricRef($config['metric'] ?? null, 'metric', $config['aggregation'] ?? null);
                $checkColumn($config['category_column'] ?? null, 'category_column');
                if (!in_array($config['orientation'] ?? 'vertical', ['vertical', 'horizontal'], true)) {
                    $errors[] = 'orientation debe ser vertical u horizontal.';
                }
                break;

            case 'stacked_bar':
                $checkMetricRef($config['base_metric'] ?? null, 'base_metric');
                $checkColumn($config['segment_column'] ?? null, 'segment_column', 'categorica');
                $checkColumn($config['category_column'] ?? null, 'category_column');
                if (!in_array($config['mode'] ?? 'absolute', ['absolute', 'percent'], true)) {
                    $errors[] = 'mode debe ser absolute o percent.';
                }
                break;
        }

        return $errors;
    }
}
