<?php
/* =============================================================
   CLEAR · Novedades semanales
   Núcleo desacoplado para que el módulo pueda migrarse a un
   subsitio sin modificar las pantallas operativas actuales.
   Compatible con PHP 7.4.
============================================================= */

function ns_value(array $row, $key, $default = '')
{
    foreach ($row as $column => $value) {
        if (strcasecmp((string)$column, (string)$key) === 0) return $value;
    }
    return $default;
}

function ns_parse_date($value)
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function ns_week_for_date(DateTimeImmutable $date)
{
    $date = $date->setTime(0, 0, 0);
    $daysSinceWednesday = (((int)$date->format('N')) - 3 + 7) % 7;
    $start = $daysSinceWednesday ? $date->modify('-' . $daysSinceWednesday . ' days') : $date;
    return ['start'=>$start, 'end'=>$start->modify('+6 days'), 'next'=>$start->modify('+7 days')];
}

function ns_selected_week(array $query, DateTimeImmutable $today)
{
    $candidate = ns_parse_date($query['semana'] ?? '');
    return ns_week_for_date($candidate ?: $today);
}

function ns_preferences()
{
    $raw=function_exists('user_pref_get')?(string)user_pref_get('novedades_semanales_preferences','{}'):'{}';
    $prefs=json_decode($raw,true);
    return is_array($prefs)?$prefs:[];
}

function ns_period(array $query, DateTimeImmutable $today, array $preferences = [])
{
    $current=ns_week_for_date($today);
    $legacy=ns_parse_date($query['semana']??'');
    $defaultWeeks=(int)($preferences['period_weeks']??8);
    if(!in_array($defaultWeeks,[1,4,8,12,16],true))$defaultWeeks=8;
    $fromCandidate=ns_parse_date($query['desde']??'');
    $toCandidate=ns_parse_date($query['hasta']??'');
    if($legacy){$fromCandidate=$legacy;$toCandidate=$legacy;}
    $toWeek=ns_week_for_date($toCandidate?:$current['start']);
    $fromWeek=ns_week_for_date($fromCandidate?:$toWeek['start']->modify('-'.(($defaultWeeks-1)*7).' days'));
    if($fromWeek['start']>$toWeek['start']){$tmp=$fromWeek;$fromWeek=$toWeek;$toWeek=$tmp;}
    if($toWeek['start']>$current['start'])$toWeek=$current;
    if($fromWeek['start']>$toWeek['start'])$fromWeek=$toWeek;
    if($fromWeek['start']<$toWeek['start']->modify('-721 days'))$fromWeek=ns_week_for_date($toWeek['start']->modify('-721 days'));
    $weeks=max(1,(int)floor($fromWeek['start']->diff($toWeek['start'])->days/7)+1);
    return [
        'from'=>$fromWeek['start'],'to'=>$toWeek['start'],'end'=>$toWeek['end'],'next'=>$toWeek['next'],'weeks'=>$weeks,
        'from_value'=>$fromWeek['start']->format('Y-m-d'),'to_value'=>$toWeek['start']->format('Y-m-d'),
    ];
}

function ns_period_label(array $period)
{
    if($period['from']->format('Y-m-d')===$period['to']->format('Y-m-d'))return ns_week_label($period['from']);
    return 'Semanas '.$period['from']->modify('+6 days')->format('W').' a '.$period['to']->modify('+6 days')->format('W').' · '.$period['from']->format('d/m/Y').' al '.$period['end']->format('d/m/Y');
}

function ns_week_label(DateTimeImmutable $start, $withRange = true)
{
    $end = $start->modify('+6 days');
    $label = 'Semana ' . $end->format('W');
    if ($withRange) $label .= ' · ' . $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y');
    return $label;
}

function ns_clean($value, $max = 180)
{
    $value = trim((string)$value);
    if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value)) return '';
    return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}

function ns_num($value, $decimals = 0)
{
    return number_format((float)$value, (int)$decimals, ',', '.');
}

function ns_percent($value)
{
    return number_format((float)$value, 1, ',', '.') . '%';
}

function ns_module_ready($db)
{
    return $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_NOVEDADES_SEMANALES_CACHE',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
}

function ns_comments_ready($db)
{
    return $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_NOVEDADES_SEMANALES_COMENTARIOS',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
}

