<?php
/* =============================================================
   CLEAR - Integracion INFOIL Query 164
   Ultimo Test de Pozos Productores Aprobados por pozo.
============================================================= */

if (!function_exists('clear_q164_well_key')) {
    function clear_q164_well_key($value)
    {
        return strtoupper(trim((string)$value));
    }
}

if (!function_exists('clear_q164_numeric')) {
    function clear_q164_numeric($value)
    {
        if ($value === null || $value === '') return null;
        if (is_int($value) || is_float($value)) return (float)$value;

        $s = trim((string)$value);
        if ($s === '') return null;
        $s = str_replace([chr(194).chr(160), ' '], '', $s);

        $lastComma = strrpos($s, ',');
        $lastDot   = strrpos($s, '.');

        if ($lastComma !== false && $lastDot !== false) {
            if ($lastComma > $lastDot) {
                $s = str_replace('.', '', $s);
                $s = str_replace(',', '.', $s);
            } else {
                $s = str_replace(',', '', $s);
            }
        } elseif ($lastComma !== false) {
            $s = str_replace(',', '.', $s);
        }

        return is_numeric($s) ? (float)$s : null;
    }
}

if (!function_exists('clear_q164_latest_map')) {
    function clear_q164_latest_map($db)
    {
        if (!$db || !$db->ok()) return [];

        /*
         * Leemos directamente la cache. Esto evita depender de que la vista
         * CLEAR_API_Q164_ULTIMO exista o tenga visibilidad de metadatos para
         * el login utilizado por la web.
         *
         * CONVERT(varchar, DECIMAL) fuerza un formato estable con punto decimal
         * antes de que ADODB/COM entregue los valores a PHP.
         */
        $sql = "WITH Q164 AS (
"
             . " SELECT POZO,
"
             . "        CONVERT(varchar(64), PRODUCCION_PETROLEO) AS PRODUCCION_PETROLEO,
"
             . "        CONVERT(varchar(64), PRODUCCION_LIQUIDO) AS PRODUCCION_LIQUIDO,
"
             . "        CONVERT(varchar(64), PRODUCCION_GAS) AS PRODUCCION_GAS,
"
             . "        DIA_OPERATIVO, FECHA_HORA, FECHA_ACTUALIZACION,
"
             . "        ROW_NUMBER() OVER (
"
             . "          PARTITION BY UPPER(LTRIM(RTRIM(POZO)))
"
             . "          ORDER BY DIA_OPERATIVO DESC, FECHA_HORA DESC, ID_TEST DESC
"
             . "        ) AS RN
"
             . " FROM dbo.CLEAR_API_Q164_CACHE
"
             . " WHERE POZO IS NOT NULL AND LTRIM(RTRIM(POZO)) <> ''
"
             . ")
"
             . "SELECT POZO, PRODUCCION_PETROLEO, PRODUCCION_LIQUIDO, PRODUCCION_GAS,
"
             . "       DIA_OPERATIVO, FECHA_HORA, FECHA_ACTUALIZACION
"
             . "FROM Q164 WHERE RN = 1";

        $rows = $db->all($sql);
        if (!$rows) return [];

        $map = [];
        foreach ($rows as $row) {
            $well = trim((string)($row['POZO'] ?? ''));
            $key = clear_q164_well_key($well);
            if ($key === '') continue;
            $map[$key] = [
                'POZO' => $well,
                'PRODUCCION_PETROLEO' => clear_q164_numeric($row['PRODUCCION_PETROLEO'] ?? null),
                'PRODUCCION_LIQUIDO' => clear_q164_numeric($row['PRODUCCION_LIQUIDO'] ?? null),
                'PRODUCCION_GAS' => clear_q164_numeric($row['PRODUCCION_GAS'] ?? null),
                'DIA_OPERATIVO' => $row['DIA_OPERATIVO'] ?? null,
                'FECHA_HORA' => $row['FECHA_HORA'] ?? null,
                'FECHA_ACTUALIZACION' => $row['FECHA_ACTUALIZACION'] ?? null,
            ];
        }
        return $map;
    }
}
