<?php
/* Opción aditiva para los enlaces de ficha. La búsqueda libre no cambia. */
function pa_well_exact_requested(array $input) {
    $value = $input['pozo_exacto'] ?? null;
    return $value === '1' || $value === 1;
}

function pa_well_exact_filter($column, $well, array $metadata = []) {
    $well = trim((string)$well);
    if ($well === '') return ['sql' => '1=0', 'params' => []];
    $identifier = '['.str_replace(']', ']]', (string)$column).']';
    $type = strtolower((string)($metadata['DATA_TYPE'] ?? $metadata['data_type'] ?? ''));
    $length = (int)($metadata['CHARACTER_MAXIMUM_LENGTH'] ?? $metadata['character_maximum_length'] ?? 0);
    $parameter = '?';
    if (in_array($type, ['char','varchar','nchar','nvarchar'], true)) {
        $maximum = in_array($type, ['nchar','nvarchar'], true) ? 4000 : 8000;
        if ($length > 0 && $length <= $maximum) {
            // Nunca truncar BB-100 a BB-10 para que quepa en el parámetro.
            if (strlen($well) > $length) return ['sql' => '1=0', 'params' => []];
            $parameter = 'CONVERT('.$type.'('.$length.'), ?)';
        } elseif ($length === -1 && in_array($type, ['varchar','nvarchar'], true)) {
            $parameter = 'CONVERT('.$type.'(max), ?)';
        }
    } elseif (in_array($type, ['text','ntext'], true)) {
        // Estos tipos heredados no permiten igualdad directa ni un índice de
        // búsqueda sobre la columna; conservar igualdad sin truncar el valor.
        $identifier = 'CONVERT(nvarchar(max), '.$identifier.')';
    }
    return ['sql' => $identifier.' = '.$parameter, 'params' => [$well]];
}
