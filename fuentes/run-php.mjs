import fs from 'node:fs';import path from 'node:path';
import {getPHPLoaderModule} from '../patch_test_tools/node_modules/@php-wasm/node-7-4/index.js';
import {PHP,loadPHPRuntime} from '../patch_test_tools/node_modules/@php-wasm/universal/index.js';
import Engine from '../patch_test_tools/node_modules/php-parser/src/index.js';
const root=path.resolve('clear_patch_work/clear');
const php=new PHP(await loadPHPRuntime(await getPHPLoaderModule()));php.mkdir('/app');
const parser=new Engine({parser:{version:'7.4'},ast:{withPositions:true}});
let files=[];
function copy(dir,rel=''){for(const d of fs.readdirSync(dir,{withFileTypes:true})){const r=path.join(rel,d.name);if(d.isDirectory()){php.mkdir('/app/'+r);copy(path.join(dir,d.name),r);}else if(/\.php$/.test(d.name)&&!['config.php','config_pi.php','mail_config.php','pumpoff_config.php','reporte_pozos_config.php'].includes(d.name)){const src=fs.readFileSync(path.join(dir,d.name),'utf8');php.writeFile('/app/'+r,src);if(!r.startsWith('ARCHIVOS_WEB/')){parser.parseCode(src,r);files.push(r);}}}}
copy(root);
php.writeFile('/unit.php',fs.readFileSync('patch_validation/unit.php','utf8'));
const r=await php.run({scriptPath:'/unit.php'});if(r.errors||r.exitCode){console.error(r.errors,r.text);process.exit(1);}const result=JSON.parse(r.text);console.log('PHP 7.4.33 tests:',result.passed,'assertions; PHP 7.4 parsed files:',files.length);fs.writeFileSync('patch_validation/php-results.json',JSON.stringify({runtime:'PHP 7.4.33 WASM',syntaxFiles:files.length,...result},null,2));
