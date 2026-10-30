-- Datos ficticios persistentes para pruebas, no ventas reales ni cotizaciones.
-- Reutiliza clientes/productos/proveedores de 002; no los copia ni reescribe ventas anteriores.
-- sqlcmd ... -v Aplicar=0 previsualiza; -v Aplicar=1 aplica una sola vez.
USE TallerRepuestosDB;
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET NUMERIC_ROUNDABORT OFF;
DECLARE @Aplicar bit=$(Aplicar),@Lote varchar(100)='historia_64_diarias_v2';
DECLARE @Hasta date=DATEADD(day,-1,CONVERT(date,GETDATE())),@Desde date;
SET @Desde=DATEADD(year,-5,DATEADD(day,1,@Hasta));
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @lock int;
 EXEC @lock=sys.sp_getapplock @Resource='carga_historia_comercial',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=10000;
 IF @lock<0 THROW 51300,'Otra carga esta en ejecucion.',1;
 IF OBJECT_ID('dbo.RP15_CARGAS_DATOS','U') IS NULL THROW 51301,'Se requiere la carga 002.',1;
 IF EXISTS(SELECT 1 FROM dbo.RP15_CARGAS_DATOS WHERE lote=@Lote)
 BEGIN
  SELECT 'Lote ya aplicado: no se insertaron duplicados.' resultado,resumen FROM dbo.RP15_CARGAS_DATOS WHERE lote=@Lote;
  COMMIT;RETURN;
 END;
 SELECT R.*,CONVERT(int,SUBSTRING(codigo_barras,7,6)) n INTO #P FROM dbo.RP03_REPUESTOS R WHERE codigo_barras LIKE '200500%' AND estado='ACTIVO';
 IF (SELECT COUNT(*) FROM #P)<>2000 THROW 51302,'Se requieren los 2000 productos originales activos de 002.',1;
 CREATE UNIQUE CLUSTERED INDEX IX_P ON #P(n);
 SELECT ROW_NUMBER() OVER(ORDER BY cedula) n,id_cliente INTO #Clientes FROM dbo.RP05_CLIENTES WHERE cedula BETWEEN '900700001' AND '900705000';
 IF (SELECT COUNT(*) FROM #Clientes)<>5000 THROW 51303,'Se requieren los clientes ficticios originales de 002.',1;
 SELECT ROW_NUMBER() OVER(ORDER BY usuario_id) n,usuario_id INTO #Vendedores FROM dbo.RP13_USUARIOS WHERE rol='Vendedor' AND estado='ACTIVO' AND nombre_usuario<>'sistema';
 SELECT ROW_NUMBER() OVER(ORDER BY id_proveedor) n,id_proveedor INTO #Proveedores FROM dbo.RP02_PROVEEDORES WHERE email LIKE 'ventas.%@example.com';
 DECLARE @V int=(SELECT COUNT(*) FROM #Vendedores),@Prov int=(SELECT COUNT(*) FROM #Proveedores);
 IF @V=0 OR @Prov<10 THROW 51304,'Faltan vendedores o proveedores de la carga inicial.',1;
 CREATE TABLE #N(n int PRIMARY KEY);
 ;WITH D(n) AS(SELECT n FROM(VALUES(0),(1),(2),(3),(4),(5),(6),(7),(8),(9)) X(n))
 INSERT #N SELECT TOP(200000) ROW_NUMBER() OVER(ORDER BY A.n,B.n,C.n,D.n,E.n,F.n) FROM D A CROSS JOIN D B CROSS JOIN D C CROSS JOIN D D CROSS JOIN D E CROSS JOIN D F;
 SELECT DATEADD(day,n-1,@Desde) fecha INTO #Dias FROM #N WHERE n<=DATEDIFF(day,@Desde,@Hasta)+1;
 SELECT CONVERT(date,fecha) fecha,COUNT(*) ventas INTO #Existentes FROM dbo.RP07_VENTAS WHERE fecha>=@Desde AND fecha<DATEADD(day,1,@Hasta) GROUP BY CONVERT(date,fecha);
 SELECT D.fecha,CASE WHEN DATEDIFF(day,'19000101',D.fecha)%7=6 THEN 0
  WHEN DATEDIFF(day,'19000101',D.fecha)%7=5 THEN 34+((CHECKSUM(CONCAT('sabado',D.fecha))&2147483647)%17)-8
  ELSE 70+((CHECKSUM(CONCAT('dia',D.fecha))&2147483647)%29)-14+CASE WHEN MONTH(D.fecha) IN(11,12) THEN 4 WHEN MONTH(D.fecha) IN(1,2) THEN -3 ELSE 0 END END objetivo,
  COALESCE(E.ventas,0) existentes INTO #Objetivos FROM #Dias D LEFT JOIN #Existentes E ON E.fecha=D.fecha;
 SELECT CONVERT(int,ROW_NUMBER() OVER(ORDER BY O.fecha,N.n)) n,O.fecha,N.n secuencia,O.objetivo-O.existentes cantidad_dia,
  CHECKSUM(CONCAT('perfil',O.fecha,N.n))&2147483647 perfil_seed,
  CHECKSUM(CONCAT('cliente',O.fecha,N.n))&2147483647 cliente_seed,
  CHECKSUM(CONCAT('modelo',O.fecha,N.n))&2147483647 modelo_seed,
  CHECKSUM(CONCAT('marca',O.fecha,N.n))&2147483647 marca_seed
 INTO #Base FROM #Objetivos O JOIN #N N ON N.n<=O.objetivo-O.existentes;
 DECLARE @Nuevas int=(SELECT COUNT(*) FROM #Base);
 IF @Nuevas NOT BETWEEN 50000 AND 130000 THROW 51305,'Volumen inesperado. Revise fechas y registros existentes.',1;
 SELECT B.n,DATEADD(millisecond,100+B.modelo_seed%800,DATEADD(second,(B.secuencia-1)*CASE WHEN DATEDIFF(day,'19000101',B.fecha)%7=5 THEN 14400 ELSE 28800 END/B.cantidad_dia,DATEADD(hour,9,CONVERT(datetime2,B.fecha)))) fecha,
  C.id_cliente,V.usuario_id,B.perfil_seed%100 perfil,1+B.modelo_seed%25 modelo,
  CASE WHEN B.marca_seed%100<60 THEN 1 WHEN B.marca_seed%100<85 THEN 2 WHEN B.marca_seed%100<96 THEN 3 ELSE 4 END marca
 INTO #S FROM #Base B JOIN #Clientes C ON C.n=1+B.cliente_seed%CASE WHEN B.perfil_seed%10<6 THEN 500 ELSE 5000 END
 JOIN #Vendedores V ON V.n=1+(B.perfil_seed/100)%@V;
 CREATE UNIQUE CLUSTERED INDEX IX_S ON #S(n);
 -- Canastas de mantenimiento/reparacion: misma compatibilidad ilustrativa dentro de la venta.
 SELECT S.n venta,P.id_repuesto,P.n producto,T.tipo,
  CASE WHEN T.tipo=12 THEN 4 WHEN T.tipo=7 THEN 2 WHEN T.tipo IN(8,9) THEN 1+S.n%2 ELSE 1 END * CASE WHEN S.n%53=0 AND T.tipo<=4 THEN 3 ELSE 1 END cantidad,
  CONVERT(decimal(10,2),ROUND(P.precio_venta*(0.88+0.12*DATEDIFF(day,@Desde,S.fecha)/NULLIF(CONVERT(decimal(12,4),DATEDIFF(day,@Desde,@Hasta)),0))/50,0)*50) precio,
  CASE WHEN DATEADD(day,-DATEDIFF(day,'19000101',CONVERT(date,S.fecha))%7,CONVERT(date,S.fecha))<@Desde THEN @Desde ELSE DATEADD(day,-DATEDIFF(day,'19000101',CONVERT(date,S.fecha))%7,CONVERT(date,S.fecha)) END periodo
 INTO #D FROM #S S CROSS JOIN(VALUES(1),(2)) Linea(n)
 CROSS APPLY(SELECT CASE
  WHEN S.perfil<35 AND Linea.n=1 THEN 1
  WHEN S.perfil BETWEEN 35 AND 49 THEN CASE Linea.n WHEN 1 THEN 1 ELSE 2 END
  WHEN S.perfil BETWEEN 50 AND 59 THEN CASE Linea.n WHEN 1 THEN 2 ELSE 4 END
  WHEN S.perfil BETWEEN 60 AND 67 THEN CASE WHEN Linea.n=1 THEN 12 WHEN S.perfil>=64 THEN 1 END
  WHEN S.perfil BETWEEN 68 AND 77 THEN CASE WHEN Linea.n=1 THEN 5+S.n%2 WHEN S.perfil>=75 THEN 7 END
  WHEN S.perfil BETWEEN 78 AND 86 AND Linea.n=1 THEN 8+S.n%2
  WHEN S.perfil BETWEEN 87 AND 92 AND Linea.n=1 THEN CASE S.n%3 WHEN 0 THEN 14 WHEN 1 THEN 15 ELSE 16 END
  WHEN S.perfil BETWEEN 93 AND 96 AND Linea.n=1 THEN CASE S.n%2 WHEN 0 THEN 13 ELSE 20 END
  WHEN S.perfil>=97 AND Linea.n=1 THEN 17+S.n%3 END tipo) T
 JOIN #P P ON P.n=(T.tipo-1)*100+(S.modelo-1)*4+S.marca;
 CREATE CLUSTERED INDEX IX_D ON #D(venta,id_repuesto);
 -- Entregas semanales en paquetes. Se descuenta el remanente de paquetes anteriores.
 ;WITH Demanda AS(SELECT periodo,id_repuesto,producto,tipo,SUM(CONVERT(bigint,cantidad)) unidades FROM #D GROUP BY periodo,id_repuesto,producto,tipo),
 Acumulado AS(SELECT *,SUM(unidades) OVER(PARTITION BY id_repuesto ORDER BY periodo ROWS UNBOUNDED PRECEDING) acumulado,CASE WHEN tipo<=4 THEN 6 WHEN tipo=12 THEN 4 WHEN tipo BETWEEN 5 AND 9 THEN 2 ELSE 1 END paquete FROM Demanda),
 Objetivo AS(SELECT *,((acumulado+paquete-1)/paquete)*paquete objetivo FROM Acumulado),
 Entregas AS(SELECT *,objetivo-LAG(objetivo,1,CONVERT(bigint,0)) OVER(PARTITION BY id_repuesto ORDER BY periodo) cantidad FROM Objetivo)
 SELECT E.periodo,E.id_repuesto,CONVERT(int,E.cantidad) cantidad,PR.id_proveedor,
  CONVERT(decimal(10,2),ROUND(P.precio_compra*(0.88+0.12*DATEDIFF(day,@Desde,E.periodo)/NULLIF(CONVERT(decimal(12,4),DATEDIFF(day,@Desde,@Hasta)),0))/50,0)*50) precio,
  1+(ROW_NUMBER() OVER(PARTITION BY E.periodo,PR.id_proveedor ORDER BY E.id_repuesto)-1)/25 bloque
 INTO #C FROM Entregas E JOIN #P P ON P.id_repuesto=E.id_repuesto JOIN #Proveedores PR ON PR.n=1+(E.tipo*3+(E.producto-1)%4)%@Prov WHERE E.cantidad>0;
 SELECT periodo,id_proveedor,bloque,DATEADD(second,CONVERT(int,bloque)-1,DATEADD(hour,8,CONVERT(datetime2,periodo))) fecha,
  SUM(CONVERT(decimal(20,2),cantidad)*precio) total INTO #CabCompras FROM #C GROUP BY periodo,id_proveedor,bloque;
 IF EXISTS(SELECT 1 FROM #CabCompras WHERE total>99999999.99) THROW 51306,'Una compra excede la capacidad del modelo.',1;
 SELECT 'Previsualizacion: no se modificaron datos.' resultado,@Desde desde,@Hasta hasta,@Nuevas ventas_nuevas,
  (SELECT COUNT(*) FROM #D) repuestos_vendidos,(SELECT COUNT(*) FROM #CabCompras) compras_nuevas,
  (SELECT COUNT(*) FROM #C) repuestos_recibidos,
  (SELECT CONVERT(decimal(10,2),AVG(CONVERT(decimal(10,2),CASE WHEN existentes>objetivo THEN existentes ELSE objetivo END))) FROM #Objetivos WHERE objetivo>0) promedio_ventas_diarias;
 IF @Aplicar=0 BEGIN ROLLBACK;RETURN;END;
 IF OBJECT_ID('dbo.RP18_ENTIDADES_CARGA','U') IS NULL CREATE TABLE dbo.RP18_ENTIDADES_CARGA(lote varchar(100) NOT NULL,tipo varchar(10) NOT NULL,id int NOT NULL,PRIMARY KEY(lote,tipo,id));
 IF EXISTS(SELECT 1 FROM #S S JOIN dbo.RP07_VENTAS V ON V.id_cliente=S.id_cliente AND V.fecha=S.fecha) THROW 51307,'Hay operaciones con la misma fecha y cliente. No se duplicaron.',1;
 CREATE TABLE #MC(id_compra int,id_proveedor int,fecha datetime2);
 INSERT dbo.RP09_COMPRAS(id_proveedor,fecha,total,estado) OUTPUT inserted.id_compra,inserted.id_proveedor,inserted.fecha INTO #MC
 SELECT id_proveedor,fecha,total,'RECIBIDO' FROM #CabCompras;
 CREATE UNIQUE INDEX IX_MC ON #MC(id_proveedor,fecha);
 SELECT ROW_NUMBER() OVER(ORDER BY M.id_compra,C.id_repuesto) n,M.id_compra,C.* INTO #IC FROM #C C JOIN #CabCompras H ON H.periodo=C.periodo AND H.id_proveedor=C.id_proveedor AND H.bloque=C.bloque JOIN #MC M ON M.id_proveedor=H.id_proveedor AND M.fecha=H.fecha;
 DECLARE @i int=1,@total int=(SELECT COUNT(*) FROM #IC);
 WHILE @i<=@total
 BEGIN
  INSERT dbo.RP10_DETALLE_COMPRAS(id_compra,id_repuesto,cantidad,precio_unitario,subtotal)
  SELECT id_compra,id_repuesto,cantidad,precio,cantidad*precio FROM #IC WHERE n BETWEEN @i AND @i+1999;
  SET @i+=2000;
 END;
 CREATE TABLE #MV(id_venta int,id_cliente int,fecha datetime2);
 INSERT dbo.RP07_VENTAS(id_cliente,id_vendedor,fecha,total,estado) OUTPUT inserted.id_venta,inserted.id_cliente,inserted.fecha INTO #MV
 SELECT S.id_cliente,S.usuario_id,S.fecha,SUM(D.cantidad*D.precio),'COMPLETADA' FROM #S S JOIN #D D ON D.venta=S.n GROUP BY S.id_cliente,S.usuario_id,S.fecha;
 CREATE UNIQUE INDEX IX_MV ON #MV(id_cliente,fecha);
 SELECT ROW_NUMBER() OVER(ORDER BY M.id_venta,D.id_repuesto) n,M.id_venta,D.* INTO #IV FROM #D D JOIN #S S ON S.n=D.venta JOIN #MV M ON M.id_cliente=S.id_cliente AND M.fecha=S.fecha;
 SET @i=1;SET @total=(SELECT COUNT(*) FROM #IV);
 WHILE @i<=@total
 BEGIN
  INSERT dbo.RP08_DETALLE_VENTAS(id_venta,id_repuesto,cantidad,precio_unitario,subtotal)
  SELECT id_venta,id_repuesto,cantidad,precio,cantidad*precio FROM #IV WHERE n BETWEEN @i AND @i+1999;
  SET @i+=2000;
 END;
 INSERT dbo.RP18_ENTIDADES_CARGA SELECT @Lote,'venta',id_venta FROM #MV UNION ALL SELECT @Lote,'compra',id_compra FROM #MC;
 IF (SELECT COUNT(*) FROM #MV)<>@Nuevas THROW 51308,'Cantidad de ventas inesperada.',1;
 IF EXISTS(SELECT 1 FROM(SELECT H.id_repuesto,SUM(CASE WHEN tipo_movimiento='ENTRADA_COMPRA' THEN CONVERT(bigint,cantidad) ELSE -CONVERT(bigint,cantidad) END) OVER(PARTITION BY H.id_repuesto ORDER BY H.fecha,H.id_historial ROWS UNBOUNDED PRECEDING) saldo FROM dbo.RP11_INVENTARIO_HISTORIAL H JOIN #P P ON P.id_repuesto=H.id_repuesto) S WHERE saldo<0)
  THROW 51309,'Saldo historico negativo. Se revierte la carga.',1;
 DECLARE @Resumen nvarchar(max)=(SELECT @Nuevas ventas_nuevas,(SELECT COUNT(*) FROM #MV) ventas,(SELECT COUNT(*) FROM #MC) compras,(SELECT COUNT(*) FROM #IV) lineas_venta,(SELECT COUNT(*) FROM #IC) lineas_compra,'CRC' moneda,64 promedio_objetivo,0 operaciones_previas_modificadas FOR JSON PATH,WITHOUT_ARRAY_WRAPPER);
 INSERT dbo.RP15_CARGAS_DATOS(lote,configuracion,fecha_desde,fecha_hasta,resumen) VALUES(@Lote,'cinco_anos;64_promedio;conservar_existentes',@Desde,@Hasta,@Resumen);
 COMMIT;
 SELECT 'Carga aplicada una vez; registros previos conservados.' resultado,@Resumen resumen;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
GO
