USE TallerRepuestosDB;
GO
SET NOCOUNT ON;
DECLARE @lote varchar(100)='historia_64_diarias_v2',@desde date,@hasta date;
SELECT @desde=fecha_desde,@hasta=fecha_hasta FROM dbo.RP15_CARGAS_DATOS WHERE lote=@lote;
IF @desde IS NULL THROW 51400,'No se encontro el lote nuevo.',1;
IF (SELECT COUNT(*) FROM dbo.RP18_ENTIDADES_CARGA WHERE lote=@lote AND tipo='venta')<>(SELECT CONVERT(int,JSON_VALUE(resumen,'$.ventas_nuevas')) FROM dbo.RP15_CARGAS_DATOS WHERE lote=@lote) THROW 51401,'Cantidad de ventas distinta a la registrada al cargar.',1;
IF EXISTS(SELECT 1 FROM dbo.RP18_ENTIDADES_CARGA L LEFT JOIN dbo.RP07_VENTAS V ON V.id_venta=L.id WHERE L.lote=@lote AND L.tipo='venta' AND V.id_venta IS NULL)
 THROW 51402,'Falta un encabezado de venta.',1;
IF EXISTS(SELECT 1 FROM dbo.RP07_VENTAS V JOIN dbo.RP18_ENTIDADES_CARGA L ON L.id=V.id_venta AND L.tipo='venta' AND L.lote=@lote OUTER APPLY(SELECT SUM(subtotal) total,COUNT(*) lineas FROM dbo.RP08_DETALLE_VENTAS D WHERE D.id_venta=V.id_venta) D WHERE D.lineas=0 OR D.total<>V.total)
 THROW 51403,'Un detalle no coincide con el encabezado.',1;
IF EXISTS(SELECT id_cliente,fecha FROM dbo.RP07_VENTAS GROUP BY id_cliente,fecha HAVING COUNT(*)>1) THROW 51404,'Operaciones con fecha y cliente duplicados.',1;
IF EXISTS(SELECT 1 FROM dbo.RP03_REPUESTOS R CROSS APPLY(SELECT SUM(CASE WHEN tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,cantidad) ELSE -CONVERT(bigint,cantidad) END) saldo FROM dbo.RP11_INVENTARIO_HISTORIAL H WHERE H.id_repuesto=R.id_repuesto) S WHERE R.codigo_barras LIKE '200500%' AND R.stock_actual<>S.saldo)
 THROW 51405,'Inventario e historial no coinciden.',1;
IF EXISTS(SELECT 1 FROM(SELECT H.id_repuesto,SUM(CASE WHEN tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,cantidad) ELSE -CONVERT(bigint,cantidad) END) OVER(PARTITION BY H.id_repuesto ORDER BY H.fecha,H.id_historial ROWS UNBOUNDED PRECEDING) saldo FROM dbo.RP11_INVENTARIO_HISTORIAL H JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=H.id_repuesto WHERE R.codigo_barras LIKE '200500%') S WHERE saldo<0)
 THROW 51406,'Saldo cronologico negativo.',1;
IF EXISTS(SELECT 1 FROM dbo.RP07_VENTAS V JOIN dbo.RP18_ENTIDADES_CARGA L ON L.tipo='venta' AND L.id=V.id_venta AND L.lote=@lote WHERE DATEPART(hour,V.fecha)<9 OR DATEPART(hour,V.fecha)>=17 OR DATEDIFF(day,'19000101',V.fecha)%7=6)
 THROW 51407,'Ventas nuevas fuera del horario o en domingo.',1;
SELECT COUNT(*) ventas_nuevas,CONVERT(decimal(18,2),AVG(V.total)) promedio_crc,MIN(V.fecha) primera,MAX(V.fecha) ultima
FROM dbo.RP07_VENTAS V JOIN dbo.RP18_ENTIDADES_CARGA L ON L.id=V.id_venta AND L.tipo='venta' AND L.lote=@lote;
SELECT TOP(1) CONVERT(decimal(18,2),PERCENTILE_CONT(0.5) WITHIN GROUP(ORDER BY total) OVER()) mediana_crc FROM dbo.RP07_VENTAS V JOIN dbo.RP18_ENTIDADES_CARGA L ON L.id=V.id_venta AND L.tipo='venta' AND L.lote=@lote;
SELECT 'OK: operaciones relacionadas, totales, inventario y cronologia verificados.' resultado;
GO
