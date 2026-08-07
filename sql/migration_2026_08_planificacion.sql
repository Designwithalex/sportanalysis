-- Migración: módulo de Planificación semanal.
-- Correr una sola vez sobre una base existente (phpMyAdmin). Bases nuevas: ya está en schema.sql.
--
-- QUÉ MODELA. El cuerpo técnico planifica la semana en % de un partido: "lunes al 60%, miércoles al
-- 70%, viernes al 60%". El 100% de referencia NO se guarda acá — se calcula de los partidos ya
-- cargados, por línea (ver app/Planificacion.php). Guardar el baseline sería congelarlo: cada
-- partido nuevo debería moverlo, y una copia vieja haría que el objetivo dejara de significar lo
-- que dice.
--
-- Lo que sí se guarda es la DECISIÓN: qué días, qué porcentaje y qué métricas mira este club.
--
-- AISLAMIENTO. Las tres tablas llevan club_id NOT NULL y FKs compuestas (id, club_id), igual que el
-- resto del dominio: los ids de día y de métrica llegan del cliente al editar.
--
-- ES DEL CLUB, NO PRIVADA. No hay `user_id`: la planificación de la semana es una sola y la ven
-- todos. Quién puede editarla lo resuelve el código (admin_club), no una columna.

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------------
-- planes_semana — una planificación por semana y por club.
-- ---------------------------------------------------------------------------
CREATE TABLE planes_semana (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    -- Siempre el LUNES de la semana. Normalizar la fecha al lunes es lo que permite que el UNIQUE
    -- de abajo signifique "una planificación por semana": con una fecha cualquiera, dos usuarios
    -- planificando la misma semana desde días distintos crearían dos planes que no se ven entre sí.
    fecha_inicio  DATE NOT NULL COMMENT 'lunes de la semana planificada',
    nota          VARCHAR(255) NULL COMMENT 'texto libre del cuerpo técnico (ej: "semana de descarga")',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_planes_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    UNIQUE KEY uq_planes_club_semana (club_id, fecha_inicio),
    UNIQUE KEY uq_planes_id_club (id, club_id) COMMENT 'target de las FKs compuestas de las hijas',
    INDEX idx_planes_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- plan_dias — el día planificado y su carga, en % de un partido.
-- ---------------------------------------------------------------------------
CREATE TABLE plan_dias (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    plan_id       INT UNSIGNED NOT NULL,
    fecha         DATE NOT NULL,
    -- SMALLINT y no DECIMAL: nadie planifica al 62,5%. El techo de 500 es una red contra el dedo
    -- pegado en el teclado, no una regla deportiva (>100% es normal en pretemporada).
    porcentaje    SMALLINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '% de la carga de un partido',
    nota          VARCHAR(255) NULL COMMENT 'ej: "unidades + juego", "solo activación"',
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_plan_dias_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_plan_dias_plan_club FOREIGN KEY (plan_id, club_id) REFERENCES planes_semana(id, club_id) ON DELETE CASCADE,
    CONSTRAINT ck_plan_dias_porcentaje CHECK (porcentaje <= 500),
    UNIQUE KEY uq_plan_dias_fecha (plan_id, fecha) COMMENT 'un solo renglón por día dentro del plan',
    INDEX idx_plan_dias_club (club_id),
    INDEX idx_plan_dias_fecha (club_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- plan_metricas — qué métricas mira este plan (la "preferencia" del ítem 3).
--
-- Van por PLAN y no en una tabla de preferencias aparte: así la semana nueva hereda las de la
-- anterior copiándolas, y una semana vieja sigue mostrando lo que se miró cuando se planificó,
-- en vez de reescribirse sola cuando alguien cambia la preferencia global.
-- ---------------------------------------------------------------------------
CREATE TABLE plan_metricas (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    club_id       INT UNSIGNED NOT NULL COMMENT 'club dueño de la fila',
    plan_id       INT UNSIGNED NOT NULL,
    -- Nombre de la columna tal cual viene del CSV del GPS ("Distance (metres)"). No es una FK a
    -- ningún catálogo: las columnas son las del archivo del club y cambian entre exportadores.
    columna       VARCHAR(150) NOT NULL,
    label         VARCHAR(80) NOT NULL COMMENT 'cómo se muestra (ej: "Distancia")',
    unidad        VARCHAR(16) NULL COMMENT 'ej: "m", "kg"',
    position      TINYINT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_plan_metricas_club FOREIGN KEY (club_id) REFERENCES clubs(id),
    CONSTRAINT fk_plan_metricas_plan_club FOREIGN KEY (plan_id, club_id) REFERENCES planes_semana(id, club_id) ON DELETE CASCADE,
    UNIQUE KEY uq_plan_metricas_col (plan_id, columna) COMMENT 'no repetir la misma métrica en un plan',
    INDEX idx_plan_metricas_club (club_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
