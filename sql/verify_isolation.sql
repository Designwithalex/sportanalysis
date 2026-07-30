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
--   - Periódicamente. Los chequeos marcados [SIN FK] son los que la base NO puede
--     garantizar por sí sola después de la PARTE B (ver PARTE 1-bis de
--     migration_2026_07_multiclub_b.sql): ahí sí hace falta mirarlos.
--   - El BLOQUE E entero es [SIN FK]: cubre `users`, `views.user_id`, `view_order`
--     y `verificaciones`, donde las FKs son simples a propósito.
--   - El BLOQUE F cubre `user_categorias` (habilitaciones por especialidad), que
--     tampoco lleva club_id y por lo tanto tampoco tiene FKs compuestas.
--
-- Requiere que la columna club_id exista, o sea: PARTE A ya corrida.
-- El BLOQUE E requiere además migration_2026_07_roles_vistas.sql, y el BLOQUE F
-- requiere migration_2026_07_kinesiologia.sql. Por eso cada uno va como statement
-- SEPARADO (igual que el D): si esa migración todavía no se aplicó, el bloque falla
-- con "Unknown column 'views.user_id'" / "Table 'user_categorias' doesn't exist"
-- pero los bloques anteriores ya corrieron y se leen igual.
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


-- ---------------------------------------------------------------------------
-- BLOQUE 5 / E (aparte) — Permisos, vistas privadas y verificaciones.
--
-- Requiere migration_2026_07_roles_vistas.sql corrida. Va como statement separado
-- para no arrastrar a los BLOQUES 1-3 si esa migración todavía no se aplicó.
--
-- Ninguno de estos cinco está garantizado por una FK: `views.user_id`,
-- `view_order` y `verificaciones` usan FKs SIMPLES (a users/clubs/views), así que
-- la base no puede impedir por sí sola que una fila cruce de club. Es la app la
-- que tiene que scopear, y esto lo audita.
--
-- NO se chequea `verificaciones.revisada_por` contra el club: un superadmin
-- resuelve pedidos de cualquier club, así que ahí un club distinto es LEGÍTIMO.
-- ---------------------------------------------------------------------------

SELECT 'E01  views.user_id -> users de otro club        [SIN FK]' AS chequeo, COUNT(*) AS filas_malas
    FROM views v JOIN users u ON u.id = v.user_id
    WHERE u.club_id <> v.club_id
UNION ALL
-- Fila de orden de tabs que apunta a una vista que ese usuario no puede ver:
-- o es de otro club, o es una vista PRIVADA de otro usuario.
SELECT 'E02  view_order -> vista no visible para el user [SIN FK]', COUNT(*)
    FROM view_order vo
    JOIN users u ON u.id = vo.user_id
    JOIN views v ON v.id = vo.view_id
    WHERE v.club_id <> u.club_id
       OR (v.user_id IS NOT NULL AND v.user_id <> vo.user_id)
UNION ALL
SELECT 'E03  verificaciones.club_id <> users.club_id     [SIN FK]', COUNT(*)
    FROM verificaciones f JOIN users u ON u.id = f.user_id
    WHERE f.club_id <> u.club_id
UNION ALL
-- Las vistas base (cluster = categoría de datos, player = overview de un jugador)
-- las genera BaseViewGenerator para el club entero: SIEMPRE user_id NULL.
-- Una con dueño sería una vista base secuestrada por un usuario.
SELECT 'E04  views cluster/player con user_id NOT NULL', COUNT(*)
    FROM views
    WHERE tipo IN ('cluster', 'player') AND user_id IS NOT NULL
UNION ALL
-- No hay UNIQUE (club_id, categoria) sobre las vistas cluster y el upsert de
-- BaseViewGenerator resuelve con
--     WHERE tipo='cluster' AND categoria=? AND club_id=? AND user_id IS NULL LIMIT 1
-- que es el target de tres DELETE (widgets, view_datasets, view_filters). Si alguna
-- vez se duplicaron, el LIMIT 1 se vuelve no determinístico: regenerar las vistas
-- base puede vaciar la equivocada. Cuenta las SOBRANTES de cada grupo.
--
-- El WHERE replica el del generador: sólo las vistas BASE (user_id NULL) compiten
-- entre sí, y `categoria IS NOT NULL` porque una cluster sin categoría no la
-- encuentra ese SELECT — ese caso es el E06, no un duplicado.
SELECT 'E05  vistas cluster duplicadas por (club, categoria)', COALESCE(SUM(sobrantes), 0)
    FROM (
        SELECT COUNT(*) - 1 AS sobrantes
        FROM views
        WHERE tipo = 'cluster' AND categoria IS NOT NULL AND user_id IS NULL
        GROUP BY club_id, categoria
        HAVING COUNT(*) > 1
    ) dup
