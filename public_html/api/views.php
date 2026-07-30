<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/ViewPermission.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$pdo = Database::get();

// Sin GET: el listado JSON de vistas no tenía un solo caller (ni en js/ ni en steps/) y se borró
// en vez de arrastrarlo al modelo por usuario. Las tabs las renderiza steps/analysis.php.
requireMethod(['POST', 'DELETE']);

if ($method === 'POST') {
    $action = $_POST['action'] ?? '';
    if ($action === 'rename') {
        handleRename($pdo);
    } elseif ($action === 'reorder') {
        handleReorder($pdo);
    } else {
        handleSave($pdo);
    }
    exit;
}

if ($method === 'DELETE') {
    handleDelete($pdo);
    exit;
}

function handleSave(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    // La `description` NO es una nota al pie: es EL PROMPT con el que api/generate.php arma la
    // grilla entera de la vista (ViewGenerator la lee de la fila, no la recibe por parámetro).
    $description = trim($_POST['description'] ?? '');
    // Una vista es un tab de SportAnalysis que se llena con widgets. No requiere descripción ni
    // datasets pre-asignados: cada widget elige sus propios datasets al crearse, y si no hay
    // ninguno asignado el generador le ofrece a la IA todos los del club.
    $datasetIds = array_map('intval', $_POST['dataset_ids'] ?? []);
    $esNueva = $id <= 0;

    // Ambos vienen del cliente. La vista, porque el UPDATE de abajo apuntaría a una ajena; los
    // datasets, porque viajan a un INSERT en view_datasets (donde no hay WHERE que los filtre).
    // En la rama de update va además el permiso de escritura: una vista del club la ven todos,
    // pero solo un admin la edita.
    if ($id > 0) {
        requireEditarVistaId($pdo, $id);
    }
    if (!empty($datasetIds)) {
        Scope::requireAll($pdo, 'datasets', $datasetIds);
    }

    $clubId = Auth::clubId();
    $userId = Auth::userId();

    $pdo->beginTransaction();
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre, description = :description WHERE id = :id AND club_id = :club');
            $stmt->execute(['nombre' => $nombre, 'description' => $description, 'id' => $id, 'club' => $clubId]);
        } else {
            if ($nombre === '') {
                // Solo las vistas VISIBLES para este usuario. Contando todo el club, dos usuarios
                // generaban "Vista N" con el mismo N, y el número no significaba nada para quien
                // lo lee: incluía privadas ajenas que nunca va a ver en sus tabs.
                $cStmt = $pdo->prepare(
                    'SELECT COUNT(*) FROM views v WHERE v.club_id = :club AND ' . Scope::sqlVistaVisible()
                );
                $cStmt->execute(['club' => $clubId, 'user' => $userId]);
                $count = (int) $cStmt->fetchColumn();
                $nombre = 'Vista ' . ($count + 1);
            }
            // Misma historia con la posición por defecto: "al final" es al final de las tabs que
            // ESTE usuario ve. (El orden real, si reordenó, vive en view_order — ver handleReorder.)
            $pStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(v.position), -1) + 1 FROM views v WHERE v.club_id = :club AND ' . Scope::sqlVistaVisible()
            );
            $pStmt->execute(['club' => $clubId, 'user' => $userId]);
            $pos = (int) $pStmt->fetchColumn();

            // ÚNICO lugar del sistema donde nace una vista privada. `user_id` explícito y siempre
            // el de la sesión: una vista creada a mano nace privada, sin excepción. Las vistas del
            // club (user_id NULL) las crea solamente BaseViewGenerator.
            $stmt = $pdo->prepare(
                'INSERT INTO views (club_id, user_id, nombre, description, position)
                 VALUES (:club, :user, :nombre, :description, :position)'
            );
            $stmt->execute([
                'club' => $clubId,
                'user' => $userId,
                'nombre' => $nombre,
                'description' => $description,
                'position' => $pos,
            ]);
            $id = (int) $pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM view_datasets WHERE view_id = :view_id AND club_id = :club')
            ->execute(['view_id' => $id, 'club' => $clubId]);
        $linkStmt = $pdo->prepare('INSERT INTO view_datasets (club_id, view_id, dataset_id) VALUES (:club, :view_id, :dataset_id)');
        foreach (array_unique($datasetIds) as $datasetId) {
            $linkStmt->execute(['club' => $clubId, 'view_id' => $id, 'dataset_id' => $datasetId]);
        }

        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar la vista: ' . $e->getMessage());
    }

    // `should_generate` le dice al cliente que corresponde encadenar un POST a api/generate.php con
    // este id. Se decide acá y no en el navegador para que la regla viva de un solo lado.
    //
    // POR QUÉ NO SE GENERA ACÁ MISMO. La llamada a la IA tarda ~60s y este endpoint no está armado
    // para eso: no hace session_write_close() (dejaría el lock de sesión tomado un minuto, colgando
    // todas las requests del usuario) ni sube el max_execution_time. api/generate.php sí hace las
    // dos cosas. Además, en dos pasos la UI puede mostrar "generando…" en vez de un formulario
    // congelado, y si la IA falla la vista igual quedó creada.
    //
    // Solo en el ALTA: sobre una vista que ya existe, regenerar es destructivo (generate() borra los
    // widgets actuales), y guardar un cambio de nombre no puede tener ese efecto de lado. Regenerar
    // una vista existente es un botón aparte y explícito.
    echo json_encode([
        'ok' => true,
        'id' => $id,
        'nombre' => $nombre,
        'created' => $esNueva,
        'should_generate' => $esNueva && $description !== '',
    ]);
}

