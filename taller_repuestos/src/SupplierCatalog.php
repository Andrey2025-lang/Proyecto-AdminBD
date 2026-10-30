<?php
declare(strict_types=1);

function saveSupplierPart(PDO $db): string
{
    $supplier=integerInput('id_proveedor');$part=integerInput('catalog_part');$state=textInput('estado',20);
    if(!in_array($state,['ACTIVO','INACTIVO','PENDIENTE'],true))throw new InvalidArgumentException('Seleccione un estado válido para el repuesto.');
    if($state==='ACTIVO'&&($_POST['confirmar_oferta']??'')!=='1')throw new InvalidArgumentException('Confirme que este proveedor ofrece el repuesto antes de habilitarlo.');
    $raw=textInput('costo_referencia',20,false);$cost=$raw===''?null:decimal(moneyCents($raw));
    $code=textInput('codigo_proveedor',50,false);$version=textInput('catalog_version',19,false);
    if($version!==''&&!preg_match('/^[0-9]{1,19}$/D',$version))throw new InvalidArgumentException('La versión del registro no es válida. Abra nuevamente el catálogo.');
    $db->beginTransaction();
    try {
        $previous=rows($db,'SELECT CONVERT(bigint,version) version FROM dbo.RP19_PROVEEDOR_REPUESTO WITH(UPDLOCK,HOLDLOCK) WHERE id_proveedor=? AND id_repuesto=?',[$supplier,$part]);
        requireReference($db,'RP02_PROVEEDORES','id_proveedor',$supplier,'El proveedor ya no existe.');
        $product=rows($db,'SELECT estado FROM dbo.RP03_REPUESTOS WHERE id_repuesto=?',[$part]);
        if(!$product)throw new InvalidArgumentException('El repuesto seleccionado ya no existe.');
        if($state==='ACTIVO'&&$product[0]['estado']!=='ACTIVO')throw new InvalidArgumentException('No puede habilitar para compras un repuesto inactivo.');
        if($previous) {
            if(!$version||strtolower($previous[0]['version'])!==strtolower($version))throw new InvalidArgumentException('La relación ya existe o fue modificada por otra persona. Abra Editar para revisar su versión actual.');
            run($db,'UPDATE dbo.RP19_PROVEEDOR_REPUESTO SET estado=?,costo_referencia=?,codigo_proveedor=?,origen=\'MANUAL\',actualizado=SYSDATETIME() WHERE id_proveedor=? AND id_repuesto=?',[$state,$cost,$code?:null,$supplier,$part]);
        } else {
            if($version)throw new InvalidArgumentException('La relación ya no existe. Recargue el catálogo.');
            run($db,'INSERT dbo.RP19_PROVEEDOR_REPUESTO(id_proveedor,id_repuesto,estado,costo_referencia,codigo_proveedor,origen) VALUES(?,?,?,?,?,\'MANUAL\')',[$supplier,$part,$state,$cost,$code?:null]);
        }
        $db->commit();return 'Repuesto del proveedor guardado. Las compras anteriores no se modificaron.';
    } catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function supplierCatalogView(PDO $db,int $supplier,?string $error=null,?string $notice=null): void
{
    $person=rows($db,'SELECT nombre_empresa FROM RP02_PROVEEDORES WHERE id_proveedor=?',[$supplier]);
    if(!$person)throw new InvalidArgumentException('El proveedor solicitado no existe.');
    $query=queryText('cq',100);$page=queryInteger('cp',1);$edit=queryInteger('producto',0);
    if($error)$edit=filter_var($_POST['catalog_part']??0,FILTER_VALIDATE_INT)?:0;
    $params=[$supplier];$where=' WHERE P.id_proveedor=?';
    if($query!==''){$where.=" AND (R.nombre LIKE ? ESCAPE '~' OR R.codigo_barras LIKE ? ESCAPE '~')";$params[]=likeText($query);$params[]=likeText($query,true);}
    $from=' FROM dbo.RP19_PROVEEDOR_REPUESTO P JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=P.id_repuesto';
    $total=(int)rows($db,'SELECT COUNT(*) total'.$from.$where,$params)[0]['total'];$pages=max(1,(int)ceil($total/15));$page=min($page,$pages);
    $list=queryStatement($db,'SELECT P.*,R.nombre,R.codigo_barras,R.estado estado_repuesto'.$from.$where.' ORDER BY R.nombre,R.id_repuesto OFFSET ? ROWS FETCH NEXT 15 ROWS ONLY',[...$params,($page-1)*15])->fetchAll();
    $item=$edit?(rows($db,'SELECT P.*,R.nombre,CONVERT(bigint,P.version) catalog_version'.$from.' WHERE P.id_proveedor=? AND P.id_repuesto=?',[$supplier,$edit])[0]??[]):[];
    $values=$error?$_POST:$item;$version=$values['catalog_version']??'';
    echo '<header class="operation-heading"><div><p class="eyebrow">CATÁLOGO DEL PROVEEDOR</p><h2 id="supplier-catalog-title">'.e($person[0]['nombre_empresa']).'</h2></div><button type="button" class="detail-close" data-supplier-close aria-label="Cerrar catálogo">×</button></header><div class="operation-body">';
    if($error)echo '<div class="notification is-danger is-light" role="alert">'.e($error).'</div>';
    if($notice)echo '<div class="notification is-success is-light" role="status">'.e($notice).'</div>';
    echo '<p class="help mb-4">Solo los repuestos habilitados se pueden incluir en nuevas compras. Los importados del historial quedan pendientes hasta confirmar su oferta. Los habilitados por la carga ficticia son datos de prueba, no disponibilidad verificada de una empresa real.</p>';
    echo '<form class="supplier-catalog-search" method="get"><input type="hidden" name="catalog_supplier" value="'.$supplier.'"><label class="label" for="catalog-search">Buscar en este catálogo</label><div class="field has-addons"><div class="control is-expanded"><input id="catalog-search" class="input" type="search" name="cq" value="'.e($query).'" maxlength="100"></div><div class="control"><button class="button is-light">Buscar</button></div></div></form>';
    echo '<div class="table-container"><table class="table is-fullwidth"><thead><tr><th>Repuesto</th><th>Costo de referencia (CRC)</th><th>Estado</th><th></th></tr></thead><tbody>';
    foreach($list as $row) {
        $url='index.php?'.http_build_query(['catalog_supplier'=>$supplier,'cq'=>$query,'cp'=>$page,'producto'=>$row['id_repuesto']]);
        $label=['ACTIVO'=>'Habilitado','INACTIVO'=>'Deshabilitado','PENDIENTE'=>'Pendiente de confirmar'][$row['estado']];
        echo '<tr><td>'.e($row['nombre']).'<small class="cell-subtitle">'.e($row['codigo_barras']).' · '.e($row['origen']==='HISTORIAL'?'Observado en compras anteriores':($row['origen']==='CARGA_SINTETICA'?'Carga ficticia':'Confirmado manualmente')).'</small></td><td>'.($row['costo_referencia']===null?'Por indicar':money($row['costo_referencia'])).'</td><td>'.e($label).($row['estado_repuesto']!=='ACTIVO'?' · Repuesto inactivo':'').'</td><td><a class="button is-small is-light" data-supplier-fetch href="'.e($url).'">Editar</a></td></tr>';
    }
    if(!$list)emptyRow(4,'No hay repuestos en este catálogo para la búsqueda. Puede registrar uno abajo.');
    echo '</tbody></table></div><div class="supplier-catalog-pager">';
    foreach([-1=>'Anterior',1=>'Siguiente'] as $step=>$label) {
        $next=$page+$step;$enabled=$next>=1&&$next<=$pages;
        if($enabled)echo '<a class="button is-small is-light" data-supplier-fetch href="'.e('index.php?'.http_build_query(['catalog_supplier'=>$supplier,'cq'=>$query,'cp'=>$next])).'">'.e($label).'</a>';
        else echo '<button class="button is-small is-light" disabled>'.e($label).'</button>';
        if($step===-1)echo '<span>Página '.$page.' de '.$pages.' · '.$total.' repuestos</span>';
    }
    echo '</div><section class="detail-section mt-5"><h3>'.($version?'Editar repuesto del proveedor':'Agregar repuesto al proveedor').'</h3><form class="supplier-part-form" method="post" action="index.php?'.e(http_build_query(['catalog_supplier'=>$supplier,'cq'=>$query,'cp'=>$page])).'"><input type="hidden" name="action" value="supplier_part"><input type="hidden" name="id_proveedor" value="'.$supplier.'"><input type="hidden" name="catalog_version" value="'.e($version).'">';csrf();
    if($version)echo '<input type="hidden" name="catalog_part" value="'.$edit.'"><p class="mb-4"><strong>'.e($item['nombre']??'Repuesto seleccionado').'</strong></p>';
    else lookupField('Repuesto','catalog_part','repuestos_kardex',lookupItems($db,'repuestos_kardex','',$edit),'purchase',$edit);
    echo '<div class="form-grid">';
    echo '<div class="field"><label class="label" for="catalog-cost">Costo de referencia (CRC, opcional)</label><input class="input" id="catalog-cost" name="costo_referencia" type="number" min="0" max="99999999.99" step="0.01" value="'.e($values['costo_referencia']??'').'"></div><div class="field"><label class="label" for="catalog-code">Código del proveedor (opcional)</label><input class="input" id="catalog-code" name="codigo_proveedor" maxlength="50" value="'.e($values['codigo_proveedor']??'').'"></div></div>';
    filterSelect('estado','Disponibilidad para nuevas compras',['PENDIENTE'=>'Pendiente de confirmar','ACTIVO'=>'Habilitado','INACTIVO'=>'Deshabilitado'],$values['estado']??'PENDIENTE');
    echo '<label class="checkbox mt-4 mb-4"><input type="checkbox" name="confirmar_oferta" value="1" '.(($error&&($values['confirmar_oferta']??'')==='1')?'checked':'').'> Confirmo que este proveedor ofrece este repuesto (obligatorio al habilitar).</label><p class="help mb-4">El costo es orientativo; cada compra conserva el costo real confirmado. Cambiarlo aquí no modifica compras anteriores.</p><button class="button is-primary">Guardar repuesto del proveedor</button></form></section></div>';
}
