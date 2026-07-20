-- Migración: orden manual de las vistas (tabs reordenables por arrastre).
-- Correr una sola vez sobre una base existente (phpMyAdmin). Bases nuevas: ignorar, schema.sql ya lo incluye.

SET NAMES utf8mb4;

ALTER TABLE views
    ADD COLUMN position INT UNSIGNED NOT NULL DEFAULT 0 AFTER player_id,
    ADD INDEX idx_views_position (position);

-- Backfill: respetar el orden actual (por created_at) como posición inicial.
SET @i := 0;
UPDATE views SET position = (@i := @i + 1) ORDER BY created_at;
