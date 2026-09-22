<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/permissions.php';

function report_ensure_tables(){
    static $checked=null;if($checked!==null)return $checked;
    $db=clear_db(); if(!$db->ok()) return false;
    /* El esquema de reportes se instala por SQL. No ejecutar DDL desde IIS. */
    $checked=(int)$db->scalar(
        "SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_REPORT_SCHEDULES',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_REPORT_LOG',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_REPORT_SETTINGS',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_REPORT_LOCK',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_REPORT_CONTACTS',N'U') IS NOT NULL " .
        "AND OBJECT_ID(N'dbo.CLEAR_REPORTES_GUARDADOS',N'U') IS NOT NULL " .
        "AND COL_LENGTH(N'dbo.CLEAR_REPORT_SCHEDULES',N'PANTALLAS') IS NOT NULL " .
        "AND EXISTS(SELECT 1 FROM sys.indexes WHERE object_id=OBJECT_ID(N'dbo.CLEAR_REPORTES_GUARDADOS') AND name=N'IX_CLEAR_REPORTES_GUARDADOS_USUARIO') " .
        "THEN 1 ELSE 0 END"
    )===1;
    return $checked;
}
function report_setting($key,$default=''){
    report_ensure_tables(); $v=clear_db()->scalar("SELECT CONFIG_VALUE FROM dbo.CLEAR_REPORT_SETTINGS WHERE CONFIG_KEY=?",[$key]); return $v===null?$default:(string)$v;
}
function report_setting_save($key,$value,$user=''){
    report_ensure_tables(); $db=clear_db(); $n=(int)$db->scalar("SELECT COUNT(*) FROM dbo.CLEAR_REPORT_SETTINGS WHERE CONFIG_KEY=?",[$key]);
    if($n) return $db->execute("UPDATE dbo.CLEAR_REPORT_SETTINGS SET CONFIG_VALUE=?,UPDATED_AT=SYSDATETIME(),UPDATED_BY=? WHERE CONFIG_KEY=?",[$value,$user,$key]);
    return $db->execute("INSERT INTO dbo.CLEAR_REPORT_SETTINGS(CONFIG_KEY,CONFIG_VALUE,UPDATED_BY) VALUES(?,?,?)",[$key,$value,$user]);
}
function report_split_emails($text){ return array_values(array_filter(array_map('trim',preg_split('/[;,\r\n]+/',(string)$text)))); }
function report_contacts_all(){
    report_ensure_tables();
    return clear_db()->all("SELECT ID,NOMBRE,EMAIL,USUARIO_CARGA,FECHA_CARGA,FECHA_MODIFICACION FROM dbo.CLEAR_REPORT_CONTACTS WHERE ACTIVO=1 ORDER BY COALESCE(NULLIF(NOMBRE,N''),EMAIL),EMAIL");
}
function report_contact_save($name,$email,$user=''){
    report_ensure_tables();$db=clear_db();
    $name=trim((string)$name);$email=strtolower(trim((string)$email));
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))return [false,'Ingresá un correo válido.'];
    if(strlen($name)>150||strlen($email)>254)return [false,'El contacto supera el largo permitido.'];
    $id=$db->scalar("SELECT TOP 1 ID FROM dbo.CLEAR_REPORT_CONTACTS WHERE LOWER(EMAIL)=LOWER(?)",[$email]);
    if($id!==null&&$id!=='')$ok=$db->execute("UPDATE dbo.CLEAR_REPORT_CONTACTS SET NOMBRE=?,EMAIL=?,ACTIVO=1,FECHA_MODIFICACION=SYSDATETIME() WHERE ID=?",[$name,$email,(int)$id]);
    else $ok=$db->execute("INSERT INTO dbo.CLEAR_REPORT_CONTACTS(NOMBRE,EMAIL,USUARIO_CARGA) VALUES(?,?,?)",[$name,$email,$user]);
    return $ok?[true,'Contacto guardado en la libreta común.']:[false,'No se pudo guardar el contacto. '.$db->error()];
}
function report_next_run(array $r,$from=null){
    $tz=new DateTimeZone($r['ZONA_HORARIA']??'America/Argentina/Buenos_Aires'); $now=$from?:new DateTime('now',$tz); $times=array_filter(array_map('trim',explode(',',(string)($r['HORARIOS']??'08:00')))); if(!$times)$times=['08:00']; sort($times);
    $freq=strtolower((string)($r['FRECUENCIA']??'daily')); $days=array_map('intval',array_filter(explode(',',(string)($r['DIAS_SEMANA']??''))));
    for($i=0;$i<370;$i++){
        $d=(clone $now)->modify('+'.$i.' day'); $dow=(int)$d->format('N');
        $allowed=true;
        if($freq==='weekdays')$allowed=$dow<=5;
        elseif($freq==='weekly'||$freq==='custom')$allowed=!$days||in_array($dow,$days,true);
        elseif($freq==='monthly')$allowed=((int)$d->format('j')===1);
        if(!$allowed)continue;
        foreach($times as $t){ if(!preg_match('/^(\d{1,2}):(\d{2})$/',$t,$m))continue; $cand=(clone $d)->setTime((int)$m[1],(int)$m[2],0); if($cand>$now)return $cand; }
    }
    return (clone $now)->modify('+1 day')->setTime(8,0);
}
function report_log($id,$to,$status,$file='',$error='',$seconds=0,$user='scheduler'){
    return clear_db()->execute("INSERT INTO dbo.CLEAR_REPORT_LOG(ID_REPORTE,DESTINATARIOS,ESTADO,ARCHIVO,ERROR,DURACION_SEGUNDOS,USUARIO_EJECUCION) VALUES(?,?,?,?,?,?,?)",[$id,$to,$status,$file,$error,$seconds,$user]);
}
function report_generate_token($id){
    $cfg=require dirname(__DIR__).'/config.php'; $secret=($cfg['db']['password']??'clear').'|'.($cfg['db']['database']??'db'); return hash_hmac('sha256',(string)$id.'|'.date('Y-m-d'),$secret);
}
function report_screen_catalog(){
    return [
        'dashboard'=>'Dashboard',
        'pozos_alarmas24'=>'Pozos · Alarmas 24 hs',
        'top_pozos'=>'Pozos · Top Pozos',
        'pozos_top20'=>'Pozos · Top 20 alarmas',
        'pozos'=>'Pozos · Ficha de pozos',
        'pozos_tecss'=>'Pozos · Estado técnico',
        'alarmas24h'=>'Alarmas 24h',
        'alarmas_activas'=>'Alarmas por históricos',
        'pi_historico'=>'PI Histórico',
        'suprimidas'=>'Suprimidas',
        'reconocidas'=>'Reconocidas',
        'reconocidas_usr'=>'Reconocidas por usuario',
        'top20'=>'Top 20 alarmas',
        'top20_24h'=>'Top 20 alarmas 24h',
        'ranking24h'=>'Ranking 24h',
        'top_total'=>'Top Total',
        'top_hml'=>'Top semanal',
        'tendencia_sem'=>'Tendencia semanal',
        'tend_sem_tags'=>'Tendencia por TAG',
        'prioridad'=>'Prioridad',
        'todas_alarmas'=>'Todas las alarmas',
        'importadas'=>'Alarmas importadas',
        'reconocimientos'=>'Reconocimientos de alarmas',
        'comentarios_semana'=>'Comentarios · Última semana',
        'analisis_ia'=>'Análisis IA semanal'
    ];
}
function report_parse_screens($value){
    $allowed=report_screen_catalog();
    $items=is_array($value)?$value:preg_split('/[;,]+/',(string)$value);
    $out=[];
    foreach($items as $x){$x=trim((string)$x);if(isset($allowed[$x])&&!in_array($x,$out,true))$out[]=$x;}
    return $out?:['dashboard'];
}
function report_build_url($id,$screen='dashboard'){
    $base=rtrim(report_setting('APP_BASE_URL','http://localhost/CLEAR'),'/');
    return $base.'/report_screen.php?id='.(int)$id.'&screen='.rawurlencode($screen).'&token='.report_generate_token($id);
}
function report_generate_pdf($id,$screen,$outfile){
    $chrome=report_setting('CHROME_PATH','C:\Program Files\Google\Chrome\Application\chrome.exe');
    if(!is_file($chrome)) return [false,'No se encontró Chrome en: '.$chrome];
    $url=report_build_url($id,$screen);
    $cmd='"'.$chrome.'" --headless --disable-gpu --no-sandbox --print-to-pdf="'.$outfile.'" --print-to-pdf-no-header "'.$url.'" 2>&1';
    exec($cmd,$out,$code);
    if($code!==0||!is_file($outfile))return [false,implode("\n",$out)?:'Chrome no generó el PDF'];
    return [true,''];
}
class ClearSmtpClient{
    private $fp,$log='';
    private function read(){ $s=''; while($this->fp&&!feof($this->fp)){ $l=fgets($this->fp,515); $s.=$l; if(strlen($l)<4||$l[3]===' ')break; } $this->log.=$s; return $s; }
    private function cmd($c,$ok=[250]){ fwrite($this->fp,$c."\r\n"); $r=$this->read(); $code=(int)substr($r,0,3); if(!in_array($code,$ok,true))throw new Exception(trim($r)); return $r; }
    public function send($cfg,$to,$cc,$bcc,$subject,$body,$attachments,$inlineImages=[]){
        $host=$cfg['host'];$port=(int)$cfg['port'];$secure=$cfg['secure']; $target=($secure==='ssl'?'ssl://':'').$host.':'.$port;
        $this->fp=@stream_socket_client($target,$errno,$errstr,20); if(!$this->fp)throw new Exception($errstr?:'No se pudo conectar al SMTP'); stream_set_timeout($this->fp,20); $this->read();
        $this->cmd('EHLO clear-platform',[250]); if($secure==='tls'){ $this->cmd('STARTTLS',[220]); if(!stream_socket_enable_crypto($this->fp,true,STREAM_CRYPTO_METHOD_TLS_CLIENT))throw new Exception('No se pudo iniciar TLS'); $this->cmd('EHLO clear-platform',[250]); }
        if(!empty($cfg['user'])){ $this->cmd('AUTH LOGIN',[334]); $this->cmd(base64_encode($cfg['user']),[334]); $this->cmd(base64_encode($cfg['pass']),[235]); }
        $from=$cfg['from']; $this->cmd('MAIL FROM:<'.$from.'>'); $all=array_unique(array_merge($to,$cc,$bcc)); foreach($all as $e)$this->cmd('RCPT TO:<'.$e.'>',[250,251]); $this->cmd('DATA',[354]);
        $boundary='=_CLEAR_'.bin2hex(random_bytes(8));$related='=_CLEAR_REL_'.bin2hex(random_bytes(8)); $headers=[]; $headers[]='From: '.($cfg['from_name']?:'CLEAR Plataforma').' <'.$from.'>'; $headers[]='To: '.implode(', ',$to); if($cc)$headers[]='Cc: '.implode(', ',$cc); $headers[]='Subject: =?UTF-8?B?'.base64_encode($subject).'?='; $headers[]='MIME-Version: 1.0'; $headers[]='Content-Type: multipart/mixed; boundary="'.$boundary.'"';
        $msg=implode("\r\n",$headers)."\r\n\r\n--$boundary\r\nContent-Type: multipart/related; boundary=\"$related\"\r\n\r\n--$related\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n$body\r\n";
        foreach((array)$inlineImages as $image){
            $path=is_array($image)?($image['path']??''):'';$cid=is_array($image)?($image['cid']??''):'';
            if($path&&$cid&&is_file($path)){$name=basename($path);$data=chunk_split(base64_encode(file_get_contents($path)));$msg.="--$related\r\nContent-Type: image/png; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-ID: <$cid>\r\nContent-Disposition: inline; filename=\"$name\"\r\n\r\n$data\r\n";}
        }
        $msg.="--$related--\r\n";
        foreach((array)$attachments as $attachment){ if($attachment&&is_file($attachment)){ $name=basename($attachment); $data=chunk_split(base64_encode(file_get_contents($attachment))); $msg.="--$boundary\r\nContent-Type: application/pdf; name=\"$name\"\r\nContent-Transfer-Encoding: base64\r\nContent-Disposition: attachment; filename=\"$name\"\r\n\r\n$data\r\n"; }}
        $msg.="--$boundary--\r\n."; fwrite($this->fp,$msg."\r\n"); $r=$this->read(); if((int)substr($r,0,3)!==250)throw new Exception(trim($r)); $this->cmd('QUIT',[221]); fclose($this->fp); return true;
    }
}
function report_smtp_config(){
    $secure=strtolower(trim((string)report_setting('SMTP_SECURE','tls')));
    if(in_array($secure,['starttls','tls'],true)) $secure='tls';
    elseif(in_array($secure,['ssl','smtps'],true)) $secure='ssl';
    else $secure='none';
    return ['host'=>trim((string)report_setting('SMTP_HOST','')),'port'=>(int)report_setting('SMTP_PORT','587'),'secure'=>$secure,'user'=>trim((string)report_setting('SMTP_USER','')),'pass'=>(string)report_setting('SMTP_PASS',''),'from'=>trim((string)report_setting('SMTP_FROM','')),'from_name'=>trim((string)report_setting('SMTP_FROM_NAME','CLEAR Petroleum'))];
}
function report_execute(array $r,$user='scheduler'){
    $start=microtime(true);
    $screens=report_parse_screens($r['PANTALLAS']??$r['TIPO_REPORTE']??'dashboard');
    $files=[];
    foreach($screens as $screen){
        $safe=preg_replace('/[^A-Za-z0-9_-]+/','_', $screen);
        $tmp=sys_get_temp_dir().DIRECTORY_SEPARATOR.'CLEAR_'.$safe.'_'.date('Ymd_His').'.pdf';
        list($ok,$err)=report_generate_pdf((int)$r['ID'],$screen,$tmp);
        if(!$ok){
            foreach($files as $f)@unlink($f);
            report_log($r['ID'],$r['DESTINATARIOS'],'ERROR','',$err,(int)(microtime(true)-$start),$user);
            return [false,$err];
        }
        $files[]=$tmp;
    }
    try{
        $smtp=report_smtp_config();
        if(!$smtp['host']||!$smtp['from'])throw new Exception('Configuración SMTP incompleta');
        $client=new ClearSmtpClient();
        $client->send($smtp,report_split_emails($r['DESTINATARIOS']),report_split_emails($r['CC']??''),report_split_emails($r['CCO']??''),$r['ASUNTO'],$r['CUERPO']?:'<p>Se adjuntan los reportes operativos automáticos de CLEAR Petroleum.</p>',$files);
        report_log($r['ID'],$r['DESTINATARIOS'],'ENVIADO',implode(';',$files),'',(int)(microtime(true)-$start),$user);
        foreach($files as $f)@unlink($f);
        return [true,'Enviado'];
    }catch(Throwable $e){
        report_log($r['ID'],$r['DESTINATARIOS'],'ERROR',implode(';',$files),$e->getMessage(),(int)(microtime(true)-$start),$user);
        foreach($files as $f)@unlink($f);
        return [false,$e->getMessage()];
    }
}
