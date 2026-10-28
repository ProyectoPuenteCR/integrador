<?php
require_once __DIR__.'/tecss_3sigma_events.php';

// Intervals of the recorded AF condition, never a conversion of CONT_EXCESOS.
// A bad sample breaks continuity; a left boundary or unfinished interval is partial.
function sigma_condition_intervals(array $samples, DateTimeImmutable $from, DateTimeImmutable $through)
{
    usort($samples,function($a,$b){return (float)(new DateTimeImmutable($a['time']))->format('U.u')<=>(float)(new DateTimeImmutable($b['time']))->format('U.u');});
    $out=[];$active=null;$previous=null;$last=null;
    $finish=function($state,$end,$until)use(&$active,&$out,$from,$through){
        if(!$active)return;
        $start=new DateTimeImmutable($active['start']);$stop=new DateTimeImmutable($until);
        if($stop>=$from&&$start<$through){
            $active['end']=$end;$active['observed_until']=$until;$active['state']=$state;
            $active['duration_seconds']=max(0,(float)$stop->format('U.u')-(float)$start->format('U.u'));
            $active['complete']=$state==='closed'&&$active['start_known'];
            $out[]=$active;
        }
        $active=null;
    };
    foreach($samples as $sample){
        $time=new DateTimeImmutable($sample['time']);
        if($time>$through)break;
        $value=$sample['value'];
        if($value===null){if($active)$finish('gap',null,$last);$previous=null;$last=null;continue;}
        if($value===1){
            if(!$active)$active=['start'=>$sample['time'],'start_known'=>$previous===0&&$time>=$from];
        }elseif($active){$finish('closed',$sample['time'],$sample['time']);}
        $previous=$value;$last=$sample['time'];
    }
    if($active&&$last)$finish('open',null,$last);
    return $out;
}

function sigma_af_series(callable $get,array $attribute,DateTimeImmutable $from,DateTimeImmutable $through,$condition=false)
{
    $result=$get('/streams/'.rawurlencode($attribute['WebId']).'/recorded?'.http_build_query([
        'startTime'=>$from->format(DATE_ATOM),'endTime'=>$through->format(DATE_ATOM),
        'boundaryType'=>'Outside','maxCount'=>20001]));
    if(!is_array($result['Items']??null)||count($result['Items'])>20000||!empty($result['Links']['Next']))throw new RuntimeException('El histórico PI supera el límite de muestras; reducí el período.');
    $samples=[];$unit=null;
    foreach($result['Items'] as $item){
        $time=sigma_af_date($item['Timestamp']??null);
        if(!$time)throw new RuntimeException('Muestra PI sin fecha válida.');
        $value=($item['Good']??false)===true&&is_numeric($item['Value']??null)?(float)$item['Value']:null;
        if($value!==null&&!is_finite($value))$value=null;
        if($condition)$value=$value===0.0?0:($value===1.0?1:null);
        $samples[]=['time'=>$time,'value'=>$value];
        if($value!==null&&$unit===null)$unit=(string)($item['UnitsAbbreviation']??'');
    }
    return ['name'=>$attribute['Name'],'unit'=>$unit??'','samples'=>$samples];
}

