<?php
require_once __DIR__.'/includes/auth.php';
require_once __DIR__.'/includes/permissions.php';
require_once __DIR__.'/includes/icons.php';
require_once __DIR__.'/includes/xlsx_simple_reader.php';

auth_require_admin();
permissions_require_menu('admin_supervisores_instalaciones');
auth_start();

$db = clear_db();
$APP_USER = auth_user();
$APP_ROLE = 'Administrador';
$ACTIVE = 'admin_supervisores_instalaciones';
$msg = '';
$msgType = 'ok';
$csrf = $_SESSION['clear_csrf_supervisores'] ?? '';
if ($csrf === '') {
    $csrf = bin2hex(random_bytes(24));
    $_SESSION['clear_csrf_supervisores'] = $csrf;
}

$objectsReady = (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_SUPERVISORES_INSTALACIONES',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_BATERIAS_CATALOGO',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES',N'V') IS NOT NULL AND OBJECT_ID(N'dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO',N'P') IS NOT NULL THEN 1 ELSE 0 END") === 1;

function csi_normalized_expression($placeholder = '?')
{
    return "UPPER(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(LTRIM(RTRIM(CONVERT(nvarchar(255),$placeholder))),N' ',N''),N'-',N''),N'_',N''),N'.',N''),N'/',N''))";
}

function csi_upsert(DB $db, array $record, $user)
{
    $keyExpression = csi_normalized_expression('?');
    $sql = "UPDATE dbo.CLEAR_SUPERVISORES_INSTALACIONES
               SET BATERIA=?,ZONA=?,SUPERVISOR=?,JEFE_ZONA=?,ACTIVO=1,
                   FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=?
             WHERE BATERIA_CLAVE=$keyExpression;
            IF @@ROWCOUNT=0
               INSERT INTO dbo.CLEAR_SUPERVISORES_INSTALACIONES
                   (BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,ACTIVO,USUARIO_ALTA,USUARIO_MODIFICACION)
               VALUES(?,?,?,?,1,?,?);";
    return $db->execute($sql, [
        $record['BATERIA'],$record['ZONA'],$record['SUPERVISOR'],$record['JEFE_ZONA'],$user,$record['BATERIA'],
        $record['BATERIA'],$record['ZONA'],$record['SUPERVISOR'],$record['JEFE_ZONA'],$user,$user,
    ]);
}

function csi_export_cell($value)
{
    $value = str_replace(["\r","\n"], ' ', (string)$value);
    return '"'.str_replace('"', '""', $value).'"';
}

if ($objectsReady && isset($_GET['export'])) {
    $exportRows = $db->all("SELECT BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,CASE WHEN ACTIVO=1 THEN N'Activo' ELSE N'Inactivo' END AS ESTADO FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES ORDER BY ZONA,BATERIA");
    audit_log('EXPORTACION','supervisores_instalaciones','Exportación Excel de '.count($exportRows).' asignaciones');
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Supervisores_Instalaciones_'.date('Ymd_His').'.csv"');
    echo "\xEF\xBB\xBF";
    echo "Batería;Zona;Supervisor;Jefe Zona;Estado\r\n";
    foreach ($exportRows as $row) {
        echo implode(';', [csi_export_cell($row['BATERIA']),csi_export_cell($row['ZONA']),csi_export_cell($row['SUPERVISOR']),csi_export_cell($row['JEFE_ZONA']),csi_export_cell($row['ESTADO'])])."\r\n";
    }
    exit;
}

if (isset($_GET['template'])) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="Plantilla_Supervisores_Instalaciones.csv"');
    echo "\xEF\xBB\xBFBatería;Zona;Supervisor;Jefe Zona\r\n";
    echo "CE 01;CED Zona I;Nombre supervisor;Nombre jefe de zona\r\n";
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $objectsReady) {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
        $msg = 'La sesión del formulario venció. Recargá la página e intentá nuevamente.';
        $msgType = 'err';
    } else {
        $action = (string)($_POST['accion'] ?? '');
        if ($action === 'guardar') {
            $id = (int)($_POST['id'] ?? 0);
            $record = [
                'BATERIA'=>trim((string)($_POST['bateria'] ?? '')),
                'ZONA'=>trim((string)($_POST['zona'] ?? '')),
                'SUPERVISOR'=>trim((string)($_POST['supervisor'] ?? '')),
                'JEFE_ZONA'=>trim((string)($_POST['jefe_zona'] ?? '')),
            ];
            if (in_array('', $record, true)) {
                $msg = 'Completá Batería, Zona, Supervisor y Jefe de zona.';
                $msgType = 'err';
            } elseif (max(array_map('strlen', $record)) > 255) {
                $msg = 'Uno de los textos supera los 255 caracteres permitidos.';
                $msgType = 'err';
            } else {
                if ($id > 0) {
                    $ok = $db->execute("UPDATE dbo.CLEAR_SUPERVISORES_INSTALACIONES SET BATERIA=?,ZONA=?,SUPERVISOR=?,JEFE_ZONA=?,FECHA_MODIFICACION=SYSDATETIME(),USUARIO_MODIFICACION=? WHERE ID=?", [$record['BATERIA'],$record['ZONA'],$record['SUPERVISOR'],$record['JEFE_ZONA'],$APP_USER,$id]);
                } else {
                    $ok = csi_upsert($db, $record, $APP_USER);
                }
                $msg = $ok ? ($id > 0 ? 'Asignación actualizada correctamente.' : 'Asignación guardada correctamente.') : 'No se pudo guardar. Verificá que la batería no esté duplicada. '.$db->error();
                $msgType = $ok ? 'ok' : 'err';
                if ($ok) audit_log($id > 0 ? 'SUPERVISOR_ACTUALIZADO' : 'SUPERVISOR_GUARDADO','supervisores_instalaciones',json_encode($record, JSON_UNESCAPED_UNICODE));
            }
        } elseif ($action === 'eliminar') {
            $id = (int)($_POST['id'] ?? 0);
            $battery = (string)$db->scalar("SELECT BATERIA FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ID=?", [$id]);
            $ok = $id > 0 && $db->execute("DELETE FROM dbo.CLEAR_SUPERVISORES_INSTALACIONES WHERE ID=?", [$id]);
            $msg = $ok ? 'Asignación eliminada.' : 'No se pudo eliminar la asignación.';
            $msgType = $ok ? 'ok' : 'err';
            if ($ok) audit_log('SUPERVISOR_ELIMINADO','supervisores_instalaciones',json_encode(['id'=>$id,'bateria'=>$battery], JSON_UNESCAPED_UNICODE));
        } elseif ($action === 'catalogo') {
            $ok = $db->execute("EXEC dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO");
            $msg = $ok ? 'Catálogo de baterías actualizado desde las tablas de instalaciones y pozos.' : 'No se pudo actualizar el catálogo. '.$db->error();
            $msgType = $ok ? 'ok' : 'err';
            if ($ok) audit_log('CATALOGO_BATERIAS_ACTUALIZADO','supervisores_instalaciones','Actualización manual del catálogo');
        } elseif ($action === 'importar') {
            $file = $_FILES['archivo'] ?? null;
            if (!$file || (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                $msg = 'Seleccioná un archivo .xlsx o .csv válido.';
                $msgType = 'err';
            } elseif ((int)($file['size'] ?? 0) > 8 * 1024 * 1024) {
                $msg = 'El archivo supera el máximo permitido de 8 MB.';
                $msgType = 'err';
            } else {
                try {
                    $records = clear_excel_supervisor_records(clear_excel_read_rows($file['tmp_name'], $file['name'], 5000));
                    $db->execute('SET XACT_ABORT ON; BEGIN TRANSACTION;');
                    $imported = 0;
                    foreach ($records as $record) {
                        if (!csi_upsert($db, $record, $APP_USER)) throw new RuntimeException($db->error() ?: 'Error al guardar la batería '.$record['BATERIA'].'.');
                        $imported++;
                    }
                    if (!$db->execute('COMMIT TRANSACTION;')) throw new RuntimeException($db->error() ?: 'No se pudo confirmar la importación.');
                    $db->execute("EXEC dbo.SP_CLEAR_ACTUALIZAR_BATERIAS_CATALOGO");
                    $msg = 'Importación completada: '.$imported.' asignaciones insertadas o actualizadas. No se eliminaron registros existentes.';
                    $msgType = 'ok';
                    audit_log('SUPERVISORES_IMPORTADOS','supervisores_instalaciones',json_encode(['archivo'=>$file['name'],'registros'=>$imported], JSON_UNESCAPED_UNICODE));
                } catch (Throwable $error) {
                    $db->execute('IF @@TRANCOUNT>0 ROLLBACK TRANSACTION;');
                    $msg = 'No se pudo importar: '.$error->getMessage();
                    $msgType = 'err';
                }
            }
        }
    }
}

