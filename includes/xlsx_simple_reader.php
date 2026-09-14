<?php
/* =============================================================
   CLEAR PLATAFORMA — lector liviano de Excel/CSV
   Lee la primera hoja de un .xlsx sin dependencias externas.
============================================================= */

function clear_excel_column_index($reference)
{
    if (!preg_match('/^([A-Z]+)/i', (string)$reference, $match)) return 0;
    $letters = strtoupper($match[1]);
    $index = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }
    return max(0, $index - 1);
}

function clear_excel_xml($content, $label)
{
    $previous = libxml_use_internal_errors(true);
    $xml = simplexml_load_string((string)$content);
    libxml_clear_errors();
    libxml_use_internal_errors($previous);
    if ($xml === false) throw new RuntimeException('El archivo Excel contiene XML inválido en '.$label.'.');
    return $xml;
}

function clear_excel_shared_strings(ZipArchive $zip)
{
    $content = $zip->getFromName('xl/sharedStrings.xml');
    if ($content === false) return [];
    $xml = clear_excel_xml($content, 'textos compartidos');
    $namespaces = $xml->getNamespaces(true);
    $main = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $xml->registerXPathNamespace('m', $main);
    $strings = [];
    foreach ($xml->xpath('//m:si') as $item) {
        $item->registerXPathNamespace('m', $main);
        $parts = [];
        foreach ($item->xpath('.//m:t') as $text) $parts[] = (string)$text;
        $strings[] = implode('', $parts);
    }
    return $strings;
}

function clear_excel_first_sheet_path(ZipArchive $zip)
{
    $workbookContent = $zip->getFromName('xl/workbook.xml');
    $relsContent = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookContent === false || $relsContent === false) return 'xl/worksheets/sheet1.xml';

    $workbook = clear_excel_xml($workbookContent, 'libro');
    $namespaces = $workbook->getNamespaces(true);
    $main = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
    $relNs = $namespaces['r'] ?? 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';
    $workbook->registerXPathNamespace('m', $main);
    $workbook->registerXPathNamespace('r', $relNs);
    $sheets = $workbook->xpath('//m:sheets/m:sheet');
    if (!$sheets) return 'xl/worksheets/sheet1.xml';
    $attributes = $sheets[0]->attributes($relNs);
    $relationshipId = (string)($attributes['id'] ?? '');
    if ($relationshipId === '') return 'xl/worksheets/sheet1.xml';

    $rels = clear_excel_xml($relsContent, 'relaciones del libro');
    $relNamespaces = $rels->getNamespaces(true);
    $package = $relNamespaces[''] ?? 'http://schemas.openxmlformats.org/package/2006/relationships';
    $rels->registerXPathNamespace('p', $package);
    foreach ($rels->xpath('//p:Relationship') as $relationship) {
        $attrs = $relationship->attributes();
        if ((string)$attrs['Id'] !== $relationshipId) continue;
        $target = str_replace('\\', '/', (string)$attrs['Target']);
        $target = preg_replace('#^\.\/#', '', $target);
        if (strpos($target, '../') !== false) throw new RuntimeException('La hoja de Excel apunta fuera del libro.');
        $target = ltrim($target, '/');
        return strpos($target, 'xl/') === 0 ? $target : 'xl/'.$target;
    }
    return 'xl/worksheets/sheet1.xml';
}