UNION ALL
-- Una vista cluster sin categoría es inalcanzable para el upsert de
-- BaseViewGenerator (busca por `categoria = ?`, que nunca matchea NULL): queda como
-- tab zombi que ninguna regeneración actualiza ni reemplaza.
SELECT 'E06  vistas cluster con categoria NULL', COUNT(*)
    FROM views
    WHERE tipo = 'cluster' AND categoria IS NULL;


-- ---------------------------------------------------------------------------
-- BLOQUE 6 / F (aparte) — Habilitaciones por especialidad (`user_categorias`).
--
-- Requiere migration_2026_07_kinesiologia.sql corrida. Statement separado por lo
-- mismo que el E: si la tabla no existe todavía, los bloques anteriores ya se
-- leyeron igual.
--
-- `user_categorias` NO lleva club_id, por decisión documentada en la PARTE 2 de esa
-- migración: es un atributo del usuario (como `view_order`), no una tabla de
-- dominio, y `users` no tiene el UNIQUE (id, club_id) que haría falta para colgarle
-- una FK compuesta. El club de una habilitación se deriva SIEMPRE de
-- `users.club_id`. Estos chequeos son la contrapartida de esa decisión.
--
-- F03 excluye a los `superadmin` a propósito, por el mismo motivo por el que el
-- BLOQUE E no chequea `verificaciones.revisada_por`: un superadmin opera sobre
-- cualquier club, así que ahí un club distinto es LEGÍTIMO. Un `admin_club`
-- otorgando fuera de su club, en cambio, es una fuga.
-- ---------------------------------------------------------------------------

SELECT 'F01  user_categorias -> users inexistente (user_id)' AS chequeo, COUNT(*) AS filas_malas
    FROM user_categorias uc
    LEFT JOIN users u ON u.id = uc.user_id
    WHERE u.id IS NULL
UNION ALL
-- La FK CASCADE ya lo impide; se chequea igual, como alarma de que alguien la
-- soltó "para destrabar" algo (mismo criterio que los BLOQUES B y C).
SELECT 'F02  user_categorias -> otorgante inexistente', COUNT(*)
    FROM user_categorias uc
    LEFT JOIN users o ON o.id = uc.otorgada_por
    WHERE uc.otorgada_por IS NOT NULL AND o.id IS NULL
UNION ALL
SELECT 'F03  otorgante de otro club, y no es superadmin  [SIN FK]', COUNT(*)
    FROM user_categorias uc
    JOIN users u ON u.id = uc.user_id
    JOIN users o ON o.id = uc.otorgada_por
    WHERE o.club_id <> u.club_id
      AND o.nivel <> 'superadmin'
UNION ALL
-- Nadie se auto-habilita: las habilitaciones las otorga otra persona (un admin_club
-- de su club, o el superadmin). Una fila donde el otorgante es el propio
-- beneficiario significa que existe (o existió) un camino self-service, que es
-- exactamente lo que no debe haber.
-- Se excluye al `superadmin`: que se otorgue a sí mismo es legítimo (puede todo
-- igual, y es quien hace el bootstrap). Las sembradas por esta migración tienen
-- otorgada_por NULL y tampoco cuentan acá.
SELECT 'F04  habilitaciones auto-otorgadas (otorgada_por = user_id)', COUNT(*)
    FROM user_categorias uc
    JOIN users u ON u.id = uc.user_id
    WHERE uc.otorgada_por = uc.user_id
      AND u.nivel <> 'superadmin'
UNION ALL
-- Otorgante que no tiene nivel para otorgar. No es un cruce de clubes pero es el
-- mismo agujero: alguien escribió en esta tabla sin pasar por el gate.
SELECT 'F05  otorgante sin nivel admin_club/superadmin', COUNT(*)
    FROM user_categorias uc
    JOIN users o ON o.id = uc.otorgada_por
    WHERE o.nivel NOT IN ('admin_club', 'superadmin');
