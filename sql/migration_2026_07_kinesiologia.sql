-- =============================================================================
-- Migración: categoría `kinesiologia` + habilitaciones por especialidad.
-- =============================================================================
--
-- QUÉ HACE
--   1) `kinesiologia` se APPENDEA al ENUM de `datasets.categoria` y `views.categoria`.
--   2) Tabla `user_categorias` — qué categorías de datos tiene habilitadas cada
--      usuario. Las otorga el admin_club (o el superadmin) al aprobar el alta;
--      NADIE se auto-habilita. Un `miembro` con la habilitación de `kinesiologia`
--      carga datos de kinesiología sin ser admin.
--   3) Migración de datos: los 8 datasets de GEBA que están en `otros` y en
--      realidad son partidos pasan a `partidos`, y la vista cluster #9 ("Partidos",
--      hoy colgada de `otros`) se re-apunta a `partidos` conservando sus 9 widgets.
--   4) Habilitaciones del superadmin, por consistencia de datos.
--
-- ORDEN RESPECTO DE LAS OTRAS MIGRACIONES
--   REQUIERE, en este orden, las TRES ya aplicadas:
--     migration_2026_07_multiclub_a.sql  (crea `clubs`/`users`, agrega `club_id`)
--     migration_2026_07_multiclub_b.sql  (saca los DEFAULT 1 de `club_id`)
--     migration_2026_07_roles_vistas.sql (crea `users.nivel`, `views.user_id`, `view_order`)
--   Esta va DESPUÉS de las tres. Depende de `users.nivel` (PARTE 4 habla de
--   niveles) y de `views.user_id` (la PARTE 3 filtra las vistas base por
--   `user_id IS NULL`, igual que BaseViewGenerator).
--   No interfiere con ninguna FK ni con ninguna columna `club_id`.
--
-- RE-EJECUTABLE. `IF NOT EXISTS` / `MODIFY` donde MariaDB lo permite; los UPDATE
-- de la PARTE 3 tienen condiciones que en un segundo run afectan 0 filas.
--
-- MariaDB 11.8.8, base chica: todos los ALTER son instantáneos.
-- LA BASE ESTÁ EN PRODUCCIÓN CON DATOS REALES DE UN CLUB.
-- La PARTE 3 es la única destructiva: LEER SUS SELECT DE PREVIEW ANTES DE CORRERLA.
-- Se puede correr por partes: 1, 2 y 4 son aditivas y seguras; la 3 aparte.
-- =============================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------------
-- PARTE 1 — `kinesiologia` en el ENUM de categorías.
--
-- ### APPENDEAR AL FINAL. NUNCA REORDENAR. ###
--   MariaDB guarda un ENUM como el ÍNDICE del valor dentro de la lista (1, 2, 3…),
--   no como texto. Si alguien "ordena alfabéticamente" esta lista, el índice 1 deja
--   de ser 'partidos' y todas las filas existentes quedan apuntando a otra
--   categoría. La conversión DEBERÍA hacerse por texto (reordenar fuerza
--   ALGORITHM=COPY), pero ya tomamos esta decisión una vez con `users.status` (ver
--   PARTE 1 de migration_2026_07_roles_vistas.sql) y el criterio es el mismo:
--   appendeando, ningún índice existente se mueve y el único cambio es que aparece
--   un valor nuevo al final.
--   El orden del ENUM no significa nada en este producto: nada ordena por categoría
--   (los labels y su orden de presentación viven en el PHP, no acá).
--
-- LAS TRES COLUMNAS TIENEN QUE TENER LA MISMA LISTA, EN EL MISMO ORDEN:
--   `datasets.categoria`, `views.categoria` y `user_categorias.categoria` (PARTE 2).
--   MySQL/MariaDB no tiene tipos de dominio compartidos, así que la única forma de
--   que no diverjan es tocarlas SIEMPRE juntas, en la misma migración. Si mañana
--   se agrega otra categoría: se appendea a las TRES acá abajo, no a una sola.
--
-- Efecto colateral esperado y deseado: `datasets.categoria` acepta el valor nuevo,
-- pero el ENUM no lo publica en ningún lado — la lista blanca del lado PHP está
-- hardcodeada en api/datasets.php, api/manual_dataset.php, steps/datos.php,
-- steps/carga_manual.php, steps/analysis.php, app/BaseViewGenerator.php y
-- js/widgets.js. Sin tocar esos archivos, `kinesiologia` existe en la base y no se
-- puede elegir desde la UI. Eso es fuera de sql/ y no lo hace esta migración.
-- ---------------------------------------------------------------------------

