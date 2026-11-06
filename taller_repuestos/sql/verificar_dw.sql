-- Verificacion exclusivamente de lectura del esquema dw y su conciliacion.
-- No carga el ETL ni modifica tablas.
USE TallerRepuestosDB;
GO

-- Objetos esperados: permite identificar faltantes sin consultar tablas inexistentes.
SELECT E.objeto,E.tipo,
    CASE WHEN O.object_id IS NULL THEN N'FALTA' ELSE N'OK' END AS estado
FROM (VALUES
    (N'dw.DIM_TIEMPO',N'U'),(N'dw.DIM_CLIENTE',N'U'),
    (N'dw.DIM_REPUESTO',N'U'),(N'dw.DIM_VENDEDOR',N'U'),
    (N'dw.FACT_VENTAS',N'U'),(N'dw.ETL_HISTORIAL',N'U'),
    (N'dw.sp_CargarDataWarehouse',N'P')
) E(objeto,tipo)
LEFT JOIN sys.objects O ON O.object_id=OBJECT_ID(E.objeto,E.tipo);
GO

-- Claves primarias, unicidad de la clave de origen y relaciones requeridas.
SELECT E.restriccion,E.tipo,
    CASE WHEN (E.tipo='PK' AND EXISTS(
            SELECT 1 FROM sys.key_constraints K
            WHERE K.name=E.restriccion AND K.type='PK' AND K.parent_object_id=OBJECT_ID(E.tabla)))
        OR (E.tipo='UQ' AND EXISTS(
            SELECT 1 FROM sys.key_constraints K
            WHERE K.name=E.restriccion AND K.type='UQ' AND K.parent_object_id=OBJECT_ID(E.tabla)))
        OR (E.tipo='FK' AND EXISTS(
            SELECT 1 FROM sys.foreign_keys K
            WHERE K.name=E.restriccion AND K.parent_object_id=OBJECT_ID(E.tabla)))
        THEN N'OK' ELSE N'FALTA' END AS estado
FROM (VALUES
    (N'PK_dw_DIM_TIEMPO',N'PK',N'dw.DIM_TIEMPO'),(N'PK_dw_DIM_CLIENTE',N'PK',N'dw.DIM_CLIENTE'),
    (N'PK_dw_DIM_REPUESTO',N'PK',N'dw.DIM_REPUESTO'),(N'PK_dw_DIM_VENDEDOR',N'PK',N'dw.DIM_VENDEDOR'),
    (N'PK_dw_FACT_VENTAS',N'PK',N'dw.FACT_VENTAS'),(N'PK_dw_ETL_HISTORIAL',N'PK',N'dw.ETL_HISTORIAL'),
    (N'UQ_dw_DIM_TIEMPO_Fecha',N'UQ',N'dw.DIM_TIEMPO'),
    (N'UQ_dw_DIM_CLIENTE_Origen',N'UQ',N'dw.DIM_CLIENTE'),
    (N'UQ_dw_DIM_REPUESTO_Origen',N'UQ',N'dw.DIM_REPUESTO'),
    (N'UQ_dw_DIM_VENDEDOR_Origen',N'UQ',N'dw.DIM_VENDEDOR'),
    (N'UQ_dw_FACT_VENTAS_Origen',N'UQ',N'dw.FACT_VENTAS'),
    (N'FK_dw_FACT_VENTAS_TIEMPO',N'FK',N'dw.FACT_VENTAS'),
    (N'FK_dw_FACT_VENTAS_CLIENTE',N'FK',N'dw.FACT_VENTAS'),
    (N'FK_dw_FACT_VENTAS_VENDEDOR',N'FK',N'dw.FACT_VENTAS'),
    (N'FK_dw_FACT_VENTAS_REPUESTO',N'FK',N'dw.FACT_VENTAS')
) E(restriccion,tipo,tabla);
GO

