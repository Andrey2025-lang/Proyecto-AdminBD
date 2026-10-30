<?php
declare(strict_types=1);

final class OperationNotFound extends RuntimeException {}

function operationReference(string $kind, int $id): string
{
    return ($kind==='ventas'?'V-':'C-').str_pad((string)$id,6,'0',STR_PAD_LEFT);
}
function operationDocument(PDO $db, string $kind, int $id): array
{
    if (!in_array($kind,['ventas','compras'],true) || $id<1) throw new InvalidArgumentException('Seleccione una operación válida.');
    $owned=!$db->inTransaction();
    if ($owned) { $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'); $db->beginTransaction(); }
    try {
        $sale=$kind==='ventas';
        $header=rows($db,$sale
            ? 'SELECT V.id_venta id,V.fecha,V.total,V.base_iva,V.monto_iva,V.estado,C.nombre persona,C.cedula identificacion,C.telefono,C.email,U.nombre_usuario responsable FROM RP07_VENTAS V JOIN RP05_CLIENTES C ON C.id_cliente=V.id_cliente JOIN RP13_USUARIOS U ON U.usuario_id=V.id_vendedor WHERE V.id_venta=?'
            : "SELECT C.id_compra id,C.fecha,C.total,C.base_iva,C.monto_iva,C.estado,P.nombre_empresa persona,P.contacto identificacion,P.telefono,P.email,CAST(NULL AS varchar(50)) responsable FROM RP09_COMPRAS C JOIN RP02_PROVEEDORES P ON P.id_proveedor=C.id_proveedor WHERE C.id_compra=?",[$id]);
        if (!$header) throw new OperationNotFound('La operación solicitada no existe.');
        $table=$sale?'RP08_DETALLE_VENTAS':'RP10_DETALLE_COMPRAS'; $key=$sale?'id_venta':'id_compra';
        $lineKey=$sale?'id_detalle_venta':'id_detalle_compra';
        $lines=rows($db,"SELECT D.id_repuesto,R.codigo_barras,R.nombre,D.cantidad,D.precio_unitario,D.subtotal,D.tarifa_iva,D.base_iva,D.monto_iva FROM $table D JOIN RP03_REPUESTOS R ON R.id_repuesto=D.id_repuesto WHERE D.$key=? ORDER BY D.$lineKey",[$id]);
        $amount=0; $units=0; $valid=(bool)$lines;
        foreach($lines as $line) {
            $amount+=(float)$line['subtotal']; $units+=(int)$line['cantidad'];
            if ((float)$line['precio_unitario']<0 || abs((float)$line['subtotal']-(int)$line['cantidad']*(float)$line['precio_unitario'])>0.005) $valid=false;
        }
        $valid=$valid && abs($amount-(float)$header[0]['total'])<0.005;
        if($header[0]['base_iva']!==null) {
            $base=0;$tax=0;
            foreach($lines as $line) {
                if($line['base_iva']===null||$line['monto_iva']===null||$line['tarifa_iva']===null||abs((float)$line['base_iva']+(float)$line['monto_iva']-(float)$line['subtotal'])>0.005)$valid=false;
                $base+=(float)$line['base_iva'];$tax+=(float)$line['monto_iva'];
            }
            if(abs($base-(float)$header[0]['base_iva'])>0.005||abs($tax-(float)$header[0]['monto_iva'])>0.005||abs($base+$tax-$amount)>0.005)$valid=false;
        } elseif($header[0]['monto_iva']!==null||array_filter($lines,static fn($r)=>$r['tarifa_iva']!==null))$valid=false;
        if ($owned) $db->commit();
        return ['kind'=>$kind,'reference'=>operationReference($kind,$id),'header'=>$header[0],'lines'=>$lines,'units'=>$units,'sum'=>$amount,'consistent'=>$valid];
    } catch(Throwable $e) { if($owned && $db->inTransaction()) $db->rollBack(); throw $e; }
}
function renderOperationDetail(array $document): void
{
    $h=$document['header']; $sale=$document['kind']==='ventas';
    echo '<header class="operation-heading"><div><p class="eyebrow">'.($sale?'VENTA DE REPUESTOS':'RECEPCIÓN DE COMPRA').'</p><h2 id="operation-title">Detalle de '.($sale?'venta':'compra').' <span>'.e($document['reference']).'</span></h2></div><button type="button" class="detail-close" data-detail-close aria-label="Cerrar detalle">×</button></header><div class="operation-body">';
    if(!$document['consistent']) echo '<div class="notification is-warning is-light">Los detalles no coinciden con el total registrado. Revise la operación antes de emitir un comprobante.</div>';
    echo '<section class="detail-section"><h3>Información de la operación</h3><dl class="detail-data-grid">';
    foreach(['Referencia'=>$document['reference'],'Fecha'=>dateLabel($h['fecha']),'Estado'=>statusLabel($h['estado']),($sale?'Vendedor':'Registro')=>$sale?$h['responsable']:'Compra recibida'] as $label=>$value) echo '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
    echo '</dl></section><section class="detail-section"><h3>'.($sale?'Cliente':'Proveedor').'</h3><dl class="detail-data-grid">';
    foreach(['Nombre'=>$h['persona'],($sale?'Identificación':'Contacto')=>$h['identificacion'],'Teléfono'=>phoneLabel($h['telefono']),'Correo'=>$h['email']?:'No registrado'] as $label=>$value) echo '<div><dt>'.e($label).'</dt><dd>'.e($value).'</dd></div>';
    echo '</dl></section><section class="detail-section"><div class="detail-section-title"><h3>Detalle de repuestos</h3><span>'.count($document['lines']).' renglones · '.number_format($document['units']).' unidades en total</span></div>';
    printReportTableHtml($document['lines']);
    if($h['base_iva']!==null) {
        echo '<dl class="detail-data-grid"><div><dt>Monto antes del IVA</dt><dd>'.money($h['base_iva']).'</dd></div><div><dt>IVA incluido</dt><dd>'.money($h['monto_iva']).'</dd></div></dl><p class="help mt-3">Los precios incluyen IVA. El impuesto mostrado ya está dentro del total, no se cobra de nuevo.</p>';
    } else echo '<p class="help">Desglose de IVA no registrado. No se infiere un impuesto de los datos históricos ni del comprobante del proveedor.</p>';
    echo '<div class="detail-total"><span>Total registrado</span><strong>'.money($h['total']).'</strong></div></section><p class="help">Precios y cantidades registrados al confirmar. Comprobante interno, no factura fiscal.</p></div><footer class="operation-footer"><button type="button" class="button is-light" data-detail-close>Volver al listado</button><div>';
    if($document['consistent']) {
        $url='index.php?receipt='.$document['kind'].'&id='.(int)$h['id'];
        echo '<a class="button is-light" href="'.e($url.'&format=xlsx').'">Descargar Excel</a><a class="button is-primary" href="'.e($url.'&format=print').'" target="_blank" rel="noopener">Imprimir / Guardar PDF</a>';
    }
    echo '</div></footer>';
}
function printReportTableHtml(array $lines): void
{
    echo '<div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Código / repuesto</th><th>Cantidad</th><th>Precio unitario</th><th>Subtotal</th></tr></thead><tbody>';
    foreach($lines as $line) echo '<tr><td><strong>'.e($line['nombre']).'</strong><small class="cell-subtitle">'.e($line['codigo_barras']).($line['tarifa_iva']!==null?' · IVA incluido: '.e(number_format((float)$line['tarifa_iva'],2)).'%':'').'</small></td><td>'.(int)$line['cantidad'].'</td><td>'.money($line['precio_unitario']).'</td><td class="amount">'.money($line['subtotal']).'</td></tr>';
    if(!$lines) echo '<tr><td colspan="4">No hay repuestos registrados.</td></tr>';
    echo '</tbody></table></div>';
}
function exportOperation(PDO $db, string $kind, int $id, string $format): never
{
    if (!in_array($format,['xlsx','print'],true)) throw new InvalidArgumentException('Seleccione Excel o documento imprimible.');
    $db->exec('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE'); $db->beginTransaction(); $file=null;
    try {
        $doc=operationDocument($db,$kind,$id); $h=$doc['header']; $sale=$kind==='ventas';
        if(!$doc['consistent']) throw new InvalidArgumentException('No se puede emitir el comprobante: el detalle y el total no coinciden.');
        $generated=rows($db,'SELECT CONVERT(varchar(19),SYSDATETIME(),120) fecha')[0]['fecha'];
        $title='Comprobante interno de '.($sale?'venta ':'compra ').$doc['reference'];
        $caption='Colones costarricenses (CRC, ₡) · Fecha: '.dateLabel($h['fecha']).' · '.($sale?'Cliente: ':'Proveedor: ').$h['persona'].' · Estado: '.statusLabel($h['estado']);
        $plan=['columns'=>[['codigo_barras','Código','text'],['nombre','Repuesto','text'],['cantidad','Cantidad','int'],['precio_unitario','Precio unitario','money'],['subtotal','Subtotal','money']]];
        $summary=['total'=>count($doc['lines']),'importe'=>$h['total'],'receipt'=>true,'units'=>$doc['units'],'record_label'=>'Renglones de repuestos','amount_label'=>'Total del comprobante','detail_label'=>'Detalle de repuestos'];
        $details=[['campo'=>'Referencia','dato'=>$doc['reference']],['campo'=>'Fecha','dato'=>dateLabel($h['fecha'])],['campo'=>$sale?'Cliente':'Proveedor','dato'=>$h['persona']],['campo'=>$sale?'Identificación':'Contacto','dato'=>$h['identificacion']],['campo'=>'Teléfono','dato'=>phoneLabel($h['telefono'])],['campo'=>'Estado','dato'=>statusLabel($h['estado'])]];
        if($sale) $details[]=['campo'=>'Vendedor responsable','dato'=>$h['responsable']];
        $details[]=['campo'=>'IVA','dato'=>$h['base_iva']===null?'Desglose de IVA no registrado':'Los precios incluyen IVA; no se suma de nuevo al total.'];
        if($h['base_iva']!==null) {
            $details[]=['campo'=>'Monto antes del IVA','dato'=>money($h['base_iva'])];
            $details[]=['campo'=>'IVA incluido','dato'=>money($h['monto_iva'])];
            array_splice($plan['columns'],4,0,[['tarifa_iva','IVA incluido (%)','text'],['base_iva','Monto antes del IVA','money'],['monto_iva','IVA incluido','money']]);
        }
        $blocks=[['title'=>'Datos de la operación','columns'=>[['campo','Campo','text'],['dato','Información','text']],'rows'=>$details]];
        $file=tmpfile(); if($file===false) throw new RuntimeException('No se pudo preparar el archivo.');
        if($format==='xlsx') writeWorkbook($file,xlsxSheet($doc['lines'],$plan,$summary,$title,$caption,$generated),$plan,$summary,xlsxOverview($summary,$blocks,$title,$caption,$generated),[],'Repuestos');
        else writePrintReport($file,$doc['lines'],$plan,$summary,$blocks,$title,$caption,$generated);
        $db->commit(); $size=ftell($file); rewind($file);
        header('Content-Type: '.($format==='xlsx'?'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':'text/html; charset=UTF-8'));
        if($format==='xlsx') header('Content-Disposition: attachment; filename="'.$doc['reference'].'.xlsx"');
        header('Content-Length: '.$size); fpassthru($file); fclose($file); exit;
    } catch(Throwable $e) { if($db->inTransaction()) $db->rollBack(); if(is_resource($file)) fclose($file); throw $e; }
}
