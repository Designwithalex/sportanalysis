# Landing + Auth — sistema visual

Extensión del sistema existente (instrumento de medición) a dos superficies nuevas: la landing
pública y las pantallas de sesión. **No se agregó ningún token a `tokens.css`** y no hay un solo
color, fuente ni radio hardcodeado: todo sale de los tokens, así el dark mode sale gratis.

## Archivos

| Archivo | Qué es |
|---|---|
| `public_html/css/landing.css` | **Nuevo.** Solo la landing. Secciones 1-11 numeradas en el propio archivo. |
| `public_html/css/components.css` | Sección nueva **al final** (`SUPERFICIES PÚBLICAS`). Nada existente se tocó ni se reordenó. |
| `public_html/app/views/head.php` | Parametrizado: `$assetPrefix`, `$useLandingCss`, `$csrfToken`, `$pageDescription`. |
| `public_html/app/views/publicnav.php` | **Nuevo.** Nav pública, sirve para landing y auth. |

La nav pública y **todo** lo de auth (campos, errores, loading, toggle de contraseña, shell,
perfil) están en `components.css`, no en `landing.css`: login/registro/perfil cargan solo
`tokens + base + components`. `landing.css` es exclusivo de la landing.

## Decisiones de composición

**Patrón elegido: "Real-Time / Operations Landing".** Hero con vista previa del producto → señales
concretas → cómo funciona → CTA. Es el patrón para productos de operación/telemetría, no el de
SaaS con testimonios: no hay testimonios que mostrar (un club real, MVP) y la promesa se demuestra
mejor enseñando el tablero que contándolo.

**El hero muestra el producto, no una ilustración.** El panel de muestra está dibujado con CSS +
un `<svg>` inline: cero imágenes, cero requests, cero layout shift, y se re-tinta solo en dark
mode. Va `aria-hidden="true"` — es una foto del producto, no información; la promesa la carga el
texto de la izquierda.

**Numeración solo donde el contenido es secuencial.** `CLAUDE.md` prohíbe los 01/02/03 decorativos.
"Cómo funciona" es literalmente el orden del wizard (Plantel → Datos → Vistas → Dashboard), así que
ahí sí se numera, con `<ol>` y el número reutilizando el círculo mono de `.confignav-num`.

**Firma arrastrada, con moderación.** El segmento signal de 44×2px aparece exactamente en cuatro
lugares y siempre significando lo mismo ("acá empieza la escala"): baseline de la nav pública,
baseline del panel de muestra, línea superior de cada paso numerado, borde superior del CTA final y
de la `.auth-card`. El tick vertical de 3px aparece en la marca (nav y footer) y como bullet de los
eyebrows. La grilla de medición del fondo del hero se dibuja con `--border` y una máscara radial.

**Un contraste que había que corregir.** El chip `.appbar-sub` usa `--accent` sobre `--signal-wash`:
medido, ese par da **~3.8:1** sobre paper — falla AA a 10.5px. Como la accesibilidad es requisito
duro, en las superficies nuevas los eyebrows van en `--accent-strong` sobre superficie plana
(**~4.8:1** claro, **~10:1** oscuro) precedidos por el tick, y **cualquier texto sobre wash usa
`--text-muted`** (~4.9:1 claro, ~6.6:1 oscuro). El acento queda para bordes, ticks y fills sin texto
encima. Regla escrita en la cabecera de ambos archivos CSS.

**Movimiento: dos animaciones en toda la landing.** `lp-rise` (entrada del panel del hero, da
continuidad espacial) y `lp-pulse` (el punto "en vivo", dice que el tablero se recalcula solo).
Ambas descansan en el estado visible, así que apagarlas no rompe nada. Ningún scroll-reveal: exige
JS, castiga a quien no tolera movimiento y no aporta.

## Qué aportó `ui-ux-pro-max` (y qué se ignoró)

Se ignoró todo lo que tocaba identidad: proponía paleta azul `#1E40AF` + ámbar, tipografía
Fira Code/Fira Sans **por CDN de Google Fonts**, y utilidades Tailwind. Nada de eso entró — el
proyecto es CSS plano con fuentes self-hosted y cero dependencias externas.

Lo que sí se usó, todo estructural:

- **Patrón de landing** "Real-Time / Operations" (orden de secciones, CTA primario en la nav +
  después de las señales, sección de confianza antes del cierre).
