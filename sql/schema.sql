-- PerformanceLab — esquema MySQL/MariaDB
-- Correr una sola vez contra la base de Hostinger (phpMyAdmin o cliente MySQL).
-- No se sube a public_html; es solo referencia/setup.
--
-- Estado: MULTI-CLUB + ROLES Y VISTAS POR USUARIO (equivale a base nueva +
-- migration_2026_07_multiclub_a + _b + migration_2026_07_roles_vistas ya
-- aplicadas). Una base creada con este archivo NO necesita correr ninguna de esas
-- migraciones; sí conviene correr seed_clubs_urba.sql para poblar el dropdown de
-- registro, y promover a mano el primer superadmin:
--     UPDATE users SET nivel = 'superadmin' WHERE email = '...';
-- (no hay ni debe haber un camino self-service para llegar a superadmin).
--
-- Regla de oro del modelo: TODA tabla del dominio lleva `club_id` NOT NULL, y las
-- relaciones entre ellas son FKs COMPUESTAS (fk_col, club_id) -> (id, club_id).
-- Eso hace estructuralmente imposible que una fila de un club cuelgue de una fila
-- de otro club, aunque haya un bug en el PHP. Las tres excepciones están marcadas
-- en su tabla (FKs a players con ON DELETE SET NULL: InnoDB no las admite compuestas).

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- clubs — un club = un tenant. Raíz de todo el aislamiento de datos.
-- El club id = 1 es GEBA (ver seed_clubs_urba.sql).
-- ---------------------------------------------------------------------------
CREATE TABLE clubs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(150) NOT NULL,
    slug          VARCHAR(120) NOT NULL COMMENT 'identificador estable apto para URL, minúsculas sin tildes',
    activo        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = no aparece en el dropdown de registro',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clubs_slug (slug),
    INDEX idx_clubs_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users — cuentas de acceso. Registro abierto: el usuario elige su club de un
-- dropdown con los clubes activos, y queda en `pending` hasta que lo aprueben.
--
-- DOS COLUMNAS QUE SE PARECEN Y NO SON LO MISMO:
--   `rol`   = etiqueta libre del perfil ("Preparador físico"). Decorativa.
--   `nivel` = permisos reales. Es lo ÚNICO que la app debe mirar para autorizar.
-- ---------------------------------------------------------------------------
CREATE TABLE users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id        INT UNSIGNED NOT NULL,
    email          VARCHAR(190) NOT NULL COMMENT '190 chars: cabe en un índice UNIQUE utf8mb4',
    password_hash  VARCHAR(255) NOT NULL COMMENT 'password_hash() de PHP, nunca texto plano',
    nombre         VARCHAR(150) NOT NULL,
    rol            VARCHAR(80) NULL COMMENT 'texto libre descriptivo (preparador físico, head coach, etc.), no controla permisos',
    nivel          ENUM('miembro', 'admin_club', 'superadmin') NOT NULL DEFAULT 'miembro'
                   COMMENT 'permisos reales; `rol` es la etiqueta libre del perfil y no gatea nada',
    -- El orden del ENUM replica el de producción a propósito ('active' primero): la migración
    -- appendea 'rechazado' al final en vez de reordenar, para no mover los índices con que
    -- MariaDB guarda los valores. Si acá lo reordenáramos, una base nueva y una migrada
    -- quedarían con índices distintos para el mismo valor.
    status         ENUM('active', 'pending', 'rechazado') NOT NULL DEFAULT 'pending'
                   COMMENT 'pending = alta creada, todavía sin aprobar; rechazado = alta denegada, no entra',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  TIMESTAMP NULL,
    CONSTRAINT fk_users_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_club (club_id),
    INDEX idx_users_club_status (club_id, status) COMMENT 'cola de altas pendientes de un club',
    INDEX idx_users_nivel (nivel)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- verificaciones — pedidos de verificación de identidad: el usuario dice "soy
