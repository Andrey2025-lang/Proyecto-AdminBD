-- Refuerza el procedimiento de 009 sin cambiar el modelo de recuperacion.
-- No crea respaldos, no configura tareas y no elimina archivos.
USE TallerRepuestosDB;
GO
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','modelo_recuperacion_observado') IS NULL
    ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD modelo_recuperacion_observado varchar(12) NULL;
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','cambio_modelo_detectado') IS NULL
    ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD cambio_modelo_detectado datetime2 NULL;
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','base_cadena_full') IS NULL
    ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD base_cadena_full datetime2 NULL;
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','base_cadena_archivo') IS NULL
    ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD base_cadena_archivo nvarchar(512) NULL;
IF COL_LENGTH('dbo.RP17_CONFIG_RESPALDOS','base_cadena_checkpoint_lsn') IS NULL
    ALTER TABLE dbo.RP17_CONFIG_RESPALDOS ADD base_cadena_checkpoint_lsn decimal(25,0) NULL;
GO
CREATE OR ALTER PROCEDURE dbo.sp_RP_RespaldoAutomatico
    @forzar_completo bit=0,
    @manual bit=0
AS
BEGIN
    SET NOCOUNT ON;
    SET XACT_ABORT ON;
    IF @@TRANCOUNT>0 THROW 50100,'El respaldo debe ejecutarse fuera de una operacion de datos.',1;

    DECLARE @lock int,@id bigint,@tipo varchar(10),@archivo nvarchar(512),@ruta nvarchar(512),
        @manualMin int,@horas int,@registroMin int,@modelo varchar(12),@observado varchar(12),
        @cambio datetime2,@base datetime2,@baseArchivo nvarchar(512),@baseLsn decimal(25,0),
        @ahora datetime2=SYSDATETIME(),@error nvarchar(2000),@respaldoId int,
        @registrarError bit=1;
    DECLARE @omitir bit=0;

    EXEC @lock=sys.sp_getapplock @Resource=N'Repuesto.Respaldos',
        @LockMode='Exclusive',@LockOwner='Session',@LockTimeout=0;
    IF @lock<0 THROW 50101,'Ya hay un respaldo en proceso. Consulte el historial antes de reintentar.',1;

    BEGIN TRY
        SELECT @manualMin=intervalo_manual_minutos,@horas=intervalo_completo_horas,
            @registroMin=intervalo_registro_minutos,@observado=modelo_recuperacion_observado,
            @cambio=cambio_modelo_detectado,@base=base_cadena_full,
            @baseArchivo=base_cadena_archivo,@baseLsn=base_cadena_checkpoint_lsn
        FROM dbo.RP17_CONFIG_RESPALDOS WITH(UPDLOCK,HOLDLOCK)
        WHERE id=1;
        IF @manualMin IS NULL THROW 50102,'Falta la configuracion de respaldos.',1;
        IF @manualMin IS NULL OR @horas IS NULL OR @registroMin IS NULL
            OR @manualMin NOT BETWEEN 1 AND 1440 OR @horas NOT BETWEEN 1 AND 168
            OR @registroMin NOT BETWEEN 1 AND 1440
            THROW 50103,'Los intervalos configurados estan fuera de rango.',1;
        IF @manual=1 AND EXISTS(
            SELECT 1 FROM dbo.RP16_RESPALDOS
            WHERE origen='MANUAL' AND inicio>DATEADD(minute,-@manualMin,@ahora))
        BEGIN
            SET @registrarError=0;
            THROW 50104,'Espere el intervalo entre solicitudes manuales indicado en pantalla.',1;
        END;

        UPDATE dbo.RP16_RESPALDOS
        SET estado='ERROR',fin=@ahora,
            detalle=N'Ejecución interrumpida; comprobar el archivo por separado antes de utilizarlo.'
        WHERE estado='EN_CURSO';

        SELECT @modelo=recovery_model_desc
        FROM sys.databases WHERE database_id=DB_ID();
        IF @modelo NOT IN('SIMPLE','FULL','BULK_LOGGED')
            THROW 50105,'No se pudo determinar un modelo de recuperacion compatible.',1;

        IF @observado IS NULL OR @observado<>@modelo
        BEGIN
            SET @observado=@modelo;
            SET @cambio=@ahora;
            SET @base=NULL;
            SET @baseArchivo=NULL;
            SET @baseLsn=NULL;
            UPDATE dbo.RP17_CONFIG_RESPALDOS
            SET modelo_recuperacion_observado=@modelo,cambio_modelo_detectado=@ahora,
                base_cadena_full=NULL,base_cadena_archivo=NULL,base_cadena_checkpoint_lsn=NULL,
                actualizado=SYSDATETIME()
            WHERE id=1;
        END;

        IF @manual=1
            SET @tipo='COMPLETO';
        ELSE IF @modelo='SIMPLE'
        BEGIN
            IF @forzar_completo=1 OR NOT EXISTS(
                SELECT 1 FROM dbo.RP16_RESPALDOS
                WHERE tipo='COMPLETO' AND origen='AUTOMATICO' AND estado='VERIFICADO'
                    AND fin>DATEADD(hour,-@horas,@ahora))
                SET @tipo='COMPLETO';
            ELSE
                SET @tipo='OMITIDO';
        END
        ELSE IF @forzar_completo=1 OR NOT EXISTS(
            SELECT 1 FROM dbo.RP16_RESPALDOS
            WHERE tipo='COMPLETO' AND origen='AUTOMATICO' AND estado='VERIFICADO'
                AND fin>DATEADD(hour,-@horas,@ahora))
            SET @tipo='COMPLETO';
        ELSE IF @base IS NULL OR @baseArchivo IS NULL OR @baseLsn IS NULL OR @base<@cambio
            SET @tipo='COMPLETO';
        ELSE IF EXISTS(
            SELECT 1 FROM dbo.RP16_RESPALDOS
            WHERE tipo='REGISTRO' AND origen='AUTOMATICO' AND estado='VERIFICADO'
                AND fin>DATEADD(minute,-@registroMin,@ahora))
        BEGIN
            SET @tipo='REGISTRO';
            SET @omitir=1;
        END
        ELSE
            SET @tipo='REGISTRO';

        IF @tipo='OMITIDO'
        BEGIN
            INSERT dbo.RP16_RESPALDOS(inicio,fin,tipo,estado,archivo,detalle,origen)
            VALUES(@ahora,@ahora,'REGISTRO','OMITIDO',N'',
                CASE WHEN @modelo='SIMPLE'
                    THEN N'Modelo SIMPLE: ya existe un respaldo completo automático verificado dentro del intervalo configurado.'
                    ELSE N'El intervalo mínimo entre respaldos de registro aún no se cumple.' END,
                'AUTOMATICO');
            EXEC sys.sp_releaseapplock @Resource=N'Repuesto.Respaldos',@LockOwner='Session';
            RETURN;
        END;

        IF @tipo='REGISTRO'
        BEGIN
            SELECT TOP(1) @respaldoId=B.backup_set_id
            FROM msdb.dbo.backupset B
            JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
            WHERE B.database_name=DB_NAME() AND B.type='D' AND B.is_copy_only=0
                AND M.physical_device_name=@baseArchivo
                AND B.backup_finish_date=CONVERT(datetime,@base)
                AND B.checkpoint_lsn=@baseLsn
            ORDER BY B.backup_set_id DESC;
            IF @respaldoId IS NULL
                THROW 50106,'La copia completa base de la cadena ya no aparece en msdb; no se puede continuar con seguridad.',1;

            IF NOT EXISTS(SELECT 1 FROM msdb.dbo.backupmediafamily M
                WHERE M.media_set_id=(SELECT B.media_set_id FROM msdb.dbo.backupset B WHERE B.backup_set_id=@respaldoId)
                GROUP BY M.media_set_id HAVING COUNT_BIG(*)=1)
                THROW 50107,'La copia completa base utiliza varios medios; esta rutina solo puede verificar un archivo por copia.',1;
            RESTORE VERIFYONLY FROM DISK=@baseArchivo WITH CHECKSUM;

            CREATE TABLE #LogSets(
                backup_set_id int NOT NULL PRIMARY KEY,
                first_lsn decimal(25,0) NOT NULL,
                last_lsn decimal(25,0) NOT NULL,
                backup_finish_date datetime NOT NULL,
                media_count int NOT NULL
            );
            INSERT #LogSets(backup_set_id,first_lsn,last_lsn,backup_finish_date,media_count)
            SELECT B.backup_set_id,B.first_lsn,B.last_lsn,B.backup_finish_date,COUNT_BIG(M.family_sequence_number)
            FROM msdb.dbo.backupset B
            JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
            WHERE B.database_name=DB_NAME() AND B.type='L'
                AND B.backup_finish_date>CONVERT(datetime,@base)
            GROUP BY B.backup_set_id,B.first_lsn,B.last_lsn,B.backup_finish_date;

            IF EXISTS(SELECT 1 FROM #LogSets WHERE media_count<>1)
                THROW 50108,'La cadena contiene respaldos de registro en varios medios que esta rutina no puede verificar individualmente.',1;

            IF EXISTS(
                SELECT 1
                FROM #LogSets L
                JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=(
                    SELECT B.media_set_id FROM msdb.dbo.backupset B WHERE B.backup_set_id=L.backup_set_id)
                WHERE NOT EXISTS(
                    SELECT 1 FROM dbo.RP16_RESPALDOS R
                    WHERE R.tipo='REGISTRO' AND R.origen='AUTOMATICO' AND R.estado='VERIFICADO'
                        AND R.archivo=M.physical_device_name))
                THROW 50109,'La cadena contiene un respaldo de registro externo o no registrado. No se puede garantizar su disponibilidad; revise el historial y los archivos.',1;

            IF EXISTS(
                SELECT 1 FROM #LogSets L
                CROSS APPLY(SELECT MAX(P.last_lsn) AS lsn_previo FROM #LogSets P
                    WHERE P.last_lsn<L.last_lsn OR (P.last_lsn=L.last_lsn AND P.backup_set_id<L.backup_set_id)) P
                WHERE L.first_lsn>COALESCE(P.lsn_previo,@baseLsn))
                THROW 50110,'La cadena de registros tiene un salto de LSN; se requiere investigar y reestablecer un punto de respaldo seguro.',1;

            IF EXISTS(
                SELECT 1 FROM dbo.RP16_RESPALDOS R
                WHERE R.tipo='REGISTRO' AND R.origen='AUTOMATICO' AND R.estado='VERIFICADO'
                    AND R.fin> @base
                    AND NOT EXISTS(
                        SELECT 1 FROM msdb.dbo.backupset B
                        JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
                        WHERE B.backup_set_id IN(SELECT backup_set_id FROM #LogSets)
                            AND M.physical_device_name=R.archivo))
                THROW 50111,'El historial de la aplicacion contiene un respaldo de registro ausente del historial del sistema.',1;

            IF @omitir=1
            BEGIN
                INSERT dbo.RP16_RESPALDOS(inicio,fin,tipo,estado,archivo,detalle,origen)
                VALUES(@ahora,@ahora,'REGISTRO','OMITIDO',N'',
                    N'El intervalo mínimo no se cumple; se comprobó la continuidad registrada de la cadena.','AUTOMATICO');
                EXEC sys.sp_releaseapplock @Resource=N'Repuesto.Respaldos',@LockOwner='Session';
                RETURN;
            END;

            DECLARE log_files CURSOR LOCAL FAST_FORWARD FOR
                SELECT DISTINCT M.physical_device_name
                FROM #LogSets L
                JOIN msdb.dbo.backupset B ON B.backup_set_id=L.backup_set_id
                JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
                ORDER BY M.physical_device_name;
            OPEN log_files;
            FETCH NEXT FROM log_files INTO @ruta;
            WHILE @@FETCH_STATUS=0
            BEGIN
                RESTORE VERIFYONLY FROM DISK=@ruta WITH CHECKSUM;
                FETCH NEXT FROM log_files INTO @ruta;
            END;
            CLOSE log_files;
            DEALLOCATE log_files;
        END;

        SET @ruta=CONVERT(nvarchar(512),SERVERPROPERTY('InstanceDefaultBackupPath'));
        IF @ruta IS NULL THROW 50112,'No se encontro la carpeta de respaldos de la instancia.',1;
        IF RIGHT(@ruta,1) NOT IN('\','/') SET @ruta+='\';
        SET @archivo=@ruta+N'Repuesto_'+CASE WHEN @manual=1 THEN N'MANUAL_' ELSE N'AUTO_' END
            +@tipo+N'_'+CONVERT(char(8),GETDATE(),112)+N'_'+CONVERT(nvarchar(36),NEWID())
            +CASE WHEN @tipo='COMPLETO' THEN N'.bak' ELSE N'.trn' END;

        INSERT dbo.RP16_RESPALDOS(tipo,estado,archivo,origen)
        VALUES(@tipo,'EN_CURSO',@archivo,CASE WHEN @manual=1 THEN 'MANUAL' ELSE 'AUTOMATICO' END);
        SET @id=SCOPE_IDENTITY();

        IF @manual=1
            BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH COPY_ONLY,CHECKSUM,COMPRESSION;
        ELSE IF @tipo='COMPLETO'
            BACKUP DATABASE TallerRepuestosDB TO DISK=@archivo WITH CHECKSUM,COMPRESSION;
        ELSE
            BACKUP LOG TallerRepuestosDB TO DISK=@archivo WITH CHECKSUM,COMPRESSION;

        RESTORE VERIFYONLY FROM DISK=@archivo WITH CHECKSUM;
        IF @manual=0 AND @tipo='COMPLETO' AND @modelo<>'SIMPLE'
        BEGIN
            SELECT TOP(1) @base=CONVERT(datetime2,B.backup_finish_date),
                @baseLsn=B.checkpoint_lsn
            FROM msdb.dbo.backupset B
            JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
            WHERE B.database_name=DB_NAME() AND B.type='D' AND B.is_copy_only=0
                AND M.physical_device_name=@archivo
            ORDER BY B.backup_finish_date DESC,B.backup_set_id DESC;
            IF @base IS NULL OR @baseLsn IS NULL
                THROW 50113,'La copia completa no aparece en msdb; no se puede confirmar su estado ni la cadena.',1;
            IF NOT EXISTS(SELECT 1 FROM msdb.dbo.backupset B
                JOIN msdb.dbo.backupmediafamily M ON M.media_set_id=B.media_set_id
                WHERE B.database_name=DB_NAME() AND B.type='D' AND B.is_copy_only=0
                    AND M.physical_device_name=@archivo
                GROUP BY B.media_set_id HAVING COUNT_BIG(*)=1)
                THROW 50114,'La copia completa utiliza varios medios que esta rutina no puede validar.',1;
        END;

        SET @ahora=SYSDATETIME();
        UPDATE dbo.RP16_RESPALDOS SET estado='VERIFICADO',fin=@ahora,
            detalle=N'Archivo comprobado con VERIFYONLY. La prueba de restauración se realiza por separado.'
        WHERE id_respaldo=@id;
        IF @@ROWCOUNT<>1 THROW 50115,'No se pudo actualizar exactamente el registro del respaldo.',1;

        IF @manual=0 AND @tipo='COMPLETO' AND @modelo<>'SIMPLE'
            UPDATE dbo.RP17_CONFIG_RESPALDOS
            SET base_cadena_full=CASE WHEN base_cadena_full IS NULL OR base_cadena_full<cambio_modelo_detectado
                    THEN @base ELSE base_cadena_full END,
                base_cadena_archivo=CASE WHEN base_cadena_full IS NULL OR base_cadena_full<cambio_modelo_detectado
                    THEN @archivo ELSE base_cadena_archivo END,
                base_cadena_checkpoint_lsn=CASE WHEN base_cadena_full IS NULL OR base_cadena_full<cambio_modelo_detectado
                    THEN @baseLsn ELSE base_cadena_checkpoint_lsn END,
                actualizado=SYSDATETIME()
            WHERE id=1;

        EXEC sys.sp_releaseapplock @Resource=N'Repuesto.Respaldos',@LockOwner='Session';
    END TRY
    BEGIN CATCH
        SET @error=LEFT(ERROR_MESSAGE(),2000);
        IF @id IS NOT NULL
            UPDATE dbo.RP16_RESPALDOS SET estado='ERROR',fin=SYSDATETIME(),detalle=@error
            WHERE id_respaldo=@id;
        ELSE IF @registrarError=1
            INSERT dbo.RP16_RESPALDOS(inicio,fin,tipo,estado,archivo,detalle,origen)
            VALUES(SYSDATETIME(),SYSDATETIME(),COALESCE(@tipo,'COMPLETO'),'ERROR',N'',@error,
                CASE WHEN @manual=1 THEN 'MANUAL' ELSE 'AUTOMATICO' END);
        IF CURSOR_STATUS('local','log_files')>=0 CLOSE log_files;
        IF CURSOR_STATUS('local','log_files')>-3 DEALLOCATE log_files;
        EXEC sys.sp_releaseapplock @Resource=N'Repuesto.Respaldos',@LockOwner='Session';
        THROW;
    END CATCH;
END;
GO