- **Formularios** (esto destapó los tres huecos reales que rompían auth):
  - *Error Placement* — el error va debajo del campo, nunca todo junto arriba → `.field.has-error`
    + `.field-error`.
  - *Submit Feedback* (severidad Alta) — todo submit necesita loading → success/error →
    `.btn.is-loading` + `.form-status` con `aria-live`.
  - *Password Visibility* — toggle ver/ocultar → `.field-password` + `.field-password-toggle`.
  - *Error Messages* (a11y, Alta) — `role="alert"` en el mensaje, no solo borde rojo.
  - *Loading Buttons* — bloquear doble submit.
- **Motion**: 150-300ms para micro-interacciones (el sistema ya está en 0.15/0.24s), máximo 1-2
  elementos animados por vista, `prefers-reduced-motion`.
- **Layout/touch**: sin scroll horizontal, targets de 44px en `pointer: coarse`, `scroll-behavior:
  smooth` para la nav por anclas (con `scroll-margin-top` porque la nav es sticky), reservar espacio
  para evitar CLS.
- **Iconos SVG inline, no emoji.** El resto de la app usa glifos (`⚙`, `✦`); en las superficies
  nuevas los iconos son `<svg>` inline con `stroke: currentColor` — sigue siendo cero dependencias y
  se tiñe con los tokens.

## Markup exacto que espera cada bloque

Cada bloque de `landing.css` tiene su markup esperado en un comentario **arriba de sus reglas**.
Acá va el esqueleto completo para copiar.

### Landing

