<?php
require __DIR__ . '/../app/bootstrap_page.php';
require_once __DIR__ . '/../app/Categorias.php';
require_once __DIR__ . '/../app/CategoryPermission.php';
require_once __DIR__ . '/../app/ViewPermission.php';
requireAuth();

$pageTitle = 'SportAnalysis';

$pdo = Database::get();
$clubId = Auth::clubId();
$userId = Auth::userId();

// Quién puede tocar las vistas DEL CLUB (renombrar / eliminar / regenerar con IA). Sobre sus
// vistas privadas cualquiera puede todo. Esto es solo la UI: el servidor valida igual.
$esAdminClub = Auth::esAdminClub();

// Generar vistas base NO es admin-only: api/base_views.php gatea POR CATEGORÍA, así que un miembro
// con la habilitación de kinesiología genera la suya sin ser administrador. Atar el botón a
// $esAdminClub le escondía la función justo a quien la necesita — el caso de uso que motivó todo
// el sistema de habilitaciones. (Los overviews por jugador sí siguen siendo admin-only: no tienen
// categoría y ninguna habilitación puede acotarlos.)
$puedeGenerarBase = $esAdminClub || CategoryPermission::categoriasEditables() !== [];

// Qué vistas se listan: las base del club (user_id NULL) más las privadas de este usuario. El
// predicado sale de Scope para que la regla esté escrita en un solo lugar de todo el sistema.
//
// El ORDEN de las tabs es POR USUARIO: `view_order` guarda la preferencia de cada uno y
// `views.position` queda como orden por defecto para quien nunca reordenó nada — de ahí el
// COALESCE sobre un LEFT JOIN (un usuario recién llegado no tiene ninguna fila en view_order).
// `:user_order` y `:user` son el mismo valor en dos placeholders distintos a propósito: repetir
// un placeholder nombrado solo funciona con emulación de prepares activada, y no vale atar esta
// query a esa configuración de PDO.
//
// `v.categoria` viaja en el SELECT aunque las tabs no la muestren: es lo que puedeEditarVista()
// necesita para resolver el permiso de una vista base de categoría (el kinesiólogo edita la vista
// de kinesiología). Sin ella, esa función cae a vistaCompleta() y paga un SELECT extra por cada
// vista que quiera evaluar — una consulta por tab para un dato que esta query ya tiene a mano.
$stmt = $pdo->prepare(
    'SELECT v.id, v.nombre, v.tipo, v.user_id, v.categoria
     FROM views v
     LEFT JOIN view_order vo ON vo.view_id = v.id AND vo.user_id = :user_order
     WHERE v.club_id = :club AND ' . Scope::sqlVistaVisible('v', ':user') . '
     ORDER BY COALESCE(vo.position, v.position), v.id'
);
$stmt->execute(['club' => $clubId, 'user' => $userId, 'user_order' => $userId]);
$views = $stmt->fetchAll();

// La vista por defecto es la primera que NO sea un overview por jugador: aterrizar en el tablero
// de un jugador suelto no le dice nada a nadie.
$mainViews = array_values(array_filter($views, fn($v) => $v['tipo'] !== 'player'));
$defaultViewId = (int) ($mainViews[0]['id'] ?? ($views[0]['id'] ?? 0));

// La barra de vistas se parte en DOS FILAS por origen: "Del club" (user_id NULL, las ve todo el
// club) y "Mías" (privadas de esta sesión). La fila ya dice de quién es cada vista, así que el
// chip "club" por tab desaparece: era ruido repetido en una barra que se escanea de un vistazo.
//
// Dentro de cada fila se vuelve a separar entre tabs inline y overviews por jugador, porque una
// fila con 37 jugadores no entra en ninguna pantalla. En la práctica los overviews son siempre
// del club (los genera BaseViewGenerator con user_id NULL), pero el split se hace por fila y no
// a mano para que una vista tipo 'player' privada — hoy imposible, mañana quizás — caiga sola en
// la fila correcta en vez de aparecer bajo "Del club".
$splitRow = static function (array $rowViews): array {
    return [
        array_values(array_filter($rowViews, fn($v) => $v['tipo'] !== 'player')),
        array_values(array_filter($rowViews, fn($v) => $v['tipo'] === 'player')),
    ];
};
[$clubTabs, $clubPlayerViews] = $splitRow(array_filter($views, fn($v) => Scope::esVistaDelClub($v)));
[$myTabs, $myPlayerViews] = $splitRow(array_filter($views, fn($v) => !Scope::esVistaDelClub($v)));

