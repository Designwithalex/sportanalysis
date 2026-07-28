-- =============================================================================
-- Migración multi-club — PARTE B (endurecimiento). CORRER SÓLO DESPUÉS DEL DEPLOY.
-- =============================================================================
--
-- #############################################################################
-- ##                                                                         ##
-- ##   AVISO: CORRER ESTO ANTES DEL DEPLOY ROMPE LA APP EN PRODUCCIÓN.        ##
-- ##                                                                         ##
-- ##   Esta migración saca el `DEFAULT 1` de las 10 columnas club_id. El      ##
-- ##   código viejo NO manda club_id en sus INSERT: sin el DEFAULT, todo      ##
-- ##   INSERT falla con "Field 'club_id' doesn't have a default value".       ##
-- ##   Subir un CSV, generar una vista, guardar o editar un widget: todo      ##
-- ##   muerto, y de inmediato.                                               ##
-- ##                                                                         ##
-- ##   REQUISITOS ANTES DE CORRER:                                            ##
-- ##     1. migration_2026_07_multiclub_a.sql corrida.                        ##
-- ##     2. seed_clubs_urba.sql corrido (GEBA en id = 1).                     ##
-- ##     3. Código con scoping por club DEPLOYADO y VERIFICADO en producción  ##
-- ##        (INSERT con club_id explícito + WHERE club_id en todo SELECT).    ##
-- ##     4. verify_isolation.sql corrido: TODOS los contadores en 0.          ##
-- ##                                                                         ##
-- #############################################################################
--
-- QUÉ HACE
--   1) Reemplaza las FKs simples por FKs COMPUESTAS (fk_col, club_id) -> (id, club_id).
--      A partir de acá la base misma hace imposible que un widget cuelgue de una
--      vista de otro club, aunque haya un bug en el PHP.
--   2) Saca el DEFAULT 1 de club_id: cada INSERT tiene que decir a qué club va.
--   3) (Opcional) FKs de club_id -> clubs(id).
--
-- Rerunnable: cada bloque dropea tanto el nombre viejo como el nuevo antes de crear.
-- MariaDB 11.8. Base ~3.3 MB, todo instantáneo.
-- =============================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- PASO 0 — CHEQUEO PREVIO OBLIGATORIO: los nombres de las FKs viejas.
--
-- Los DROP de abajo asumen los nombres declarados en sql/schema.sql y en
-- migration_2026_07_base_views.sql. Si la base de producción se creó de otra
-- forma, InnoDB pudo haber autogenerado nombres tipo `datasets_ibfk_1` y los
-- DROP ... IF EXISTS no van a encontrar nada: la FK simple vieja quedaría viva
-- junto a la nueva compuesta (redundante, no rompe, pero ensucia).
--
-- Correr esto ANTES y comparar con la lista esperada:
--
-- SELECT TABLE_NAME, CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
-- FROM information_schema.KEY_COLUMN_USAGE
-- WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
-- ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;
--
-- Esperado (13 FKs simples): fk_dataset_rows_dataset, fk_dataset_rows_player,
-- fk_reconciliations_dataset, fk_reconciliations_suggested,
-- fk_reconciliations_resolved, fk_views_player, fk_view_datasets_view,
-- fk_view_datasets_dataset, fk_widgets_view, fk_widget_versions_widget,
-- fk_custom_metrics_view, fk_custom_metrics_dataset, fk_view_filters_view,
-- fk_view_filters_dataset  (+ fk_users_club, nueva de la PARTE A).
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- PARTE 1 — FKs compuestas.
--
-- Nomenclatura: la compuesta lleva el nombre de la vieja + sufijo `_club`.
-- El índice que cada una necesita del lado hijo, y el UNIQUE (id, club_id) del
-- lado padre, ya los creó la PARTE A.
-- ---------------------------------------------------------------------------

