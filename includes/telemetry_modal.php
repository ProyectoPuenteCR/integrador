<div class="telemetry-modal" id="telemetryModal" hidden aria-hidden="true">
  <div class="telemetry-modal__overlay" data-telemetry-modal-close></div>
  <section class="telemetry-modal__dialog" role="dialog" aria-modal="true" aria-labelledby="telemetryModalTitle">
    <header class="telemetry-modal__head">
      <div class="telemetry-modal__title-wrap">
        <div class="telemetry-modal__eyebrow" id="telemetryModalEyebrow">Vista rápida</div>
        <h2 class="telemetry-modal__title" id="telemetryModalTitle">Detalle</h2>
        <p class="telemetry-modal__subtitle" id="telemetryModalSubtitle"></p>
      </div>
      <div class="telemetry-modal__actions">
        <a class="telemetry-modal__action" id="telemetryModalOpenPage" href="#" target="_blank" rel="noopener"><span class="telemetry-modal__open-label">Abrir aparte</span></a>
        <button type="button" class="telemetry-modal__action" id="telemetryModalMaximize" aria-label="Maximizar ventana">Maximizar</button>
        <button type="button" class="telemetry-modal__action telemetry-modal__close" data-telemetry-modal-close aria-label="Cerrar">×</button>
      </div>
    </header>
    <div class="telemetry-modal__body">
      <div class="telemetry-modal__loading" id="telemetryModalLoading"><span class="telemetry-modal__spinner"></span>Cargando contenido…</div>
      <iframe class="telemetry-modal__frame" id="telemetryModalFrame" src="about:blank" title="Contenido embebido" loading="eager" allowfullscreen></iframe>
    </div>
  </section>
</div>
