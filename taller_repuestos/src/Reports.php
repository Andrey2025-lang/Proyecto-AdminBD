<?php
declare(strict_types=1);
require_once __DIR__.'/Queries.php';
require_once __DIR__.'/XlsxWriter.php';
require_once __DIR__.'/ReportLayout.php';

function reportTitles(): array
{
    return ['ventas'=>'Estado de ventas','compras'=>'Estado de compras','inventario'=>'Existencias actuales',
        'historial'=>'Movimientos de inventario','clientes'=>'Directorio de clientes','proveedores'=>'Directorio de proveedores','repuestos'=>'Catálogo de repuestos'];
}
function reportCaption(string $kind, array $f): string
{
    $parts=[];
    if (in_array($kind,['ventas','compras','historial'],true)) $parts[]='Período: '.($f['from']?:'sin fecha inicial').' al '.($f['to']?:'sin fecha final').' (ambas fechas incluidas)';
    if ($kind==='inventario') $parts[]='Existencias al momento de la emisión, no un saldo histórico';
    if ($f['q']!=='') $parts[]='Búsqueda: '.$f['q'];
    if ($f['category'] && in_array($kind,['inventario','repuestos','historial'],true)) $parts[]='Categoría #'.$f['category'];
    if ($f['state']!=='' && in_array($kind,['repuestos','inventario','ventas','compras'],true)) $parts[]='Estado: '.$f['state'];
    if ($f['seller'] && $kind==='ventas') $parts[]='Vendedor #'.$f['seller'];
    if ($f['level']!=='' && in_array($kind,['inventario','repuestos'],true)) $parts[]=$f['level']==='bajo'?'Existencias por reponer':'Existencias sobre el mínimo';
    if ($f['type']!=='' && $kind==='historial') $parts[]='Movimiento: '.$f['type'];
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
    $filters=listFilters(); $plan=queryPlan($kind,$filters); $limit=$format==='print'?5000:100000;
    // Se mantiene una lectura consistente entre conteo, total y filas.
    $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
    $db->beginTransaction(); $file=null;
    try {
        $summary=querySummary($db,$plan);
        if ((int)$summary['total']>$limit) throw new InvalidArgumentException('El reporte supera '.number_format($limit).' registros. Reduzca el rango de fechas o aplique más filtros.');
        $generated=rows($db,'SELECT CONVERT(varchar(19),SYSDATETIME(),120) fecha')[0]['fecha'];
        $caption=reportCaption($kind,$filters);
        $blocks=$format==='csv'?[]:reportBreakdowns($db,$kind,$plan);
        $stmt=queryStatement($db,'SELECT '.$plan['fields'].' FROM '.$plan['from'].$plan['where'].' ORDER BY '.$plan['order'],$plan['params']);
        $file=tmpfile();
        if ($file===false) throw new RuntimeException('No se pudo preparar la descarga.');
        if ($format==='xlsx') {
            writeWorkbook($file,xlsxSheet($stmt,$plan,$summary,$titles[$kind],$caption,$generated),$plan,$summary,xlsxOverview($summary,$blocks,$titles[$kind],$caption,$generated));
        } elseif ($format==='csv') {
            fwrite($file,"\xEF\xBB\xBF");
            fputcsv($file,['Repuesto · '.$titles[$kind]],';','"','');
            fputcsv($file,['Emitido: '.$generated,$caption,'Registros: '.$summary['total']],';','"','');
            fputcsv($file,array_column($plan['columns'],1),';','"','');
            while($row=$stmt->fetch(PDO::FETCH_ASSOC)) {
                $values=[]; foreach($plan['columns'] as [$key,$label,$type]) $values[]=csvValue($row[$key]??'',$type);
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