/**
 * Renombra una vista sin tocar nada más (ni description ni sus datasets). Sirve para cualquier
 * tipo de vista, incluidas las base (cluster/player), donde handleSave borraría metadata.
 */
function handleRename(PDO $pdo): void
{
    $id = (int) ($_POST['id'] ?? 0);
    $nombre = trim($_POST['nombre'] ?? '');
    if ($id <= 0 || $nombre === '') {
        respondError(400, 'Falta el id o el nombre.');
    }
    // Renombrar una vista del club se lo renombra a todo el club: solo un admin.
    requireEditarVistaId($pdo, $id);
    $stmt = $pdo->prepare('UPDATE views SET nombre = :nombre WHERE id = :id AND club_id = :club');
    $stmt->execute(['nombre' => $nombre, 'id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true, 'id' => $id, 'nombre' => $nombre]);
}

/**
 * Persiste el orden de tabs DE ESTE USUARIO. Recibe ids[] en el orden nuevo.
 *
 * Escribe en `view_order (user_id, view_id, position)` y NUNCA más en `views.position`. Sobre una
 * sola columna compartida el reorder estaba roto de dos formas a la vez:
 *
 *   · El cliente manda solo las tabs QUE VE. Numerar esa lista 0..n pisaba la `position` de las
 *     vistas privadas de OTROS usuarios, que nunca aparecen en ella.
 *   · Las vistas base son filas compartidas por el club: arrastrar una tab le reacomodaba el
 *     dashboard a todos los miembros.
 *
 * `views.position` queda como el orden POR DEFECTO (el que fija BaseViewGenerator al crear las
 * vistas base), para el usuario que todavía no reordenó nada.
 *
 * Sin chequeo de admin a propósito: reordenar es una preferencia personal. Incluso sobre las tabs
 * del club, el efecto no sale de la propia fila de view_order del usuario.
 */
function handleReorder(PDO $pdo): void
{
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) {
        respondError(400, 'Faltan los ids.');
    }
    // Lista arbitraria del cliente: falla en bloque si alguna vista no es visible para la sesión
    // (otro club, o privada de otro usuario). Devuelve los ids ya normalizados a int, sin
    // duplicados y en el mismo orden: es exactamente lo que hay que numerar.
    $ids = Scope::requireAll($pdo, 'views', $ids);

    // UPSERT: la primera vez que un usuario arrastra una tab puede no tener fila todavía.
    $stmt = $pdo->prepare(
        'INSERT INTO view_order (user_id, view_id, position) VALUES (:user, :view_id, :position)
         ON DUPLICATE KEY UPDATE position = VALUES(position)'
    );

    $pdo->beginTransaction();
    try {
        foreach ($ids as $i => $viewId) {
            $stmt->execute(['user' => Auth::userId(), 'view_id' => $viewId, 'position' => $i]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        $pdo->rollBack();
        respondError(500, 'Error al guardar el orden: ' . $e->getMessage());
    }
    echo json_encode(['ok' => true]);
}

function handleDelete(PDO $pdo): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        respondError(400, 'Falta el id de la vista a eliminar.');
    }
    // Borrar una vista del club se la borra a todo el club (con sus widgets, en cascada): admin.
    requireEditarVistaId($pdo, $id);
    $stmt = $pdo->prepare('DELETE FROM views WHERE id = :id AND club_id = :club');
    $stmt->execute(['id' => $id, 'club' => Auth::clubId()]);
    echo json_encode(['ok' => true]);
}
