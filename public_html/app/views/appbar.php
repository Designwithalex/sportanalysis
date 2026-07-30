<?php
/**
 * Partial: appbar-instrumento compartido por todas las pantallas.
 * Marca condensada + chip mono "IA" + baseline signal.
 *
 * $appbarAction (opcional) define la acción de la derecha:
 *   ['href' => ..., 'label' => ..., 'icon' => ..., 'primary' => bool]
 * Default: enlace a Configuración (para la superficie de análisis).
 *
 * A la derecha del slot de acción va el menú de usuario (nombre + club + rol + Cerrar sesión).
 * Ambos viven dentro de un .tb-left, que es el agrupador de acciones que ya usa la toolbar de
 * la vista: el .appbar es un flex con justify-content:space-between y necesita exactamente dos
 * hijos para que la marca quede a la izquierda y todo lo demás junto a la derecha.
 *
 * El desplegable reutiliza el patrón de menú de steps/analysis.php:
 * .tb-menu-wrap + .widget-menu + role="menu" + [hidden].
 */
require_once __DIR__ . '/../Auth.php';

$action = $appbarAction ?? ['href' => 'datos.php', 'label' => 'Configuración', 'icon' => '⚙'];
$linkClass = 'appbar-link' . (!empty($action['primary']) ? ' appbar-link-primary' : '');

$appbarUser = Auth::user();
// Prefijo hasta la raíz pública ('' en la raíz, '../' desde steps/). Fallback '../' porque el
// appbar hoy solo lo incluyen pantallas de steps/.
$appbarRoot = function_exists('pageRootPrefix') ? pageRootPrefix() : '../';
?>
<div class="appbar">
    <div class="appbar-brand">SportAnalysis <span class="appbar-sub">IA</span></div>
    <div class="tb-left">
        <a class="<?= $linkClass ?>" href="<?= htmlspecialchars($action['href']) ?>"><span class="appbar-link-icon" aria-hidden="true"><?= htmlspecialchars($action['icon'] ?? '') ?></span> <?= htmlspecialchars($action['label']) ?></a>
        <?php if ($appbarUser): ?>
            <div class="tb-menu-wrap">
                <button class="btn-secondary btn appbar-user-btn" id="appbar-user-btn" type="button"
                        aria-haspopup="menu" aria-expanded="false" aria-controls="appbar-user-menu"
                        aria-label="Menú de usuario">
                    <span class="appbar-sub" aria-hidden="true"><?= htmlspecialchars(Auth::initials($appbarUser['nombre'])) ?></span>
                    <span class="appbar-user-name"><?= htmlspecialchars($appbarUser['nombre']) ?></span>
                    <span aria-hidden="true">▾</span>
                </button>
                <div class="widget-menu" id="appbar-user-menu" role="menu" hidden>
                    <div class="wm-user">
                        <p class="wm-user-name"><?= htmlspecialchars($appbarUser['nombre']) ?></p>
                        <p class="wm-user-meta"><?= htmlspecialchars($appbarUser['club_nombre']) ?></p>
                        <?php if (!empty($appbarUser['rol'])): ?>
                            <p class="wm-user-meta"><?= htmlspecialchars($appbarUser['rol']) ?></p>
                        <?php endif; ?>
                    </div>
                    <div class="widget-menu-sep" role="separator"></div>
                    <a class="widget-menu-item" role="menuitem" href="<?= htmlspecialchars($appbarRoot) ?>perfil.php"><span class="wm-icon" aria-hidden="true">✎</span> Mi perfil</a>
                    <?php /* admin.php no estaba linkeada desde ningún lado y había que escribir la
                             URL a mano — siendo que es el único lugar donde se aprueban altas y se
                             habilitan categorías. Una pantalla a la que no se llega no existe.
                             Solo se muestra a quien puede entrar: para el resto devuelve 404. */ ?>
                    <?php if (Auth::esAdminClub()): ?>
                        <a class="widget-menu-item" role="menuitem" href="<?= htmlspecialchars($appbarRoot) ?>admin.php"><span class="wm-icon" aria-hidden="true">☑</span> Altas y permisos</a>
                    <?php endif; ?>
                    <?php /* Cerrar sesión va por POST con token: un logout por GET lo dispara
                              cualquier <img src="/logout.php"> de un tercero. */ ?>
                    <form method="post" action="<?= htmlspecialchars($appbarRoot) ?>logout.php">
                        <?= Csrf::field() ?>
                        <button class="widget-menu-item destructive" role="menuitem" type="submit"><span class="wm-icon" aria-hidden="true">⏻</span> Cerrar sesión</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php if ($appbarUser): ?>
<script>
// Toggle del menú de usuario. Va inline y no en un .js compartido porque el appbar lo incluyen
// pantallas que cargan distintos bundles (analysis.php carga widgets.js, plantel/datos no) y el
// partial tiene que funcionar solo. Mismo comportamiento que setupOverflowMenu() de widgets.js.
(function () {
    var trigger = document.getElementById('appbar-user-btn');
    var menu = document.getElementById('appbar-user-menu');
    if (!trigger || !menu) return;
    var wrap = trigger.parentElement;

    function close() {
        if (menu.hidden) return;
        menu.hidden = true;
        trigger.setAttribute('aria-expanded', 'false');
        document.removeEventListener('click', onOutside, true);
        document.removeEventListener('keydown', onEsc, true);
    }
    function onOutside(e) { if (!wrap.contains(e.target)) close(); }
    function onEsc(e) { if (e.key === 'Escape') { close(); trigger.focus(); } }

    trigger.addEventListener('click', function (e) {
        e.stopPropagation();
        if (!menu.hidden) { close(); return; }
        // Un solo menú abierto a la vez, también contra los de la toolbar de la vista.
        if (typeof closeOtherWidgetMenus === 'function') closeOtherWidgetMenus(menu);
        menu.hidden = false;
        trigger.setAttribute('aria-expanded', 'true');
        var first = menu.querySelector('.widget-menu-item');
        if (first) first.focus();
        document.addEventListener('click', onOutside, true);
        document.addEventListener('keydown', onEsc, true);
    });
})();
</script>
<?php endif; ?>