function clear_excel_read_xlsx($path, $maxRows = 5000, $maxColumns = 64)
{
    if (!class_exists('ZipArchive')) throw new RuntimeException('El servidor PHP no tiene habilitada la extensión ZIP necesaria para importar .xlsx. Podés guardar el archivo como CSV e importarlo.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('No se pudo abrir el archivo Excel.');
    try {
        $shared = clear_excel_shared_strings($zip);
        $sheetPath = clear_excel_first_sheet_path($zip);
        $sheetContent = $zip->getFromName($sheetPath);
        if ($sheetContent === false) throw new RuntimeException('No se encontró la primera hoja del archivo Excel.');
        $sheet = clear_excel_xml($sheetContent, 'primera hoja');
        $namespaces = $sheet->getNamespaces(true);
        $main = $namespaces[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('m', $main);
        $rows = [];
        foreach ($sheet->xpath('//m:sheetData/m:row') as $xmlRow) {
            if (count($rows) >= $maxRows) throw new RuntimeException('El Excel supera el máximo de '.(int)$maxRows.' filas permitidas.');
            $row = [];
            $xmlRow->registerXPathNamespace('m', $main);
            foreach ($xmlRow->xpath('./m:c') as $cell) {
                $cell->registerXPathNamespace('m', $main);
                $attrs = $cell->attributes();
                $column = clear_excel_column_index((string)($attrs['r'] ?? 'A1'));
                if ($column >= $maxColumns) continue;
                $type = (string)($attrs['t'] ?? '');
                $value = '';
                if ($type === 'inlineStr') {
                    $parts = [];
                    foreach ($cell->xpath('.//m:t') as $text) $parts[] = (string)$text;
                    $value = implode('', $parts);
                } else {
                    $valueNodes = $cell->xpath('./m:v');
                    $raw = $valueNodes ? (string)$valueNodes[0] : '';
                    if ($type === 's') $value = $shared[(int)$raw] ?? '';
                    elseif ($type === 'b') $value = $raw === '1' ? 'Sí' : 'No';
                    else $value = $raw;
                }
                $row[$column] = trim((string)$value);
            }
            if ($row) {
                $last = min(max(array_keys($row)), $maxColumns - 1);
                $normalized = [];
                for ($i = 0; $i <= $last; $i++) $normalized[] = $row[$i] ?? '';
                $rows[] = $normalized;
            }
        }
        return $rows;
    } finally {
        $zip->close();
    }
}

function clear_excel_read_csv($path, $maxRows = 5000, $maxColumns = 64)
{
    $handle = fopen($path, 'rb');
    if (!$handle) throw new RuntimeException('No se pudo abrir el archivo CSV.');
    try {
        $sample = fgets($handle);
        if ($sample === false) return [];
        $counts = [';' => substr_count($sample, ';'), ',' => substr_count($sample, ','), "\t" => substr_count($sample, "\t")];
        arsort($counts);
        $delimiter = (string)key($counts);
        rewind($handle);
        $rows = [];
        while (($row = fgetcsv($handle, 0, $delimiter)) !== false) {
            if (count($rows) >= $maxRows) throw new RuntimeException('El archivo supera el máximo de '.(int)$maxRows.' filas permitidas.');
            $row = array_slice(array_map(function ($value) { return trim((string)$value); }, $row), 0, $maxColumns);
            if (!$rows && isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', $row[0]);
            if (count(array_filter($row, function ($value) { return $value !== ''; })) > 0) $rows[] = $row;
        }
        return $rows;
    } finally {
        fclose($handle);
    }
}

function clear_excel_read_rows($path, $originalName, $maxRows = 5000)
{
    $extension = strtolower(pathinfo((string)$originalName, PATHINFO_EXTENSION));
    if ($extension === 'xlsx') return clear_excel_read_xlsx($path, $maxRows);
    if ($extension === 'csv' || $extension === 'txt') return clear_excel_read_csv($path, $maxRows);
    throw new RuntimeException('Formato no admitido. Usá un archivo .xlsx o .csv.');
}

function clear_excel_normalize_header($value)
{
    $value = strtolower(trim((string)$value));
    $value = strtr($value, ['á'=>'a','é'=>'e','í'=>'i','ó'=>'o','ú'=>'u','ü'=>'u','ñ'=>'n']);
    return preg_replace('/[^a-z0-9]+/', '', $value);
}

function clear_excel_supervisor_records(array $rows)
{
    if (!$rows) throw new RuntimeException('El archivo no contiene filas.');
    $headerRow = -1;
    $columns = [];
    foreach (array_slice($rows, 0, 15, true) as $rowIndex => $row) {
        $candidate = [];
        foreach ($row as $columnIndex => $value) $candidate[clear_excel_normalize_header($value)] = $columnIndex;
        $battery = $candidate['bateria'] ?? null;
        $zone = $candidate['zona'] ?? null;
        $supervisor = $candidate['supervisor'] ?? null;
        $chief = $candidate['jefezona'] ?? ($candidate['jefedezona'] ?? null);
        if ($battery !== null && $zone !== null && $supervisor !== null && $chief !== null) {
            $headerRow = $rowIndex;
            $columns = ['BATERIA'=>$battery,'ZONA'=>$zone,'SUPERVISOR'=>$supervisor,'JEFE_ZONA'=>$chief];
            break;
        }
    }
    if ($headerRow < 0) throw new RuntimeException('No se encontraron las columnas Batería, Zona, Supervisor y Jefe Zona.');

    $records = [];
    foreach ($rows as $rowIndex => $row) {
        if ($rowIndex <= $headerRow) continue;
        $record = [];
        foreach ($columns as $key => $columnIndex) $record[$key] = trim((string)($row[$columnIndex] ?? ''));
        if ($record['BATERIA'] === '' && $record['ZONA'] === '' && $record['SUPERVISOR'] === '' && $record['JEFE_ZONA'] === '') continue;
        if ($record['BATERIA'] === '') throw new RuntimeException('La fila '.($rowIndex + 1).' no tiene batería.');
        foreach (['ZONA'=>'zona','SUPERVISOR'=>'supervisor','JEFE_ZONA'=>'jefe de zona'] as $field => $label) {
            if ($record[$field] === '') throw new RuntimeException('La fila '.($rowIndex + 1).' no tiene '.$label.'.');
        }
        $records[] = $record;
    }
    if (!$records) throw new RuntimeException('El archivo no contiene asignaciones para importar.');
    return $records;
}
