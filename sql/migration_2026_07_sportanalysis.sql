-- Migración: pivote a SportAnalysis (categorías de datos + widgets multi-dataset).
-- Correr una sola vez sobre una base existente (phpMyAdmin). Idempotente en lo posible.
-- Bases nuevas: ignorar este archivo, schema.sql ya incluye todo.

SET NAMES utf8mb4;

-- 1) Categoría de dataset (los 5 buckets del nuevo Paso Datos).
ALTER TABLE datasets
    ADD COLUMN categoria ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros')
        NOT NULL DEFAULT 'otros' AFTER nombre,
    ADD INDEX idx_datasets_categoria (categoria);

-- 2) Widgets: no requiere cambio de esquema. config.dataset_id (int) sigue funcionando por
--    retrocompatibilidad; los widgets nuevos guardan config.dataset_ids (array). El renderer y
--    el validador aceptan ambos formatos (WidgetRenderer::datasetIds()).

-- 3) Filtros de vista globales: dataset_id pasa a NULL-able. Los filtros de vista ahora son
--    globales sobre dimensiones universales (familia/sub_familia/jugador) y se guardan con
--    dataset_id NULL; aplican a todos los widgets. Los filtros por columna propia viven en el
--    config del widget (config.filter).
ALTER TABLE view_filters MODIFY dataset_id INT UNSIGNED NULL;
