<?php
declare(strict_types=1);

function queryText(string $key, int $max = 150): string
{
    $value = $_GET[$key] ?? '';
    if (!is_string($value) || !preg_match('//u', $value) || preg_match_all('/./us', $value) > $max) {
        throw new InvalidArgumentException('El filtro ' . $key . ' no es válido.');
    }
    return trim($value);
}
function queryInteger(string $key, int $default, int $min = 1): int
{
    if (!isset($_GET[$key]) || $_GET[$key] === '') return $default;
    $number = filter_var($_GET[$key], FILTER_VALIDATE_INT, ['options'=>['min_range'=>$min,'max_range'=>2147483647]]);
    if ($number === false) throw new InvalidArgumentException('El filtro ' . $key . ' no es válido.');
    return $number;
}
function queryDate(string $key): string
{
    $value = queryText($key, 10);
    if ($value === '') return '';
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    if (!$date || $date->format('Y-m-d') !== $value || $value < '1900-01-01' || $value > '2100-12-31') {
        throw new InvalidArgumentException('Indique una fecha válida entre 1900 y 2100.');
    }
    return $value;
}
function listFilters(): array
{
    $f = ['q'=>queryText('q'),'size'=>queryInteger('tam',($_GET['page']??'')==='inventario'?10:15),'history_size'=>queryInteger('htam',10),'p'=>queryInteger('p',1),'h'=>queryInteger('h',1),
        'category'=>queryInteger('categoria',0),'seller'=>queryInteger('vendedor',0),'part'=>queryInteger('repuesto',0),
        'state'=>queryText('estado',20),'level'=>queryText('nivel',20),'type'=>queryText('tipo',20),
        'from'=>queryDate('desde'),'to'=>queryDate('hasta')];
    foreach ([$f['size'],$f['history_size']] as $size) if (!in_array($size,[10,15,25,30,50,100],true)) throw new InvalidArgumentException('Seleccione una cantidad de filas válida: 10, 15, 30, 50 o 100.');
    if (!in_array($f['state'],['','ACTIVO','INACTIVO','COMPLETADA','RECIBIDO'],true)
        || !in_array($f['level'],['','bajo','disponible'],true)
        || !in_array($f['type'],['','ENTRADA_COMPRA','SALIDA_VENTA'],true)) throw new InvalidArgumentException('Uno de los filtros seleccionados no es válido.');
    if ($f['from'] !== '' && $f['to'] !== '' && $f['from'] > $f['to']) throw new InvalidArgumentException('La fecha inicial no puede ser posterior a la fecha final.');
    return $f;
}
function likeText(string $value, bool $prefix = false): string
{
    return ($prefix ? '' : '%') . strtr($value,['~'=>'~~','%'=>'~%','_'=>'~_','['=>'~[']) . '%';
}

