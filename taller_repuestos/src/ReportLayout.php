<?php
declare(strict_types=1);

function reportBreakdowns(PDO $db, string $kind, array $plan): array
{
    $blocks=[]; $base=' FROM '.$plan['from'].$plan['where'];
    if (in_array($kind,['ventas','compras'],true)) {
        $a=$kind==='ventas'?'V':'C';
        $countLabel=$kind==='ventas'?'Cantidad de ventas':'Cantidad de compras';
        $columns=[['periodo','Mes','text'],['operaciones',$countLabel,'int'],['importe','Monto total','money'],['promedio',$kind==='ventas'?'Promedio por venta':'Promedio por compra','money']];
        $blocks[]=['title'=>'Resumen mensual','columns'=>$columns,'rows'=>rows($db,"SELECT CONVERT(char(7),$a.fecha,120) periodo,COUNT_BIG(*) operaciones,SUM($a.total) importe,AVG($a.total) promedio".$base." GROUP BY CONVERT(char(7),$a.fecha,120) ORDER BY periodo",$plan['params'])];
        $name=$kind==='ventas'?'U.nombre_usuario':'P.nombre_empresa'; $id=$kind==='ventas'?'U.usuario_id':'P.id_proveedor';
        $columns[0]=['persona',$kind==='ventas'?'Vendedor':'Proveedor','text'];
        $blocks[]=['title'=>$kind==='ventas'?'Resultados por vendedor':'Resultados por proveedor','columns'=>$columns,
            'rows'=>rows($db,"SELECT $name persona,COUNT_BIG(*) operaciones,SUM($a.total) importe,AVG($a.total) promedio".$base." GROUP BY $id,$name ORDER BY importe DESC,$name",$plan['params'])];
        $detail=$kind==='ventas'?'RP08_DETALLE_VENTAS':'RP10_DETALLE_COMPRAS'; $key=$kind==='ventas'?'id_venta':'id_compra';
        // Los importes del encabezado se cuentan una sola vez; las unidades provienen de las partidas.
        $detailBase=' FROM '.$plan['from']." JOIN dbo.$detail D ON D.$key=$a.$key JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=D.id_repuesto".$plan['where'];
        $blocks[]=['title'=>$kind==='ventas'?'Repuestos más vendidos (primeros 20)':'Repuestos comprados (primeros 20)',
            'columns'=>[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['operaciones',$countLabel,'int'],['unidades','Unidades','int'],['importe','Monto total','money']],
            'rows'=>rows($db,"SELECT TOP (20) R.codigo_barras,R.nombre,COUNT(DISTINCT $a.$key) operaciones,SUM(CONVERT(bigint,D.cantidad)) unidades,SUM(D.subtotal) importe".$detailBase.' GROUP BY R.id_repuesto,R.codigo_barras,R.nombre ORDER BY unidades DESC,R.nombre',$plan['params'])];
    } elseif ($kind==='kardex') {
        $first=rows($db,"SELECT TOP (1) H.saldo-CASE WHEN H.tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,H.cantidad) ELSE -CONVERT(bigint,H.cantidad) END apertura".$base.' ORDER BY H.fecha,H.id_historial',$plan['params']);
        $last=rows($db,'SELECT TOP (1) H.saldo cierre'.$base.' ORDER BY H.fecha DESC,H.id_historial DESC',$plan['params']);
        $blocks[]=['title'=>'Cantidades al inicio y al final','columns'=>[['apertura','Cantidad antes de la primera entrada o salida','int'],['cierre','Cantidad después de la última entrada o salida','int']],
            'rows'=>$first?[['apertura'=>$first[0]['apertura'],'cierre'=>$last[0]['cierre']]]:[]];
    } elseif (in_array($kind,['repuestos','inventario'],true)) {
        $blocks[]=['title'=>'Inventario por categoría','columns'=>[['categoria','Categoría','text'],['referencias','Tipos de repuesto','int'],['unidades','Unidades disponibles','int'],['valor','Valor al precio de compra actual','money']],
            'rows'=>rows($db,'SELECT C.nombre categoria,COUNT_BIG(*) referencias,SUM(CONVERT(bigint,R.stock_actual)) unidades,SUM(R.stock_actual*R.precio_compra) valor'.$base.' GROUP BY C.id_categoria,C.nombre ORDER BY C.nombre',$plan['params'])];
    } elseif ($kind==='historial') {
        $blocks[]=['title'=>'Movimientos por mes','columns'=>[['periodo','Mes','text'],['tipo_movimiento','Movimiento','text'],['movimientos','Registros','int'],['unidades','Unidades','int']],
            'rows'=>rows($db,'SELECT CONVERT(char(7),H.fecha,120) periodo,H.tipo_movimiento,COUNT_BIG(*) movimientos,SUM(CONVERT(bigint,H.cantidad)) unidades'.$base.' GROUP BY CONVERT(char(7),H.fecha,120),H.tipo_movimiento ORDER BY periodo,H.tipo_movimiento',$plan['params'])];
    }
    return $blocks;
}
function printReportTable($file, array $columns, iterable $records): void
{
    fwrite($file,'<div class="table-wrap"><table><thead><tr>');
    foreach($columns as [$key,$label,$type]) fwrite($file,'<th'.(in_array($type,['money','int'],true)?' class="number"':'').'>'.e($label).'</th>');
    fwrite($file,'</tr></thead><tbody>'); $count=0;
    foreach($records as $record) {
        fwrite($file,'<tr>');
        foreach($columns as [$key,$label,$type]) {
            $value=reportValue($key,$record[$key]??'');
            $text=match($type){'money'=>money($value),'int'=>number_format((int)$value),'date'=>dateLabel($value),default=>$value};
            fwrite($file,'<td'.(in_array($type,['money','int'],true)?' class="number"':($type==='date'?' class="date"':'')).'>'.e($text).'</td>');
        }
        fwrite($file,'</tr>'); $count++;
    }
    if (!$count) fwrite($file,'<tr><td class="empty" colspan="'.count($columns).'">No hay registros para los filtros seleccionados.</td></tr>');
    fwrite($file,'</tbody></table></div>');
}
function reportRecords(PDOStatement|iterable $stmt): Generator
{
    if($stmt instanceof PDOStatement) { while($row=$stmt->fetch(PDO::FETCH_ASSOC)) yield $row; }
    else yield from $stmt;
}
function writePrintReport($file, PDOStatement|iterable $stmt, array $plan, array $summary, array $blocks, string $title, string $caption, string $generated): void
{
    $css='*{box-sizing:border-box}body{font:12px Arial,sans-serif;color:#304b3b;background:#f2f5f2;margin:0;padding:30px}main{max-width:1200px;margin:auto;background:white;padding:36px 42px;border:1px solid #e0e8e0;border-radius:14px;box-shadow:0 4px 20px #183b2510}.tools{max-width:1200px;margin:0 auto 20px;display:flex;justify-content:space-between;align-items:center;color:#7d8d80;font-size:11px}button{background:#176f57;color:#fff;border:0;padding:12px 20px;border-radius:7px;cursor:pointer;font-weight:bold}.brand{display:flex;gap:12px;align-items:center;letter-spacing:2px;font-size:12px;font-weight:bold}.brand b{display:grid;place-items:center;background:#176f57;color:white;border-radius:9px;width:38px;height:38px;font-size:25px;letter-spacing:0}.document-head{display:flex;justify-content:space-between;gap:25px;padding-bottom:23px;border-bottom:3px solid #176f57}.issued{font-size:10px;line-height:1.8;text-align:right;color:#7d8d80}.eyebrow{font-size:9px;letter-spacing:2px;color:#8b9c8e;margin:27px 0 8px}h1{font-size:29px;letter-spacing:-.8px;margin:0 0 20px;color:#244c38}h2{font-size:15px;color:#315e43;margin:25px 0 12px;break-after:avoid}.filters{font-size:11px;line-height:1.8;background:#f5f8f4;border-left:3px solid #72a384;padding:12px 16px;color:#6c806f;margin:0 0 22px}.metrics{display:flex;gap:15px;margin-bottom:26px}.metric{flex:1;background:#edf5ef;border:1px solid #dfeade;padding:17px 20px;border-radius:9px}.metric span{display:block;font-size:9px;letter-spacing:1px;color:#79917d}.metric strong{display:block;font-size:23px;letter-spacing:-.5px;color:#176f57;margin-top:8px}.summary-grid{display:grid;grid-template-columns:1fr 1fr;gap:24px}.table-wrap{overflow-x:auto}table{border-collapse:collapse;width:100%;font-size:11px}th{background:#176f57;color:#fff;text-align:left;font-size:10px;padding:12px 10px;font-weight:600}td{padding:10px;border-bottom:1px solid #e2eae2;vertical-align:top;line-height:1.6;overflow-wrap:anywhere}tbody tr:nth-child(even){background:#edf5ef}.number{text-align:right;white-space:nowrap;font-variant-numeric:tabular-nums}.date{white-space:nowrap;font-size:10px}.empty{text-align:center;padding:25px;color:#8a998b}.detail-heading{margin-top:32px;display:flex;justify-content:space-between;align-items:center}.detail-heading small{font-size:10px;color:#8b9b8d}.grand-total{display:flex;justify-content:space-between;align-items:center;padding:17px 20px;background:#176f57;color:white;font-weight:bold;font-size:13px}.grand-total strong{font-size:20px}footer{margin-top:26px;border-top:1px solid #e0e9e0;padding-top:15px;font-size:9px;color:#8a9a8b;line-height:1.8}@page{size:A4 landscape;margin:14mm}@media(max-width:800px){body{padding:14px}main{padding:22px}.summary-grid{grid-template-columns:1fr}.metrics{flex-wrap:wrap}.metric{min-width:140px}.document-head{flex-wrap:wrap}.issued{text-align:left}}@media print{*{-webkit-print-color-adjust:exact;print-color-adjust:exact}body{background:white;margin:0;padding:0;font-size:10px}main{max-width:none;padding:0;border:0;border-radius:0;box-shadow:none}.tools{display:none}.summary-grid{display:grid;grid-template-columns:1fr 1fr}th,td{padding:7px 8px;font-size:9px}thead{display:table-header-group}tr{break-inside:avoid}.metric{padding:12px 16px}.metric strong{font-size:21px}footer{font-size:8px}.table-wrap{overflow:visible}h1{font-size:25px}.document-head{break-inside:avoid}.grand-total{break-inside:avoid}}';
    fwrite($file,'<!doctype html><html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>'.e($title).'</title><style>'.$css.'</style></head><body><div class="tools"><span>Vista de impresión · Seleccione «Guardar como PDF» en su navegador</span><button onclick="window.print()">Imprimir / Guardar PDF</button></div><main><header class="document-head"><div class="brand"><b>R</b><span>REPUESTO<br><small>GESTIÓN DEL TALLER</small></span></div><div class="issued">FECHA DE EMISIÓN<br><strong>'.e(dateLabel($generated)).'</strong></div></header><p class="eyebrow">REPORTE DE GESTIÓN</p><h1>'.e($title).'</h1><div class="filters">'.e($caption).'</div><section class="metrics"><div class="metric"><span>'.e($summary['record_label']??'Resultados').'</span><strong>'.number_format((int)$summary['total']).'</strong></div>');
    if(isset($summary['importe'])) fwrite($file,'<div class="metric"><span>'.e($summary['amount_label']??'Monto total').'</span><strong>'.money($summary['importe']).'</strong></div><div class="metric"><span>'.(!empty($summary['receipt'])?'Unidades en total':e($summary['average_label']??'Promedio')).'</span><strong>'.(!empty($summary['receipt'])?number_format($summary['units']):money($summary['promedio']??0)).'</strong></div>');
    fwrite($file,'</section>');
    if($blocks) {
        fwrite($file,'<section class="summary-grid">');
        foreach($blocks as $block) {
            fwrite($file,'<section><h2>'.e($block['title']).'</h2>'); printReportTable($file,$block['columns'],$block['rows']); fwrite($file,'</section>');
        }
        fwrite($file,'</section>');
    }
    fwrite($file,'<div class="detail-heading"><h2>'.e($summary['detail_label']??'Detalle de repuestos').'</h2><small>'.number_format((int)$summary['total']).' resultados</small></div>');
    printReportTable($file,$plan['columns'],reportRecords($stmt));
    if(isset($summary['importe'])) fwrite($file,'<div class="grand-total"><span>'.(!empty($summary['receipt'])?'Total del comprobante':'Total del período').'</span><strong>'.money($summary['importe']).'</strong></div>');
    fwrite($file,'<footer>Repuesto · Documento informativo. No sustituye una factura ni una certificación contable.<br>Los importes se expresan en la moneda del negocio. El documento conserva los filtros indicados al momento de su emisión.</footer></main></body></html>');
}
