# PerformanceLab — MVP

## Contexto

**PerformanceLab**: MVP para testear con un solo club de rugby real. Producto separado de GimnasiaLabs (otro proyecto del usuario, en Next.js/Supabase), pero comparte principios de diseño de datos.

**Qué hace:** un preparador físico sube el plantel del equipo, sube datasets en CSV con cualquier estructura (partidos, entrenamientos, fuerza, nutrición — lo que sea), crea "vistas" describiendo en lenguaje natural qué quiere ver, y la IA genera una grilla de gráficos/tablas para esa vista. Después, el usuario la sigue completando de dos formas seguras: a mano (agregar/quitar widgets, crear métricas propias, filtros) o pidiéndole a la IA un ajuste puntual a un widget específico.

## Stack técnico (definido, no re-discutir)

- **Backend:** PHP 8+ puro con PDO, sin framework. Endpoints como scripts que devuelven JSON (`/api/*.php`).
- **Base de datos:** MySQL (Hostinger, hosting compartido).
- **Frontend:** HTML renderizado server-side por PHP + **CSS plano** (custom properties para el sistema de diseño, sin frameworks tipo Tailwind/Bootstrap) + **JavaScript vanilla** (fetch API, sin frameworks tipo React/Vue). Mínimo JS posible para el editor de widgets — sin dependencias de build.
- **Gráficos:** Chart.js (única librería externa de frontend permitida, vía CDN).
- **IA:** llamadas server-side (PHP con cURL) a la API de Anthropic (`api.anthropic.com/v1/messages`). La API key vive en variables de entorno del servidor, nunca en el HTML/JS que llega al navegador.
- **Hosting:** Hostinger, plan compartido. El usuario maneja su propio script de deploy — no generar instrucciones de deploy salvo que las pida. FTP está enjaulado dentro de `public_html`, no se puede subir nada fuera de esa carpeta.
- **Sin Vercel, sin Next.js, sin React, sin Node en producción.**

## Estructura de carpetas (todo dentro de `public_html` — Hostinger no permite subir nada afuera)

```
code/
├── public_html/              ← raíz web, todo lo que se sube por FTP
│   ├── .htaccess             (bloquea dotfiles, sin listado de directorios)
│   ├── index.php             (entrada / router del wizard)
│   ├── steps/                (Paso 1-4: plantel.php, datos.php, vistas.php, validacion.php, dashboard.php)
│   ├── api/                  (endpoints JSON, targets de fetch())
│   ├── css/                  (tokens.css, base.css, components.css)
│   ├── js/                   (wizard.js, widgets.js, chart-init.js, api.js)
│   └── app/                  ← lógica interna, protegida con .htaccess (Require all denied)
│       ├── .htaccess         (bloquea CUALQUIER acceso HTTP directo a esta carpeta)
│       ├── config.php        (credenciales reales — gitignored, nunca se commitea)
│       ├── config.example.php (referencia de variables sin valores reales)
│       ├── Database.php, CsvParser.php, ColumnTypeDetector.php, NameMatcher.php,
│       │   WidgetSchema.php, WidgetRenderer.php, AnthropicClient.php, ViewGenerator.php
├── sql/                      (schema.sql — NO se sube a producción, se corre una vez a mano vía phpMyAdmin)
├── .gitignore
└── CLAUDE.md
```

**Seguridad de `app/`:** doble capa. (1) `.htaccess` con `Require all denied` bloquea requests HTTP directos. (2) Aunque el `.htaccess` fallara, `config.php` es un archivo PHP — si se pidiera por URL, el servidor lo *ejecuta* (no devuelve texto plano), así que las credenciales nunca quedan expuestas como en un `.env` estático. Los archivos de `app/` solo se cargan vía `require`/`include` desde `api/` o `steps/`.

## Dirección de diseño

Minimalista, estética de producto de IA actual con identidad propia (pensar Linear, Vercel dashboard, Notion) — **no** plantilla genérica. Evitar los tres defaults del ecosistema IA: (1) fondo crema cálido + serif + acento terracota, (2) fondo casi negro + acento verde ácido/vermellón único, (3) layout tipo diario con hairlines y cero border-radius. Fondo oscuro o acento vibrante solo si se justifica por el brief (datos de GPS/rendimiento deportivo).