```php
<?php
$pageTitle       = 'SportAnalysis — el tablero de tu club, armado desde tus CSV';
$pageDescription = '…';
$assetPrefix     = '';      // página en la raíz de public_html
$useLandingCss   = true;
require __DIR__ . '/app/views/head.php';

$publicnavHome = '/';
require __DIR__ . '/app/views/publicnav.php';
?>
<main id="contenido" tabindex="-1">

  <!-- 1. HERO -->
  <section class="lp-hero">
    <div class="lp-wrap lp-hero-inner">
      <div class="lp-hero-copy">
        <p class="lp-eyebrow">Para preparadores físicos</p>
        <h1 class="lp-hero-title">Tus CSV de siempre, <em>leídos como un tablero</em></h1>
        <p class="lp-hero-lede">…</p>
        <div class="lp-hero-ctas">
          <a class="btn lp-btn" href="registro.php">Crear cuenta</a>
          <a class="btn btn-secondary lp-btn" href="#como-funciona">Ver cómo funciona</a>
        </div>
        <ul class="lp-hero-note">
          <li>Sin planilla nueva</li><li>Sin plantilla fija</li><li>Datos de tu club</li>
        </ul>
      </div>
      <div class="lp-hero-panel" aria-hidden="true">
        <div class="lp-frame">
          <div class="lp-frame-bar">
            <span class="lp-frame-title">Carga semanal — Forwards</span>
            <span class="lp-frame-live">En vivo</span>
          </div>
          <div class="lp-frame-body">
            <div class="lp-mini">
              <p class="lp-mini-label">Distancia total</p>
              <p class="lp-mini-value">6.482<span class="lp-mini-unit">m</span></p>
              <span class="lp-mini-delta">+8%</span>
            </div>
            <div class="lp-mini">
              <p class="lp-mini-label">Sprints &gt; 20 km/h</p>
              <p class="lp-mini-value">14</p>
              <span class="lp-mini-delta">+3</span>
            </div>
            <div class="lp-mini lp-mini-wide">
              <p class="lp-mini-label">Metros por sesión</p>
              <div class="lp-bars">
                <i style="--h:38%"></i><i style="--h:62%"></i><i style="--h:47%"></i>
                <i style="--h:83%"></i><i style="--h:55%"></i><i style="--h:71%"></i>
              </div>
            </div>
            <div class="lp-mini lp-mini-wide">
              <p class="lp-mini-label">Velocidad máxima</p>
              <div class="lp-spark">
                <svg viewBox="0 0 200 48" preserveAspectRatio="none" focusable="false">
                  <polyline points="0,38 33,26 66,31 100,14 133,20 166,9 200,16"/>
                </svg>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- 2. PROBLEMA → PROPUESTA -->
  <section class="lp-section">
    <div class="lp-wrap">
      <header class="lp-section-head">
        <p class="lp-eyebrow">El problema</p>
        <h2 class="lp-title">…</h2>
        <p class="lp-lede">…</p>
      </header>
      <div class="lp-flow">
        <article class="lp-flow-card is-raw">
          <p class="lp-flow-kicker">Lo que tenés</p>
          <h3 class="lp-flow-title">Un CSV con las columnas que usa tu club</h3>
          <pre class="lp-csv">jugador,fecha,distancia_m,sprints
Acosta,12/03,6482,14
Benítez,12/03,5910,11</pre>
        </article>
        <div class="lp-flow-arrow" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false"><path d="M4 12h14M13 6l6 6-6 6"/></svg>
        </div>
        <article class="lp-flow-card">
          <p class="lp-flow-kicker">Lo que pedís</p>
          <h3 class="lp-flow-title">Una frase, en tu idioma</h3>
          <p class="lp-quote">"Quiero ver la carga semanal de los forwards y quién está por
             debajo de su promedio."</p>
        </article>
        <div class="lp-flow-arrow" aria-hidden="true">
          <svg viewBox="0 0 24 24" focusable="false"><path d="M4 12h14M13 6l6 6-6 6"/></svg>
        </div>
        <article class="lp-flow-card is-out">
          <p class="lp-flow-kicker">Lo que sale</p>
          <h3 class="lp-flow-title">Una grilla de widgets, editable</h3>
          <div class="lp-flow-grid" aria-hidden="true">
            <i></i><i></i><i class="wide"></i><i class="wide"></i>
          </div>
        </article>
      </div>
    </div>
  </section>

  <!-- 3. CÓMO FUNCIONA (único bloque numerado) -->
  <section class="lp-section lp-section-alt" id="como-funciona">
    <div class="lp-wrap">
      <header class="lp-section-head">
        <p class="lp-eyebrow">Cómo funciona</p>
        <h2 class="lp-title">De la planilla al tablero en cuatro pasos</h2>
      </header>
      <ol class="lp-steps">
        <li class="lp-step">
          <span class="lp-step-num" aria-hidden="true">1</span>
          <h3 class="lp-step-title">Plantel</h3>
          <p class="lp-step-text">…</p>
        </li>
        <!-- 2 Datos · 3 Vistas · 4 Dashboard -->
      </ol>
    </div>
  </section>

  <!-- 4. LIBRERÍA DE WIDGETS -->
  <section class="lp-section" id="widgets">
    <div class="lp-wrap">
      <header class="lp-section-head">
        <p class="lp-eyebrow">Librería fija</p>
        <h2 class="lp-title">Cinco tipos de widget. Ni uno inventado sobre la marcha.</h2>
        <p class="lp-lede">…</p>
      </header>
      <div class="lp-widgets">

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-kpi" aria-hidden="true">
            <span class="lp-glyph-kpi-label">Distancia</span>
            <span class="lp-glyph-kpi-value">6.482</span>
          </div>
          <h3 class="lp-widget-name">KPI card</h3>
          <p class="lp-widget-desc">…</p>
          <span class="lp-widget-tag">escala %</span>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-rows" aria-hidden="true">
            <i style="--w:100%"></i><i style="--w:82%" class="ok"></i>
            <i style="--w:64%" class="hot"></i><i style="--w:90%"></i>
          </div>
          <h3 class="lp-widget-name">Tabla con formato condicional</h3>
          <p class="lp-widget-desc">…</p>
          <span class="lp-widget-tag">escala %</span>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-line" aria-hidden="true">
            <svg viewBox="0 0 200 60" preserveAspectRatio="none" focusable="false">
              <polyline points="0,46 40,30 80,38 120,16 160,24 200,10"/>
              <polyline class="alt" points="0,52 40,44 80,48 120,34 160,40 200,30"/>
            </svg>
          </div>
          <h3 class="lp-widget-name">Línea temporal</h3>
          <p class="lp-widget-desc">…</p>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-bars" aria-hidden="true">
            <i style="--h:44%"></i><i style="--h:72%"></i><i style="--h:58%"></i>
            <i style="--h:90%"></i><i style="--h:66%"></i>
          </div>
          <h3 class="lp-widget-name">Barra por jugador</h3>
          <p class="lp-widget-desc">…</p>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-stack" aria-hidden="true">
            <i style="--h:74%"><span class="sg-a" style="--s:46%"></span><span class="sg-b" style="--s:32%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:96%"><span class="sg-a" style="--s:38%"></span><span class="sg-b" style="--s:40%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:62%"><span class="sg-a" style="--s:52%"></span><span class="sg-b" style="--s:26%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:84%"><span class="sg-a" style="--s:30%"></span><span class="sg-b" style="--s:44%"></span><span class="sg-c" style="--s:26%"></span></i>
          </div>
          <h3 class="lp-widget-name">Barra apilada</h3>
          <p class="lp-widget-desc">…</p>
        </article>

      </div>
    </div>
  </section>

  <!-- 5. CONFIANZA -->
  <section class="lp-section lp-section-alt" id="datos">
    <div class="lp-wrap lp-trust">
      <div class="lp-trust-copy">
        <p class="lp-eyebrow">Aislamiento</p>
        <h2 class="lp-title">Los datos de tu club son tuyos</h2>
        <p class="lp-lede">…</p>
      </div>
      <ul class="lp-trust-list">
        <li class="lp-trust-item">
          <span class="lp-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <div>
            <p class="lp-trust-name">Un club por instalación</p>
            <p class="lp-trust-text">…</p>
          </div>
        </li>
        <!-- repetir -->
      </ul>
    </div>
  </section>

  <!-- 6. CTA FINAL -->
  <section class="lp-section">
    <div class="lp-wrap">
      <div class="lp-cta">
        <h2 class="lp-cta-title">Probalo con los datos del jueves pasado</h2>
        <p class="lp-cta-sub">…</p>
        <div class="lp-cta-actions">
          <a class="btn lp-btn" href="registro.php">Crear cuenta</a>
          <a class="btn btn-secondary lp-btn" href="login.php">Ya tengo cuenta</a>
        </div>
      </div>
    </div>
  </section>

</main>

<footer class="lp-footer">
  <div class="lp-wrap lp-footer-inner">
    <div class="lp-footer-brand">SportAnalysis</div>
    <nav class="lp-footer-links" aria-label="Pie">
      <a href="#como-funciona">Cómo funciona</a>
      <a href="#widgets">Widgets</a>
      <a href="#datos">Tus datos</a>
      <a href="login.php">Ingresar</a>
    </nav>
    <p class="lp-footer-legal">© 2026 · Hecho para un club, no para un mercado</p>
  </div>
</footer>
</body>
</html>
```

