-- =============================================================================
-- verify_isolation.sql — auditoría del aislamiento por club.
-- =============================================================================
--
-- SÓLO LECTURA. No modifica nada. Se puede correr cuando sea, en producción.
--
-- CÓMO LEERLO: una fila por chequeo. TODAS las filas tienen que dar
-- filas_malas = 0. Cualquier número > 0 es una fuga de datos entre clubes.
--
-- CUÁNDO CORRERLO
--   - Después de migration_2026_07_multiclub_a.sql (debe dar todo 0 desde el vamos:
--     hay un solo club y todo quedó backfilleado a GEBA).
--   - Después de deployar el código con scoping, ANTES de correr la PARTE B.
--     Este es el chequeo que decide si la B se puede correr.
--   - Periódicamente. Los tres chequeos marcados [SIN FK] son los únicos que la
--     base NO puede garantizar por sí sola después de la PARTE B (ver PARTE 1-bis
--     de migration_2026_07_multiclub_b.sql): ahí sí hace falta mirarlos.
--
-- Requiere que la columna club_id exista, o sea: PARTE A ya corrida.
-- MariaDB 11.8 — sin funciones de ventana, sólo COUNT + JOIN.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- BLOQUE 1 — Relación hijo -> padre: el hijo pertenece a otro club que su padre.
-- ---------------------------------------------------------------------------

SELECT 'A01  dataset_rows -> datasets'                             AS chequeo, COUNT(*) AS filas_malas
    FROM dataset_rows c JOIN datasets p ON p.id = c.dataset_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A02  dataset_rows -> players (player_id)        [SIN FK]', COUNT(*)
    FROM dataset_rows c JOIN players p ON p.id = c.player_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A03  name_reconciliations -> datasets', COUNT(*)
    FROM name_reconciliations c JOIN datasets p ON p.id = c.dataset_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A04  name_reconciliations -> players (sugerido) [SIN FK]', COUNT(*)
    FROM name_reconciliations c JOIN players p ON p.id = c.suggested_player_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A05  name_reconciliations -> players (resuelto) [SIN FK]', COUNT(*)
    FROM name_reconciliations c JOIN players p ON p.id = c.resolved_player_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A06  views -> players (player_id)', COUNT(*)
    FROM views c JOIN players p ON p.id = c.player_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A07  view_datasets -> views', COUNT(*)
    FROM view_datasets c JOIN views p ON p.id = c.view_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A08  view_datasets -> datasets', COUNT(*)
    FROM view_datasets c JOIN datasets p ON p.id = c.dataset_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A09  widgets -> views', COUNT(*)
    FROM widgets c JOIN views p ON p.id = c.view_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A10  widget_versions -> widgets', COUNT(*)
    FROM widget_versions c JOIN widgets p ON p.id = c.widget_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A11  custom_metrics -> views', COUNT(*)
    FROM custom_metrics c JOIN views p ON p.id = c.view_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A12  custom_metrics -> datasets', COUNT(*)
    FROM custom_metrics c JOIN datasets p ON p.id = c.dataset_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A13  view_filters -> views', COUNT(*)
    FROM view_filters c JOIN views p ON p.id = c.view_id WHERE p.club_id <> c.club_id
UNION ALL
SELECT 'A14  view_filters -> datasets (dataset_id NOT NULL)', COUNT(*)
    FROM view_filters c JOIN datasets p ON p.id = c.dataset_id WHERE p.club_id <> c.club_id

