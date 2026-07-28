-- =============================================================================
-- Migración multi-club — PARTE A (compatible hacia atrás).
-- =============================================================================
--
-- QUÉ HACE
--   Crea `clubs` y `users`, agrega `club_id` a las 10 tablas del dominio, hace
--   backfill de todo lo existente a GEBA (club_id = 1) y crea los índices que la
--   PARTE B va a necesitar como target de sus FKs compuestas.
--
-- CUÁNDO CORRERLA
--   ANTES de deployar el código con scoping por club. Esta migración es
--   deliberadamente NO destructiva: la app en producción sigue funcionando igual
--   mientras corre y después, sin tocar una línea de PHP.
--
-- ORDEN COMPLETO DEL OPERATIVO
--   1) migration_2026_07_multiclub_a.sql   <-- este archivo
--   2) seed_clubs_urba.sql                 (revisar la lista antes)
--   3) deploy del código con scoping + verificación manual en producción
--   4) verify_isolation.sql                (todos los contadores deben dar 0)
--   5) migration_2026_07_multiclub_b.sql   (FKs compuestas + saca el DEFAULT)
--
-- !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
-- EL `DEFAULT 1` DE club_id ES DELIBERADO Y CRÍTICO. NO LO "LIMPIES".
-- El código que hoy está en producción no pasa `club_id` en NINGÚN INSERT.
-- Sin el DEFAULT, cada INSERT contra una tabla con club_id NOT NULL falla al
-- instante (subir un CSV, generar una vista, guardar un widget: todo roto).
-- El DEFAULT se saca recién en la PARTE B, cuando el código ya lo manda explícito.
-- !!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!!
--
-- MariaDB 11.8. Se usan `IF NOT EXISTS` (sintaxis MariaDB) para que un segundo
-- run no rompa. Tamaño de la base: ~3.3 MB, los ALTER son instantáneos.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- PARTE 1 — Tablas nuevas: clubs y users.
-- `clubs` va primero porque `users.club_id` la referencia.
-- ---------------------------------------------------------------------------

-- clubs — un club = un tenant. Todo dato del dominio cuelga de acá.
CREATE TABLE IF NOT EXISTS clubs (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre        VARCHAR(150) NOT NULL,
    slug          VARCHAR(120) NOT NULL COMMENT 'identificador estable apto para URL, minúsculas sin tildes',
    activo        TINYINT(1) NOT NULL DEFAULT 1 COMMENT '0 = no aparece en el dropdown de registro',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_clubs_slug (slug),
    INDEX idx_clubs_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- users — cuentas de acceso. Registro abierto: el usuario elige su club del dropdown.
CREATE TABLE IF NOT EXISTS users (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id        INT UNSIGNED NOT NULL,
    email          VARCHAR(190) NOT NULL COMMENT '190 chars: cabe en un índice UNIQUE utf8mb4',
    password_hash  VARCHAR(255) NOT NULL COMMENT 'password_hash() de PHP, nunca texto plano',
    nombre         VARCHAR(150) NOT NULL,
    rol            VARCHAR(80) NULL COMMENT 'texto libre descriptivo (preparador físico, head coach, etc.), no controla permisos',
    status         ENUM('active', 'pending') NOT NULL DEFAULT 'active'
                   COMMENT 'pending = alta creada pero todavía sin habilitar',
    created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_login_at  TIMESTAMP NULL,
    CONSTRAINT fk_users_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_users_email (email),
    INDEX idx_users_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- PARTE 2 — club_id en las 10 tablas del dominio.
-- NOT NULL DEFAULT 1: ver el aviso grande del encabezado.
-- Sin FK a clubs todavía: se agrega en la PARTE B, cuando el seed ya corrió.
-- ---------------------------------------------------------------------------

ALTER TABLE players
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE datasets
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE dataset_rows
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE name_reconciliations
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE views
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE view_datasets
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER dataset_id;

ALTER TABLE widgets
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE widget_versions
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE custom_metrics
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;

ALTER TABLE view_filters
    ADD COLUMN IF NOT EXISTS club_id INT UNSIGNED NOT NULL DEFAULT 1
        COMMENT 'club dueño de la fila. DEFAULT 1 temporal hasta la migración B' AFTER id;


-- ---------------------------------------------------------------------------
-- PARTE 3 — Backfill explícito a GEBA (club_id = 1).
--
-- El DEFAULT de la PARTE 2 ya dejó todas las filas existentes en 1, así que estos
-- UPDATE son técnicamente redundantes. Se hacen igual, sin WHERE, a propósito:
-- que quede escrito y auditable a qué club se adoptaron los datos históricos, y
-- que la cuenta de filas afectadas que reporta phpMyAdmin se pueda contrastar con
-- el inventario esperado (players 37 / datasets 9 / dataset_rows 486 /
-- name_reconciliations 4 / views 44 / view_datasets 342 / widgets 278 /
-- widget_versions 279 / custom_metrics 0 / view_filters 37).
--
-- Si tu cliente tiene sql_safe_updates activado va a rechazar el UPDATE sin WHERE:
-- correr antes  SET SESSION sql_safe_updates = 0;  (phpMyAdmin no lo activa).
-- ---------------------------------------------------------------------------

UPDATE players              SET club_id = 1;
UPDATE datasets             SET club_id = 1;
UPDATE dataset_rows         SET club_id = 1;
UPDATE name_reconciliations SET club_id = 1;
UPDATE views                SET club_id = 1;
UPDATE view_datasets        SET club_id = 1;
UPDATE widgets              SET club_id = 1;
UPDATE widget_versions      SET club_id = 1;
UPDATE custom_metrics       SET club_id = 1;
UPDATE view_filters         SET club_id = 1;


-- ---------------------------------------------------------------------------
-- PARTE 4 — Índices.
--
-- 4a) Índice simple sobre club_id en las 10 tablas: es la columna que va a estar
--     en el WHERE de literalmente toda query de la app.
--
-- 4b) UNIQUE (id, club_id) en los padres de las FKs compuestas de la PARTE B.
--     Sin esto, el ADD CONSTRAINT de la B falla con errno 150: InnoDB exige un
--     índice en el padre cuyas columnas iniciales sean exactamente las
--     referenciadas y en ese orden. El PRIMARY KEY (id) NO alcanza.
--     Se hacen UNIQUE (no simple INDEX) porque el estándar SQL pide clave única
--     del lado padre; como `id` ya es PK, (id, club_id) es único de arranque.
--
-- 4c) Índice compuesto del lado hijo (fk_col, club_id). InnoDB lo crearía solo
--     al agregar la FK, pero con nombre autogenerado; creándolo acá queda con
--     nombre propio y la PARTE B no toca la estructura de índices.
-- ---------------------------------------------------------------------------

