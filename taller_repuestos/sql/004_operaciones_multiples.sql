-- Migración incremental. Conserva operaciones y procedimiento originales.
USE TallerRepuestosDB;
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
GO
IF COL_LENGTH('dbo.RP14_SOLICITUDES_OPERACION','id_operacion') IS NULL
    ALTER TABLE dbo.RP14_SOLICITUDES_OPERACION ADD id_operacion INT NULL;
GO
CREATE OR ALTER PROCEDURE dbo.sp_ProcesarVentaMultiple
    @id_cliente INT, @id_vendedor INT, @lineas NVARCHAR(MAX)
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    BEGIN TRY
        BEGIN TRANSACTION;
        IF ISJSON(@lineas)<>1 OR LEFT(LTRIM(@lineas),1)<>'['
            THROW 50010,'La lista de productos no es valida.',1;
        DECLARE @D TABLE(id_repuesto INT,cantidad BIGINT,precio_esperado DECIMAL(10,2));
        INSERT @D SELECT id_repuesto,cantidad,precio_esperado
        FROM OPENJSON(@lineas) WITH(id_repuesto INT,cantidad BIGINT,precio_esperado DECIMAL(10,2));
        IF NOT EXISTS(SELECT 1 FROM @D) OR (SELECT COUNT(*) FROM @D)>100
          OR EXISTS(SELECT 1 FROM @D WHERE id_repuesto IS NULL OR cantidad IS NULL OR cantidad<=0 OR cantidad>2147483647 OR precio_esperado<0)
            THROW 50010,'Revise los productos y cantidades de la venta.',1;
        IF EXISTS(SELECT 1 FROM @D GROUP BY id_repuesto HAVING SUM(cantidad)>2147483647 OR MIN(COALESCE(precio_esperado,-1))<>MAX(COALESCE(precio_esperado,-1)))
            THROW 50010,'Las cantidades o precios de un producto repetido no son validos.',1;
        IF NOT EXISTS(SELECT 1 FROM dbo.RP05_CLIENTES WHERE id_cliente=@id_cliente)
            THROW 50011,'El cliente seleccionado no existe.',1;
        IF NOT EXISTS(SELECT 1 FROM dbo.RP13_USUARIOS WHERE usuario_id=@id_vendedor AND estado='ACTIVO' AND nombre_usuario<>'sistema')
            THROW 50012,'El vendedor seleccionado no esta activo.',1;

        DECLARE @P TABLE(id_repuesto INT PRIMARY KEY,cantidad INT,precio DECIMAL(10,2),stock INT,estado VARCHAR(20));
        -- Bloqueos en orden de ID para reducir conflictos entre ventas concurrentes.
        DECLARE @id INT;
        DECLARE productos CURSOR LOCAL FAST_FORWARD FOR SELECT DISTINCT id_repuesto FROM @D ORDER BY id_repuesto;
        OPEN productos; FETCH NEXT FROM productos INTO @id;
        WHILE @@FETCH_STATUS=0
        BEGIN
            INSERT @P SELECT R.id_repuesto,CONVERT(INT,D.cantidad),R.precio_venta,R.stock_actual,R.estado
            FROM dbo.RP03_REPUESTOS R WITH(UPDLOCK,HOLDLOCK)
            JOIN(SELECT id_repuesto,SUM(cantidad) cantidad FROM @D GROUP BY id_repuesto) D ON D.id_repuesto=R.id_repuesto
            WHERE R.id_repuesto=@id;
            FETCH NEXT FROM productos INTO @id;
        END;
        CLOSE productos; DEALLOCATE productos;
        IF (SELECT COUNT(*) FROM @P)<>(SELECT COUNT(DISTINCT id_repuesto) FROM @D) OR EXISTS(SELECT 1 FROM @P WHERE estado<>'ACTIVO')
            THROW 50013,'Un repuesto ya no existe o esta inactivo.',1;
        IF EXISTS(SELECT 1 FROM @P WHERE stock<cantidad)
            THROW 50004,'Existencias insuficientes en uno de los repuestos.',1;
        IF EXISTS(SELECT 1 FROM @P P JOIN @D D ON D.id_repuesto=P.id_repuesto WHERE D.precio_esperado IS NOT NULL AND D.precio_esperado<>P.precio)
            THROW 50014,'Un precio cambio. Revise los precios actualizados antes de confirmar.',1;
        DECLARE @total DECIMAL(38,2)=(SELECT SUM(CONVERT(DECIMAL(20,2),cantidad)*precio) FROM @P);
        IF @total>99999999.99 THROW 50015,'El total supera el maximo permitido.',1;
        INSERT dbo.RP07_VENTAS(id_cliente,id_vendedor,total,estado) VALUES(@id_cliente,@id_vendedor,@total,'COMPLETADA');
        DECLARE @venta INT=CONVERT(INT,SCOPE_IDENTITY());
        INSERT dbo.RP08_DETALLE_VENTAS(id_venta,id_repuesto,cantidad,precio_unitario,subtotal)
        SELECT @venta,id_repuesto,cantidad,precio,cantidad*precio FROM @P;
        COMMIT TRANSACTION;
        SELECT @venta id_venta;
    END TRY
    BEGIN CATCH
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        THROW;
    END CATCH;
END;
GO