function ns_report_ready($db)
{
    return $db->ok() && (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
}

function ns_report_items($db, $user)
{
    if(!ns_report_ready($db))return [];
    return $db->all("SELECT ID,ITEM_KEY,ITEM_TIPO,TITULO,PAYLOAD_JSON,CONVERT(varchar(19),COALESCE(FECHA_CAMBIO,FECHA_CARGA),120) AS FECHA FROM dbo.CLEAR_NOVEDADES_SEMANALES_REPORTE_ITEMS WHERE USUARIO=? AND ACTIVO=1 ORDER BY FECHA_CARGA,ID",[(string)$user]);
}

function ns_type_options()
{
    return ['POZO'=>'Pozo','BATERÍA'=>'Batería','SATÉLITE'=>'Satélite','GAS'=>'Gas','PIAS'=>'PIAS','ENERGÍA'=>'Energía','PLANTA TRAT.'=>'Planta Trat.','PLANTA LH'=>'Planta LH','SIN CLASIFICAR'=>'Sin clasificar'];
}

function ns_type_class($value)
{
    $value = strtoupper(trim((string)$value));
    if ($value === 'POZO') return 'is-well';
    if ($value === 'BATERÍA' || $value === 'BATERIA') return 'is-battery';
    if ($value === 'SATÉLITE' || $value === 'SATELITE') return 'is-satellite';
    if ($value === 'GAS') return 'is-gas';
    if ($value === 'PIAS') return 'is-pias';
    if ($value === 'ENERGÍA' || $value === 'ENERGIA') return 'is-energy';
    if ($value === 'PLANTA TRAT.' || $value === 'PLANTA TRAT') return 'is-plant';
    if ($value === 'PLANTA LH') return 'is-plant-lh';
    return 'is-unknown';
}

function ns_comment_key($weekStart, $type, $installation, $tag)
{
    return strtoupper(trim((string)$weekStart) . '|' . trim((string)$type) . '|' . trim((string)$installation) . '|' . trim((string)$tag));
}

function ns_bad_actor_filters(array $query, array $period)
{
    $type = ns_clean($query['tipo_instalacion'] ?? '', 30);
    if ($type !== '' && !array_key_exists($type, ns_type_options())) $type = '';
    $sort = strtolower(ns_clean($query['orden'] ?? 'total', 30));
    if (!in_array($sort, ['semana','total','tipo','instalacion','tag','descripcion','campo_ext'], true)) $sort = 'total';
    $direction = strtolower((string)($query['direccion'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';
    $comment = strtolower(ns_clean($query['comentario'] ?? '', 10));
    if (!in_array($comment, ['con','sin'], true)) $comment = '';
    $minimum = trim((string)($query['total_desde'] ?? ''));
    $maximum = trim((string)($query['total_hasta'] ?? ''));
    $minimum = ($minimum !== '' && ctype_digit($minimum)) ? (int)$minimum : null;
    $maximum = ($maximum !== '' && ctype_digit($maximum)) ? (int)$maximum : null;
    $selectedWeek = ns_parse_date($query['semana_filtro'] ?? '');
    if ($selectedWeek) {
        $selectedWeek = ns_week_for_date($selectedWeek)['start'];
        if ($selectedWeek < $period['from'] || $selectedWeek > $period['to']) $selectedWeek = null;
    }
    return [
        'week_start'=>$period['from_value'],
        'week_end'=>$period['to_value'],
        'selected_week'=>$selectedWeek ? $selectedWeek->format('Y-m-d') : '',
        'period_end'=>$period['end']->format('Y-m-d'),
        'type'=>$type,
        'installation'=>ns_clean($query['instalacion'] ?? '', 180),
        'tag'=>ns_clean($query['tag'] ?? '', 180),
        'description'=>ns_clean($query['descripcion'] ?? '', 240),
        'external'=>ns_clean($query['campo_ext'] ?? '', 180),
        'q'=>ns_clean($query['q'] ?? '', 240),
        'minimum'=>$minimum,
        'maximum'=>$maximum,
        'comment'=>$comment,
        'sort'=>$sort,
        'direction'=>$direction,
    ];
}

function ns_bad_actor_where(array $filters, array &$params, $commentsReady = true)
{
    $where = ['C.SEMANA_DESDE>=CONVERT(date,?,23)','C.SEMANA_DESDE<=CONVERT(date,?,23)'];
    $params = [$filters['week_start'],$filters['week_end']];
    if ($filters['selected_week'] !== '') { $where[]='C.SEMANA_DESDE=CONVERT(date,?,23)'; $params[]=$filters['selected_week']; }
    if ($filters['type'] !== '') { $where[]='C.TIPO_INSTALACION=?'; $params[]=$filters['type']; }
    if ($filters['installation'] !== '') { $where[]='C.INSTALACION LIKE ?'; $params[]='%'.$filters['installation'].'%'; }
    if ($filters['tag'] !== '') { $where[]='C.TAG LIKE ?'; $params[]='%'.$filters['tag'].'%'; }
    if ($filters['description'] !== '') { $where[]='C.DESCRIPCION LIKE ?'; $params[]='%'.$filters['description'].'%'; }
    if ($filters['external'] !== '') { $where[]='C.CAMPO_EXT LIKE ?'; $params[]='%'.$filters['external'].'%'; }
    if ($filters['minimum'] !== null) { $where[]='C.TOTAL_ALARMAS>=?'; $params[]=$filters['minimum']; }
    if ($filters['maximum'] !== null) { $where[]='C.TOTAL_ALARMAS<=?'; $params[]=$filters['maximum']; }
    if ($filters['q'] !== '') {
        foreach (array_values(array_filter(preg_split('/\s+/', $filters['q']))) as $term) {
            $where[]='(C.INSTALACION LIKE ? OR C.TAG LIKE ? OR C.DESCRIPCION LIKE ? OR C.CAMPO_EXT LIKE ?)';
            for ($i=0; $i<4; $i++) $params[]='%'.$term.'%';
        }
    }
    if ($commentsReady && $filters['comment'] === 'con') $where[]='CO.ACTIVO=1 AND CO.COMENTARIO<>N\'\'';
    if ($commentsReady && $filters['comment'] === 'sin') $where[]='(CO.ID IS NULL OR CO.ACTIVO=0 OR CO.COMENTARIO=N\'\')';
    return $where;
}

function ns_order_sql(array $filters)
{
    $columns = ['semana'=>'C.SEMANA_DESDE','total'=>'C.TOTAL_ALARMAS','tipo'=>'C.TIPO_INSTALACION','instalacion'=>'C.INSTALACION','tag'=>'C.TAG','descripcion'=>'C.DESCRIPCION','campo_ext'=>'C.CAMPO_EXT'];
    $column = $columns[$filters['sort']] ?? 'C.TOTAL_ALARMAS';
    $direction = $filters['direction'] === 'asc' ? 'ASC' : 'DESC';
    return $column . ' ' . $direction . ',C.INSTALACION ASC,C.TAG ASC';
}

function ns_cache_select($alias = 'C')
{
    $a=preg_replace('/[^A-Za-z0-9_]/','',(string)$alias);
    if ($a==='') $a='C';
    return "CONVERT(varchar(10),$a.SEMANA_DESDE,23) AS SEMANA_DESDE," .
        "CONVERT(varchar(10),$a.SEMANA_HASTA,23) AS SEMANA_HASTA," .
        "$a.TIPO_INSTALACION,$a.INSTALACION,$a.TAG,$a.DESCRIPCION,$a.TOTAL_ALARMAS,$a.CAMPO_EXT," .
        "CONVERT(varchar(19),$a.FECHA_ACTUALIZACION,120) AS FECHA_ACTUALIZACION";
}

function ns_query_string(array $overrides = [], array $remove = [])
{
    $query = $_GET;
    foreach ($remove as $key) unset($query[$key]);
    foreach ($overrides as $key=>$value) {
        if ($value === null || $value === '') unset($query[$key]); else $query[$key]=$value;
    }
    return http_build_query($query);
}

function ns_sort_link($key, $label)
{
    $current = strtolower((string)($_GET['orden'] ?? 'total'));
    $direction = strtolower((string)($_GET['direccion'] ?? 'desc'));
    $next = ($current === $key && $direction === 'asc') ? 'desc' : 'asc';
    $mark = $current === $key ? ($direction === 'asc' ? '↑' : '↓') : '↕';
    return '<a class="nsSort" href="?' . h(ns_query_string(['orden'=>$key,'direccion'=>$next,'pagina'=>1])) . '">' . h($label) . '<span>' . $mark . '</span></a>';
}

function ns_hidden_inputs(array $exclude)
{
    foreach ($_GET as $key=>$value) {
        if (in_array($key, $exclude, true) || is_array($value) || $value === '') continue;
        echo '<input type="hidden" name="' . h($key) . '" value="' . h($value) . '">';
    }
}

function ns_render_module_nav($active)
{
    $items = [
        'novedades_semanales_panel'=>['Panel semanal','novedades_semanales.php'],
        'novedades_semanales_monitoreo'=>['Monitoreo PUMP OFF','novedades_semanales_monitoreo.php'],
        'novedades_semanales_malos_actores'=>['Malos actores','novedades_semanales_malos_actores.php'],
        'novedades_semanales_comparativa'=>['Comparativa','novedades_semanales_comparativa.php'],
        'novedades_semanales_seguimiento'=>['Seguimiento','novedades_semanales_seguimiento.php'],
        'novedades_semanales_reporte'=>['Reporte','novedades_semanales_reporte.php'],
    ];
    echo '<nav class="nsModuleNav" aria-label="Novedades semanales">';
    foreach ($items as $key=>$item) {
        if (!permissions_can_menu($key)) continue;
        if ($key === 'novedades_semanales_reporte') {
            echo '<button type="button" class="nsModuleNav__button ' . ($active===$key?'is-active':'') . '" data-ns-report-open>' . h($item[0]) . '<span class="nsReportCount" data-ns-report-count hidden>0</span></button>';
        } else {
            echo '<a class="' . ($active===$key?'is-active':'') . '" href="' . h($item[1]) . '">' . h($item[0]) . '</a>';
        }
    }
    echo '</nav>';
}

function ns_report_key($source, array $identity)
{
    return ns_clean($source,40).':'.sha1(json_encode($identity,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
}

function ns_report_pick($key, $type, $title, array $payload, $label = 'Agregar a reporte')
{
    $encoded=base64_encode(json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));
    return '<label class="nsReportPick" title="'.h($label).'"><input type="checkbox" data-ns-report-add data-report-key="'.h($key).'" data-report-type="'.h($type).'" data-report-title="'.h($title).'" data-report-payload="'.h($encoded).'"><span>'.h($label).'</span></label>';
}

function ns_report_row_payload(array $columns)
{
    return ['kind'=>'row','columns'=>$columns];
}

function ns_chart_type($value, $default = 'line')
{
    $value=strtolower(trim((string)$value));
    return in_array($value,['line','bar','doughnut'],true)?$value:$default;
}

function ns_render_not_installed()
{
    echo '<div class="nsNotice is-warning"><b>El módulo todavía no tiene instalada su caché.</b> Ejecutá una sola vez <code>SQL/CLEAR_NOVEDADES_SEMANALES.sql</code>. Las pantallas existentes continúan funcionando normalmente.</div>';
}

function ns_render_comment_button(array $row, $weekLabel, $canUseComments)
{
    if (!$canUseComments) return '<span class="nsCommentPreview">Sin permiso</span>';
    $hasComment=(int)ns_value($row,'COMENTARIO_ACTIVO',0)===1 && trim((string)ns_value($row,'COMENTARIO'))!=='';
    $title=$hasComment?'Ver o editar comentario semanal':'Agregar comentario semanal';
    return '<button type="button" class="nsCommentButton ' . ($hasComment?'has-comment':'') . '" data-ns-comment ' .
        'data-week-start="'.h(ns_value($row,'SEMANA_DESDE')).'" data-week-end="'.h(ns_value($row,'SEMANA_HASTA')).'" ' .
        'data-week-label="'.h($weekLabel).'" data-type="'.h(ns_value($row,'TIPO_INSTALACION')).'" ' .
        'data-installation="'.h(ns_value($row,'INSTALACION')).'" data-tag="'.h(ns_value($row,'TAG')).'" title="'.h($title).'" aria-label="'.h($title).'">'.icon('message').'</button>';
}

function ns_render_comment_modal($canCreate)
{
    ?>
    <div class="nsModalOverlay" id="nsCommentOverlay" hidden></div>
    <section class="nsModal" id="nsCommentModal" aria-hidden="true" aria-labelledby="nsCommentTitle">
      <div class="nsModal__head"><div><h2 id="nsCommentTitle">Comentario semanal</h2><p id="nsCommentMeta">—</p></div><button type="button" class="nsModal__close" id="nsCommentClose" aria-label="Cerrar">×</button></div>
      <div class="nsModal__body"><div class="nsModal__previous" id="nsCommentPrevious" hidden></div><label for="nsCommentText">Justificación del mal actor durante la semana</label><textarea id="nsCommentText" maxlength="2000" placeholder="Escribí la gestión, causa o acción pendiente..."<?php echo $canCreate?'':' readonly'; ?>></textarea><div class="nsModal__status" id="nsCommentStatus" hidden></div></div>
      <div class="nsModal__foot"><button type="button" class="nsButton is-secondary" id="nsCommentCancel">Cerrar</button><?php if($canCreate): ?><button type="button" class="nsButton" id="nsCommentSave">Guardar comentario</button><?php endif; ?></div>
    </section>
    <?php
}
