import fs from 'node:fs';import path from 'node:path';import assert from 'node:assert/strict';
import {getPHPLoaderModule} from '../patch_test_tools/node_modules/@php-wasm/node-7-4/index.js';import {PHP,loadPHPRuntime} from '../patch_test_tools/node_modules/@php-wasm/universal/index.js';
const php=new PHP(await loadPHPRuntime(await getPHPLoaderModule()));php.mkdir('/app');
function copy(dir,rel=''){for(const d of fs.readdirSync(dir,{withFileTypes:true})){const r=path.join(rel,d.name);if(d.isDirectory()){php.mkdir('/app/'+r);copy(path.join(dir,d.name),r);}else if(d.name.endsWith('.php')&&!/^(config|config_pi|mail_config|pumpoff_config|reporte_pozos_config)\.php$/.test(d.name))php.writeFile('/app/'+r,fs.readFileSync(path.join(dir,d.name)));}}
copy('clear_patch_work/clear');const unit=fs.readFileSync('patch_validation/unit.php','utf8'),db=unit.slice(unit.indexOf('class TestDB'),unit.indexOf("$GLOBALS['passed']=[];"));
php.writeFile('/app/includes/db.php',`<?php function check($v,$l){if(!$v)throw new RuntimeException($l);} ${db} function clear_db(){static $db=null;if(!$db)$db=new TestDB();return $db;}`);
php.writeFile('/app/includes/auth.php',`<?php function auth_require(){} function auth_user(){return 'tester';} function auth_es_admin(){return $GLOBALS['writable'];}`);
php.writeFile('/app/includes/permissions.php',`<?php function permissions_require_menu($k){} function permissions_can($k){return false;}`);
php.writeFile('/app/config.php',`<?php return ['app'=>['tz'=>'UTC']];`);
const base={ID:0,VERSION:0,SOLICITUD:'a'.repeat(32),FECHA:'2026-08-28',ESTADO:'PENDIENTE',REQUERIMIENTO:'Revisar comunicación',RESPONSABLE:'Jefa Norte',RESPONSABLE_TIPO:'JEFE_PRODUCCION',SUPERVISORES:['Ana Campo','Bruno Campo'],token:'csrf'};
const checks=[];
async function run(label,method,get,post={},status=200,writable=true,extra=''){
 const value=v=>`json_decode('${JSON.stringify(v).replaceAll("'","\\'")}',true)`;
 php.writeFile('/app/test-api.php',`<?php $GLOBALS['writable']=${writable?'true':'false'}; $_SERVER['REQUEST_METHOD']='${method}';$_SERVER['CONTENT_TYPE']='multipart/form-data; boundary=test';$_SERVER['CONTENT_LENGTH']=200;$_GET=${value({tipo:'REQUERIMIENTO',...get})};$_POST=${value(post)};$_FILES=[];$_SESSION=['novedades_gestion_token'=>'csrf'];${extra} require '/app/novedades_gestion_api.php';`);
 const r=await php.run({scriptPath:'/app/test-api.php'});assert.equal(r.errors,'',label+': PHP errors');assert.equal(r.httpStatusCode,status,label+': status '+r.text);const data=JSON.parse(r.text);assert.equal(data.ok,status===200,label);checks.push(label);return data;
}
await run('GET catalogue requirements','GET',{accion:'catalogo'});
await run('GET catalogue audits','GET',{tipo:'AUDITORIA',accion:'catalogo'});
await run('POST valid assignment stores','POST',{},base);
await run('POST invalid CSRF rejected','POST',{}, {...base,token:'wrong'},403);
await run('POST missing CSRF rejected','POST',{}, {FECHA:'2026-08-28'},403);
await run('POST empty multipart rejected','POST',{}, {},413);
await run('POST wrong chief supervisor rejected','POST',{}, {...base,SUPERVISORES:['Carlos Campo']},409);
await run('POST invalid type rejected','POST',{}, {...base,RESPONSABLE_TIPO:'ADMIN'},409);
await run('POST new contact guarded','POST',{accion:'guardar_responsable'}, {NOMBRE:'Nuevo Técnico',token:'csrf'});
await run('POST readonly cannot add contact','POST',{accion:'guardar_responsable'}, {NOMBRE:'Nuevo Técnico',token:'csrf'},409,false);
await run('POST readonly cannot create record','POST',{},base,409,false);
await run('POST excessive request rejected','POST',{},base,413,true,"$_SERVER['CONTENT_LENGTH']=6291457;");
await run('POST upload too large rejected','POST',{},base,409,true,"$_FILES=['ADJUNTO'=>['error'=>UPLOAD_ERR_INI_SIZE]];");
await run('POST unknown action rejected','POST',{accion:'unexpected'},base,400);
await run('DELETE not allowed','DELETE',{}, {},405);
await run('invalid module rejected','GET',{tipo:'unknown'}, {},400);
console.log('Actual PHP API tests:',checks.length);fs.writeFileSync('patch_validation/api-results.json',JSON.stringify({runtime:'PHP 7.4.33 WASM; simulated SQL adapter',checks},null,2));