// `?view_id=` viene del cliente: sin validar, alguien pega el id de una vista de otro club y le
// renderizamos el tablero entero. Scope::find() devuelve la fila solo si es del club de la sesión.
// Si no lo es (o no existe, o es basura), caemos en silencio a la vista por defecto: no mostramos
// un error, porque un "esa vista no es tuya" confirmaría que el id existe.
$requestedViewId = (int) ($_GET['view_id'] ?? 0);
$activeViewId = ($requestedViewId > 0 && Scope::find($pdo, 'views', $requestedViewId) !== null)
    ? $requestedViewId
    : $defaultViewId;

$activeView = null;
foreach ($views as $vv) {
    if ($vv['id'] == $activeViewId) { $activeView = $vv; break; }
}
$activeViewName = $activeView['nombre'] ?? '';

// Sin vista activa (club sin vistas todavía) se asume "del club": no hay nada que editar, y el
// default seguro es el que menos permite.
$activeViewEsDelClub = $activeView === null ? true : Scope::esVistaDelClub($activeView);

// La regla la decide ViewPermission, no esta pantalla. Escrita a mano acá era
// `$esAdminClub || !$activeViewEsDelClub`, que desde el eje de categorías quedó CORTA: le negaba
// al kinesiólogo la vista base de kinesiología, que el servidor sí le deja editar
// (api/widgets.php → requireEditarVista). El resultado no era un permiso de más sino una pantalla
// que esconde botones que funcionan — el peor de los dos errores, porque no da ningún síntoma.
// La fila ya trae tipo y categoria, así que no relee nada.
$puedeEditarVistaActiva = $activeView !== null && puedeEditarVista($activeView);

// Todos los datasets están disponibles para cualquier widget (el cruce lo decide cada widget).
$stmt = $pdo->prepare(
    'SELECT id, nombre, categoria, column_schema FROM datasets WHERE club_id = :club ORDER BY categoria, uploaded_at'
);
$stmt->execute(['club' => $clubId]);
$datasets = $stmt->fetchAll();
foreach ($datasets as &$d) {
    $d['column_schema'] = json_decode($d['column_schema'], true);
}
unset($d);

$hasData = !empty($datasets);

// Clusters (categorías) que tienen datos: alimentan el modal de "vistas base".
//
// Se recorre Categorias::conVistaBase() y NO el catálogo entero. La lista escrita a mano que había
// acá incluía `entrenamientos` y `otros`, que no tienen vista base: el modal ofrecía un chip por
// cada una y BaseViewGenerator::cluster() las rechaza con "no tiene vista base del club". O sea,
// una opción visible cuyo único destino posible era un error. El orden de presentación y las
// etiquetas también salen de ahí, así que agregar una categoría no vuelve a requerir tocar esto.
$clusterCounts = [];
foreach ($datasets as $d) {
    $clusterCounts[$d['categoria']] = ($clusterCounts[$d['categoria']] ?? 0) + 1;
}
$baseClusters = [];
foreach (Categorias::conVistaBase() as $k => $lbl) {
    if (!empty($clusterCounts[$k])) {
        $baseClusters[] = ['categoria' => $k, 'label' => $lbl, 'count' => $clusterCounts[$k]];
    }
}
// Alimenta el "Generar también un overview por jugador (N jugadores)" del modal de vistas base.
$stmt = $pdo->prepare('SELECT COUNT(*) FROM players WHERE club_id = :club');
$stmt->execute(['club' => $clubId]);
$playerCount = (int) $stmt->fetchColumn();
$openBaseViews = isset($_GET['base_views']);

// Valores posibles para los filtros globales de vista (dimensiones universales del plantel).
// Solo del plantel del club: este array viaja al HTML como DIM_VALUES y llena el datalist del
// modal de filtros — con la tabla entera, filtrar por "Jugador" ofrecería nombres de otros clubes.
$dimValues = ['__familia' => [], '__sub_familia' => [], '__player_nombre' => []];
$stmt = $pdo->prepare('SELECT nombre, familia, sub_familia FROM players WHERE club_id = :club ORDER BY nombre');
$stmt->execute(['club' => $clubId]);
foreach ($stmt->fetchAll() as $p) {
    if ($p['familia'] !== null && $p['familia'] !== '') $dimValues['__familia'][$p['familia']] = true;
    if ($p['sub_familia'] !== null && $p['sub_familia'] !== '') $dimValues['__sub_familia'][$p['sub_familia']] = true;
    if ($p['nombre'] !== null && $p['nombre'] !== '') $dimValues['__player_nombre'][$p['nombre']] = true;
}
foreach ($dimValues as $k => $v) { $dimValues[$k] = array_keys($v); }