function queryPlan(string $kind, array $f): array
{
    $where = []; $params = [];
    $money = null;
    if (in_array($kind,['repuestos','inventario'],true)) {
        $fields = 'R.id_repuesto,R.codigo_barras,R.nombre,R.precio_compra,R.precio_venta,R.stock_actual,R.stock_minimo,R.estado,C.nombre categoria';
        $from = 'dbo.RP03_REPUESTOS R JOIN dbo.RP01_CATEGORIAS C ON C.id_categoria=R.id_categoria';
        $order = 'R.nombre,R.id_repuesto';
        $columns = [['codigo_barras','Código','text'],['nombre','Repuesto','text'],['categoria','Categoría','text'],['precio_compra','Precio de compra','money'],['precio_venta','Precio de venta','money'],['stock_actual','Cantidad disponible','int'],['stock_minimo','Cantidad mínima','int'],['estado','Estado','text']];
        if ($f['q'] !== '') { $where[] = "(R.nombre LIKE ? ESCAPE '~' OR R.codigo_barras LIKE ? ESCAPE '~' OR C.nombre LIKE ? ESCAPE '~')"; $params=[likeText($f['q']),likeText($f['q'],true),likeText($f['q'])]; }
        if ($f['category']) { $where[]='R.id_categoria=?'; $params[]=$f['category']; }
        if ($f['state'] !== '') { $where[]='R.estado=?'; $params[]=$f['state']; }
        if ($f['level'] !== '') $where[]=$f['level']==='bajo' ? 'R.stock_actual<=R.stock_minimo' : 'R.stock_actual>R.stock_minimo';
    } elseif (in_array($kind,['clientes','proveedores'],true)) {
        $client=$kind==='clientes'; $alias=$client?'C':'P';
        $pk=$client?'id_cliente':'id_proveedor'; $name=$client?'nombre':'nombre_empresa'; $other=$client?'cedula':'contacto';
        $fields="$alias.$pk,$alias.$name,$alias.$other,$alias.telefono,$alias.email";
        $from=$client?'dbo.RP05_CLIENTES C':'dbo.RP02_PROVEEDORES P'; $order="$alias.$name,$alias.$pk";
        $columns=[[$name,$client?'Cliente':'Empresa','text'],[$other,$client?'Cédula':'Contacto','text'],['telefono','Teléfono','text'],['email','Correo','text']];
        if ($f['q']!=='') { $where[]="($alias.$name LIKE ? ESCAPE '~' OR $alias.$other LIKE ? ESCAPE '~')"; $params=[likeText($f['q']),likeText($f['q'],$client)]; }
    } elseif (in_array($kind,['ventas','compras'],true)) {
        $sale=$kind==='ventas'; $a=$sale?'V':'C'; $pk=$sale?'id_venta':'id_compra';
        $fields=$sale?'V.id_venta id,V.fecha,V.total,V.estado,C.nombre persona,U.nombre_usuario vendedor':'C.id_compra id,C.fecha,C.total,C.estado,P.nombre_empresa persona';
        $from=$sale?'dbo.RP07_VENTAS V JOIN dbo.RP05_CLIENTES C ON C.id_cliente=V.id_cliente JOIN dbo.RP13_USUARIOS U ON U.usuario_id=V.id_vendedor':'dbo.RP09_COMPRAS C JOIN dbo.RP02_PROVEEDORES P ON P.id_proveedor=C.id_proveedor';
        $order="$a.fecha DESC,$a.$pk DESC"; $money="$a.total";
        $columns=[['id','Referencia','int'],['fecha','Fecha','date'],['persona',$sale?'Cliente':'Proveedor','text']];
        if ($sale) $columns[]=['vendedor','Vendedor','text'];
        $columns[]=['estado','Estado','text']; $columns[]=['total','Total','money'];
        if ($f['q']!=='') { $where[]=$sale?"(C.nombre LIKE ? ESCAPE '~' OR C.cedula LIKE ? ESCAPE '~')":"P.nombre_empresa LIKE ? ESCAPE '~'"; $params=[likeText($f['q'])]; if ($sale) $params[]=likeText($f['q'],true); }
        if ($f['state']!=='') { $where[]="$a.estado=?"; $params[]=$f['state']; }
        if ($sale && $f['seller']) { $where[]='V.id_vendedor=?'; $params[]=$f['seller']; }
        if ($f['from']!=='') { $where[]="$a.fecha>=CONVERT(date,?)"; $params[]=$f['from']; }
        if ($f['to']!=='') { $where[]="$a.fecha<DATEADD(day,1,CONVERT(date,?))"; $params[]=$f['to']; }
    } elseif ($kind==='historial') {
        $fields='H.id_historial,H.fecha,H.tipo_movimiento,H.cantidad,H.motivo,R.nombre,U.nombre_usuario';
        $from='dbo.RP11_INVENTARIO_HISTORIAL H JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto JOIN dbo.RP13_USUARIOS U ON U.usuario_id=H.usuario_id';
        $order='H.fecha DESC,H.id_historial DESC';
        $columns=[['fecha','Fecha','date'],['nombre','Repuesto','text'],['tipo_movimiento','Movimiento','text'],['cantidad','Cantidad','int'],['nombre_usuario','Responsable','text'],['motivo','Motivo','text']];
        if ($f['q']!=='') { $where[]="(R.nombre LIKE ? ESCAPE '~' OR R.codigo_barras LIKE ? ESCAPE '~')"; $params=[likeText($f['q']),likeText($f['q'],true)]; }
        if ($f['category']) { $where[]='R.id_categoria=?'; $params[]=$f['category']; }
        if ($f['type']!=='') { $where[]='H.tipo_movimiento=?'; $params[]=$f['type']; }
        if ($f['from']!=='') { $where[]='H.fecha>=CONVERT(date,?)'; $params[]=$f['from']; }
        if ($f['to']!=='') { $where[]='H.fecha<DATEADD(day,1,CONVERT(date,?))'; $params[]=$f['to']; }
    } else throw new InvalidArgumentException('El reporte solicitado no existe.');
    $countFrom=$from;
    if ($f['q']==='') {
        $countFrom=match($kind) {
            'ventas'=>'dbo.RP07_VENTAS V','compras'=>'dbo.RP09_COMPRAS C',
            'repuestos','inventario'=>'dbo.RP03_REPUESTOS R',
            'historial'=>$f['category']?'dbo.RP11_INVENTARIO_HISTORIAL H JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto':'dbo.RP11_INVENTARIO_HISTORIAL H',
            default=>$from,
        };
    }
    return ['fields'=>$fields,'from'=>$from,'countFrom'=>$countFrom,'where'=>$where?' WHERE '.implode(' AND ',$where):'', 'params'=>$params,'order'=>$order,'columns'=>$columns,'money'=>$money];
}
function queryStatement(PDO $db, string $sql, array $params): PDOStatement
{
    $stmt=$db->prepare($sql);
    foreach (array_values($params) as $i=>$value) $stmt->bindValue($i+1,$value,is_int($value)?PDO::PARAM_INT:PDO::PARAM_STR);
    $stmt->execute();
    return $stmt;
}
function querySummary(PDO $db, array $plan): array
{
    return rows($db,'SELECT COUNT_BIG(*) total'.($plan['money']?',COALESCE(SUM('.$plan['money'].'),0) importe,COALESCE(AVG('.$plan['money'].'),0) promedio':'').' FROM '.$plan['countFrom'].$plan['where'],$plan['params'])[0];
}
function paginated(PDO $db, string $kind, array $filters, string $key='p'): array
{
    $plan=queryPlan($kind,$filters); $summary=querySummary($db,$plan);
    $size=$key==='h'?$filters['history_size']:$filters['size'];
    $total=(int)$summary['total']; $pages=max(1,(int)ceil($total/$size));
    $current=min($filters[$key],$pages); $offset=($current-1)*$size;
    if (in_array($kind,['ventas','compras'],true) && $filters['q']==='') {
        $pk=$kind==='ventas'?'V.id_venta':'C.id_compra';
        $sql='WITH PageIds AS (SELECT '.$pk.' id FROM '.$plan['countFrom'].$plan['where'].' ORDER BY '.$plan['order'].' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY) SELECT '.$plan['fields'].' FROM '.$plan['from'].' JOIN PageIds Page ON Page.id='.$pk.' ORDER BY '.$plan['order'];
    } else $sql='SELECT '.$plan['fields'].' FROM '.$plan['from'].$plan['where'].' ORDER BY '.$plan['order'].' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY';
    $stmt=queryStatement($db,$sql,[...$plan['params'],$offset,$size]);
    return ['items'=>$stmt->fetchAll(),'total'=>$total,'pages'=>$pages,'current'=>$current,'size'=>$size,'key'=>$key,'amount'=>$summary['importe']??null];
}

