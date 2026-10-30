<?php
declare(strict_types=1);

function backupPanel(PDO $db): void
{
    $config=rows($db,'SELECT intervalo_manual_minutos,intervalo_completo_horas,intervalo_registro_minutos FROM dbo.RP17_CONFIG_RESPALDOS WHERE id=1')[0]??null;
    if(!$config)throw new RuntimeException('Falta la configuración de respaldos.');
    $history=rows($db,'SELECT TOP(8) id_respaldo,inicio,fin,tipo,estado,origen FROM dbo.RP16_RESPALDOS ORDER BY id_respaldo DESC');
    ?>
    <section class="panel-card mt-5" aria-label="Respaldos">
    <?php panelTitle('Respaldo de la información','Guarda una copia adicional sin modificar compras, ventas ni inventario.'); ?>
    <p class="help mb-4">Copia completa automática cada <?= (int)$config['intervalo_completo_horas'] ?> horas y copia de cambios cada <?= (int)$config['intervalo_registro_minutos'] ?> minutos. La tarea de Windows requiere la sesión abierta y el equipo encendido. Las copias anteriores no se eliminan.</p>
    <form method="post" action="index.php?page=inicio" data-backup-form>
    <?php csrf(); ?><input type="hidden" name="action" value="backup">
    <button class="button is-primary" type="submit">Crear respaldo</button>
    <p class="help mt-2" role="status">Espere al menos <?= (int)$config['intervalo_manual_minutos'] ?> minutos entre solicitudes manuales. Crear una copia no restaura ni deshace operaciones.</p>
    </form>
    <div class="table-container mt-4"><table class="table is-fullwidth"><thead><tr><th>Referencia</th><th>Inicio</th><th>Finalización</th><th>Tipo</th><th>Origen</th><th>Estado</th></tr></thead><tbody>
    <?php if(!$history)emptyRow(6,'Todavía no se han registrado respaldos.');
    foreach($history as $row): ?><tr><td>#<?= (int)$row['id_respaldo'] ?></td><td><?= e(dateLabel($row['inicio'])) ?></td><td><?= e(dateLabel($row['fin'])) ?></td><td><?= $row['tipo']==='COMPLETO'?'Completo':'Cambios' ?></td><td><?= $row['origen']==='MANUAL'?'Manual':'Automático' ?></td><td><?= e(['VERIFICADO'=>'Verificado','EN_CURSO'=>'En proceso','ERROR'=>'Requiere revisión'][$row['estado']]??'Requiere revisión') ?></td></tr><?php endforeach ?>
    </tbody></table></div><p class="help">Verificado significa que se comprobó el archivo; la prueba de recuperación se realiza por separado. Si una solicitud se interrumpe, actualice Inicio para consultar su resultado antes de reintentar.</p>
    </section>
    <?php
}
