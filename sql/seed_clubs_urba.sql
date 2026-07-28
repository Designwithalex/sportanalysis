-- =============================================================================
-- seed_clubs_urba.sql — clubes para el dropdown de registro (multi-club).
-- =============================================================================
--
-- ORIGEN DE LA LISTA
--   Extraída de Wikipedia, "Anexo:Clubes de rugby de Argentina". NO es el padrón
--   oficial de la URBA. La URBA declara ~80 clubes afiliados; acá quedan 70 tras
--   deduplicar, así que faltan clubes y sobra al menos uno que no es URBA.
--
--   >>> REVISAR ESTA LISTA A MANO ANTES DE CORRERLA. <<<
--   Es el dropdown que va a ver todo usuario que se registre: cada nombre mal
--   escrito o club faltante es fricción directa en el alta.
--
-- DEDUPLICACIÓN APLICADA (la lista cruda traía 72 entradas, quedan 70):
--   - "Club San Andrés"        == "San Andrés"     -> se conserva "Club San Andrés"
--   - "Club Varela Junior Rugby" == "Varela Junior" -> se conserva "Club Varela Junior Rugby"
--
-- PARES QUE PARECEN DUPLICADOS PERO NO LO SON (se conservan los dos):
--   - "Club Atlético de San Isidro" (CASI) vs "San Isidro Club" (SIC)
--   - "Club de Gimnasia y Esgrima" (GEBA) vs "Club Gimnasia y Esgrima de Ituzaingó"
--   - "Belgrano Athletic Club" vs "Club Manuel Belgrano"
--   - "Centro Naval" vs "Liceo Naval"
--   - "Hurling Club" (Hurlingham) vs "Retiro Rugby Hurlingham"
--   - "Club Universitario de Buenos Aires" vs "Círculo Universitario de Quilmes"
--     vs "Universitario de La Plata"
--   - "Club San Martín" vs "Círculo de ex Cadetes del Liceo Militar Gral San Martín"
--
-- OJO — POSIBLE INTRUSO:
--   "Club Atlético del Rosario" (Plaza Jewell) NO es afiliado de la URBA, juega en
--   la Unión de Rugby de Rosario. Viene de la lista de Wikipedia, no lo saqué por
--   mi cuenta. Si el dropdown es sólo URBA, borrar esa fila antes de correr.
--
-- ORDEN E IDs
--   GEBA se inserta con id = 1 EXPLÍCITO. Es obligatorio: la migración
--   migration_2026_07_multiclub_a.sql hace backfill de todos los datos ya cargados
--   con club_id = 1. Si GEBA no queda en el id 1, los 486 dataset_rows / 44 vistas /
--   278 widgets existentes quedan asignados a otro club.
--   El resto va sin id (AUTO_INCREMENT desde 2), en orden alfabético por nombre.
--
-- CUÁNDO CORRERLO
--   Inmediatamente DESPUÉS de la Parte 1 de migration_2026_07_multiclub_a.sql
--   (que crea la tabla `clubs`) y ANTES de migration_2026_07_multiclub_b.sql.
--   Corre una sola vez: `slug` es UNIQUE, un segundo run falla con duplicate key.
-- =============================================================================

SET NAMES utf8mb4;

-- GEBA primero y con id fijo (ver nota arriba).
INSERT INTO clubs (id, nombre, slug) VALUES
    (1, 'Club de Gimnasia y Esgrima', 'geba');

