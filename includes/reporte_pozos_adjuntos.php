<?php
// Los archivos nunca se sirven desde una carpeta pública ni se interpretan.
function pfa_max_bytes(): int { return 5 * 1024 * 1024; }
function pfa_extensions(): array { return ['pdf','jpg','jpeg','png','webp','txt','csv','doc','docx','xls','xlsx','ppt','pptx','zip']; }
function pfa_storage_dir(): string {
    $config=dirname(__DIR__).'/reporte_pozos_config.php';
    $settings=is_file($config)?require $config:[];
    $path=is_array($settings)?($settings['adjuntos_dir']??''):'';
    if(!is_string($path)||$path===''||strpos($path,"://")!==false||!preg_match('~^(?:[A-Za-z]:[/\\\\]|/)~',$path))throw new RuntimeException('Configurá la carpeta privada de adjuntos según LEEME antes de subir archivos.');
    $dir=realpath($path);
    if($dir===false||!is_dir($dir))throw new RuntimeException('La carpeta privada de adjuntos no está disponible. Revisá su configuración.');
    $normal=function($v){return strtolower(rtrim(str_replace('\\','/',$v),'/')).'/';};
    foreach([dirname(__DIR__),$_SERVER['DOCUMENT_ROOT']??''] as $web){
        if($web!==''&&($base=realpath($web))!==false&&strpos($normal($dir),$normal($base))===0)throw new RuntimeException('La carpeta de adjuntos debe estar fuera de la raíz web de IIS.');
    }
    return rtrim($dir,'/\\');
}
function pfa_filename($name): string {
    if(!is_string($name)||!preg_match('//u',$name))throw new RuntimeException('El nombre del archivo no es válido.');
    $name=trim($name);
    if($name===''||preg_match('~[\\\\/\x00-\x1f\x7f<>:"|?*]~',$name)||preg_match('/[\x{202a}-\x{202e}\x{2066}-\x{2069}]/u',$name)||(preg_match_all('/[\s\S]/u',$name)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$name))>180)throw new RuntimeException('Usá un nombre de archivo de hasta 180 caracteres, sin rutas ni caracteres especiales.');
    $ext=strtolower(pathinfo($name,PATHINFO_EXTENSION));
    if(!in_array($ext,pfa_extensions(),true))throw new RuntimeException('Formato no admitido. Usá PDF, imágenes JPG/PNG/WebP, TXT/CSV, Word, Excel, PowerPoint o ZIP.');
    return $name;
}
function pfa_validate_upload(?array $upload): ?array {
    if($upload===null)return null;
    if(!isset($upload['error'])||!is_int($upload['error']))throw new RuntimeException('El adjunto recibido no es válido.');
    if($upload['error']===UPLOAD_ERR_NO_FILE)return null;
    if(in_array($upload['error'],[UPLOAD_ERR_INI_SIZE,UPLOAD_ERR_FORM_SIZE],true))throw new RuntimeException('El archivo supera el límite permitido. Máximo 5 MB; revisá también el límite de PHP.');
    if($upload['error']!==UPLOAD_ERR_OK)throw new RuntimeException('No se recibió el archivo completo. Seleccionalo nuevamente; el parte no se guardó.');
    $name=pfa_filename($upload['name']??null);$tmp=$upload['tmp_name']??null;
    if(!is_string($tmp)||!is_uploaded_file($tmp))throw new RuntimeException('La carga del adjunto no es válida.');
    $size=filesize($tmp);
    if($size===false||$size<1||$size>pfa_max_bytes())throw new RuntimeException('El adjunto debe tener contenido y pesar como máximo 5 MB.');
    return ['name'=>$name,'tmp'=>$tmp,'bytes'=>$size];
}
function pfa_stage(array $file): array {
    $dir=pfa_storage_dir();
    if(!is_writable($dir))throw new RuntimeException('IIS no tiene permiso de escritura en la carpeta privada de adjuntos.');
    $key=bin2hex(random_bytes(32));$path=$dir.DIRECTORY_SEPARATOR.$key.'.bin';
    if(!move_uploaded_file($file['tmp'],$path))throw new RuntimeException('No se pudo guardar el adjunto. No se guardó el parte.');
    @chmod($path,0600);
    return ['key'=>$key,'name'=>$file['name'],'bytes'=>$file['bytes'],'path'=>$path];
}
function pfa_path(string $key): string {
    if(!preg_match('/^[a-f0-9]{64}$/D',$key))throw new RuntimeException('Adjunto no disponible.');
    return pfa_storage_dir().DIRECTORY_SEPARATOR.$key.'.bin';
}
function pfa_remove_old(string $key): void {
    if($key==='')return;
    try{$path=pfa_path($key);if(is_file($path)&&!@unlink($path))error_log('CLEAR Reporte de Pozos: un adjunto anterior requiere limpieza manual.');}
    catch(Throwable $e){error_log('CLEAR Reporte de Pozos: no se pudo limpiar un adjunto anterior.');}
}
