-- =============================================================================
-- Migración: niveles de permiso, verificación de identidad y vistas por usuario.
-- =============================================================================
--
-- QUÉ HACE
--   1) `users.nivel` — permisos reales (miembro / admin_club / superadmin) y
--      `users.status` suma 'rechazado' AL FINAL del ENUM y cambia el DEFAULT a
--      'pending' (el registro ya no habilita solo: hay que aprobarlo). El valor
--      se appendea, no se reordena — ver la nota en la PARTE 1.
--   2) Bootstrap manual del superadmin (designwithalexx@gmail.com).
--   3) Tabla `verificaciones` — pedidos de verificación de identidad de un socio
--      contra su club (instagram / nº de socio / link a una foto).
--   4) `views.user_id` — NULL = vista base del club, NOT NULL = vista privada.
--   5) Tabla `view_order` — orden de tabs POR USUARIO (`views.position` es una sola
--      columna compartida; con vistas privadas el orden dejó de ser representable).
--   6) Backfill opcional de `view_order` desde `views.position`.
--
-- ORDEN RESPECTO DE LAS OTRAS MIGRACIONES
--   REQUIERE: migration_2026_07_multiclub_a.sql (crea `clubs` y `users`, y agrega
--             `club_id` a las 10 tablas del dominio) + seed_clubs_urba.sql.
--   INDEPENDIENTE de migration_2026_07_multiclub_b.sql: esta migración se puede
--   correr ANTES o DESPUÉS de la B, indistintamente.
--     · No toca ninguna columna `club_id`, así que no interfiere con la PARTE 2
--       de la B (la que saca el `DEFAULT 1`).
--     · No toca `fk_views_player` / `fk_views_player_club`, así que no interfiere
--       con la PARTE 1 de la B.
--     · Las FKs nuevas (`fk_views_user`, las de `verificaciones` y `view_order`)
--       son SIMPLES y apuntan a `users(id)` / `clubs(id)` / `views(id)`, todas
--       claves primarias que existen antes y después de la B.
--   Recomendado igual: correrla ANTES de la B, para que la B siga siendo el último
--   paso del operativo multi-club y el `verify_isolation.sql` previo a la B ya
--   incluya los chequeos nuevos del BLOQUE E.
--
-- Re-ejecutable: `IF NOT EXISTS` / `IF EXISTS` en todo lo que MariaDB lo permite;
-- las FKs usan el patrón `DROP FOREIGN KEY IF EXISTS` + `ADD CONSTRAINT` de la
-- migración B. Leer igual la nota de la PARTE 1 antes de un segundo run.
--
-- MariaDB 11.8.8. Base chica (~3.3 MB), todos los ALTER son instantáneos.
-- LA BASE ESTÁ EN PRODUCCIÓN Y CON UN USUARIO ADENTRO: leer los avisos.
-- =============================================================================

SET NAMES utf8mb4;