-- Conteos; se informa cada tabla faltante en vez de abortar el script.
IF OBJECT_ID(N'dw.DIM_TIEMPO',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''DIM_TIEMPO'' AS tabla,COUNT_BIG(*) AS filas FROM dw.DIM_TIEMPO;';
ELSE SELECT N'DIM_TIEMPO' AS tabla,CAST(NULL AS bigint) AS filas;
IF OBJECT_ID(N'dw.DIM_CLIENTE',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''DIM_CLIENTE'' AS tabla,COUNT_BIG(*) AS filas FROM dw.DIM_CLIENTE;';
ELSE SELECT N'DIM_CLIENTE' AS tabla,CAST(NULL AS bigint) AS filas;
IF OBJECT_ID(N'dw.DIM_REPUESTO',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''DIM_REPUESTO'' AS tabla,COUNT_BIG(*) AS filas FROM dw.DIM_REPUESTO;';
ELSE SELECT N'DIM_REPUESTO' AS tabla,CAST(NULL AS bigint) AS filas;
IF OBJECT_ID(N'dw.DIM_VENDEDOR',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''DIM_VENDEDOR'' AS tabla,COUNT_BIG(*) AS filas FROM dw.DIM_VENDEDOR;';
ELSE SELECT N'DIM_VENDEDOR' AS tabla,CAST(NULL AS bigint) AS filas;
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''FACT_VENTAS'' AS tabla,COUNT_BIG(*) AS filas FROM dw.FACT_VENTAS;';
ELSE SELECT N'FACT_VENTAS' AS tabla,CAST(NULL AS bigint) AS filas;
IF OBJECT_ID(N'dw.ETL_HISTORIAL',N'U') IS NOT NULL EXEC sys.sp_executesql N'SELECT N''ETL_HISTORIAL'' AS tabla,COUNT_BIG(*) AS filas FROM dw.ETL_HISTORIAL;';
ELSE SELECT N'ETL_HISTORIAL' AS tabla,CAST(NULL AS bigint) AS filas;
GO

-- Hechos duplicados según la clave operacional venta-detalle.
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT venta_id_origen,detalle_venta_id_origen,COUNT_BIG(*) AS copias
        FROM dw.FACT_VENTAS
        GROUP BY venta_id_origen,detalle_venta_id_origen
        HAVING COUNT_BIG(*)>1;';
GO

-- Hechos sin dimensión relacionada o con fecha distinta de la dimensión tiempo.
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_TIEMPO',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_CLIENTE',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_REPUESTO',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_VENDEDOR',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT F.venta_id_origen,F.detalle_venta_id_origen,
            CASE WHEN T.tiempo_key IS NULL THEN N''tiempo inexistente''
                 WHEN CONVERT(date,F.fecha_venta)<>T.fecha THEN N''fecha no coincide''
                 WHEN C.cliente_key IS NULL THEN N''cliente inexistente''
                 WHEN R.repuesto_key IS NULL THEN N''repuesto inexistente''
                 WHEN V.vendedor_key IS NULL THEN N''vendedor inexistente'' END AS problema
        FROM dw.FACT_VENTAS F
        LEFT JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
        LEFT JOIN dw.DIM_CLIENTE C ON C.cliente_key=F.cliente_key
        LEFT JOIN dw.DIM_REPUESTO R ON R.repuesto_key=F.repuesto_key
        LEFT JOIN dw.DIM_VENDEDOR V ON V.vendedor_key=F.vendedor_key
        WHERE T.tiempo_key IS NULL OR CONVERT(date,F.fecha_venta)<>T.fecha
            OR C.cliente_key IS NULL OR R.repuesto_key IS NULL OR V.vendedor_key IS NULL;';
GO

-- La dimensión calendario no debe tener huecos entre su primera y ultima fecha.
IF OBJECT_ID(N'dw.DIM_TIEMPO',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        WITH Fechas AS(
            SELECT fecha,LAG(fecha) OVER(ORDER BY fecha) AS fecha_anterior
            FROM dw.DIM_TIEMPO
        )
        SELECT fecha_anterior,fecha AS siguiente_fecha
        FROM Fechas
        WHERE fecha_anterior IS NOT NULL AND DATEDIFF(day,fecha_anterior,fecha)>1;';
GO

-- Cantidades, precio y subtotal del hecho deben ser consistentes.
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT venta_id_origen,detalle_venta_id_origen,cantidad,precio_unitario,subtotal
        FROM dw.FACT_VENTAS
        WHERE cantidad<=0 OR precio_unitario<0 OR subtotal<0
            OR subtotal<>CONVERT(decimal(10,2),cantidad*precio_unitario);';
GO

-- Cantidades y subtotales de las operaciones fuente.
IF OBJECT_ID(N'dbo.RP08_DETALLE_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT id_venta,id_detalle_venta,cantidad,precio_unitario,subtotal
        FROM dbo.RP08_DETALLE_VENTAS
        WHERE cantidad<=0 OR precio_unitario<0 OR subtotal<0
            OR subtotal<>CONVERT(decimal(10,2),cantidad*precio_unitario);';
GO

-- IVA: el historico puede tener tarifa/base/monto NULL; si hay tarifa,
-- base y monto son obligatorios y deben sumar exactamente el subtotal.
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT venta_id_origen,detalle_venta_id_origen,tarifa_iva,base_iva,monto_iva,subtotal,
            CASE
                WHEN tarifa_iva IS NULL AND (base_iva IS NOT NULL OR monto_iva IS NOT NULL)
                    THEN N''Base o monto disponibles sin tarifa registrada''
                WHEN tarifa_iva IS NOT NULL AND base_iva IS NULL
                    THEN N''Base imponible NULL con tarifa registrada''
                WHEN tarifa_iva IS NOT NULL AND monto_iva IS NULL
                    THEN N''Monto IVA NULL con tarifa registrada''
                WHEN tarifa_iva IS NOT NULL AND (tarifa_iva<0 OR tarifa_iva>100)
                    THEN N''Tarifa fuera de rango''
                WHEN tarifa_iva IS NOT NULL AND base_iva+monto_iva<>subtotal
                    THEN N''Base e IVA no suman el subtotal''
            END AS problema
        FROM dw.FACT_VENTAS
        WHERE (tarifa_iva IS NULL AND (base_iva IS NOT NULL OR monto_iva IS NOT NULL))
            OR (tarifa_iva IS NOT NULL AND
                (base_iva IS NULL OR monto_iva IS NULL OR tarifa_iva<0 OR tarifa_iva>100
                    OR base_iva<0 OR monto_iva<0 OR base_iva+monto_iva<>subtotal));';
GO

-- Conciliacion: cada detalle de venta completada debe tener exactamente su hecho
-- con mismas medidas y las mismas referencias naturales.
IF OBJECT_ID(N'dw.FACT_VENTAS',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_CLIENTE',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_REPUESTO',N'U') IS NOT NULL
   AND OBJECT_ID(N'dw.DIM_VENDEDOR',N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.RP07_VENTAS',N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.RP08_DETALLE_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT COALESCE(O.venta_id,F.venta_id_origen) AS venta_id,
            COALESCE(O.detalle_id,F.detalle_venta_id_origen) AS detalle_id,
            CASE WHEN O.detalle_id IS NULL THEN N''Hecho sin detalle operacional completado''
                 WHEN F.detalle_venta_id_origen IS NULL THEN N''Detalle completado sin hecho''
                 WHEN O.cantidad<>F.cantidad OR O.precio_unitario<>F.precio_unitario
                    OR O.subtotal<>F.subtotal THEN N''Cantidad, precio o subtotal no coincide''
                 WHEN O.cliente_id<>C.cliente_id_origen OR O.vendedor_id<>V.vendedor_id_origen
                    OR O.repuesto_id<>R.repuesto_id_origen THEN N''Dimensiones no coinciden con la operacion''
                 WHEN EXISTS(SELECT O.tarifa_iva,O.base_iva,O.monto_iva
                    EXCEPT SELECT F.tarifa_iva,F.base_iva,F.monto_iva)
                    THEN N''Desglose IVA no coincide con la operacion'' END AS problema
        FROM (
            SELECT H.id_venta AS venta_id,D.id_detalle_venta AS detalle_id,H.id_cliente AS cliente_id,
                H.id_vendedor AS vendedor_id,D.id_repuesto AS repuesto_id,D.cantidad,D.precio_unitario,
                D.subtotal,D.tarifa_iva,D.base_iva,D.monto_iva
            FROM dbo.RP07_VENTAS H
            JOIN dbo.RP08_DETALLE_VENTAS D ON D.id_venta=H.id_venta
            WHERE H.estado=''COMPLETADA''
        ) O
        FULL OUTER JOIN dw.FACT_VENTAS F
            ON F.venta_id_origen=O.venta_id AND F.detalle_venta_id_origen=O.detalle_id
        LEFT JOIN dw.DIM_CLIENTE C ON C.cliente_key=F.cliente_key
        LEFT JOIN dw.DIM_VENDEDOR V ON V.vendedor_key=F.vendedor_key
        LEFT JOIN dw.DIM_REPUESTO R ON R.repuesto_key=F.repuesto_key
        WHERE O.detalle_id IS NULL OR F.detalle_venta_id_origen IS NULL
            OR O.cantidad<>F.cantidad OR O.precio_unitario<>F.precio_unitario OR O.subtotal<>F.subtotal
            OR O.cliente_id<>C.cliente_id_origen OR O.vendedor_id<>V.vendedor_id_origen
            OR O.repuesto_id<>R.repuesto_id_origen
            OR EXISTS(SELECT O.tarifa_iva,O.base_iva,O.monto_iva EXCEPT SELECT F.tarifa_iva,F.base_iva,F.monto_iva);';
GO

-- Conciliacion operacional de encabezados contra sus lineas e IVA registrado.
IF OBJECT_ID(N'dbo.RP07_VENTAS',N'U') IS NOT NULL
   AND OBJECT_ID(N'dbo.RP08_DETALLE_VENTAS',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT H.id_venta,H.total,SUM(D.subtotal) AS suma_detalles,
            H.base_iva,SUM(D.base_iva) AS suma_base_iva,
            H.monto_iva,SUM(D.monto_iva) AS suma_monto_iva
        FROM dbo.RP07_VENTAS H
        LEFT JOIN dbo.RP08_DETALLE_VENTAS D ON D.id_venta=H.id_venta
        WHERE H.estado=''COMPLETADA''
        GROUP BY H.id_venta,H.total,H.base_iva,H.monto_iva
        HAVING COUNT_BIG(D.id_detalle_venta)=0 OR H.total<>COALESCE(SUM(D.subtotal),0)
            OR (H.base_iva IS NULL AND COUNT_BIG(D.base_iva)>0)
            OR (H.base_iva IS NOT NULL AND
                (COUNT_BIG(D.base_iva)<>COUNT_BIG(D.id_detalle_venta) OR H.base_iva<>SUM(D.base_iva)))
            OR (H.monto_iva IS NULL AND COUNT_BIG(D.monto_iva)>0)
            OR (H.monto_iva IS NOT NULL AND
                (COUNT_BIG(D.monto_iva)<>COUNT_BIG(D.id_detalle_venta) OR H.monto_iva<>SUM(D.monto_iva)));';
GO

-- Estado de las ejecuciones ETL: resultados recientes y conteo por estado.
IF OBJECT_ID(N'dw.ETL_HISTORIAL',N'U') IS NOT NULL
    EXEC sys.sp_executesql N'
        SELECT estado,COUNT_BIG(*) AS ejecuciones
        FROM dw.ETL_HISTORIAL
        GROUP BY estado
        ORDER BY estado;
        SELECT TOP(20) etl_id,inicio,fin,estado,filas_extraidas,hechos_insertados,
            hechos_actualizados,error
        FROM dw.ETL_HISTORIAL
        ORDER BY etl_id DESC;';
ELSE SELECT N'dw.ETL_HISTORIAL no existe' AS estado;
GO