-- Resto de los clubes, alfabético por nombre.
INSERT INTO clubs (nombre, slug) VALUES
    ('Albatros Rugby Club',                                       'albatros'),
    ('Asociación Alumni',                                         'alumni'),
    ('Asociación Deportiva Francesa',                             'deportiva-francesa'),
    ('Asociación Lanús Rugby Club',                               'lanus'),
    ('Ateneo Cultural y Deportivo Don Bosco',                     'don-bosco'),
    ('Atlético y Progreso',                                       'atletico-y-progreso'),
    ('Banco Hipotecario',                                         'banco-hipotecario'),
    ('Belgrano Athletic Club',                                    'belgrano-athletic'),
    ('Buenos Aires Cricket & Rugby Club',                         'buenos-aires-cricket'),
    ('Centro Naval',                                              'centro-naval'),
    ('Círculo de ex Cadetes del Liceo Militar Gral San Martín',   'ex-cadetes-liceo-militar'),
    ('Círculo Universitario de Quilmes',                          'universitario-de-quilmes'),
    ('Club Argentino de Rugby',                                   'argentino-de-rugby'),
    ('Club Atlético Banco de la Nación Argentina',                'banco-nacion'),
    ('Club Atlético de San Isidro',                               'casi'),
    ('Club Atlético del Rosario',                                 'atletico-del-rosario'),
    ('Club Atlético Porteño',                                     'porteno'),
    ('Club Beromama',                                             'beromama'),
    ('Club Champagnat',                                           'champagnat'),
    ('Club Ciudad de Buenos Aires',                               'ciudad-de-buenos-aires'),
    ('Club Ciudad de Campana',                                    'ciudad-de-campana'),
    ('Club Daom',                                                 'daom'),
    ('Club de la Municipalidad de Vicente López',                 'municipalidad-vicente-lopez'),
    ('Club de Rugby Los Tilos',                                   'los-tilos'),
    ('Club Gimnasia y Esgrima de Ituzaingó',                      'gimnasia-y-esgrima-ituzaingo'),
    ('Club Italiano Rugby',                                       'italiano'),
    ('Club Manuel Belgrano',                                      'manuel-belgrano'),
    ('Club Pucará',                                               'pucara'),
    ('Club Regatas de Bella Vista',                               'regatas-bella-vista'),
    ('Club San Albano',                                           'san-albano'),
    ('Club San Andrés',                                           'san-andres'),
    ('Club San Carlos',                                           'san-carlos'),
    ('Club San Cirano',                                           'san-cirano'),
    ('Club San Fernando',                                         'san-fernando'),
    ('Club San Luis',                                             'san-luis'),
    ('Club San Martín',                                           'san-martin'),
    ('Club Universitario de Buenos Aires',                        'cuba'),
    ('Club Varela Junior Rugby',                                  'varela-junior'),
    ('Curupaytí Club de Rugby',                                   'curupayti'),
    ('Delta Rugby Club',                                          'delta'),
    ('Hindú Club',                                                'hindu'),
    ('Hurling Club',                                              'hurling'),
    ('La Salle',                                                  'la-salle'),
    ('Las Cañas',                                                 'las-canas'),
    ('Liceo Naval',                                               'liceo-naval'),
    ('Lomas Athletic Club',                                       'lomas-athletic'),
    ('Los Cedros',                                                'los-cedros'),
    ('Los Pinos',                                                 'los-pinos'),
    ('Luján Rugby Club',                                          'lujan'),
    ('Mariano Moreno',                                            'mariano-moreno'),
    ('Monte Grande',                                              'monte-grande'),
    ('Náutico Arsenal Zárate',                                    'nautico-arsenal-zarate'),
    ('Newman',                                                    'newman'),
    ('Obras Sanitarias',                                          'obras-sanitarias'),
    ('Olivos Rugby Club',                                         'olivos'),
    ('Pueyrredón Rugby Club',                                     'pueyrredon'),
    ('Retiro Rugby Hurlingham',                                   'retiro'),
    ('Rugby Club Los Matreros',                                   'los-matreros'),
    ('Rugby Club San Marcos',                                     'san-marcos'),
    ('San Antonio de Padua',                                      'san-antonio-de-padua'),
    ('San Isidro Club',                                           'sic'),
    ('San José Rugby Club',                                       'san-jose'),
    ('San Miguel Rugby and Hockey Club',                          'san-miguel'),
    ('San Patricio',                                              'san-patricio'),
    ('Sociedad Hebraica Argentina',                               'hebraica'),
    ('Sociedad Italiana de Tiro al Segno',                        'tiro-al-segno'),
    ('St. Brendan''s Rugby Club',                                 'st-brendans'),
    ('Tigre Rugby Club',                                          'tigre'),
    ('Universitario de La Plata',                                 'universitario-la-plata');

-- Chequeo post-seed: deben dar 70 clubes y GEBA en el id 1.
-- SELECT COUNT(*) AS total_clubes FROM clubs;
-- SELECT id, nombre, slug FROM clubs WHERE id = 1;