-- socio de este club" y aporta pruebas blandas. Un admin_club (o el superadmin)
-- aprueba o rechaza con motivo. Se permiten varios pedidos por usuario
-- (rechazado -> vuelve a intentar), por eso no hay UNIQUE sobre user_id.
--
-- `foto_url` ES UN LINK, NUNCA UN ARCHIVO SUBIDO: aceptar uploads abriría un
-- vector de subida arbitraria dentro de public_html.
--
-- EXCEPCIÓN AL PATRÓN: las FKs son SIMPLES, no compuestas con club_id.
-- `revisada_por` es ON DELETE SET NULL (InnoDB no lo admite compuesto con una
-- columna NOT NULL, mismo caso que dataset_rows.player_id), y `user_id` no tiene
-- un UNIQUE (id, club_id) en users al que engancharse. Que `verificaciones.club_id`
-- coincida con `users.club_id` lo garantiza la app; verify_isolation.sql lo audita
-- (chequeo E03).
-- ---------------------------------------------------------------------------
CREATE TABLE verificaciones (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id         INT UNSIGNED NOT NULL COMMENT 'quién pide la verificación',
    club_id         INT UNSIGNED NOT NULL COMMENT 'club contra el que se verifica; debe coincidir con users.club_id',
    instagram       VARCHAR(120) NULL COMMENT 'usuario de instagram, con o sin @',
    numero_socio    VARCHAR(60) NULL COMMENT 'texto libre: hay clubes con nº alfanuméricos',
    foto_url        VARCHAR(500) NULL COMMENT 'LINK a una foto (carnet, perfil). Nunca un archivo subido al server',
    nota            TEXT NULL COMMENT 'comentario libre del solicitante',
    estado          ENUM('pendiente', 'aprobada', 'rechazada') NOT NULL DEFAULT 'pendiente',
    motivo_rechazo  TEXT NULL COMMENT 'sólo si estado = rechazada; se le muestra al solicitante',
    revisada_por    INT UNSIGNED NULL COMMENT 'admin_club o superadmin que resolvió el pedido',
    created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    revisada_at     TIMESTAMP NULL,
    CONSTRAINT fk_verificaciones_user    FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_verificaciones_club    FOREIGN KEY (club_id)      REFERENCES clubs(id),
    CONSTRAINT fk_verificaciones_revisor FOREIGN KEY (revisada_por) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_verificaciones_estado (estado) COMMENT 'cola global de pendientes (superadmin)',
    INDEX idx_verificaciones_club_estado (club_id, estado) COMMENT 'cola de pendientes de un club (admin_club)',
    -- UNIQUE: una solicitud por usuario. Sin esto, dos submits concurrentes desde
    -- verificacion.php (que hace SELECT-then-UPDATE-or-INSERT) crean dos filas y la cola
    -- del admin muestra dos tarjetas de la misma persona.
    UNIQUE KEY uq_verificaciones_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- players — plantel (Paso 1). Tabla maestra: todo dato se vincula por nombre.
-- ---------------------------------------------------------------------------
CREATE TABLE players (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    nombre        VARCHAR(150) NOT NULL,
    familia       ENUM('back', 'forward') NOT NULL,
    sub_familia   VARCHAR(100) NULL,
    metadata      JSON NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_players_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_players_id_club (id, club_id) COMMENT 'target de las FKs compuestas de views',
    INDEX idx_players_nombre (nombre),
    INDEX idx_players_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- datasets — cada CSV subido en el Paso 2, con nombre propio y schema detectado.
-- ---------------------------------------------------------------------------
CREATE TABLE datasets (
    id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id             INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    nombre              VARCHAR(150) NOT NULL,
    categoria           ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros') NOT NULL DEFAULT 'otros'
                        COMMENT 'bucket del Paso Datos: cada partido/sesion se sube como su propio dataset dentro de una categoria',
    original_filename   VARCHAR(255) NULL,
    column_schema       JSON NOT NULL COMMENT 'nombre de columna -> tipo detectado (numerica/texto/fecha/categorica)',
    player_column_name  VARCHAR(150) NULL COMMENT 'columna del CSV identificada como nombre de jugador',
    uploaded_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_datasets_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_datasets_id_club (id, club_id) COMMENT 'target de las FKs compuestas de las tablas hijas',
    INDEX idx_datasets_categoria (categoria),
    INDEX idx_datasets_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- dataset_rows — filas crudas de cada dataset, sin transformar.
--
-- EXCEPCIÓN AL PATRÓN: `fk_dataset_rows_player` queda como FK SIMPLE. InnoDB no
-- admite ON DELETE SET NULL si alguna columna del lado hijo es NOT NULL (tendría
-- que nulificar club_id), así que no puede ser compuesta. El scoping por club de
-- player_id lo garantiza la aplicación; verify_isolation.sql lo audita (chequeo A02).
-- ---------------------------------------------------------------------------
CREATE TABLE dataset_rows (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    dataset_id    INT UNSIGNED NOT NULL,
    player_id     INT UNSIGNED NULL,
    raw_name      VARCHAR(150) NULL COMMENT 'valor tal cual apareció en la columna de nombre del CSV',
    raw_data      JSON NOT NULL COMMENT 'fila completa como fue subida',
    match_status  ENUM('matched', 'unmatched', 'discarded') NOT NULL DEFAULT 'unmatched',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_dataset_rows_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_dataset_rows_dataset_club FOREIGN KEY (dataset_id, club_id) REFERENCES datasets(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_dataset_rows_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_dataset_rows_dataset (dataset_id),
    INDEX idx_dataset_rows_dataset_club (dataset_id, club_id),
    INDEX idx_dataset_rows_player (player_id),
    INDEX idx_dataset_rows_match_status (match_status),
    INDEX idx_dataset_rows_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- name_reconciliations — checkpoint del Paso 3.7, una fila por nombre sin matchear.
-- Se resuelve una vez por dataset (no por vista).
--
-- EXCEPCIÓN AL PATRÓN: las dos FKs a players son SIMPLES por el mismo motivo que
-- en dataset_rows (ON DELETE SET NULL). Auditadas en verify_isolation.sql (A04/A05).
-- ---------------------------------------------------------------------------
CREATE TABLE name_reconciliations (
    id                    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id               INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    dataset_id            INT UNSIGNED NOT NULL,
    raw_name              VARCHAR(150) NOT NULL,
    suggested_player_id   INT UNSIGNED NULL COMMENT 'mejor candidato por fuzzy match, nunca se aplica solo',
    resolution            ENUM('pending', 'confirmed', 'manual', 'discarded') NOT NULL DEFAULT 'pending',
    resolved_player_id    INT UNSIGNED NULL COMMENT 'jugador finalmente asignado, sea por confirmacion o eleccion manual',
    created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at           TIMESTAMP NULL,
    CONSTRAINT fk_reconciliations_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_reconciliations_dataset_club FOREIGN KEY (dataset_id, club_id) REFERENCES datasets(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_reconciliations_suggested FOREIGN KEY (suggested_player_id) REFERENCES players(id) ON DELETE SET NULL,
    CONSTRAINT fk_reconciliations_resolved FOREIGN KEY (resolved_player_id) REFERENCES players(id) ON DELETE SET NULL,
    INDEX idx_reconciliations_dataset (dataset_id),
    INDEX idx_reconciliations_dataset_club (dataset_id, club_id),
    INDEX idx_reconciliations_resolution (resolution),
    INDEX idx_reconciliations_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- views — vistas creadas en el Paso 3 (nombre + descripción en lenguaje natural).
-- `player_id` es NULLable (sólo lo usan las vistas tipo 'player'). Con semántica
-- MATCH SIMPLE de InnoDB, la FK compuesta no se evalúa cuando player_id es NULL,
-- así que las vistas manual/cluster se insertan sin restricción extra.
--
-- VISIBILIDAD (`user_id`):
--   NULL     -> vista BASE del club: la ven todos los usuarios del club.
--   NOT NULL -> vista PRIVADA: sólo la ve ese usuario.
-- Las vistas 'cluster' y 'player' las genera BaseViewGenerator para el club
-- entero, así que son SIEMPRE base (user_id NULL); sólo las 'manual' pueden ser
-- privadas. No se expresa con un CHECK para no clavar una decisión de producto;
-- se audita en verify_isolation.sql (chequeo E04).
--
-- `fk_views_user` es ON DELETE CASCADE, NO SET NULL, y la diferencia importa:
-- acá user_id NULL no significa "huérfana" sino "visible para todo el club", así
-- que SET NULL convertiría las vistas privadas de una cuenta borrada en vistas
-- públicas del club. Perder los datos de quien se fue es preferible a exponerlos.
-- ---------------------------------------------------------------------------
CREATE TABLE views (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    nombre        VARCHAR(150) NOT NULL,
    tipo          ENUM('manual', 'cluster', 'player') NOT NULL DEFAULT 'manual'
                  COMMENT 'manual = creada a mano; cluster = vista base de una categoria de datos; player = overview de un jugador',
    categoria     ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros') NULL
                  COMMENT 'solo en vistas cluster: de que categoria de datasets es la vista',
    player_id     INT UNSIGNED NULL COMMENT 'solo en vistas player: jugador cuyo overview muestra la vista',
    user_id       INT UNSIGNED NULL
                  COMMENT 'NULL = vista base del club (la ven todos); NOT NULL = privada de ese usuario',
    position      INT UNSIGNED NOT NULL DEFAULT 0
                  COMMENT 'orden por defecto de las tabs, a nivel club. El orden real por usuario vive en view_order',
    description   TEXT NOT NULL COMMENT 'prompt original del usuario / intent de generacion',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_views_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_views_player_club FOREIGN KEY (player_id, club_id) REFERENCES players(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY uq_views_id_club (id, club_id) COMMENT 'target de las FKs compuestas de las tablas hijas',
    INDEX idx_views_tipo (tipo),
    INDEX idx_views_position (position),
    INDEX idx_views_player_club (player_id, club_id),
    INDEX idx_views_user (user_id),
    INDEX idx_views_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- view_order — orden de las tabs POR USUARIO.
--
-- `views.position` es una sola columna compartida por todo el club: alcanzaba
-- cuando todas las vistas eran del club, pero con vistas privadas mezcladas en
-- las mismas tabs cada usuario ve un orden distinto y no entra en una columna.
-- `views.position` se conserva como orden por defecto para un usuario que nunca
-- reordenó nada (o sea: sin filas acá).
--
-- Sin club_id a propósito: es una tabla de PREFERENCIA de usuario, no de dominio.
-- El club sale del user y de la view, que ya lo llevan. La contra es que la base
-- no impide por sí sola una fila que cruce (usuario de un club, vista de otro):
-- se audita en verify_isolation.sql (chequeo E02).
-- ---------------------------------------------------------------------------
CREATE TABLE view_order (
    user_id     INT UNSIGNED NOT NULL,
    view_id     INT UNSIGNED NOT NULL,
    position    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT 'orden de la tab para ESE usuario; 0 = primera',
    PRIMARY KEY (user_id, view_id),
    CONSTRAINT fk_view_order_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_view_order_view FOREIGN KEY (view_id) REFERENCES views(id) ON DELETE CASCADE,
    INDEX idx_view_order_view (view_id) COMMENT 'lado hijo de fk_view_order_view',
    INDEX idx_view_order_user_pos (user_id, position) COMMENT 'ORDER BY position del render de tabs'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- view_datasets — qué datasets aplican a cada vista (checkboxes del Paso 3).
-- Doble FK compuesta: la vista y el dataset tienen que ser del mismo club.
-- ---------------------------------------------------------------------------
CREATE TABLE view_datasets (
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NOT NULL,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    PRIMARY KEY (view_id, dataset_id),
    CONSTRAINT fk_view_datasets_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_view_datasets_view_club FOREIGN KEY (view_id, club_id) REFERENCES views(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_view_datasets_dataset_club FOREIGN KEY (dataset_id, club_id) REFERENCES datasets(id, club_id) ON DELETE CASCADE,
    INDEX idx_view_datasets_dataset (dataset_id),
    INDEX idx_view_datasets_view_club (view_id, club_id),
    INDEX idx_view_datasets_dataset_club (dataset_id, club_id),
    INDEX idx_view_datasets_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- widgets — grilla del Paso 4. type limitado a la libreria fija del brief.
--
-- OJO: los datasets que consume un widget viven dentro de `config` (JSON), fuera
-- del alcance de cualquier FK. El scoping por club de esos ids es responsabilidad
-- de la aplicación; verify_isolation.sql lo audita (chequeo D01).
-- ---------------------------------------------------------------------------
CREATE TABLE widgets (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    view_id       INT UNSIGNED NOT NULL,
    type          ENUM('kpi_card', 'table', 'line_chart', 'bar_chart', 'stacked_bar') NOT NULL,
    config        JSON NOT NULL COMMENT 'unica fuente de verdad que el WidgetRenderer convierte a HTML + Chart.js. Incluye dataset_ids: [..] (uno o varios datasets para cruzar partidos); se acepta el viejo dataset_id por retrocompat',
    position      INT UNSIGNED NOT NULL DEFAULT 0,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_widgets_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_widgets_view_club FOREIGN KEY (view_id, club_id) REFERENCES views(id, club_id) ON DELETE CASCADE,
    UNIQUE KEY uq_widgets_id_club (id, club_id) COMMENT 'target de la FK compuesta de widget_versions',
    INDEX idx_widgets_view (view_id),
    INDEX idx_widgets_view_club (view_id, club_id),
    INDEX idx_widgets_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- widget_versions — historial para el undo (ultimas 5-10 por widget, se poda en la app).
-- ---------------------------------------------------------------------------
CREATE TABLE widget_versions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    widget_id     INT UNSIGNED NOT NULL,
    config        JSON NOT NULL,
    source        ENUM('initial', 'manual', 'ai') NOT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_widget_versions_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_widget_versions_widget_club FOREIGN KEY (widget_id, club_id) REFERENCES widgets(id, club_id) ON DELETE CASCADE,
    INDEX idx_widget_versions_widget (widget_id, created_at),
    INDEX idx_widget_versions_widget_club (widget_id, club_id),
    INDEX idx_widget_versions_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- custom_metrics — formulas simples entre columnas numericas de un dataset,
-- disponibles para todos los widgets de la vista que usen ese dataset.
-- ---------------------------------------------------------------------------
CREATE TABLE custom_metrics (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NOT NULL,
    nombre        VARCHAR(150) NOT NULL,
    formula       JSON NOT NULL COMMENT '{ operacion, columnas: [...] }',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_custom_metrics_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_custom_metrics_view_club FOREIGN KEY (view_id, club_id) REFERENCES views(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_custom_metrics_dataset_club FOREIGN KEY (dataset_id, club_id) REFERENCES datasets(id, club_id) ON DELETE CASCADE,
    INDEX idx_custom_metrics_view (view_id),
    INDEX idx_custom_metrics_dataset (dataset_id),
    INDEX idx_custom_metrics_view_club (view_id, club_id),
    INDEX idx_custom_metrics_dataset_club (dataset_id, club_id),
    INDEX idx_custom_metrics_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- view_filters — filtros a nivel vista+dataset, disponibles para sus widgets.
-- `dataset_id` NULL = filtro global de la vista; con MATCH SIMPLE esas filas no
-- evalúan la FK compuesta a datasets, que es exactamente lo que se busca.
-- ---------------------------------------------------------------------------
CREATE TABLE view_filters (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    view_id       INT UNSIGNED NOT NULL,
    dataset_id    INT UNSIGNED NULL COMMENT 'NULL = filtro global de la vista sobre una dimension universal (familia/sub_familia/jugador), aplica a TODOS los widgets sin importar su dataset',
    column_name   VARCHAR(150) NOT NULL,
    filter_type   VARCHAR(50) NOT NULL COMMENT 'rango, valores, fecha, etc.',
    config        JSON NULL COMMENT 'parametros propios del filtro (min/max, opciones, etc.)',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_view_filters_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_view_filters_view_club FOREIGN KEY (view_id, club_id) REFERENCES views(id, club_id) ON DELETE CASCADE,
    CONSTRAINT fk_view_filters_dataset_club FOREIGN KEY (dataset_id, club_id) REFERENCES datasets(id, club_id) ON DELETE CASCADE,
    INDEX idx_view_filters_view (view_id),
    INDEX idx_view_filters_view_club (view_id, club_id),
    INDEX idx_view_filters_dataset_club (dataset_id, club_id),
    INDEX idx_view_filters_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
