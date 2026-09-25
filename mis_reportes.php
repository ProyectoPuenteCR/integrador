<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/icons.php';
require_once __DIR__ . '/includes/permissions.php';
require_once __DIR__ . '/includes/reporting.php';

auth_require();
permissions_require_menu('mis_reportes');

$APP_USER = auth_user();
$APP_ROLE = auth_es_admin() ? 'Administrador' : 'Usuario';
$ACTIVE = 'mis_reportes';

$tablesReady = report_ensure_tables();
$db = clear_db();
$catalog = report_screen_catalog();
$screenDefs = report_screen_picker_definitions_for_user($APP_USER);
$requestedScreen = trim((string)($_GET['pantalla'] ?? ''));
if ($requestedScreen !== '' && !isset($screenDefs[$requestedScreen])) $requestedScreen = '';

$rows = [];
$logs = [];
$programmed = 0;
$activeCount = 0;
$executedToday = 0;
if ($db->ok() && $tablesReady) {
    $rows = $db->all(
        "SELECT * FROM dbo.CLEAR_REPORT_SCHEDULES WHERE USUARIO_CARGA=? ORDER BY ACTIVO DESC,NOMBRE,ID DESC",
        [$APP_USER]
    );
    $programmed = count($rows);
    foreach ($rows as $row) if ((int)($row['ACTIVO'] ?? 0) === 1) $activeCount++;
    $executedToday = (int)($db->scalar(
        "SELECT COUNT(*) FROM dbo.CLEAR_REPORT_LOG L INNER JOIN dbo.CLEAR_REPORT_SCHEDULES S ON S.ID=L.ID_REPORTE " .
        "WHERE S.USUARIO_CARGA=? AND L.ESTADO=N'ENVIADO' AND L.FECHA_EJECUCION>=CONVERT(date,SYSDATETIME())",
        [$APP_USER]
    ) ?? 0);
    $logs = $db->all(
        "SELECT TOP 20 L.FECHA_EJECUCION,L.ESTADO,L.DESTINATARIOS,L.ERROR,S.NOMBRE " .
        "FROM dbo.CLEAR_REPORT_LOG L INNER JOIN dbo.CLEAR_REPORT_SCHEDULES S ON S.ID=L.ID_REPORTE " .
        "WHERE S.USUARIO_CARGA=? ORDER BY L.FECHA_EJECUCION DESC",
        [$APP_USER]
    );
}

$schedulerLast = $tablesReady ? report_setting('SCHEDULER_LAST_RUN','') : '';
$schedulerTs = $schedulerLast !== '' ? strtotime($schedulerLast) : false;
$schedulerOk = $schedulerTs !== false && (time() - $schedulerTs) <= 300;

