<?php
/**
 * Permiso de ESCRITURA sobre una vista.
 *
 * Scope.php resuelve el eje de VISIBILIDAD (quién ve qué). Este archivo resuelve el otro eje, que
 * no se deduce del anterior:
 *
 *     vista privada (user_id = X)   → solo la ve X y solo X la modifica. Scope ya lo cierra: si el
 *                                     id llegó hasta acá y no es del club, es de esta sesión.
 *     vista del club (user_id NULL) → la ven TODOS los miembros, pero solo la modifica un
 *                                     admin_club (o el superadmin).
 *
 * POR QUÉ HACE FALTA. Las vistas base son filas COMPARTIDAS por todo el club, y
 * `BaseViewGenerator::upsertView()` hace sobre ellas un UPDATE y tres DELETE (widgets,
 * view_datasets, view_filters). Sin este gate, cualquier miembro apretando "regenerar" —o
 * borrando un widget, o arrastrando la grilla— le vaciaba o le reacomodaba el tablero a todo el
 * club desde un botón normal de la UI.
 *
 * ACÁ EL CORTE ES 403, NO 404, al revés que en Scope: la vista del club el usuario LA VE listada.
 * No hay existencia que ocultar; lo que hay que explicarle es por qué no la puede tocar.
 *
 * Como todo lo que corta con respondError(), solo se puede usar desde endpoints JSON
 * (bootstrap_api.php ya carga Response.php).
 */

require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Scope.php';

/**
 * Corta con 403 si la vista es del club y el usuario no es admin_club ni superadmin.
 *
 * NO valida pertenencia: asume una fila que ya pasó por Scope::require()/find(), es decir visible
 * para la sesión. Los dos chequeos son independientes y hacen falta los dos.
 *
 * `Auth::esAdminClub()` y no `Auth::requireNivel('admin_club')`: requireNivel compara EXACTO, sin
 * jerarquía, y dejaría afuera al superadmin.
 *
 * @param array $view Fila de `views` (tiene que traer la columna `user_id`).
 */
function requireEditarVista(array $view): void
{
    if (Scope::esVistaDelClub($view) && !Auth::esAdminClub()) {
        respondError(403, 'Esta vista es del club: solo un administrador puede modificarla.');
    }
}

/**
 * Las dos barreras de un `view_id` que llega del cliente, en una línea: 404 si no es visible
 * (Scope) y 403 si es del club y el usuario no es admin.
 *
 * @return array La fila de `views`, garantizadamente visible y editable por esta sesión.
 */
function requireEditarVistaId(PDO $pdo, int $viewId): array
{
    $view = Scope::require($pdo, 'views', $viewId);
    requireEditarVista($view);

    return $view;
}
