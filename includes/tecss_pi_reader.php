<?php
require_once __DIR__ . '/pi_transport.php';
class TecssPiNotFound extends RuntimeException {}

// Este lector sólo emite GET. No captura valores, acusa eventos ni envía mensajes.
class TecssPiReader
{
    private $get;
    private $attributes = [];

    public function __construct(callable $get) { $this->get = $get; }

    private function read($path, array $query = [])
    {
        $result = call_user_func($this->get, $path . ($query ? '?' . http_build_query($query) : ''));
        if (!is_array($result)) throw new RuntimeException('Respuesta PI inválida.');
        return $result;
    }

    public function items($path, array $query = [])
    {
        $items = [];
        for ($page=0; $page<200; $page++) {
            $result = $this->read($path, array_merge($query, ['startIndex'=>$page*500,'maxCount'=>500]));
            if (!isset($result['Items']) || !is_array($result['Items'])) throw new RuntimeException('Colección PI inválida.');
            $items = array_merge($items, $result['Items']);
            if (count($result['Items']) < 500) return $items;
        }
        throw new RuntimeException('La consulta PI excede el límite; no se publica una sincronización incompleta.');
    }

    private function attributes($kind, $id)
    {
        $key = $kind . '/' . $id;
        if (!isset($this->attributes[$key])) {
            $map = [];
            foreach ($this->items('/'.$key.'/attributes') as $attribute) $map[$attribute['Name']] = $attribute;
            $this->attributes[$key] = $map;
        }
        return $this->attributes[$key];
    }

    private function value($attribute, $time)
    {
        if (empty($attribute['WebId'])) return null;
        $result = $this->read('/streams/'.rawurlencode($attribute['WebId']).'/value', ['time'=>$time]);
        return ($result['Good'] ?? false) === true ? ($result['Value'] ?? null) : null;
    }

    public function database($path)
    {
        $result = $this->read('/assetdatabases', ['path'=>$path]);
        if (empty($result['WebId'])) throw new RuntimeException('No se pudo resolver la base AF.');
        return $result['WebId'];
    }

    public function frame($id)
    {
        try { return $this->read('/eventframes/'.rawurlencode($id)); }
        catch (TecssPiNotFound $error) { return null; }
    }

    public function collect($database, $template, $from, $through, array $wellMap, array $extraFrames = [], $nameFilter = null)
    {
        $query = [
            'templateName'=>$template, 'searchMode'=>'Overlapped', 'searchFullHierarchy'=>'true',
            'startTime'=>$from, 'endTime'=>$through, 'sortField'=>'StartTime', 'sortOrder'=>'Ascending'];
        if ($nameFilter !== null) $query['nameFilter'] = $nameFilter;
        $frames = $this->items('/assetdatabases/'.rawurlencode($database).'/eventframes', $query);
        // Releer los abiertos antiguos aunque hayan cerrado antes de esta ventana.
        $byId = [];
        foreach (array_merge($frames, $extraFrames) as $frame) $byId[$frame['WebId']] = $frame;
        $frames = array_values($byId);
        $events = [];
        foreach ($frames as $frame) {
            if (($frame['TemplateName'] ?? '') !== $template || empty($frame['WebId'])) throw new RuntimeException('Evento PI de plantilla inesperada.');
            $id = rawurlencode($frame['WebId']);
            $start = tecss_pi_date($frame['StartTime'] ?? '');
            if (!$start) throw new RuntimeException('Evento PI sin fecha de inicio válida.');
            $end = tecss_pi_date($frame['EndTime'] ?? '', true);
            if ($end && $end < $start) throw new RuntimeException('Evento PI con fechas inconsistentes.');
            $attributes = $this->attributes('eventframes', $id);
            $well = $this->value($attributes['POZO'] ?? [], $frame['StartTime']);
            $key = tecss_pi_well_key(is_string($well) ? $well : '');
            if ($key === '' || !isset($wellMap[$key])) throw new RuntimeException('Evento PI sin correspondencia única con un pozo CLEAR: '.$frame['Name']);
            $elements = $this->items('/eventframes/'.$id.'/referencedelements');
            // Selección exacta del elemento TECSS del mismo pozo. No usar el primer resultado.
            $elements = array_values(array_filter($elements, function($element) use ($key) {
                $parts = explode('\\', rtrim($element['Path'] ?? '', '\\'));
                return strcasecmp(array_pop($parts) ?? '', 'TECSS') === 0 && tecss_pi_well_key(array_pop($parts) ?? '') === $key;
            }));
            if (count($elements) !== 1) throw new RuntimeException('Referencia TECSS ambigua o ausente: '.$frame['Name']);
            $source = $this->attributes('elements', rawurlencode($elements[0]['WebId']));
            // Leer los atributos del elemento al inicio, no el valor final capturado en el EF.
            $vibration = $this->value($source['VI-001-V'] ?? [], $frame['StartTime']);
            $threshold = $this->value($source['3SIGMA'] ?? [], $frame['StartTime']);
            $notification = null;
            $annotationsRead = false;
            try {
                $annotations = $this->items('/eventframes/'.$id.'/annotations');
                $annotationsRead = true;
                foreach ($annotations as $annotation) {
                    $creator = $annotation['Creator'] ?? '';
                    if (is_array($creator)) $creator = $creator['Name'] ?? '';
                    $text = $annotation['Value'] ?? $annotation['Description'] ?? '';
                    if (!is_string($text) || stripos($creator, 'PINotificationsService') === false ||
                        !preg_match('/\bNotification sent to\s+[1-9][0-9]*\b/i', $text)) continue;
                    $date = tecss_pi_date($annotation['CreationDate'] ?? $annotation['CreationTime'] ?? '');
                    if ($date && (!$notification || $date < $notification)) $notification = $date;
                }
            } catch (RuntimeException $error) {
                // La falta de permiso a anotaciones no implica que no se haya enviado un correo.
            }
            $events[$frame['WebId']] = [
                'id'=>$frame['WebId'], 'well'=>$wellMap[$key], 'name'=>$frame['Name'],
                'start'=>$start, 'end'=>$end, 'vibration'=>tecss_pi_scaled($vibration),
                'threshold'=>tecss_pi_scaled($threshold), 'notification'=>$notification,
                'annotations_read'=>$annotationsRead ? 1 : 0];
        }
        return array_values($events);
    }
}

function tecss_pi_well_key($well)
{
    // No igualar sólo por número: LH-945 y otro yacimiento 945 son pozos distintos.
    return strtoupper(preg_replace('/^YPF\.SC\./i', '', trim($well)));
}

function tecss_pi_scaled($value)
{
    return is_numeric($value) && is_finite((float)$value) ? (float)$value * 1000 : null;
}

function tecss_pi_date($value, $allowOpen = false)
{
    if ($allowOpen && preg_match('/^9999-12-31/', $value)) return null;
    if (!is_string($value) || !preg_match('/^\d{4}-\d\d-\d\dT.*(?:Z|[+-]\d\d:\d\d)$/', $value)) {
        throw new RuntimeException('PI devolvió una fecha sin zona horaria válida.');
    }
    try { $date = new DateTimeImmutable($value); }
    catch (Exception $error) { throw new RuntimeException('Fecha PI inválida.'); }
    return $date->setTimezone(new DateTimeZone('America/Argentina/Buenos_Aires'))->format('Y-m-d H:i:s.v');
}
