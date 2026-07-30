<?php
/**
 * Permiso de ESCRITURA sobre una vista.
 *
 * Scope.php resuelve el eje de VISIBILIDAD (quién ve qué). Este archivo resuelve el otro eje, que
 * no se deduce del anterior:
 *
 *     vista privada (user_id = X)   → solo la ve X y solo X la modifica. Scope ya lo cierra: si el
 *                                     id llegó hasta acá y no es del club, es de esta sesión.
 *     vista del club (user_id NULL) → la ven TODOS los miembros. La modifica un admin_club (o el
 *                                     superadmin) y, si es la vista base de una CATEGORÍA, también
 *                                     quien tenga habilitada esa categoría. Ver abajo.
 *
 * POR QUÉ HACE FALTA. Las vistas base son filas COMPARTIDAS por todo el club, y
 * `BaseViewGenerator::upsertView()` hace sobre ellas un UPDATE y tres DELETE (widgets,
 * view_datasets, view_filters). Sin este gate, cualquier miembro apretando "regenerar" —o
 * borrando un widget, o arrastrando la grilla— le vaciaba o le reacomodaba el tablero a todo el
 * club desde un botón normal de la UI.
 *
 * ── LA VISTA DE UNA CATEGORÍA LA EDITA EL DUEÑO DE ESA CATEGORÍA ─────────────────────────────
 *
 * Concretamente: `views.tipo = 'cluster'` con `categoria = X` la edita un admin_club O quien
 * tenga la habilitación de X (CategoryPermission). Motivo: si el kinesiólogo puede cargar los
 * datos de kinesiología pero no acomodar cómo se ven, la función está hecha por la mitad — cada
 * cambio de un umbral en un widget vuelve a necesitar un administrador.
 *
 * Lo que NO afloja esta regla, y es deliberado:
 *   - `tipo = 'player'` (los overview por jugador) → solo admin_club. Cruzan todas las categorías
 *     a la vez: no hay una sola habilitación que alcance para autorizarlos.
 *   - Vista del club SIN categoría (una 'manual' que alguien compartió, o un 'cluster' viejo con
 *     la columna en NULL) → solo admin_club. Sin categoría no hay a qué habilitación preguntarle,
 *     y ante la duda manda el permiso más bajo.
 *
 * ACÁ EL CORTE ES 403, NO 404, al revés que en Scope: la vista del club el usuario LA VE listada.
 * No hay existencia que ocultar; lo que hay que explicarle es por qué no la puede tocar.
 *
 * Como todo lo que corta con respondError(), requireEditarVista*() solo se puede usar desde
 * endpoints JSON (bootstrap_api.php ya carga Response.php). Desde una pantalla HTML usá
 * puedeEditarVista(), que no tiene efectos colaterales.
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scope.php';
require_once __DIR__ . '/Categorias.php';
require_once __DIR__ . '/CategoryPermission.php';
require_once __DIR__ . '/Database.php';

/**
 * ¿Puede esta sesión modificar esta vista? Sin efectos colaterales: para pantallas HTML y para
 * decidir qué botones se dibujan (el servidor revalida igual con requireEditarVista()).
 *
 * NO valida pertenencia al club: asume una fila que ya pasó por Scope::require()/find(), es decir
 * visible para la sesión. Los dos chequeos son independientes y hacen falta los dos.
 *
 * @param array $view Fila de `views`. Tiene que traer `user_id` y —para que la regla de categoría
 *                    pueda aplicarse— `tipo` y `categoria`. Un SELECT parcial no rompe nada: cae
 *                    al camino de vistaCompleta(), que las relee.
 */
function puedeEditarVista(array $view): bool
{
    // Vista privada: si llegó hasta acá vía Scope, es de esta sesión.
    if (!Scope::esVistaDelClub($view)) {
        return true;
    }

    // `Auth::esAdminClub()` y no `Auth::requireNivel('admin_club')`: requireNivel compara EXACTO,
    // sin jerarquía, y dejaría afuera al superadmin.
    if (Auth::esAdminClub()) {
        return true;
    }

    $view = vistaCompleta($view);
    if ($view === null) {
        return false;
    }

    if (($view['tipo'] ?? '') !== 'cluster') {
        return false;
    }

    $categoria = (string) ($view['categoria'] ?? '');
    if ($categoria === '') {
        return false;
    }

    return CategoryPermission::puedeEditarCategoria($categoria);
}

/**
 * Corta con 403 si la sesión no puede modificar esta vista.
 *
 * @param array $view Fila de `views` (ver puedeEditarVista()).
 */
function requireEditarVista(array $view): void
{
    // Los dos atajos baratos primero: ni la vista privada ni el admin necesitan releer nada.
    if (!Scope::esVistaDelClub($view) || Auth::esAdminClub()) {
        return;
    }

    // Se completa ANTES de preguntar para no releer la fila dos veces (una para decidir y otra
    // para armar el mensaje). Si no se pudo completar, puedeEditarVista() deniega igual.
    $completa = vistaCompleta($view) ?? $view;

    if (puedeEditarVista($completa)) {
        return;
    }

    // El mensaje cambia según qué le falta, porque la acción que tiene que tomar es distinta:
    // conseguir una habilitación de categoría es algo que un admin_club le da en un click; que
    // lo asciendan a administrador, no.
    $categoria = (string) ($completa['categoria'] ?? '');

    if (($completa['tipo'] ?? '') === 'cluster' && $categoria !== '') {
        respondError(403, 'Esta es la vista base de ' . Categorias::label($categoria)
            . ': para modificarla necesitás esa categoría habilitada, o ser administrador del club.');
    }

    respondError(403, 'Esta vista es del club: solo un administrador puede modificarla.');
}

/**
 * Las dos barreras de un `view_id` que llega del cliente, en una línea: 404 si no es visible
 * (Scope) y 403 si es del club y la sesión no la puede editar.
 *
 * @return array La fila de `views`, garantizadamente visible y editable por esta sesión.
 */
function requireEditarVistaId(PDO $pdo, int $viewId): array
{
    $view = Scope::require($pdo, 'views', $viewId);
    requireEditarVista($view);

    return $view;
}

/**
 * Completa una fila de `views` a la que le faltan las columnas que la regla de categoría necesita.
 *
 * Existe porque hay call sites que traen la vista con un SELECT recortado (`SELECT DISTINCT v.id,
 * v.user_id ...` en el reorder de widgets, por ejemplo). Antes de que la categoría importara, con
 * `user_id` alcanzaba. Ahora no, y decidir un permiso con menos columnas de las que la regla
 * necesita es fallar en silencio: le negaría a un kinesiólogo su propia vista según por qué
 * endpoint entró.
 *
 * Se relee solo en ese caso —fila incompleta, usuario no admin, vista del club—, que es raro y
 * nunca está en un loop caliente. Acotado por club: esto decide un permiso y no vale apoyarse en
 * que el llamador ya filtró.
 *
 * @return array|null La fila con tipo y categoria, o null si no se pudo resolver (→ denegar).
 */
function vistaCompleta(array $view): ?array
{
    if (array_key_exists('tipo', $view) && array_key_exists('categoria', $view)) {
        return $view;
    }

    $id = (int) ($view['id'] ?? 0);
    $clubId = Auth::clubId();
    if ($id <= 0 || $clubId <= 0) {
        return null;
    }

    try {
        $stmt = Database::get()->prepare(
            'SELECT id, user_id, tipo, categoria FROM views WHERE id = :id AND club_id = :club LIMIT 1'
        );
        $stmt->execute(['id' => $id, 'club' => $clubId]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }

    return $row ?: null;
}