Antes de escribir CSS masivo, definir y mostrar un sistema de diseño compacto:
- **Paleta:** 4-6 colores con hex, para dashboard de datos deportivos (lectura rápida de números, estados on-target/off-target, sin gritar).
- **Tipografía:** una face para títulos/hero con carácter, una para texto/UI, considerar monoespaciada para números y datos crudos (lecturas GPS).
- **Layout:** grilla de widgets, wizard de pasos, tablas — densidad de información legible por encima de espacios decorativos (usuario real mira esto en medio de un entrenamiento).
- **Elemento de firma:** detalle visual único con sentido (nada de números 01/02/03 salvo contenido realmente secuencial — el wizard Plantel→Datos→Vistas→Validación→Dashboard sí lo es).

Responsive hasta mobile, foco de teclado visible, animación con propósito (no decorativa) y poca.

## Principio de arquitectura no negociable

La IA **nunca genera código, HTML libre, ni gráficos directamente**. Su único output es un **JSON de configuración** que un renderer PHP fijo (ya escrito, testeado) convierte en HTML + inicialización de Chart.js. Aplica tanto a generación inicial de una vista como a cualquier edición asistida por IA posterior.

## Flujo de usuario

```
Paso 1: Plantel → Paso 2: Datos → Paso 3: Vistas → Paso 3.7: Validación → Paso 4: Dashboard
```

### Paso 1 — Cargar plantel
- CSV único con la nómina: columnas mínimas Nombre, Familia (back/forward), Sub-familia. Columnas extra se guardan como metadata libre (JSON).
- Tabla maestra: todo dato posterior se vincula a un jugador por nombre.

### Paso 2 — Cargar datos
- Cualquier CSV, sin estructura fija. Cada subida se guarda como **dataset con nombre propio** (autogenerado, editable).
- Al subir: (a) detectar tipo de cada columna (numérica/texto/fecha/categórica), (b) detectar cuál columna es el nombre del jugador, (c) guardar los datos **crudos** sin transformar.
- Múltiples datasets, subidas incrementales.

### Paso 3 — Crear vistas
- Por vista: nombre editable, descripción libre (texto), selección de datasets (checkboxes).
- Multi-vista: se pueden crear varias antes de generar, o agregar después.

### Paso 3.7 — Validación de datos (checkpoint bloqueante)
Antes de generar el dashboard, correr análisis sobre los datasets seleccionados:
- **Nombres sin matchear:** fuzzy match contra el plantel (tolerar tildes, mayúsculas, orden nombre-apellido). Match candidato = sugerencia, **nunca se aplica solo**. Usuario debe: confirmar explícito ("Sí, es este jugador"), elegir otro jugador manualmente, o descartar la fila.
- **Columna de nombre ambigua o ausente:** listar columnas del dataset, usuario elige cuál es la de nombre de jugador.
- **Datos insuficientes:** si la vista pide algo que el dataset no tiene (ej. filtrar por fecha sin columna de fecha), mensaje simple con opción de volver a Paso 2 o 3.
- Se resuelve **una vez por dataset**, no por vista.
- Si el usuario descarta un problema sin resolver: el dashboard se genera igual, pero el widget afectado muestra aviso visible (ej. "3 jugadores excluidos por datos sin asignar").

### Paso 4 — Dashboard generado + edición
- Grilla de gráficos/tablas generada por IA a partir de la descripción + los datasets. La IA puede **proponer widgets adicionales** dentro de la librería fija si detecta algo útil (ej. radar comparativo si hay datos de ambas familias). Riesgo bajo — se descartan con un click.
- **Edición manual, sin IA:** agregar/quitar widgets, crear métricas configurables, agregar/quitar filtros.
- **"Modificar con IA" por widget individual:** pedido puntual en texto libre sobre un widget ya generado. Por **parche validado**, nunca regeneración libre — ver reglas de seguridad abajo.
- **No implementar "Mejorar con IA" a nivel de vista completa** en este MVP — queda para v2.
- Tabs para navegar entre vistas, botón "Agregar +". Exportar PDF y Configuración: versión básica, no prioridad.

## Mecanismo de seguridad para "Modificar con IA" (crítico)

