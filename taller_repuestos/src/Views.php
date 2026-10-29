<?php
declare(strict_types=1);

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money($value): string { return number_format((float)$value, 2, '.', ','); }
function dateLabel($value): string { return date('d/m/Y H:i', strtotime((string)$value)); }
function csrf(): void { echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function startForm(string $page, string $action): void
{
    $GLOBALS['activeForm'] = $action;
    echo '<form method="post" action="index.php?page=' . $page . '" hx-post="index.php?page=' . $page . '" hx-target="#content" hx-indicator="#loading" hx-disabled-elt="find button">';
    csrf();
    echo '<input type="hidden" name="action" value="' . $action . '">';
}
function fieldValue(string $name, $fallback)
{
    if (!empty($GLOBALS['error']) && ($_POST['action'] ?? '') === ($GLOBALS['activeForm'] ?? '') && isset($_POST[$name]) && is_string($_POST[$name])) {
        return $_POST[$name];
    }
    return $fallback;
}
function input(string $label, string $name, $value = '', string $type = 'text', string $attributes = ''): void
{
    $value = fieldValue($name, $value);
    $id = ($GLOBALS['activeForm'] ?? '') === 'category' ? 'category-' . $name : $name;
    echo '<div class="field"><label class="label" for="' . e($id) . '">' . e($label) . '</label><div class="control"><input class="input" id="' . e($id) . '" name="' . e($name) . '" type="' . $type . '" value="' . e($value) . '" ' . $attributes . '></div></div>';
}
function options(string $label, string $name, array $items, string $idKey, string $labelKey, $selected = ''): void
{
    $selected = fieldValue($name, $selected);
    echo '<div class="field"><label class="label" for="' . e($name) . '">' . e($label) . '</label><div class="select is-fullwidth"><select required id="' . e($name) . '" name="' . e($name) . '"><option value="">Seleccione una opción</option>';
    foreach ($items as $item) echo '<option value="' . e($item[$idKey]) . '" ' . ((string)$item[$idKey] === (string)$selected ? 'selected' : '') . '>' . e($item[$labelKey]) . '</option>';
    echo '</select></div></div>';
}
function emptyRow(int $cols, string $label = 'Todavía no hay registros. Comience registrando uno.'): void
{
    echo '<tr><td colspan="' . $cols . '" class="empty-state">' . e($label) . '</td></tr>';
}
function editLink(string $page, int $id): string
{
    return '<a class="button is-small is-light" href="index.php?page=' . $page . '&edit=' . $id . '" hx-get="index.php?page=' . $page . '&edit=' . $id . '" hx-target="#content" hx-push-url="true">Editar</a>';
}
function searchForm(string $page, string $placeholder): string
{
    $q = is_string($_GET['q'] ?? null) ? substr($_GET['q'], 0, 150) : '';
    echo '<form class="search-bar" method="get" action="index.php" hx-get="index.php" hx-target="#content" hx-push-url="true"><input type="hidden" name="page" value="' . $page . '"><input class="input" type="search" name="q" aria-label="Buscar" placeholder="' . e($placeholder) . '" value="' . e($q) . '"><button class="button is-light">Buscar</button></form>';
    return $q;
}
function panelTitle(string $title, string $subtitle = ''): void
{
    echo '<div class="panel-heading-custom"><h2>' . e($title) . '</h2>' . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</div>';
}
function renderPage(PDO $db, string $page): void
{
    match ($page) {
        'inicio' => dashboard($db),
        'repuestos' => partsPage($db),
        'clientes', 'proveedores' => contactsPage($db, $page),
        'compras', 'ventas' => operationsPage($db, $page),
        'inventario' => inventoryPage($db),
        default => dashboard($db),
    };
}

function dashboard(PDO $db): void
{
    $stats = rows($db, "SELECT (SELECT COUNT(*) FROM RP03_REPUESTOS WHERE estado='ACTIVO') partes,
        (SELECT COUNT(*) FROM RP03_REPUESTOS WHERE estado='ACTIVO' AND stock_actual <= stock_minimo) bajos,
        (SELECT COALESCE(SUM(total),0) FROM RP07_VENTAS WHERE estado='COMPLETADA' AND fecha >= CONVERT(date,GETDATE())) vendido,
        (SELECT COUNT(*) FROM RP05_CLIENTES) clientes")[0];
    $low = rows($db, "SELECT TOP (6) nombre, stock_actual, stock_minimo FROM RP03_REPUESTOS WHERE estado='ACTIVO' AND stock_actual <= stock_minimo ORDER BY stock_actual, nombre");
    $recent = rows($db, 'SELECT TOP (6) V.id_venta,V.fecha,V.total,C.nombre FROM RP07_VENTAS V JOIN RP05_CLIENTES C ON C.id_cliente=V.id_cliente ORDER BY V.fecha DESC,V.id_venta DESC');
    dashboardView($stats, $low, $recent);
}

function dashboardView(array $stats, array $low, array $recent): void
{
    ?>
    <section class="welcome"><div><p class="eyebrow">TODO BAJO CONTROL</p><h2>Tu taller, en movimiento.</h2><p>Consulta tus existencias y registra cada entrada y salida.</p><a class="button is-primary" href="index.php?page=ventas" hx-get="index.php?page=ventas" hx-target="#content" hx-push-url="true">Registrar una venta <span aria-hidden="true">↗</span></a></div><div class="welcome-art" aria-hidden="true"><div class="art-box">R<span>+</span></div><span class="art-label">INVENTARIO CONECTADO</span></div></section>
    <div class="stats-grid">
    <?php foreach ([['Repuestos activos', $stats['partes'], 'Referencias en el catálogo', '▦'], ['Ventas de hoy', money($stats['vendido']), 'Importe en moneda del negocio', '↗'], ['Existencias bajas', $stats['bajos'], 'En el mínimo o por debajo', '↘'], ['Clientes', $stats['clientes'], 'Clientes registrados', '♙']] as [$title,$number,$caption,$icon]): ?>
        <article class="stat-card"><div><span><?= e($title) ?></span><span class="stat-icon" aria-hidden="true"><?= $icon ?></span></div><strong><?= e($number) ?></strong><small><?= e($caption) ?></small></article>
    <?php endforeach ?></div>
    <div class="dashboard-grid"><section class="panel-card"><?php panelTitle('Últimas ventas', 'Las operaciones más recientes del taller'); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Venta</th><th>Cliente</th><th>Fecha</th><th class="has-text-right">Total</th></tr></thead><tbody>
    <?php if (!$recent) emptyRow(4, 'Las ventas que registres aparecerán aquí.'); foreach ($recent as $r): ?><tr><td><span class="reference">#<?= (int)$r['id_venta'] ?></span></td><td><?= e($r['nombre']) ?></td><td><?= dateLabel($r['fecha']) ?></td><td class="has-text-right amount"><?= money($r['total']) ?></td></tr><?php endforeach ?>
    </tbody></table></div></section><section class="panel-card"><?php panelTitle('Atención al inventario', 'Repuestos que necesitan reposición'); ?>
    <?php if (!$low): ?><p class="empty-state">No hay alertas de existencias.</p><?php endif; foreach ($low as $r): ?><div class="stock-alert"><span class="stock-alert-icon">!</span><div><strong><?= e($r['nombre']) ?></strong><small>Mínimo: <?= (int)$r['stock_minimo'] ?> unidades</small></div><span class="tag is-warning is-light"><?= (int)$r['stock_actual'] ?> uds.</span></div><?php endforeach ?>
    </section></div>
    <div class="info-strip"><span>↔</span><p><strong>Cada movimiento cuenta.</strong> Las compras suman existencias y las ventas las descuentan automáticamente mediante los triggers de SQL Server.</p></div>
    <?php
}

function partsPage(PDO $db): void
{
    $edit = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if (!empty($GLOBALS['error']) && ($_POST['action'] ?? '') === 'part') $edit = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $part = $edit ? (rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE id_repuesto=?', [$edit])[0] ?? []) : [];
    $cats = rows($db, 'SELECT * FROM RP01_CATEGORIAS ORDER BY nombre');
    ?>
    <div class="split-layout"><section class="panel-card list-panel"><?php panelTitle('Catálogo de repuestos', 'Hasta 100 resultados por búsqueda · precios en moneda del negocio');
    $q = searchForm('repuestos', 'Buscar por nombre, código o categoría…');
    $items = rows($db, 'SELECT TOP (100) R.*, C.nombre categoria FROM RP03_REPUESTOS R JOIN RP01_CATEGORIAS C ON C.id_categoria=R.id_categoria WHERE R.nombre LIKE ? OR R.codigo_barras LIKE ? OR C.nombre LIKE ? ORDER BY R.nombre', array_fill(0, 3, '%' . $q . '%')); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Compra / venta</th><th>Existencias</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(5); foreach ($items as $r): ?>
    <tr><td><strong><?= e($r['nombre']) ?></strong><small class="cell-subtitle"><?= e($r['codigo_barras']) ?> · <?= e($r['categoria']) ?></small></td><td><?= money($r['precio_compra']) ?><small class="cell-subtitle amount"><?= money($r['precio_venta']) ?></small></td><td><span class="tag <?= $r['stock_actual'] <= $r['stock_minimo'] ? 'is-warning is-light' : 'is-success is-light' ?>"><?= (int)$r['stock_actual'] ?> uds.</span></td><td><span class="tag is-light"><?= e($r['estado']) ?></span></td><td><?= editLink('repuestos', (int)$r['id_repuesto']) ?></td></tr>
    <?php endforeach ?></tbody></table></div></section>
    <aside><section class="panel-card form-panel"><?php panelTitle($part ? 'Editar repuesto' : 'Nuevo repuesto', 'Las nuevas referencias comienzan con stock cero.');
    startForm('repuestos', 'part'); ?><input type="hidden" name="id" value="<?= (int)($part['id_repuesto'] ?? 0) ?>">
    <?php input('Código de barras', 'codigo_barras', $part['codigo_barras'] ?? '', 'text', 'required maxlength="20"');
    input('Nombre del repuesto', 'nombre', $part['nombre'] ?? '', 'text', 'required maxlength="150"');
    options('Categoría', 'id_categoria', $cats, 'id_categoria', 'nombre', $part['id_categoria'] ?? ''); ?>
    <div class="form-grid"><?php input('Precio de compra', 'precio_compra', $part['precio_compra'] ?? '0.00', 'number', 'min="0" max="99999999.99" step="0.01" required'); input('Precio de venta', 'precio_venta', $part['precio_venta'] ?? '0.00', 'number', 'min="0" max="99999999.99" step="0.01" required'); ?></div>
    <?php input('Existencias mínimas', 'stock_minimo', $part['stock_minimo'] ?? 1, 'number', 'min="0" max="2147483647" step="1" required');
    options('Estado', 'estado', [['id'=>'ACTIVO','label'=>'Activo'],['id'=>'INACTIVO','label'=>'Inactivo']], 'id', 'label', $part['estado'] ?? 'ACTIVO'); ?>
    <button class="button is-primary is-fullwidth">Guardar repuesto</button>
    <?php if ($part): ?><a class="button is-text is-fullwidth mt-2" href="index.php?page=repuestos" hx-get="index.php?page=repuestos" hx-target="#content" hx-push-url="true">Cancelar edición</a><?php endif ?></form></section>
    <details class="panel-card category-panel"><summary>+ Registrar categoría</summary><?php startForm('repuestos','category'); input('Nombre de la categoría','nombre','','text','required maxlength="100"'); ?><button class="button is-light is-fullwidth">Guardar categoría</button></form></details></aside></div>
    <?php
}

function contactsPage(PDO $db, string $page): void
{
    $client = $page === 'clientes';
    $table = $client ? 'RP05_CLIENTES' : 'RP02_PROVEEDORES';
    $pk = $client ? 'id_cliente' : 'id_proveedor';
    $name = $client ? 'nombre' : 'nombre_empresa';
    $other = $client ? 'cedula' : 'contacto';
    $edit = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if (!empty($GLOBALS['error']) && ($_POST['action'] ?? '') === ($client ? 'client' : 'supplier')) $edit = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $item = $edit ? (rows($db, "SELECT * FROM $table WHERE $pk=?", [$edit])[0] ?? []) : [];
    ?>
    <div class="split-layout"><section class="panel-card list-panel"><?php panelTitle($client ? 'Directorio de clientes' : 'Directorio de proveedores', 'Hasta 100 resultados por búsqueda');
    $q = searchForm($page, $client ? 'Buscar por nombre o cédula…' : 'Buscar por empresa o contacto…');
    $items = rows($db, "SELECT TOP (100) * FROM $table WHERE $name LIKE ? OR $other LIKE ? ORDER BY $name", ['%'.$q.'%', '%'.$q.'%']); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th><?= $client ? 'Cliente / cédula' : 'Empresa / contacto' ?></th><th>Teléfono</th><th>Correo electrónico</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(4); foreach ($items as $r): ?><tr><td><strong><?= e($r[$name]) ?></strong><small class="cell-subtitle"><?= e($r[$other]) ?></small></td><td><?= e($r['telefono']) ?></td><td><?= e($r['email'] ?: '—') ?></td><td><?= editLink($page, (int)$r[$pk]) ?></td></tr><?php endforeach ?>
    </tbody></table></div></section><section class="panel-card form-panel"><?php panelTitle($item ? 'Editar registro' : ($client ? 'Nuevo cliente' : 'Nuevo proveedor')); startForm($page, $client ? 'client' : 'supplier'); ?>
    <input type="hidden" name="id" value="<?= (int)($item[$pk] ?? 0) ?>">
    <?php input($client ? 'Nombre completo' : 'Nombre de la empresa', $name, $item[$name] ?? '', 'text', 'required maxlength="' . ($client ? 100 : 150) . '"');
    input($client ? 'Cédula' : 'Persona de contacto', $other, $item[$other] ?? '', 'text', 'required maxlength="' . ($client ? 20 : 100) . '"');
    input('Teléfono', 'telefono', $item['telefono'] ?? '', 'tel', 'required maxlength="20"');
    input('Correo electrónico' . ($client ? ' (opcional)' : ''), 'email', $item['email'] ?? '', 'email', 'maxlength="100" ' . ($client ? '' : 'required')); ?>
    <button class="button is-primary is-fullwidth">Guardar <?= $client ? 'cliente' : 'proveedor' ?></button>
    <?php if ($item): ?><a class="button is-text is-fullwidth mt-2" href="index.php?page=<?= $page ?>" hx-get="index.php?page=<?= $page ?>" hx-target="#content" hx-push-url="true">Cancelar edición</a><?php endif ?></form></section></div>
    <?php
}

function operationsPage(PDO $db, string $page): void
{
    $sale = $page === 'ventas';
    $parts = rows($db, "SELECT * FROM RP03_REPUESTOS WHERE estado='ACTIVO' ORDER BY nombre");
    $people = rows($db, $sale ? 'SELECT * FROM RP05_CLIENTES ORDER BY nombre' : 'SELECT * FROM RP02_PROVEEDORES ORDER BY nombre_empresa');
    $sellers = $sale ? rows($db, "SELECT * FROM RP13_USUARIOS WHERE estado='ACTIVO' AND nombre_usuario <> 'sistema' ORDER BY nombre_usuario") : [];
    ?>
    <div class="info-strip"><span><?= $sale ? '↗' : '↙' ?></span><p><?= $sale ? 'Al confirmar, SQL Server descuenta las existencias y registra la salida. El precio se toma del catálogo.' : 'Al recibir una compra, SQL Server aumenta las existencias y registra la entrada.' ?> Una referencia por operación en esta versión.</p></div>
    <div class="split-layout"><section class="panel-card list-panel"><?php panelTitle($sale ? 'Ventas registradas' : 'Compras recibidas', 'Últimas 100 operaciones · registros confirmados, sin edición ni anulación');
    $items = rows($db, $sale
        ? 'SELECT TOP (100) V.id_venta id,V.fecha,V.total,V.estado,C.nombre persona,U.nombre_usuario vendedor FROM RP07_VENTAS V JOIN RP05_CLIENTES C ON C.id_cliente=V.id_cliente JOIN RP13_USUARIOS U ON U.usuario_id=V.id_vendedor ORDER BY V.fecha DESC,V.id_venta DESC'
        : 'SELECT TOP (100) C.id_compra id,C.fecha,C.total,C.estado,P.nombre_empresa persona FROM RP09_COMPRAS C JOIN RP02_PROVEEDORES P ON P.id_proveedor=C.id_proveedor ORDER BY C.fecha DESC,C.id_compra DESC'); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Referencia</th><th><?= $sale ? 'Cliente / vendedor' : 'Proveedor' ?></th><th>Total</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(5); foreach ($items as $r): ?><tr><td><strong>#<?= (int)$r['id'] ?></strong><small class="cell-subtitle"><?= dateLabel($r['fecha']) ?></small></td><td><?= e($r['persona']) ?><?php if ($sale): ?><small class="cell-subtitle"><?= e($r['vendedor']) ?></small><?php endif ?></td><td class="amount"><?= money($r['total']) ?></td><td><span class="tag is-success is-light"><?= e($r['estado']) ?></span></td><td><a class="button is-small is-light" href="index.php?page=<?= $page ?>&detail=<?= (int)$r['id'] ?>" hx-get="index.php?page=<?= $page ?>&detail=<?= (int)$r['id'] ?>" hx-target="#content" hx-push-url="true">Ver</a></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php $detail = filter_var($_GET['detail'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if ($detail > 0):
        $dt = $sale ? 'RP08_DETALLE_VENTAS' : 'RP10_DETALLE_COMPRAS';
        $key = $sale ? 'id_venta' : 'id_compra';
        $lines = rows($db, "SELECT D.*,R.nombre FROM $dt D JOIN RP03_REPUESTOS R ON R.id_repuesto=D.id_repuesto WHERE D.$key=?", [$detail]);
        panelTitle('Detalle #' . $detail); ?>
        <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Cantidad</th><th>Precio</th><th>Subtotal</th></tr></thead><tbody>
        <?php if (!$lines) emptyRow(4, 'No se encontró el detalle.'); foreach ($lines as $line): ?><tr><td><?= e($line['nombre']) ?></td><td><?= (int)$line['cantidad'] ?></td><td><?= money($line['precio_unitario']) ?></td><td><?= money($line['subtotal']) ?></td></tr><?php endforeach ?></tbody></table></div>
    <?php endif ?></section>
    <section class="panel-card form-panel"><?php panelTitle($sale ? 'Nueva venta' : 'Registrar compra', 'Todos los importes están en la moneda del negocio.');
    if (!$people || !$parts || ($sale && !$sellers)): ?><div class="notification is-warning is-light">Registre primero <?= $sale ? 'clientes, repuestos y un vendedor activo' : 'proveedores y repuestos' ?>. El script de datos de prueba incluye un vendedor.</div><?php endif;
    startForm($page, $sale ? 'sale' : 'purchase');
    options($sale ? 'Cliente' : 'Proveedor', $sale ? 'id_cliente' : 'id_proveedor', $people, $sale ? 'id_cliente' : 'id_proveedor', $sale ? 'nombre' : 'nombre_empresa');
    if ($sale) options('Vendedor responsable', 'id_vendedor', $sellers, 'usuario_id', 'nombre_usuario'); ?>
    <div class="field"><label class="label" for="id_repuesto">Repuesto</label><div class="select is-fullwidth"><select required name="id_repuesto" id="id_repuesto" data-operation="<?= $sale ? 'sale' : 'purchase' ?>"><option value="">Seleccione un repuesto</option>
    <?php foreach ($parts as $r): ?><option value="<?= (int)$r['id_repuesto'] ?>" <?= (string)fieldValue('id_repuesto', '') === (string)$r['id_repuesto'] ? 'selected' : '' ?> data-price="<?= e($r[$sale ? 'precio_venta' : 'precio_compra']) ?>" data-stock="<?= (int)$r['stock_actual'] ?>"><?= e($r['nombre']) ?> · <?= (int)$r['stock_actual'] ?> uds.</option><?php endforeach ?></select></div></div>
    <div id="stock-hint" class="help mb-4">Seleccione una referencia para consultar sus existencias.</div>
    <?php input('Cantidad', 'cantidad', 1, 'number', 'min="1" max="2147483647" step="1" required');
    if (!$sale) input('Precio unitario de compra', 'precio_unitario', '0.00', 'number', 'min="0" max="99999999.99" step="0.01" required'); ?>
    <div class="total-preview"><span>Total estimado</span><strong id="total-preview">0.00</strong><small>El servidor valida y calcula el importe definitivo.</small></div>
    <button class="button is-primary is-fullwidth" <?= !$people || !$parts || ($sale && !$sellers) ? 'disabled' : '' ?>><?= $sale ? 'Confirmar venta' : 'Confirmar recepción' ?></button>
    <p class="help mt-3">La operación es transaccional: se guarda completa o se revierte.</p></form></section></div>
    <?php
}

function inventoryPage(PDO $db): void
{
    ?>
    <section class="panel-card mb-5"><?php panelTitle('Existencias por repuesto', 'Hasta 100 resultados por búsqueda · el historial conserva entradas y salidas');
    $q = searchForm('inventario', 'Buscar por nombre o código…');
    $items = rows($db, 'SELECT TOP (100) * FROM RP03_REPUESTOS WHERE nombre LIKE ? OR codigo_barras LIKE ? ORDER BY stock_actual-stock_minimo, nombre', ['%'.$q.'%', '%'.$q.'%']); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Código</th><th>Estado</th><th>Actual</th><th>Mínimo</th><th>Nivel</th></tr></thead><tbody>
    <?php if (!$items) emptyRow(6); foreach ($items as $r): ?><tr><td><strong><?= e($r['nombre']) ?></strong></td><td><?= e($r['codigo_barras']) ?></td><td><?= e($r['estado']) ?></td><td class="amount"><?= (int)$r['stock_actual'] ?></td><td><?= (int)$r['stock_minimo'] ?></td><td><span class="tag <?= $r['stock_actual'] <= $r['stock_minimo'] ? 'is-warning is-light' : 'is-success is-light' ?>"><?= $r['stock_actual'] <= $r['stock_minimo'] ? 'Reponer' : 'Disponible' ?></span></td></tr><?php endforeach ?>
    </tbody></table></div></section>
    <section class="panel-card"><?php panelTitle('Historial de movimientos', 'Últimos 100 movimientos que coinciden con la búsqueda');
    $history = rows($db, 'SELECT TOP (100) H.*,R.nombre,U.nombre_usuario FROM RP11_INVENTARIO_HISTORIAL H JOIN RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto JOIN RP13_USUARIOS U ON U.usuario_id=H.usuario_id WHERE R.nombre LIKE ? OR R.codigo_barras LIKE ? ORDER BY H.fecha DESC,H.id_historial DESC', ['%'.$q.'%', '%'.$q.'%']); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Fecha</th><th>Repuesto</th><th>Movimiento</th><th>Cantidad</th><th>Responsable</th><th>Motivo</th></tr></thead><tbody>
    <?php if (!$history) emptyRow(6, 'No hay movimientos para esta búsqueda.'); foreach ($history as $r): ?><tr><td><?= dateLabel($r['fecha']) ?></td><td><?= e($r['nombre']) ?></td><td><span class="tag <?= $r['tipo_movimiento'] === 'ENTRADA_COMPRA' ? 'is-info is-light' : 'is-light' ?>"><?= e($r['tipo_movimiento']) ?></span></td><td class="amount"><?= (int)$r['cantidad'] ?></td><td><?= e($r['nombre_usuario']) ?></td><td><?= e($r['motivo']) ?></td></tr><?php endforeach ?>
    </tbody></table></div></section><p class="help mt-3">El modelo original atribuye las entradas de compras al usuario técnico «sistema»; las ventas se atribuyen al vendedor seleccionado.</p>
    <?php
}
