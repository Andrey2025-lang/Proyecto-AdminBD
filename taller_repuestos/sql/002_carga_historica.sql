-- Datos SINTÉTICOS con apariencia comercial. No son identidades verificadas.
-- Ejecutar 001_control_duplicidad.sql y 003_indices_historial.sql primero.
-- No borra ni actualiza clientes, repuestos u operaciones existentes.
-- Una transacción, bloques de inserción y marca de lote evitan cargas parciales
-- y duplicadas. Los nombres/diccionarios solo viven aquí, nunca en PHP/JS.
USE TallerRepuestosDB;
GO
SET NOCOUNT ON;
SET XACT_ABORT ON;
SET ANSI_NULLS ON;
SET ANSI_PADDING ON;
SET ANSI_WARNINGS ON;
SET ARITHABORT ON;
SET CONCAT_NULL_YIELDS_NULL ON;
SET QUOTED_IDENTIFIER ON;
SET NUMERIC_ROUNDABORT OFF;

DECLARE @CantidadClientes INT=5000, @CantidadRepuestos INT=2000,
        @CantidadVendedores INT=4, @CantidadProveedores INT=15,
        @CantidadVentas INT=15000, @CantidadCompras INT=1500;
DECLARE @Lote VARCHAR(100)='historia_comercial_v1';
DECLARE @FechaHasta DATE=CONVERT(DATE,GETDATE()), @FechaDesde DATE;
SET @FechaDesde=DATEADD(YEAR,-2,@FechaHasta);
DECLARE @Configuracion VARCHAR(200)=CONCAT(@CantidadClientes,':',@CantidadRepuestos,':',@CantidadVendedores,':',@CantidadProveedores,':',@CantidadVentas,':',@CantidadCompras);
IF @CantidadClientes NOT BETWEEN 1 AND 100000 OR @CantidadRepuestos NOT BETWEEN 1 AND 2000
 OR @CantidadVendedores NOT BETWEEN 1 AND 4 OR @CantidadProveedores NOT BETWEEN 1 AND 15
 OR @CantidadVentas NOT BETWEEN 1 AND 100000 OR @CantidadCompras NOT BETWEEN 1 AND 100000
 THROW 51100,'Cantidades fuera de rango. Clientes/ventas/compras hasta 100000, repuestos hasta 2000, vendedores hasta 4 y proveedores hasta 15.',1;
IF OBJECT_ID('dbo.RP14_SOLICITUDES_OPERACION','U') IS NULL
 THROW 51101,'Ejecute primero la migracion 001_control_duplicidad.sql.',1;
IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP11_INVENTARIO_HISTORIAL') AND name='IX_Historial_Repuesto_Fecha')
 THROW 51102,'Ejecute primero la migracion 003_indices_historial.sql.',1;
