<?php
declare(strict_types=1);

function tradeReferenceSql(string $kind,string $field): string
{
    return "CONCAT('".($kind==='ventas'?'V-':'C-')."',CASE WHEN $field<1000000 THEN RIGHT('000000'+CONVERT(varchar(10),$field),6) ELSE CONVERT(varchar(10),$field) END)";
}

function tradeDetailPlan(string $kind,array $header): array
{
    $sale=$kind==='ventas';$alias=$sale?'V':'C';$key=$sale?'id_venta':'id_compra';
    $table=$sale?'RP08_DETALLE_VENTAS':'RP10_DETALLE_COMPRAS';
    $reference=tradeReferenceSql($kind,"$alias.$key");
    $from=$header['from']." JOIN dbo.$table D ON D.$key=$alias.$key JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=D.id_repuesto";
    $fields="$reference referencia,$alias.fecha,".($sale?'C.nombre':'P.nombre_empresa')." persona,".($sale?'U.nombre_usuario vendedor,':'')."R.codigo_barras,R.nombre,D.cantidad,D.precio_unitario,D.subtotal";
    $columns=[['referencia',$sale?'Venta':'Compra','text'],['fecha','Fecha','date'],['persona',$sale?'Cliente':'Proveedor','text']];
    if($sale)$columns[]=['vendedor','Vendedor','text'];
    array_push($columns,['codigo_barras','Código','text'],['nombre','Repuesto','text'],['cantidad','Cantidad','int'],['precio_unitario',$sale?'Precio vendido':'Costo registrado','money'],['subtotal','Monto del repuesto','money']);
    return ['fields'=>$fields,'from'=>$from,'countFrom'=>$from,'where'=>$header['where'],'params'=>$header['params'],
        'order'=>"$alias.fecha,$alias.$key,D.".($sale?'id_detalle_venta':'id_detalle_compra'),'columns'=>$columns,'money'=>'D.subtotal'];
}

function checkTradeReport(PDO $db,string $kind,array $plan): void
{
    $sale=$kind==='ventas';$a=$sale?'V':'C';$key=$sale?'id_venta':'id_compra';$table=$sale?'RP08_DETALLE_VENTAS':'RP10_DETALLE_COMPRAS';
    $base=' FROM '.$plan['from']." OUTER APPLY(SELECT COUNT_BIG(*) lineas,SUM(D.subtotal) monto,MAX(CASE WHEN D.cantidad<=0 OR D.precio_unitario<0 OR D.subtotal<>CONVERT(decimal(20,2),D.precio_unitario)*D.cantidad THEN 1 ELSE 0 END) invalido FROM dbo.$table D WHERE D.$key=$a.$key) Revision";
    $where=$plan['where'].($plan['where']?' AND ':' WHERE ')."(Revision.lineas=0 OR Revision.monto<>$a.total OR Revision.invalido=1)";
    $bad=rows($db,"SELECT TOP(1) $a.$key id".$base.$where,$plan['params']);
    if($bad)throw new InvalidArgumentException('No se puede emitir el reporte: revise el detalle y total de la '.($sale?'venta':'compra').' #'.$bad[0]['id'].'. No se entregaron totales inconsistentes.');
}

function reportSheetName(string $kind): string
{
    return match($kind){'ventas'=>'Ventas','compras'=>'Compras','inventario'=>'Inventario','reposicion'=>'Revisar compras','kardex','historial'=>'Entradas y salidas','precios'=>'Lista de precios',default=>'Catálogo interno'};
}

function reportRows(PDO $db,array $plan): PDOStatement
{
    return queryStatement($db,'SELECT '.$plan['fields'].' FROM '.$plan['from'].$plan['where'].' ORDER BY '.$plan['order'],$plan['params']);
}

function streamReportRows(PDO $db,array $plan): Generator
{
    $stmt=reportRows($db,$plan);
    try {while($row=$stmt->fetch(PDO::FETCH_ASSOC))yield $row;}
    finally {$stmt->closeCursor();}
}

function reportMetrics(PDO $db,string $kind,array $plan,array $summary): array
{
    $base=' FROM '.$plan['from'].$plan['where'];
    if($kind==='inventario') {
        $r=rows($db,'SELECT COALESCE(SUM(CONVERT(bigint,R.stock_actual)),0) unidades,COALESCE(SUM(CASE WHEN R.stock_actual=0 THEN 1 ELSE 0 END),0) agotados,COALESCE(SUM(CASE WHEN R.stock_actual<=R.stock_minimo THEN 1 ELSE 0 END),0) bajos,COALESCE(SUM(CONVERT(decimal(20,2),R.precio_compra)*R.stock_actual),0) estimado'.$base,$plan['params'])[0];
        return [['label'=>'Unidades registradas','value'=>$r['unidades'],'type'=>'int'],['label'=>'Repuestos sin unidades','value'=>$r['agotados'],'type'=>'int'],
            ['label'=>'Repuestos en el mínimo o por debajo','value'=>$r['bajos'],'type'=>'int'],['label'=>'Valor estimado al precio de compra actual (CRC)','value'=>$r['estimado'],'type'=>'money']];
    }
    if(in_array($kind,['ventas','compras'],true)) {
        return [['label'=>$kind==='ventas'?'Unidades vendidas':'Unidades recibidas','value'=>$summary['units'],'type'=>'int'],
            ['label'=>$summary['average_label'],'value'=>$summary['promedio'],'type'=>'money']];
    }
    return [];
}

function reportOverviewBlocks(string $kind,array $blocks): array
{
    if(!in_array($kind,['ventas','compras'],true))return $blocks;
    foreach($blocks as $index=>&$block) {
        if($index===0)continue;
        $total=count($block['rows']);
        if($total>20) {
            $block['rows']=array_slice($block['rows'],0,20);
            $block['title'].=' · primeros 20 de '.number_format($total).' (lista completa en Excel)';
        }
    }
    unset($block);
    return $blocks;
}

function purchaseCostAnalysis(PDO $db,array $detailPlan): array
{
    $base=' FROM '.$detailPlan['from'].$detailPlan['where'];
    return ['title'=>'Costos por proveedor y repuesto','columns'=>[['persona','Proveedor','text'],['codigo_barras','Código','text'],['nombre','Repuesto','text'],
        ['unidades','Unidades recibidas','int'],['costo_promedio','Costo unitario promedio ponderado','money'],['ultima_fecha','Última recepción del período','date'],['importe','Monto comprado','money']],
        'rows'=>rows($db,'SELECT P.nombre_empresa persona,R.codigo_barras,R.nombre,SUM(CONVERT(bigint,D.cantidad)) unidades,SUM(CONVERT(decimal(20,2),D.subtotal))/NULLIF(SUM(CONVERT(decimal(20,2),D.cantidad)),0) costo_promedio,MAX(C.fecha) ultima_fecha,SUM(D.subtotal) importe'.$base.' GROUP BY P.id_proveedor,P.nombre_empresa,R.id_repuesto,R.codigo_barras,R.nombre ORDER BY P.nombre_empresa,R.nombre,R.codigo_barras',$detailPlan['params'])];
}
