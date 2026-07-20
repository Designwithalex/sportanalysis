-- Migración: vistas base sugeridas por IA (por cluster + overview por jugador).
-- Correr una sola vez sobre una base existente (phpMyAdmin). Bases nuevas: ignorar, schema.sql ya lo incluye.
--
-- Agrega metadata a `views` para distinguir las vistas base (generadas por IA a partir de un cluster
-- de datos o de un jugador) de las vistas manuales, y para poder regenerarlas sin duplicar.

SET NAMES utf8mb4;

ALTER TABLE views
    ADD COLUMN tipo ENUM('manual', 'cluster', 'player') NOT NULL DEFAULT 'manual'
        COMMENT 'manual = creada a mano; cluster = vista base de una categoria de datos; player = overview de un jugador'
        AFTER nombre,
    ADD COLUMN categoria ENUM('partidos', 'entrenamientos', 'fuerza', 'nutricion', 'otros') NULL
        COMMENT 'solo en vistas cluster: de que categoria de datasets es la vista'
        AFTER tipo,
    ADD COLUMN player_id INT UNSIGNED NULL
        COMMENT 'solo en vistas player: jugador cuyo overview muestra la vista'
        AFTER categoria,
    ADD CONSTRAINT fk_views_player FOREIGN KEY (player_id) REFERENCES players(id) ON DELETE CASCADE,
    ADD INDEX idx_views_tipo (tipo);