ALTER TABLE datasets
    MODIFY IF EXISTS categoria
        ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros', 'kinesiologia')
        NOT NULL DEFAULT 'otros'
        COMMENT 'bucket del Paso Datos: cada partido/sesion se sube como su propio dataset dentro de una categoria';

ALTER TABLE views
    MODIFY IF EXISTS categoria
        ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros', 'kinesiologia')
        NULL
        COMMENT 'solo en vistas cluster: de que categoria de datasets es la vista';

-- Verificación: todas las columnas listadas tienen que devolver EXACTAMENTE la
-- misma lista, en el mismo orden, con 'kinesiologia' al final. En este punto de la
-- primera corrida devuelve 2 filas (datasets, views); después de la PARTE 2 —o en
-- un segundo run— devuelve 3, con user_categorias.
SELECT TABLE_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND COLUMN_NAME = 'categoria'
  AND TABLE_NAME IN ('datasets', 'views', 'user_categorias')
ORDER BY TABLE_NAME;


-- ---------------------------------------------------------------------------
-- PARTE 2 — user_categorias: habilitaciones por especialidad.
--
-- QUÉ MODELA: "el usuario X puede trabajar con datos de la categoría Y". Una fila
-- por habilitación otorgada. Sin fila = sin permiso. No hay filas de "denegado":
-- la ausencia es la denegación, así que revocar es un DELETE.
--
-- QUIÉN LAS OTORGA: un admin_club de su club, o el superadmin, típicamente al
-- aprobar el alta (users.status pending -> active). Nadie se auto-habilita: no
-- debe existir ningún endpoint donde el propio usuario inserte acá, igual que no
-- existe camino self-service a `nivel = 'superadmin'`.
--
-- QUIÉN NO LAS NECESITA: `superadmin` y `admin_club` pueden con todo por `nivel`;
-- el chequeo de la app tiene que ser "es admin_club/superadmin  O  tiene la fila".
-- Igual les cargamos las filas al superadmin en la PARTE 4 — ver el porqué ahí.
--
-- ### DECISIÓN: ESTA TABLA NO LLEVA `club_id`. Razón: ###
--   La regla de oro del esquema ("toda tabla del dominio lleva club_id NOT NULL")
--   existe para poder colgar FKs COMPUESTAS (fk_col, club_id) -> (id, club_id), que
--   son las que hacen estructuralmente imposible cruzar clubes. Acá ese beneficio
--   NO se puede obtener: el padre sería `users`, y `users` no tiene el UNIQUE
--   (id, club_id) que haría falta como target — exactamente el mismo motivo por el
--   que `verificaciones.user_id` quedó con FK simple. O sea que un `club_id` acá
--   sería el costo de la denormalización (una segunda copia del club, que puede
--   divergir de `users.club_id` por cualquier bug del PHP) sin nada de la garantía
--   que la justifica en el resto del modelo.
--   Además esto NO es una tabla de dominio: es un ATRIBUTO DEL USUARIO, del mismo
--   tipo que `view_order` (que también omite club_id, por escrito y a propósito).
--   Una habilitación no "pertenece a un club": pertenece a una persona. Si esa
--   persona alguna vez se mueve de club, un club_id copiado queda silenciosamente
--   viejo, mientras que el derivado de `users.club_id` la sigue solo.
--   El club, cuando haga falta filtrar (ej. "todos los kinesiólogos de mi club"),
--   sale de un JOIN a users: `JOIN users u ON u.id = uc.user_id WHERE u.club_id = ?`.
--   Contrapartida asumida: la base por sí sola no impide una fila rara. Se audita
--   en verify_isolation.sql, BLOQUE F (chequeos F01/F02/F03).
--
-- `otorgada_por` es ON DELETE SET NULL, no CASCADE: si mañana se borra la cuenta
-- del admin que otorgó los permisos, se pierde el rastro de quién los dio, pero
-- NO se revocan las habilitaciones de media docena de personas. Borrar a un admin
-- no puede dejar al plantel sin poder cargar datos.
-- NULL también es el valor de las habilitaciones sembradas por esta migración
-- (no las otorgó una persona).
-- `user_id` sí es CASCADE: sin usuario, la habilitación no significa nada.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS user_categorias (
    user_id       INT UNSIGNED NOT NULL COMMENT 'usuario habilitado',
    -- MISMA lista y MISMO orden que datasets.categoria y views.categoria (PARTE 1).
    categoria     ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros', 'kinesiologia') NOT NULL
                  COMMENT 'categoria de datos habilitada; mismo ENUM que datasets.categoria y views.categoria',
    otorgada_por  INT UNSIGNED NULL
                  COMMENT 'admin_club/superadmin que la asignó. NULL = sembrada por migración, o el otorgante fue borrado',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    -- PK compuesta: una habilitación por (usuario, categoría). Hace el otorgar
    -- idempotente (INSERT IGNORE / ON DUPLICATE KEY) y el revocar un DELETE exacto.
    PRIMARY KEY (user_id, categoria),
    CONSTRAINT fk_user_categorias_user      FOREIGN KEY (user_id)      REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_categorias_otorgante FOREIGN KEY (otorgada_por) REFERENCES users(id) ON DELETE SET NULL,
    INDEX idx_user_categorias_categoria (categoria) COMMENT 'quiénes están habilitados en una categoría',
    INDEX idx_user_categorias_otorgante (otorgada_por) COMMENT 'lado hijo de fk_user_categorias_otorgante'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='habilitaciones por especialidad: qué categorías de datos puede tocar cada usuario';

-- Este MODIFY es el que hace converger la tabla en un SEGUNDO run: si
-- `user_categorias` ya existía de una corrida anterior, el CREATE TABLE IF NOT
-- EXISTS de arriba no la toca y su ENUM se quedaría viejo. Correr el MODIFY suelto
-- garantiza que las TRES columnas terminan con la misma lista, se haya creado la
-- tabla en este run o en otro. Es idempotente: deja siempre la misma definición.
ALTER TABLE user_categorias
    MODIFY IF EXISTS categoria
        ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros', 'kinesiologia') NOT NULL
        COMMENT 'categoria de datos habilitada; mismo ENUM que datasets.categoria y views.categoria';


-- ===========================================================================
-- PARTE 3 — MIGRACIÓN DE DATOS.  *** LA PARTE CON RIESGO. LEER ANTES. ***
-- ===========================================================================
--
-- CONTEXTO: los 8 partidos de GEBA se cargaron en la categoría `otros` (era el
-- DEFAULT y la UI no ofrecía otra cosa al momento de la carga), y la vista cluster
-- que el PF ve como "Partidos" (id 9, con 9 widgets) quedó colgada de `otros`.
-- Esta parte los pone donde corresponde, SIN regenerar nada por IA y sin tocar un
-- solo widget.
--
-- POR QUÉ TODO VA ACOTADO A `club_id = 1` (GEBA): hoy hay un solo club, pero
-- `otros` es la categoría por defecto de CUALQUIER carga. Un UPDATE sin club_id
-- se llevaría puestos, mañana, los datasets legítimamente "otros" de otro club.
-- El id de club está hardcodeado a propósito: esta migración corrige datos de un
-- club concreto, no es una regla general.
--
-- ---------------------------------------------------------------------------
-- 3.0 — PREVIEW. Correr SOLO esto primero y LEER LA SALIDA.
--       Si algo no coincide con lo esperado, PARAR y no correr los UPDATE.
-- ---------------------------------------------------------------------------

-- (a) Los datasets que se van a mover. ESPERADO: exactamente 8 filas, ids 3..10:
--     F1vs San albano, F2vsPucara, F3vsOlivos, F4vsSanLuis, F5vsCurupa,
--     F6vsLomas, F7vsHurling, Gye vs San Andres.
--     Si aparece alguno que NO es un partido, sacarlo del alcance (ver 3.1) antes
--     de seguir: una vez movido, no hay forma automática de saber cuál era cuál.
SELECT id, club_id, categoria, nombre, original_filename, uploaded_at
FROM datasets
WHERE club_id = 1 AND categoria = 'otros'
ORDER BY id;

-- (b) Datasets en `otros` de OTROS clubes: NO se tocan. ESPERADO hoy: 0 filas.
SELECT id, club_id, nombre FROM datasets WHERE categoria = 'otros' AND club_id <> 1 ORDER BY club_id, id;

-- (c) La vista a re-apuntar y su contenido. ESPERADO: 1 fila,
--     id=9, club_id=1, tipo='cluster', categoria='otros', user_id=NULL, 9 widgets.
SELECT v.id, v.club_id, v.tipo, v.categoria, v.user_id, v.nombre, v.position,
       (SELECT COUNT(*) FROM widgets w        WHERE w.view_id = v.id) AS widgets,
       (SELECT COUNT(*) FROM view_datasets vd WHERE vd.view_id = v.id) AS datasets_asociados
FROM views v
WHERE v.id = 9;

-- (d) ### EL CHEQUEO QUE DECIDE SI LA MIGRACIÓN ES SEGURA ###
--     Todas las vistas cluster del club 1. ESPERADO: 2 filas — #9 en 'otros' y
--     #47 ("Fuerza") en 'fuerza'.
--     LO QUE NO PUEDE HABER: otra vista cluster de club 1 ya en categoria='partidos'.
--     Si la hubiera, DESPUÉS del UPDATE quedarían DOS clusters 'partidos' del mismo
--     club, y el upsert de BaseViewGenerator — que resuelve con
--     `WHERE tipo='cluster' AND categoria=? AND club_id=? AND user_id IS NULL LIMIT 1` —
--     pasaría a elegir una de las dos de forma no determinística. Ese SELECT es el
--     target de tres DELETE (widgets, view_datasets, view_filters): regenerar las
--     vistas base vaciaría la vista equivocada.
--     El UPDATE de 3.2 se protege solo contra esto, pero mirá la salida igual.
SELECT id, club_id, tipo, categoria, user_id, nombre,
       (SELECT COUNT(*) FROM widgets w WHERE w.view_id = views.id) AS widgets
FROM views
WHERE tipo = 'cluster' AND club_id = 1
ORDER BY categoria, id;

-- (e) Cualquier OTRA vista (de cualquier tipo) que apunte a `otros` en el club 1.
--     `views.categoria` sólo tiene sentido en las cluster; si acá aparece una
--     'manual' o 'player' con categoría, es basura previa y NO la toca esta
--     migración (quedaría apuntando a una categoría que se vació — inocuo, porque
--     nada la lee, pero conviene saberlo).
SELECT id, club_id, tipo, categoria, user_id, nombre
FROM views
WHERE club_id = 1 AND categoria = 'otros' AND id <> 9
ORDER BY id;


-- ---------------------------------------------------------------------------
-- 3.1 — Mover los datasets de `otros` a `partidos`.
--
-- Idempotente: en un segundo run no queda ninguna fila con categoria='otros' en el
-- club 1, así que afecta 0 filas. No hay forma de que "vuelva a mover" algo.
--
-- Si el preview (a) mostró algún dataset que NO es un partido, NO corras esto tal
-- cual: agregá la exclusión explícita, por ejemplo
--     AND id NOT IN (<ids que se quedan en otros>)
-- ESPERADO: 8 filas afectadas (0 en un segundo run).
-- ---------------------------------------------------------------------------

UPDATE datasets
SET categoria = 'partidos'
WHERE club_id = 1
  AND categoria = 'otros';


-- ---------------------------------------------------------------------------
-- 3.2 — Re-apuntar la vista cluster #9 de `otros` a `partidos`.
--
-- Conserva sus 9 widgets, sus view_datasets y sus view_filters: sólo cambia la
-- etiqueta de categoría de la vista. NO se borra ni se regenera nada.
--
-- Las condiciones no son decorativas:
--   · id = 9 AND club_id = 1     → un club de otro tenant no puede tener el id 9
--                                  ajeno, pero el par se verifica igual.
--   · tipo = 'cluster'           → si alguien cambió el tipo de la vista, no la tocamos.
--   · categoria = 'otros'        → hace el UPDATE idempotente (2º run: 0 filas).
--   · user_id IS NULL            → es la clave con la que BaseViewGenerator busca
--                                  las vistas base; si esta dejó de serlo, parar.
--   · el subselect = 0          → NO crear un segundo cluster 'partidos' en el club.
--     MariaDB no deja leer directamente la tabla que se está actualizando (error
--     1093), así que el conteo va envuelto en una tabla derivada. La derivada lleva
--     un COUNT(*) A PROPÓSITO, no un `SELECT 1`: una derivada sin agregación el
--     optimizador la MERGEA con la consulta externa (derived_merge está en on por
--     defecto) y vuelve a caer en el 1093. Con un agregado adentro no puede
--     mergearla: la materializa antes del UPDATE y por lo tanto ve el estado previo,
--     que es exactamente lo que queremos chequear.
--
-- ESPERADO: 1 fila afectada. Si afecta 0 en el PRIMER run, NO fue idempotencia:
-- alguna de las condiciones falló. Volvé al preview (c) y (d) antes de seguir.
-- ---------------------------------------------------------------------------

UPDATE views
SET categoria = 'partidos'
WHERE id = 9
  AND club_id = 1
  AND tipo = 'cluster'
  AND categoria = 'otros'
  AND user_id IS NULL
  AND (
      SELECT ya_existen FROM (
          SELECT COUNT(*) AS ya_existen
          FROM views
          WHERE tipo = 'cluster' AND categoria = 'partidos'
            AND club_id = 1 AND user_id IS NULL
      ) chequeo_duplicado
  ) = 0;


-- ---------------------------------------------------------------------------
-- 3.3 — VERIFICACIÓN POST-MIGRACIÓN. Correr y leer.
-- ---------------------------------------------------------------------------

-- (a) Cómo quedaron repartidos los datasets. ESPERADO para el club 1:
--     partidos = 8, fuerza = 1, y `otros` YA NO APARECE.
SELECT club_id, categoria, COUNT(*) AS datasets
FROM datasets
GROUP BY club_id, categoria
ORDER BY club_id, categoria;

-- (b) La vista #9, ya en 'partidos', con sus 9 widgets intactos.
SELECT v.id, v.club_id, v.tipo, v.categoria, v.user_id, v.nombre,
       (SELECT COUNT(*) FROM widgets w        WHERE w.view_id = v.id) AS widgets,
       (SELECT COUNT(*) FROM view_datasets vd WHERE vd.view_id = v.id) AS datasets_asociados
FROM views v
WHERE v.id = 9;

-- (c) ### LA TRAMPA DEL LIMIT 1 ### Vistas cluster duplicadas por (club, categoría).
--     TIENE QUE DEVOLVER 0 FILAS. Cualquier fila acá significa que hay dos vistas
--     base compitiendo por la misma categoría en el mismo club y que el próximo
--     "generar vistas base" puede vaciar la equivocada. Se resuelve borrando la
--     sobrante a mano (la que no tiene widgets, o la más nueva).
--     Mismo chequeo, en formato auditoría, en verify_isolation.sql (E05).
SELECT club_id, categoria, COUNT(*) AS cuantas, GROUP_CONCAT(id ORDER BY id) AS view_ids
FROM views
WHERE tipo = 'cluster' AND categoria IS NOT NULL AND user_id IS NULL
GROUP BY club_id, categoria
HAVING COUNT(*) > 1;

-- (d) Ningún dataset del club 1 quedó atrás en `otros`. ESPERADO: 0.
SELECT COUNT(*) AS datasets_otros_club1 FROM datasets WHERE club_id = 1 AND categoria = 'otros';


-- ---------------------------------------------------------------------------
-- PARTE 4 — Habilitaciones del superadmin.
--
-- POR QUÉ, SI UN SUPERADMIN PUEDE TODO IGUAL: no las necesita. La autorización se
-- resuelve por `users.nivel` — un `superadmin` (y un `admin_club` dentro de su
-- club) pasa cualquier chequeo de categoría sin mirar esta tabla, y el gate de la
-- app tiene que estar escrito así:
--     nivel IN ('superadmin','admin_club')  OR  EXISTS (fila en user_categorias)
-- Se siembran igual por CONSISTENCIA DE DATOS: que el panel de permisos muestre
-- las casillas tildadas en vez de un usuario aparentemente sin ninguna
-- habilitación, y que cualquier consulta que se escriba sobre `user_categorias`
-- (reportes, "quién puede ver kinesiología") lo incluya sin un OR especial. Si el
-- día de mañana a esta cuenta se le baja el nivel, no se queda sin nada.
--
-- Las cuatro categorías son las de especialidad. `entrenamientos` y `otros` no se
-- otorgan: `otros` queda vacía después de la PARTE 3 y `entrenamientos` todavía no
-- tiene datos; cuando las haga falta, se otorgan desde el panel como cualquier otra.
--
-- WHERE por email y no por id, igual que la PARTE 2 de roles_vistas.sql: el id (6)
-- es un accidente del AUTO_INCREMENT y el email es la identidad estable.
--
-- `otorgada_por` NULL = no la otorgó una persona, la sembró esta migración.
--
-- INSERT IGNORE: idempotente. En un segundo run no duplica (choca con la PK
-- compuesta) y, más importante, NO pisa un `otorgada_por` real ni un `created_at`
-- si mientras tanto alguien re-otorgó estas categorías desde el panel.
-- ESPERADO: 4 filas insertadas (0 en un segundo run).
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO user_categorias (user_id, categoria, otorgada_por)
SELECT u.id, c.categoria, NULL
FROM users u
JOIN (
              SELECT 'partidos'     AS categoria
    UNION ALL SELECT 'fuerza'
    UNION ALL SELECT 'nutricion'
    UNION ALL SELECT 'kinesiologia'
) c
WHERE u.email = 'designwithalexx@gmail.com';

-- Verificación. ESPERADO: 4 filas (fuerza, kinesiologia, nutricion, partidos)
-- para id=6 / nivel='superadmin'.
SELECT u.id, u.email, u.nivel, uc.categoria, uc.otorgada_por, uc.created_at
FROM users u
JOIN user_categorias uc ON uc.user_id = u.id
ORDER BY u.id, uc.categoria;


-- ---------------------------------------------------------------------------
-- PARTE 5 — OPCIONAL, VA COMENTADA A PROPÓSITO. Cerrar la trampa del LIMIT 1.
--
-- La causa de fondo del riesgo de la PARTE 3 es que nada impide dos vistas cluster
-- de la misma categoría en el mismo club. Se puede cerrar con un UNIQUE:
--
--     ALTER TABLE views ADD UNIQUE KEY uq_views_cluster_categoria (club_id, tipo, categoria);
--
-- Funciona porque InnoDB considera los NULL DISTINTOS entre sí en un índice único:
-- las vistas 'manual' y 'player' tienen `categoria` NULL, así que no quedan
-- limitadas a una por club; sólo las 'cluster' (categoria NOT NULL) quedan a una
-- por (club, categoría), que es exactamente lo que asume BaseViewGenerator.
--
-- NO se corre en esta migración por dos motivos:
--   1) Si hoy hubiera duplicados, el ALTER falla a mitad de la tanda. El orden sano
--      es: correr la PARTE 3, verificar que 3.3(c) y el chequeo E05 de
--      verify_isolation.sql den 0, y RECIÉN AHÍ agregar el UNIQUE.
--   2) Convierte un bug silencioso en un error visible del lado PHP: si algún día
--      el generador intenta crear una cluster duplicada, hoy la crea y rompe
--      después; con el UNIQUE, el INSERT tira una excepción PDO que el código de
--      base_views.php tiene que saber mostrar. Es mejor, pero es un cambio de
--      comportamiento y hay que probarlo, no meterlo de contrabando en la misma
--      corrida que la migración de datos.
--
-- Si se decide correrlo, usar la forma re-ejecutable que MariaDB acepta:
--     ALTER TABLE views ADD UNIQUE KEY IF NOT EXISTS uq_views_cluster_categoria (club_id, tipo, categoria);
-- ---------------------------------------------------------------------------