-- ---------------------------------------------------------------------------
-- BLOQUE 2 — club_id NULL. Con la columna NOT NULL siempre debe dar 0; sirve de
-- alarma si alguien alguna vez la hace NULLable "para destrabar" algo.
-- ---------------------------------------------------------------------------
UNION ALL SELECT 'B01  players.club_id IS NULL',              COUNT(*) FROM players              WHERE club_id IS NULL
UNION ALL SELECT 'B02  datasets.club_id IS NULL',             COUNT(*) FROM datasets             WHERE club_id IS NULL
UNION ALL SELECT 'B03  dataset_rows.club_id IS NULL',         COUNT(*) FROM dataset_rows         WHERE club_id IS NULL
UNION ALL SELECT 'B04  name_reconciliations.club_id IS NULL', COUNT(*) FROM name_reconciliations WHERE club_id IS NULL
UNION ALL SELECT 'B05  views.club_id IS NULL',                COUNT(*) FROM views                WHERE club_id IS NULL
UNION ALL SELECT 'B06  view_datasets.club_id IS NULL',        COUNT(*) FROM view_datasets        WHERE club_id IS NULL
UNION ALL SELECT 'B07  widgets.club_id IS NULL',              COUNT(*) FROM widgets              WHERE club_id IS NULL
UNION ALL SELECT 'B08  widget_versions.club_id IS NULL',      COUNT(*) FROM widget_versions      WHERE club_id IS NULL
UNION ALL SELECT 'B09  custom_metrics.club_id IS NULL',       COUNT(*) FROM custom_metrics       WHERE club_id IS NULL
UNION ALL SELECT 'B10  view_filters.club_id IS NULL',         COUNT(*) FROM view_filters         WHERE club_id IS NULL

-- ---------------------------------------------------------------------------
-- BLOQUE 3 — club_id huérfano: apunta a un club que no existe en `clubs`.
-- (La PARTE 3 opcional de la migración B lo previene por FK; igual se chequea.)
-- ---------------------------------------------------------------------------
UNION ALL SELECT 'C01  players.club_id huérfano',              COUNT(*) FROM players              c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C02  datasets.club_id huérfano',             COUNT(*) FROM datasets             c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C03  dataset_rows.club_id huérfano',         COUNT(*) FROM dataset_rows         c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C04  name_reconciliations.club_id huérfano', COUNT(*) FROM name_reconciliations c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C05  views.club_id huérfano',                COUNT(*) FROM views                c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C06  view_datasets.club_id huérfano',        COUNT(*) FROM view_datasets        c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C07  widgets.club_id huérfano',              COUNT(*) FROM widgets              c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C08  widget_versions.club_id huérfano',      COUNT(*) FROM widget_versions      c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C09  custom_metrics.club_id huérfano',       COUNT(*) FROM custom_metrics       c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C10  view_filters.club_id huérfano',         COUNT(*) FROM view_filters         c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL
UNION ALL SELECT 'C11  users.club_id huérfano',                COUNT(*) FROM users                c LEFT JOIN clubs k ON k.id = c.club_id WHERE k.id IS NULL;


-- ---------------------------------------------------------------------------
-- BLOQUE 4 (aparte, opcional) — widgets.config apuntando a datasets de otro club.
--
-- Ninguna FK puede cubrir esto: los datasets de un widget viven DENTRO del JSON
-- de `widgets.config` (`dataset_ids: [..]`, o el viejo `dataset_id` escalar por
-- retrocompatibilidad, ver WidgetRenderer::datasetIds()). Es el agujero real más
-- probable si el generador de vistas por IA no filtra por club.
--
-- Va como statement separado porque usa JSON_TABLE (MariaDB 10.6+). Si tu servidor
-- lo rechaza, el BLOQUE 1-3 de arriba ya corrió igual.
-- ---------------------------------------------------------------------------

SELECT 'D01  widgets.config.dataset_ids -> datasets de otro club' AS chequeo, COUNT(*) AS filas_malas
FROM widgets w
JOIN JSON_TABLE(
        COALESCE(
            JSON_EXTRACT(w.config, '$.dataset_ids'),
            JSON_ARRAY(JSON_EXTRACT(w.config, '$.dataset_id'))
        ),
        '$[*]' COLUMNS (dataset_id INT PATH '$')
     ) jt ON 1 = 1
JOIN datasets d ON d.id = jt.dataset_id
WHERE d.club_id <> w.club_id;