Notas para el agente de PHP:

- `<em>` dentro de `.lp-hero-title` tiñe la frase con el acento (no queda en itálica).
- Los `style="--h:…"` / `--w` / `--s` son **valores de dato**, no estilos ad-hoc: definen la altura
  o el ancho de cada barra del pictograma. Es la única forma de parametrizarlos sin JS.
- Todos los pictogramas van `aria-hidden="true"`: el significado lo carga el texto.
- `<main id="contenido" tabindex="-1">` es obligatorio — es el destino del skip-link de
  `publicnav.php`.

### Login / Registro

```php
<?php
$pageTitle   = 'Ingresar — SportAnalysis';
$assetPrefix = '';                    // sin $useLandingCss: auth no carga landing.css
$csrfToken   = $csrf;                 // lo llena el agente de auth
require __DIR__ . '/app/views/head.php';

$publicnavCurrent = 'login';
$publicnavSticky  = false;
require __DIR__ . '/app/views/publicnav.php';
?>
<main class="auth-shell" id="contenido" tabindex="-1">
  <section class="auth-card">
    <p class="auth-eyebrow">Ingresar</p>
    <h1 class="auth-title">Entrá a tu club</h1>
    <p class="auth-sub">Tus datasets, tus vistas y tus dashboards.</p>

    <div class="form-status" role="status" aria-live="polite"></div>

    <form class="auth-form" method="post" novalidate>
      <div class="field">
        <label for="email">Email</label>
        <input type="email" id="email" name="email" autocomplete="email" required>
        <p class="field-error" id="email-err" role="alert"></p>
      </div>

      <div class="field">
        <label for="pass">Contraseña</label>
        <div class="field-password">
          <input type="password" id="pass" name="pass" autocomplete="current-password" required>
          <button type="button" class="field-password-toggle"
                  aria-label="Mostrar contraseña" aria-pressed="false">
            <svg class="icon-eye" viewBox="0 0 24 24" focusable="false">
              <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3"/>
            </svg>
            <svg class="icon-eye-off" viewBox="0 0 24 24" focusable="false">
              <path d="M3 3l18 18M10.6 10.6a3 3 0 004.2 4.2M9.9 5.2A9.6 9.6 0 0112 5c6.4 0 10 7 10 7a17 17 0 01-3.2 4M6.3 6.4A17 17 0 002 12s3.6 7 10 7c1 0 2-.2 2.9-.5"/>
            </svg>
          </button>
        </div>
        <p class="field-error" id="pass-err" role="alert"></p>
      </div>

      <label class="auth-check">
        <input type="checkbox" name="recordarme"> <span>Mantener la sesión abierta</span>
      </label>

      <button class="btn auth-submit btn-swap-label" type="submit">
        <span class="btn-spinner" aria-hidden="true"></span>
        <span class="btn-label">Entrar</span>
        <span class="btn-loading-label">Entrando…</span>
      </button>
    </form>

    <div class="auth-meta"><a href="recuperar.php">Olvidé mi contraseña</a></div>
    <p class="auth-alt">¿Todavía no tenés cuenta? <a href="registro.php">Creá una</a></p>
  </section>
</main>
```