-- views(player_id, club_id) -> players(id, club_id)
--
-- NOTA SOBRE NULLABLES (leer antes de tocar nada):
--   `views.player_id` es NULLable (sólo las vistas tipo 'player' lo usan; hoy 37
--   de 44). InnoDB usa semántica MATCH SIMPLE: si CUALQUIER columna de la FK es
--   NULL, la restricción no se chequea. O sea: las vistas manual/cluster
--   (player_id NULL) siguen insertándose sin problema, exactamente como hoy.
--   La FK vieja `fk_views_player` es ON DELETE CASCADE (no SET NULL, ojo con el
--   plan que dice SET NULL — verificado contra sql/schema.sql línea 90). CASCADE
--   sí es compatible con una FK compuesta que incluye una columna NOT NULL,
--   porque borra la fila entera en vez de tener que nulificar club_id.
ALTER TABLE views DROP FOREIGN KEY IF EXISTS fk_views_player;
ALTER TABLE views DROP FOREIGN KEY IF EXISTS fk_views_player_club;
ALTER TABLE views
    ADD CONSTRAINT fk_views_player_club FOREIGN KEY (player_id, club_id)
        REFERENCES players (id, club_id) ON DELETE CASCADE;

-- dataset_rows(dataset_id, club_id) -> datasets(id, club_id)
ALTER TABLE dataset_rows DROP FOREIGN KEY IF EXISTS fk_dataset_rows_dataset;
ALTER TABLE dataset_rows DROP FOREIGN KEY IF EXISTS fk_dataset_rows_dataset_club;
ALTER TABLE dataset_rows
    ADD CONSTRAINT fk_dataset_rows_dataset_club FOREIGN KEY (dataset_id, club_id)
        REFERENCES datasets (id, club_id) ON DELETE CASCADE;

-- name_reconciliations(dataset_id, club_id) -> datasets(id, club_id)
ALTER TABLE name_reconciliations DROP FOREIGN KEY IF EXISTS fk_reconciliations_dataset;
ALTER TABLE name_reconciliations DROP FOREIGN KEY IF EXISTS fk_reconciliations_dataset_club;
ALTER TABLE name_reconciliations
    ADD CONSTRAINT fk_reconciliations_dataset_club FOREIGN KEY (dataset_id, club_id)
        REFERENCES datasets (id, club_id) ON DELETE CASCADE;

-- view_datasets -> views y -> datasets (los dos padres)
ALTER TABLE view_datasets DROP FOREIGN KEY IF EXISTS fk_view_datasets_view;
ALTER TABLE view_datasets DROP FOREIGN KEY IF EXISTS fk_view_datasets_view_club;
ALTER TABLE view_datasets
    ADD CONSTRAINT fk_view_datasets_view_club FOREIGN KEY (view_id, club_id)
        REFERENCES views (id, club_id) ON DELETE CASCADE;

ALTER TABLE view_datasets DROP FOREIGN KEY IF EXISTS fk_view_datasets_dataset;
ALTER TABLE view_datasets DROP FOREIGN KEY IF EXISTS fk_view_datasets_dataset_club;
ALTER TABLE view_datasets
    ADD CONSTRAINT fk_view_datasets_dataset_club FOREIGN KEY (dataset_id, club_id)
        REFERENCES datasets (id, club_id) ON DELETE CASCADE;

-- widgets(view_id, club_id) -> views(id, club_id)
ALTER TABLE widgets DROP FOREIGN KEY IF EXISTS fk_widgets_view;
ALTER TABLE widgets DROP FOREIGN KEY IF EXISTS fk_widgets_view_club;
ALTER TABLE widgets
    ADD CONSTRAINT fk_widgets_view_club FOREIGN KEY (view_id, club_id)
        REFERENCES views (id, club_id) ON DELETE CASCADE;

-- widget_versions(widget_id, club_id) -> widgets(id, club_id)
ALTER TABLE widget_versions DROP FOREIGN KEY IF EXISTS fk_widget_versions_widget;
ALTER TABLE widget_versions DROP FOREIGN KEY IF EXISTS fk_widget_versions_widget_club;
ALTER TABLE widget_versions
    ADD CONSTRAINT fk_widget_versions_widget_club FOREIGN KEY (widget_id, club_id)
        REFERENCES widgets (id, club_id) ON DELETE CASCADE;