function sigma_af_trend(callable $get,$well,DateTimeImmutable $from,DateTimeImmutable $through)
{
    if(preg_match('/[\\\\|*?\[\]\x00-\x1f]/',$well))throw new RuntimeException('Pozo no compatible con la búsqueda AF.');
    $name=stripos($well,'YPF.SC.')===0?$well:'YPF.SC.'.$well;
    $path='\\\\SWPCRDLHSCD51\\IUP\\Instalaciones\\Pozos\\'.$name.'\\TECSS';
    $element=$get('/elements?'.http_build_query(['path'=>$path]));
    if(empty($element['WebId'])||strcasecmp($element['Path']??'',$path)!==0)throw new RuntimeException('No se encontró el elemento TECSS exacto del pozo.');
    $attributes=$get('/elements/'.rawurlencode($element['WebId']).'/attributes?maxCount=1001');
    if(!is_array($attributes['Items']??null)||count($attributes['Items'])>1000)throw new RuntimeException('Catálogo AF incompleto.');
    $map=[];foreach($attributes['Items'] as $a)$map[$a['Name']]=$a;
    $now=new DateTimeImmutable('now');$until=$through<$now?$through:$now;
    $out=['available'=>true,'intervals'=>[],'series'=>[],'warnings'=>[],'notification_status'=>null,'checked_at'=>$now->format(DATE_ATOM)];
    foreach(['VI-001-V','3SIGMA','3SIGMA_SUP'] as $key){
        try{
            if(empty($map[$key]['WebId']))throw new RuntimeException('Atributo ausente.');
            $series=sigma_af_series($get,$map[$key],$from,$until,$key==='3SIGMA_SUP');
            if($key==='3SIGMA_SUP'){
                // Verified formula on this installation. Do not interpret a changed formula.
                if(preg_replace('/\s+/','',$map[$key]['ConfigString']??'')!=='A=VI-001-V;B=3SIGMA;[ifA>Bthen1else0]')throw new RuntimeException('La fórmula de exceso cambió.');
                if(!array_filter($series['samples'],function($s)use($from,$until){$t=new DateTimeImmutable($s['time']);return $s['value']!==null&&$t>=$from&&$t<=$until;}))throw new RuntimeException('Sin muestras válidas en el período.');
                $out['intervals']=sigma_condition_intervals($series['samples'],$from,$until);
                $out['intervals_available']=true;
            }else{
                // Retain one outside boundary sample at the range start for step plotting.
                $inside=[];$before=null;
                foreach($series['samples'] as $sample){$t=new DateTimeImmutable($sample['time']);if($t<$from)$before=$sample;elseif($t<=$until)$inside[]=$sample;}
                if($before){$before['time']=$from->format(DATE_ATOM);array_unshift($inside,$before);}
                // The verified 3SIGMA PI Point is step=1: retain its last value up
                // to the requested end, without inventing an extra measurement.
                if($key==='3SIGMA'&&preg_match('/(?:^|;)step=1(?:;|$)/i',$map[$key]['ConfigString']??'')&&$inside){
                    $last=$inside[count($inside)-1];
                    if($last['value']!==null&&new DateTimeImmutable($last['time'])<$until){$last['time']=$until->format(DATE_ATOM);$last['held']=true;$inside[]=$last;}
                }
                $series['samples']=$inside;$out['series'][$key]=$series;
            }
        }catch(RuntimeException $e){$out['warnings'][]='No se pudo leer '.$key.': '.$e->getMessage();}
    }
    try{
        $rules=$get('/elements/'.rawurlencode($element['WebId']).'/notificationrules?maxCount=1001');
        if(!is_array($rules['Items']??null)||count($rules['Items'])>1000)throw new RuntimeException('Reglas incompletas.');
        $matches=array_values(array_filter($rules['Items'],function($r){return ($r['Name']??'')==='3Sigma Superado';}));
        if(count($matches)===1)$out['notification_status']=$matches[0]['Status']??null;
    }catch(RuntimeException $e){$out['warnings'][]='No se pudo comprobar el estado actual de la regla de notificación.';}
    try{
        $analyses=$get('/elements/'.rawurlencode($element['WebId']).'/analyses?maxCount=1001');
        if(!is_array($analyses['Items']??null)||count($analyses['Items'])>1000)throw new RuntimeException('Análisis incompletos.');
        $matches=array_values(array_filter($analyses['Items'],function($a){return ($a['Name']??'')==='3Sigma_Superado';}));
        if(count($matches)!==1)throw new RuntimeException('Análisis ambiguo o ausente.');
        $out['analysis_status']=$matches[0]['Status']??null;
        // Follow only the inspected PI route, never an absolute URL from a response.
        $rulePath=parse_url($matches[0]['Links']['AnalysisRule']??'',PHP_URL_PATH);
        if(!is_string($rulePath)||!preg_match('#^/piwebapi/analysisrules/[A-Za-z0-9_-]+$#D',$rulePath))throw new RuntimeException('Ruta de análisis no válida.');
        $children=$get(substr($rulePath,strlen('/piwebapi')).'/analysisrules?maxCount=1001');
        $out['minimum_duration_seconds']=sigma_af_minimum_duration($children['Items']??[]);
        if($out['minimum_duration_seconds']===null)throw new RuntimeException('Duración mínima no reconocida.');
    }catch(RuntimeException $e){$out['warnings'][]='No se pudo verificar el tiempo mínimo configurado; no se presume una duración requerida.';}
    return $out;
}

function sigma_af_minimum_duration(array $rules)
{
    $values=[];
    foreach($rules as $rule){
        if(($rule['Name']??'')!=='PerformanceEquation'||($rule['IsConfigured']??false)!==true)continue;
        $config=(string)($rule['ConfigString']??'');
        // Restrict this to the single start trigger inspected on the installation.
        if(preg_match_all('/@timetrue\("([0-9]+)"\)\s*StartTrigger1\s*:=/',$config,$m)===1&&substr_count($config,'@timetrue(')===1)$values[]=(int)$m[1][0];
    }
    return count($values)===1&&$values[0]>0?$values[0]:null;
}
