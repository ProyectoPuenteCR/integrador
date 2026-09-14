<?php
/* Semanas operativas del Top 20 de instalaciones: miércoles a martes. */

function it20_parse_date($value)
{
    $value = trim((string)$value);
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    return ($date && $date->format('Y-m-d') === $value) ? $date : null;
}

function it20_week_for_date(DateTimeImmutable $date)
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

function it20_selected_week(array $query, DateTimeImmutable $today)
{
    $candidate = it20_parse_date($query['semana'] ?? '');
    if (!$candidate) $candidate = $today;
    return it20_week_for_date($candidate);
}

function it20_week_options(DateTimeImmutable $today, $count = 53)
{
    $current = it20_week_for_date($today);
    $options = [];
    for ($i = 0; $i < max(1, (int)$count); $i++) {
        $start = $current['start']->modify('-' . ($i * 7) . ' days');
        $end = $start->modify('+6 days');
        $options[] = [
            'value' => $start->format('Y-m-d'),
            'label' => $start->format('d/m/Y') . ' al ' . $end->format('d/m/Y'),
        ];
    }
    return $options;
}

function it20_is_canonical_week($startValue, $endValue = '')
{
    $start = it20_parse_date($startValue);
    if (!$start) return false;
    $week = it20_week_for_date($start);
    if ($week['start']->format('Y-m-d') !== $start->format('Y-m-d')) return false;
    if (trim((string)$endValue) === '') return true;
    $end = it20_parse_date($endValue);
    return $end && $week['end']->format('Y-m-d') === $end->format('Y-m-d');
}
