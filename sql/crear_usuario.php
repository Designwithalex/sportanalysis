<?php
/**
 * Alta de un usuario del club desde la línea de comandos.
 *
 * POR QUÉ EXISTE. El registro por la web es abierto pero nace `pending`: el usuario se anota, deja
 * su evidencia en verificacion.php y un admin lo aprueba. Eso está bien para que se sume alguien
 * solo, y es un rodeo cuando el club ya sabe a quién quiere adentro — el primer admin de un club,
 * o el cuerpo técnico que arranca el año. Este script hace esa alta directa, ya en `active`.
 *
 * LA CONTRASEÑA SE MUESTRA UNA SOLA VEZ. Se genera al azar, se guarda SOLO el hash y el texto plano
 * se imprime acá y no queda en ningún lado. No hay recuperación por mail en este MVP (el hosting no
 * manda correo), así que si se pierde, la salida es volver a correr esto o un UPDATE a mano sobre
 * `users.password_hash`. Cada uno la cambia desde su perfil, que pide la contraseña actual.
 *
 * NIVEL Y CATEGORÍAS NO SE COMBINAN COMO PARECE. `admin_club` SALTEA las habilitaciones: un admin
 * escribe todas las categorías, así que pasarle --categorias a un admin no acota nada, solo deja
 * filas que no se leen. Las habilitaciones son para `miembro` (ver CategoryPermission).
 *
 *   php sql/crear_usuario.php --email=x@y.com --nombre="Jorge Fernández" \
 *       --rol="Head coach" --nivel=admin_club
 *
 *   php sql/crear_usuario.php --email=x@y.com --nombre="Agustín Pérez" \
 *       --rol="Kinesiólogo" --nivel=miembro --categorias=kinesiologia
 *
 * Sin --write no escribe nada: muestra qué haría.
 */

if (PHP_SAPI !== 'cli') {
    exit("Solo por linea de comandos.\n");
}

require __DIR__ . '/../public_html/app/config.php';
require __DIR__ . '/../public_html/app/Categorias.php';

$opciones = [
    'email' => null, 'nombre' => null, 'rol' => null,
    'nivel' => 'miembro', 'categorias' => '', 'club' => '1', 'otorgante' => '',
];
$write = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--write') {
        $write = true;
        continue;
    }
    if (preg_match('/^--([a-z]+)=(.*)$/s', $arg, $m) && array_key_exists($m[1], $opciones)) {
        $opciones[$m[1]] = $m[2];
        continue;
    }
    exit("Argumento desconocido: $arg\n");
}

$email  = strtolower(trim((string) $opciones['email']));
$nombre = trim((string) $opciones['nombre']);
$rol    = trim((string) $opciones['rol']);
$nivel  = trim((string) $opciones['nivel']);
$clubId = (int) $opciones['club'];

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    exit("--email falta o no es un email válido.\n");
}
if ($nombre === '') {
    exit("--nombre es obligatorio: es lo que se muestra en la app.\n");
}
if (!in_array($nivel, ['miembro', 'admin_club', 'superadmin'], true)) {
    exit("--nivel debe ser miembro, admin_club o superadmin.\n");
}

$categorias = array_values(array_filter(array_map('trim', explode(',', (string) $opciones['categorias']))));
foreach ($categorias as $c) {
    if (!Categorias::esValida($c)) {
        exit("Categoría inválida: $c\nVálidas: " . implode(', ', Categorias::todas()) . "\n");
    }
}
if ($categorias && $nivel !== 'miembro') {
    // No se corta: se avisa. El grant es inofensivo, pero creer que acota a un admin sí es un error.
    fwrite(STDERR, "AVISO: --categorias no acota a un $nivel; los admins escriben todas las categorías.\n");
}

$pdo = new PDO(
    'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
    DB_USER,
    DB_PASS,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);

$club = $pdo->prepare('SELECT nombre FROM clubs WHERE id = :id');
$club->execute(['id' => $clubId]);
$clubNombre = $club->fetchColumn();
if ($clubNombre === false) {
    exit("No existe el club $clubId.\n");
}

// El email es UNIQUE en la base: chequear acá es para dar un mensaje claro en vez de un 1062.
$ya = $pdo->prepare('SELECT id, nombre, nivel, status FROM users WHERE email = :e');
$ya->execute(['e' => $email]);
if ($existente = $ya->fetch()) {
    exit(sprintf(
        "Ya existe un usuario con ese email: #%d %s (%s, %s). No se toca.\n",
        $existente['id'],
        $existente['nombre'],
        $existente['nivel'],
        $existente['status']
    ));
}

printf("%s\n", $write ? 'ESCRITURA' : 'simulacion (--write para aplicar)');
printf("  club       : %s (#%d)\n", $clubNombre, $clubId);
printf("  email      : %s\n", $email);
printf("  nombre     : %s\n", $nombre);
printf("  rol        : %s\n", $rol !== '' ? $rol : '(sin rol)');
printf("  nivel      : %s\n", $nivel);
printf("  status     : active (entra directo, sin pasar por verificacion.php)\n");
printf("  categorias : %s\n", $categorias ? implode(', ', $categorias) : ($nivel === 'miembro' ? '(ninguna — solo lectura)' : '(todas, por ser admin)'));

if (!$write) {
    printf("\nnada escrito.\n");
    exit;
}

/**
 * Contraseña al azar, legible al dictarla.
 *
 * Alfabeto sin los caracteres que se confunden leyendo (O/0, l/1/I): esta clave se pasa por
 * WhatsApp o se dicta, y un cero que alguien tipea como "o" es un usuario que no puede entrar y no
 * tiene cómo recuperarla. random_int() es criptográficamente seguro; rand() no.
 */
function contrasenaAlAzar(int $largo = 16): string
{
    $alfabeto = 'abcdefghijkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < $largo; $i++) {
        $out .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
    }

    return $out;
}

$plana = contrasenaAlAzar();

$pdo->beginTransaction();
try {
    $ins = $pdo->prepare(
        'INSERT INTO users (club_id, email, password_hash, nombre, rol, nivel, status)
         VALUES (:club, :email, :hash, :nombre, :rol, :nivel, "active")'
    );
    $ins->execute([
        'club'   => $clubId,
        'email'  => $email,
        'hash'   => password_hash($plana, PASSWORD_DEFAULT),
        'nombre' => $nombre,
        'rol'    => $rol !== '' ? $rol : null,
        'nivel'  => $nivel,
    ]);
    $userId = (int) $pdo->lastInsertId();

    if ($categorias) {
        // `otorgada_por` deja el rastro de quién habilitó qué. Vacío = alta por consola.
        $otorgante = trim((string) $opciones['otorgante']);
        $grant = $pdo->prepare(
            'INSERT INTO user_categorias (user_id, categoria, otorgada_por) VALUES (:u, :c, :por)'
        );
        foreach ($categorias as $c) {
            $grant->execute(['u' => $userId, 'c' => $c, 'por' => $otorgante !== '' ? (int) $otorgante : null]);
        }
    }

    $pdo->commit();
} catch (PDOException $e) {
    $pdo->rollBack();
    exit("\nFALLO, no se escribió nada: " . $e->getMessage() . "\n");
}

printf("\n  -> usuario #%d creado\n", $userId);
printf("  CONTRASEÑA (se muestra una sola vez): %s\n", $plana);