Contrato de los estados (lo que tiene que hacer el JS):

| Estado | Qué toca |
|---|---|
| Error de campo | `.field` → agregar `has-error`; escribir texto en su `.field-error`; `aria-invalid="true"` y `aria-describedby` en el input. |
| Campo corregido | quitar `has-error`, vaciar `.field-error`, quitar `aria-invalid`. |
| Submit en curso | botón → agregar `is-loading` + `aria-busy="true"`; bandera JS contra doble submit (**no** `disabled`: deshabilitar el botón enfocado tira el foco al `<body>`). |
| Resultado | inyectar `<div class="alert alert-error">` o `alert-success` dentro de `.form-status` (`:empty` la colapsa sola cuando no hay nada). |
| Ver contraseña | `.field-password` → `is-revealed`; `input.type` password↔text; actualizar `aria-pressed` y `aria-label`. |
| Fuerza de clave (registro) | `.pw-meter` → `data-level` 0..3 y texto en `.pw-meter-text`. |

### Perfil

`<div class="page">` normal + `.profile-head` (avatar de iniciales mono, nombre, meta) + tarjetas
`.card` con campos `.field`. Para la zona destructiva: `<div class="card is-danger">` y
`<button class="btn btn-danger">`.

## Responsive

Desktop-first con `max-width`, como el resto del sistema. **720px** es el valor canónico
(intacto, ya lo usaba `.dash-grid`); se agregó **1024px** solo para colapsar las grillas de 2+
columnas de contenido ancho.

| | ≤1024px | ≤720px |
|---|---|---|
| Hero | 1 columna, panel debajo | CTAs a ancho completo |
| Problema→propuesta | 1 columna, flechas rotadas 90° | igual |
| Cómo funciona | 2 columnas | 1 columna |
| Widgets | 2 columnas | 1 columna |
| Confianza | 1 columna | igual |
| Nav pública | — | marca 18px, chip "IA" oculto, marca truncable |
| Panel del hero | — | mini-cards a 1 columna |

`@media (pointer: coarse)` lleva a 44px los links de nav, footer y el toggle de contraseña.

## Dudas donde quiero tu opinión

1. **El chip `.appbar-sub` da ~3.8:1.** Lo dejé intacto (hay agentes trabajando en el dashboard),
   pero la app ya tiene ese fallo de contraste. ¿Lo corrijo en un pase aparte cambiando el texto de
   los chips sobre wash a `--text-muted`? Afecta `.appbar-sub`, `.tag`, `.badge-back`,
   `.ai-hero-box-cta`, `.pm-preview-type`.
2. **Anillo de foco.** El anillo de 4px lo agregué **sumado** al `outline` global de `base.css`, no
   en reemplazo: el wash al 12% solo no llega al 3:1 que pide un indicador de foco. Si preferís el
   anillo puro, hay que subir la opacidad del wash o usar un borde de acento sólido debajo.
3. **`<meta name="color-scheme" content="light dark">`** lo agregué a `head.php`, así que aplica a
   **todas** las pantallas: hace que selects, scrollbars y autofill nativos acompañen el dark mode
   en vez de renderizar claros. Es una mejora, pero cambia levemente el aspecto de los controles
   nativos del wizard en dark mode. Decime si lo dejo o lo limito a las páginas nuevas.
4. **Nombres de archivo asumidos:** `login.php`, `registro.php`, `recuperar.php`, y la landing en la
   raíz. Todos son parámetros de `publicnav.php`, así que cambiarlos es una línea.