/**
 * Tabs inline de una fila. El `title` lleva el nombre completo porque el nombre visible se trunca
 * con ellipsis (una vista "Impacto acumulado de la pretemporada" no puede empujar a las demás).
 */
function viewTabsHtml(array $tabs, int $activeViewId): string
{
    $out = '';
    foreach ($tabs as $v) {
        $nombre = htmlspecialchars($v['nombre']);
        $out .= '<a class="view-tab' . ($v['id'] == $activeViewId ? ' active' : '') . '"'
            . ' href="?view_id=' . (int) $v['id'] . '"'
            . ' data-view-id="' . (int) $v['id'] . '"'
            . ' title="' . $nombre . '">'
            . '<span class="view-tab-name">' . $nombre . '</span></a>';
    }
    return $out;
}

/**
 * Desplegable de overviews por jugador de una fila. Devuelve '' si la fila no tiene ninguno, así
 * la fila "Mías" no arrastra un control vacío en el caso normal (los overviews son del club).
 */
function viewPlayersHtml(array $players, int $activeViewId, string $slug): string
{
    if (empty($players)) return '';
    $activo = null;
    foreach ($players as $pv) {
        if ($pv['id'] == $activeViewId) { $activo = $pv; break; }
    }
    $btnId = 'players-menu-btn-' . $slug;
    $menuId = 'players-menu-' . $slug;
    $label = $activo ? htmlspecialchars(str_replace('Overview — ', '', $activo['nombre'])) : 'Jugadores';

    $items = '';
    foreach ($players as $pv) {
        $items .= '<a class="widget-menu-item' . ($pv['id'] == $activeViewId ? ' active' : '') . '"'
            . ' role="menuitem" href="?view_id=' . (int) $pv['id'] . '">'
            . '<span class="wm-icon" aria-hidden="true">&#128100;</span> '
            . htmlspecialchars(str_replace('Overview — ', '', $pv['nombre'])) . '</a>';
    }

    return '<div class="tb-menu-wrap view-tabs-players">'
        . '<button class="view-tab view-tab-players' . ($activo ? ' active' : '') . '" id="' . $btnId . '" type="button"'
        . ' aria-haspopup="menu" aria-expanded="false" aria-controls="' . $menuId . '">'
        . '<span class="view-tab-name">' . $label . '</span> &#9662;</button>'
        . '<div class="widget-menu players-menu" id="' . $menuId . '" role="menu" hidden>' . $items . '</div>'
        . '</div>';
}

/**
 * Menú de desborde de una fila ("+3 ▾"). Nace vacío y oculto: quién entra y quién no depende del
 * ancho real, que solo se conoce en el cliente. js/widgets.js lo llena en cada layout. Reutiliza
 * el par .tb-menu-wrap + .widget-menu de la toolbar, y .players-menu para el scroll interno.
 */
function viewOverflowHtml(string $slug): string
{
    $btnId = 'view-more-btn-' . $slug;
    $menuId = 'view-more-menu-' . $slug;
    return '<div class="tb-menu-wrap vrail-overflow" id="view-more-' . $slug . '" hidden>'
        . '<button class="view-tab view-tab-players vrail-more" id="' . $btnId . '" type="button"'
        . ' aria-haspopup="menu" aria-expanded="false" aria-controls="' . $menuId . '">+0 &#9662;</button>'
        . '<div class="widget-menu players-menu" id="' . $menuId . '" role="menu" hidden></div>'
        . '</div>';
}

require __DIR__ . '/../app/views/head.php';
?>
<?php require __DIR__ . '/../app/views/appbar.php'; ?>

