<?php
// El Dashboard fue reemplazado por SportAnalysis (analysis.php). Redirigimos para no romper
// bookmarks ni links viejos. Se conserva el query string (ej: view_id).
$qs = $_SERVER['QUERY_STRING'] ?? '';
header('Location: analysis.php' . ($qs !== '' ? '?' . $qs : ''));
exit;
