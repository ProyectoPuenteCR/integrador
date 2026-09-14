<?php
/* Acciones reutilizables para grillas de alarmas: histórico SQL y comentario. */

require_once __DIR__ . '/alarm_event_comments.php';

function clear_alarm_actions_related_query($tag)
{
    $tag = trim((string)$tag);
    if ($tag === '') return '';
    $parts = explode('_', $tag);
    if (count($parts) > 1) {
        array_pop($parts);
        $group = trim(implode('_', $parts));
        if ($group !== '') return $group;
    }
    return $tag;
}

function clear_alarm_actions_cell(array $data)
{
    $display = trim((string)($data['display'] ?? ''));
    $tag = trim((string)($data['tag'] ?? ''));
    $timestamp = trim((string)($data['timestamp'] ?? ''));
    $value = trim((string)($data['value'] ?? ''));
    $description = trim((string)($data['description'] ?? ''));
    $subject = trim((string)($data['subject'] ?? $tag));
    $subjectLabel = trim((string)($data['subject_label'] ?? ($tag !== '' ? $tag : $subject)));
    $context = trim((string)($data['context'] ?? 'alarma'));
    $query = trim((string)($data['query'] ?? clear_alarm_actions_related_query($tag)));
    $historyStart = trim((string)($data['history_start'] ?? ''));
    $historyEnd = trim((string)($data['history_end'] ?? ''));
    $preservePiLink = !empty($data['preserve_pi_link']);
    $iconOnly = !empty($data['icon_only']);
    $showHistory = !array_key_exists('show_history', $data) || !empty($data['show_history']);
    $showComment = !empty($data['show_comment']) && $subject !== '';
    $hasComment = !empty($data['has_comment']);
    $canView = function_exists('permissions_can') && permissions_can('comments.view');
    $canCreate = function_exists('permissions_can') && permissions_can('comments.create');
    $eventKey = $subject !== '' ? sha1(strtoupper($subject)) : '';

    ob_start();
    ?>
    <div class="alarmCell">
      <?php if (!$iconOnly && $preservePiLink && $tag !== ''): ?>
      <button type="button" class="alarmCell__text tagLink" data-pi-tag="<?php echo h($tag); ?>" data-pi-query="<?php echo h($query); ?>" title="Ver histórico de PI y SQL"><?php echo h($display !== '' ? $display : '—'); ?></button>
      <?php elseif (!$iconOnly): ?>
      <span class="alarmCell__text"><?php echo h($display !== '' ? $display : '—'); ?></span>
      <?php endif; ?>
      <?php if (($tag !== '' && $showHistory) || ($showComment && ($canView || $canCreate))): ?>
      <span class="alarmCell__actions" aria-label="Acciones de alarma">
        <?php if ($showHistory): ?>
        <button type="button" class="alarmAction alarmAction--trend" data-sql-history="true" data-sql-tag="<?php echo h($tag); ?>"<?php if($historyStart!==''): ?> data-sql-start="<?php echo h($historyStart); ?>"<?php endif; ?><?php if($historyEnd!==''): ?> data-sql-end="<?php echo h($historyEnd); ?>"<?php endif; ?> title="Ver histórico SQL del rango seleccionado" aria-label="Ver histórico SQL de <?php echo h($tag); ?>">
          <?php echo icon('trend'); ?>
        </button>
        <?php endif; ?>
        <?php if ($showComment && ($canView || $canCreate)): ?>
        <button type="button" class="alarmAction alarmAction--comment<?php echo $hasComment ? ' has-comment' : ''; ?>" data-alarm-comment="true" data-event-key="<?php echo h($eventKey); ?>" data-subject="<?php echo h($subject); ?>" data-subject-label="<?php echo h($subjectLabel); ?>" data-context="<?php echo h($context); ?>" data-tag="<?php echo h($tag); ?>" data-timestamp="<?php echo h($timestamp); ?>" data-value="<?php echo h($value); ?>" data-description="<?php echo h($description); ?>" data-can-create="<?php echo $canCreate ? '1' : '0'; ?>" title="<?php echo $hasComment ? 'Ver comentario central existente' : ($canCreate ? 'Agregar comentario central' : 'Ver comentario'); ?>" aria-label="Comentario de <?php echo h($subjectLabel); ?>">
          <?php echo icon('message'); ?><span class="alarmAction__dot" aria-hidden="true"></span>
        </button>
        <?php endif; ?>
      </span>
      <?php endif; ?>
    </div>
    <?php
    return trim((string)ob_get_clean());
}

function clear_alarm_actions_modal()
{
    $canView = function_exists('permissions_can') && permissions_can('comments.view');
    $canCreate = function_exists('permissions_can') && permissions_can('comments.create');
    ?>
    <div class="sqlHistoryModal__overlay" id="sqlHistoryModalOverlay" hidden></div>
    <section class="sqlHistoryModal" id="sqlHistoryModal" aria-hidden="true" aria-labelledby="sqlHistoryModalTitle">
      <div class="sqlHistoryModal__head">
        <div>
          <div class="sqlHistoryModal__eyebrow">Histórico exclusivo de SQL Server</div>
          <h2 id="sqlHistoryModalTitle">Tendencia de alarmas</h2>
          <p>Datos de <b>dbo.FIXALARMS</b> · una semana por defecto.</p>
        </div>
        <div class="sqlHistoryModal__actions">
          <a class="sqlHistoryModal__link" id="sqlHistoryModalOpenPage" href="alarm_sql_historico.php" target="_blank" rel="noopener">Abrir página completa</a>
          <button type="button" class="sqlHistoryModal__close" id="sqlHistoryModalClose" aria-label="Cerrar histórico SQL">×</button>
        </div>
      </div>
      <div class="sqlHistoryModal__body"><iframe id="sqlHistoryModalFrame" class="sqlHistoryModal__frame" src="about:blank" title="Histórico SQL de alarmas" loading="lazy"></iframe></div>
    </section>
    <?php if (!$canView && !$canCreate) return; ?>
    <div class="alarmQuickComment__overlay" id="alarmQuickCommentOverlay" hidden></div>
    <section class="alarmQuickComment" id="alarmQuickComment" aria-hidden="true" aria-labelledby="alarmQuickCommentTitle">
      <div class="alarmQuickComment__head">
        <div>
          <div class="alarmQuickComment__eyebrow">Comentario centralizado</div>
          <h2 id="alarmQuickCommentTitle">Comentario de alarma</h2>
          <p id="alarmQuickCommentMeta">—</p>
        </div>
        <button type="button" class="alarmQuickComment__close" id="alarmQuickCommentClose" aria-label="Cerrar">×</button>
      </div>
      <div class="alarmQuickComment__body">
        <div class="alarmQuickComment__previous" id="alarmQuickCommentPrevious" hidden></div>
        <label for="alarmQuickCommentText">Comentario</label>
        <textarea id="alarmQuickCommentText" maxlength="2000" placeholder="Escribí una observación sobre esta alarma..."<?php echo $canCreate ? '' : ' readonly'; ?>></textarea>
        <div class="alarmQuickComment__status" id="alarmQuickCommentStatus" hidden></div>
      </div>
      <div class="alarmQuickComment__foot">
        <button type="button" class="alarmQuickComment__cancel" id="alarmQuickCommentCancel">Cerrar</button>
        <?php if ($canCreate): ?><button type="button" class="alarmQuickComment__save" id="alarmQuickCommentSave">Guardar comentario</button><?php endif; ?>
      </div>
    </section>
    <?php
}
