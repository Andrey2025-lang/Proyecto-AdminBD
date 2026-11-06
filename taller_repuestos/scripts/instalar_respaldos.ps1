param([string]$Server='localhost')
$ErrorActionPreference='Stop'
$name='Repuesto-RespaldoAutomatico'
if(Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue){throw 'La tarea ya existe. Revísela antes de modificar su programación.'}
$runner=Join-Path $PSScriptRoot 'respaldo.ps1'
$sqlcmd=(Get-Command sqlcmd -ErrorAction Stop).Source
$preflight="SET NOCOUNT ON; IF OBJECT_ID(N'dbo.sp_RP_RespaldoAutomatico',N'P') IS NULL OR COL_LENGTH(N'dbo.RP16_RESPALDOS',N'origen') IS NULL OR COL_LENGTH(N'dbo.RP17_CONFIG_RESPALDOS',N'intervalo_manual_minutos') IS NULL OR COL_LENGTH(N'dbo.RP17_CONFIG_RESPALDOS',N'intervalo_completo_horas') IS NULL OR COL_LENGTH(N'dbo.RP17_CONFIG_RESPALDOS',N'intervalo_registro_minutos') IS NULL OR COL_LENGTH(N'dbo.RP17_CONFIG_RESPALDOS',N'base_cadena_full') IS NULL OR COL_LENGTH(N'dbo.RP17_CONFIG_RESPALDOS',N'base_cadena_archivo') IS NULL THROW 50110,'Aplique las migraciones 009 y 012 antes de instalar la tarea.',1; IF NOT EXISTS(SELECT 1 FROM dbo.RP17_CONFIG_RESPALDOS WHERE id=1) THROW 50111,'Falta la configuracion de respaldos.',1;"
& $sqlcmd -S $Server -E -C -b -d TallerRepuestosDB -Q $preflight
if($LASTEXITCODE -ne 0){throw 'No se cumplen los prerrequisitos de base de datos. No se creó la tarea.'}
$user=[System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$runner`" -Server `"$Server`""
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30)
$principal=New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Ejecuta el procedimiento de respaldos según el modelo de recuperación y los intervalos configurados. Requiere sesión de Windows abierta.' -ErrorAction Stop | Out-Null
& $sqlcmd -S $Server -E -C -b -d TallerRepuestosDB -Q "UPDATE dbo.RP17_CONFIG_RESPALDOS SET programado=1,tarea=N'$name',actualizado=SYSDATETIME() WHERE id=1;"
if($LASTEXITCODE -ne 0){throw 'La tarea se creó, pero no se pudo registrar su configuración en la base.'}
Get-ScheduledTask -TaskName $name | Select-Object TaskName,State