-- ---------------------------------------------------------------------------
-- DESPUÉS DE CORRER: correr verify_isolation.sql entero. El BLOQUE F es nuevo y
-- cubre esta migración (habilitaciones huérfanas, otorgantes de otro club, y las
-- vistas cluster duplicadas del BLOQUE E). Todo tiene que dar filas_malas = 0.
--
-- PENDIENTE FUERA DE sql/ (no lo hace esta migración):
--   · Sumar 'kinesiologia' a las listas blancas hardcodeadas del PHP/JS:
--     api/datasets.php, api/manual_dataset.php, steps/datos.php,
--     steps/carga_manual.php, steps/analysis.php, app/BaseViewGenerator.php
--     (CATEGORIA_LABELS) y js/widgets.js (catLabels). Sin eso, la categoría existe
--     en la base pero no se puede elegir ni se muestra con nombre.
--   · Escribir el gate de permisos contra `user_categorias` (ver PARTE 4) y la
--     pantalla del admin_club para otorgarlas al aprobar un alta.
--   · Ojo: con los datasets ya en `partidos`, apretar "generar vistas base" para
--     Partidos va a encontrar la vista #9 y REEMPLAZAR sus 9 widgets por los que
--     proponga la IA. Es el comportamiento de siempre del upsert (antes lo hacía
--     sobre la categoría 'otros'), pero ahora el botón está a un click de la vista
--     que el PF usa todos los días. No es un bug de esta migración; es lo que hay
--     que saber antes de tocar ese botón.
-- ---------------------------------------------------------------------------