1. **Parche, no regeneración:** la IA recibe el config JSON actual del widget + la instrucción del usuario, y devuelve **solo los campos que cambian**. Nunca reescribe el widget entero.
2. **Validación antes de aplicar:** el parche se valida contra el esquema del dataset (¿existe la columna? ¿la agregación es válida para ese tipo de widget?) antes de tocar la vista. Si no valida, se rechaza con mensaje claro.
3. **Preview + confirmación:** el cambio propuesto se muestra antes de aplicarse. Usuario confirma o descarta. Nunca se sobreescribe en silencio.
4. **Historial de versiones (undo):** guardar últimas 5-10 versiones de config de cada widget (IA o manual). Volver atrás es un click.

## Esquema de datos sugerido (ajustar libremente en implementación)

- `players` — id, nombre, familia, sub_familia, metadata (JSON)
- `datasets` — id, nombre, uploaded_at, column_schema (JSON: nombre columna → tipo), player_column_name
- `dataset_rows` — id, dataset_id, player_id (nullable), raw_data (JSON), match_status (matched/unmatched/discarded)
- `name_reconciliations` — dataset_id, raw_name, suggested_player_id, resolution, resolved_player_id
- `views` — id, nombre, description (prompt original)
- `view_datasets` — view_id, dataset_id
- `widgets` — id, view_id, type, config (JSON), position
- `widget_versions` — id, widget_id, config (JSON), created_at, source (manual/ai/initial)
- `custom_metrics` — id, view_id, dataset_id, nombre, formula (JSON: operación + columnas)
- `view_filters` — id, view_id, dataset_id, column_name, filter_type

## Librería fija de widgets (IA y editor manual comparten este espacio)

1. **KPI card** — métrica (columna o métrica custom), agregación, filtro propio opcional, comparación opcional, formato de número, selector de escala % opcional (ver punto 6).
2. **Tabla con formato condicional** — columnas a mostrar y orden, fila = jugador o jugador+sesión, agregación por columna, hasta 3 reglas de color por umbral por columna, orden por defecto, búsqueda de texto libre on/off, selector de escala % opcional.
3. **Línea temporal** — una o más métricas en eje Y, eje X (columna fecha/sesión), agrupar por columna categórica (máx. 6 líneas simultáneas), agregación por punto, línea continua o con marcadores.
4. **Barra por jugador/categoría** — métrica, eje de categorías, agregación, orden (alfabético o ranking), orientación, línea de referencia opcional.
5. **Barra apilada** — métrica base, columna que define segmentos (hasta 6), eje de categorías, modo absoluto o 100% apilado.
6. **Selector de escala (% objetivo)** — transversal a KPI card y Tabla: dropdown/slider de porcentaje (25/50/75/100/125% + valor libre). `valor mostrado = valor base × (porcentaje / 100)`, recalculado **en el cliente con JS**, sin volver a consultar el servidor. Caso de uso: planificación de carga ("¿cuántos metros le pido al plantel el jueves si quiero entrenar al 50% de lo que corren en partido?").

Cada widget tiene cuatro controles compartidos: **Editar** (panel manual) · **Modificar con IA** (parche validado) · **Duplicar** · **Eliminar**, más botón **Deshacer** (historial de versiones).

Las **métricas configurables** (fórmula simple entre columnas numéricas del mismo dataset) se crean a nivel vista+dataset y quedan disponibles para todos los widgets de esa vista que usen ese dataset. Eliminar una métrica en uso pide confirmación.

## Fuera de scope explícito para este MVP

- "Mejorar con IA" a nivel de vista completa (varios widgets a la vez).
- Multi-club, multi-tenant, roles de usuario.
- Exportar PDF con diseño cuidado (versión básica alcanza).
- Persistencia de plantel versionado.
- Métricas derivadas complejas tipo ACWR, o cruces entre datasets distintos en las métricas configurables.

## Orden de implementación

1. Esquema MySQL
2. Paso 1 y 2 (carga de datos)
3. Paso 3.7 (validación)
4. Generador de vistas por IA con la librería de widgets
5. Editor manual
6. "Modificar con IA" por widget con mecanismo de parche validado

Cada paso depende del anterior — probar incrementalmente con datos reales del club antes de avanzar. No implementar "Mejorar con IA" a nivel vista completa ni nada fuera del scope arriba salvo pedido explícito.
