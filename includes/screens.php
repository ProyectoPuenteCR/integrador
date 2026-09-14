<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/screens.php
   Define cada pantalla de lista: tabla origen, columnas a mostrar,
   etiquetas, y reglas de formato (badges, mono, números).
   El motor list.php lee esto para renderizar.
============================================================= */

/* Tipos de columna disponibles:
   'text'   -> texto normal
   'mono'   -> fuente monoespaciada (fechas, valores)
   'num'    -> número alineado a la derecha, formato miles
   'badge'  -> pastilla de color según el valor (ver badge maps abajo)
   'prio'   -> badge de prioridad (HIGH/MED/LOW/INFO)
   'status' -> badge de estado de alarma
*/

function clear_screens()
{
    return [

      'alarmas24h' => [
        'titulo'   => 'Alarmas 24h',
        'subtitulo'=> 'Eventos FIX de las últimas 24 horas',
        'icono'    => 'bell',
        'tabla'    => 'dbo.FIXALARMS_24H',
        'orderby'  => 'ALM_NATIVETIMEIN DESC',
        'fecha_col'=> 'ALM_NATIVETIMEIN',
        'tag_col'  => 'ALM_TAGNAME',
        'valor_col'=> 'ALM_VALUE',
        'installation_type' => true,
        'installation_type_external_col' => 'ALM_ALMEXTFLD2',
        'standard_filters' => true,
        'buscar'   => ['ALM_ALMEXTFLD2', 'ALM_TAGNAME', 'ALM_DESCR'],
        'cols' => [
          ['ALM_ALMEXTFLD2',   'Pozo',         'text'],
          ['ALM_NATIVETIMEIN',  'Hora inicio',  'mono'],
          ['ALM_NATIVETIMELAST','Última',       'mono'],
          ['ALM_TAGNAME',       'Tag',          'text'],
          ['ALM_VALUE',         'Valor',        'mono'],
          ['ALM_UNIT',          'Unidad',       'text'],
          ['ALM_DESCR',         'Descripción',  'text'],
          ['ALM_ALMSTATUS',     'Estado',       'status'],
          ['ALM_ALMPRIORITY',   'Prioridad',    'prio'],
        ],
      ],

      'alarmas_activas' => [
        'titulo'   => 'Alarmas por Historicos',
        'subtitulo'=> 'Consulta de alarmas históricas por fecha',
        'icono'    => 'wave',
        'tabla'    => 'dbo.FIXALARMS_ONLY',
        'orderby'  => 'ALM_NATIVETIMEIN DESC',
        'fecha_col'=> 'ALM_NATIVETIMEIN',
        'tag_col'  => 'ALM_TAGNAME',
        'installation_type' => true,
        'installation_type_external_col' => 'ALM_ALMEXTFLD2',
        'standard_filters' => true,
        'buscar'   => ['ALM_TAGNAME', 'ALM_DESCR'],
        'cols' => [
          ['ALM_NATIVETIMEIN',  'Hora inicio',  'mono'],
          ['ALM_NATIVETIMELAST','Última',       'mono'],
          ['ALM_TAGNAME',       'Tag',          'text'],
          ['ALM_VALUE',         'Valor',        'mono'],
          ['ALM_UNIT',          'Unidad',       'text'],
          ['ALM_DESCR',         'Descripción',  'text'],
          ['ALM_ALMSTATUS',     'Estado',       'status'],
          ['ALM_ALMPRIORITY',   'Prioridad',    'prio'],
        ],
      ],

      'suprimidas' => [
        'titulo'   => 'Alarmas suprimidas 24h',
        'subtitulo'=> 'Eventos suprimidos en las últimas 24 horas',
        'icono'    => 'shield',
        'tabla'    => 'dbo.FIXALARMS_SUPRIMIDAS24H',
        'orderby'  => 'FechaHora DESC',
        'fecha_col'=> 'FechaHora',
        'tag_col'  => 'TagID',
        'installation_type' => true,
        'installation_type_external_col' => 'ALM_ALMEXTFLD2',
        'standard_filters' => true,
        'buscar'   => ['TagID', 'Descripcion', 'Usuario'],
        'cols' => [
          ['ID',          'ID',          'num'],
          ['FechaHora',   'Fecha y hora','mono'],
          ['TipoEvento',  'Evento',      'text'],
          ['Usuario',     'Usuario',     'text'],
          ['TagID',       'Tag',         'text'],
          ['Descripcion', 'Descripción', 'text'],
          ['Valor',       'Valor',       'mono'],
        ],
      ],

      'reconocidas' => [
        'titulo'   => 'Alarmas reconocidas',
        'subtitulo'=> 'Reconocimientos registrados por operador',
        'icono'    => 'check',
        'tabla'    => 'dbo.FIXALARMS_RECONOCIDAS',
        'orderby'  => 'ALM_NATIVETIMEIN DESC',
        'fecha_col'=> 'ALM_NATIVETIMEIN',
        'tag_col'  => 'TAG_FIX',
        'installation_type' => true,
        'installation_type_external_col' => 'ALM_ALMEXTFLD2',
        'standard_filters' => true,
        'buscar'   => ['TAG_FIX', 'OPERADOR', 'ALM_DESCR'],
        // Esta vista tiene textos extensos de FIX; usamos una tabla compacta
        // con ajuste de línea y columnas balanceadas para evitar solapamientos.
        'table_class' => 'grid--compact grid--reconocidas',
        'cols' => [
          ['ALM_NATIVETIMEIN', 'Hora',        'mono'],
          ['TAG_FIX',          'Tag',         'text'],
          ['ALM_DESCR',        'Descripción', 'long'],
          ['OPERADOR',         'Operador',    'operator'],
          ['ALARMA_RECONOCIDA','Reconocida',  'long'],
          ['ALM_ALMPRIORITY',  'Prioridad',   'prio'],
        ],
      ],

      'reconocidas_usr' => [
        'titulo'   => 'Reconocidas por usuario',
        'subtitulo'=> 'Ranking de reconocimientos por operador',
        'icono'    => 'check',
        'tabla'    => 'dbo.FIXALARMS_RECONOCIDAS_usr',
        'orderby'  => 'RANKING ASC',
        'tag_col'  => 'TAG_FIX',
        'standard_filters' => true,
        'buscar'   => ['TAG_FIX', 'OPERADOR'],
        'cols' => [
          ['RANKING',                  'Ranking',         'num'],
          ['TAG_FIX',                  'Tag',             'text'],
          ['OPERADOR',                 'Operador',        'operator'],
          ['CANTIDAD_RECONOCIMIENTOS', 'Reconocimientos', 'num'],
        ],
      ],

      'top20_all' => [
        'titulo'   => 'Top 20 alarmas',
        'subtitulo'=> 'Tags más frecuentes (histórico completo)',
        'icono'    => 'chart',
        'tabla'    => 'dbo.FIXALARMS_TOP20_ALL',
        'orderby'  => 'TOTAL_ALARMAS DESC',
        'tag_col'  => 'ALM_TAGNAME',
        'buscar'   => ['ALM_TAGNAME', 'DESCRIPCION'],
        'cols' => [
          ['ALM_TAGNAME',    'Tag',          'text'],
          ['DESCRIPCION',    'Descripción',  'text'],
          ['TOTAL_ALARMAS',  'Total alarmas','num'],
          ['ALM_ALMEXTFLD2', 'Campo ext.',   'text'],
        ],
      ],

      'top20_24h' => [
        'titulo'   => 'Top 20 alarmas 24h',
        'subtitulo'=> 'Tags más frecuentes en las últimas 24 horas',
        'icono'    => 'chart',
        'tabla'    => 'dbo.CLEAR_CACHE_TOP20_24H',
        'orderby'  => 'TOTAL_ALARMAS DESC',
        'tag_col'  => 'ALM_TAGNAME',
        'installation_type' => true,
        'installation_type_external_col' => 'ALM_ALMEXTFLD2',
        'installation_type_charts' => true,
        'comment_timestamp_col' => 'ULTIMA_APARICION',
        'standard_filters' => true,
        'buscar'   => ['ALM_TAGNAME', 'DESCRIPCION'],
        'cols' => [
          ['ALM_TAGNAME',    'Tag',          'text'],
          ['DESCRIPCION',    'Descripción',  'text'],
          ['TOTAL_ALARMAS',  'Total alarmas','num'],
          ['ALM_ALMEXTFLD2', 'Campo ext.',   'text'],
        ],
      ],

      'ranking24h' => [
        'titulo'   => 'Ranking de alarmas 24h',
        'subtitulo'=> 'Repeticiones por tag en las últimas 24 horas',
        'icono'    => 'chart',
        'tabla'    => 'dbo.FIXALARMS_RANK24H',
        'orderby'  => 'Ranking ASC',
        'tag_col'  => 'ALM_TAGNAME',
        'buscar'   => ['ALM_TAGNAME', 'ALM_DESCR'],
        'cols' => [
          ['Ranking',               'Ranking',      'num'],
          ['ALM_TAGNAME',           'Tag',          'text'],
          ['ALM_DESCR',             'Descripción',  'text'],
          ['Cantidad_Repeticiones', 'Repeticiones', 'num'],
        ],
      ],

      'tendencia_sem' => [
        'titulo'   => 'Tendencia semanal',
        'subtitulo'=> 'Total de alarmas por día',
        'icono'    => 'trend',
        'tabla'    => 'dbo.FIXALARMS_TENDENCIA_SEM',
        'orderby'  => 'Fecha DESC',
        'fecha_col'=> 'Fecha',
        'buscar'   => [],
        'cols' => [
          ['Fecha',         'Fecha',         'mono'],
          ['Total_Alarmas', 'Total alarmas', 'num'],
        ],
      ],

      'tend_sem_tags' => [
        'titulo'   => 'Tendencia semanal por tag',
        'subtitulo'=> 'Total de alarmas por día y por tag',
        'icono'    => 'trend',
        'tabla'    => 'dbo.FIXALARMS_TEND_SEM_TAGS',
        'orderby'  => 'Fecha DESC',
        'fecha_col'=> 'Fecha',
        'tag_col'  => 'ALM_TAGNAME',
        'buscar'   => ['ALM_TAGNAME'],
        'cols' => [
          ['Fecha',         'Fecha',         'mono'],
          ['ALM_TAGNAME',   'Tag',           'text'],
          ['Total_Alarmas', 'Total alarmas', 'num'],
        ],
      ],

      'prioridad' => [
        'titulo'   => 'Prioridad de alarmas',
        'subtitulo'=> 'Conteo total por nivel de prioridad',
        'icono'    => 'gauge',
        'tabla'    => 'dbo.FIXALARMS_PRIORITY',
        'orderby'  => 'TOTAL DESC',
        'buscar'   => [],
        'cols' => [
          ['ALM_ALMPRIORITY', 'Prioridad', 'prio'],
          ['TOTAL',           'Total',     'num'],
        ],
      ],

      'top_hml' => [
        'titulo'   => 'Top semanal',
        'subtitulo'=> 'Resumen de alarmas por criticidad de los últimos 7 días',
        'icono'    => 'gauge',
        'tabla'    => 'dbo.FIXALAMRS_TOP_HML',
        'orderby'  => 'Fecha DESC',
        'fecha_col'=> 'Fecha',
        'buscar'   => [],
        'cols' => [
          ['Fecha',          'Fecha',  'mono'],
          ['PrioridadAlta',  'Alta',   'num'],
          ['PrioridadMedia', 'Media',  'num'],
          ['PrioridadBaja',  'Baja',   'num'],
        ],
      ],

      'pozos' => [
        'titulo'   => 'Pozos',
        'subtitulo'=> 'Ficha de pozos por zona, batería y método',
        'icono'    => 'oil',
        'tabla'    => 'dbo.POZOS',
        'orderby'  => 'ZONA, BATERIA, POZO',
        'buscar'   => ['POZO', 'BATERIA', 'ZONA'],
        'cols' => [
          ['ZONA',     'Zona',     'text'],
          ['BATERIA',  'Batería',  'text'],
          ['POZO',     'Pozo',     'text'],
          ['TIPO',     'Tipo',     'text'],
          ['METODO',   'Método',   'text'],
          ['PETROLEO', 'Petróleo', 'num'],
          ['GAS',      'Gas',      'num'],
          ['AGUA',     'Agua',     'num'],
        ],
      ],

      'pozos_tecss' => [
        'titulo'   => 'Pozos · estado técnico',
        'subtitulo'=> 'Presión, vibración y golpes por minuto',
        'icono'    => 'gauge',
        'tabla'    => 'dbo.Pozos_tecss',
        'orderby'  => 'Pozo, Fecha DESC',
        'fecha_col'=> 'Fecha',
        'buscar'   => ['Pozo'],
        'cols' => [
          ['Fecha',             'Fecha',     'mono'],
          ['Pozo',              'Pozo',      'text'],
          ['Presion',           'Presión',   'num'],
          ['Vibracion',         'Vibración', 'num'],
          ['Golpes por minuto', 'GPM',       'num'],
          ['Estado',            'Estado',    'text'],
          ['tipo',              'Tipo',      'text'],
        ],
      ],

      'importadas' => [
        'titulo'   => 'Alarmas importadas',
        'subtitulo'=> 'Eventos importados desde sistemas externos',
        'icono'    => 'file',
        'tabla'    => 'dbo.ALM_Importadas',
        'orderby'  => 'FechaHora DESC',
        'fecha_col'=> 'FechaHora',
        'tag_col'  => 'TagID',
        'buscar'   => ['TagID', 'Descripcion', 'Usuario'],
        'cols' => [
          ['ID',          'ID',          'num'],
          ['FechaHora',   'Fecha y hora','mono'],
          ['TipoEvento',  'Evento',      'text'],
          ['Usuario',     'Usuario',     'text'],
          ['TagID',       'Tag',         'text'],
          ['Descripcion', 'Descripción', 'text'],
          ['Valor',       'Valor',       'mono'],
        ],
      ],

    ];
}