-- custom_metrics -> views y -> datasets
ALTER TABLE custom_metrics DROP FOREIGN KEY IF EXISTS fk_custom_metrics_view;
ALTER TABLE custom_metrics DROP FOREIGN KEY IF EXISTS fk_custom_metrics_view_club;
ALTER TABLE custom_metrics
    ADD CONSTRAINT fk_custom_metrics_view_club FOREIGN KEY (view_id, club_id)
        REFERENCES views (id, club_id) ON DELETE CASCADE;

ALTER TABLE custom_metrics DROP FOREIGN KEY IF EXISTS fk_custom_metrics_dataset;
ALTER TABLE custom_metrics DROP FOREIGN KEY IF EXISTS fk_custom_metrics_dataset_club;
ALTER TABLE custom_metrics
    ADD CONSTRAINT fk_custom_metrics_dataset_club FOREIGN KEY (dataset_id, club_id)
        REFERENCES datasets (id, club_id) ON DELETE CASCADE;

-- view_filters(view_id, club_id) -> views(id, club_id)
ALTER TABLE view_filters DROP FOREIGN KEY IF EXISTS fk_view_filters_view;
ALTER TABLE view_filters DROP FOREIGN KEY IF EXISTS fk_view_filters_view_club;
ALTER TABLE view_filters
    ADD CONSTRAINT fk_view_filters_view_club FOREIGN KEY (view_id, club_id)
        REFERENCES views (id, club_id) ON DELETE CASCADE;

-- view_filters(dataset_id, club_id) -> datasets(id, club_id)
--
-- NO estaba en el plan (el plan sólo pedía view_filters -> views). Lo agrego
-- porque si no, `view_filters.dataset_id` queda con la FK simple vieja y sería la
-- única puerta abierta para apuntar a un dataset de otro club. Si preferís
-- ceñirte al plan al pie de la letra, borrá este bloque: no rompe nada más.
--
-- `view_filters.dataset_id` es NULLable a propósito (NULL = filtro global de la
-- vista sobre familia/sub_familia/jugador, ver migration_2026_07_sportanalysis.sql).
-- Con MATCH SIMPLE esas filas quedan sin chequear y siguen funcionando igual que hoy.
ALTER TABLE view_filters DROP FOREIGN KEY IF EXISTS fk_view_filters_dataset;
ALTER TABLE view_filters DROP FOREIGN KEY IF EXISTS fk_view_filters_dataset_club;
ALTER TABLE view_filters
    ADD CONSTRAINT fk_view_filters_dataset_club FOREIGN KEY (dataset_id, club_id)
        REFERENCES datasets (id, club_id) ON DELETE CASCADE;


-- ---------------------------------------------------------------------------
-- PARTE 1-bis — LAS TRES FKs QUE **NO** SE PUEDEN VOLVER COMPUESTAS.
--
--   dataset_rows.player_id            -> players(id)  ON DELETE SET NULL
--   name_reconciliations.suggested_player_id -> players(id)  ON DELETE SET NULL
--   name_reconciliations.resolved_player_id  -> players(id)  ON DELETE SET NULL
--
-- Motivo (restricción dura de InnoDB, no es una preferencia):
--   ON DELETE SET NULL exige que TODAS las columnas del lado hijo sean NULLables,
--   porque al borrarse el padre el motor tiene que poner NULL en todas. `club_id`
--   es NOT NULL, así que un
--       FOREIGN KEY (player_id, club_id) REFERENCES players(id, club_id) ON DELETE SET NULL
--   es rechazado directamente al crearlo (errno 150 / "Column 'club_id' cannot be
--   NOT NULL: needed in a foreign key constraint ... SET NULL").
--
-- Alternativas evaluadas y descartadas:
--   a) Hacer club_id NULLable en esas dos tablas -> destruye el aislamiento, que es
--      justo lo que estamos construyendo. NO.
--   b) Cambiar SET NULL por CASCADE -> cambia el comportamiento del producto: hoy
--      borrar un jugador conserva sus filas de datos crudos con player_id NULL
--      (quedan como "sin asignar", recuperables desde el Paso 3.7). Con CASCADE se
--      borrarían los datos. Es una decisión de producto, NO la tomo yo acá.
--
-- Consecuencia a asumir: estas tres columnas quedan sin protección de FK contra
-- cruce de clubes. Hay que cubrirlas en la aplicación (el matcher de nombres sólo
-- debe mirar players del club de la sesión) y monitorearlas: verify_isolation.sql
-- tiene un chequeo dedicado para cada una.
--
-- Las FKs simples viejas (fk_dataset_rows_player, fk_reconciliations_suggested,
-- fk_reconciliations_resolved) se dejan TAL CUAL. No tocar.
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- PARTE 2 — Sacar el DEFAULT 1 de club_id en las 10 tablas.
-- A partir de acá, todo INSERT tiene que pasar club_id explícito.
-- Se usa MODIFY (portable) en vez de ALTER COLUMN ... DROP DEFAULT. MODIFY
-- reescribe la definición completa, por eso se repite el COMMENT (si no, se pierde).
-- ---------------------------------------------------------------------------

