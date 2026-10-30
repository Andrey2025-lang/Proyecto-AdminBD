-- Verificaciones de solo lectura de la carga comercial con cantidades predeterminadas.
USE TallerRepuestosDB;
GO
SET NOCOUNT ON;
IF NOT EXISTS(SELECT 1 FROM dbo.RP15_CARGAS_DATOS WHERE lote='historia_comercial_v1')
 THROW 51200,'No se encontro la marca del lote historico.',1;
IF (SELECT COUNT(*) FROM dbo.RP03_REPUESTOS WHERE codigo_barras LIKE '200500%')<>2000
 THROW 51201,'Cantidad inesperada de repuestos del lote.',1;
IF (SELECT COUNT(*) FROM dbo.RP05_CLIENTES WHERE cedula BETWEEN '900700001' AND '900705000')<>5000
 THROW 51202,'Cantidad inesperada de clientes del lote.',1;
IF EXISTS(SELECT 1 FROM(
 SELECT H.id_repuesto,SUM(CASE WHEN H.tipo_movimiento='ENTRADA_COMPRA' THEN H.cantidad ELSE -H.cantidad END)
 OVER(PARTITION BY H.id_repuesto ORDER BY H.fecha,H.id_historial ROWS UNBOUNDED PRECEDING) saldo
 FROM dbo.RP11_INVENTARIO_HISTORIAL H JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto
 WHERE R.codigo_barras LIKE '200500%'
) X WHERE saldo<0) THROW 51203,'Se detectaron saldos historicos negativos.',1;
IF EXISTS(SELECT 1 FROM dbo.RP03_REPUESTOS R CROSS APPLY(
 SELECT SUM(CASE WHEN tipo_movimiento='ENTRADA_COMPRA' THEN cantidad ELSE -cantidad END) saldo
 FROM dbo.RP11_INVENTARIO_HISTORIAL WHERE id_repuesto=R.id_repuesto
) S WHERE R.codigo_barras LIKE '200500%' AND R.stock_actual<>S.saldo)
 THROW 51204,'El inventario no coincide con el historial.',1;
IF EXISTS(SELECT 1 FROM dbo.RP07_VENTAS V CROSS APPLY(SELECT SUM(subtotal) total FROM dbo.RP08_DETALLE_VENTAS WHERE id_venta=V.id_venta) D WHERE V.total<>D.total)
 THROW 51205,'Un total de venta no coincide con sus detalles.',1;
IF EXISTS(SELECT 1 FROM dbo.RP09_COMPRAS C CROSS APPLY(SELECT SUM(subtotal) total FROM dbo.RP10_DETALLE_COMPRAS WHERE id_compra=C.id_compra) D WHERE C.total<>D.total)
 THROW 51206,'Un total de compra no coincide con sus detalles.',1;
IF EXISTS(SELECT 1 FROM dbo.RP08_DETALLE_VENTAS WHERE subtotal<>cantidad*precio_unitario)
 OR EXISTS(SELECT 1 FROM dbo.RP10_DETALLE_COMPRAS WHERE subtotal<>cantidad*precio_unitario)
 THROW 51207,'Un subtotal no coincide con precio y cantidad.',1;
SELECT 'OK: cantidades, saldos cronologicos, inventario y totales verificados.' resultado;
SELECT lote,fecha_desde,fecha_hasta,resumen FROM dbo.RP15_CARGAS_DATOS;
GO
