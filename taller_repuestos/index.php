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
    'proveedores' => 'Proveedores', 'compras' => 'Compras', 'ventas' => 'Ventas', 'inventario' => 'Inventario e historial','reportes'=>'Reportes'];
$page = is_string($_GET['page'] ?? null) ? $_GET['page'] : 'inicio';
if (!isset($pages[$page])) $page = 'inicio';
$notice = $_SESSION['notice'] ?? null;
unset($_SESSION['notice']);
$error = null;
$GLOBALS['completedOperation']=$_SESSION['completed_operation']??null;
unset($_SESSION['completed_operation']);
$setup = null;
$db = null;
if (isset($_GET['preview'])) { header('Location: index.php',true,303); exit; }
try {
    $db = database();
} catch (RuntimeException $e) {
    error_log($e->getMessage());
    $setup = 'No se pudo acceder a la información del taller. Revise la configuración de conexión o contacte al administrador.';
    http_response_code(503);
}
if (isset($_GET['lookup'])) {
    session_write_close();
    try {
        if (!$db) throw new RuntimeException('Conexión no disponible.');
        $kind=queryText('lookup',20); $operation=queryText('operation',10);
        if (!in_array($operation,['sale','purchase'],true)) throw new InvalidArgumentException('Operación inválida.');
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(lookupPage($db,$kind,queryText('term',100),queryInteger('lp',1),queryInteger('proveedor',0)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
    } catch (Throwable $e) { error_log((string)$e); http_response_code(422); header('Content-Type: application/json; charset=UTF-8'); echo json_encode(['error'=>'No se pudo buscar. Revise el texto e intente nuevamente.']); }
    exit;
}
if(isset($_GET['catalog_supplier'])) {
    try {
        if(!$db)throw new RuntimeException('Conexión no disponible.');
        $supplier=queryInteger('catalog_supplier',0);$catalogError=null;$catalogNotice=null;
        if($_SERVER['REQUEST_METHOD']==='POST') {
            try {
                if(!is_string($_POST['csrf']??null)||!hash_equals($_SESSION['csrf'],$_POST['csrf']))throw new InvalidArgumentException('La sesión del formulario venció. Recargue el catálogo.');
                if(($_POST['action']??'')!=='supplier_part'||integerInput('id_proveedor')!==$supplier)throw new InvalidArgumentException('El proveedor del formulario no coincide.');
                $catalogNotice=saveSupplierPart($db);
            } catch(InvalidArgumentException $e){$catalogError=$e->getMessage();http_response_code(422);}
        }
        session_write_close();supplierCatalogView($db,$supplier,$catalogError,$catalogNotice);
    } catch(Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException?422:503);if(!$e instanceof InvalidArgumentException)error_log((string)$e);
        echo '<header class="operation-heading"><h2 id="supplier-catalog-title">Repuestos del proveedor</h2><button type="button" class="detail-close" data-supplier-close aria-label="Cerrar catálogo">×</button></header><div class="operation-body">'.e($e instanceof InvalidArgumentException?$e->getMessage():'No se pudo cargar el catálogo. Intente nuevamente.').'</div>';
    }
    exit;
}
if(isset($_GET['download_form'])||isset($_GET['preview_export'])) {
    session_write_close();$json=isset($_GET['preview_export']);
    try {
        if(!$db)throw new RuntimeException('Conexión no disponible.');
        if($json) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode(previewExport($db,queryText('preview_export',20),queryText('format',10)),JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
        } else downloadForm($db,queryText('download_form',20));
    } catch(Throwable $e) {
        http_response_code($e instanceof InvalidArgumentException?422:503);
        if(!$e instanceof InvalidArgumentException)error_log((string)$e);
        $message=$e instanceof InvalidArgumentException?$e->getMessage():'No se pudo consultar la selección. Intente nuevamente.';
        if($json){header('Content-Type: application/json; charset=UTF-8');echo json_encode(['error'=>$message],JSON_UNESCAPED_UNICODE);}
        else echo '<header class="operation-heading"><h2 id="download-title">Descargar información</h2><button type="button" class="detail-close" data-download-close aria-label="Cerrar descargas">×</button></header><div class="operation-body">'.e($message).'</div>';
    }
    exit;
}
if (isset($_GET['export'])) {
    session_write_close();
    $page='reportes';
    try {
        if (!$db) throw new RuntimeException('Conexión no disponible.');
        exportReport($db,queryText('export',20),queryText('format',10));
    } catch (InvalidArgumentException $e) { $error=$e->getMessage(); http_response_code(422); }
    catch (Throwable $e) { error_log((string)$e); $error='No se pudo generar el archivo. Intente nuevamente o contacte al administrador.'; http_response_code(500); }
}
if(isset($_GET['view_operation'])) {
    session_write_close();
    try {
        if(!$db) throw new RuntimeException('Conexión no disponible.');
        renderOperationDetail(operationDocument($db,queryText('view_operation',10),queryInteger('id',0)));
    } catch(Throwable $e) {
        $status=$e instanceof OperationNotFound?404:($e instanceof InvalidArgumentException?422:503);http_response_code($status);
        if($status===503)error_log((string)$e);
        echo '<header class="operation-heading"><h2 id="operation-title">Detalle de operación</h2><button type="button" class="detail-close" data-detail-close aria-label="Cerrar detalle">×</button></header><div class="operation-body"><div class="notification is-warning is-light">'.e($status===503?'No se pudo cargar la operación. Intente nuevamente.':$e->getMessage()).'</div></div>';
    }
    exit;
}
if(isset($_GET['receipt'])) {
    session_write_close();$page='reportes';
    try {
        if(!$db)throw new RuntimeException('Conexión no disponible.');
        exportOperation($db,queryText('receipt',10),queryInteger('id',0),queryText('format',10));
    } catch(OperationNotFound $e){$error=$e->getMessage();http_response_code(404);}
    catch(InvalidArgumentException $e){$error=$e->getMessage();http_response_code(422);}
    catch(Throwable $e){error_log((string)$e);$error='No se pudo generar el comprobante.';http_response_code(500);}
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
            $_SESSION['completed_operation']=$GLOBALS['completedOperation']??null;
            header('Location: index.php?page=' . $page, true, 303);
            exit;
        }
    } catch (InvalidArgumentException $e) {
        $error = $e->getMessage();
        http_response_code(422);
    } catch (Throwable $e) {
        error_log((string)$e);
        $error = databaseErrorMessage($e, is_string($_POST['action'] ?? null) ? $_POST['action'] : '');
        http_response_code(422);
    }
}
$partial = ($_SERVER['HTTP_HX_REQUEST'] ?? '') === 'true';
ob_start();
?>
<div class="page-heading"><div><p class="eyebrow">CONTROL DEL NEGOCIO</p><h1><?= e($pages[$page]) ?></h1></div></div>
<?php if ($notice): ?><div class="notification is-success is-light" role="status"><?= e($notice) ?></div><?php endif ?>
<?php if ($error): ?><div class="notification is-danger is-light" role="alert"><?= e($error) ?></div><?php endif ?>
<?php if ($setup): ?>
    <div class="setup-card"><span class="setup-icon">⚙</span><h2>Conexión no disponible</h2><p><?= e($setup) ?></p>
    <p class="mt-4 mb-5">La guía de instalación del proyecto contiene los pasos para restablecer el acceso.</p>
    <a class="button is-primary" href="index.php">Reintentar</a></div>
