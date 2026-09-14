<section class="nsTableCard rpDraft" id="rpDraftPanel" hidden aria-labelledby="rpDraftTitle">
  <div class="nsTableHead"><div><h2 id="rpDraftTitle">Pozos pasados al parte · sin guardar</h2><p>Completá jefe y estado donde falten. TAG y observaciones son opcionales. Se guardan todos juntos; no se reemplazan partes existentes.</p></div></div>
  <form id="rpDraftForm">
    <div class="rpDraftToolbar"><label class="nsControl">Semana del lote<input type="date" id="rpDraftWeek" value="<?php echo h($selectedValue); ?>" required></label><label class="nsControl">Estado para todo el lote<select id="rpDraftState"><option value="">Elegir…</option><option value="MANUAL">MANUAL</option><option value="AUTOMATICO">AUTOMÁTICO</option><option value="HOA">HOA</option></select></label><button class="nsButton is-secondary" id="rpApplyState" type="button">Aplicar estado</button><span id="rpDraftCount"></span></div>
    <div class="tablescroll"><table class="grid nsTable" id="rpDraftTable" data-ns-no-auto-select-all><thead><tr><?php foreach(array_diff_key($columns,['ADJUNTO'=>true]) as $title): ?><th><?php echo h($title); ?></th><?php endforeach; ?></tr></thead><tbody></tbody></table></div>
    <p class="pfPartError" id="rpDraftError" role="alert" hidden></p>
    <div class="rpDraftToolbar"><button class="nsButton" id="rpSaveBatch" type="submit">Guardar pozos en el parte</button><button class="nsButton is-secondary" id="rpClearDraft" type="button">Descartar lote</button><small>Hasta 100 pozos por lote. Para adjuntar un archivo, guardá el lote y usá Editar en el pozo correspondiente.</small></div>
  </form>
</section>
