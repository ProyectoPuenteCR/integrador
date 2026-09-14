<?php
/* Semanas operativas fijas para Top 20 Pozos: miércoles a martes. */

function pt20_parse_date($value)
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function pt20_week_for_date(DateTimeImmutable $date)
{
    $date = $date->setTime(0, 0, 0);
    $daysSinceWednesday = (((int)$date->format('N')) - 3 + 7) % 7;
    $start = $daysSinceWednesday > 0 ? $date->modify('-' . $daysSinceWednesday . ' days') : $date;
    return [
        'start' => $start,
        'end' => $start->modify('+6 days'),
        'next' => $start->modify('+7 days'),
    ];
}

function pt20_selected_week(array $query, DateTimeImmutable $today)
{
    $candidate = pt20_parse_date($query['semana'] ?? '');
    if (!$candidate) $candidate = pt20_parse_date($query['hasta'] ?? '');
    if (!$candidate) $candidate = pt20_parse_date($query['desde'] ?? '');
    if (!$candidate) $candidate = $today;
    return pt20_week_for_date($candidate);
}

function pt20_week_options(DateTimeImmutable $today, $count = 53)
{
    $current = pt20_week_for_date($today);
    $options = [];
    $count = max(1, (int)$count);
    for ($i = 0; $i < $count; $i++) {
        $start = $current['start']->modify('-' . ($i * 7) . ' days');
        $end = $start->modify('+6 days');
        $options[] = [
            'value' => $start->format('Y-m-d'),
            'label' => $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'),
        ];
    }
    return $options;
}

function pt20_is_canonical_week($startValue, $endValue = '')
{
    $start = pt20_parse_date($startValue);
    if (!$start) return false;
    $week = pt20_week_for_date($start);
    if ($week['start']->format('Y-m-d') !== $start->format('Y-m-d')) return false;
    if (trim((string)$endValue) === '') return true;
    $end = pt20_parse_date($endValue);
    return $end && $week['end']->format('Y-m-d') === $end->format('Y-m-d');
}