<div class="page page-wide">
    <?php if (!$hasData): ?>
        <div class="card">
            <div class="empty-state">
                Todavía no hay datos cargados. <a href="datos.php">Andá a Configuración → Datos</a> para subir al menos un dataset antes de crear análisis.
            </div>
        </div>
    <?php else: ?>
        <?php if (!empty($views)): ?>
            <!-- Barra de vistas en dos filas por origen. Ninguna fila scrollea: lo que no entra
                 en el ancho real lo manda js/widgets.js al menú "+N ▾" de esa misma fila. Con
                 cero vistas la barra no se renderiza — la card de abajo ya explica todo y dos
                 filas casi vacías arriba de ella serían puro alto perdido. -->
            <div class="view-rail" id="view-rail">
                <div class="vrail-row" data-row="club">
                    <span class="vrail-label" id="vrail-label-club"><span class="vrl-full">Del club</span><span class="vrl-abbr">Club</span></span>
                    <nav class="vrail-tabs" id="view-tabs-club" aria-labelledby="vrail-label-club"><?= viewTabsHtml($clubTabs, $activeViewId) ?></nav>
                    <?= viewOverflowHtml('club') ?>
                    <?= viewPlayersHtml($clubPlayerViews, $activeViewId, 'club') ?>
                    <?php if (empty($clubTabs) && empty($clubPlayerViews)): ?>
                        <?php if ($puedeGenerarBase): ?>
                            <button class="view-tab vrail-cta" id="gen-base-btn-row" type="button"><span aria-hidden="true">✦</span> Generar vistas base</button>
                        <?php else: ?>
                            <!-- Un miembro no puede generar las vistas base: se le dice el estado,
                                 no se le ofrece un botón que el servidor va a rechazar. -->
                            <span class="vrail-empty">Tu club todavía no tiene vistas base.</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
                <!-- La fila "Mías" mantiene su etiqueta aunque esté vacía: es donde va a caer lo
                     que el usuario cree, y con la etiqueta puesta "+ Nueva vista" se lee como
                     "creá una vista tuya" y no como "creá una vista del club". -->
                <div class="vrail-row" data-row="mine">
                    <span class="vrail-label" id="vrail-label-mine"><span class="vrl-full">Mías</span><span class="vrl-abbr">Mías</span></span>
                    <nav class="vrail-tabs" id="view-tabs-mine" aria-labelledby="vrail-label-mine"><?= viewTabsHtml($myTabs, $activeViewId) ?></nav>
                    <?= viewOverflowHtml('mine') ?>
                    <?= viewPlayersHtml($myPlayerViews, $activeViewId, 'mine') ?>
                    <button class="view-tab-add" id="add-view-btn" type="button">+ <span class="vta-full">Nueva vista</span><span class="vta-abbr">Nueva</span></button>
                </div>
            </div>
        <?php endif; ?>

        <?php if (empty($views)): ?>
            <?php if ($puedeGenerarBase): ?>
                <div class="card">
                    <div class="card-title">Arrancá con tus vistas base</div>
                    <div class="card-sub">Dejá que la IA arme un tablero por cada tipo de dato que cargaste (partidos, entrenamientos, fuerza…) más un overview por jugador. Después las editás o creás las tuyas a mano.</div>
                    <div class="btn-row">
                        <button class="btn" id="gen-base-btn-empty" type="button"><span class="btn-spark" aria-hidden="true">✦</span> Generar vistas base con IA</button>
                        <button class="btn-secondary btn" id="add-view-btn-empty" type="button">+ Vista vacía</button>
                    </div>
                </div>
            <?php else: ?>
                <!-- Un miembro no puede generar las vistas base del club: ofrecerle el botón sería
                     ofrecerle un 403. Se le dice a quién pedírselas y se le deja lo que sí puede
                     hacer solo — crear vistas propias. -->
                <div class="card">
                    <div class="card-title">Tu club todavía no tiene vistas base</div>
                    <div class="card-sub">Las vistas base las genera con IA un administrador del club: un tablero por cada tipo de dato cargado (partidos, entrenamientos, fuerza…) más un overview por jugador. Pedile a un administrador de tu club que las genere. Mientras tanto podés armar vistas propias — solo las ves vos.</div>
                    <div class="btn-row">
                        <button class="btn" id="add-view-btn-empty" type="button">+ Vista vacía</button>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="dash-toolbar">
                <div class="tb-left">
                    <button class="btn-secondary btn" id="metrics-btn" type="button" title="Métricas configurables">Métricas</button>
                    <button class="btn-secondary btn" id="filters-btn" type="button">Filtros</button>
                    <div class="tb-menu-wrap">
                        <button class="btn-secondary btn tb-more-btn" id="view-actions-btn" type="button"
                                aria-haspopup="menu" aria-expanded="false" aria-controls="view-actions-menu" aria-label="Más acciones de la vista">⋯</button>
                        <?php
                        // Sobre una vista DEL CLUB, renombrar y eliminar son cosa de un admin_club.
                        // Para el resto se deshabilitan con el motivo en el title, en vez de
                        // desaparecer: que la acción exista y esté explicado por qué no está
                        // disponible se lee mejor que un menú que cambia de forma según la tab.
                        // "Generar vistas base con IA" sí se esconde: no es una acción sobre esta
                        // vista sino una capacidad del club que un miembro nunca va a tener, y un
                        // ítem permanentemente gris es basura visual.
                        // Esconder NO protege: el servidor valida el permiso igual. Esto solo
                        // evita ofrecer lo que va a terminar rechazado.
                        //
                        // El motivo cambia según QUÉ le falta, porque la acción que tiene que
                        // tomar es distinta: una habilitación de categoría se la da un admin de su
                        // club en un click; que lo asciendan a administrador, no. Son los mismos
                        // dos mensajes que devuelve requireEditarVista(), a propósito: leer una
                        // cosa en la pantalla y otra en el error sería peor que no explicar nada.
                        $catVistaActiva = (string) ($activeView['categoria'] ?? '');
                        $motivoBloqueo = ($activeView !== null && ($activeView['tipo'] ?? '') === 'cluster' && $catVistaActiva !== '')
                            ? 'Es la vista base de ' . Categorias::label($catVistaActiva)
                                . ': para modificarla necesitás esa categoría habilitada, o ser administrador del club.'
                            : 'Es una vista del club: solo un administrador del club puede modificarla.';
                        $bloqueoVistaClub = $puedeEditarVistaActiva
                            ? ''
                            : ' disabled title="' . htmlspecialchars($motivoBloqueo, ENT_QUOTES) . '"';
                        ?>
                        <div class="widget-menu" id="view-actions-menu" role="menu" hidden>
                            <?php if (!$puedeEditarVistaActiva): ?>
                                <!-- El title del botón deshabilitado no existe en touch (tablet al
                                     borde de la cancha), así que el motivo va también como texto.
                                     Reutiliza .wm-user / .wm-user-meta del menú de usuario del
                                     appbar: mono chico, no clickeable, sin hover — exactamente lo
                                     que hace falta acá y por lo que no puede ser .widget-menu-item. -->
                                <div class="wm-user">
                                    <div class="wm-user-meta"><?= htmlspecialchars($motivoBloqueo) ?></div>
                                </div>
                                <div class="widget-menu-sep" role="separator"></div>
                            <?php endif; ?>
                            <button class="widget-menu-item" id="rename-view-btn" type="button" role="menuitem"<?= $bloqueoVistaClub ?>><span class="wm-icon" aria-hidden="true">✎</span> Renombrar vista</button>
                            <?php if ($puedeGenerarBase): ?>
                                <button class="widget-menu-item" id="gen-base-btn" type="button" role="menuitem"><span class="wm-icon" aria-hidden="true">✦</span> Generar vistas base con IA</button>
                            <?php endif; ?>
                            <div class="widget-menu-sep" role="separator"></div>
                            <button class="widget-menu-item destructive" id="delete-view-btn" type="button" role="menuitem"<?= $bloqueoVistaClub ?>><span class="wm-icon" aria-hidden="true">🗑</span> Eliminar vista</button>
                        </div>
                    </div>
                </div>
                <?php if ($puedeEditarVistaActiva): ?>
                    <!-- Agregar un widget a una vista del club es admin (api/build_widget.php y
                         api/widgets.php validan por vista). Para un miembro, la CTA más grande de
                         la pantalla sería un 403 asegurado: no se renderiza. Los controles de cada
                         widget los saca js/widgets.js con el mismo criterio. -->
                    <div class="tb-right">
                        <!-- Generar / Regenerar la vista entera. Nace hidden y sin rótulo fijo: el
                             texto depende de si la vista ya tiene widgets, y eso solo se sabe
                             cuando responde api/widgets.php. Mostrarlo antes haría parpadear
                             "Generar" → "Regenerar", que es justo la diferencia entre una acción
                             inocua y una destructiva. loadWidgets() lo revela con el rótulo bueno. -->
                        <button class="btn-secondary btn btn-swap-label" id="generate-view-btn" type="button" hidden>
                            <span class="btn-spinner" aria-hidden="true"></span>
                            <span class="btn-spark" aria-hidden="true">✦</span>
                            <span class="btn-label">Generar con IA</span>
                            <span class="btn-loading-label">Generando…</span>
                        </button>
                        <button class="btn" id="add-widget-btn" type="button"><span class="btn-spark" aria-hidden="true">✦</span> Agregar widget</button>
                    </div>
                <?php endif; ?>
            </div>

            <div id="alert-box"></div>
            <div class="dash-grid" id="widget-grid"></div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<!-- Modal: nueva vista -->
