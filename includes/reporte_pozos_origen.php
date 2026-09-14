<section class="nsTableCard rpSource" id="rpSourcePanel" aria-labelledby="rpSourceTitle">
  <div class="nsTableHead"><div><h2 id="rpSourceTitle">Pozos disponibles · BM</h2><p>Fuente: caché local de telemetría y maestro de supervisores. Seleccioná los pozos para pasarlos al parte de arriba.</p></div><div class="nsTableHead__actions">
    <?php if($canCreate): ?><button type="button" class="nsButton" id="rpPassSelected" disabled>Pasar seleccionados al parte ↑</button><?php endif; ?>
    <button type="button" class="nsButton is-secondary" id="rpSourceReset">Limpiar filtros</button><button type="button" class="nsButton is-secondary" id="rpSourceReload">Actualizar listas</button>
    <div class="nsColumnTools"><button class="nsButton is-secondary" type="button" data-ns-columns-toggle>Columnas ▾</button><div class="nsColumnsMenu" data-ns-columns-menu></div></div>
  </div></div>
  <p class="rpSourceStatus" id="rpSourceStatus" role="status">Cargando la lista local de pozos…</p>
  <?php $sourceColumns=['report'=>'SELECCIÓN','ZONA'=>'ZONA','SUPERVISOR'=>'SUPERVISOR','JEFE_ZONA'=>'JEFE DE ZONA','BATERIA'=>'BATERÍA','POZO'=>'POZO','TAG'=>'TAG']; ?>
  <div class="tablescroll"><table class="grid nsTable" id="rpSourceTable" data-ns-custom-columns data-ns-no-auto-select-all>
    <thead><tr><?php foreach($sourceColumns as $field=>$title): ?><th data-column-key="<?php echo h($field); ?>" data-column-label="<?php echo h($title); ?>"><?php if($field==='report'): ?><input type="checkbox" id="rpSourceSelectAll" aria-label="Seleccionar todos los pozos visibles" <?php echo $canCreate?'':'disabled'; ?>><?php else: ?><button type="button" class="nsClientSort" data-rp-source-sort="<?php echo h($field); ?>"><?php echo h($title); ?></button><?php endif; ?></th><?php endforeach; ?></tr>
    <tr class="nsFilterRow"><?php foreach($sourceColumns as $field=>$title): ?><th data-column-key="<?php echo h($field); ?>"><?php if($field==='report'): ?><small id="rpSourceSelected">0 elegidos</small><?php elseif(in_array($field,['POZO','TAG'],true)): ?><input data-rp-source-filter="<?php echo h($field); ?>" placeholder="Buscar" aria-label="Filtrar <?php echo h($title); ?>"><?php else: ?><select data-rp-source-filter="<?php echo h($field); ?>" aria-label="Filtrar <?php echo h($title); ?>"><option value="">Todos</option></select><?php endif; ?></th><?php endforeach; ?></tr></thead><tbody><tr id="rpSourceEmpty"><td colspan="7">Cargando…</td></tr></tbody>
  </table></div>
</section>