<?php elseif ($db):
    try { renderPage($db, $page); }
    catch (InvalidArgumentException $e) { http_response_code(422); echo '<div class="notification is-warning is-light" role="alert">'.e($e->getMessage()).'</div>'; }
    catch (Throwable $e) { error_log((string)$e); http_response_code(503); echo '<div class="notification is-danger is-light" role="alert">No se pudo cargar la información. Intente nuevamente o contacte al administrador.</div>'; }
endif;
if($GLOBALS['completedOperation']) {
    $completed=$GLOBALS['completedOperation'];
    echo '<span hidden data-open-operation data-kind="'.e($completed['kind']).'" data-id="'.(int)$completed['id'].'"></span>';
}
$content = ob_get_clean();
if ($partial) { echo $content; exit; }
?>
<!doctype html>
<html lang="es" data-theme="light">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="htmx-config" content='{"historyRestoreAsHxRequest":false}'><title>Repuesto · Gestión del taller</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bulma@1.0.4/css/bulma.min.css">
<link rel="stylesheet" href="assets/app.css?v=<?= filemtime(__DIR__.'/assets/app.css') ?>">
<script src="../miniframework_cech/miniframework_cech/htmx.min.js" defer></script><script src="assets/app.js?v=<?= filemtime(__DIR__.'/assets/app.js') ?>" defer></script><script src="assets/operations.js?v=<?= filemtime(__DIR__.'/assets/operations.js') ?>" defer></script><script src="assets/editor.js?v=<?= filemtime(__DIR__.'/assets/editor.js') ?>" defer></script><script src="assets/downloads.js?v=<?= filemtime(__DIR__.'/assets/downloads.js') ?>" defer></script></head>
<body>
<aside class="sidebar" id="sidebar"><a class="brand" href="index.php" aria-label="Repuesto · Inicio"><span class="brand-mark">R</span><span class="brand-text">repuesto<span class="brand-dot">.</span><small>GESTIÓN DEL TALLER</small></span></a>
<button id="sidebar-toggle" class="sidebar-toggle sidebar-pin" type="button" aria-label="Fijar menú abierto" aria-controls="sidebar" aria-expanded="false" aria-pressed="false"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Fijar menú</span></button>
<button class="sidebar-close" type="button" aria-label="Cerrar menú">×</button>
<p class="menu-caption">ESPACIO DE TRABAJO</p><nav aria-label="Menú principal">
<?php $icons = ['inicio'=>'◫','repuestos'=>'▦','clientes'=>'♙','proveedores'=>'▤','compras'=>'↙','ventas'=>'↗','inventario'=>'≡']; foreach (array_intersect_key($pages,$icons) as $key => $label): ?>
<a class="nav-link <?= $page === $key ? 'active' : '' ?>" href="index.php?page=<?= $key ?>" hx-get="index.php?page=<?= $key ?>" hx-target="#content" hx-push-url="true" data-page="<?= $key ?>" title="<?= e($label) ?>" aria-label="<?= e($label) ?>"><span aria-hidden="true"><?= $icons[$key] ?></span><span class="nav-label"><?= $key === 'inicio' ? 'Inicio' : e($label) ?></span></a>
<?php endforeach ?></nav><div class="sidebar-footer">Gestión de repuestos<small>Compras, ventas e inventario<br>en un solo lugar.</small></div></aside>
<button class="sidebar-backdrop" type="button" aria-label="Cerrar menú" hidden></button>
<div class="workspace"><header class="topbar"><div class="topbar-start"><button id="sidebar-mobile-toggle" class="sidebar-toggle sidebar-mobile-toggle" type="button" aria-label="Abrir menú" aria-controls="sidebar" aria-expanded="false"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path d="M4 6h16M4 12h16M4 18h16"/></svg><span>Menú</span></button><span>Administración <span class="muted">/ Taller de repuestos</span></span></div></header>
<main id="content" tabindex="-1" hx-history-elt><?= $content ?></main><footer class="app-footer">Repuesto <span>Compras · Ventas · Inventario</span></footer></div>
<div id="loading" class="htmx-indicator" role="status">Procesando…</div>
<dialog id="operation-detail" class="operation-dialog" aria-labelledby="operation-title"></dialog>
<dialog id="record-editor" class="operation-dialog edit-dialog" aria-labelledby="edit-title"></dialog>
<dialog id="download-dialog" class="operation-dialog edit-dialog" aria-labelledby="download-title"></dialog>
<dialog id="supplier-catalog" class="operation-dialog" aria-labelledby="supplier-catalog-title"></dialog>
<script src="assets/backups.js?v=<?= filemtime(__DIR__.'/assets/backups.js') ?>" defer></script>
<script src="assets/filters.js?v=<?= filemtime(__DIR__.'/assets/filters.js') ?>" defer></script>
<script src="assets/supplier-catalog.js?v=<?= filemtime(__DIR__.'/assets/supplier-catalog.js') ?>" defer></script>
</body></html>
