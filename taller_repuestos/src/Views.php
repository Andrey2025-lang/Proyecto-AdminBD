<?php
declare(strict_types=1);
require_once __DIR__ . '/OperationRequests.php';
require_once __DIR__ . '/Queries.php';
require_once __DIR__ . '/Reports.php';
require_once __DIR__ . '/Operations.php';

function e($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function money($value): string { return '₡ '.number_format((float)$value, 2, '.', ','); }
function dateLabel($value): string { return $value===null||$value===''?'—':date('d/m/Y H:i', strtotime((string)$value)); }
function csrf(): void { echo '<input type="hidden" name="csrf" value="' . e($_SESSION['csrf']) . '">'; }
function startForm(string $page, string $action): void
{
    $GLOBALS['activeForm'] = $action;
    echo '<form method="post" action="index.php?page=' . $page . '" hx-post="index.php?page=' . $page . '" hx-target="#content" hx-indicator="#loading" hx-disabled-elt="find button">';
    csrf();
    echo '<input type="hidden" name="action" value="' . $action . '">';
    if (in_array($action, ['sale','purchase'], true)) {
        [$token, $signature] = operationCredentials($action);
        echo '<input type="hidden" name="operation_token" value="' . e($token) . '"><input type="hidden" name="operation_signature" value="' . e($signature) . '">';
    }
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
    $url=e(pageUrl($page,['edit'=>$id]));
    return '<a class="button is-small is-light" href="'.$url.'" data-edit-record>Editar</a>'.($page==='proveedores'?' <button type="button" class="button is-small is-light" data-supplier-catalog="'.$id.'">Repuestos</button>':'');
}
function pageUrl(string $page, array $changes=[]): string
{
    $params=['page'=>$page];
    foreach (['q','tam','htam','p','h','categoria','estado','nivel','tipo','desde','hasta','vendedor'] as $key) if (isset($_GET[$key]) && is_scalar($_GET[$key])) $params[$key]=$_GET[$key];
    return 'index.php?'.http_build_query(array_replace($params,$changes));
}
function filterSelect(string $name, string $label, array $choices, $selected): void
{
    echo '<label class="filter-field"><span>'.e($label).'</span><select class="input" name="'.e($name).'">';
    foreach ($choices as $value=>$text) echo '<option value="'.e($value).'" '.((string)$value===(string)$selected?'selected':'').'>'.e($text).'</option>';
    echo '</select></label>';
}
function searchForm(string $page, string $placeholder, array $categories=[], array $sellers=[]): array
{
    $f=listFilters();
    echo '<form class="filter-bar" method="get" action="index.php" hx-get="index.php" hx-target="#content" hx-push-url="true"><input type="hidden" name="page" value="'.$page.'">';
    echo '<label class="filter-field filter-search"><span>Buscar</span><input class="input" type="search" name="q" maxlength="150" placeholder="'.e($placeholder).'" value="'.e($f['q']).'"></label>';
    if ($categories) {
        $choices=[''=>'Todas']; foreach($categories as $c) $choices[$c['id_categoria']]=$c['nombre'];
        filterSelect('categoria','Categoría',$choices,$f['category']);
    }
    if (in_array($page,['repuestos','inventario'],true)) {
        filterSelect('estado','Estado',[''=>'Todos','ACTIVO'=>'Activo','INACTIVO'=>'Inactivo'],$f['state']);
        filterSelect('nivel','Existencias',[''=>'Todas','bajo'=>'Por reponer','disponible'=>'Sobre el mínimo'],$f['level']);
    }
    if (in_array($page,['compras','ventas','inventario'],true)) {
        echo '<label class="filter-field"><span>Desde'.($page==='inventario'?' (movimientos)':'').'</span><input class="input" type="date" name="desde" value="'.e($f['from']).'" min="1900-01-01" max="2100-12-31"></label><label class="filter-field"><span>Hasta</span><input class="input" type="date" name="hasta" value="'.e($f['to']).'" min="1900-01-01" max="2100-12-31"></label>';
    }
    if ($page==='inventario') filterSelect('tipo','Movimiento',[''=>'Todos','ENTRADA_COMPRA'=>'Compra','SALIDA_VENTA'=>'Venta'],$f['type']);
    if ($page==='ventas' && $sellers) {
        $choices=[''=>'Todos']; foreach($sellers as $seller) $choices[$seller['usuario_id']]=$seller['nombre_usuario'];
        filterSelect('vendedor','Vendedor',$choices,$f['seller']);
    }
    echo '<input type="hidden" name="tam" value="'.$f['size'].'">';
    if ($page==='inventario') echo '<input type="hidden" name="htam" value="'.$f['history_size'].'">';
    echo '<button class="button is-light">Aplicar</button><a class="button is-text" href="index.php?page='.$page.'" hx-get="index.php?page='.$page.'" hx-target="#content" hx-push-url="true">Limpiar</a></form>';
    return $f;
}
function pagination(string $page, array $data): void
{
    $sizeName=$data['key']==='h'?'htam':'tam';
    echo '<nav class="pager" aria-label="Paginación de '.e($data['key']).'"><div class="pager-info"><span>'.($data['total']?number_format(($data['current']-1)*$data['size']+1).'–'.number_format(min($data['current']*$data['size'],$data['total'])):'0').' de '.number_format($data['total']).'</span>';
    echo '<form class="pager-size" method="get" action="index.php" hx-get="index.php" hx-target="#content" hx-push-url="true" hx-trigger="change, submit">';
    parse_str((string)parse_url(pageUrl($page,[$data['key']=>1]),PHP_URL_QUERY),$params);
    foreach ($params as $name=>$value) if($name!==$sizeName) echo '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
    echo '<label>Filas <select name="'.$sizeName.'" aria-label="Filas de '.($data['key']==='h'?'historial':e($page)).'">';
    $sizes=$page==='inventario'?[10,15,30,50]:[15,30,50,100];
    if (!in_array($data['size'],$sizes,true)) { $sizes[]=$data['size']; sort($sizes); }
    foreach ($sizes as $size) echo '<option value="'.$size.'" '.($size===$data['size']?'selected':'').'>'.$size.'</option>';
    echo '</select></label><button class="button is-small is-light pager-apply">Cambiar</button></form></div><div class="pager-pages">';
    foreach(['anterior'=>-1,'siguiente'=>1] as $direction=>$step) {
        if($step===1) {
            echo '<form class="pager-jump" method="get" action="index.php" hx-get="index.php" hx-target="#content" hx-push-url="true">';
            parse_str((string)parse_url(pageUrl($page),PHP_URL_QUERY),$jump);
            foreach($jump as $name=>$value) if($name!==$data['key']) echo '<input type="hidden" name="'.e($name).'" value="'.e($value).'">';
            echo '<label>Página <input type="number" name="'.e($data['key']).'" value="'.$data['current'].'" min="1" max="'.$data['pages'].'" required aria-label="Ir a página de '.e($data['key']).'"> <span>de '.number_format($data['pages']).'</span></label><button class="button is-small is-light" type="submit">Ir</button></form>';
        }
        $enabled=$step<0?$data['current']>1:$data['current']<$data['pages'];
        $text=$step<0?'‹ Anterior':'Siguiente ›';
        if($enabled) {
            $url=e(pageUrl($page,[$data['key']=>$data['current']+$step]));
            echo '<a class="button is-small is-light pager-direction" href="'.$url.'" hx-get="'.$url.'" hx-target="#content" hx-push-url="true" aria-label="Página '.$direction.'">'.$text.'</a>';
        } else echo '<button class="button is-small is-light pager-direction" disabled aria-label="Página '.$direction.'">'.$text.'</button>';
    }
    echo '</div></nav>';
}
function exportLinks(string $page, string $kind): void
{
    $url=pageUrl($page,['export'=>$kind,'p'=>null,'h'=>null]);
    $trade=in_array($kind,['ventas','compras'],true);
    echo '<div class="export-links"><a class="button is-small is-light" href="'.e($url.'&format=xlsx').'">'.($kind==='repuestos'?'Catálogo interno (Excel)':'Descargar Excel').'</a><a class="button is-small is-light" href="'.e($url.'&format=print').'" target="_blank" rel="noopener">'.($trade?'Resumen / PDF':'Imprimir / PDF').'</a><small>'.($trade?'Excel incluye todas las operaciones y sus repuestos. PDF muestra el resumen.':'Se exportan todos los resultados del filtro.').'</small></div>';
    $options=[[$kind,'csv',$trade?'Detalle de repuestos (CSV)':'Datos para importar (CSV)']];
    if($kind==='inventario')array_push($options,['reposicion','xlsx','Repuestos por revisar para compra (Excel)'],['reposicion','print','Repuestos por revisar para compra (PDF)']);
    if($kind==='repuestos')array_push($options,['precios','xlsx','Lista de precios sin costos internos (Excel)'],['precios','print','Lista de precios sin costos internos (PDF)']);
    echo '<details class="download-options"><summary>Más descargas</summary><div>';
    foreach($options as [$export,$format,$label]) {
        $href=pageUrl($page,['export'=>$export,'format'=>$format,'p'=>null,'h'=>null]);
        echo '<a class="button is-small is-light" href="'.e($href).'"'.($format==='print'?' target="_blank" rel="noopener"':'').'>'.e($label).'</a>';
    }
    echo '</div></details>';
}
function selectedPostId(string $action, string $field): int
{
    return !empty($GLOBALS['error']) && ($_POST['action']??'')===$action ? (filter_var($_POST[$field]??0,FILTER_VALIDATE_INT)?:0) : 0;
}
function lookupField(string $label, string $name, string $kind, array $items, string $operation, int $selected, bool $required=true): void
{
    $match=null;
    foreach($items as $item) if((int)$item['id']===$selected) $match=$item;
    if (!$match) $selected=0;
    $extra=in_array($kind,['repuestos','repuestos_compra'],true)?' data-operation="'.$operation.'" data-price="'.e($match[$operation==='sale'?'precio_venta':'precio_compra']??'').'" data-stock="'.e($match['stock_actual']??'').'"':'';
    echo '<div class="field autocomplete" data-lookup="'.$kind.'" data-operation="'.$operation.'"><label class="label" for="search-'.$name.'">'.e($label).'</label><div class="autocomplete-control"><input class="input autocomplete-input" id="search-'.$name.'" type="text" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="choices-'.$name.'" autocomplete="off" spellcheck="false" placeholder="Escriba para buscar…" '.($required?'required':'').' value="'.e($match['label']??'').'"><button class="autocomplete-clear" type="button" aria-label="Limpiar '.e($label).'" '.(!$selected?'hidden':'').'>×</button></div><input type="hidden" id="'.$name.'" name="'.$name.'" value="'.($selected?:'').'"'.$extra.'><div class="autocomplete-menu" hidden><p class="autocomplete-status" role="status" aria-live="polite"></p><div class="autocomplete-options" id="choices-'.$name.'" role="listbox" aria-label="Resultados de '.e($label).'"></div><button class="autocomplete-more" type="button" hidden>Mostrar más resultados</button></div><p class="help">Abra la lista o escriba al menos 2 caracteres para filtrar.</p><noscript><p class="help">Active JavaScript para buscar y seleccionar un registro.</p></noscript></div>';
}
function cartSeed(PDO $db, string $operation): array
{
    if(empty($GLOBALS['error']) || ($_POST['action']??'')!==$operation) return [];
    try { $lines=operationLines($operation); } catch(InvalidArgumentException $e) { return []; }
    $ids=array_unique(array_column($lines,'id_repuesto'));
    $parts=rows($db,'SELECT id_repuesto,nombre,codigo_barras,stock_actual,precio_venta,estado FROM RP03_REPUESTOS WHERE id_repuesto IN ('.implode(',',array_fill(0,count($ids),'?')).')',array_values($ids));
    $map=[]; foreach($parts as $part) $map[(int)$part['id_repuesto']]=$part;
    $supplier=$operation==='purchase'?selectedPostId('purchase','id_proveedor'):0;
    $allowed=$supplier?array_map('intval',array_column(rows($db,"SELECT id_repuesto FROM dbo.RP19_PROVEEDOR_REPUESTO WHERE id_proveedor=? AND estado='ACTIVO'",[$supplier]),'id_repuesto')):[];
    return array_map(static function($line) use($map,$operation,$supplier,$allowed) {
        $p=$map[$line['id_repuesto']]??null;
        return ['id_repuesto'=>$line['id_repuesto'],'cantidad'=>$line['cantidad'],'precio'=>$operation==='sale'?($p['precio_venta']??'0.00'):$line['precio_unitario'],
            'nombre'=>$p['nombre']??'Repuesto no disponible','codigo_barras'=>$p['codigo_barras']??'', 'stock'=>$p && $p['estado']==='ACTIVO'?(int)$p['stock_actual']:0,
            'supplier'=>$supplier,'supplierValid'=>$operation==='sale'||($p&&$p['estado']==='ACTIVO'&&in_array($line['id_repuesto'],$allowed,true))];
    },$lines);
}
function panelTitle(string $title, string $subtitle = ''): void
{
    echo '<div class="panel-heading-custom"><h2>' . e($title) . '</h2>' . ($subtitle !== '' ? '<p>' . e($subtitle) . '</p>' : '') . '</div>';
}
function renderPage(PDO $db, string $page): void
{
    if (in_array($page,['repuestos','clientes','proveedores','compras','ventas','inventario'],true)) listFilters();
    match ($page) {
        'inicio' => dashboard($db),
        'repuestos' => partsPage($db),
        'clientes', 'proveedores' => contactsPage($db, $page),
        'compras', 'ventas' => operationsPage($db, $page),
        'inventario' => inventoryPage($db),
        'reportes' => reportsPage($db),
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
    <div class="info-strip"><span>↔</span><p>Las existencias se actualizan automáticamente al registrar una compra o una venta.</p></div>
    <?php
}

function partsPage(PDO $db): void
{
    $edit = filter_var($_GET['edit'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    if (!empty($GLOBALS['error']) && ($_POST['action'] ?? '') === 'part') $edit = filter_var($_POST['id'] ?? 0, FILTER_VALIDATE_INT) ?: 0;
    $part = $edit ? (rows($db, 'SELECT * FROM RP03_REPUESTOS WHERE id_repuesto=?', [$edit])[0] ?? []) : [];
    if($edit && !$part)throw new InvalidArgumentException('El repuesto que desea editar ya no existe.');
    $cats = rows($db, 'SELECT id_categoria,nombre FROM RP01_CATEGORIAS ORDER BY nombre');
    ?>
    <div class="split-layout"><section class="panel-card list-panel"><?php panelTitle('Catálogo de repuestos', 'Precios y existencias del catálogo');
    $filters = searchForm('repuestos', 'Nombre, código o categoría…',$cats);
    $data=paginated($db,'repuestos',$filters); $items=$data['items']; foreach($items as &$row)$row['estado']=statusLabel($row['estado']);unset($row); exportLinks('repuestos','repuestos'); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Compra / venta</th><th>Existencias</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(5); foreach ($items as $r): ?>
    <tr><td><strong><?= e($r['nombre']) ?></strong><small class="cell-subtitle"><?= e($r['codigo_barras']) ?> · <?= e($r['categoria']) ?></small></td><td><?= money($r['precio_compra']) ?><small class="cell-subtitle amount"><?= money($r['precio_venta']) ?></small></td><td><span class="tag <?= $r['stock_actual'] <= $r['stock_minimo'] ? 'is-warning is-light' : 'is-success is-light' ?>"><?= (int)$r['stock_actual'] ?> uds.</span></td><td><span class="tag is-light"><?= e($r['estado']) ?></span></td><td><?= editLink('repuestos', (int)$r['id_repuesto']) ?></td></tr>
    <?php endforeach ?></tbody></table></div><?php pagination('repuestos',$data); ?></section>
    <aside><section class="panel-card form-panel"><?php panelTitle($part ? 'Editar repuesto' : 'Nuevo repuesto', 'Las nuevas referencias comienzan con stock cero.');
    startForm('repuestos', 'part'); ?><input type="hidden" name="id" value="<?= (int)($part['id_repuesto'] ?? 0) ?>">
    <?php input('Código de barras (único)', 'codigo_barras', $part['codigo_barras'] ?? '', 'text', 'required maxlength="20"'); ?>
    <p class="help mb-4">No puede repetirse en otro repuesto. Admite hasta 20 caracteres, incluidos códigos alfanuméricos.</p>
    <?php
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
    if($edit && !$item)throw new InvalidArgumentException('El registro que desea editar ya no existe.');
    ?>
    <div class="split-layout"><section class="panel-card list-panel"><?php panelTitle($client ? 'Directorio de clientes' : 'Directorio de proveedores');
    $filters = searchForm($page, $client ? 'Buscar por nombre o cédula…' : 'Buscar por empresa o contacto…');
    $data=paginated($db,$page,$filters); $items=$data['items']; ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th><?= $client ? 'Cliente / cédula' : 'Empresa / contacto' ?></th><th>Teléfono</th><th>Correo electrónico</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(4); foreach ($items as $r): ?><tr><td><strong><?= e($r[$name]) ?></strong><small class="cell-subtitle"><?= e($r[$other]) ?></small></td><td><?= e(phoneLabel($r['telefono'])) ?></td><td><?= e($r['email'] ?: '—') ?></td><td><?= editLink($page, (int)$r[$pk]) ?></td></tr><?php endforeach ?>
    </tbody></table></div><?php pagination($page,$data); ?></section><section class="panel-card form-panel"><?php panelTitle($item ? 'Editar registro' : ($client ? 'Nuevo cliente' : 'Nuevo proveedor')); startForm($page, $client ? 'client' : 'supplier'); ?>
    <input type="hidden" name="id" value="<?= (int)($item[$pk] ?? 0) ?>">
    <?php input($client ? 'Nombre completo' : 'Nombre de la empresa', $name, $item[$name] ?? '', 'text', 'required maxlength="' . ($client ? 100 : 150) . '"');
    input($client ? 'Cédula' : 'Persona de contacto', $other, $item[$other] ?? '', 'text', 'required maxlength="' . ($client ? 20 : 100) . '"');
    input('Teléfono', 'telefono', phoneLabel($item['telefono'] ?? ''), 'tel', 'required maxlength="20" placeholder="8888-8888"');
    input('Correo electrónico' . ($client ? ' (opcional)' : ''), 'email', $item['email'] ?? '', 'email', 'maxlength="100" ' . ($client ? '' : 'required')); ?>
    <button class="button is-primary is-fullwidth">Guardar <?= $client ? 'cliente' : 'proveedor' ?></button>
    <?php if ($item): ?><a class="button is-text is-fullwidth mt-2" href="index.php?page=<?= $page ?>" hx-get="index.php?page=<?= $page ?>" hx-target="#content" hx-push-url="true">Cancelar edición</a><?php endif ?></form><?php if(!$client&&$item): ?><button type="button" class="button is-light is-fullwidth mt-4" data-supplier-catalog="<?= (int)$item[$pk] ?>">Repuestos que ofrece</button><?php endif ?></section></div>
    <?php
}

function operationsPage(PDO $db, string $page): void
{
    $sale = $page === 'ventas';
    $operation=$sale?'sale':'purchase';
    $selectedPart=selectedPostId($operation,'id_repuesto'); $selectedPerson=selectedPostId($operation,$sale?'id_cliente':'id_proveedor');
    $parts=lookupItems($db,$sale?'repuestos':'repuestos_compra','',$selectedPart,$sale?0:$selectedPerson);
    $people=lookupItems($db,$sale?'clientes':'proveedores','',$selectedPerson);
    $sellers = $sale ? rows($db, "SELECT usuario_id,nombre_usuario FROM RP13_USUARIOS WHERE estado='ACTIVO' AND nombre_usuario <> 'sistema' ORDER BY nombre_usuario") : [];
    ?>
    <div class="info-strip"><span><?= $sale ? '↗' : '↙' ?></span><p><?= $sale ? 'Al confirmar la venta se descuentan las existencias. El precio se toma del catálogo.' : 'Al confirmar la recepción se suman las unidades al inventario.' ?> Seleccione el repuesto y la cantidad.</p></div>
    <div class="split-layout operations-layout"><section class="panel-card list-panel"><?php panelTitle($sale ? 'Ventas registradas' : 'Compras recibidas', 'Operaciones confirmadas');
    $filters=searchForm($page,$sale?'Buscar cliente…':'Buscar proveedor…',[],$sellers); $data=paginated($db,$page,$filters); $items=$data['items']; foreach($items as &$row)$row['estado']=statusLabel($row['estado']);unset($row); exportLinks($page,$page); ?>
    <p class="result-summary">Monto total de los <?= number_format($data['total']) ?> resultados: <strong><?= money($data['amount']) ?></strong></p>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Referencia</th><th><?= $sale ? 'Cliente / vendedor' : 'Proveedor' ?></th><th>Total</th><th>Estado</th><th></th></tr></thead><tbody>
    <?php if (!$items) emptyRow(5); foreach ($items as $r): ?><tr><td><strong>#<?= (int)$r['id'] ?></strong><small class="cell-subtitle"><?= dateLabel($r['fecha']) ?></small></td><td><?= e($r['persona']) ?><?php if ($sale): ?><small class="cell-subtitle"><?= e($r['vendedor']) ?></small><?php endif ?></td><td class="amount"><?= money($r['total']) ?></td><td><span class="tag is-success is-light"><?= e($r['estado']) ?></span></td><td><button class="button is-small is-light" type="button" data-detail-kind="<?= $page ?>" data-detail-id="<?= (int)$r['id'] ?>">Ver detalle</button></td></tr><?php endforeach ?>
    </tbody></table></div>
    <?php pagination($page,$data); ?></section>
    <section class="panel-card form-panel"><?php panelTitle($sale ? 'Nueva venta' : 'Registrar compra', 'Todos los montos están en colones costarricenses (CRC).');
    if (!$people || !$parts || ($sale && !$sellers)): ?><div class="notification is-warning is-light">Para continuar necesita <?= $sale ? 'un cliente, un repuesto y un vendedor activo' : 'un proveedor con repuestos habilitados' ?>. Complete los registros que faltan.</div><?php endif;
    startForm($page, $sale ? 'sale' : 'purchase');
    lookupField($sale?'Cliente':'Proveedor',$sale?'id_cliente':'id_proveedor',$sale?'clientes':'proveedores',$people,$operation,$selectedPerson);
    if ($sale) options('Vendedor responsable', 'id_vendedor', $sellers, 'usuario_id', 'nombre_usuario'); ?>
    <div class="cart-editor" data-operation="<?= $operation ?>"><script type="application/json" class="cart-seed"><?= json_encode(cartSeed($db,$operation),JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR) ?></script><input type="hidden" name="lines" class="cart-lines" value="[]">
    <h3 class="cart-heading">Agregar repuestos</h3>
    <?php lookupField('Repuesto','id_repuesto',$sale?'repuestos':'repuestos_compra',$parts,$operation,0,false); ?>
    <?php if(!$sale): ?><p class="help mb-4">Primero seleccione un proveedor. Solo se muestran sus repuestos habilitados. Puede administrar su oferta desde Proveedores → Editar → Repuestos que ofrece.</p><?php endif ?>
    <div id="stock-hint" class="help mb-4">Seleccione una referencia para consultar sus existencias.</div>
    <?php input('Cantidad', 'cantidad', 1, 'number', 'min="1" max="2147483647" step="1"');
    if (!$sale) input('Precio unitario de compra', 'precio_unitario', '0.00', 'number', 'min="0" max="99999999.99" step="0.01"'); ?>
    <button type="button" class="button is-light is-fullwidth" data-cart-add>+ Agregar a la operación</button>
    <div class="cart-alert notification is-warning is-light" role="alert" hidden></div><div class="cart-table-wrap"><table class="table is-fullwidth cart-table"><thead><tr><th>Repuesto</th><th>Cant.</th><th>Precio</th><th></th></tr></thead><tbody class="cart-items"></tbody></table></div>
    <?php if(!$sale): ?><button type="button" class="button is-small is-light" data-cart-clear>Vaciar repuestos</button><?php endif ?>
    <div class="total-preview"><span>Total estimado</span><strong id="total-preview">₡ 0.00</strong><small>Revise la cantidad y el precio antes de confirmar.</small></div>
    <button class="button is-primary is-fullwidth" data-cart-submit <?= !$people || !$parts || ($sale && !$sellers) ? 'disabled' : '' ?>><?= $sale ? 'Confirmar venta' : 'Confirmar recepción' ?></button></div>
    <p class="help mt-3">Una vez confirmada, la operación no se puede editar ni anular.</p></form></section></div>
    <?php
}

function inventoryPage(PDO $db): void
{
    ?>
    <section class="panel-card mb-5"><?php panelTitle('Existencias por repuesto', 'Las existencias corresponden al momento de la consulta; las fechas filtran el historial.');
    $cats=rows($db,'SELECT id_categoria,nombre FROM RP01_CATEGORIAS ORDER BY nombre');
    $filters = searchForm('inventario', 'Buscar por nombre o código…',$cats);
    $data=paginated($db,'inventario',$filters); $items=$data['items']; foreach($items as &$row)$row['estado']=statusLabel($row['estado']);unset($row); exportLinks('inventario','inventario'); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Código</th><th>Estado</th><th>Actual</th><th>Mínimo</th><th>Nivel</th></tr></thead><tbody>
    <?php if (!$items) emptyRow(6); foreach ($items as $r): ?><tr><td><strong><?= e($r['nombre']) ?></strong></td><td><?= e($r['codigo_barras']) ?></td><td><?= e($r['estado']) ?></td><td class="amount"><?= (int)$r['stock_actual'] ?></td><td><?= (int)$r['stock_minimo'] ?></td><td><span class="tag <?= $r['stock_actual'] <= $r['stock_minimo'] ? 'is-warning is-light' : 'is-success is-light' ?>"><?= $r['stock_actual'] <= $r['stock_minimo'] ? 'Reponer' : 'Disponible' ?></span></td></tr><?php endforeach ?>
    </tbody></table></div><?php pagination('inventario',$data); ?></section>
    <section class="panel-card"><?php panelTitle('Historial de movimientos');
    $historyData=paginated($db,'historial',$filters,'h'); $history=$historyData['items']; exportLinks('inventario','historial'); ?>
    <div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Fecha</th><th>Repuesto</th><th>Movimiento</th><th>Cantidad</th><th>Responsable</th><th>Motivo</th></tr></thead><tbody>
    <?php if (!$history) emptyRow(6, 'No hay movimientos para esta búsqueda.'); foreach ($history as $r): ?><tr><td><?= dateLabel($r['fecha']) ?></td><td><?= e($r['nombre']) ?></td><td><span class="tag <?= $r['tipo_movimiento'] === 'ENTRADA_COMPRA' ? 'is-info is-light' : 'is-light' ?>"><?= e($r['tipo_movimiento']) ?></span></td><td class="amount"><?= (int)$r['cantidad'] ?></td><td><?= e($r['nombre_usuario']) ?></td><td><?= e($r['motivo']) ?></td></tr><?php endforeach ?>
    </tbody></table></div><?php pagination('inventario',$historyData); ?></section><p class="help mt-3">Las compras figuran a nombre de «sistema» y las ventas a nombre del vendedor responsable.</p>
    <?php
}

function reportsPage(PDO $db): void
{
    ?>
    <section class="panel-card reports-panel"><?php panelTitle('Descargar información', 'Seleccione el reporte, el período y el formato.'); ?>
    <form action="index.php" method="get" target="_blank" class="report-form">
    <?php filterSelect('export','Reporte',reportTitles(),'ventas');
    filterSelect('format','Formato',['xlsx'=>'Excel (.xlsx)','csv'=>'Archivo CSV','print'=>'Documento para imprimir / guardar como PDF'],'xlsx'); ?>
    <div class="form-grid"><?php input('Desde','desde',date('Y-m-01'),'date','min="1900-01-01" max="2100-12-31"'); input('Hasta','hasta',date('Y-m-d'),'date','min="1900-01-01" max="2100-12-31"'); ?></div>
    <?php input('Nombre o código (opcional)','q','','search','maxlength="150"');
    lookupField('Repuesto para consultar sus entradas y salidas','repuesto','repuestos_kardex',lookupItems($db,'repuestos_kardex'),'purchase',0,false); ?>
    <button class="button is-primary">Generar reporte</button>
    <p class="help mt-4">Ventas y compras muestran cuánto se vendió o compró y qué repuestos se incluyeron. «Repuestos que faltan» recomienda qué comprar. Para ver las entradas y salidas de un repuesto, selecciónelo arriba. Las fechas incluyen ambos días; el inventario muestra las cantidades actuales.</p>
    </form></section>
    <section class="info-strip"><span>↓</span><p>También puede descargar desde cada listado para conservar sus filtros. Excel y CSV permiten hasta 100.000 registros; el documento imprimible, hasta 5.000. Si supera el límite, ajuste los filtros. Los archivos incluyen fecha de emisión, filtros y cantidad de registros; compras y ventas incluyen el total del período.</p></section>
    <?php
}