<div class="modal-overlay hidden" id="view-modal">
    <div class="modal modal-narrow" role="dialog" aria-modal="true" aria-label="Nueva vista">
        <div class="modal-head">
            <div class="modal-title" id="view-modal-title">Nueva vista</div>
            <button class="modal-close" id="view-modal-close">&times;</button>
        </div>
        <div id="view-modal-alert"></div>
        <input type="hidden" id="view-id-input" value="">
        <div class="field">
            <label for="view-name-input">Nombre de la vista</label>
            <input type="text" id="view-name-input" placeholder="Ej: Carga de partidos">
        </div>
        <!-- La descripción NO es metadata administrativa: es el pedido con el que la IA arma el
             tablero entero. Se oculta al renombrar (renombrar no regenera nada). -->
        <div class="field" id="view-desc-field">
            <label for="view-desc-input">¿Qué querés ver en esta vista?</label>
            <textarea id="view-desc-input" placeholder="Ej: Quiero ver la distancia total por partido, un ranking de sprints por jugador y la comparación backs vs forwards"></textarea>
            <div class="field-hint">Describilo con tus palabras y la IA arma los widgets sola — puede tardar hasta un minuto. Si lo dejás vacío, la vista nace en blanco y la armás a mano.</div>
        </div>
        <div class="btn-row">
            <button class="btn btn-swap-label" id="view-create-btn" type="button">
                <span class="btn-spinner" aria-hidden="true"></span>
                <span class="btn-label">Crear vista</span>
                <span class="btn-loading-label">Creando…</span>
            </button>
        </div>
        <div id="view-modal-progress"></div>
    </div>
