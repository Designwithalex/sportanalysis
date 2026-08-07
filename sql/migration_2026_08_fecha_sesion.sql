-- Migración: fecha REAL de la sesión, separada de la fecha de carga.
-- Correr una sola vez sobre una base existente (phpMyAdmin). Bases nuevas: ignorar, schema.sql ya lo incluye.
--
-- EL BUG QUE CIERRA. Un entrenamiento del lunes cuyo CSV se sube el miércoles quedaba registrado
-- como del miércoles: no existía ninguna columna de fecha de sesión, así que todo lo que necesitara
-- tiempo caía sobre `uploaded_at`. La fecha real SÍ venía en los archivos —columna `Date` del
-- GPS— pero como número de serie de Excel (46095), que el detector de tipos clasificaba `numerica`
-- y se guardaba como el string "46095". Por eso los nueve datasets cargados no tenían una sola
-- columna de tipo `fecha`, y ningún widget podía ordenar ni filtrar por tiempo.
--
-- El backfill de los datasets ya cargados NO se hace acá: los valores viven adentro del JSON de
-- `dataset_rows.raw_data` y hay que decodificarlos con la misma lógica que usa la app. Lo corre
-- `sql/backfill_fecha_sesion.php`, que reusa app/ExcelDate.php.

SET NAMES utf8mb4;

ALTER TABLE datasets
    ADD COLUMN fecha_sesion DATE NULL
        COMMENT 'fecha real de la sesion/partido, derivada de los datos; NO la fecha de carga'
        AFTER player_column_name,
    ADD INDEX idx_datasets_fecha_sesion (club_id, fecha_sesion);