if (!$objectsReady) {
    $msg = 'Falta instalar los objetos SQL. Ejecutá SQL/CLEAR_SUPERVISORES_INSTALACIONES.sql una sola vez en LC_MDB.';
    $msgType = 'err';
}

$rows = $objectsReady ? $db->all("SELECT ID,BATERIA,ZONA,SUPERVISOR,JEFE_ZONA,ACTIVO,CONVERT(varchar(19),FECHA_MODIFICACION,120) AS FECHA_MODIFICACION,USUARIO_MODIFICACION,EN_CATALOGO,ORIGENES FROM dbo.CLEAR_VW_SUPERVISORES_INSTALACIONES ORDER BY ZONA,BATERIA") : [];
$catalog = $objectsReady ? $db->all("SELECT BATERIA,MAX(ORIGEN) AS ORIGEN FROM dbo.CLEAR_BATERIAS_CATALOGO WHERE BATERIA IS NOT NULL AND LTRIM(RTRIM(BATERIA))<>N'' GROUP BY BATERIA ORDER BY BATERIA") : [];
$recordMap = [];
foreach ($rows as $row) $recordMap[(string)$row['ID']] = ['id'=>(int)$row['ID'],'bateria'=>$row['BATERIA'],'zona'=>$row['ZONA'],'supervisor'=>$row['SUPERVISOR'],'jefe_zona'=>$row['JEFE_ZONA']];
?>
<!doctype html>
<html lang="es"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Supervisores de instalaciones</title><link rel="stylesheet" href="assets/css/app.css?v=20260821-supervisores">
<style>
.csi-toolbar{display:flex;gap:9px;align-items:center;flex-wrap:wrap;margin-bottom:14px}.csi-search{min-width:280px;flex:1;max-width:520px;padding:10px 12px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text)}.csi-btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:40px;padding:0 13px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--petrol);font-weight:700;text-decoration:none;cursor:pointer}.csi-btn svg{width:16px;height:16px}.csi-btn.primary{background:var(--petrol);border-color:var(--petrol);color:#fff}.csi-btn.danger{color:var(--red-tx)}.csi-layout{display:grid;grid-template-columns:minmax(720px,1fr) 390px;gap:18px;align-items:start}.csi-card{background:var(--surface,#fff);border:1px solid var(--line);border-top:3px solid var(--petrol);border-radius:14px;padding:18px;position:sticky;top:14px}.csi-card h3{margin:0 0 5px}.csi-muted{color:var(--text-mut);font-size:12px}.csi-field{margin-top:12px}.csi-field label{display:block;font-size:12px;font-weight:700;margin-bottom:5px}.csi-field input{width:100%;padding:10px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text)}.csi-actions{display:flex;gap:8px;margin-top:15px}.csi-import{margin-top:20px;padding-top:17px;border-top:1px solid var(--line)}.csi-import input[type=file]{width:100%;font-size:12px;margin:8px 0 10px}.csi-msg{padding:11px 13px;border-radius:9px;margin-bottom:14px}.csi-msg.ok{background:var(--green-soft);color:var(--green-tx)}.csi-msg.err{background:var(--red-soft);color:var(--red-tx)}.csi-pill{display:inline-flex;padding:4px 8px;border-radius:999px;font-size:11px;font-weight:700}.csi-pill.ok{background:var(--green-soft);color:var(--green-tx)}.csi-pill.warn{background:#fff4d6;color:#8a5b00}.csi-row-actions{display:flex;gap:6px;white-space:nowrap}.csi-mini{min-height:33px;padding:0 9px;font-size:12px}.csi-table td{vertical-align:middle}.csi-empty{padding:24px;text-align:center;color:var(--text-mut)}@media(max-width:1180px){.csi-layout{grid-template-columns:1fr}.csi-card{position:static}}@media(max-width:700px){.csi-search{min-width:100%}}
</style></head><body><div class="app"><?php include __DIR__.'/includes/sidebar.php';?><main class="main"><?php include __DIR__.'/includes/topbar.php';?>
<div class="page__head"><div><h1 class="page__title">Supervisores de instalaciones</h1><div class="page__sub">Maestro de responsables por batería para futuras relaciones con alarmas y monitoreo</div></div><div class="page__live"><span class="dot"></span>Área admin</div></div>
<?php if ($msg): ?><div class="csi-msg <?php echo h($msgType); ?>"><?php echo h($msg); ?></div><?php endif; ?>
<div class="csi-toolbar"><input class="csi-search" id="csiSearch" placeholder="Buscar batería, zona, supervisor o jefe de zona..."><a class="csi-btn" href="?export=1"><?php echo icon('download'); ?> Exportar Excel</a><a class="csi-btn" href="?template=1"><?php echo icon('file'); ?> Descargar plantilla</a><form method="post"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="accion" value="catalogo"><button class="csi-btn" type="submit"><?php echo icon('grid'); ?> Actualizar catálogo</button></form></div>
<div class="csi-layout"><div class="tablewrap"><div class="tablescroll"><table class="grid csi-table" id="csiTable"><thead><tr><th>Batería</th><th>Zona</th><th>Supervisor</th><th>Jefe de zona</th><th>Relación</th><th>Modificación</th><th>Acciones</th></tr></thead><tbody>
<?php if (!$rows): ?><tr><td colspan="7" class="csi-empty">Todavía no hay asignaciones cargadas.</td></tr><?php endif; ?>
<?php foreach ($rows as $row): ?><tr data-search="<?php echo h(strtolower(implode(' ', [$row['BATERIA'],$row['ZONA'],$row['SUPERVISOR'],$row['JEFE_ZONA']]))); ?>"><td><b><?php echo h($row['BATERIA']); ?></b></td><td><?php echo h($row['ZONA']); ?></td><td><?php echo h($row['SUPERVISOR']); ?></td><td><?php echo h($row['JEFE_ZONA']); ?></td><td><?php if ((int)$row['EN_CATALOGO']): ?><span class="csi-pill ok" title="<?php echo h($row['ORIGENES']); ?>">Vinculada</span><?php else: ?><span class="csi-pill warn" title="No fue encontrada en las fuentes actuales">Solo maestro</span><?php endif; ?></td><td><?php echo h($row['FECHA_MODIFICACION']); ?><br><span class="csi-muted"><?php echo h($row['USUARIO_MODIFICACION']); ?></span></td><td><div class="csi-row-actions"><button class="csi-btn csi-mini" type="button" onclick="csiEdit(<?php echo (int)$row['ID']; ?>)"><?php echo icon('check'); ?> Editar</button><form method="post" onsubmit="return confirm('¿Eliminar la asignación de <?php echo h($row['BATERIA']); ?>?')"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="accion" value="eliminar"><input type="hidden" name="id" value="<?php echo (int)$row['ID']; ?>"><button class="csi-btn csi-mini danger" type="submit"><?php echo icon('trash'); ?> Eliminar</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>
<aside class="csi-card"><h3 id="csiFormTitle">Nueva asignación</h3><div class="csi-muted">La batería queda disponible mediante una clave normalizada para relacionarla con todas las grillas en mejoras futuras.</div><form method="post" id="csiForm"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="accion" value="guardar"><input type="hidden" name="id" id="csiId" value="0"><div class="csi-field"><label>Batería</label><input name="bateria" id="csiBateria" list="csiBaterias" required maxlength="255" autocomplete="off"><datalist id="csiBaterias"><?php foreach ($catalog as $item): ?><option value="<?php echo h($item['BATERIA']); ?>"><?php echo h($item['ORIGEN']); ?></option><?php endforeach; ?></datalist></div><div class="csi-field"><label>Zona</label><input name="zona" id="csiZona" required maxlength="150"></div><div class="csi-field"><label>Supervisor</label><input name="supervisor" id="csiSupervisor" required maxlength="200"></div><div class="csi-field"><label>Jefe de zona</label><input name="jefe_zona" id="csiJefe" required maxlength="200"></div><div class="csi-actions"><button class="csi-btn primary" type="submit"><?php echo icon('check'); ?> Guardar</button><button class="csi-btn" type="button" onclick="csiReset()">Cancelar</button></div></form>
<div class="csi-import"><h3>Importar Excel</h3><div class="csi-muted">Admite .xlsx y .csv. Debe contener Batería, Zona, Supervisor y Jefe Zona. Actualiza coincidencias y conserva las demás filas.</div><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="accion" value="importar"><input type="file" name="archivo" accept=".xlsx,.csv,text/csv,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" required><button class="csi-btn primary" type="submit"><?php echo icon('download'); ?> Importar archivo</button></form></div></aside></div>
</main></div>
<script>const CSI_RECORDS=<?php echo json_encode($recordMap, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>;function csiEdit(id){const r=CSI_RECORDS[String(id)];if(!r)return;document.getElementById('csiFormTitle').textContent='Editar '+r.bateria;document.getElementById('csiId').value=r.id;document.getElementById('csiBateria').value=r.bateria;document.getElementById('csiZona').value=r.zona;document.getElementById('csiSupervisor').value=r.supervisor;document.getElementById('csiJefe').value=r.jefe_zona;document.querySelector('.csi-card').scrollIntoView({behavior:'smooth',block:'start'});}function csiReset(){document.getElementById('csiForm').reset();document.getElementById('csiId').value='0';document.getElementById('csiFormTitle').textContent='Nueva asignación';}document.getElementById('csiSearch').addEventListener('input',function(){const q=this.value.trim().toLocaleLowerCase('es');document.querySelectorAll('#csiTable tbody tr[data-search]').forEach(function(row){row.style.display=!q||row.dataset.search.indexOf(q)>=0?'':'none';});});</script><script src="assets/js/app.js"></script></body></html>
