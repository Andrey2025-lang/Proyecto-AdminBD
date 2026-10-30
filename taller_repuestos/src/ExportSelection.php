<?php
declare(strict_types=1);

function exportSelectionFilters(): array
{
    $f=listFilters();$f['take']=queryInteger('cantidad',0);$f['offset']=queryInteger('desde_resultado',1)-1;$f['sort']=queryText('orden',12)?:'recientes';
    if(!in_array($f['sort'],['recientes','antiguos'],true)||$f['take']>200000||$f['offset']>2000000)throw new InvalidArgumentException('Revise el orden y la cantidad (máximo 200.000 registros principales).');
    if($f['offset']&&!$f['take'])throw new InvalidArgumentException('Indique una cantidad al seleccionar desde un resultado específico.');
    return $f;
}
function selectExportPlan(string $kind,array $plan,array $f): array
{
    if(!$f['take'])return $plan;
    $id=match($kind){'ventas'=>'V.id_venta','compras'=>'C.id_compra','historial','kardex'=>'H.id_historial',default=>'R.id_repuesto'};
    $order=$plan['order'];
    if(in_array($kind,['ventas','compras','historial'],true)&&$f['sort']==='antiguos')$order=str_replace(' DESC',' ASC',$order);
    if($kind==='kardex'&&$f['sort']==='recientes')$order='H.fecha DESC,H.id_historial DESC';
    $selection='SELECT '.$id.' selected_id FROM '.$plan['from'].$plan['where'].' ORDER BY '.$order.' OFFSET CAST(? AS int) ROWS FETCH NEXT CAST(? AS int) ROWS ONLY';
    $plan['from'].=' JOIN ('.$selection.') ExportSelection ON ExportSelection.selected_id='.$id;
    $plan['countFrom']=$plan['from'];$plan['where']='';$plan['params']=[...$plan['params'],$f['offset'],$f['take']];
    return $plan;
}
function exportLimits(string $format,bool $trade): int
{
    return $format==='print'&&!$trade?5000:200000;
}
function previewExport(PDO $db,string $kind,string $format): array
{
    if(!isset(reportTitles()[$kind])||!in_array($format,['xlsx','csv','print'],true))throw new InvalidArgumentException('Seleccione un reporte y formato válidos.');
    $f=exportSelectionFilters();$base=businessReportPlan($kind,$f);$trade=in_array($kind,['ventas','compras'],true);
    $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');$db->beginTransaction();
    try {
        $matches=querySummary($db,$base);$plan=selectExportPlan($kind,$base,$f);$summary=querySummary($db,$plan);
        if((int)$summary['total']>exportLimits($format,$trade))throw new InvalidArgumentException('Demasiados resultados para ese formato. Reduzca fechas o cantidad.');
        $details=null;
        if($trade) {
            $details=querySummary($db,tradeDetailPlan($kind,$plan))['total'];
            if($format!=='print'&&(int)$details>500000)throw new InvalidArgumentException('El detalle supera 500.000 renglones. Reduzca la selección.');
        }
        $db->commit();
        return ['matches'=>(int)$matches['total'],'records'=>(int)$summary['total'],'detail_rows'=>$details===null?null:(int)$details,
            'amount'=>isset($summary['importe'])?money($summary['importe']):null,'currency'=>'CRC','summary_only'=>$format==='print'&&$trade];
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function downloadForm(PDO $db,string $kind): void
{
    if(!isset(reportTitles()[$kind]))throw new InvalidArgumentException('El reporte solicitado no existe.');
    $f=exportSelectionFilters();$trade=in_array($kind,['ventas','compras'],true);$dated=$trade||in_array($kind,['historial','kardex'],true);
    echo '<header class="operation-heading"><h2 id="download-title">'.e(reportTitles()[$kind]).'</h2><button type="button" class="detail-close" data-download-close aria-label="Cerrar descargas">×</button></header><div class="operation-body">';
    echo '<form action="index.php" method="get" target="_blank" class="download-form"><input type="hidden" name="export" value="'.e($kind).'">';
    filterSelect('format','Formato',['xlsx'=>'Excel: detalle y resúmenes','csv'=>'CSV: tabla para importar','print'=>$trade?'PDF / impresión: resumen del período':'PDF / impresión'],queryText('format',10)?:'xlsx');
    if($dated) {
        echo '<div class="form-grid">';input('Desde','desde',$f['from']?:date('Y-m-01'),'date','min="1900-01-01" max="2100-12-31"');input('Hasta','hasta',$f['to']?:date('Y-m-d'),'date','min="1900-01-01" max="2100-12-31"');echo '</div><p class="help mb-4">Ambas fechas incluidas. Deje las fechas vacías para consultar toda la historia.</p>';
    } else echo '<p class="help mb-4">Cantidades y precios actuales al generar el archivo. No es un saldo histórico.</p>';
    input($trade?($kind==='ventas'?'Buscar cliente por nombre o cédula':'Buscar proveedor por nombre'):'Buscar repuesto por nombre o código','q',$f['q'],'search','maxlength="150"');
    if($trade) {
        $sale=$kind==='ventas';$field=$sale?'f_cliente':'f_proveedor';$lookup=$sale?'clientes':'proveedores';$selected=$sale?$f['client']:$f['supplier'];
        lookupField($sale?'Cliente específico (opcional)':'Proveedor específico (opcional)',$field,$lookup,lookupItems($db,$lookup,'',$selected),'purchase',$selected,false);
        if($sale)filterSelect('vendedor','Vendedor',[''=>'Todos']+array_column(rows($db,"SELECT usuario_id,nombre_usuario FROM RP13_USUARIOS WHERE nombre_usuario<>'sistema' ORDER BY nombre_usuario"),'nombre_usuario','usuario_id'),$f['seller']);
        echo '<div class="form-grid">';input('Desde el número de '.($sale?'venta':'compra'),'referencia_desde',$f['ref_from']?:'','number','min="1" max="2147483647" step="1"');input('Hasta el número de '.($sale?'venta':'compra'),'referencia_hasta',$f['ref_to']?:'','number','min="1" max="2147483647" step="1"');echo '</div><p class="help mb-4">Use solo el número: para V-001234, escriba 1234. Se incluyen todos los repuestos de cada operación seleccionada.</p>';
    }
    lookupField('Repuesto específico'.($kind==='kardex'?'':' (opcional)'),'f_repuesto','repuestos_kardex',lookupItems($db,'repuestos_kardex','',$f['part']),'purchase',$f['part'],$kind==='kardex');
    if(!$trade) {
        filterSelect('categoria','Categoría',[''=>'Todas']+array_column(rows($db,'SELECT id_categoria,nombre FROM RP01_CATEGORIAS ORDER BY nombre'),'nombre','id_categoria'),$f['category']);
        if(!in_array($kind,['precios','reposicion','kardex','historial'],true)) {
            filterSelect('estado','Estado',[''=>'Todos','ACTIVO'=>'Activo','INACTIVO'=>'Inactivo'],$f['state']);
            filterSelect('nivel','Cantidad disponible',[''=>'Todas','bajo'=>'En el mínimo o por debajo','disponible'=>'Sobre el mínimo'],$f['level']);
        }
        if($kind==='historial')filterSelect('tipo','Movimiento',[''=>'Todos','ENTRADA_COMPRA'=>'Entrada por compra','SALIDA_VENTA'=>'Salida por venta'],$f['type']);
    }
    echo '<div class="form-grid">';input('Cantidad a descargar (vacío = todos)','cantidad',$f['take']?:'','number','min="1" max="200000" step="1"');input('Desde el resultado','desde_resultado',$f['offset']+1,'number','min="1" max="2000001" step="1" required');echo '</div>';
    if($dated)filterSelect('orden','Seleccionar a partir de',['recientes'=>'Los más recientes','antiguos'=>'Los más antiguos'],$f['sort']);
    echo '<p class="help mb-4">La cantidad se aplica a operaciones, repuestos o movimientos completos después de filtrar, no a páginas del listado. Las entradas y salidas de un repuesto se presentan cronológicamente.</p><div class="download-preview notification is-light" role="status" aria-live="polite">Comprobando selección…</div><button class="button is-primary" data-download-submit disabled>Generar archivo</button><p class="help mt-3">El archivo usa una lectura consistente. Los conteos pueden variar si se guardan operaciones después de esta vista previa. Importes en CRC.</p></form></div>';
}
