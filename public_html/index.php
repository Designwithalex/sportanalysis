<?php
/**
 * Landing pública de SportAnalysis.
 *
 * Es la única pantalla anónima con contenido: no abre sesión, no consulta la base y no carga
 * JS ni librerías externas. Por eso NO incluye app/bootstrap_page.php (trae el guard de auth):
 * solo necesita el helper asset(), que head.php ya requiere por su cuenta.
 *
 * El router que antes vivía acá (contar players/datasets y redirigir al paso que corresponda)
 * se mudó a panel.php, que es la entrada de la sesión iniciada.
 *
 * Markup: sigue al pie de la letra el esqueleto de DESIGN-LANDING.md y los comentarios de
 * css/landing.css. Toda clase usada existe en landing.css o components.css.
 */

$pageTitle       = 'SportAnalysis — el tablero de tu club, armado desde tus CSV';
$pageDescription = 'Subís el plantel y los CSV que ya venís usando, describís en una frase qué '
                 . 'querés ver y la IA arma la grilla de gráficos y tablas. Después la ajustás a '
                 . 'mano, widget por widget. Para preparadores físicos de rugby.';
$assetPrefix     = '';      // la landing vive en la raíz de public_html
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
        <p class="lp-hero-lede">Subís los datos que ya venís juntando —GPS, entrenamientos, fuerza,
          lo que sea—, describís en una frase qué querés mirar y la IA arma la grilla de gráficos y
          tablas. Después la seguís completando vos, widget por widget.</p>
        <div class="lp-hero-ctas">
          <a class="btn lp-btn" href="registro.php">Crear cuenta</a>
          <?php /* Secundario del hero: invita a bajar, no repite "Ingresar" — la nav ya lo ofrece
                   justo arriba. El CTA final sí ofrece la vuelta con "Ya tengo cuenta". */ ?>
          <a class="btn btn-secondary lp-btn" href="#como-funciona">Ver cómo funciona</a>
        </div>
        <ul class="lp-hero-note">
          <li>Sin planilla nueva</li><li>Sin plantilla fija</li><li>Datos de tu club</li>
        </ul>
      </div>
      <div class="lp-hero-panel" aria-hidden="true">
        <div class="lp-frame">
          <div class="lp-frame-bar">
            <span class="lp-frame-title">Ejemplo · Carga semanal — Forwards</span>
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
        <h2 class="lp-title">Los datos ya los tenés. Lo que falta es mirarlos.</h2>
        <p class="lp-lede">El GPS exporta sus columnas, la app del gimnasio otras y el resto vive en
          una planilla que armaste a mano. Acá no hay estructura que respetar: el CSV entra como
          está, y lo que querés ver lo escribís con tus palabras.</p>
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
          <p class="lp-quote">“Quiero ver la carga semanal de los forwards y quién viene por debajo
            de su promedio.”</p>
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

  <!-- 3. CÓMO FUNCIONA (único bloque numerado: es el orden real del wizard) -->
  <section class="lp-section lp-section-alt" id="como-funciona">
    <div class="lp-wrap">
      <header class="lp-section-head">
        <p class="lp-eyebrow">Cómo funciona</p>
        <h2 class="lp-title">De la planilla al tablero en cuatro pasos</h2>
        <p class="lp-lede">Los dos primeros se hacen una vez. Después vivís en las vistas.</p>
      </header>
      <ol class="lp-steps">
        <li class="lp-step">
          <span class="lp-step-num" aria-hidden="true">1</span>
          <h3 class="lp-step-title">Plantel</h3>
          <p class="lp-step-text">Subís la nómina una sola vez: nombre, familia (back o forward) y
            sub-familia. Las columnas de más se guardan igual. Es la tabla maestra contra la que se
            engancha todo lo que cargues después.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step-num" aria-hidden="true">2</span>
          <h3 class="lp-step-title">Datos</h3>
          <p class="lp-step-text">Cualquier CSV, con las columnas que tenga. Se guarda crudo, como
            dataset con nombre propio, y podés seguir sumando cargas. Antes de generar nada revisás
            los nombres que no matchearon: la sugerencia se propone, nunca se aplica sola.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step-num" aria-hidden="true">3</span>
          <h3 class="lp-step-title">Vistas</h3>
          <p class="lp-step-text">Elegís qué datasets entran y escribís en texto libre qué querés
            ver. Podés tener varias en paralelo: la del partido, la de la semana de carga, la del
            gimnasio.</p>
        </li>
        <li class="lp-step">
          <span class="lp-step-num" aria-hidden="true">4</span>
          <h3 class="lp-step-title">Dashboard</h3>
          <p class="lp-step-text">La IA devuelve la grilla armada. Desde ahí agregás o sacás
            widgets, creás métricas propias y le pedís un ajuste puntual a uno solo. Todo cambio se
            previsualiza antes de aplicarse y se puede deshacer.</p>
        </li>
      </ol>
    </div>
  </section>

  <!-- 4. LIBRERÍA DE WIDGETS -->
  <section class="lp-section" id="widgets">
    <div class="lp-wrap">
      <header class="lp-section-head">
        <p class="lp-eyebrow">Librería fija</p>
        <h2 class="lp-title">Cinco tipos de widget. Ni uno inventado sobre la marcha.</h2>
        <p class="lp-lede">La IA no escribe código ni dibuja gráficos: devuelve una configuración
          que el sistema renderiza con estos cinco bloques, los mismos que podés editar a mano. El
          KPI y la tabla suman un selector de escala: movés el porcentaje y el número se recalcula
          en el momento. Si en partido corren 6.000 m, ¿cuántos metros le pedís al plantel el jueves
          para entrenar al 50%?</p>
      </header>
      <div class="lp-widgets">

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-kpi" aria-hidden="true">
            <span class="lp-glyph-kpi-label">Distancia</span>
            <span class="lp-glyph-kpi-value">6.482</span>
          </div>
          <h3 class="lp-widget-name">KPI card</h3>
          <p class="lp-widget-desc">Un número que importa, con su agregación, su filtro propio y, si
            hace falta, la comparación contra otro período.</p>
          <span class="lp-widget-tag">escala %</span>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-rows" aria-hidden="true">
            <i style="--w:100%"></i><i style="--w:82%" class="ok"></i>
            <i style="--w:64%" class="hot"></i><i style="--w:90%"></i>
          </div>
          <h3 class="lp-widget-name">Tabla con formato condicional</h3>
          <p class="lp-widget-desc">Fila por jugador o por jugador y sesión, hasta tres reglas de
            color por columna y buscador de texto. Para leer de un vistazo quién está afuera.</p>
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
          <p class="lp-widget-desc">Una o más métricas contra fecha o sesión, agrupadas por la
            categoría que elijas. Hasta seis líneas a la vez.</p>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-bars" aria-hidden="true">
            <i style="--h:44%"></i><i style="--h:72%"></i><i style="--h:58%"></i>
            <i style="--h:90%"></i><i style="--h:66%"></i>
          </div>
          <h3 class="lp-widget-name">Barra por jugador</h3>
          <p class="lp-widget-desc">Por jugador o por la categoría que quieras, ordenada por ranking
            o alfabético, con línea de referencia opcional.</p>
        </article>

        <article class="lp-widget">
          <div class="lp-widget-glyph lp-glyph-stack" aria-hidden="true">
            <i style="--h:74%"><span class="sg-a" style="--s:46%"></span><span class="sg-b" style="--s:32%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:96%"><span class="sg-a" style="--s:38%"></span><span class="sg-b" style="--s:40%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:62%"><span class="sg-a" style="--s:52%"></span><span class="sg-b" style="--s:26%"></span><span class="sg-c" style="--s:22%"></span></i>
            <i style="--h:84%"><span class="sg-a" style="--s:30%"></span><span class="sg-b" style="--s:44%"></span><span class="sg-c" style="--s:26%"></span></i>
          </div>
          <h3 class="lp-widget-name">Barra apilada</h3>
          <p class="lp-widget-desc">Una métrica partida en hasta seis segmentos, en valores
            absolutos o al 100%. Para ver de qué está hecho el total.</p>
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
        <p class="lp-lede">Cada club entra a lo suyo y ve solo su plantel, sus datasets y sus
          vistas. No hay tablero compartido, no hay comparaciones entre clubes y no hay nada tuyo
          en la pantalla de otro.</p>
      </div>
      <ul class="lp-trust-list">
        <li class="lp-trust-item">
          <span class="lp-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <div>
            <p class="lp-trust-name">Un club por instalación</p>
            <p class="lp-trust-text">Tu plantel, tus datasets y tus vistas viven en tu propia base.
              Nadie más los consulta.</p>
          </div>
        </li>
        <li class="lp-trust-item">
          <span class="lp-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <div>
            <p class="lp-trust-name">El CSV se guarda crudo</p>
            <p class="lp-trust-text">Nada se transforma al subirlo. Si cargaste mal un dataset, lo
              borrás y lo volvés a subir sin arrastrar restos.</p>
          </div>
        </li>
        <li class="lp-trust-item">
          <span class="lp-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <div>
            <p class="lp-trust-name">Nada se aplica solo</p>
            <p class="lp-trust-text">Los nombres que no matchean los confirmás vos, y cada cambio
              que propone la IA se muestra antes de tocar la vista. Siempre hay deshacer.</p>
          </div>
        </li>
        <li class="lp-trust-item">
          <span class="lp-trust-icon" aria-hidden="true">
            <svg viewBox="0 0 24 24" focusable="false"><path d="M20 6L9 17l-5-5"/></svg>
          </span>
          <div>
            <p class="lp-trust-name">La IA corre del lado del servidor</p>
            <p class="lp-trust-text">Las llamadas al modelo salen desde el servidor: la clave nunca
              baja al navegador ni queda en el HTML.</p>
          </div>
        </li>
      </ul>
    </div>
  </section>

  <!-- 6. CTA FINAL -->
  <section class="lp-section">
    <div class="lp-wrap">
      <div class="lp-cta">
        <h2 class="lp-cta-title">Probalo con los datos del jueves pasado</h2>
        <p class="lp-cta-sub">Subís el plantel, tirás un CSV y escribís qué querés ver. Si la vista
          no te sirve, la borrás y armás otra.</p>
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
    <p class="lp-footer-legal">© <?= date('Y') ?> · Hecho para un club, no para un mercado</p>
  </div>
</footer>
</body>
</html>
