<section class="nsCard pfPartPanel" id="pfPartPanel" hidden aria-labelledby="pfPartTitle">
  <div class="nsCard__head"><div><h2 id="pfPartTitle">Agregar parte</h2><p>Un pozo por semana. TAG, observaciones y adjunto son opcionales.</p></div></div>
  <form id="pfPartForm" class="nsCard__body" enctype="multipart/form-data">
    <div class="pfCatalogBar"><span id="pfCatalogStatus" role="status">Las listas se cargarán al abrir el parte.</span><button class="nsButton is-secondary" type="button" id="pfCatalogReload">Actualizar listas</button></div>
    <input type="hidden" name="ID" value="0"><input type="hidden" name="VERSION" value="0">
    <div class="pfPartFields">
      <label class="nsControl">Semana · elegí una fecha<input type="date" name="SEMANA_DESDE" min="2000-01-01" max="<?php echo h($week->modify('+6 days')->format('Y-m-d')); ?>" value="<?php echo h($selectedValue); ?>" required><small id="pfPartWeekHint">Se guarda de miércoles a martes.</small></label>
      <label class="nsControl">Zona<select name="ZONA" required><option value="">Seleccionar zona</option><?php foreach($zones as $zone): ?><option value="<?php echo h($zone); ?>"><?php echo h($zone); ?></option><?php endforeach; ?></select></label>
      <?php foreach(['SUPERVISOR'=>200,'JEFE_PRODUCCION'=>200,'BATERIA'=>255,'POZO'=>180,'TAG'=>255] as $field=>$limit): ?>
      <div class="nsControl pfLookup" data-pf-lookup="<?php echo h($field); ?>">
        <label for="pfInput<?php echo h($field); ?>"><?php echo h($columns[$field]); ?><?php echo $field==='TAG'?' (opcional)':''; ?></label>
        <div class="pfLookupInput"><input id="pfInput<?php echo h($field); ?>" type="text" name="<?php echo h($field); ?>" maxlength="<?php echo $limit; ?>" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="pfOptions<?php echo h($field); ?>" placeholder="Escribir o elegir…" <?php echo $field==='TAG'?'':'required'; ?>><button type="button" data-pf-lookup-toggle aria-label="Mostrar opciones de <?php echo h($columns[$field]); ?>">▾</button></div>
        <div class="pfLookupOptions" id="pfOptions<?php echo h($field); ?>" role="listbox" hidden></div>
        <small data-pf-field-source></small>
        <?php if($field==='JEFE_PRODUCCION'): ?><button type="button" id="pfUseZoneChief" class="pfUseChief" hidden></button><?php endif; ?>
      </div>
      <?php endforeach; ?>
      <label class="nsControl">Estado<select name="ESTADO" required><option value="">Seleccionar estado</option><option value="MANUAL">MANUAL</option><option value="AUTOMATICO">AUTOMÁTICO</option><option value="HOA">HOA</option></select></label>
    </div>
    <div class="rpPartExtras">
      <label class="nsControl">Observaciones (opcional)<textarea name="OBSERVACIONES" rows="4" maxlength="2000" placeholder="Escribí las observaciones de este pozo…"></textarea><small>Hasta 2.000 caracteres.</small></label>
      <div class="nsControl"><label for="pfAttachment">Adjuntar archivo (opcional · máximo 5 MB)</label><input type="file" id="pfAttachment" name="ADJUNTO" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,.csv,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip"><small>Un archivo por parte. PDF, imágenes, documentos Office, TXT/CSV o ZIP.</small><small id="pfAttachmentInfo" role="status"></small><button class="nsButton is-secondary" type="button" id="pfClearFile" hidden>Cancelar archivo elegido</button><div id="pfCurrentAttachment" hidden><a id="pfCurrentAttachmentLink">Descargar adjunto actual</a><label class="rpRemoveFile"><input type="checkbox" name="QUITAR_ADJUNTO" value="1"> Quitar el adjunto actual al guardar</label></div></div>
    </div>
    <p id="pfPartError" class="pfPartError" role="alert" hidden></p>
    <div class="pfPartActions"><button class="nsButton" type="submit" id="pfPartSubmit">Guardar parte</button><button class="nsButton is-secondary" type="button" id="pfPartCancel">Cancelar</button><span>Guardar actualiza la grilla superior de la semana.</span></div>
  </form>
</section>