-- 4a) club_id en las 10 tablas.
ALTER TABLE players             ADD INDEX IF NOT EXISTS idx_players_club (club_id);
ALTER TABLE datasets            ADD INDEX IF NOT EXISTS idx_datasets_club (club_id);
ALTER TABLE dataset_rows        ADD INDEX IF NOT EXISTS idx_dataset_rows_club (club_id);
ALTER TABLE name_reconciliations ADD INDEX IF NOT EXISTS idx_reconciliations_club (club_id);
ALTER TABLE views               ADD INDEX IF NOT EXISTS idx_views_club (club_id);
ALTER TABLE view_datasets       ADD INDEX IF NOT EXISTS idx_view_datasets_club (club_id);
ALTER TABLE widgets             ADD INDEX IF NOT EXISTS idx_widgets_club (club_id);
ALTER TABLE widget_versions     ADD INDEX IF NOT EXISTS idx_widget_versions_club (club_id);
ALTER TABLE custom_metrics      ADD INDEX IF NOT EXISTS idx_custom_metrics_club (club_id);
ALTER TABLE view_filters        ADD INDEX IF NOT EXISTS idx_view_filters_club (club_id);

-- 4b) Target de las FKs compuestas (lado padre).
ALTER TABLE players  ADD UNIQUE KEY IF NOT EXISTS uq_players_id_club  (id, club_id);
ALTER TABLE datasets ADD UNIQUE KEY IF NOT EXISTS uq_datasets_id_club (id, club_id);
ALTER TABLE views    ADD UNIQUE KEY IF NOT EXISTS uq_views_id_club    (id, club_id);
ALTER TABLE widgets  ADD UNIQUE KEY IF NOT EXISTS uq_widgets_id_club  (id, club_id);

-- 4c) Lado hijo de cada FK compuesta de la PARTE B.
ALTER TABLE views               ADD INDEX IF NOT EXISTS idx_views_player_club (player_id, club_id);
ALTER TABLE dataset_rows        ADD INDEX IF NOT EXISTS idx_dataset_rows_dataset_club (dataset_id, club_id);
ALTER TABLE name_reconciliations ADD INDEX IF NOT EXISTS idx_reconciliations_dataset_club (dataset_id, club_id);
ALTER TABLE view_datasets       ADD INDEX IF NOT EXISTS idx_view_datasets_view_club (view_id, club_id);
ALTER TABLE view_datasets       ADD INDEX IF NOT EXISTS idx_view_datasets_dataset_club (dataset_id, club_id);
ALTER TABLE widgets             ADD INDEX IF NOT EXISTS idx_widgets_view_club (view_id, club_id);
ALTER TABLE widget_versions     ADD INDEX IF NOT EXISTS idx_widget_versions_widget_club (widget_id, club_id);
ALTER TABLE custom_metrics      ADD INDEX IF NOT EXISTS idx_custom_metrics_view_club (view_id, club_id);
ALTER TABLE custom_metrics      ADD INDEX IF NOT EXISTS idx_custom_metrics_dataset_club (dataset_id, club_id);
ALTER TABLE view_filters        ADD INDEX IF NOT EXISTS idx_view_filters_view_club (view_id, club_id);
ALTER TABLE view_filters        ADD INDEX IF NOT EXISTS idx_view_filters_dataset_club (dataset_id, club_id);


-- ---------------------------------------------------------------------------
-- Chequeo post-migración (debe dar 10 filas, todas con club_id = 1 y count > 0
-- salvo custom_metrics que hoy está vacía).
-- ---------------------------------------------------------------------------
-- SELECT 'players' t, club_id, COUNT(*) n FROM players GROUP BY club_id
-- UNION ALL SELECT 'datasets', club_id, COUNT(*) FROM datasets GROUP BY club_id
-- UNION ALL SELECT 'dataset_rows', club_id, COUNT(*) FROM dataset_rows GROUP BY club_id
-- UNION ALL SELECT 'name_reconciliations', club_id, COUNT(*) FROM name_reconciliations GROUP BY club_id
-- UNION ALL SELECT 'views', club_id, COUNT(*) FROM views GROUP BY club_id
-- UNION ALL SELECT 'view_datasets', club_id, COUNT(*) FROM view_datasets GROUP BY club_id
-- UNION ALL SELECT 'widgets', club_id, COUNT(*) FROM widgets GROUP BY club_id
-- UNION ALL SELECT 'widget_versions', club_id, COUNT(*) FROM widget_versions GROUP BY club_id
-- UNION ALL SELECT 'custom_metrics', club_id, COUNT(*) FROM custom_metrics GROUP BY club_id
-- UNION ALL SELECT 'view_filters', club_id, COUNT(*) FROM view_filters GROUP BY club_id;
