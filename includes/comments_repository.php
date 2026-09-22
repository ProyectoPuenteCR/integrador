<?php

function clear_comments_sources()
{
    return ['alarmas'=>'Alarmas y reconocimientos','novedades'=>'Novedades semanales','instalaciones'=>'Top 20 instalaciones','pozos'=>'Top 20 pozos'];
}

function clear_comments_union_sql($db)
{
    // La fila vacía fija nombres y tipos aunque alguna instalación todavía no tenga todas las tablas opcionales.
    $parts=["SELECT CAST(NULL AS bigint) ID,CAST(N'' AS nvarchar(20)) ORIGEN,CAST(N'' AS nvarchar(255)) ASUNTO,CAST(N'' AS nvarchar(500)) CONTEXTO,CAST(N'' AS nvarchar(max)) COMENTARIO,CAST(N'' AS nvarchar(128)) USUARIO,CAST(NULL AS datetime2(0)) FECHA_CARGA,CAST(NULL AS datetime2(0)) FECHA_ACTUALIZACION,CAST(NULL AS date) FECHA_DESDE,CAST(NULL AS date) FECHA_HASTA WHERE 1=0"];
    $exists=function($table)use($db){return (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.".$table."',N'U') IS NULL THEN 0 ELSE 1 END")===1;};
    if($exists('FIXALARMS_COMENTARIOS'))$parts[]="SELECT CAST(ID AS bigint) ID,N'alarmas' ORIGEN,CONVERT(nvarchar(255),TAG_FIX) ASUNTO,CONCAT(CASE WHEN NULLIF(LTRIM(RTRIM(CONVERT(nvarchar(255),OPERADOR))),N'') IS NULL THEN N'' ELSE N'Operador: '+CONVERT(nvarchar(255),OPERADOR)+N' · ' END,COALESCE(NULLIF(CONVERT(nvarchar(500),MOTIVO),N''),N'Comentario operativo')) CONTEXTO,CONVERT(nvarchar(max),COMENTARIO) COMENTARIO,CONVERT(nvarchar(128),USUARIO_CARGA) USUARIO,CONVERT(datetime2(0),FECHA_CARGA) FECHA_CARGA,CONVERT(datetime2(0),COALESCE(FECHA_MODIFICACION,FECHA_CARGA)) FECHA_ACTUALIZACION,CONVERT(date,FECHA_RECONOCIMIENTO) FECHA_DESDE,CONVERT(date,FECHA_RECONOCIMIENTO) FECHA_HASTA FROM dbo.FIXALARMS_COMENTARIOS WHERE ACTIVO=1 AND NULLIF(LTRIM(RTRIM(COMENTARIO)),N'') IS NOT NULL";
    if($exists('CLEAR_NOVEDADES_SEMANALES_COMENTARIOS'))$parts[]="SELECT CAST(ID AS bigint),N'novedades',CONVERT(nvarchar(255),TAG),CONCAT(CONVERT(nvarchar(255),TIPO_INSTALACION),N' · ',CONVERT(nvarchar(255),INSTALACION)),CONVERT(nvarchar(max),COMENTARIO),CONVERT(nvarchar(128),COALESCE(USUARIO_MODIFICACION,USUARIO_CARGA)),CONVERT(datetime2(0),FECHA_CARGA),CONVERT(datetime2(0),COALESCE(FECHA_MODIFICACION,FECHA_CARGA)),CONVERT(date,SEMANA_DESDE),CONVERT(date,SEMANA_HASTA) FROM dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS WHERE ACTIVO=1 AND NULLIF(LTRIM(RTRIM(COMENTARIO)),N'') IS NOT NULL";
    if($exists('FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES'))$parts[]="SELECT CAST(ID AS bigint),N'instalaciones',CONVERT(nvarchar(255),INSTALACION),N'Comentario semanal de instalación',CONVERT(nvarchar(max),COMENTARIO),CONVERT(nvarchar(128),COALESCE(USUARIO_MODIFICACION,USUARIO_CARGA)),CONVERT(datetime2(0),FECHA_CARGA),CONVERT(datetime2(0),COALESCE(FECHA_MODIFICACION,FECHA_CARGA)),CONVERT(date,SEMANA_DESDE),CONVERT(date,SEMANA_HASTA) FROM dbo.FIXALARMS_INSTALACION_COMENTARIOS_SEMANALES WHERE ACTIVO=1 AND NULLIF(LTRIM(RTRIM(COMENTARIO)),N'') IS NOT NULL";
    if($exists('FIXALARMS_POZO_COMENTARIOS_SEMANALES'))$parts[]="SELECT CAST(ID AS bigint),N'pozos',CONVERT(nvarchar(255),POZO),N'Comentario semanal de pozo',CONVERT(nvarchar(max),COMENTARIO),CONVERT(nvarchar(128),COALESCE(USUARIO_MODIFICACION,USUARIO_CARGA)),CONVERT(datetime2(0),FECHA_CARGA),CONVERT(datetime2(0),COALESCE(FECHA_MODIFICACION,FECHA_CARGA)),CONVERT(date,SEMANA_DESDE),CONVERT(date,SEMANA_HASTA) FROM dbo.FIXALARMS_POZO_COMENTARIOS_SEMANALES WHERE ACTIVO=1 AND NULLIF(LTRIM(RTRIM(COMENTARIO)),N'') IS NOT NULL";
    return $parts?implode(' UNION ALL ',$parts):'';
}

function clear_comments_search($db,array $filters,$page=1,$perPage=100)
{
    $union=clear_comments_union_sql($db);if($union==='')return ['rows'=>[],'total'=>0,'page'=>1,'pages'=>1];
    $where=['1=1'];$params=[];
    if(!empty($filters['source'])&&isset(clear_comments_sources()[$filters['source']])){$where[]='ORIGEN=?';$params[]=$filters['source'];}
    if(!empty($filters['from'])){$where[]='FECHA_ACTUALIZACION>=CONVERT(date,?,23)';$params[]=$filters['from'];}
    if(!empty($filters['to'])){$where[]='FECHA_ACTUALIZACION<DATEADD(day,1,CONVERT(date,?,23))';$params[]=$filters['to'];}
    if(!empty($filters['q'])){$where[]='(ASUNTO LIKE ? OR CONTEXTO LIKE ? OR COMENTARIO LIKE ? OR USUARIO LIKE ?)';$like='%'.$filters['q'].'%';array_push($params,$like,$like,$like,$like);}
    $base=' FROM ('.$union.') C WHERE '.implode(' AND ',$where);$total=(int)$db->scalar('SELECT COUNT_BIG(*)'.$base,$params);$pages=max(1,(int)ceil($total/$perPage));$page=max(1,min($pages,(int)$page));$offset=($page-1)*$perPage;
    return ['rows'=>$db->all('SELECT *'.$base.' ORDER BY FECHA_ACTUALIZACION DESC,ORIGEN,ID DESC OFFSET '.(int)$offset.' ROWS FETCH NEXT '.(int)$perPage.' ROWS ONLY',$params),'total'=>$total,'page'=>$page,'pages'=>$pages];
}
