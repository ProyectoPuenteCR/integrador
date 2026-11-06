<?php
require_once __DIR__.'/includes/recomendaciones_fallas.php';
require_once __DIR__.'/includes/icons.php';
auth_require();
$APP_USER=auth_user();
$admin=auth_es_admin();
$APP_ROLE=$admin?'Administrador':'Usuario';
$ACTIVE='recomendaciones_fallas';
if (empty($_SESSION['clear_rf_csrf'])) $_SESSION['clear_rf_csrf']=bin2hex(random_bytes(24));
$csrf=$_SESSION['clear_rf_csrf'];
$error=''; $notice=$_SESSION['clear_rf_notice']??''; unset($_SESSION['clear_rf_notice']);
$ready=rf_ready();
if ($_SERVER['REQUEST_METHOD']==='POST') {
    try {
        if (!empty($_SESSION['clear_report_mode'])) throw new RuntimeException('La sesión de reportes es de solo lectura.',403);
        $_SESSION['clear_rf_notice']=rf_handle($_POST,$admin,$APP_USER,$csrf);
        header('Location: recomendaciones_fallas.php',true,303); exit;
    } catch (Throwable $e) {
        if ($e instanceof InvalidArgumentException || $e->getCode()===403) $error=$e->getMessage();
        else { error_log('CLEAR RF: '.$e->getMessage()); $error='No se pudo completar la operación. Verificá la instalación y volvé a intentar.'; }
        if ($e->getCode()===403) http_response_code(403);
    }
}
$edit=null;
$editId=filter_var($_GET['editar']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
if ($admin && $ready && $editId) {
    $edit=rf_row($editId);
    if (!$edit) $error='La publicación no existe o fue eliminada.';
}
$form=['tipo'=>'Recomendación','nombre'=>$APP_USER,'fecha'=>date('Y-m-d'),'email'=>'','titulo'=>'','comentario'=>''];
if ($edit) foreach ($form as $k=>$v) $form[$k]=(string)($edit[strtoupper($k)]??$v);
if ($error && in_array(rf_text($_POST,'accion'),['editar','publicar'],true)) foreach ($form as $k=>$v) $form[$k]=rf_text($_POST,$k);
$token=rf_text($_POST,'token');
if (!preg_match('/^[a-f0-9]{48}$/D',$token)) $token=bin2hex(random_bytes(24));
$db=clear_db(); $rows=[]; $total=0;
$page=max(1,min(1000000,(int)($_GET['pagina']??1))); $pages=1;
if ($ready) {
    $total=(int)$db->scalar('SELECT COUNT(*) FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE ELIMINADO=0');
    $pages=max(1,(int)ceil($total/20)); $page=min($page,$pages); $offset=($page-1)*20;
    $rows=$db->all('SELECT * FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE ELIMINADO=0 ORDER BY FECHA_CARGA DESC,ID DESC OFFSET '.$offset.' ROWS FETCH NEXT 20 ROWS ONLY');
    if ($db->error()!=='') { $error='No se pudieron consultar las publicaciones. Intentá nuevamente.'; error_log('CLEAR RF listado: '.$db->error()); }
}
$recipients=$admin&&$ready?(string)$db->scalar('SELECT DESTINATARIOS FROM dbo.CLEAR_RF_CONFIG WHERE ID=1'):'';
if ($error && rf_text($_POST,'accion')==='configurar' && $admin) $recipients=rf_text($_POST,'destinatarios');
function rf_date_label($value,$time=false) {
    $ts=strtotime((string)$value); return $ts?date($time?'d/m/Y H:i':'d/m/Y',$ts):(string)$value;
}
?>
<!doctype html><html lang="es"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Recomendaciones y fallas · CLEAR</title>
<link rel="stylesheet" href="assets/css/app.css?v=3.0.1">
<link rel="stylesheet" href="assets/css/recomendaciones_fallas.css?v=20261106">
</head><body class="rf-screen"><div class="app">
<?php include __DIR__.'/includes/sidebar.php'; ?>
<main class="main"><?php include __DIR__.'/includes/topbar.php'; ?>
<section class="rf-page">
 <a class="rf-back" href="inicio.php">← Volver a Inicio</a>
 <header class="rf-header"><div><h1>Recomendaciones y fallas</h1><p>Tu experiencia nos ayuda a mejorar CLEAR. Compartí una propuesta o informá un problema.</p></div><span class="rf-badge"><?php echo $total; ?> publicaciones</span></header>
 <?php if ($notice): ?><div class="rf-alert rf-success" role="status"><?php echo h($notice); ?></div><?php endif; ?>
 <?php if ($error): ?><div class="rf-alert rf-error" role="alert"><?php echo h($error); ?></div><?php endif; ?>
 <?php if (!$ready): ?><div class="rf-alert rf-error" role="alert">Módulo no disponible. El administrador debe ejecutar SQL/CLEAR_RECOMENDACIONES_FALLAS.sql y verificar la conexión a la base.</div><?php endif; ?>
 <div class="rf-layout">
 <aside>
 <section class="rf-panel"><h2><?php echo $edit?'Editar publicación #'.(int)$edit['ID']:'Nueva publicación'; ?></h2><p class="rf-muted">Todos los campos son obligatorios.</p>
 <form method="post" action="recomendaciones_fallas.php<?php echo $edit?'?editar='.(int)$edit['ID']:''; ?>">
 <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>">
 <input type="hidden" name="accion" value="<?php echo $edit?'editar':'publicar'; ?>">
 <input type="hidden" name="token" value="<?php echo h($token); ?>">
 <?php if ($edit): ?><input type="hidden" name="id" value="<?php echo (int)$edit['ID']; ?>"><?php endif; ?>
 <fieldset <?php echo !$ready?'disabled':''; ?>>
 <label class="rf-field">Tipo<select name="tipo" required><?php foreach (['Recomendación','Falla'] as $type): ?><option <?php echo $form['tipo']===$type?'selected':''; ?>><?php echo h($type); ?></option><?php endforeach; ?></select></label>
 <label class="rf-field">Nombre<input name="nombre" autocomplete="name" maxlength="150" required value="<?php echo h($form['nombre']); ?>"></label>
 <div class="rf-pair"><label class="rf-field">Fecha<input name="fecha" type="date" min="1753-01-01" required value="<?php echo h($form['fecha']); ?>"></label>
 <label class="rf-field">Email<input name="email" type="email" autocomplete="email" maxlength="254" required value="<?php echo h($form['email']); ?>"></label></div>
 <label class="rf-field">Título<input name="titulo" maxlength="200" required placeholder="Resumí tu propuesta o problema" value="<?php echo h($form['titulo']); ?>"></label>
 <label class="rf-field">Comentario<textarea name="comentario" rows="6" maxlength="10000" required placeholder="Contanos qué pasó o qué te gustaría mejorar…"><?php echo h($form['comentario']); ?></textarea></label>
 <p class="rf-muted">La publicación y tus datos de contacto serán visibles para los usuarios de CLEAR. Se enviará un aviso a los receptores configurados.</p>
 <div class="rf-actions"><button class="rf-btn rf-primary" type="submit"><?php echo $edit?'Guardar cambios':'Publicar'; ?></button><?php if ($edit): ?><a class="rf-btn" href="recomendaciones_fallas.php">Cancelar</a><?php endif; ?></div>
 </fieldset></form></section>
 <?php if ($admin): ?><details class="rf-panel rf-settings" <?php echo rf_text($_POST,'accion')==='configurar'?'open':''; ?>><summary>Emails receptores <span class="rf-muted">· Administración</span></summary><form method="post" action="recomendaciones_fallas.php">
 <input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="accion" value="configurar">
 <label class="rf-field">Destinatarios<textarea name="destinatarios" rows="4" maxlength="4000" required placeholder="persona@empresa.com; equipo@empresa.com"><?php echo h($recipients); ?></textarea></label><p class="rf-muted">Separá los emails con punto y coma, coma o salto de línea. El envío utiliza el SMTP de Reportes por correo.</p>
 <button class="rf-btn rf-primary" <?php echo !$ready?'disabled':''; ?>>Guardar receptores</button></form></details><?php endif; ?>
 </aside>
 <section class="rf-feed" aria-label="Publicaciones del equipo"><div class="rf-feed-head"><h2>Publicaciones del equipo</h2><span class="rf-muted">Más recientes primero</span></div>
 <?php if ($ready && !$rows): ?><div class="rf-panel rf-empty"><h3>Todavía no hay publicaciones</h3><p>La próxima mejora puede empezar con tu comentario.</p></div><?php endif; ?>
 <?php foreach ($rows as $row): ?>
 <article class="rf-panel rf-post"><div class="rf-post-top"><span class="rf-type <?php echo $row['TIPO']==='Falla'?'rf-type-falla':''; ?>"><?php echo h($row['TIPO']); ?></span><span class="rf-muted">#<?php echo (int)$row['ID']; ?> · Publicado <?php echo h(rf_date_label($row['FECHA_CARGA'],true)); ?></span></div>
 <h3><?php echo h($row['TITULO']); ?></h3><div class="rf-meta"><strong><?php echo h($row['NOMBRE']); ?></strong><span><?php echo h(rf_date_label($row['FECHA'])); ?></span><span><?php echo h($row['EMAIL']); ?></span></div>
 <div class="rf-comment"><?php echo h($row['COMENTARIO']); ?></div>
 <?php if (!empty($row['FECHA_MODIFICACION'])): ?><p class="rf-muted">Editado por <?php echo h($row['USUARIO_MODIFICACION']); ?> · <?php echo h(rf_date_label($row['FECHA_MODIFICACION'],true)); ?></p><?php endif; ?>
 <?php if ($admin): ?><footer class="rf-post-admin"><p class="rf-muted">Correo: <?php echo h($row['EMAIL_ESTADO']); ?><?php if ($row['EMAIL_ESTADO']!=='ENVIANDO' && !empty($row['EMAIL_ERROR'])): ?> · <?php echo h($row['EMAIL_ERROR']); ?><?php endif; ?></p><div class="rf-actions"><a class="rf-btn" href="recomendaciones_fallas.php?editar=<?php echo (int)$row['ID']; ?>">Editar</a>
 <?php if (in_array($row['EMAIL_ESTADO'],['PENDIENTE','ERROR','SIN_DESTINATARIOS'],true)): ?><form method="post" action="recomendaciones_fallas.php"><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$row['ID']; ?>"><button class="rf-btn" name="accion" value="reenviar">Reintentar correo</button></form><?php endif; ?>
 <form method="post" action="recomendaciones_fallas.php" data-rf-delete><input type="hidden" name="csrf" value="<?php echo h($csrf); ?>"><input type="hidden" name="id" value="<?php echo (int)$row['ID']; ?>"><button class="rf-btn rf-danger" name="accion" value="borrar">Borrar</button></form></div></footer><?php endif; ?>
 </article><?php endforeach; ?>
 <?php if ($pages>1): ?><nav class="rf-pagination" aria-label="Páginas de publicaciones"><?php if ($page>1): ?><a class="rf-btn" href="?pagina=<?php echo $page-1; ?>">← Anterior</a><?php endif; ?><span>Página <?php echo $page; ?> de <?php echo $pages; ?></span><?php if ($page<$pages): ?><a class="rf-btn" href="?pagina=<?php echo $page+1; ?>">Siguiente →</a><?php endif; ?></nav><?php endif; ?>
 </section></div>
</section></main></div>
<script src="assets/js/app.js?v=3.0.1"></script>
<script src="assets/js/recomendaciones_fallas.js?v=20261106"></script>
</body></html>
