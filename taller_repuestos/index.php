<?php
declare(strict_types=1);
session_start();
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require __DIR__ . '/src/Database.php';
require __DIR__ . '/src/Actions.php';
require __DIR__ . '/src/Views.php';

$_SESSION['csrf'] ??= bin2hex(random_bytes(32));
$pages = ['inicio' => 'Resumen general', 'repuestos' => 'Repuestos', 'clientes' => 'Clientes',
    'proveedores' => 'Proveedores', 'compras' => 'Compras', 'ventas' => 'Ventas', 'inventario' => 'Inventario e historial'];
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'inicio';
if (!isset($pages[$page])) $page = 'inicio';
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$error = null;
$setup = null;
$db = null;
$preview = ($_GET['preview'] ?? '') === '1' && $_SERVER['REQUEST_METHOD'] === 'GET';
if ($preview) $page = 'inicio';
try {
    if (!$preview) {
    $db = database();
    }
} catch (RuntimeException $e) {
    $setup = $e->getMessage();
    http_response_code(503);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!is_string($_POST['csrf'] ?? null) || !hash_equals($_SESSION['csrf'], $_POST['csrf'])) {
            throw new InvalidArgumentException('La sesión del formulario venció. Recargue la página e intente de nuevo.');
        }
        if (!$db) throw new InvalidArgumentException('Configure la conexión antes de registrar operaciones.');
        $notice = action($db);
        header('HX-Push-Url: index.php?page=' . $page);
        if (($_SERVER['HTTP_HX_REQUEST'] ?? '') !== 'true') {
            $_SESSION['notice'] = $notice;
            header('Location: index.php?page=' . $page, true, 303);
            exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        http_response_code(422);
    } catch (Throwable $e) {
        error_log((string)$e);
        $message = $e->getMessage();
        $error = str_contains($message, '2601') || str_contains($message, '2627') ? 'Ya existe un registro con ese código o cédula.'
            : (str_contains($message, '50002') ? 'Debe existir el usuario técnico sistema. Ejecute el script de datos de prueba.'
            : 'No se pudo completar la operación. Verifique los datos y la conexión; no se guardaron cambios parciales en compras o ventas.');
        http_response_code(422);
    }
}
$partial = ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
ob_start();
?>
<div class="page-heading"><div><p class="eyebrow">CONTROL DEL NEGOCIO</p><h1><?= e($pages[$page]) ?></h1></div><span class="tag is-light">Prototipo académico</span></div>
<?php if ($notice): ?><div class="notification is-success is-light" role="status"><?= e($notice) ?></div><?php endif ?>
<?php if ($error): ?><div class="notification is-danger is-light" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if ($preview): ?>
    <div class="notification is-info is-light" role="status"><strong>Vista previa de solo lectura.</strong> Estos son datos ilustrativos, no consultas a SQL Server. Para registrar operaciones, configure la conexión. <a href="index.php">Salir de la vista previa</a></div>
    <?php dashboardView(['partes'=>24, 'bajos'=>3, 'vendido'=>'185500.00', 'clientes'=>12],
        [['nombre'=>'Filtro de aceite', 'stock_actual'=>3, 'stock_minimo'=>5], ['nombre'=>'Pastillas de freno', 'stock_actual'=>2, 'stock_minimo'=>4], ['nombre'=>'Bujía de encendido', 'stock_actual'=>4, 'stock_minimo'=>6]],
        [['id_venta'=>104,'fecha'=>'2026-10-28 14:32','total'=>'42500.00','nombre'=>'María Rodríguez'], ['id_venta'=>103,'fecha'=>'2026-10-28 12:15','total'=>'68000.00','nombre'=>'Carlos Jiménez'], ['id_venta'=>102,'fecha'=>'2026-10-28 10:48','total'=>'31000.00','nombre'=>'Ana Vargas'], ['id_venta'=>101,'fecha'=>'2026-10-28 09:20','total'=>'44000.00','nombre'=>'Luis Méndez']]); ?>
<?php elseif ($setup): ?>
    <div class="setup-card"><span class="setup-icon">⚙</span><h2>Conectemos el taller</h2><p><?= e($setup) ?></p>
    <ol><li>Instale PHP con PDO_SQLSRV y el controlador ODBC de Microsoft.</li><li>Cree la base con el SQL original, solo si todavía no existe.</li><li>Configure <code>config.php</code> y cargue <code>sql/datos_prueba.sql</code>.</li></ol>
    <a class="button is-primary" href="index.php">Volver a comprobar</a> <a class="button is-light" href="index.php?preview=1">Ver diseño de ejemplo</a><p class="help mt-4">La interfaz está lista. Los módulos mostrarán datos reales cuando la conexión esté disponible.</p></div>
<?php elseif ($db):
    try { renderPage($db, $page); }
    catch (Throwable $e) { error_log((string)$e); http_response_code(503); echo '<div class="notification is-danger is-light" role="alert">No se pudo consultar este módulo. Compruebe que la base contiene las tablas del script TallerRepuestosDB.sql.</div>'; }
endif;
$content = ob_get_clean();
if ($partial) { echo $content; exit; }
?>
<!doctype html>
<html lang="es" data-theme="light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="htmx-config" content='{"historyRestoreAsHxRequest":false}'><title>Repuesto · Gestión del taller</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
<link rel="stylesheet" href="assets/app.css">
<script src="../miniframework_cech/miniframework_cech/htmx.min.js" defer></script><script src="assets/app.js" defer></script></head>
<body>
<aside class="sidebar"><a class="brand" href="index.php"><span class="brand-mark">R</span><span>repuesto<span class="brand-dot">.</span><small>GESTIÓN DEL TALLER</small></span></a>
<p class="menu-caption">ESPACIO DE TRABAJO</p><nav aria-label="Menú principal">
<?php $icons = ['inicio'=>'◫','repuestos'=>'▦','clientes'=>'♙','proveedores'=>'▤','compras'=>'↙','ventas'=>'↗','inventario'=>'≡']; foreach ($pages as $key => $label): ?>
<a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= $key ?>" hx-get="index.php?page=<?= $key ?>" hx-target="#content" hx-push-url="true" data-page="<?= $key ?>"><span aria-hidden="true"><?= $icons[$key] ?></span><?= $key === 'inicio' ? 'Inicio' : e($label) ?></a>
<?php endforeach ?></nav><div class="sidebar-footer"><span class="status-dot"></span> SQL Server · PHP<small>Compras, ventas e inventario<br>en un solo lugar.</small></div></aside>
<div class="workspace"><header class="topbar"><span>Administración <span class="muted">/ Taller de repuestos</span></span><span class="workspace-label">TR <span>Entorno local</span></span></header>
<main id="content" tabindex="-1" hx-history-elt><?= $content ?></main><footer class="app-footer">Repuesto · Prototipo funcional <span>PHP + Bulma + HTMX</span></footer></div>
<div id="loading" class="htmx-indicator" role="status">Procesando…</div>
</body></html>