-- ---------------------------------------------------------------------------
-- PARTE 0 — Snapshot de `users.status` ANTES de tocar el ENUM.
--
-- POR QUÉ EXISTE ESTA PARTE (leer, no es paranoia gratuita):
--   El `MODIFY` de la PARTE 1 no sólo agrega el valor 'rechazado': REORDENA la
--   lista, de ('active','pending') a ('pending','active','rechazado').
--   MariaDB guarda los ENUM internamente como el ÍNDICE del valor en la lista
--   (1, 2, 3...), no como texto. Mientras el ALTER se resuelva con una copia de
--   tabla (ALGORITHM=COPY) la conversión se hace POR TEXTO y 'active' sigue
--   siendo 'active'; y reordenar la lista fuerza justamente esa copia, así que
--   en teoría no hay riesgo. Pero la diferencia entre "en teoría" y "el único
--   usuario de producción amanece en 'pending' y no puede entrar" es una lista
--   ENUM, y no hay forma de probarlo en seco contra la base real.
--
--   Entonces: guardamos el status actual COMO TEXTO antes del ALTER y lo
--   restauramos después. Si MariaDB se portó bien, el UPDATE de la PARTE 1-bis
--   afecta 0 filas y no cambia nada. Si se portó mal, lo arregla.
--
--   Estado esperado hoy (verificado por SELECT de sólo lectura contra la base):
--   1 sola fila en `users` → id=6, club_id=1, designwithalexx@gmail.com,
--   status='active'. ESA FILA TIENE QUE QUEDAR EN 'active'.
--
-- OJO CON EL SEGUNDO RUN: si volvés a correr esta migración DESPUÉS de haber
-- cambiado el status de alguien a mano (aprobar/rechazar un alta), la PARTE 1-bis
-- lo revierte al valor congelado acá. Por eso, una vez verificada la migración,
-- corré el DROP de la PARTE 7.
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS bkp_users_status_2026_07 (
    user_id     INT UNSIGNED NOT NULL PRIMARY KEY,
    status      VARCHAR(20) NOT NULL COMMENT 'valor de users.status como TEXTO, previo al cambio de ENUM',
    tomado_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='temporal: red de seguridad del MODIFY de users.status. Borrar tras verificar (PARTE 7)';

-- INSERT IGNORE: en un segundo run NO pisa el snapshot original, que es el único
-- que refleja el estado pre-migración.
INSERT IGNORE INTO bkp_users_status_2026_07 (user_id, status)
SELECT id, CAST(status AS CHAR) FROM users;


-- ---------------------------------------------------------------------------
-- PARTE 1 — Permisos de usuario.
--
--   `nivel`  = permisos REALES. Es lo único que la app debe mirar para autorizar.
--   `rol`    = etiqueta libre del perfil ("Preparador físico", "Head coach").
--              Descriptiva, no gatea absolutamente nada. Se deja como está.
--
--   `status` suma 'rechazado' y su DEFAULT pasa a 'pending': de ahora en más un
--   alta nueva nace SIN acceso y alguien la tiene que aprobar. El DEFAULT sólo
--   afecta a filas FUTURAS; las existentes conservan su valor (ver PARTE 0/1-bis).
--
-- NOTA DE RE-EJECUCIÓN: `ADD COLUMN IF NOT EXISTS nivel` es idempotente, pero si
-- en un segundo run la columna ya existe, el `MODIFY` de status se ejecuta igual
-- (es idempotente también: deja la misma definición).
--
-- OJO, GAP DEL LADO PHP (no lo arregla esta migración, es fuera de sql/):
-- `public_html/registro.php` inserta el alta con status "active" HARDCODEADO, no
-- deja que actúe el DEFAULT. Mientras ese INSERT no saque la columna `status` de
-- la lista (o pase 'pending'), el nuevo DEFAULT no cambia nada en la práctica y
-- todo el que se registre entra habilitado, salteándose `verificacion.php`.
-- ---------------------------------------------------------------------------

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS nivel ENUM('miembro', 'admin_club', 'superadmin') NOT NULL DEFAULT 'miembro'
        COMMENT 'permisos reales; `rol` es la etiqueta libre del perfil y no gatea nada'
        AFTER rol,
    -- OJO CON EL ORDEN DEL ENUM: es ('active','pending') en producción y acá se APPENDEA
    -- 'rechazado' al final, sin reordenar. No es cosmético.
    --
    -- MariaDB guarda un ENUM como el ÍNDICE del valor, no como texto. Reordenar la lista a
    -- ('pending','active',...) haría que el índice 1 deje de ser 'active' y pase a ser 'pending'.
    -- La conversión debería hacerse por texto (el reordenamiento fuerza ALGORITHM=COPY), pero
    -- "debería" contra "el único usuario de producción amanece en pending y no puede entrar" no
    -- es una apuesta que valga la pena: appendeando, los índices existentes no se mueven y el
    -- único cambio real es el DEFAULT.
    --
    -- El orden del ENUM no tiene ningún significado en este producto: nada ordena por status.
    MODIFY IF EXISTS status ENUM('active', 'pending', 'rechazado') NOT NULL DEFAULT 'pending'
        COMMENT 'pending = alta creada, todavía sin aprobar; rechazado = alta denegada, no entra';

-- Índices de apoyo al flujo de aprobación (cola de pendientes por club) y a la
-- búsqueda de administradores. Baratos: la tabla tiene 1 fila.
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_club_status (club_id, status);
ALTER TABLE users ADD INDEX IF NOT EXISTS idx_users_nivel (nivel);


-- ---------------------------------------------------------------------------
-- PARTE 1-bis — VERIFICAR que ningún status se movió (ver PARTE 0).
--
-- Tiene que devolver 0 filas. Si devuelve alguna, el ALTER remapeó el ENUM y hay
-- que restaurar a mano desde bkp_users_status_2026_07 ANTES de seguir.
--
-- Es una verificación y no un UPDATE automático a propósito: un UPDATE que
-- restaura "al valor congelado" es correcto la primera vez, pero si la migración
-- se re-ejecuta después de haber aprobado altas a mano, revertiría esas
-- aprobaciones en silencio. Con el ENUM appendeado (no reordenado) el remapeo no
-- puede ocurrir, así que esto es sólo la red que confirma el supuesto.
-- ---------------------------------------------------------------------------

SELECT u.id, u.email, b.status AS status_antes, u.status AS status_ahora
FROM users u
JOIN bkp_users_status_2026_07 b ON b.user_id = u.id
WHERE u.status <> b.status;


-- ---------------------------------------------------------------------------
-- PARTE 2 — El superadmin.
--
-- BOOTSTRAP MANUAL, A PROPÓSITO. No hay ni debe haber un camino self-service para
-- llegar a 'superadmin': ninguna pantalla de registro, ningún endpoint, ningún
-- formulario. La única forma de crear el primer superadmin es este UPDATE corrido
-- a mano contra la base. Si mañana hace falta otro, se agrega acá (o desde el
-- panel, pero sólo un superadmin ya existente puede promover a otro).
--
-- El WHERE por email y no por id: el id (6) es un accidente del AUTO_INCREMENT.
-- ---------------------------------------------------------------------------

UPDATE users SET nivel = 'superadmin' WHERE email = 'designwithalexx@gmail.com';


-- ---------------------------------------------------------------------------
-- PARTE 3 — verificaciones.
--
-- Un pedido de verificación: el usuario dice "soy socio de este club" y aporta
-- pruebas blandas (instagram, nº de socio, link a una foto). Un admin_club o el
-- superadmin lo aprueba o lo rechaza con motivo.
--
-- `foto_url` ES UN LINK, NUNCA UN ARCHIVO SUBIDO. En Hostinger compartido no hay
-- almacenamiento de uploads de usuario para esto, y aceptar archivos abriría un
-- vector de subida arbitraria dentro de public_html. Se guarda la URL y listo.
--
-- Se permiten VARIOS pedidos por usuario (rechazado -> vuelve a intentar), por eso
-- no hay UNIQUE sobre user_id.
--
-- SOBRE LAS FKs: son SIMPLES, no compuestas con club_id como el resto del modelo.
--   · `revisada_por` es ON DELETE SET NULL, que InnoDB no admite compuesta cuando
--     alguna columna del lado hijo es NOT NULL (mismo motivo que las tres
--     excepciones documentadas en schema.sql).
--   · `user_id` podría ser compuesta contra users(id, club_id), pero users hoy no
--     tiene el UNIQUE (id, club_id) que haría falta como target. La coherencia
--     entre `verificaciones.club_id` y `users.club_id` queda a cargo de la app y
--     se audita en verify_isolation.sql (chequeo E03).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS verificaciones (
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
    -- UNIQUE y no un índice común: una solicitud por usuario. verificacion.php hace
    -- SELECT-then-UPDATE-or-INSERT, que con dos submits concurrentes puede insertar dos filas.
    -- El lector degrada bien (ORDER BY id DESC LIMIT 1) pero la cola del admin mostraría dos
    -- tarjetas de la misma persona. La restricción lo cierra en la base y no en la disciplina.
    UNIQUE KEY uq_verificaciones_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


-- ---------------------------------------------------------------------------
-- PARTE 4 — views.user_id (vistas privadas).
--
--   user_id IS NULL      -> vista BASE del club: la ven todos los usuarios del club.
--                           Es el estado de las 45 vistas que ya existen.
--   user_id IS NOT NULL  -> vista PRIVADA: sólo la ve ese usuario.
--
-- Las vistas tipo 'cluster' y 'player' son siempre base (user_id NULL): las genera
-- BaseViewGenerator para el club entero. Sólo las 'manual' pueden ser privadas.
-- No se puede expresar con un CHECK sin bloquear un futuro cambio de producto, así
-- que se audita en verify_isolation.sql (chequeo E04).
--
-- ### DECISIÓN: ON DELETE CASCADE. Razón: ###
--   La alternativa era SET NULL, y SET NULL acá es una FUGA DE PRIVACIDAD, no un
--   modo degradado. En este modelo `user_id IS NULL` NO significa "huérfana":
--   significa "vista base del club, visible para TODOS". O sea que borrar una
--   cuenta convertiría sus vistas privadas en vistas públicas del club, en
--   silencio y sin que nadie lo pida. Es exactamente el peor resultado posible de
--   una operación de borrado de cuenta.
--   CASCADE pierde datos, sí — pero pierde datos que eran de la cuenta borrada, y
--   perder datos de alguien que se fue es infinitamente más barato que exponer sus
--   vistas al resto del club.
--   También se evaluó RESTRICT (bloquear el borrado del usuario mientras tenga
--   vistas privadas): es seguro pero convierte "borrar un usuario" en un trámite
--   de dos pasos sin ganar nada, y en la práctica las cuentas casi no se borran —
--   un alta que no corresponde se marca status='rechazado', no se elimina.
--   Las vistas privadas borradas arrastran en cascada sus widgets, view_datasets,
--   custom_metrics, view_filters y sus filas de view_order, por las FKs que ya
--   tienen esas tablas contra views.
-- ---------------------------------------------------------------------------

ALTER TABLE views
    ADD COLUMN IF NOT EXISTS user_id INT UNSIGNED NULL
        COMMENT 'NULL = vista base del club (la ven todos); NOT NULL = privada de ese usuario'
        AFTER player_id,
    ADD INDEX IF NOT EXISTS idx_views_user (user_id);

ALTER TABLE views DROP FOREIGN KEY IF EXISTS fk_views_user;
ALTER TABLE views
    ADD CONSTRAINT fk_views_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE;


-- ---------------------------------------------------------------------------
-- PARTE 5 — view_order (orden de tabs por usuario).
--
-- POR QUÉ: `views.position` es UNA columna por vista, compartida por todo el club.
-- Servía cuando todas las vistas eran del club. Con vistas privadas mezcladas en
-- las mismas tabs, el orden que ve cada usuario es distinto y no hay forma de
-- guardarlo en una columna sola. `views.position` NO se borra: sigue siendo el
-- orden por defecto para un usuario que nunca reordenó nada.
--
-- Sin club_id a propósito: es una tabla de PREFERENCIA de usuario, no de dominio.
-- El club sale del user y de la view, que ya lo llevan. La contra es que la base
-- por sí sola no impide una fila que cruce (usuario de un club, vista de otro);
-- eso se audita en verify_isolation.sql (chequeo E02).
-- ---------------------------------------------------------------------------

CREATE TABLE IF NOT EXISTS view_order (
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
-- PARTE 6 — Backfill de view_order desde views.position.
--
-- Congela el orden de tabs que el usuario ve HOY, para que la salida de esta
-- migración no le reordene el dashboard. Sólo se siembran las vistas que ese
-- usuario puede ver: las base de su club (user_id NULL) y las suyas.
--
-- INSERT IGNORE: re-ejecutable, y en un segundo run NO pisa reordenamientos que
-- el usuario haya hecho después.
--
-- Si la app va a caer a `views.position` cuando un usuario no tiene filas en
-- view_order, este bloque es opcional y se puede borrar sin consecuencias.
-- Hoy son 1 usuario x 45 vistas = 45 filas.
-- ---------------------------------------------------------------------------

INSERT IGNORE INTO view_order (user_id, view_id, position)
SELECT u.id, v.id, v.position
FROM users u
JOIN views v
  ON v.club_id = u.club_id
 AND (v.user_id IS NULL OR v.user_id = u.id);


-- ---------------------------------------------------------------------------
-- PARTE 7 — Limpieza (correr DESPUÉS de verificar, no en la misma tanda).
--
-- Verificar primero (tiene que devolver 1 fila: id=6, active, superadmin):
--   SELECT id, club_id, email, rol, nivel, status FROM users;
--
-- Y que el backfill de tabs haya entrado (hoy: 45):
--   SELECT COUNT(*) FROM view_order;
--
-- Recién ahí, y para que un eventual re-run de esta migración no revierta
-- aprobaciones hechas a mano, soltar la red de seguridad:
--   DROP TABLE IF EXISTS bkp_users_status_2026_07;
-- ---------------------------------------------------------------------------
