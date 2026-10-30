<?php
declare(strict_types=1);
require_once __DIR__.'/Queries.php';
require_once __DIR__.'/Presentation.php';
require_once __DIR__.'/XlsxWriter.php';
require_once __DIR__.'/ReportLayout.php';

function reportTitles(): array
{
    return ['ventas'=>'Ventas','compras'=>'Compras','inventario'=>'Inventario','reposicion'=>'Repuestos que faltan',
        'kardex'=>'Entradas y salidas de un repuesto','historial'=>'Entradas y salidas del inventario','repuestos'=>'Catálogo de repuestos'];
}
function businessReportPlan(string $kind, array $filters): array
{
    if($kind==='reposicion') {
        $filters['level']='bajo'; $filters['state']='ACTIVO';
        $plan=queryPlan('inventario',$filters);
        $plan['fields'].=',R.stock_minimo-R.stock_actual+1 sugerido,(R.stock_minimo-R.stock_actual+1)*R.precio_compra costo_estimado';
        $plan['columns']=[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['categoria','Categoría','text'],['stock_actual','Cantidad disponible','int'],['stock_minimo','Cantidad mínima','int'],['sugerido','Cantidad recomendada para comprar','int'],['costo_estimado','Costo aproximado','money']];
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
    return queryPlan($kind,$filters);
}
function reportCaption(string $kind, array $f): string
{
    $parts=[];
    if (in_array($kind,['ventas','compras','historial','kardex'],true)) $parts[]='Período: '.($f['from']?shortDateLabel($f['from']):'sin fecha inicial').' al '.($f['to']?shortDateLabel($f['to']):'sin fecha final').' (ambas fechas incluidas)';
    if ($kind==='reposicion') $parts[]='Repuestos activos con poca cantidad disponible. Se recomienda comprar hasta superar el mínimo en una unidad. El costo es aproximado';
    if ($kind==='kardex') $parts[]='La cantidad después de cada entrada o salida se calcula a partir del inventario actual y su historial completo';
    if ($kind==='inventario') $parts[]='Cantidades disponibles al generar el reporte';
    if ($f['q']!=='') $parts[]='Búsqueda: '.$f['q'];
    if ($f['category'] && in_array($kind,['inventario','repuestos','historial'],true)) $parts[]='Categoría #'.$f['category'];
    if ($f['state']!=='' && in_array($kind,['repuestos','inventario','ventas','compras'],true)) $parts[]='Estado: '.statusLabel($f['state']);
    if ($f['seller'] && $kind==='ventas') $parts[]='Vendedor #'.$f['seller'];
    if ($f['level']!=='' && in_array($kind,['inventario','repuestos'],true)) $parts[]=$f['level']==='bajo'?'Existencias por reponer':'Existencias sobre el mínimo';
    if ($f['type']!=='' && $kind==='historial') $parts[]='Movimiento: '.statusLabel($f['type']);
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
    $filters=listFilters(); $plan=businessReportPlan($kind,$filters); $limit=$format==='print'?5000:100000;
    // Se mantiene una lectura consistente entre conteo, total y filas.
    $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->beginTransaction(); $file=null;
    try {
        $summary=querySummary($db,$plan);
        $summary['record_label']=match($kind){'ventas'=>'Cantidad de ventas','compras'=>'Cantidad de compras','kardex','historial'=>'Cantidad de movimientos',default=>'Repuestos incluidos'};
        $summary['average_label']=$kind==='ventas'?'Promedio por venta':'Promedio por compra';
        $summary['detail_label']=match($kind){'ventas'=>'Ventas registradas','compras'=>'Compras registradas','historial','kardex'=>'Entradas y salidas',default=>'Detalle de repuestos'};
        if ((int)$summary['total']>$limit) throw new InvalidArgumentException('El reporte supera '.number_format($limit).' registros. Reduzca el rango de fechas o aplique más filtros.');
        $generated=rows($db,'SELECT CONVERT(varchar(19),SYSDATETIME(),120) fecha')[0]['fecha'];
        $caption=reportCaption($kind,$filters);
        if($kind==='kardex') {
            $part=rows($db,'SELECT nombre,codigo_barras FROM RP03_REPUESTOS WHERE id_repuesto=?',[$filters['part']]);
            if(!$part) throw new InvalidArgumentException('El repuesto seleccionado no existe.');
            $caption=$part[0]['nombre'].' · '.$part[0]['codigo_barras'].' · '.$caption;
        }
        $blocks=$format==='csv'?[]:reportBreakdowns($db,$kind,$plan);
        $stmt=queryStatement($db,'SELECT '.$plan['fields'].' FROM '.$plan['from'].$plan['where'].' ORDER BY '.$plan['order'],$plan['params']);
        $file=tmpfile();
        if ($file===false) throw new RuntimeException('No se pudo preparar la descarga.');
        if ($format==='xlsx') {
            writeWorkbook($file,xlsxSheet($stmt,$plan,$summary,$titles[$kind],$caption,$generated),$plan,$summary,xlsxOverview($summary,$blocks,$titles[$kind],$caption,$generated));
        } elseif ($format==='csv') {
            fwrite($file,"\xEF\xBB\xBF");
            fputcsv($file,['Repuesto · '.$titles[$kind]],';','"','');
            fputcsv($file,['Emitido: '.dateLabel($generated),$caption,$summary['record_label'].': '.$summary['total']],';','"','');
            fputcsv($file,array_column($plan['columns'],1),';','"','');
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
                $values=[]; foreach($plan['columns'] as [$key,$label,$type]) $values[]=csvValue($type==='date'?dateLabel($row[$key]):reportValue($key,$row[$key]??''),$type);
                fputcsv($file,$values,';','"','');
            }
            if (isset($summary['importe'])) fputcsv($file,['Total del período',$summary['importe']],';','"','');
        } else {
            writePrintReport($file,$stmt,$plan,$summary,$blocks,$titles[$kind],$caption,$generated);
        }
        $stmt->closeCursor();
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
