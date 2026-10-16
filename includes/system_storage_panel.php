<?php /* Se incluye exclusivamente desde Estado del sistema, ya autenticado. */
if (!isset($APP_USER, $cfg)) return;
?>
<section class="storage" id="systemStorage" aria-labelledby="storageTitle">
  <div class="storage__head">
    <div>
      <h2 id="storageTitle">Almacenamiento de bases de datos</h2>
      <p class="storage__muted">Alarmas y logs · Tamaño actual de tablas, archivos y log de transacciones</p>
    </div>
    <div class="storage__controls">
      <label>Base de datos
        <select id="storageDatabase" aria-label="Base de datos a evaluar">
          <option value="<?php echo h($cfg['db']['database'] ?? ''); ?>"><?php echo h($cfg['db']['database'] ?? ''); ?></option>
        </select>
      </label>
      <button type="button" class="storage__button" id="storageRefresh">Actualizar tamaños</button>
    </div>
  </div>
  <p id="storageStatus" class="storage__muted" role="status" aria-live="polite">Preparando lectura de metadatos...</p>
  <div id="storageError" class="storage__alert" role="alert" hidden></div>
  <div id="storageContent" hidden>
    <div class="storage__cards">
      <div class="storage__card"><span>Archivos de datos</span><b id="storageDataSize">—</b><small>Asignado a MDF/NDF · no incluye LDF</small></div>
      <div class="storage__card"><span>Datos ocupados</span><b id="storageDataUsed">—</b><small id="storageDataFree">Espacio libre interno: —</small></div>
      <div class="storage__card"><span>Archivo(s) de log</span><b id="storageLogFile">—</b><small>Tamaño asignado a LDF</small></div>
      <div class="storage__card" id="storageLogCard"><span>Ocupación del log</span><b id="storageLogPercent">—</b><small id="storageLogUsed">Uso actual: —</small></div>
    </div>
    <div class="storage__toolbar">
      <label>Buscar tabla
        <input id="storageSearch" type="search" placeholder="FIXALARMS, CLEAR_AUDIT_LOG..." autocomplete="off">
      </label>
      <label>Ordenar grilla por
        <select id="storageSort">
          <option value="RESERVED_MB">Mayor tamaño reservado</option>
          <option value="USED_MB">Mayor tamaño usado</option>
          <option value="ROWS_APPROX">Más registros</option>
          <option value="TABLE_NAME">Nombre de tabla</option>
        </select>
      </label>
      <button class="storage__button storage__button--clear" id="storageClear" type="button">Limpiar filtro</button>
      <span class="storage__muted" id="storageTableSummary"></span>
    </div>
    <div class="storage__charts">
      <div class="storage__plot">
        <h3>Top 10 tablas por espacio reservado</h3>
        <p class="storage__muted">El filtro de tabla también se aplica al gráfico. Tocá una barra para ver esa tabla.</p>
        <div class="storage__legend"><span><i class="storage__seg--data"></i>Datos</span><span><i class="storage__seg--index"></i>Índices / estructuras</span><span><i class="storage__seg--unused"></i>Reserva sin usar</span></div>
        <div id="storageBars"></div>
      </div>
      <div class="storage__plot">
        <h3>Log de transacciones</h3>
        <div id="storageLogRing" class="storage__ring" role="img" aria-label="Ocupación del log no disponible"><b id="storageLogRingText">—</b></div>
        <p id="storageLogDetail" class="storage__muted"></p>
        <p id="storageLogState" class="storage__logstate"></p>
        <p id="storageRecovery" class="storage__muted"></p>
        <p class="storage__muted">Aviso desde 80%; ocupación alta desde 95%. Son umbrales visuales, no una prueba de falla.</p>
      </div>
    </div>
    <div id="storageTableError" class="storage__alert" hidden></div>
    <div class="storage__tablewrap">
      <table class="storage__table">
        <caption class="storage__muted">Tablas físicas visibles al usuario SQL · valores en MB (1024² bytes)</caption>
        <thead><tr><th>Tabla</th><th>Registros aprox.</th><th>Datos MB</th><th>Índices / estructuras MB</th><th>Usado MB</th><th>Reservado MB</th><th>Reserva sin usar MB</th></tr></thead>
        <tbody id="storageTableRows"></tbody>
      </table>
    </div>
    <div class="storage__pager">
      <label>Filas <select id="storagePageSize"><option value="50">50</option><option value="100">100</option><option value="0">Todas</option></select></label>
      <button class="storage__button" type="button" id="storagePrev">Anterior</button>
      <span id="storagePage" class="storage__muted"></span>
      <button class="storage__button" type="button" id="storageNext">Siguiente</button>
    </div>
    <details class="storage__help">
      <summary>Archivos de la base y crecimiento configurado</summary>
      <div id="storageFileError" class="storage__alert" hidden></div>
      <div class="storage__tablewrap"><table class="storage__table">
        <thead><tr><th>Archivo lógico</th><th>Tipo</th><th>Estado</th><th>Asignado MB</th><th>Usado MB (datos)</th><th>Autocrecimiento</th><th>Máximo configurado</th></tr></thead>
        <tbody id="storageFileRows"></tbody>
      </table></div>
    </details>
    <p id="storageWarnings" class="storage__muted"></p>
  </div>
  <details class="storage__help">
    <summary>Ayuda: qué se mide, rendimiento y permisos SQL</summary>
    <p>Datos y log son distintos. El log de transacciones (LDF) no es una tabla de auditoría.
    Para revisar una base de logs independiente, elegila en el selector; sólo se muestran bases disponibles para el usuario SQL.</p>
    <p>Los registros son aproximados, obtenidos de metadatos. Los índices no duplican el conteo de filas.
    Son tamaños físicos, sin filtros operativos de alarmas ni fechas; los conteos pueden diferir de otras pantallas.
    “Reservado” incluye lo usado y la reserva aún sin usar de cada tabla. Esa reserva no es el espacio libre del disco.
    “Índices / estructuras” incluye índices secundarios y páginas de administración.
    El total de tablas puede diferir del tamaño de archivos por objetos internos, espacio libre y otros objetos.</p>
    <p>Se excluyen vistas del ranking y no se mide el almacenamiento externo FILESTREAM ni las tablas en memoria.
    La ocupación del log combina todos sus archivos. El tamaño utilizable del log puede diferir ligeramente de la suma de archivos.
    Esta es una foto actual: no se inventan históricos de crecimiento.</p>
    <p>Lectura bajo demanda, sin sondeo automático, con caché de 60 segundos por sesión/base.
    El botón “Actualizar tamaños” no repite las consultas RTQP ni de Jobs.
    No se ejecutan borrados, SHRINK, reorganizaciones, cambios de recuperación ni creación de tablas o Jobs.</p>
    <p>Permisos para tablas: SQL 2016/2019, VIEW DATABASE STATE y VIEW DEFINITION;
    SQL 2022+, VIEW DATABASE PERFORMANCE STATE y VIEW SECURITY DEFINITION.
    Para ocupación del log: VIEW SERVER STATE (2016/2019) o VIEW SERVER PERFORMANCE STATE (2022+).
    El DBA debe revisar el alcance antes de otorgarlos; la aplicación no modifica permisos.
    Las métricas no disponibles se informan, nunca se reemplazan por ceros.</p>
  </details>
  <noscript><p class="storage__alert">Activá JavaScript para cargar los tamaños y gráficos.</p></noscript>
</section>
