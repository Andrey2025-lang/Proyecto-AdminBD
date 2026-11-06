-- Consultas analiticas de solo lectura para el almacen dw.
-- Cada consulta es independiente. @desde y @hasta son opcionales; @hasta es inclusiva.
USE TallerRepuestosDB;
GO

-- Total de ventas por anio y mes.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT T.anio,T.mes,T.nombre_mes,
    COUNT(DISTINCT F.venta_id_origen) AS ventas,
    SUM(F.subtotal) AS total_ventas,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.base_iva END) AS base_con_desglose,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.monto_iva END) AS iva_con_desglose,
    SUM(CASE WHEN F.tarifa_iva IS NULL THEN 1 ELSE 0 END) AS lineas_sin_desglose_iva
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY T.anio,T.mes,T.nombre_mes
ORDER BY T.anio,T.mes;
GO

-- Ventas por repuesto.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT R.repuesto_id_origen,R.codigo_barras,R.nombre,R.categoria,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe,
    COUNT(DISTINCT F.venta_id_origen) AS ventas
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_REPUESTO R ON R.repuesto_key=F.repuesto_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY R.repuesto_id_origen,R.codigo_barras,R.nombre,R.categoria
ORDER BY unidades DESC,importe DESC,R.nombre;
GO

-- Ventas por cliente.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT C.cliente_id_origen,C.nombre,C.cedula,
    COUNT(DISTINCT F.venta_id_origen) AS ventas,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_CLIENTE C ON C.cliente_key=F.cliente_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY C.cliente_id_origen,C.nombre,C.cedula
ORDER BY importe DESC,C.nombre;
GO

-- Ventas por vendedor.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT V.vendedor_id_origen,V.nombre_usuario,
    COUNT(DISTINCT F.venta_id_origen) AS ventas,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_VENDEDOR V ON V.vendedor_key=F.vendedor_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY V.vendedor_id_origen,V.nombre_usuario
ORDER BY importe DESC,V.nombre_usuario;
GO

-- Unidades vendidas por periodo (mes).
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT T.anio,T.mes,T.nombre_mes,SUM(F.cantidad) AS unidades_vendidas
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY T.anio,T.mes,T.nombre_mes
ORDER BY T.anio,T.mes;
GO

-- Base imponible e IVA por periodo. Los NULL historicos permanecen sin desglose;
-- los SUM ignoran esos NULL y no los convierten en cero ni imputan impuestos.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT T.anio,T.mes,T.nombre_mes,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.base_iva END) AS base_imponible_conocida,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.monto_iva END) AS iva_conocido,
    COUNT_BIG(CASE WHEN F.tarifa_iva IS NOT NULL THEN 1 END) AS lineas_con_desglose,
    COUNT_BIG(CASE WHEN F.tarifa_iva IS NULL THEN 1 END) AS lineas_sin_desglose
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY T.anio,T.mes,T.nombre_mes
ORDER BY T.anio,T.mes;
GO

-- Consulta existente: ventas y unidades por mes y vendedor.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT T.anio,T.mes,T.nombre_mes,U.nombre_usuario AS vendedor,
    COUNT(DISTINCT F.venta_id_origen) AS ventas,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.base_iva END) AS base_con_desglose,
    SUM(CASE WHEN F.tarifa_iva IS NOT NULL THEN F.monto_iva END) AS iva_con_desglose,
    SUM(CASE WHEN F.tarifa_iva IS NULL THEN 1 ELSE 0 END) AS lineas_sin_desglose_iva
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_VENDEDOR U ON U.vendedor_key=F.vendedor_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY T.anio,T.mes,T.nombre_mes,U.nombre_usuario
ORDER BY T.anio,T.mes,U.nombre_usuario;
GO

-- Consulta existente: los 20 repuestos con mas unidades vendidas.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT TOP(20) R.codigo_barras,R.nombre,R.categoria,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_REPUESTO R ON R.repuesto_key=F.repuesto_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY R.codigo_barras,R.nombre,R.categoria
ORDER BY unidades DESC,importe DESC,R.nombre;
GO

-- Consulta existente: clientes por ventas acumuladas.
DECLARE @desde date=NULL,@hasta date=NULL;
SELECT C.cliente_id_origen,C.nombre,C.cedula,
    COUNT(DISTINCT F.venta_id_origen) AS ventas,
    SUM(F.cantidad) AS unidades,SUM(F.subtotal) AS importe
FROM dw.FACT_VENTAS F
JOIN dw.DIM_TIEMPO T ON T.tiempo_key=F.tiempo_key
JOIN dw.DIM_CLIENTE C ON C.cliente_key=F.cliente_key
WHERE (@desde IS NULL OR T.fecha>=@desde)
  AND (@hasta IS NULL OR T.fecha<=@hasta)
GROUP BY C.cliente_id_origen,C.nombre,C.cedula
ORDER BY importe DESC,C.nombre;
GO