function lookupPlan(string $kind, string $term): array
{
    $params=[];
    if (in_array($kind,['repuestos','repuestos_kardex'],true)) {
        $fields='id_repuesto id,nombre label,codigo_barras,precio_compra,precio_venta,stock_actual'; $table='dbo.RP03_REPUESTOS';
        $where=$kind==='repuestos'?"estado='ACTIVO'":'1=1'; $order='nombre,id_repuesto'; $pk='id_repuesto';
        if ($term!=='') { $where.=" AND (nombre LIKE ? ESCAPE '~' OR codigo_barras LIKE ? ESCAPE '~')"; $params=[likeText($term),likeText($term,true)]; }
    } elseif ($kind==='clientes') {
        $fields='id_cliente id,nombre label,cedula';$table='dbo.RP05_CLIENTES';$where='1=1';$order='nombre,id_cliente';$pk='id_cliente';
        if ($term!=='') { $where.=" AND (nombre LIKE ? ESCAPE '~' OR cedula LIKE ? ESCAPE '~')"; $params=[likeText($term),likeText($term,true)]; }
    } elseif ($kind==='proveedores') {
        $fields='id_proveedor id,nombre_empresa label';$table='dbo.RP02_PROVEEDORES';$where='1=1';$order='nombre_empresa,id_proveedor';$pk='id_proveedor';
        if ($term!=='') { $where.=" AND nombre_empresa LIKE ? ESCAPE '~'"; $params=[likeText($term)]; }
    } else throw new InvalidArgumentException('La búsqueda solicitada no existe.');
    return compact('fields','table','where','order','pk','params');
}
function lookupItems(PDO $db, string $kind, string $term='', int $selected=0): array
{
    extract(lookupPlan($kind,$term));
    $items=rows($db,"SELECT TOP (20) $fields FROM $table WHERE $where ORDER BY $order",$params);
    if ($selected && !in_array($selected,array_map('intval',array_column($items,'id')),true)) {
        $match=rows($db,"SELECT $fields FROM $table WHERE $pk=?".($kind==='repuestos'?" AND estado='ACTIVO'":''),[$selected]);
        if ($match) array_unshift($items,$match[0]);
    }
    return $items;
}
function lookupPage(PDO $db, string $kind, string $term, int $page): array
{
    $plan=lookupPlan($kind,$term);
    if ($page<1 || $page>5000) throw new InvalidArgumentException('La página de búsqueda no es válida.');
    if ($term!=='' && preg_match_all('/./us',$term)<2) return ['items'=>[],'has_more'=>false,'page'=>$page,'minimum'=>2];
    $stmt=queryStatement($db,'SELECT '.$plan['fields'].' FROM '.$plan['table'].' WHERE '.$plan['where'].' ORDER BY '.$plan['order'].' OFFSET ? ROWS FETCH NEXT ? ROWS ONLY',[...$plan['params'],($page-1)*20,21]);
    $items=$stmt->fetchAll(); $more=count($items)>20;
    if ($more) array_pop($items);
    return ['items'=>$items,'has_more'=>$more,'page'=>$page,'minimum'=>2];
}
