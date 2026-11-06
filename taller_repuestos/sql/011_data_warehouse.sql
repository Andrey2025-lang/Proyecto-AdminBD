-- Data Warehouse incremental dentro de TallerRepuestosDB.
-- No modifica ni elimina tablas o datos operacionales.
USE TallerRepuestosDB;
GO
SET ANSI_NULLS ON;
SET QUOTED_IDENTIFIER ON;
SET XACT_ABORT ON;
GO
BEGIN TRY
    BEGIN TRANSACTION;

    IF SCHEMA_ID(N'dw') IS NULL
        EXEC(N'CREATE SCHEMA dw AUTHORIZATION dbo;');

    IF OBJECT_ID(N'dw.DIM_TIEMPO', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.DIM_TIEMPO(
            tiempo_key int IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_DIM_TIEMPO PRIMARY KEY,
            fecha date NOT NULL,
            anio smallint NOT NULL,
            trimestre tinyint NOT NULL,
            mes tinyint NOT NULL,
            nombre_mes nvarchar(10) NOT NULL,
            dia tinyint NOT NULL,
            dia_anio smallint NOT NULL,
            dia_semana tinyint NOT NULL,
            nombre_dia_semana nvarchar(12) NOT NULL,
            es_fin_semana bit NOT NULL,
            semana_iso tinyint NOT NULL,
            CONSTRAINT UQ_dw_DIM_TIEMPO_Fecha UNIQUE(fecha),
            CONSTRAINT CK_dw_DIM_TIEMPO_Calendario CHECK(
                mes BETWEEN 1 AND 12 AND dia BETWEEN 1 AND 31
                AND dia_anio BETWEEN 1 AND 366
                AND trimestre BETWEEN 1 AND 4 AND dia_semana BETWEEN 1 AND 7
                AND semana_iso BETWEEN 1 AND 53
            )
        );
    END;
    IF COL_LENGTH(N'dw.DIM_TIEMPO',N'dia_anio') IS NULL
        ALTER TABLE dw.DIM_TIEMPO ADD dia_anio smallint NULL;
    IF COL_LENGTH(N'dw.DIM_TIEMPO',N'nombre_dia_semana') IS NULL
        ALTER TABLE dw.DIM_TIEMPO ADD nombre_dia_semana nvarchar(12) NULL;
    IF COL_LENGTH(N'dw.DIM_TIEMPO',N'es_fin_semana') IS NULL
        ALTER TABLE dw.DIM_TIEMPO ADD es_fin_semana bit NULL;
    EXEC(N'
        UPDATE dw.DIM_TIEMPO
        SET dia_anio=DATEPART(dayofyear,fecha),
            nombre_dia_semana=CASE dia_semana WHEN 1 THEN N''lunes'' WHEN 2 THEN N''martes''
                WHEN 3 THEN N''miércoles'' WHEN 4 THEN N''jueves'' WHEN 5 THEN N''viernes''
                WHEN 6 THEN N''sábado'' ELSE N''domingo'' END,
            es_fin_semana=CASE WHEN dia_semana IN(6,7) THEN 1 ELSE 0 END
        WHERE dia_anio IS NULL OR nombre_dia_semana IS NULL OR es_fin_semana IS NULL;
        ALTER TABLE dw.DIM_TIEMPO ALTER COLUMN dia_anio smallint NOT NULL;
        ALTER TABLE dw.DIM_TIEMPO ALTER COLUMN nombre_dia_semana nvarchar(12) NOT NULL;
        ALTER TABLE dw.DIM_TIEMPO ALTER COLUMN es_fin_semana bit NOT NULL;');
    IF NOT EXISTS(SELECT 1 FROM sys.check_constraints WHERE parent_object_id=OBJECT_ID(N'dw.DIM_TIEMPO') AND name=N'CK_dw_DIM_TIEMPO_DiaAnio')
        EXEC(N'ALTER TABLE dw.DIM_TIEMPO ADD CONSTRAINT CK_dw_DIM_TIEMPO_DiaAnio CHECK(dia_anio BETWEEN 1 AND 366);');

    IF OBJECT_ID(N'dw.DIM_CLIENTE', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.DIM_CLIENTE(
            cliente_key bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_DIM_CLIENTE PRIMARY KEY,
            cliente_id_origen int NOT NULL,
            cedula varchar(20) NOT NULL,
            nombre nvarchar(100) NOT NULL,
            telefono varchar(20) NOT NULL,
            email varchar(100) NULL,
            actualizado_dw datetime2(7) NOT NULL CONSTRAINT DF_dw_DIM_CLIENTE_Actualizado DEFAULT SYSUTCDATETIME(),
            CONSTRAINT UQ_dw_DIM_CLIENTE_Origen UNIQUE(cliente_id_origen)
        );
    END;

    IF OBJECT_ID(N'dw.DIM_REPUESTO', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.DIM_REPUESTO(
            repuesto_key bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_DIM_REPUESTO PRIMARY KEY,
            repuesto_id_origen int NOT NULL,
            codigo_barras varchar(20) NOT NULL,
            nombre nvarchar(150) NOT NULL,
            categoria_id_origen int NOT NULL,
            categoria nvarchar(100) NOT NULL,
            estado varchar(20) NOT NULL,
            actualizado_dw datetime2(7) NOT NULL CONSTRAINT DF_dw_DIM_REPUESTO_Actualizado DEFAULT SYSUTCDATETIME(),
            CONSTRAINT UQ_dw_DIM_REPUESTO_Origen UNIQUE(repuesto_id_origen)
        );
    END;

    IF OBJECT_ID(N'dw.DIM_VENDEDOR', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.DIM_VENDEDOR(
            vendedor_key bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_DIM_VENDEDOR PRIMARY KEY,
            vendedor_id_origen int NOT NULL,
            nombre_usuario varchar(50) NOT NULL,
            rol varchar(50) NOT NULL,
            estado varchar(20) NOT NULL,
            actualizado_dw datetime2(7) NOT NULL CONSTRAINT DF_dw_DIM_VENDEDOR_Actualizado DEFAULT SYSUTCDATETIME(),
            CONSTRAINT UQ_dw_DIM_VENDEDOR_Origen UNIQUE(vendedor_id_origen)
        );
    END;

    IF OBJECT_ID(N'dw.FACT_VENTAS', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.FACT_VENTAS(
            venta_fact_key bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_FACT_VENTAS PRIMARY KEY,
            venta_id_origen int NOT NULL,
            detalle_venta_id_origen int NOT NULL,
            fecha_venta datetime2(7) NOT NULL,
            tiempo_key int NOT NULL,
            cliente_key bigint NOT NULL,
            vendedor_key bigint NOT NULL,
            repuesto_key bigint NOT NULL,
            cantidad int NOT NULL,
            precio_unitario decimal(10,2) NOT NULL,
            subtotal decimal(10,2) NOT NULL,
            tarifa_iva decimal(5,2) NULL,
            base_iva decimal(10,2) NULL,
            monto_iva decimal(10,2) NULL,
            cargado_dw datetime2(7) NOT NULL CONSTRAINT DF_dw_FACT_VENTAS_Cargado DEFAULT SYSUTCDATETIME(),
            CONSTRAINT UQ_dw_FACT_VENTAS_Origen UNIQUE(venta_id_origen,detalle_venta_id_origen),
            CONSTRAINT FK_dw_FACT_VENTAS_TIEMPO FOREIGN KEY(tiempo_key) REFERENCES dw.DIM_TIEMPO(tiempo_key),
            CONSTRAINT FK_dw_FACT_VENTAS_CLIENTE FOREIGN KEY(cliente_key) REFERENCES dw.DIM_CLIENTE(cliente_key),
            CONSTRAINT FK_dw_FACT_VENTAS_VENDEDOR FOREIGN KEY(vendedor_key) REFERENCES dw.DIM_VENDEDOR(vendedor_key),
            CONSTRAINT FK_dw_FACT_VENTAS_REPUESTO FOREIGN KEY(repuesto_key) REFERENCES dw.DIM_REPUESTO(repuesto_key),
            CONSTRAINT CK_dw_FACT_VENTAS_Cantidad CHECK(cantidad > 0),
            CONSTRAINT CK_dw_FACT_VENTAS_Importes CHECK(
                precio_unitario >= 0 AND subtotal >= 0
                AND (
                    (tarifa_iva IS NULL AND base_iva IS NULL AND monto_iva IS NULL)
                    OR (tarifa_iva IS NOT NULL AND base_iva IS NOT NULL AND monto_iva IS NOT NULL
                        AND tarifa_iva BETWEEN 0 AND 100 AND base_iva >= 0 AND monto_iva >= 0
                        AND base_iva + monto_iva = subtotal)
                )
            )
        );
    END;

    IF OBJECT_ID(N'dw.ETL_HISTORIAL', N'U') IS NULL
    BEGIN
        CREATE TABLE dw.ETL_HISTORIAL(
            etl_id bigint IDENTITY(1,1) NOT NULL CONSTRAINT PK_dw_ETL_HISTORIAL PRIMARY KEY,
            inicio datetime2(7) NOT NULL,
            fin datetime2(7) NULL,
            estado varchar(12) NOT NULL,
            filas_extraidas bigint NOT NULL CONSTRAINT DF_dw_ETL_Extraidas DEFAULT 0,
            tiempos_insertados bigint NOT NULL CONSTRAINT DF_dw_ETL_Tiempos DEFAULT 0,
            clientes_insertados bigint NOT NULL CONSTRAINT DF_dw_ETL_ClientesIns DEFAULT 0,
            clientes_actualizados bigint NOT NULL CONSTRAINT DF_dw_ETL_ClientesAct DEFAULT 0,
            repuestos_insertados bigint NOT NULL CONSTRAINT DF_dw_ETL_RepuestosIns DEFAULT 0,
            repuestos_actualizados bigint NOT NULL CONSTRAINT DF_dw_ETL_RepuestosAct DEFAULT 0,
            vendedores_insertados bigint NOT NULL CONSTRAINT DF_dw_ETL_VendedoresIns DEFAULT 0,
            vendedores_actualizados bigint NOT NULL CONSTRAINT DF_dw_ETL_VendedoresAct DEFAULT 0,
            hechos_insertados bigint NOT NULL CONSTRAINT DF_dw_ETL_HechosIns DEFAULT 0,
            hechos_actualizados bigint NOT NULL CONSTRAINT DF_dw_ETL_HechosAct DEFAULT 0,
            error nvarchar(2000) NULL,
            CONSTRAINT CK_dw_ETL_HISTORIAL_Estado CHECK(estado IN('EN_CURSO','COMPLETADO','ERROR'))
        );
    END;

    IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dw.FACT_VENTAS') AND name=N'IX_dw_FACT_VENTAS_Tiempo')
        CREATE INDEX IX_dw_FACT_VENTAS_Tiempo ON dw.FACT_VENTAS(tiempo_key) INCLUDE(cliente_key,vendedor_key,repuesto_key,cantidad,subtotal,base_iva,monto_iva);
    IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dw.FACT_VENTAS') AND name=N'IX_dw_FACT_VENTAS_Cliente')
        CREATE INDEX IX_dw_FACT_VENTAS_Cliente ON dw.FACT_VENTAS(cliente_key,tiempo_key) INCLUDE(cantidad,subtotal,base_iva,monto_iva);
    IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dw.FACT_VENTAS') AND name=N'IX_dw_FACT_VENTAS_Repuesto')
        CREATE INDEX IX_dw_FACT_VENTAS_Repuesto ON dw.FACT_VENTAS(repuesto_key,tiempo_key) INCLUDE(cantidad,subtotal);
    IF NOT EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dw.FACT_VENTAS') AND name=N'IX_dw_FACT_VENTAS_Vendedor')
        CREATE INDEX IX_dw_FACT_VENTAS_Vendedor ON dw.FACT_VENTAS(vendedor_key,tiempo_key) INCLUDE(cantidad,subtotal);

    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT > 0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
CREATE OR ALTER PROCEDURE dw.sp_CargarDataWarehouse
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @@TRANCOUNT > 0 THROW 52001,'El ETL debe ejecutarse fuera de otra transaccion.',1;

    DECLARE @lock int,@run_id bigint,@inicio datetime2(7)=SYSUTCDATETIME(),
        @error nvarchar(2000),@filas bigint=0,
        @fechaInicio date,@fechaFin date,
        @tiempos_ins bigint=0,@clientes_ins bigint=0,@clientes_act bigint=0,
        @repuestos_ins bigint=0,@repuestos_act bigint=0,
        @vendedores_ins bigint=0,@vendedores_act bigint=0,
        @hechos_ins bigint=0,@hechos_act bigint=0;

    EXEC @lock=sys.sp_getapplock @Resource=N'dw.sp_CargarDataWarehouse',
        @LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0;
    IF @lock < 0
    BEGIN
        INSERT dw.ETL_HISTORIAL(inicio,fin,estado,error)
        VALUES(@inicio,SYSUTCDATETIME(),'ERROR',N'Otra ejecución del ETL está activa; esta solicitud no inició.');
        THROW 52002,'Otra ejecución del ETL está activa. Intente después.',1;
    END;

    BEGIN TRY
        BEGIN TRANSACTION;
        INSERT dw.ETL_HISTORIAL(inicio,estado) VALUES(@inicio,'EN_CURSO');
        SET @run_id=SCOPE_IDENTITY();

        SELECT V.id_venta AS venta_id_origen,D.id_detalle_venta AS detalle_venta_id_origen,
            V.fecha AS fecha_venta,CONVERT(date,V.fecha) AS fecha,
            V.id_cliente AS cliente_id_origen,C.cedula,C.nombre AS cliente_nombre,C.telefono AS cliente_telefono,C.email AS cliente_email,
            V.id_vendedor AS vendedor_id_origen,U.nombre_usuario,U.rol,U.estado AS vendedor_estado,
            D.id_repuesto AS repuesto_id_origen,R.codigo_barras,R.nombre AS repuesto_nombre,
            R.id_categoria AS categoria_id_origen,K.nombre AS categoria_nombre,R.estado AS repuesto_estado,
            D.cantidad,D.precio_unitario,D.subtotal,D.tarifa_iva,D.base_iva,D.monto_iva,V.total AS venta_total,
            V.base_iva AS venta_base_iva,V.monto_iva AS venta_monto_iva
        INTO #Fuente
        FROM dbo.RP07_VENTAS V
        JOIN dbo.RP08_DETALLE_VENTAS D ON D.id_venta=V.id_venta
        LEFT JOIN dbo.RP05_CLIENTES C ON C.id_cliente=V.id_cliente
        LEFT JOIN dbo.RP13_USUARIOS U ON U.usuario_id=V.id_vendedor
        LEFT JOIN dbo.RP03_REPUESTOS R ON R.id_repuesto=D.id_repuesto
        LEFT JOIN dbo.RP01_CATEGORIAS K ON K.id_categoria=R.id_categoria
        WHERE V.estado='COMPLETADA';

        SELECT @filas=COUNT_BIG(*) FROM #Fuente;
        IF EXISTS(SELECT 1 FROM #Fuente WHERE fecha IS NULL OR cliente_id_origen IS NULL OR cedula IS NULL
            OR cliente_nombre IS NULL OR cliente_telefono IS NULL OR vendedor_id_origen IS NULL
            OR nombre_usuario IS NULL OR rol IS NULL OR vendedor_estado IS NULL
            OR repuesto_id_origen IS NULL OR codigo_barras IS NULL OR repuesto_nombre IS NULL
            OR categoria_id_origen IS NULL OR categoria_nombre IS NULL OR repuesto_estado IS NULL
            OR cantidad IS NULL OR cantidad<=0 OR precio_unitario IS NULL OR precio_unitario<0
            OR subtotal IS NULL OR subtotal<>CONVERT(decimal(10,2),cantidad*precio_unitario))
            THROW 52003,'La extracción encontró referencias incompletas o medidas inválidas; no se cargó el almacén.',1;

        IF EXISTS(SELECT 1 FROM #Fuente GROUP BY venta_id_origen,detalle_venta_id_origen HAVING COUNT_BIG(*)>1)
            THROW 52004,'La extracción contiene una línea de venta duplicada.',1;

        IF EXISTS(
            SELECT venta_id_origen
            FROM #Fuente
            GROUP BY venta_id_origen
            HAVING MIN(venta_total)<>SUM(subtotal)
                OR (MAX(venta_base_iva) IS NULL AND COUNT_BIG(base_iva)>0)
                OR (MAX(venta_base_iva) IS NOT NULL AND
                    (COUNT_BIG(base_iva)<>COUNT_BIG(*) OR MAX(venta_base_iva)<>SUM(base_iva)))
                OR (MAX(venta_monto_iva) IS NULL AND COUNT_BIG(monto_iva)>0)
                OR (MAX(venta_monto_iva) IS NOT NULL AND
                    (COUNT_BIG(monto_iva)<>COUNT_BIG(*) OR MAX(venta_monto_iva)<>SUM(monto_iva)))
        )
            THROW 52005,'El encabezado de una venta no coincide con sus detalles o su desglose fiscal.',1;

        IF EXISTS(SELECT 1 FROM #Fuente WHERE
            (tarifa_iva IS NULL AND (base_iva IS NOT NULL OR monto_iva IS NOT NULL))
            OR (tarifa_iva IS NOT NULL AND (tarifa_iva<0 OR tarifa_iva>100 OR base_iva IS NULL OR monto_iva IS NULL
                OR base_iva<0 OR monto_iva<0 OR base_iva+monto_iva<>subtotal)))
            THROW 52006,'La extracción encontró un desglose de IVA incompleto o inconsistente.',1;

        CREATE UNIQUE CLUSTERED INDEX UX_Fuente_Detalle ON #Fuente(venta_id_origen,detalle_venta_id_origen);

        SELECT @fechaInicio=MIN(fecha),@fechaFin=MAX(fecha) FROM #Fuente;
        SELECT @fechaInicio=CASE WHEN MIN(fecha) IS NOT NULL AND (@fechaInicio IS NULL OR MIN(fecha)<@fechaInicio)
                THEN MIN(fecha) ELSE @fechaInicio END,
            @fechaFin=CASE WHEN MAX(fecha) IS NOT NULL AND (@fechaFin IS NULL OR MAX(fecha)>@fechaFin)
                THEN MAX(fecha) ELSE @fechaFin END
        FROM dw.DIM_TIEMPO;

        IF @fechaInicio IS NOT NULL AND @fechaFin IS NOT NULL
        BEGIN
            ;WITH Calendario AS(
                SELECT @fechaInicio AS fecha
                UNION ALL
                SELECT DATEADD(day,1,fecha)
                FROM Calendario
                WHERE fecha<@fechaFin
            )
            SELECT fecha INTO #Fechas FROM Calendario OPTION(MAXRECURSION 0);

            INSERT dw.DIM_TIEMPO(fecha,anio,trimestre,mes,nombre_mes,dia,dia_anio,dia_semana,nombre_dia_semana,es_fin_semana,semana_iso)
            SELECT F.fecha,DATEPART(year,F.fecha),DATEPART(quarter,F.fecha),DATEPART(month,F.fecha),
            CASE DATEPART(month,F.fecha)
                WHEN 1 THEN N'enero' WHEN 2 THEN N'febrero' WHEN 3 THEN N'marzo' WHEN 4 THEN N'abril'
                WHEN 5 THEN N'mayo' WHEN 6 THEN N'junio' WHEN 7 THEN N'julio' WHEN 8 THEN N'agosto'
                WHEN 9 THEN N'septiembre' WHEN 10 THEN N'octubre' WHEN 11 THEN N'noviembre' ELSE N'diciembre'
            END,
                DATEPART(day,F.fecha),DATEPART(dayofyear,F.fecha),D.dia_semana,
                CASE D.dia_semana WHEN 1 THEN N'lunes' WHEN 2 THEN N'martes' WHEN 3 THEN N'miércoles'
                    WHEN 4 THEN N'jueves' WHEN 5 THEN N'viernes' WHEN 6 THEN N'sábado' ELSE N'domingo' END,
                CASE WHEN D.dia_semana IN(6,7) THEN 1 ELSE 0 END,
                DATEPART(ISO_WEEK,F.fecha)
            FROM #Fechas F
            CROSS APPLY(VALUES(CONVERT(tinyint,((DATEDIFF(day,CONVERT(date,'19000101',112),F.fecha)%7+7)%7)+1))) D(dia_semana)
            WHERE NOT EXISTS(SELECT 1 FROM dw.DIM_TIEMPO T WITH(UPDLOCK,HOLDLOCK) WHERE T.fecha=F.fecha);
            SET @tiempos_ins=@@ROWCOUNT;
        END;

        SELECT DISTINCT cliente_id_origen,cedula,cliente_nombre,cliente_telefono,cliente_email
        INTO #Clientes FROM #Fuente;
        UPDATE D SET cedula=S.cedula,nombre=S.cliente_nombre,telefono=S.cliente_telefono,email=S.cliente_email,
            actualizado_dw=SYSUTCDATETIME()
        FROM dw.DIM_CLIENTE D JOIN #Clientes S ON S.cliente_id_origen=D.cliente_id_origen
        WHERE EXISTS(SELECT D.cedula,D.nombre,D.telefono,D.email EXCEPT SELECT S.cedula,S.cliente_nombre,S.cliente_telefono,S.cliente_email);
        SET @clientes_act=@@ROWCOUNT;
        INSERT dw.DIM_CLIENTE(cliente_id_origen,cedula,nombre,telefono,email)
        SELECT S.cliente_id_origen,S.cedula,S.cliente_nombre,S.cliente_telefono,S.cliente_email
        FROM #Clientes S
        WHERE NOT EXISTS(SELECT 1 FROM dw.DIM_CLIENTE D WITH(UPDLOCK,HOLDLOCK) WHERE D.cliente_id_origen=S.cliente_id_origen);
        SET @clientes_ins=@@ROWCOUNT;

        SELECT DISTINCT repuesto_id_origen,codigo_barras,repuesto_nombre,categoria_id_origen,categoria_nombre,repuesto_estado
        INTO #Repuestos FROM #Fuente;
        UPDATE D SET codigo_barras=S.codigo_barras,nombre=S.repuesto_nombre,
            categoria_id_origen=S.categoria_id_origen,categoria=S.categoria_nombre,estado=S.repuesto_estado,
            actualizado_dw=SYSUTCDATETIME()
        FROM dw.DIM_REPUESTO D JOIN #Repuestos S ON S.repuesto_id_origen=D.repuesto_id_origen
        WHERE EXISTS(SELECT D.codigo_barras,D.nombre,D.categoria_id_origen,D.categoria,D.estado
            EXCEPT SELECT S.codigo_barras,S.repuesto_nombre,S.categoria_id_origen,S.categoria_nombre,S.repuesto_estado);
        SET @repuestos_act=@@ROWCOUNT;
        INSERT dw.DIM_REPUESTO(repuesto_id_origen,codigo_barras,nombre,categoria_id_origen,categoria,estado)
        SELECT S.repuesto_id_origen,S.codigo_barras,S.repuesto_nombre,S.categoria_id_origen,S.categoria_nombre,S.repuesto_estado
        FROM #Repuestos S
        WHERE NOT EXISTS(SELECT 1 FROM dw.DIM_REPUESTO D WITH(UPDLOCK,HOLDLOCK) WHERE D.repuesto_id_origen=S.repuesto_id_origen);
        SET @repuestos_ins=@@ROWCOUNT;

        SELECT DISTINCT vendedor_id_origen,nombre_usuario,rol,vendedor_estado INTO #Vendedores FROM #Fuente;
        UPDATE D SET nombre_usuario=S.nombre_usuario,rol=S.rol,estado=S.vendedor_estado,actualizado_dw=SYSUTCDATETIME()
        FROM dw.DIM_VENDEDOR D JOIN #Vendedores S ON S.vendedor_id_origen=D.vendedor_id_origen
        WHERE EXISTS(SELECT D.nombre_usuario,D.rol,D.estado EXCEPT SELECT S.nombre_usuario,S.rol,S.vendedor_estado);
        SET @vendedores_act=@@ROWCOUNT;
        INSERT dw.DIM_VENDEDOR(vendedor_id_origen,nombre_usuario,rol,estado)
        SELECT S.vendedor_id_origen,S.nombre_usuario,S.rol,S.vendedor_estado
        FROM #Vendedores S
        WHERE NOT EXISTS(SELECT 1 FROM dw.DIM_VENDEDOR D WITH(UPDLOCK,HOLDLOCK) WHERE D.vendedor_id_origen=S.vendedor_id_origen);
        SET @vendedores_ins=@@ROWCOUNT;

        UPDATE F SET
            fecha_venta=S.fecha_venta,tiempo_key=T.tiempo_key,cliente_key=C.cliente_key,
            vendedor_key=U.vendedor_key,repuesto_key=R.repuesto_key,cantidad=S.cantidad,
            precio_unitario=S.precio_unitario,subtotal=S.subtotal,tarifa_iva=S.tarifa_iva,
            base_iva=S.base_iva,monto_iva=S.monto_iva,cargado_dw=SYSUTCDATETIME()
        FROM dw.FACT_VENTAS F
        JOIN #Fuente S ON S.venta_id_origen=F.venta_id_origen AND S.detalle_venta_id_origen=F.detalle_venta_id_origen
        JOIN dw.DIM_TIEMPO T ON T.fecha=S.fecha
        JOIN dw.DIM_CLIENTE C ON C.cliente_id_origen=S.cliente_id_origen
        JOIN dw.DIM_VENDEDOR U ON U.vendedor_id_origen=S.vendedor_id_origen
        JOIN dw.DIM_REPUESTO R ON R.repuesto_id_origen=S.repuesto_id_origen
        WHERE EXISTS(SELECT F.fecha_venta,F.tiempo_key,F.cliente_key,F.vendedor_key,F.repuesto_key,F.cantidad,
                F.precio_unitario,F.subtotal,F.tarifa_iva,F.base_iva,F.monto_iva
            EXCEPT SELECT S.fecha_venta,T.tiempo_key,C.cliente_key,U.vendedor_key,R.repuesto_key,S.cantidad,
                S.precio_unitario,S.subtotal,S.tarifa_iva,S.base_iva,S.monto_iva);
        SET @hechos_act=@@ROWCOUNT;
        INSERT dw.FACT_VENTAS(venta_id_origen,detalle_venta_id_origen,fecha_venta,tiempo_key,cliente_key,vendedor_key,
            repuesto_key,cantidad,precio_unitario,subtotal,tarifa_iva,base_iva,monto_iva)
        SELECT S.venta_id_origen,S.detalle_venta_id_origen,S.fecha_venta,T.tiempo_key,C.cliente_key,U.vendedor_key,
            R.repuesto_key,S.cantidad,S.precio_unitario,S.subtotal,S.tarifa_iva,S.base_iva,S.monto_iva
        FROM #Fuente S
        JOIN dw.DIM_TIEMPO T ON T.fecha=S.fecha
        JOIN dw.DIM_CLIENTE C ON C.cliente_id_origen=S.cliente_id_origen
        JOIN dw.DIM_VENDEDOR U ON U.vendedor_id_origen=S.vendedor_id_origen
        JOIN dw.DIM_REPUESTO R ON R.repuesto_id_origen=S.repuesto_id_origen
        WHERE NOT EXISTS(SELECT 1 FROM dw.FACT_VENTAS F WITH(UPDLOCK,HOLDLOCK)
            WHERE F.venta_id_origen=S.venta_id_origen AND F.detalle_venta_id_origen=S.detalle_venta_id_origen);
        SET @hechos_ins=@@ROWCOUNT;

        UPDATE dw.ETL_HISTORIAL SET fin=SYSUTCDATETIME(),estado='COMPLETADO',
            filas_extraidas=@filas,tiempos_insertados=@tiempos_ins,
            clientes_insertados=@clientes_ins,clientes_actualizados=@clientes_act,
            repuestos_insertados=@repuestos_ins,repuestos_actualizados=@repuestos_act,
            vendedores_insertados=@vendedores_ins,vendedores_actualizados=@vendedores_act,
            hechos_insertados=@hechos_ins,hechos_actualizados=@hechos_act
        WHERE etl_id=@run_id;
        COMMIT TRANSACTION;
        EXEC sys.sp_releaseapplock @Resource=N'dw.sp_CargarDataWarehouse',@LockOwner='Session';

        SELECT @run_id AS etl_id,'COMPLETADO' AS estado,@filas AS filas_extraidas,
            @tiempos_ins AS tiempos_insertados,@clientes_ins AS clientes_insertados,@clientes_act AS clientes_actualizados,
            @repuestos_ins AS repuestos_insertados,@repuestos_act AS repuestos_actualizados,
            @vendedores_ins AS vendedores_insertados,@vendedores_act AS vendedores_actualizados,
            @hechos_ins AS hechos_insertados,@hechos_act AS hechos_actualizados;
    END TRY
    BEGIN CATCH
        SET @error=LEFT(ERROR_MESSAGE(),2000);
        IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
        INSERT dw.ETL_HISTORIAL(inicio,fin,estado,filas_extraidas,
            tiempos_insertados,clientes_insertados,clientes_actualizados,
            repuestos_insertados,repuestos_actualizados,vendedores_insertados,vendedores_actualizados,
            hechos_insertados,hechos_actualizados,error)
        VALUES(@inicio,SYSUTCDATETIME(),'ERROR',@filas,@tiempos_ins,@clientes_ins,@clientes_act,
            @repuestos_ins,@repuestos_act,@vendedores_ins,@vendedores_act,@hechos_ins,@hechos_act,@error);
        EXEC sys.sp_releaseapplock @Resource=N'dw.sp_CargarDataWarehouse',@LockOwner='Session';
        THROW;
    END CATCH;
END;
GO