ALTER TABLE players             MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE datasets            MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE dataset_rows        MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE name_reconciliations MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE views               MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE view_datasets       MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE widgets             MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE widget_versions     MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE custom_metrics      MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';
ALTER TABLE view_filters        MODIFY club_id INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila';


-- ---------------------------------------------------------------------------
-- PARTE 3 (OPCIONAL, no estaba en el plan) — FK de club_id -> clubs(id).
--
-- Garantiza que ninguna fila apunte a un club inexistente. Sin ON DELETE: borrar
-- un club con datos queda BLOQUEADO (RESTRICT), que es lo que querés — un club se
-- desactiva con `clubs.activo = 0`, no se borra.
--
-- REQUIERE que seed_clubs_urba.sql ya haya corrido (si no, no existe el club 1 y
-- el ADD CONSTRAINT falla al validar las filas existentes).
--
-- Si preferís no agregarlas, borrá este bloque entero: nada más depende de él.
-- ---------------------------------------------------------------------------

ALTER TABLE players DROP FOREIGN KEY IF EXISTS fk_players_club;
ALTER TABLE players ADD CONSTRAINT fk_players_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE datasets DROP FOREIGN KEY IF EXISTS fk_datasets_club;
ALTER TABLE datasets ADD CONSTRAINT fk_datasets_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE dataset_rows DROP FOREIGN KEY IF EXISTS fk_dataset_rows_club;
ALTER TABLE dataset_rows ADD CONSTRAINT fk_dataset_rows_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE name_reconciliations DROP FOREIGN KEY IF EXISTS fk_reconciliations_club;
ALTER TABLE name_reconciliations ADD CONSTRAINT fk_reconciliations_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE views DROP FOREIGN KEY IF EXISTS fk_views_club;
ALTER TABLE views ADD CONSTRAINT fk_views_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE view_datasets DROP FOREIGN KEY IF EXISTS fk_view_datasets_club;
ALTER TABLE view_datasets ADD CONSTRAINT fk_view_datasets_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE widgets DROP FOREIGN KEY IF EXISTS fk_widgets_club;
ALTER TABLE widgets ADD CONSTRAINT fk_widgets_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE widget_versions DROP FOREIGN KEY IF EXISTS fk_widget_versions_club;
ALTER TABLE widget_versions ADD CONSTRAINT fk_widget_versions_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE custom_metrics DROP FOREIGN KEY IF EXISTS fk_custom_metrics_club;
ALTER TABLE custom_metrics ADD CONSTRAINT fk_custom_metrics_club FOREIGN KEY (club_id) REFERENCES clubs (id);

ALTER TABLE view_filters DROP FOREIGN KEY IF EXISTS fk_view_filters_club;
ALTER TABLE view_filters ADD CONSTRAINT fk_view_filters_club FOREIGN KEY (club_id) REFERENCES clubs (id);


-- ---------------------------------------------------------------------------
-- Después de correr esto: volver a pasar verify_isolation.sql (todo en 0) y
-- probar en producción una subida de CSV + generación de vista + edición de widget.
-- ---------------------------------------------------------------------------
