<?php
declare(strict_types=1);
require_once __DIR__.'/Queries.php';
require_once __DIR__.'/Presentation.php';
require_once __DIR__.'/XlsxWriter.php';
require_once __DIR__.'/ReportLayout.php';
require_once __DIR__.'/BusinessReports.php';
require_once __DIR__.'/ExportSelection.php';

function reportTitles(): array
{
    return ['ventas'=>'Ventas del período','compras'=>'Compras recibidas del período','inventario'=>'Inventario actual','reposicion'=>'Repuestos por revisar para compra',
        'kardex'=>'Entradas y salidas de un repuesto','historial'=>'Entradas y salidas del inventario','repuestos'=>'Catálogo interno de repuestos','precios'=>'Lista de precios'];
}
function businessReportPlan(string $kind, array $filters): array
{
    if($kind==='reposicion') {
        $filters['level']='bajo'; $filters['state']='ACTIVO';
        $plan=queryPlan('inventario',$filters);
        $plan['fields'].=",R.stock_minimo-R.stock_actual faltante,CASE WHEN R.stock_actual=0 THEN 'Sin unidades' WHEN R.stock_actual<R.stock_minimo THEN 'Por debajo del mínimo' ELSE 'En el mínimo' END alerta,COALESCE(Ventas.unidades,0) vendido_30,Compra.ultima_compra";
        $plan['from'].=" OUTER APPLY(SELECT SUM(CONVERT(bigint,D.cantidad)) unidades FROM dbo.RP08_DETALLE_VENTAS D JOIN dbo.RP07_VENTAS V ON V.id_venta=D.id_venta WHERE D.id_repuesto=R.id_repuesto AND V.estado='COMPLETADA' AND V.fecha>=DATEADD(day,-29,CONVERT(date,GETDATE())) AND V.fecha<DATEADD(day,1,CONVERT(date,GETDATE()))) Ventas OUTER APPLY(SELECT MAX(C.fecha) ultima_compra FROM dbo.RP10_DETALLE_COMPRAS D JOIN dbo.RP09_COMPRAS C ON C.id_compra=D.id_compra WHERE D.id_repuesto=R.id_repuesto AND C.estado='RECIBIDO' AND C.fecha<=SYSDATETIME()) Compra";
        $plan['columns']=[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['categoria','Categoría','text'],['stock_actual','Cantidad registrada','int'],['stock_minimo','Cantidad mínima','int'],['faltante','Faltante para llegar al mínimo','int'],['alerta','Situación','text'],['vendido_30','Unidades vendidas en los últimos 30 días','int'],['ultima_compra','Última compra recibida','date']];
        $plan['order']="CASE WHEN R.stock_actual=0 THEN 0 WHEN R.stock_actual<R.stock_minimo THEN 1 ELSE 2 END,COALESCE(Ventas.unidades,0) DESC,R.nombre,R.id_repuesto";
        return $plan;
    }
    if($kind==='kardex') {
        if(!$filters['part']) throw new InvalidArgumentException('Seleccione un repuesto para consultar sus entradas y salidas.');
        $plan=queryPlan('historial',$filters);
        $plan['from']="(SELECT M.*,R0.stock_actual-SUM(CASE WHEN M.tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,M.cantidad) ELSE -CONVERT(bigint,M.cantidad) END) OVER(PARTITION BY M.id_repuesto)+SUM(CASE WHEN M.tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,M.cantidad) ELSE -CONVERT(bigint,M.cantidad) END) OVER(PARTITION BY M.id_repuesto ORDER BY M.fecha,M.id_historial ROWS UNBOUNDED PRECEDING) saldo FROM dbo.RP11_INVENTARIO_HISTORIAL M JOIN dbo.RP03_REPUESTOS R0 ON R0.id_repuesto=M.id_repuesto WHERE M.id_repuesto=".(int)$filters['part'].") H JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto JOIN dbo.RP13_USUARIOS U ON U.usuario_id=H.usuario_id";
        $plan['countFrom']=$plan['from'];
        $plan['fields'].=",CASE WHEN H.tipo_movimiento='ENTRADA_COMPRA' THEN H.cantidad ELSE 0 END entrada,CASE WHEN H.tipo_movimiento='SALIDA_VENTA' THEN H.cantidad ELSE 0 END salida,H.saldo";
        $plan['order']='H.fecha,H.id_historial';
        $plan['columns']=[['fecha','Fecha','date'],['tipo_movimiento','Movimiento','text'],['entrada','Cantidad que entró','int'],['salida','Cantidad que salió','int'],['saldo','Cantidad disponible después','int'],['nombre_usuario','Responsable','text'],['motivo','Referencia','text']];
        return $plan;
    }
    if($kind==='precios')$filters['state']='ACTIVO';
    if(in_array($kind,['ventas','compras'],true)&&$filters['state']==='')$filters['state']=$kind==='ventas'?'COMPLETADA':'RECIBIDO';
    $plan=queryPlan($kind==='precios'?'repuestos':$kind,$filters);
    if($kind==='precios')$plan['columns']=[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['categoria','Categoría','text'],['precio_venta','Precio de venta','money']];
    if($kind==='inventario') {
        $plan['fields'].=",CONVERT(decimal(20,2),R.precio_compra)*R.stock_actual valor_estimado,CASE WHEN R.stock_actual=0 THEN 'Sin unidades' WHEN R.stock_actual<=R.stock_minimo THEN 'En el mínimo o por debajo' ELSE 'Sobre el mínimo' END nivel";
        $plan['columns']=[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['categoria','Categoría','text'],['estado','Estado','text'],['stock_actual','Cantidad registrada','int'],['stock_minimo','Cantidad mínima','int'],['nivel','Situación','text'],['precio_compra','Precio de compra actual','money'],['valor_estimado','Valor estimado al precio actual','money']];
    }
    if(in_array($kind,['ventas','compras'],true)) {
        $a=$kind==='ventas'?'V':'C';$key=$kind==='ventas'?'id_venta':'id_compra';
        $plan['fields'].=','.tradeReferenceSql($kind,"$a.$key").' referencia';
        $plan['columns'][0]=['referencia',$kind==='ventas'?'Venta':'Compra','text'];
    }
    return $plan;
}
function reportCaption(string $kind, array $f): string
{
    $parts=['Moneda: colones costarricenses (CRC, ₡)'];
    if (in_array($kind,['ventas','compras','historial','kardex'],true)) $parts[]='Período: '.($f['from']?shortDateLabel($f['from']):'sin fecha inicial').' al '.($f['to']?shortDateLabel($f['to']):'sin fecha final').' (ambas fechas incluidas)';
    if ($kind==='reposicion') $parts[]='Repuestos activos en el mínimo o por debajo. El faltante solo indica cuánto falta para alcanzar el mínimo, no una cantidad de compra recomendada. Ventas: últimos 30 días calendario, incluido hoy; no el período de los filtros';
    if ($kind==='kardex') $parts[]='La cantidad después de cada entrada o salida se calcula a partir del inventario actual y su historial completo';
    if ($kind==='inventario') $parts[]='Cantidades registradas al generar el reporte, no un conteo físico. Valor estimado al precio de compra actual, no valoración contable histórica';
    if ($kind==='precios') $parts[]='Precios actuales de repuestos activos. Consulte disponibilidad. No es cotización ni factura fiscal';
    if(in_array($kind,['ventas','compras'],true))$parts[]=$kind==='ventas'?'Ventas completadas registradas; no acredita cobros. Precios de cada venta, no del catálogo actual':'Compras recibidas registradas; no acredita pagos. Costos guardados en cada compra';
    if ($f['q']!=='') $parts[]='Búsqueda: '.$f['q'];
    if ($f['category'] && in_array($kind,['inventario','repuestos','historial'],true)) $parts[]='Categoría #'.$f['category'];
    if ($f['state']!=='' && in_array($kind,['repuestos','inventario','ventas','compras'],true)) $parts[]='Estado: '.statusLabel($f['state']);
    if ($f['seller'] && $kind==='ventas') $parts[]='Vendedor #'.$f['seller'];
    if ($f['level']!=='' && in_array($kind,['inventario','repuestos'],true)) $parts[]=$f['level']==='bajo'?'Existencias por reponer':'Existencias sobre el mínimo';
    if ($f['type']!=='' && $kind==='historial') $parts[]='Movimiento: '.statusLabel($f['type']);
    if($f['client'])$parts[]='Cliente #'.$f['client'];
    if($f['supplier'])$parts[]='Proveedor #'.$f['supplier'];
    if($f['ref_from']||$f['ref_to'])$parts[]='Números de operación: '.($f['ref_from']?:'primero').' a '.($f['ref_to']?:'último');
    if($f['part']&&in_array($kind,['ventas','compras'],true))$parts[]='Operaciones que incluyen el repuesto #'.$f['part'].'; se muestran todos sus repuestos';
    if(!empty($f['take']))$parts[]='Selección de hasta '.$f['take'].' registros desde el resultado '.($f['offset']+1).', '.($f['sort']==='recientes'?'más recientes primero':'más antiguos primero');
    return $parts?implode(' · ',$parts):'Todos los registros';
}
function csvValue($value, string $type): string
{
    $text=(string)($value??'');
    if (!in_array($type,['money','int'],true) && preg_match('/^[\s\x00-\x1f\x{FEFF}\x{200B}]*[=+@-]|^[\t\r\n]/u',$text)) $text="'".$text;
    return $text;
}
function exportReport(PDO $db, string $kind, string $format): never
{
    $titles=reportTitles();
    if (!isset($titles[$kind]) || !in_array($format,['xlsx','csv','print'],true)) throw new InvalidArgumentException('Seleccione un reporte y un formato válidos.');
    $filters=exportSelectionFilters(); $plan=selectExportPlan($kind,businessReportPlan($kind,$filters),$filters);
    $trade=in_array($kind,['ventas','compras'],true);
    $limit=exportLimits($format,$trade);
    // Se mantiene una lectura consistente entre conteo, total y filas.
    $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->beginTransaction(); $file=null;
    try {
        $summary=querySummary($db,$plan);
        $summary['record_label']=match($kind){'ventas'=>'Cantidad de ventas','compras'=>'Cantidad de compras','kardex','historial'=>'Cantidad de movimientos',default=>'Repuestos incluidos'};
        $summary['average_label']=$kind==='ventas'?'Promedio por venta':'Promedio por compra';
        $summary['detail_label']=match($kind){'ventas'=>'Ventas registradas','compras'=>'Compras registradas','historial','kardex'=>'Entradas y salidas',default=>'Detalle de repuestos'};
        $detailPlan=null;$detailSummary=null;
        if($trade) {
            checkTradeReport($db,$kind,$plan);
            $detailPlan=tradeDetailPlan($kind,$plan);$detailSummary=querySummary($db,$detailPlan);
            if($format!=='print'&&(int)$detailSummary['total']>500000)throw new InvalidArgumentException('El detalle supera 500.000 renglones. Reduzca el período; no se exportó un detalle incompleto.');
            $summary['units']=rows($db,'SELECT COALESCE(SUM(CONVERT(bigint,D.cantidad)),0) unidades FROM '.$detailPlan['from'].$detailPlan['where'],$detailPlan['params'])[0]['unidades'];
        }
        $summary['metrics']=reportMetrics($db,$kind,$plan,$summary);
        $summary['print_summary_only']=$format==='print'&&$trade;
        if ((int)$summary['total']>$limit) throw new InvalidArgumentException('El reporte supera '.number_format($limit).' registros. Reduzca el rango de fechas o aplique más filtros.');
        $generated=rows($db,'SELECT CONVERT(varchar(19),SYSDATETIME(),120) fecha')[0]['fecha'];
        $caption=reportCaption($kind,$filters);
        if($kind==='kardex') {
            $part=rows($db,'SELECT nombre,codigo_barras FROM RP03_REPUESTOS WHERE id_repuesto=?',[$filters['part']]);
            if(!$part) throw new InvalidArgumentException('El repuesto seleccionado no existe.');
            $caption=$part[0]['nombre'].' · '.$part[0]['codigo_barras'].' · '.$caption;
        }
        $blocks=$format==='csv'?[]:reportBreakdowns($db,$kind,$plan);
        $overviewBlocks=reportOverviewBlocks($kind,$blocks);
        $file=tmpfile();
        if ($file===false) throw new RuntimeException('No se pudo preparar la descarga.');
        if ($format==='xlsx') {
            $extra=[];
            if($trade) {
                $detailSummary['record_label']='Renglones de repuestos';
                $extra[]=['name'=>'Detalle de repuestos','plan'=>$detailPlan,'summary'=>$detailSummary,
                    'content'=>xlsxSheet(streamReportRows($db,$detailPlan),$detailPlan,$detailSummary,'Detalle de '.($kind==='ventas'?'ventas':'compras'),$caption,$generated)];
                foreach([2=>'Por repuesto',1=>$kind==='ventas'?'Por vendedor':'Por proveedor'] as $index=>$name) {
                    $block=$blocks[$index];$columns=$block['columns'];
                    if($index===1)$columns=[$columns[0],$columns[1],$columns[3],$columns[2]];
                    $sectionPlan=['columns'=>$columns];$sectionSummary=['total'=>count($block['rows']),'importe'=>$summary['importe'],'record_label'=>$index===2?'Repuestos distintos':($kind==='ventas'?'Vendedores':'Proveedores')];
                    $extra[]=['name'=>$name,'plan'=>$sectionPlan,'summary'=>$sectionSummary,
                        'content'=>xlsxSheet($block['rows'],$sectionPlan,$sectionSummary,$block['title'],$caption,$generated)];
                }
                if($kind==='compras') {
                    $costs=purchaseCostAnalysis($db,$detailPlan);$costPlan=['columns'=>$costs['columns']];
                    $costSummary=['total'=>count($costs['rows']),'importe'=>$summary['importe'],'record_label'=>'Combinaciones de proveedor y repuesto'];
                    $extra[]=['name'=>'Costos por proveedor','plan'=>$costPlan,'summary'=>$costSummary,
                        'content'=>xlsxSheet($costs['rows'],$costPlan,$costSummary,$costs['title'],$caption.' · Costo promedio = monto comprado / unidades recibidas; no es una cotización vigente',$generated)];
                }
            }
            writeWorkbook($file,xlsxSheet(streamReportRows($db,$plan),$plan,$summary,$titles[$kind],$caption,$generated),$plan,$summary,
                xlsxOverview($summary,$overviewBlocks,$titles[$kind],$caption,$generated),$extra,reportSheetName($kind));
        } elseif ($format==='csv') {
            // CSV es una tabla limpia importable; Excel incluye además resúmenes y otras hojas.
            $csvPlan=$detailPlan??$plan;
            fwrite($file,"\xEF\xBB\xBF");
            fputcsv($file,array_map(static fn($column)=>$column[1].($column[2]==='money'?' (CRC)':''),$csvPlan['columns']),';','"','');
            foreach(streamReportRows($db,$csvPlan) as $row) {
                $values=[]; foreach($csvPlan['columns'] as [$key,$label,$type]) $values[]=csvValue($type==='date'?dateLabel($row[$key]):reportValue($key,$row[$key]??''),$type);
                fputcsv($file,$values,';','"','');
            }
        } else {
            writePrintReport($file,$summary['print_summary_only']?[]:streamReportRows($db,$plan),$plan,$summary,$overviewBlocks,$titles[$kind],$caption,$generated);
        }
        $db->commit();
        $size=ftell($file); rewind($file);
        header('Content-Type: '.match($format){'xlsx'=>'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet','csv'=>'text/csv; charset=UTF-8',default=>'text/html; charset=UTF-8'});
        if ($format!=='print') header('Content-Disposition: attachment; filename="repuesto_'.$kind.'_'.date('Ymd_His').'.'.$format.'"');
        header('Content-Length: '.$size);
        fpassthru($file); fclose($file); exit;
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if (is_resource($file)) fclose($file);
        throw $e;
    }
}