function mr_freq_label($value, $days = '')
{
    $value = strtolower(trim((string)$value));
    if ($value === 'daily') return 'Diario';
    if ($value === 'weekdays') return 'Lunes a viernes';
    if ($value === 'monthly') return 'Mensual · día 1';
    if ($value === 'weekly' || $value === 'custom') {
        $names = [1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'];
        $out = [];
        foreach (array_filter(array_map('intval', explode(',', (string)$days))) as $day) {
            if (isset($names[$day])) $out[] = $names[$day];
        }
        return ($value === 'weekly' ? 'Semanal' : 'Personalizado') . ($out ? ' · ' . implode(', ', $out) : '');
    }
    return $value !== '' ? $value : '—';
}
function mr_num($value)
{
    return number_format((int)$value,0,',','.');
}
function mr_datetime($value)
{
    $value = trim((string)$value);
    if ($value === '') return 'Pendiente';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}
function mr_screen_summary($value, $catalog)
{
    $labels = [];
    foreach (report_parse_screens($value) as $key) $labels[] = $catalog[$key] ?? $key;
    if (count($labels) > 3) return implode(', ', array_slice($labels, 0, 3)) . ' +' . (count($labels) - 3);
    return implode(', ', $labels);
}
?><!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mis Reportes · CLEAR Plataforma</title>
<link rel="stylesheet" href="assets/css/app.css?v=20260925-mis-reportes-1">
<style>
.mrKpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:16px}
.mrKpi{background:var(--surface,#fff);border:1px solid var(--line-mid);border-radius:14px;padding:15px 17px;display:flex;align-items:center;gap:13px}
.mrKpi__icon{width:42px;height:42px;border-radius:50%;display:grid;place-items:center;background:var(--petrol-soft);color:var(--petrol)}
.mrKpi__icon svg{width:20px;height:20px}.mrKpi b{display:block;font-family:var(--font-head);font-size:25px;color:var(--petrol);line-height:1}
.mrKpi span{display:block;margin-top:4px;color:var(--text-mut);font-size:11px;text-transform:uppercase;letter-spacing:.55px}
.mrPanel{background:var(--surface,#fff);border:1px solid var(--line-mid);border-top:3px solid var(--petrol);border-radius:16px;padding:18px;margin-bottom:16px}
.mrPanel__head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;margin-bottom:16px}
.mrPanel__head h2{margin:0;font-family:var(--font-head);font-size:21px;color:var(--text)}.mrPanel__head p{margin:4px 0 0;color:var(--text-mut);font-size:12px}
.mrGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.mrField.full{grid-column:1/-1}
.mrField label{display:block;margin:0 0 5px;font-size:11px;font-weight:800;color:var(--text-soft);text-transform:uppercase;letter-spacing:.55px}
.mrField input,.mrField select,.mrField textarea{width:100%;min-height:42px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text);font:inherit;padding:9px 11px;outline:0}
.mrField input:focus,.mrField select:focus,.mrField textarea:focus{border-color:var(--petrol);box-shadow:0 0 0 3px var(--petrol-soft)}
.mrField textarea{min-height:82px;resize:vertical}.mrReadOnly{background:var(--bg,#f6f9fa)!important;color:var(--text-soft)!important}
.mrDays{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:7px}.mrDay{border:1px solid var(--line-mid);border-radius:9px;padding:8px 6px;background:var(--bg,#f8fbfc);text-align:center;font-size:11px;font-weight:700;color:var(--text-soft)}
.mrDay input{display:block;margin:0 auto 5px}
.mrSectionTitle{margin:17px 0 9px;padding-top:15px;border-top:1px solid var(--line);font-size:13px;font-weight:800;color:var(--petrol)}
.mrScreenTools{display:flex;gap:8px;margin-bottom:8px}.mrScreenTools input{flex:1;border:1px solid var(--line-mid);border-radius:9px;padding:9px 11px}
.mrScreens{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px;max-height:360px;overflow:auto}
.mrScreen{display:flex;align-items:center;gap:9px;border:1px solid var(--line-mid);border-radius:9px;padding:9px 10px;background:var(--surface,#fff);cursor:pointer}
.mrScreen:hover{background:var(--petrol-soft)}.mrScreen span{flex:1;font-size:12px;font-weight:650;color:var(--text)}.mrScreen small{font-size:9.5px;color:var(--text-mut);text-align:right}
.mrActions{display:flex;gap:9px;flex-wrap:wrap;margin-top:16px}.mrBtn{min-height:40px;border:1px solid var(--line-mid);border-radius:9px;background:var(--surface,#fff);color:var(--text);font:inherit;padding:0 14px;cursor:pointer}
.mrBtn.primary{background:var(--petrol);border-color:var(--petrol);color:#fff;font-weight:700}.mrBtn.danger{color:#b42318;border-color:#f1c8c3}.mrBtn:disabled{opacity:.55;cursor:wait}
.mrNotice{padding:10px 12px;border-radius:9px;margin-bottom:14px;font-size:12px}.mrNotice.ok{background:#edf9f3;color:#176b4b;border:1px solid #c9ead9}.mrNotice.warn{background:#fff8e8;color:#8a5a00;border:1px solid #f2dfaa}.mrNotice.err{background:#fff1ef;color:#a62a22;border:1px solid #f0c9c5}
.mrTableWrap{overflow:auto}.mrTable{width:100%;border-collapse:collapse;font-size:12px}.mrTable th,.mrTable td{padding:10px 9px;border-bottom:1px solid var(--line);text-align:left;vertical-align:middle}.mrTable th{font-size:10px;color:var(--text-mut);text-transform:uppercase;letter-spacing:.45px;background:var(--bg,#f7fafb)}
.mrTable tbody tr:hover{background:var(--petrol-soft)}.mrBadge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:4px 8px;font-size:10px;font-weight:700;background:#edf9f3;color:#176b4b}.mrBadge.off{background:#fff6dc;color:#8a6200}.mrBadge.err{background:#fff0ef;color:#b42318}
.mrRowActions{display:flex;gap:5px;flex-wrap:wrap}.mrIconBtn{width:34px;height:32px;border:1px solid var(--line-mid);border-radius:8px;background:#fff;display:grid;place-items:center;cursor:pointer;color:var(--petrol)}.mrIconBtn svg{width:14px;height:14px}.mrIconBtn.danger{color:#c72f26;background:#fff5f4}
.mrSearch{min-width:250px;border:1px solid var(--line-mid);border-radius:9px;padding:9px 11px}
.mrMuted{font-size:11px;color:var(--text-mut)}.mrName{font-weight:750;color:var(--petrol)}
@media(max-width:1050px){.mrGrid,.mrScreens{grid-template-columns:1fr}.mrKpis{grid-template-columns:1fr 1fr 1fr}}
@media(max-width:700px){.mrKpis{grid-template-columns:1fr}.mrDays{grid-template-columns:repeat(4,1fr)}.mrPanel__head{flex-direction:column}.mrSearch{width:100%;min-width:0}}
</style>
</head>
<body>
<div class="app">
<?php include __DIR__ . '/includes/sidebar.php'; ?>
<main class="main">
<?php include __DIR__ . '/includes/topbar.php'; ?>

<div class="page__head">
  <div>
    <h1 class="page__title">Mis Reportes</h1>
    <div class="page__sub">Configurá, programá y gestioná tus informes personales.</div>
  </div>
  <div class="page__live"><span class="dot"></span><?php echo $schedulerOk ? 'Programador activo' : 'Revisar programador'; ?></div>
</div>

<?php if(!$tablesReady): ?>
  <div class="mrNotice err">El módulo de reportes no está instalado completamente en SQL Server. Avisá al administrador.</div>
<?php elseif(!$schedulerOk): ?>
  <div class="mrNotice warn"><b>Programador sin actividad reciente.</b> Podés guardar informes, pero los envíos automáticos dependen del ejecutor de reportes. Última comprobación: <?php echo h($schedulerLast ?: 'sin registro'); ?>.</div>
<?php endif; ?>
<div id="mrMessage"></div>

<section class="mrKpis">
  <article class="mrKpi"><div class="mrKpi__icon"><?php echo icon('file'); ?></div><div><b><?php echo mr_num($programmed); ?></b><span>Programados</span></div></article>
  <article class="mrKpi"><div class="mrKpi__icon"><?php echo icon('check'); ?></div><div><b><?php echo mr_num($activeCount); ?></b><span>Activos</span></div></article>
  <article class="mrKpi"><div class="mrKpi__icon"><?php echo icon('history'); ?></div><div><b><?php echo mr_num($executedToday); ?></b><span>Ejecutados hoy</span></div></article>
</section>

<section class="mrPanel">
  <div class="mrPanel__head">
    <div><h2 id="mrFormTitle">Nuevo informe programado</h2><p>Seleccioná las pantallas que tenés habilitadas y definí cuándo querés recibir el PDF.</p></div>
  </div>
  <form id="mrForm">
    <input type="hidden" name="action" value="save_schedule">
    <input type="hidden" name="id" id="mrId">
    <div class="mrGrid">
      <div class="mrField"><label>Nombre del informe</label><input required maxlength="180" name="nombre" id="mrNombre" placeholder="Ej. Reporte semanal de pozos"></div>
      <div class="mrField"><label>Destinatarios</label><input required name="destinatarios" id="mrDestinatarios" placeholder="correo1@empresa.com; correo2@empresa.com"></div>
      <div class="mrField"><label>CC</label><input name="cc" id="mrCc" placeholder="Opcional"></div>
      <div class="mrField"><label>CCO</label><input name="cco" id="mrCco" placeholder="Opcional"></div>
      <div class="mrField full"><label>Asunto</label><input required maxlength="250" name="asunto" id="mrAsunto" value="CLEAR Petroleum | Reporte operativo"></div>
      <div class="mrField full"><label>Cuerpo del correo</label><textarea name="cuerpo" id="mrCuerpo">Se adjunta mi reporte programado de CLEAR Petroleum.</textarea></div>
      <div class="mrField"><label>Formato</label><input class="mrReadOnly" value="PDF" readonly></div>
      <div class="mrField"><label>Frecuencia</label><select name="frecuencia" id="mrFrecuencia"><option value="daily">Diario</option><option value="weekdays">Lunes a viernes</option><option value="weekly">Semanal</option><option value="custom">Días seleccionados</option><option value="monthly">Primer día del mes</option></select></div>
      <div class="mrField"><label>Horarios</label><input required name="horarios" id="mrHorarios" value="08:00" placeholder="08:00 o 08:00,20:00"></div>
      <div class="mrField"><label>Zona horaria</label><input name="zona_horaria" id="mrZona" value="America/Argentina/Buenos_Aires"></div>
      <div class="mrField full"><label>Estado</label><select name="activo" id="mrActivo"><option value="1">Activo</option><option value="0">Pausado</option></select></div>
      <div class="mrField full">
        <label>Días de semana</label>
        <div class="mrDays"><?php foreach([1=>'Lun',2=>'Mar',3=>'Mié',4=>'Jue',5=>'Vie',6=>'Sáb',7=>'Dom'] as $n=>$d): ?><label class="mrDay"><input type="checkbox" name="dias[]" value="<?php echo $n; ?>"><?php echo h($d); ?></label><?php endforeach; ?></div>
      </div>
    </div>

    <div class="mrSectionTitle">Pantallas incluidas</div>
    <div class="mrScreenTools">
      <input type="search" id="mrScreenSearch" placeholder="Buscar pantalla...">
      <button type="button" class="mrBtn" id="mrSelectAll">Marcar visibles</button>
      <button type="button" class="mrBtn" id="mrClearAll">Desmarcar</button>
    </div>
    <div class="mrScreens">
      <?php
      $defaultKey = $requestedScreen !== '' ? $requestedScreen : (isset($screenDefs['dashboard_inst_sup']) ? 'dashboard_inst_sup' : (string)array_key_first($screenDefs));
      foreach($screenDefs as $screenKey=>$def):
        $label=(string)($def['label']??$screenKey);
        $group=(string)($def['group']??'Otros');
      ?>
      <label class="mrScreen" data-search="<?php echo h(strtolower($label.' '.$group)); ?>">
        <input type="checkbox" name="pantallas[]" value="<?php echo h($screenKey); ?>" <?php echo $screenKey===$defaultKey?'checked':''; ?>>
        <span><?php echo h($label); ?></span><small><?php echo h($group); ?></small>
      </label>
      <?php endforeach; ?>
    </div>
    <div class="mrMuted" style="margin-top:7px">Solo aparecen pantallas habilitadas para tu usuario. Si perdés acceso a una pantalla, el informe no la podrá ejecutar.</div>

    <div class="mrActions">
      <button class="mrBtn primary" type="submit"><?php echo icon('check'); ?> Guardar programación</button>
      <button class="mrBtn" type="button" id="mrReset">Limpiar</button>
    </div>
  </form>
</section>

<section class="mrPanel">
  <div class="mrPanel__head">
    <div><h2>Mis programaciones</h2><p>Solo vos podés editar, duplicar, ejecutar o eliminar tus programaciones.</p></div>
    <input class="mrSearch" type="search" id="mrScheduleSearch" placeholder="Buscar informes...">
  </div>
  <div class="mrTableWrap">
    <table class="mrTable" id="mrScheduleTable">
      <thead><tr><th>Nombre</th><th>Frecuencia</th><th>Próxima ejecución</th><th>Estado</th><th>Pantallas</th><th>Último estado</th><th>Acciones</th></tr></thead>
      <tbody>
      <?php if(!$rows): ?><tr><td colspan="7" class="mrMuted">Todavía no tenés informes programados.</td></tr><?php endif; ?>
      <?php foreach($rows as $r):
        $rowJson=json_encode($r,JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $lastState=strtoupper(trim((string)($r['ULTIMO_ESTADO']??'')));
      ?>
        <tr data-schedule-row data-name="<?php echo h(strtolower((string)$r['NOMBRE'])); ?>">
          <td><div class="mrName"><?php echo h($r['NOMBRE']); ?></div><div class="mrMuted"><?php echo h($r['DESTINATARIOS']); ?></div></td>
          <td><?php echo h(mr_freq_label($r['FRECUENCIA']??'', $r['DIAS_SEMANA']??'')); ?><div class="mrMuted"><?php echo h($r['HORARIOS']??''); ?></div></td>
          <td><?php echo h(mr_datetime($r['PROXIMA_EJECUCION']??'')); ?></td>
          <td><span class="mrBadge <?php echo !empty($r['ACTIVO'])?'':'off'; ?>"><?php echo !empty($r['ACTIVO'])?'Activo':'Pausado'; ?></span></td>
          <td title="<?php echo h(mr_screen_summary($r['PANTALLAS']??$r['TIPO_REPORTE']??'', $catalog)); ?>"><?php echo h(mr_screen_summary($r['PANTALLAS']??$r['TIPO_REPORTE']??'', $catalog)); ?></td>
          <td><?php if($lastState==='ERROR'): ?><span class="mrBadge err">Error</span><?php elseif($lastState!==''): ?><span class="mrBadge"><?php echo h($lastState); ?></span><?php else: ?><span class="mrMuted">Sin envíos</span><?php endif; ?></td>
          <td><div class="mrRowActions">
            <button type="button" class="mrIconBtn" title="Editar" onclick='mrEdit(<?php echo $rowJson; ?>)'><?php echo icon('tools'); ?></button>
            <button type="button" class="mrIconBtn" title="Ejecutar ahora" onclick="mrSend(<?php echo (int)$r['ID']; ?>)"><?php echo icon('check'); ?></button>
            <button type="button" class="mrIconBtn" title="Duplicar" onclick="mrDuplicate(<?php echo (int)$r['ID']; ?>)"><?php echo icon('plus'); ?></button>
            <button type="button" class="mrIconBtn danger" title="Eliminar" onclick="mrDelete(<?php echo (int)$r['ID']; ?>)"><?php echo icon('trash'); ?></button>
          </div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>

<section class="mrPanel">
  <div class="mrPanel__head"><div><h2>Historial reciente</h2><p>Últimas ejecuciones de tus informes.</p></div></div>
  <div class="mrTableWrap"><table class="mrTable"><thead><tr><th>Fecha</th><th>Informe</th><th>Estado</th><th>Destinatarios</th><th>Detalle</th></tr></thead><tbody>
  <?php if(!$logs): ?><tr><td colspan="5" class="mrMuted">Todavía no hay ejecuciones registradas.</td></tr><?php endif; ?>
  <?php foreach($logs as $log): ?><tr><td><?php echo h(mr_datetime($log['FECHA_EJECUCION']??'')); ?></td><td><?php echo h($log['NOMBRE']??'—'); ?></td><td><?php echo h($log['ESTADO']??'—'); ?></td><td><?php echo h($log['DESTINATARIOS']??''); ?></td><td class="mrMuted"><?php echo h($log['ERROR']??''); ?></td></tr><?php endforeach; ?>
  </tbody></table></div>
</section>

</main>
</div>
<script src="assets/js/app.js?v=20260925-mis-reportes-1"></script>
<script>
(function(){
  var form=document.getElementById('mrForm');
  var msg=document.getElementById('mrMessage');
  var screenSearch=document.getElementById('mrScreenSearch');
  var scheduleSearch=document.getElementById('mrScheduleSearch');

  function note(text,ok){
    msg.innerHTML='<div class="mrNotice '+(ok?'ok':'err')+'">'+text+'</div>';
    window.scrollTo({top:0,behavior:'smooth'});
  }
  function post(fd){
    return fetch('mis_reportes_api.php',{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}})
      .then(async function(r){
        var raw=await r.text(),data;
        try{data=JSON.parse(String(raw||'').replace(/^\uFEFF/,''));}
        catch(e){throw new Error('El servidor devolvió una respuesta no válida.');}
        if(!r.ok||!data.ok)throw new Error(data.error||'Error');
        return data;
      });
  }
  form.addEventListener('submit',function(e){
    e.preventDefault();
    var button=form.querySelector('button[type=submit]');
    button.disabled=true;
    post(new FormData(form)).then(function(d){note(d.message||'Programación guardada.',true);setTimeout(function(){location.reload();},500);})
      .catch(function(e){note(e.message,false);button.disabled=false;});
  });

  document.getElementById('mrReset').addEventListener('click',function(){
    form.reset();document.getElementById('mrId').value='';document.getElementById('mrFormTitle').textContent='Nuevo informe programado';
    var checked=document.querySelectorAll('.mrScreens input[type=checkbox]:checked');checked.forEach(function(x){x.checked=false;});
    var first=document.querySelector('.mrScreens input[type=checkbox]');if(first)first.checked=true;
  });
  if(screenSearch)screenSearch.addEventListener('input',function(){
    var q=this.value.trim().toLowerCase();
    document.querySelectorAll('.mrScreen').forEach(function(row){row.style.display=!q||String(row.dataset.search||'').includes(q)?'flex':'none';});
  });
  document.getElementById('mrSelectAll').addEventListener('click',function(){document.querySelectorAll('.mrScreen').forEach(function(row){if(row.style.display!=='none')row.querySelector('input').checked=true;});});
  document.getElementById('mrClearAll').addEventListener('click',function(){document.querySelectorAll('.mrScreens input[type=checkbox]').forEach(function(x){x.checked=false;});});
  if(scheduleSearch)scheduleSearch.addEventListener('input',function(){
    var q=this.value.trim().toLowerCase();
    document.querySelectorAll('[data-schedule-row]').forEach(function(row){row.style.display=!q||String(row.dataset.name||'').includes(q)?'table-row':'none';});
  });

  window.mrEdit=function(r){
    document.getElementById('mrId').value=r.ID||'';
    document.getElementById('mrNombre').value=r.NOMBRE||'';
    document.getElementById('mrDestinatarios').value=r.DESTINATARIOS||'';
    document.getElementById('mrCc').value=r.CC||'';
    document.getElementById('mrCco').value=r.CCO||'';
    document.getElementById('mrAsunto').value=r.ASUNTO||'';
    document.getElementById('mrCuerpo').value=r.CUERPO||'';
    document.getElementById('mrFrecuencia').value=r.FRECUENCIA||'daily';
    document.getElementById('mrHorarios').value=r.HORARIOS||'08:00';
    document.getElementById('mrZona').value=r.ZONA_HORARIA||'America/Argentina/Buenos_Aires';
    document.getElementById('mrActivo').value=Number(r.ACTIVO||0)?'1':'0';
    var days=String(r.DIAS_SEMANA||'').split(',');
    document.querySelectorAll('[name="dias[]"]').forEach(function(x){x.checked=days.includes(x.value);});
    var screens=String(r.PANTALLAS||r.TIPO_REPORTE||'').split(',');
    document.querySelectorAll('[name="pantallas[]"]').forEach(function(x){x.checked=screens.includes(x.value);});
    document.getElementById('mrFormTitle').textContent='Editar informe programado';
    form.scrollIntoView({behavior:'smooth',block:'start'});
  };
  window.mrSend=function(id){
    if(!confirm('¿Generar y enviar este informe ahora?'))return;
    var fd=new FormData();fd.append('action','send_now');fd.append('id',String(id));
    post(fd).then(function(d){note(d.message||'Informe enviado.',true);setTimeout(function(){location.reload();},700);}).catch(function(e){note(e.message,false);});
  };
  window.mrDuplicate=function(id){
    if(!confirm('¿Duplicar esta programación? La copia quedará pausada.'))return;
    var fd=new FormData();fd.append('action','duplicate_schedule');fd.append('id',String(id));
    post(fd).then(function(d){note(d.message||'Programación duplicada.',true);setTimeout(function(){location.reload();},500);}).catch(function(e){note(e.message,false);});
  };
  window.mrDelete=function(id){
    if(!confirm('¿Eliminar esta programación?'))return;
    var fd=new FormData();fd.append('action','delete_schedule');fd.append('id',String(id));
    post(fd).then(function(){location.reload();}).catch(function(e){note(e.message,false);});
  };
})();
</script>
</body>
</html>
