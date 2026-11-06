<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/reporting.php';

function rf_text($data, $key) {
    return isset($data[$key]) && is_string($data[$key]) ? trim($data[$key]) : '';
}
function rf_length($s) {
    // NVARCHAR y maxlength cuentan unidades UTF-16.
    $n=preg_match_all('/./us',$s,$m);
    return $n===false ? false : $n+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$s,$m);
}
function rf_validate($input) {
    $d=[];
    foreach (['tipo','nombre','fecha','email','titulo','comentario'] as $key) $d[$key]=rf_text($input,$key);
    foreach (['nombre'=>150,'email'=>254,'titulo'=>200,'comentario'=>10000] as $key=>$max) {
        $len=rf_length($d[$key]);
        if ($d[$key]==='' || $len===false || $len>$max) throw new InvalidArgumentException('Completá '.$key.' (máximo '.$max.' caracteres).');
    }
    if (!in_array($d['tipo'],['Recomendación','Falla'],true)) throw new InvalidArgumentException('Seleccioná un tipo válido.');
    if (!filter_var($d['email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Ingresá un email válido.');
    $date=DateTimeImmutable::createFromFormat('!Y-m-d',$d['fecha']);
    if (!$date || $date->format('Y-m-d')!==$d['fecha'] || $d['fecha']<'1753-01-01') throw new InvalidArgumentException('Ingresá una fecha válida.');
    return $d;
}
function rf_emails($raw) {
    if (strlen($raw)>4000) throw new InvalidArgumentException('La lista de destinatarios es demasiado larga.');
    $emails=report_split_emails($raw);
    if (!$emails) throw new InvalidArgumentException('Ingresá al menos un destinatario.');
    foreach ($emails as $email) if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($email)>254) throw new InvalidArgumentException('Email receptor inválido: '.$email);
    return array_values(array_unique(array_map('strtolower',$emails)));
}
function rf_ready() {
    $db=clear_db();
    return $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_RECOMENDACIONES_FALLAS',N'U') IS NOT NULL AND OBJECT_ID(N'dbo.CLEAR_RF_CONFIG',N'U') IS NOT NULL THEN 1 ELSE 0 END")===1;
}
function rf_row($id) {
    $rows=clear_db()->all('SELECT * FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE ID=? AND ELIMINADO=0',[(int)$id]);
    return $rows[0]??null;
}
function rf_notify($id) {
    $db=clear_db(); $r=rf_row($id);
    if (!$r) throw new RuntimeException('La publicación ya no está disponible.');
    // Reserva atómica para evitar dos envíos simultáneos del mismo aviso.
    $claim=bin2hex(random_bytes(24));
    if (!$db->execute("UPDATE dbo.CLEAR_RECOMENDACIONES_FALLAS SET EMAIL_ESTADO='ENVIANDO',EMAIL_ERROR=?,EMAIL_FECHA=SYSDATETIME() WHERE ID=? AND ELIMINADO=0 AND EMAIL_ESTADO IN ('PENDIENTE','ERROR','SIN_DESTINATARIOS')",[$claim,(int)$id])) throw new RuntimeException('No se pudo reservar la notificación.');
    $claimed=$db->scalar('SELECT EMAIL_ERROR FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE ID=?',[(int)$id]);
    if ($claimed!==$claim) return 'El aviso ya fue enviado o está en proceso.';
    $state='ERROR'; $error='';
    try {
        $raw=(string)$db->scalar('SELECT DESTINATARIOS FROM dbo.CLEAR_RF_CONFIG WHERE ID=1');
        if (trim($raw)==='') { $state='SIN_DESTINATARIOS'; throw new RuntimeException('No hay emails receptores configurados.'); }
        $to=rf_emails($raw);
        $cfg=report_smtp_config();
        if (!$cfg['host'] || !filter_var($cfg['from'],FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Configuración SMTP incompleta. Revisá Reportes por correo.');
        $body='<h2>CLEAR · Recomendaciones y fallas</h2><p>Publicación #'.(int)$id.'</p>';
        foreach (['TIPO'=>'Tipo','NOMBRE'=>'Nombre','FECHA'=>'Fecha','EMAIL'=>'Email','TITULO'=>'Título','COMENTARIO'=>'Comentario','USUARIO_CARGA'=>'Usuario'] as $key=>$label) {
            $body.='<p><strong>'.$label.':</strong> '.nl2br(h($r[$key])).'</p>';
        }
        (new ClearSmtpClient())->send($cfg,$to,[],[],'CLEAR · '.$r['TIPO'].' #'.(int)$id,$body,[]);
        $state='ENVIADO';
    } catch (Throwable $e) { $error=$e->getMessage(); error_log('CLEAR RF #'.(int)$id.': '.$error); }
    $saved=$db->execute('UPDATE dbo.CLEAR_RECOMENDACIONES_FALLAS SET EMAIL_ESTADO=?,EMAIL_ERROR=?,EMAIL_FECHA=SYSDATETIME() WHERE ID=? AND EMAIL_ERROR=?',[$state,substr($error,0,950),(int)$id,$claim]);
    if (!$saved) return 'Publicación guardada. No se pudo registrar el resultado del correo; el administrador debe verificarlo antes de reintentar.';
    return $state==='ENVIADO' ? 'Publicación guardada y aviso enviado.' : 'Publicación guardada. El aviso por correo quedó pendiente; el administrador puede revisarlo y reintentar.';
}
function rf_handle($post,$admin,$user,$csrf) {
    if ($csrf==='' || !hash_equals($csrf,rf_text($post,'csrf'))) throw new InvalidArgumentException('La sesión del formulario venció. Recargá la página.');
    $action=rf_text($post,'accion');
    if (!in_array($action,['publicar','editar','borrar','configurar','reenviar'],true)) throw new InvalidArgumentException('Acción inválida.');
    if ($action!=='publicar' && !$admin) throw new RuntimeException('Solo los administradores pueden realizar esta acción.',403);
    if (!rf_ready()) throw new RuntimeException('El módulo no está instalado o la base de datos no está disponible.');
    $db=clear_db();
    if ($action==='configurar') {
        $emails=implode(';',rf_emails(rf_text($post,'destinatarios')));
        $ok=$db->execute('UPDATE dbo.CLEAR_RF_CONFIG SET DESTINATARIOS=?,ACTUALIZADO_POR=?,ACTUALIZADO_EN=SYSDATETIME() WHERE ID=1',[$emails,$user]);
    } elseif ($action==='publicar') {
        $d=rf_validate($post); $token=rf_text($post,'token');
        if (!preg_match('/^[a-f0-9]{48}$/D',$token)) throw new InvalidArgumentException('Identificador de publicación inválido. Recargá la página.');
        $existing=$db->scalar('SELECT ID FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE TOKEN=?',[$token]);
        if ($existing) return 'Esta publicación ya se guardó. No se creó un duplicado.';
        $ok=$db->execute('INSERT INTO dbo.CLEAR_RECOMENDACIONES_FALLAS(TOKEN,TIPO,NOMBRE,FECHA,EMAIL,TITULO,COMENTARIO,USUARIO_CARGA) VALUES(?,?,?,?,?,?,?,?)',[$token,$d['tipo'],$d['nombre'],$d['fecha'],$d['email'],$d['titulo'],$d['comentario'],$user]);
        if ($ok) {
            $id=$db->scalar('SELECT ID FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE TOKEN=?',[$token]);
            if (!$id) return 'Publicación guardada. El administrador debe revisar el aviso pendiente.';
            return rf_notify($id);
        }
        // La restricción UNIQUE también cubre doble clics concurrentes.
        if ($db->scalar('SELECT ID FROM dbo.CLEAR_RECOMENDACIONES_FALLAS WHERE TOKEN=?',[$token])) return 'Esta publicación ya se guardó.';
    } else {
        $id=filter_var(rf_text($post,'id'),FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
        if (!$id || !rf_row($id)) throw new InvalidArgumentException('La publicación no existe o fue eliminada.');
        if ($action==='reenviar') return rf_notify($id);
        if ($action==='borrar') $ok=$db->execute('UPDATE dbo.CLEAR_RECOMENDACIONES_FALLAS SET ELIMINADO=1,USUARIO_MODIFICACION=?,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=? AND ELIMINADO=0',[$user,$id]);
        else {
            $d=rf_validate($post);
            $ok=$db->execute('UPDATE dbo.CLEAR_RECOMENDACIONES_FALLAS SET TIPO=?,NOMBRE=?,FECHA=?,EMAIL=?,TITULO=?,COMENTARIO=?,USUARIO_MODIFICACION=?,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=? AND ELIMINADO=0',[$d['tipo'],$d['nombre'],$d['fecha'],$d['email'],$d['titulo'],$d['comentario'],$user,$id]);
        }
    }
    if (!$ok) { error_log('CLEAR RF SQL: '.$db->error()); throw new RuntimeException('No se pudo guardar el cambio. Intentá nuevamente.'); }
    return 'Cambios guardados.';
}