</div>

<!-- Modal: generar vistas base con IA -->
<div class="modal-overlay hidden" id="base-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Generar vistas base">
        <div class="modal-head">
            <div class="modal-title">✦ Generar vistas base con IA</div>
            <button class="modal-close" id="base-modal-close">&times;</button>
        </div>
        <div id="base-modal-alert"></div>

        <!-- Pantalla 1: elegir modo -->
        <div id="base-screen-mode">
            <p class="card-sub" style="margin-top:0;">
                La IA arma un tablero por cada tipo de dato que cargaste (partidos, entrenamientos, etc.), con las métricas que le interesan a un preparador físico de rugby. Cuando una categoría tiene varios partidos o sesiones, los cruza y te muestra la info <strong>agregada</strong> a través de todos.
            </p>
            <div class="field">
                <label>Se detectaron estos grupos de datos</label>
                <div class="base-cluster-chips" id="base-cluster-chips"></div>
            </div>
            <div class="field">
                <label>¿Cómo querés generarlas?</label>
                <div class="base-mode-options">
                    <label class="base-mode-opt">
                        <input type="radio" name="base-mode" value="auto" checked>
                        <span><strong>Automático</strong><br><span class="card-sub">La IA elige qué mostrar a partir de tus datos.</span></span>
                    </label>
                    <label class="base-mode-opt">
                        <input type="radio" name="base-mode" value="guided">
                        <span><strong>Guiado</strong><br><span class="card-sub">Vos marcás qué querés ver en cada grupo antes de generar.</span></span>
                    </label>
                </div>
            </div>
            <label class="base-player-toggle">
                <input type="checkbox" id="base-players-toggle" checked>
                <span>Generar también un overview por jugador (<span id="base-player-count">0</span> jugadores)</span>
            </label>
            <div class="btn-row">
                <button class="btn" id="base-continue-btn" type="button">Continuar</button>
            </div>
        </div>

        <!-- Pantalla 2: guiado (checklist + texto por cluster) -->
        <div id="base-screen-guided" hidden>
            <p class="card-sub" style="margin-top:0;">Marcá lo que quieras ver en cada grupo y/o escribilo con tus palabras. Lo que dejes vacío, lo arma la IA sola.</p>
            <div id="base-guided-clusters"></div>
            <div class="btn-row">
                <button class="btn-secondary btn" id="base-back-btn" type="button">← Volver</button>
                <button class="btn" id="base-generate-btn" type="button">Generar vistas</button>
            </div>
        </div>

        <!-- Pantalla 3: progreso -->
        <div id="base-screen-progress" hidden>
            <p class="card-sub" style="margin-top:0;">Generando tus vistas base. Esto puede tardar un momento por cada grupo.</p>
            <div id="base-progress-list"></div>
        </div>
    </div>
</div>

<!-- Modal: agregar widget con IA (prompt + repreguntas) -->
<div class="modal-overlay hidden" id="prompt-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Agregar widget">
        <div class="modal-head">
            <div class="modal-title">Agregar widget</div>
            <button class="modal-close" id="prompt-modal-close">&times;</button>
        </div>
        <div id="prompt-modal-alert"></div>
        <div class="field">
            <label for="pm-name">Nombre del widget</label>
            <input type="text" id="pm-name" placeholder="Ej: Metros promedio por partido">
        </div>
        <div class="field">
            <label for="pm-prompt">¿Qué querés ver? Describilo y la IA lo arma</label>
            <textarea id="pm-prompt" placeholder="Ej: Promedio de metros recorridos por partido, comparando backs y forwards"></textarea>
            <div class="pm-examples" id="pm-examples"></div>
        </div>
        <div id="pm-questions"></div>
        <div class="btn-row">
            <button class="btn-secondary btn" id="pm-manual-btn" type="button">Crear a mano</button>
            <button class="btn" id="pm-submit-btn" type="button">Generar con IA</button>
        </div>
        <div id="pm-preview"></div>
    </div>
