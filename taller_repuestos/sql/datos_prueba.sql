-- Datos ilustrativos para una demostración local. NO elimina ni vacía tablas.
-- Ejecute DESPUÉS de crear la base con TallerRepuestosDB.sql.
-- Los códigos DEMO-* y la cédula DEMO-CLIENTE identifican datos de muestra.
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
BEGIN TRY
    BEGIN TRANSACTION;
    IF NOT EXISTS (SELECT 1 FROM RP13_USUARIOS WHERE nombre_usuario = 'sistema')
        INSERT INTO RP13_USUARIOS(nombre_usuario,rol,estado) VALUES ('sistema','Admin','ACTIVO');
    IF NOT EXISTS (SELECT 1 FROM RP13_USUARIOS WHERE nombre_usuario = 'vendedor_demo')
        INSERT INTO RP13_USUARIOS(nombre_usuario,rol,estado) VALUES ('vendedor_demo','Vendedor','ACTIVO');

    IF NOT EXISTS (SELECT 1 FROM RP01_CATEGORIAS WHERE nombre = 'Filtros')
        INSERT INTO RP01_CATEGORIAS(nombre) VALUES ('Filtros');
    IF NOT EXISTS (SELECT 1 FROM RP01_CATEGORIAS WHERE nombre = 'Frenos')
        INSERT INTO RP01_CATEGORIAS(nombre) VALUES ('Frenos');
    IF NOT EXISTS (SELECT 1 FROM RP01_CATEGORIAS WHERE nombre = 'Encendido')
        INSERT INTO RP01_CATEGORIAS(nombre) VALUES ('Encendido');

    IF NOT EXISTS (SELECT 1 FROM RP05_CLIENTES WHERE cedula = 'DEMO-CLIENTE')
        INSERT INTO RP05_CLIENTES(cedula,nombre,telefono,email)
        VALUES ('DEMO-CLIENTE','Cliente de demostración','8888-0000','cliente@example.com');
    IF NOT EXISTS (SELECT 1 FROM RP02_PROVEEDORES WHERE email = 'proveedor-demo@example.com')
        INSERT INTO RP02_PROVEEDORES(nombre_empresa,contacto,telefono,email)
        VALUES ('Distribuidora Demo','Contacto de prueba','2222-0000','proveedor-demo@example.com');

    DECLARE @datos TABLE(codigo VARCHAR(20),nombre VARCHAR(150),categoria VARCHAR(100),compra DECIMAL(10,2),venta DECIMAL(10,2),minimo INT,cantidad INT);
    INSERT INTO @datos VALUES
        ('DEMO-FILTRO-01','Filtro de aceite','Filtros',3500,5500,5,10),
        ('DEMO-FILTRO-02','Filtro de aire','Filtros',6500,9500,3,8),
        ('DEMO-FRENO-01','Juego de pastillas de freno','Frenos',15000,22500,4,2),
        ('DEMO-BUJIA-01','Bujía de encendido','Encendido',2000,3500,6,4);
    INSERT INTO RP03_REPUESTOS(codigo_barras,nombre,id_categoria,precio_compra,precio_venta,stock_actual,stock_minimo,estado)
    SELECT D.codigo,D.nombre,(SELECT TOP (1) id_categoria FROM RP01_CATEGORIAS WHERE nombre=D.categoria ORDER BY id_categoria),D.compra,D.venta,0,D.minimo,'ACTIVO'
    FROM @datos D WHERE NOT EXISTS (SELECT 1 FROM RP03_REPUESTOS R WHERE R.codigo_barras=D.codigo);

    -- Una sola recepción inicial por repuesto de demostración. Las existencias
    -- se cargan a través del trigger, no mediante UPDATE directo al inventario.
    DECLARE @proveedor INT = (SELECT TOP (1) id_proveedor FROM RP02_PROVEEDORES WHERE email='proveedor-demo@example.com' ORDER BY id_proveedor);
    DECLARE @pendientes TABLE(id_repuesto INT,cantidad INT,precio DECIMAL(10,2));
    INSERT INTO @pendientes
    SELECT R.id_repuesto,D.cantidad,D.compra FROM @datos D JOIN RP03_REPUESTOS R ON R.codigo_barras=D.codigo
    WHERE NOT EXISTS (SELECT 1 FROM RP10_DETALLE_COMPRAS DC JOIN RP09_COMPRAS C ON C.id_compra=DC.id_compra WHERE DC.id_repuesto=R.id_repuesto AND C.id_proveedor=@proveedor);
    IF EXISTS (SELECT 1 FROM @pendientes)
    BEGIN
        INSERT INTO RP09_COMPRAS(id_proveedor,total,estado)
        SELECT @proveedor,SUM(cantidad*precio),'RECIBIDO' FROM @pendientes;
        DECLARE @compra INT = CONVERT(INT,SCOPE_IDENTITY());
        INSERT INTO RP10_DETALLE_COMPRAS(id_compra,id_repuesto,cantidad,precio_unitario,subtotal)
        SELECT @compra,id_repuesto,cantidad,precio,cantidad*precio FROM @pendientes;
    END;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
