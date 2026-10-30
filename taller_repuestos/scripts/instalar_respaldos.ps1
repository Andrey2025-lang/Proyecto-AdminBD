param([string]$Server='localhost')
$ErrorActionPreference='Stop'
$name='Repuesto-RespaldoAutomatico'
if(Get-ScheduledTask -TaskName $name -ErrorAction SilentlyContinue){throw 'La tarea ya existe. Revísela antes de modificar su programación.'}
$runner=Join-Path $PSScriptRoot 'respaldo.ps1'
$sqlcmd=(Get-Command sqlcmd -ErrorAction Stop).Source
$user=[System.Security.Principal.WindowsIdentity]::GetCurrent().Name
$action=New-ScheduledTaskAction -Execute 'powershell.exe' -Argument "-NoProfile -NonInteractive -ExecutionPolicy Bypass -File `"$runner`" -Server `"$Server`""
$trigger=New-ScheduledTaskTrigger -Once -At (Get-Date).AddMinutes(1) -RepetitionInterval (New-TimeSpan -Minutes 30)
$principal=New-ScheduledTaskPrincipal -UserId $user -LogonType Interactive -RunLevel Limited
$settings=New-ScheduledTaskSettingsSet -StartWhenAvailable -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -MultipleInstances IgnoreNew -ExecutionTimeLimit (New-TimeSpan -Hours 1)
Register-ScheduledTask -TaskName $name -Action $action -Trigger $trigger -Principal $principal -Settings $settings -Description 'Respaldo completo cada 24 horas y registro cada 30 minutos. Requiere sesión de Windows abierta; no requiere abrir la aplicación.' -ErrorAction Stop | Out-Null
& $sqlcmd -S $Server -E -C -b -d TallerRepuestosDB -Q "UPDATE dbo.RP17_CONFIG_RESPALDOS SET programado=1,tarea=N'$name',actualizado=SYSDATETIME() WHERE id=1;"
if($LASTEXITCODE -ne 0){throw 'La tarea se creó, pero no se pudo registrar su configuración en la base.'}
Get-ScheduledTask -TaskName $name | Select-Object TaskName,State