</div>

<!-- Modal: editor manual de widget -->
<div class="modal-overlay hidden" id="widget-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-labelledby="widget-modal-title">
        <div class="modal-head">
            <div class="modal-title" id="widget-modal-title">Nuevo widget</div>
            <button class="modal-close" id="widget-modal-close">&times;</button>
        </div>
        <div id="widget-modal-alert"></div>
        <form id="widget-form">
            <input type="hidden" id="wf-id">
            <div class="field-row">
                <div class="field">
                    <label>Tipo</label>
                    <select id="wf-type">
                        <option value="kpi_card">KPI card</option>
                        <option value="table">Tabla</option>
                        <option value="line_chart">Línea temporal</option>
                        <option value="bar_chart">Barra por jugador/categoría</option>
                        <option value="stacked_bar">Barra apilada</option>
                    </select>
                </div>
                <div class="field">
                    <label>Título</label>
                    <input type="text" id="wf-title" placeholder="Ej: Distancia por sesión">
                </div>
            </div>
            <div class="field">
                <label>Datasets (uno o varios para cruzar)</label>
                <div class="checkbox-list" id="wf-datasets"></div>
            </div>

            <div id="wf-sections"></div>

            <div class="field wf-filter-block">
                <label>Filtro del widget (opcional)</label>
                <div class="card-sub" style="margin-top:0;">Filtra solo este widget por una de sus columnas. Para filtrar toda la vista por familia/jugador, usá "Filtros" arriba.</div>
                <div class="field-row">
                    <div class="field"><select id="wf-filter-column"><option value="">Sin filtro</option></select></div>
                    <div class="field">
                        <select id="wf-filter-operator">
                            <option value="eq">= igual a</option>
                            <option value="neq">≠ distinto de</option>
                            <option value="gt">&gt; mayor que</option>
                            <option value="gte">&gt;= mayor o igual</option>
                            <option value="lt">&lt; menor que</option>
                            <option value="lte">&lt;= menor o igual</option>
                        </select>
                    </div>
                    <div class="field"><input type="text" id="wf-filter-value" placeholder="Valor"></div>
                </div>
            </div>

            <div class="btn-row">
                <button type="submit" class="btn">Guardar widget</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: modificar con IA -->
<div class="modal-overlay hidden" id="ai-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Modificar con IA">
        <div class="modal-head">
            <div class="modal-title">Modificar con IA</div>
            <button class="modal-close" id="ai-modal-close">&times;</button>
        </div>
        <div id="ai-modal-alert"></div>
        <input type="hidden" id="ai-widget-id">
        <div class="field">
            <label>Qué querés cambiar</label>
            <textarea id="ai-instruction" placeholder="Ej: Cambiá el color de la regla condicional a rojo cuando supere 1.5"></textarea>
        </div>
        <div class="btn-row">
            <button class="btn" id="ai-propose-btn" type="button">Proponer cambio</button>
        </div>
        <div id="ai-preview-container"></div>
    </div>
</div>

