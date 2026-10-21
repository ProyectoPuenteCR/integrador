<?php
/* Cálculos puros del dashboard. No consulta ni escribe en SQL. PHP 7.4+. */

function dp_kpi_cutoff_seconds($value)
{
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', (string)$value, $m)) return 0;
    return min(23, (int)$m[1]) * 3600 + min(59, (int)$m[2]) * 60 + min(59, (int)($m[3] ?? 0));
}

function dp_kpi_date($value)
{
    if ($value instanceof DateTimeInterface) return $value->format('Y-m-d');
    $value = substr(trim((string)$value), 0, 10);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return $date && $date->format('Y-m-d') === $value ? $value : '';
}

function dp_kpi_period(array $daily, DateTimeImmutable $start, DateTimeImmutable $end, $cutoff)
{
    $metrics = ['SIN_COM', 'COMUNICANDO', 'EN_FALLA'];
    $valid = [];
    foreach ($daily as $row) {
        $day = dp_kpi_date($row['DIA_OPERATIVO'] ?? '');
        if ($day < $start->format('Y-m-d') || $day >= $end->format('Y-m-d')) continue;
        if ((int)($row['COBERTURA_COMPLETA'] ?? 0) !== 1) continue;
        if (dp_kpi_cutoff_seconds($row['HORA_CORTE'] ?? $cutoff) !== dp_kpi_cutoff_seconds($cutoff)) continue;
        foreach ($metrics as $metric) {
            if (!isset($row[$metric]) || !is_numeric($row[$metric]) || (float)$row[$metric] < 0) continue 2;
        }
        $valid[$day] = $row;
    }
    $expected = (int)$start->diff($end)->format('%a');
    $complete = $expected > 0 && count($valid) === $expected;
    $values = array_fill_keys($metrics, null);
    if ($complete) {
        foreach ($metrics as $metric) {
            $sum = 0.0;
            foreach ($valid as $row) $sum += (float)$row[$metric];
            $values[$metric] = $sum / $expected;
        }
    }
    return [
        'start' => $start->format('Y-m-d'), 'end' => $end->format('Y-m-d'),
        'label' => $start->format('d/m/Y').' al '.$end->modify('-1 day')->format('d/m/Y'),
        'expected' => $expected, 'available' => count($valid), 'complete' => $complete,
        'values' => $values,
    ];
}

function dp_kpi_comparisons(array $daily, $timestamp, $cutoff = '00:00:00')
{
    $now = (new DateTimeImmutable('@'.(int)$timestamp))->setTimezone(new DateTimeZone(date_default_timezone_get()));
    $operatingDate = $now->modify('-'.dp_kpi_cutoff_seconds($cutoff).' seconds')->setTime(0, 0);
    // Las semanas de CLEAR comienzan el miércoles (no dependen de DATEFIRST).
    $weekStart = $operatingDate->modify('-'.(((int)$operatingDate->format('N') - 3 + 7) % 7).' days');
    $monthStart = $operatingDate->modify('first day of this month');
    $definitions = [
        ['key'=>'week', 'title'=>'Semana vs semana', 'basis'=>'Semanas completas · miércoles a martes',
         'current_start'=>$weekStart->modify('-7 days'), 'current_end'=>$weekStart,
         'previous_start'=>$weekStart->modify('-14 days'), 'previous_end'=>$weekStart->modify('-7 days')],
        ['key'=>'month', 'title'=>'Mes vs mes', 'basis'=>'Meses calendario completos',
         'current_start'=>$monthStart->modify('-1 month'), 'current_end'=>$monthStart,
         'previous_start'=>$monthStart->modify('-2 months'), 'previous_end'=>$monthStart->modify('-1 month')],
    ];
    $out = [];
    foreach ($definitions as $definition) {
        $current = dp_kpi_period($daily, $definition['current_start'], $definition['current_end'], $cutoff);
        $previous = dp_kpi_period($daily, $definition['previous_start'], $definition['previous_end'], $cutoff);
        $metrics = [];
        foreach (['SIN_COM'=>'Sin comunicación', 'COMUNICANDO'=>'Comunicando', 'EN_FALLA'=>'Pozos en falla'] as $key=>$label) {
            $value = $current['values'][$key]; $prior = $previous['values'][$key];
            $delta = $value !== null && $prior !== null ? $value - $prior : null;
            $percent = $delta === null ? null : ($prior > 0 ? $delta * 100 / $prior : ($value == 0 ? 0.0 : null));
            $metrics[] = ['key'=>$key, 'label'=>$label, 'current'=>$value, 'previous'=>$prior,
                'delta'=>$delta, 'percent'=>$percent,
                'favorable'=>$delta === null || abs($delta) < 0.000001 ? null : ($key === 'COMUNICANDO' ? $delta > 0 : $delta < 0)];
        }
        $out[] = ['key'=>$definition['key'], 'title'=>$definition['title'], 'basis'=>$definition['basis'],
            'current'=>$current, 'previous'=>$previous, 'ready'=>$current['complete'] && $previous['complete'], 'metrics'=>$metrics];
    }
    return $out;
}

function dp_top_without_comm(array $rows, $timestamp, $limit = 10)
{
    $out = [];
    foreach ($rows as $row) {
        if (($row['comm'] ?? '') !== 'Sin comunicación') continue;
        $start = $row['sin_com_desde'] ?? null;
        $sample = !empty($row['date']) ? min((int)$timestamp, (int)$row['date']) : null;
        // No usar FECHA_CACHE como inicio ni prolongar una caída con datos vencidos.
        $row['dias_sin_com'] = $start && $sample && (int)$start <= $sample ? ($sample - (int)$start) / 86400.0 : null;
        $out[] = $row;
    }
    usort($out, static function ($a, $b) {
        $av = $a['dias_sin_com']; $bv = $b['dias_sin_com'];
        if ($av === null && $bv !== null) return 1;
        if ($bv === null && $av !== null) return -1;
        if ($av !== $bv) return $bv <=> $av;
        return strnatcasecmp((string)$a['pozo'], (string)$b['pozo']);
    });
    return array_slice($out, 0, max(0, (int)$limit));
}
