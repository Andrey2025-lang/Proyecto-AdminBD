<?php
declare(strict_types=1);

function shortDateLabel(string $value): string
{
    return date('d/m/Y',strtotime($value));
}

function phoneLabel($value): string
{
    $text=trim((string)($value??''));
    $digits=preg_replace('/[\s().-]/','',$text);
    if(preg_match('/^\d{8}$/D',$digits)) return substr($digits,0,4).'-'.substr($digits,4);
    if(preg_match('/^(?:\+?506)(\d{8})$/D',$digits,$m)) return '+506 '.substr($m[1],0,4).'-'.substr($m[1],4);
    return $text;
}
function statusLabel($value): string
{
    return ['ACTIVO'=>'Activo','INACTIVO'=>'Inactivo','COMPLETADA'=>'Completada','RECIBIDO'=>'Recibida',
        'ENTRADA_COMPRA'=>'Entrada por compra','SALIDA_VENTA'=>'Salida por venta',
        'INSERT'=>'Registro nuevo','UPDATE'=>'Actualización','DELETE'=>'Eliminación','Admin'=>'Administrador'][$value??'']??(string)$value;
}
function tableLabel($value): string
{
    return ['RP01_CATEGORIAS'=>'Categorías','RP02_PROVEEDORES'=>'Proveedores','RP03_REPUESTOS'=>'Repuestos',
        'RP05_CLIENTES'=>'Clientes','RP07_VENTAS'=>'Ventas','RP08_DETALLE_VENTAS'=>'Detalle de ventas',
        'RP09_COMPRAS'=>'Compras','RP10_DETALLE_COMPRAS'=>'Detalle de compras','RP11_INVENTARIO_HISTORIAL'=>'Inventario',
        'RP13_USUARIOS'=>'Usuarios'][$value??'']??(string)$value;
}
function reportValue(string $key, $value)
{
    return match($key) {
        'estado','tipo_movimiento','operacion','rol'=>statusLabel($value),
        'telefono'=>phoneLabel($value),
        'periodo'=>preg_match('/^\d{4}-\d{2}$/D',(string)$value)?substr($value,5,2).'/'.substr($value,0,4):$value,
        'tabla_afectada'=>tableLabel($value),
        default=>$value,
    };
}
