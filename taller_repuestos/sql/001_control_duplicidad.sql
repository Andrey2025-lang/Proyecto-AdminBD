-- Migración incremental. NO recrea la base ni elimina registros.
-- Ejecutar una vez (es seguro repetirla) después del SQL original.
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
    -- Bloquear cambios de categorías mientras se comprueban y crean las reglas.
    DECLARE @cantidad INT;
    SELECT @cantidad=COUNT(*) FROM dbo.RP01_CATEGORIAS WITH (TABLOCKX, HOLDLOCK);
    IF EXISTS (SELECT 1 FROM dbo.RP01_CATEGORIAS WHERE LEN(LTRIM(RTRIM(nombre)))=0)
        THROW 51010, 'Hay categorias sin nombre. Revisarlas antes de aplicar la migracion; no se modificaron datos.', 1;
    IF EXISTS (
        SELECT UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS
        FROM dbo.RP01_CATEGORIAS
        GROUP BY UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS
        HAVING COUNT(*)>1
    )
    BEGIN
        SELECT id_categoria,nombre FROM dbo.RP01_CATEGORIAS
        WHERE UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS IN (
            SELECT UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS
            FROM dbo.RP01_CATEGORIAS
            GROUP BY UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS HAVING COUNT(*)>1
        );
        THROW 51011, 'Hay categorias duplicadas. Revisarlas antes de aplicar la migracion; no se eliminaron registros.', 1;
    END;
    IF COL_LENGTH('dbo.RP01_CATEGORIAS','nombre_normalizado') IS NULL
        EXEC(N'ALTER TABLE dbo.RP01_CATEGORIAS ADD nombre_normalizado AS (UPPER(LTRIM(RTRIM(nombre))) COLLATE Modern_Spanish_CI_AS) PERSISTED;');
    IF NOT EXISTS (SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID('dbo.RP01_CATEGORIAS') AND name='UX_Categorias_NombreNormalizado')
        EXEC(N'CREATE UNIQUE INDEX UX_Categorias_NombreNormalizado ON dbo.RP01_CATEGORIAS(nombre_normalizado);');
    IF OBJECT_ID('dbo.CK_Categorias_NombreNoVacio','C') IS NULL
        ALTER TABLE dbo.RP01_CATEGORIAS WITH CHECK ADD CONSTRAINT CK_Categorias_NombreNoVacio CHECK(LEN(LTRIM(RTRIM(nombre)))>0);

    -- La clave de solicitud identifica un envío, no los datos del negocio.
    -- Dos solicitudes nuevas con el mismo contenido son operaciones distintas.
    IF OBJECT_ID('dbo.RP14_SOLICITUDES_OPERACION','U') IS NULL
    BEGIN
        CREATE TABLE dbo.RP14_SOLICITUDES_OPERACION (
            solicitud CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
            tipo VARCHAR(8) NOT NULL,
            huella CHAR(64) COLLATE Latin1_General_100_BIN2 NOT NULL,
            fecha DATETIME2 NOT NULL CONSTRAINT DF_Solicitudes_Fecha DEFAULT SYSDATETIME(),
            CONSTRAINT PK_Solicitudes_Operacion PRIMARY KEY(solicitud),
            CONSTRAINT CK_Solicitudes_Tipo CHECK(tipo IN ('sale','purchase'))
        );
    END;
    COMMIT TRANSACTION;
END TRY
BEGIN CATCH
    IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;
    THROW;
END CATCH;
GO