BEGIN TRY
 BEGIN TRANSACTION;
 DECLARE @Lock INT;
 EXEC @Lock=sys.sp_getapplock @Resource='carga_historia_comercial',@LockMode='Exclusive',@LockOwner='Transaction',@LockTimeout=60000;
 IF @Lock<0 THROW 51103,'Otra carga esta en ejecucion. Intente despues.',1;
 IF OBJECT_ID('dbo.RP15_CARGAS_DATOS','U') IS NULL
 CREATE TABLE dbo.RP15_CARGAS_DATOS(
    lote VARCHAR(100) NOT NULL PRIMARY KEY, configuracion VARCHAR(200) NOT NULL,
    fecha_desde DATE NOT NULL, fecha_hasta DATE NOT NULL, fecha_carga DATETIME2 NOT NULL DEFAULT SYSDATETIME(),
    resumen NVARCHAR(MAX) NOT NULL
 );
 IF EXISTS(SELECT 1 FROM dbo.RP15_CARGAS_DATOS WHERE lote=@Lote)
 BEGIN
    IF EXISTS(SELECT 1 FROM dbo.RP15_CARGAS_DATOS WHERE lote=@Lote AND configuracion<>@Configuracion)
       THROW 51104,'El lote ya existe con otras cantidades. No se agregaron registros. Revise la carga existente.',1;
    SELECT 'Lote ya cargado: no se insertaron datos adicionales.' estado,lote,fecha_desde,fecha_hasta,resumen FROM dbo.RP15_CARGAS_DATOS WHERE lote=@Lote;
    COMMIT; RETURN;
 END;
 IF NOT EXISTS(SELECT 1 FROM dbo.RP13_USUARIOS WHERE nombre_usuario='sistema')
    THROW 51105,'Falta el usuario sistema requerido por el historial de compras.',1;

 CREATE TABLE #N(n INT NOT NULL PRIMARY KEY);
 ;WITH D(n) AS (SELECT n FROM (VALUES(0),(1),(2),(3),(4),(5),(6),(7),(8),(9)) X(n))
 INSERT #N SELECT TOP(100000) CONVERT(INT,ROW_NUMBER() OVER(ORDER BY A.n,B.n,C.n,D.n,E.n)) FROM D A CROSS JOIN D B CROSS JOIN D C CROSS JOIN D D CROSS JOIN D E;

 DECLARE @Nombres TABLE(id INT PRIMARY KEY,nombre VARCHAR(30));
 INSERT @Nombres VALUES(1,'Andrea'),(2,'Carlos'),(3,'Daniela'),(4,'Luis'),(5,'María'),(6,'José'),(7,'Valeria'),(8,'Diego'),(9,'Sofía'),(10,'Andrés'),(11,'Gabriela'),(12,'David'),(13,'Lucía'),(14,'Pablo'),(15,'Natalia'),(16,'Esteban'),(17,'Paola'),(18,'Ricardo'),(19,'Adriana'),(20,'Jorge'),(21,'Elena'),(22,'Daniel'),(23,'Patricia'),(24,'Fernando'),(25,'Carolina'),(26,'Mario'),(27,'Alejandra'),(28,'Óscar'),(29,'Isabel'),(30,'Roberto'),(31,'Melissa'),(32,'Felipe'),(33,'Laura'),(34,'Sebastián'),(35,'Cristina'),(36,'Sergio'),(37,'Ana'),(38,'Eduardo'),(39,'Rebeca'),(40,'Manuel');
 DECLARE @Apellidos TABLE(id INT PRIMARY KEY,apellido VARCHAR(30));
 INSERT @Apellidos VALUES(1,'Vargas'),(2,'Rodríguez'),(3,'González'),(4,'Mora'),(5,'Ramírez'),(6,'Castro'),(7,'Jiménez'),(8,'Solano'),(9,'Rojas'),(10,'Méndez'),(11,'Hernández'),(12,'Sánchez'),(13,'Chaves'),(14,'Alvarado'),(15,'Salazar'),(16,'Campos'),(17,'Vega'),(18,'Araya'),(19,'Navarro'),(20,'Arias'),(21,'Cordero'),(22,'Aguilar'),(23,'Pérez'),(24,'López'),(25,'Soto'),(26,'Calderón'),(27,'Quesada'),(28,'Valverde'),(29,'Villalobos'),(30,'Segura'),(31,'Montero'),(32,'Brenes'),(33,'Fallas'),(34,'Cruz'),(35,'León'),(36,'Porras'),(37,'Umaña'),(38,'Zamora'),(39,'Cascante'),(40,'Céspedes');
 SELECT N.n,CONVERT(VARCHAR(20),900700000+N.n) cedula,
    CONVERT(VARCHAR(100),A.nombre+' '+B.apellido+' '+C.apellido) nombre,
    CONVERT(VARCHAR(20),80000000+((N.n*7919)%19999999)) telefono,
    CASE WHEN N.n%4=0 THEN CONVERT(VARCHAR(100),'contacto.'+CONVERT(VARCHAR(10),N.n)+'@example.com') END email
 INTO #Clientes FROM #N N JOIN @Nombres A ON A.id=1+(N.n-1)%40 JOIN @Apellidos B ON B.id=1+((N.n-1)/40)%40
 JOIN @Apellidos C ON C.id=1+((N.n-1)/1600)%40 WHERE N.n<=@CantidadClientes;
 IF EXISTS(SELECT 1 FROM #Clientes C JOIN dbo.RP05_CLIENTES R ON R.cedula=C.cedula)
    THROW 51106,'Hay identificaciones de clientes que coinciden con registros existentes. No se sobrescribieron datos.',1;
 DECLARE @i INT=1;
 WHILE @i<=@CantidadClientes
 BEGIN
    INSERT dbo.RP05_CLIENTES(cedula,nombre,telefono,email) SELECT cedula,nombre,telefono,email FROM #Clientes WHERE n BETWEEN @i AND @i+999;
    SET @i+=1000;
 END;
 ALTER TABLE #Clientes ADD id_cliente INT;
 UPDATE C SET id_cliente=R.id_cliente FROM #Clientes C JOIN dbo.RP05_CLIENTES R ON R.cedula=C.cedula;

 DECLARE @Vendedores TABLE(n INT PRIMARY KEY,nombre VARCHAR(50),usuario_id INT);
 INSERT @Vendedores(n,nombre) SELECT n,nombre FROM (VALUES(1,'Daniela Vargas Mora'),(2,'José Ramírez Castro'),(3,'Andrés Solano Rojas'),(4,'Valeria Méndez Arias')) V(n,nombre) WHERE n<=@CantidadVendedores;
 IF EXISTS(SELECT 1 FROM @Vendedores V JOIN dbo.RP13_USUARIOS U ON U.nombre_usuario=V.nombre)
    THROW 51107,'Un vendedor de la carga ya existe. Revise los nombres antes de continuar.',1;
 INSERT dbo.RP13_USUARIOS(nombre_usuario,rol,estado) SELECT nombre,'Vendedor','ACTIVO' FROM @Vendedores;
 UPDATE V SET usuario_id=U.usuario_id FROM @Vendedores V JOIN dbo.RP13_USUARIOS U ON U.nombre_usuario=V.nombre;
 DECLARE @Proveedores TABLE(n INT PRIMARY KEY,nombre VARCHAR(150),id_proveedor INT);
 INSERT @Proveedores(n,nombre) SELECT n,nombre FROM (VALUES
 (1,'Distribuidora Automotriz del Valle'),(2,'Importadora Ruta Central'),(3,'Suministros Mecánicos del Oeste'),(4,'Repuestos y Accesorios del Norte'),(5,'Comercial Automotriz Los Robles'),
 (6,'Distribuciones Motor y Ruta'),(7,'Importadora Técnica del Pacífico'),(8,'Soluciones Automotrices La Unión'),(9,'Repuestos Industriales del Este'),(10,'Comercial de Frenos y Suspensión'),
 (11,'Suministros Eléctricos Automotrices'),(12,'Distribuidora de Filtros Central'),(13,'Refrigeración Automotriz del Sur'),(14,'Rodamientos y Transmisiones del Valle'),(15,'Importaciones Automotrices Horizonte')) P(n,nombre) WHERE n<=@CantidadProveedores;
 IF EXISTS(SELECT 1 FROM @Proveedores P JOIN dbo.RP02_PROVEEDORES R ON R.nombre_empresa=P.nombre)
    THROW 51108,'Un proveedor de la carga ya existe. No se combinaron empresas automaticamente.',1;
 INSERT dbo.RP02_PROVEEDORES(nombre_empresa,contacto,telefono,email)
 SELECT P.nombre,N.nombre+' '+A.apellido,CONVERT(VARCHAR(20),22000000+P.n*3701),'ventas.'+CONVERT(VARCHAR(10),P.n)+'@example.com'
 FROM @Proveedores P JOIN @Nombres N ON N.id=P.n JOIN @Apellidos A ON A.id=1+(P.n*7)%40;
 UPDATE P SET id_proveedor=R.id_proveedor FROM @Proveedores P JOIN dbo.RP02_PROVEEDORES R ON R.nombre_empresa=P.nombre;

 DECLARE @Tipos TABLE(n INT PRIMARY KEY,nombre VARCHAR(60),categoria VARCHAR(100),familia INT,costo DECIMAL(10,2));
 INSERT @Tipos VALUES
 (1,'Filtro de aceite','Filtros',1,1800),(2,'Filtro de aire','Filtros',1,3500),(3,'Filtro de combustible','Filtros',1,6500),(4,'Filtro de cabina','Filtros',1,3000),
 (5,'Pastillas de freno delanteras','Frenos',2,15000),(6,'Pastillas de freno traseras','Frenos',2,13000),(7,'Disco de freno delantero','Frenos',2,28000),
 (8,'Amortiguador delantero','Suspensión y dirección',3,35000),(9,'Amortiguador trasero','Suspensión y dirección',3,28000),(10,'Terminal de dirección','Suspensión y dirección',3,8500),(11,'Rótula de suspensión','Suspensión y dirección',3,9500),
 (12,'Bujía de encendido','Encendido',4,2500),(13,'Bobina de encendido','Encendido',4,22000),
 (14,'Correa de accesorios','Refrigeración y correas',5,9000),(15,'Bomba de agua','Refrigeración y correas',5,30000),(16,'Termostato','Refrigeración y correas',5,6500),(17,'Radiador','Refrigeración y correas',5,65000),
 (18,'Alternador','Sistema eléctrico',6,95000),(19,'Motor de arranque','Sistema eléctrico',6,85000),(20,'Sensor de oxígeno','Sistema eléctrico',6,28000);
 INSERT dbo.RP01_CATEGORIAS(nombre) SELECT DISTINCT T.categoria FROM @Tipos T
 WHERE NOT EXISTS(SELECT 1 FROM dbo.RP01_CATEGORIAS C WHERE C.nombre_normalizado=UPPER(T.categoria));
 DECLARE @Vehiculos TABLE(n INT PRIMARY KEY,modelo VARCHAR(40),periodo VARCHAR(15));
 INSERT @Vehiculos VALUES(1,'Toyota Corolla','2010-2014'),(2,'Toyota Yaris','2014-2018'),(3,'Toyota Hilux','2012-2015'),(4,'Toyota RAV4','2013-2018'),(5,'Toyota Prado','2010-2017'),
 (6,'Honda Civic','2012-2015'),(7,'Honda CR-V','2012-2016'),(8,'Honda Fit','2014-2018'),(9,'Nissan Sentra','2013-2019'),(10,'Nissan Versa','2012-2018'),(11,'Nissan Frontier','2010-2015'),(12,'Nissan X-Trail','2014-2020'),
 (13,'Suzuki Swift','2011-2017'),(14,'Suzuki Vitara','2016-2020'),(15,'Hyundai Accent','2012-2017'),(16,'Hyundai Elantra','2011-2016'),(17,'Hyundai Tucson','2016-2020'),(18,'Kia Rio','2012-2017'),(19,'Kia Sportage','2011-2016'),
 (20,'Mitsubishi L200','2010-2015'),(21,'Mitsubishi Outlander','2013-2019'),(22,'Mazda 3','2014-2018'),(23,'Mazda BT-50','2012-2019'),(24,'Chevrolet Aveo','2012-2017'),(25,'Chevrolet D-Max','2013-2019');
 DECLARE @Marcas TABLE(familia INT,n INT,marca VARCHAR(20),PRIMARY KEY(familia,n));
 INSERT @Marcas VALUES(1,1,'Mann'),(1,2,'Bosch'),(1,3,'Wix'),(1,4,'Fram'),(2,1,'Brembo'),(2,2,'TRW'),(2,3,'Akebono'),(2,4,'Bosch'),(3,1,'Monroe'),(3,2,'KYB'),(3,3,'Sachs'),(3,4,'TRW'),(4,1,'NGK'),(4,2,'Denso'),(4,3,'Bosch'),(4,4,'Champion'),(5,1,'Gates'),(5,2,'Dayco'),(5,3,'Aisin'),(5,4,'GMB'),(6,1,'Bosch'),(6,2,'Denso'),(6,3,'Valeo'),(6,4,'Hella');
 -- Compatibilidades ilustrativas: no sustituir un catálogo técnico del fabricante.
 SELECT N.n,CONVERT(VARCHAR(150),T.nombre+' '+M.marca+' · '+V.modelo+' '+V.periodo) nombre,C.id_categoria,
 CONVERT(DECIMAL(10,2),ROUND(T.costo*(100+(V.n%7)*4+(M.n-1)*6)/100.0,0)) precio_compra,
 CONVERT(DECIMAL(10,2),ROUND(T.costo*(145+(V.n%7)*6+(M.n-1)*9)/100.0/50,0)*50) precio_venta,
 2+(N.n%7) stock_minimo,CONVERT(VARCHAR(12),200500000000+N.n) base_codigo,
 CONVERT(INT,NULL) id_repuesto,CONVERT(VARCHAR(20),NULL) codigo_barras
 INTO #Repuestos FROM #N N JOIN @Tipos T ON T.n=1+(N.n-1)/100 JOIN @Vehiculos V ON V.n=1+((N.n-1)/4)%25
 JOIN @Marcas M ON M.familia=T.familia AND M.n=1+(N.n-1)%4 JOIN dbo.RP01_CATEGORIAS C ON C.nombre_normalizado=UPPER(T.categoria)
 WHERE N.n<=@CantidadRepuestos;
 UPDATE R SET codigo_barras=R.base_codigo+CONVERT(VARCHAR(1),(10-X.suma%10)%10)
 FROM #Repuestos R JOIN(
    SELECT P.n,SUM(CONVERT(INT,SUBSTRING(P.base_codigo,N.n,1))*CASE WHEN N.n%2=0 THEN 3 ELSE 1 END) suma
    FROM #Repuestos P CROSS JOIN #N N WHERE N.n<=12 GROUP BY P.n
 ) X ON X.n=R.n;
 IF EXISTS(SELECT 1 FROM #Repuestos P JOIN dbo.RP03_REPUESTOS R ON R.codigo_barras=P.codigo_barras)
    THROW 51109,'Un codigo de producto coincide con un repuesto existente. La carga se cancelo sin sobrescribirlo.',1;
 SET @i=1;
 WHILE @i<=@CantidadRepuestos
 BEGIN
    INSERT dbo.RP03_REPUESTOS(codigo_barras,nombre,id_categoria,precio_compra,precio_venta,stock_actual,stock_minimo,estado)
    SELECT codigo_barras,nombre,id_categoria,precio_compra,precio_venta,0,stock_minimo,'ACTIVO' FROM #Repuestos WHERE n BETWEEN @i AND @i+499;
    SET @i+=500;
 END;
 UPDATE P SET id_repuesto=R.id_repuesto FROM #Repuestos P JOIN dbo.RP03_REPUESTOS R ON R.codigo_barras=P.codigo_barras;
 CREATE UNIQUE INDEX IX_TempRepuestos ON #Repuestos(n);

 -- Días laborables, con menos actividad los sábados y mayor demanda reciente.
 SELECT N.n,DATEADD(DAY,N.n-1,@FechaDesde) fecha INTO #Dias FROM #N N WHERE N.n<=DATEDIFF(DAY,@FechaDesde,@FechaHasta)+1;
 SELECT CONVERT(INT,ROW_NUMBER() OVER(ORDER BY D.fecha,N.n)) n,D.fecha INTO #Turnos FROM #Dias D
 JOIN #N N ON N.n<=CASE WHEN DATEDIFF(DAY,'19000101',D.fecha)%7=5 THEN 3 ELSE 5 END
     +DATEDIFF(MONTH,@FechaDesde,D.fecha)/8+CASE WHEN MONTH(D.fecha) IN(11,12) THEN 2 ELSE 0 END
 WHERE DATEDIFF(DAY,'19000101',D.fecha)%7<>6;
 CREATE UNIQUE CLUSTERED INDEX IX_Turnos ON #Turnos(n);
 DECLARE @Turnos INT=(SELECT COUNT(*) FROM #Turnos);
 SELECT N.n,T.fecha,C.id_cliente,V.usuario_id,
 ROW_NUMBER() OVER(PARTITION BY T.fecha ORDER BY N.n) secuencia,
 COUNT(*) OVER(PARTITION BY T.fecha) cantidad_dia
 INTO #BaseVentas FROM #N N JOIN #Turnos T ON T.n=1+CONVERT(INT,CONVERT(BIGINT,N.n-1)*@Turnos/@CantidadVentas)
 JOIN #Clientes C ON C.n=1+CASE WHEN N.n%10<6 THEN (N.n*37)%CASE WHEN @CantidadClientes<1000 THEN @CantidadClientes ELSE 1000 END ELSE (N.n*97)%@CantidadClientes END
 JOIN @Vendedores V ON V.n=1+(CASE WHEN N.n%10<4 THEN 0 WHEN N.n%10<7 THEN 1 WHEN N.n%10<9 THEN 2 ELSE 3 END)%@CantidadVendedores
 WHERE N.n<=@CantidadVentas;
 SELECT n,id_cliente,usuario_id,DATEADD(SECOND,CONVERT(INT,(secuencia-1)*28800/cantidad_dia),DATEADD(HOUR,9,CONVERT(DATETIME2,fecha))) fecha INTO #Ventas FROM #BaseVentas;
 CREATE UNIQUE CLUSTERED INDEX IX_TempVentas ON #Ventas(n);
 SELECT V.n venta,R.id_repuesto,1+(V.n+N.n)%3 cantidad,
 CONVERT(DECIMAL(10,2),ROUND(R.precio_venta*(85+DATEDIFF(MONTH,@FechaDesde,V.fecha)*15.0/24)/100,2)) precio,
 CASE WHEN CONVERT(DATE,V.fecha)<DATEADD(DAY,CASE WHEN DAY(V.fecha)<=15 THEN 0 ELSE 15 END,DATEFROMPARTS(YEAR(V.fecha),MONTH(V.fecha),1)) THEN CONVERT(DATE,V.fecha)
 ELSE DATEADD(DAY,CASE WHEN DAY(V.fecha)<=15 THEN 0 ELSE 15 END,DATEFROMPARTS(YEAR(V.fecha),MONTH(V.fecha),1)) END periodo
 INTO #DetalleVentas FROM #Ventas V JOIN #N N ON N.n<=1+(V.n%4)
 JOIN #Repuestos R ON R.n=1+CASE WHEN V.n%3=0 THEN (V.n*37+N.n*113)%CASE WHEN @CantidadRepuestos<400 THEN @CantidadRepuestos ELSE 400 END ELSE (V.n*37+N.n*113)%@CantidadRepuestos END;
 UPDATE #DetalleVentas SET periodo=@FechaDesde WHERE periodo<@FechaDesde;

 -- Cada quincena recibe primero las unidades que se venderán en ella.
 SELECT D.periodo,D.id_repuesto,SUM(D.cantidad) cantidad INTO #Necesidades FROM #DetalleVentas D GROUP BY D.periodo,D.id_repuesto;
 MERGE #Necesidades AS T USING(SELECT @FechaDesde periodo,id_repuesto,n%17 reserva FROM #Repuestos) S
 ON T.periodo=S.periodo AND T.id_repuesto=S.id_repuesto
 WHEN MATCHED THEN UPDATE SET cantidad=T.cantidad+S.reserva
 WHEN NOT MATCHED AND S.reserva>0 THEN INSERT(periodo,id_repuesto,cantidad) VALUES(S.periodo,S.id_repuesto,S.reserva);
 SELECT N.periodo,N.id_repuesto,N.cantidad,P.id_proveedor,
 CONVERT(DECIMAL(10,2),ROUND(R.precio_compra*(85+DATEDIFF(MONTH,@FechaDesde,N.periodo)*15.0/24)/100,2)) precio,
 DENSE_RANK() OVER(ORDER BY N.periodo,P.id_proveedor) grupo,
 ROW_NUMBER() OVER(PARTITION BY N.periodo,P.id_proveedor ORDER BY N.id_repuesto) linea
 INTO #LineasCompra FROM #Necesidades N JOIN #Repuestos R ON R.id_repuesto=N.id_repuesto
 JOIN @Proveedores P ON P.n=1+(R.n-1)%@CantidadProveedores;
 DECLARE @Grupos INT=(SELECT COUNT(DISTINCT grupo) FROM #LineasCompra),@Lineas INT=(SELECT COUNT(*) FROM #LineasCompra);
 IF @CantidadCompras<@Grupos OR @CantidadCompras>@Lineas
    THROW 51110,'Cantidad de compras incompatible con los productos/periodos. Ajustar cantidades; se revirtio toda la carga.',1;
 SELECT grupo,1+COUNT(*) adicionales INTO #Divisiones FROM(
    SELECT TOP(@CantidadCompras-@Grupos) grupo FROM #LineasCompra WHERE linea>1 ORDER BY linea,grupo
 ) X GROUP BY grupo;
 SELECT DISTINCT L.grupo,L.periodo,L.id_proveedor,COALESCE(D.adicionales,1) divisiones INTO #GruposCompra
 FROM #LineasCompra L LEFT JOIN #Divisiones D ON D.grupo=L.grupo;
 SELECT G.grupo,N.n bloque,G.id_proveedor,DATEADD(SECOND,N.n-1,DATEADD(HOUR,8,CONVERT(DATETIME2,G.periodo))) fecha INTO #Compras
 FROM #GruposCompra G JOIN #N N ON N.n<=G.divisiones;
 ALTER TABLE #LineasCompra ADD bloque INT;
 UPDATE L SET bloque=1+(L.linea-1)%G.divisiones FROM #LineasCompra L JOIN #GruposCompra G ON G.grupo=L.grupo;

 CREATE TABLE #MapaCompras(id_compra INT, id_proveedor INT,fecha DATETIME2);
 INSERT dbo.RP09_COMPRAS(id_proveedor,fecha,total,estado)
 OUTPUT inserted.id_compra,inserted.id_proveedor,inserted.fecha INTO #MapaCompras
 SELECT C.id_proveedor,C.fecha,SUM(L.cantidad*L.precio),'RECIBIDO' FROM #Compras C JOIN #LineasCompra L ON L.grupo=C.grupo AND L.bloque=C.bloque GROUP BY C.id_proveedor,C.fecha;
 SELECT CONVERT(INT,ROW_NUMBER() OVER(ORDER BY L.grupo,L.bloque,L.id_repuesto)) n,M.id_compra,L.id_repuesto,L.cantidad,L.precio INTO #InsertarCompras
 FROM #LineasCompra L JOIN #Compras C ON C.grupo=L.grupo AND C.bloque=L.bloque JOIN #MapaCompras M ON M.id_proveedor=C.id_proveedor AND M.fecha=C.fecha;
 DECLARE @Total INT=(SELECT COUNT(*) FROM #InsertarCompras);
 SET @i=1;
 WHILE @i<=@Total
 BEGIN
    INSERT dbo.RP10_DETALLE_COMPRAS(id_compra,id_repuesto,cantidad,precio_unitario,subtotal)
    SELECT id_compra,id_repuesto,cantidad,precio,cantidad*precio FROM #InsertarCompras WHERE n BETWEEN @i AND @i+999;
    SET @i+=1000;
 END;
 CREATE TABLE #MapaVentas(id_venta INT,fecha DATETIME2 PRIMARY KEY);
 INSERT dbo.RP07_VENTAS(id_cliente,id_vendedor,fecha,total,estado)
 OUTPUT inserted.id_venta,inserted.fecha INTO #MapaVentas
 SELECT V.id_cliente,V.usuario_id,V.fecha,SUM(D.cantidad*D.precio),'COMPLETADA' FROM #Ventas V JOIN #DetalleVentas D ON D.venta=V.n GROUP BY V.id_cliente,V.usuario_id,V.fecha;
 SELECT CONVERT(INT,ROW_NUMBER() OVER(ORDER BY D.venta,D.id_repuesto)) n,M.id_venta,D.id_repuesto,D.cantidad,D.precio INTO #InsertarVentas
 FROM #DetalleVentas D JOIN #Ventas V ON V.n=D.venta JOIN #MapaVentas M ON M.fecha=V.fecha;
 SET @Total=(SELECT COUNT(*) FROM #InsertarVentas); SET @i=1;
 WHILE @i<=@Total
 BEGIN
    INSERT dbo.RP08_DETALLE_VENTAS(id_venta,id_repuesto,cantidad,precio_unitario,subtotal)
    SELECT id_venta,id_repuesto,cantidad,precio,cantidad*precio FROM #InsertarVentas WHERE n BETWEEN @i AND @i+1999;
    SET @i+=2000;
 END;

 -- Comprobar saldos cronológicos y finales ANTES de confirmar el lote.
 IF EXISTS(SELECT 1 FROM(
    SELECT SUM(CASE WHEN H.tipo_movimiento='ENTRADA_COMPRA' THEN H.cantidad ELSE -H.cantidad END)
    OVER(PARTITION BY H.id_repuesto ORDER BY H.fecha,H.id_historial ROWS UNBOUNDED PRECEDING) saldo
    FROM dbo.RP11_INVENTARIO_HISTORIAL H JOIN #Repuestos R ON R.id_repuesto=H.id_repuesto
 ) S WHERE saldo<0) THROW 51111,'Se detecto stock historico negativo. La carga se revirtio.',1;
 IF EXISTS(SELECT 1 FROM dbo.RP03_REPUESTOS P JOIN #Repuestos R ON R.id_repuesto=P.id_repuesto WHERE P.stock_actual<>R.n%17)
    THROW 51112,'El inventario final no coincide con los movimientos. La carga se revirtio.',1;
 DECLARE @Resumen NVARCHAR(MAX)=(SELECT @CantidadClientes clientes,@CantidadRepuestos repuestos,@CantidadVendedores vendedores,@CantidadProveedores proveedores,
    (SELECT COUNT(*) FROM #MapaVentas) ventas,(SELECT COUNT(*) FROM #MapaCompras) compras,
    (SELECT COUNT(*) FROM #InsertarVentas) lineas_venta,(SELECT COUNT(*) FROM #InsertarCompras) lineas_compra FOR JSON PATH,WITHOUT_ARRAY_WRAPPER);
 INSERT dbo.RP15_CARGAS_DATOS(lote,configuracion,fecha_desde,fecha_hasta,resumen) VALUES(@Lote,@Configuracion,@FechaDesde,@FechaHasta,@Resumen);
 COMMIT;
 SELECT 'Carga completada. Datos sinteticos guardados en SQL Server.' estado,@Lote lote,@FechaDesde fecha_desde,@FechaHasta fecha_hasta,@Resumen resumen;
END TRY
BEGIN CATCH
 IF @@TRANCOUNT>0 ROLLBACK;
 THROW;
END CATCH;
GO
