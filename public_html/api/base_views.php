<?php

require __DIR__ . '/../app/bootstrap_api.php';
require __DIR__ . '/../app/BaseViewGenerator.php';
// Explícito aunque BaseViewGenerator ya lo arrastre: este archivo lo usa directo en su gate de
// abajo, y depender de un require transitivo se rompe en silencio el día que el generador cambie.
require_once __DIR__ . '/../app/CategoryPermission.php';

// Guard de sesión. Va antes de session_write_close() (lee $_SESSION) y antes de tocar la base.
// Además valida el token anti-CSRF en todo método que no sea GET/HEAD.
requireAuth();

// Las vistas base (tipo 'cluster' y 'player') son DEL CLUB: filas compartidas por todos los
// miembros, con user_id NULL. Regenerarlas no es aditivo — BaseViewGenerator::upsertView() hace un
// UPDATE y tres DELETE sobre widgets, view_datasets y view_filters de la vista existente.
//
// EL GATE ES POR CATEGORÍA, NO GLOBAL. Antes acá había un `if (!Auth::esAdminClub()) 403` que
// cubría las tres acciones, y eso volvía imposible el caso de uso central de las habilitaciones:
// el kinesiólogo, que tiene la habilitación de `kinesiologia` pero es `miembro`, nunca llegaba a
// generar SU vista base. El chequeo fino ya vive adentro de BaseViewGenerator::generateCluster(),
// que llama a CategoryPermission::requireCategoria() de la categoría concreta antes de gastar una
// llamada a la IA.
//
// 'generate_players' SÍ queda admin-only: los overviews por jugador no tienen categoría, así que
// ninguna habilitación puede acotarlos y afectan a todo el club por igual.
//
// esAdminClub() y no requireNivel('admin_club'): requireNivel compara EXACTO y dejaría afuera al
// superadmin.
if (($_POST['action'] ?? '') === 'generate_players' && !Auth::esAdminClub()) {
    respondError(403, 'Los overviews por jugador son del club: solo un administrador puede generarlos.');
}

// 'suggest' no escribe nada, pero SÍ gasta una llamada a la IA. Sin este piso, un miembro sin
// ninguna habilitación podría pedirla en loop y quemar presupuesto de API sin poder generar nada
// después. Con al menos una categoría habilitada, el pedido tiene un destino posible.
if (CategoryPermission::categoriasEditables() === []) {
    respondError(403, 'No tenés ninguna categoría habilitada. Pedile a un administrador de tu club que te habilite.');
}

// Generar vistas base implica llamadas largas a la IA (~30-60s cada una) más reintentos con backoff.
// Subimos el límite de ejecución para que no corte a mitad de camino (Hostinger suele permitirlo).
@set_time_limit(180);
@ignore_user_abort(true);

requireMethod('POST');

// PHP mantiene un lock exclusivo sobre el archivo de sesión mientras está abierta, lo que serializa
// todas las requests del mismo usuario. Con una llamada a la IA de ~60s por delante, eso congelaría
// la app entera. Cerramos la sesión para escritura acá; a partir de este punto no se toca $_SESSION.
session_write_close();

$pdo = Database::get();
$action = $_POST['action'] ?? '';
$generator = new BaseViewGenerator($pdo);

try {
    switch ($action) {
        case 'suggest':
            // Checklist sugerida por cluster (modo guiado).
            echo json_encode(['ok' => true, 'clusters' => $generator->suggestChecklists()]);
            break;

        case 'generate_cluster':
            // Genera (o regenera) la vista base de UNA categoría. El cliente itera de a una.
            $categoria = trim($_POST['categoria'] ?? '');
            $intent = trim($_POST['intent'] ?? '');
            if ($categoria === '') {
                respondError(400, 'Falta la categoría.');
            }
            echo json_encode(['ok' => true] + $generator->generateCluster($categoria, $intent));
            break;

        case 'generate_players':
            // 1 llamada IA (plantilla) + N clones (una vista por jugador).
            $template = $generator->generatePlayerTemplate();
            $views = $generator->instantiatePlayerViews($template);
            echo json_encode(['ok' => true, 'created' => count($views), 'views' => $views]);
            break;

        default:
            respondError(400, 'Acción desconocida.');
    }
} catch (RuntimeException $e) {
    respondError(422, $e->getMessage());
}