<!-- Modal: metricas configurables -->
<div class="modal-overlay hidden" id="metrics-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Métricas configurables">
        <div class="modal-head">
            <div class="modal-title">Métricas configurables</div>
            <button class="modal-close" id="metrics-modal-close">&times;</button>
        </div>
        <div class="card-sub">Opcional: normalmente la IA arma las métricas sola desde el prompt. Usá esto solo si querés una fórmula fija reutilizable.</div>
        <div id="metrics-list"></div>
        <hr style="border-color:var(--border); margin: var(--space-4) 0;">
        <div class="field-row">
            <div class="field">
                <label>Dataset</label>
                <select id="metric-dataset">
                    <?php foreach ($datasets as $d): ?>
                        <option value="<?= $d['id'] ?>"><?= htmlspecialchars($d['nombre']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Operación</label>
                <select id="metric-operation">
                    <option value="sum">Suma</option>
                    <option value="subtract">Resta (col1 - col2)</option>
                    <option value="multiply">Multiplicación</option>
                    <option value="divide">División (col1 / col2)</option>
                    <option value="ratio">Ratio % (col1 / col2 × 100)</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label>Nombre</label>
            <input type="text" id="metric-nombre" placeholder="Ej: Metros por minuto">
        </div>
        <div class="field">
            <label>Columnas (numéricas, en orden)</label>
            <div id="metric-columns-container"></div>
        </div>
        <div class="btn-row">
            <button class="btn" id="metric-create-btn" type="button">Crear métrica</button>
        </div>
    </div>
</div>

<!-- Modal: filtros globales de la vista (dimensiones universales) -->
<div class="modal-overlay hidden" id="filters-modal">
    <div class="modal" role="dialog" aria-modal="true" aria-label="Filtros de la vista">
        <div class="modal-head">
            <div class="modal-title">Filtros de la vista</div>
            <button class="modal-close" id="filters-modal-close">&times;</button>
        </div>
        <div class="card-sub">Segmentan <strong>toda la vista</strong>: aplican a todos los widgets, sin importar qué dataset use cada uno. Para filtrar por una columna propia de un gráfico, usá el filtro dentro de "Editar" de ese widget.</div>
        <div id="filters-list"></div>
        <hr style="border-color:var(--border); margin: var(--space-4) 0;">
        <div class="field-row">
            <div class="field">
                <label>Dimensión</label>
                <select id="filter-dimension">
                    <option value="__familia">Familia (back/forward)</option>
                    <option value="__sub_familia">Sub-familia</option>
                    <option value="__player_nombre">Jugador</option>
                </select>
            </div>
            <div class="field">
                <label>Condición</label>
                <select id="filter-operator">
                    <option value="eq">es</option>
                    <option value="neq">no es</option>
                </select>
            </div>
        </div>
        <div class="field">
            <label>Valor</label>
            <input type="text" id="filter-value" list="filter-value-list" placeholder="Ej: forward">
            <datalist id="filter-value-list"></datalist>
        </div>
        <div class="btn-row">
            <button class="btn" id="filter-create-btn" type="button">Agregar filtro</button>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script src="<?= asset('../js/api.js') ?>"></script>
<script src="<?= asset('../js/wizard.js') ?>"></script>
<script>
const ACTIVE_VIEW_ID = <?= (int) $activeViewId ?>;
const DATASETS = <?= json_encode($datasets, JSON_UNESCAPED_UNICODE) ?>;
const DIM_VALUES = <?= json_encode($dimValues, JSON_UNESCAPED_UNICODE) ?>;
const HAS_VIEWS = <?= empty($views) ? 'false' : 'true' ?>;
const BASE_CLUSTERS = <?= json_encode($baseClusters, JSON_UNESCAPED_UNICODE) ?>;
const PLAYER_COUNT = <?= $playerCount ?>;
const OPEN_BASE_VIEWS = <?= $openBaseViews ? 'true' : 'false' ?>;
const ACTIVE_VIEW_NAME = <?= json_encode($activeViewName, JSON_UNESCAPED_UNICODE) ?>;
// Etiquetas de categoría desde el servidor, NO hardcodeadas en el JS. widgets.js tenía su propia
// copia y agrupaba con Object.keys() sobre ella: una categoría que faltara en esa lista no salía
// sin etiqueta, directamente NO SE VEÍA en el editor de widgets. Con kinesiología eso significaba
// que el kinesiólogo no encontraba sus propios datasets.
const CATEGORIA_LABELS = <?= json_encode(Categorias::labels(), JSON_UNESCAPED_UNICODE) ?>;
// Permisos, solo para decidir qué ofrece la UI. El servidor los vuelve a validar en cada endpoint:
// tocar estas constantes desde la consola no habilita nada.
const IS_CLUB_ADMIN = <?= $esAdminClub ? 'true' : 'false' ?>;          // admin_club o superadmin
const ACTIVE_VIEW_IS_CLUB = <?= $activeViewEsDelClub ? 'true' : 'false' ?>; // vista base del club vs. privada
const CAN_EDIT_ACTIVE_VIEW = <?= $puedeEditarVistaActiva ? 'true' : 'false' ?>; // renombrar / eliminar
// Categorías que este usuario puede escribir. Decide si se le ofrece "Generar vistas base": el
// gate del servidor (api/base_views.php) es por categoría, no por nivel, así que mostrar el botón
// solo a los admin le escondía la función al kinesiólogo que sí puede usarla.
const CATEGORIAS_EDITABLES = <?= json_encode(CategoryPermission::categoriasEditables(), JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="<?= asset('../js/chart-init.js') ?>"></script>
<script src="<?= asset('../js/widgets.js') ?>"></script>
</body>
</html>
