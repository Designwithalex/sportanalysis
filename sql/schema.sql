-- PerformanceLab — esquema MySQL/MariaDB
-- Correr una sola vez contra la base de Hostinger (phpMyAdmin o cliente MySQL).
-- No se sube a public_html; es solo referencia/setup.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- players — plantel (Paso 1). Tabla maestra: todo dato se vincula por nombre.
-- ---------------------------------------------------------------------------
CREATE TABLE players (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(150) NOT NULL,
    familia       ENUM('back', 'forward') NOT NULL,
    sub_familia   VARCHAR(100) NULL,
    metadata      JSON NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_players_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- datasets — cada CSV subido en el Paso 2, con nombre propio y schema detectado.
-- ---------------------------------------------------------------------------
CREATE TABLE datasets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre              VARCHAR(150) NOT NULL,
    categoria           ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros') NOT NULL DEFAULT 'otros'
                        COMMENT 'bucket del Paso Datos: cada partido/sesion se sube como su propio dataset dentro de una categoria',
    original_filename   VARCHAR(255) NULL,
    column_schema       JSON NOT NULL COMMENT 'nombre de columna -> tipo detectado (numerica/texto/fecha/categorica)',
    player_column_name  VARCHAR(150) NULL COMMENT 'columna del CSV identificada como nombre de jugador',
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_datasets_categoria (categoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- dataset_rows — filas crudas de cada dataset, sin transformar.
-- ---------------------------------------------------------------------------
CREATE TABLE dataset_rows (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dataset_id    INT UNSIGNED NOT NULL,
    player_id     INT UNSIGNED NULL,
    raw_name      VARCHAR(150) NULL COMMENT 'valor tal cual apareció en la columna de nombre del CSV',
    raw_data      JSON NOT NULL COMMENT 'fila completa como fue subida',
    match_status  ENUM('matched', 'unmatched', 'discarded') NOT NULL DEFAULT 'unmatched',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dataset_rows_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_rows_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_dataset_rows_dataset (dataset_id),
    INDEX idx_dataset_rows_player (player_id),
    INDEX idx_dataset_rows_match_status (match_status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- name_reconciliations — checkpoint del Paso 3.7, una fila por nombre sin matchear.
-- Se resuelve una vez por dataset (no por vista).
-- ---------------------------------------------------------------------------
CREATE TABLE name_reconciliations (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dataset_id            INT UNSIGNED NOT NULL,
    raw_name              VARCHAR(150) NOT NULL,
    suggested_player_id   INT UNSIGNED NULL COMMENT 'mejor candidato por fuzzy match, nunca se aplica solo',
    resolution            ENUM('pending', 'confirmed', 'manual', 'discarded') NOT NULL DEFAULT 'pending',
    resolved_player_id    INT UNSIGNED NULL COMMENT 'jugador finalmente asignado, sea por confirmacion o eleccion manual',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at           TIMESTAMP NULL,
    CONSTRAINT fk_reconciliations_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
    CONSTRAINT fk_reconciliations_suggested FOREIGN KEY (suggested_player_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_reconciliations_resolved FOREIGN KEY (resolved_player_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_reconciliations_dataset (dataset_id),
    INDEX idx_reconciliations_resolution (resolution)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- views — vistas creadas en el Paso 3 (nombre + descripción en lenguaje natural).
-- ---------------------------------------------------------------------------
CREATE TABLE views (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(150) NOT NULL,
    tipo          ENUM('manual', 'cluster', 'player') NOT NULL DEFAULT 'manual'
                  COMMENT 'manual = creada a mano; cluster = vista base de una categoria de datos; player = overview de un jugador',
    categoria     ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros') NULL
                  COMMENT 'solo en vistas cluster: de que categoria de datasets es la vista',
    player_id     INT UNSIGNED NULL COMMENT 'solo en vistas player: jugador cuyo overview muestra la vista',
    position      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'orden manual de las tabs (reordenables por arrastre)',
    description   TEXT NOT NULL COMMENT 'prompt original del usuario / intent de generacion',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_views_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    INDEX idx_views_tipo (tipo),
    INDEX idx_views_position (position)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- view_datasets — qué datasets aplican a cada vista (checkboxes del Paso 3).
-- ---------------------------------------------------------------------------
CREATE TABLE view_datasets (
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NOT NULL,
    PRIMARY KEY (view_id, dataset_id),
    CONSTRAINT fk_view_datasets_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE,
    CONSTRAINT fk_view_datasets_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
    INDEX idx_view_datasets_dataset (dataset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- widgets — grilla del Paso 4. type limitado a la libreria fija del brief.
-- ---------------------------------------------------------------------------
CREATE TABLE widgets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_id       INT UNSIGNED NOT NULL,
    type          ENUM('kpi_card', 'table', 'line_chart', 'bar_chart', 'stacked_bar') NOT NULL,
    config        JSON NOT NULL COMMENT 'unica fuente de verdad que el WidgetRenderer convierte a HTML + Chart.js. Incluye dataset_ids: [..] (uno o varios datasets para cruzar partidos); se acepta el viejo dataset_id por retrocompat',
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_widgets_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE,
    INDEX idx_widgets_view (view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- widget_versions — historial para el undo (ultimas 5-10 por widget, se poda en la app).
-- ---------------------------------------------------------------------------
CREATE TABLE widget_versions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    widget_id     INT UNSIGNED NOT NULL,
    config        JSON NOT NULL,
    source        ENUM('initial', 'manual', 'ai') NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_widget_versions_widget FOREIGN KEY (widget_id) REFERENCES widgets(id) ON DELETE CASCADE,
    INDEX idx_widget_versions_widget (widget_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- custom_metrics — formulas simples entre columnas numericas de un dataset,
-- disponibles para todos los widgets de la vista que usen ese dataset.
-- ---------------------------------------------------------------------------
CREATE TABLE custom_metrics (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    formula       JSON NOT NULL COMMENT '{ operacion, columnas: [...] }',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_custom_metrics_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE,
    CONSTRAINT fk_custom_metrics_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
    INDEX idx_custom_metrics_view (view_id),
    INDEX idx_custom_metrics_dataset (dataset_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- view_filters — filtros a nivel vista+dataset, disponibles para sus widgets.
-- ---------------------------------------------------------------------------
CREATE TABLE view_filters (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NULL COMMENT 'NULL = filtro global de la vista sobre una dimension universal (familia/sub_familia/jugador), aplica a TODOS los widgets sin importar su dataset',
    column_name   VARCHAR(150) NOT NULL,
    filter_type   VARCHAR(50) NOT NULL COMMENT 'rango, valores, fecha, etc.',
    config        JSON NULL COMMENT 'parametros propios del filtro (min/max, opciones, etc.)',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_view_filters_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE,
    CONSTRAINT fk_view_filters_dataset FOREIGN KEY (dataset_id) REFERENCES datasets(id) ON DELETE CASCADE,
    INDEX idx_view_filters_view (view_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
