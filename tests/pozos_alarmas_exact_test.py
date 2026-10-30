"""Prueba del filtro real de pantalla/exportación con pozos vecinos.

Ejecutar con Python 3 y PHP CLI (PHP_BINARY permite elegir PHP 7.4).
No conecta a CLEAR: ejecuta los constructores PHP y sus predicados sobre
SQLite en memoria. Sólo elimina el CONVERT de tipo SQL Server del texto.
"""
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
from html.parser import HTMLParser
from urllib.parse import parse_qs, urlencode

ROOT = Path(__file__).resolve().parents[1]
PHP = os.environ.get("PHP_BINARY", "php")
PROGRAM = r'''
$root = $argv[1];
require $root.'/includes/pozos_alarmas_exact.php';
function pa24_terms($value) {
    return array_values(array_filter(preg_split('/\s+/',trim($value)),function($v){return $v!=='';}));
}
function pex_terms($value) { return pa24_terms($value); }
function clear_zones_all($db) { return []; }
function clear_zone_valid($value,$zones) { return ''; }
function build_filter($root,$file,$request) {
    $_GET=$request;
    $source=file_get_contents($root.'/'.$file);
    if(!preg_match('/\$baseConditions\s*=/',$source,$match,PREG_OFFSET_CAPTURE))
        throw new RuntimeException('No se encontró el constructor de filtro.');
    $start=$match[0][1];
    if(!preg_match('/\$rangeConditions\s*=/',$source,$match,PREG_OFFSET_CAPTURE,$start))
        throw new RuntimeException('No se encontró el final del filtro.');
    $builder=substr($source,$start,$match[0][1]-$start);
    $wellExact=pa_well_exact_requested($request);
    $wellSearch=$request['pozo']??'';
    $wellMetadata=['DATA_TYPE'=>'varchar','CHARACTER_MAXIMUM_LENGTH'=>255];
    $well=$wellColumn='ALM_ALMEXTFLD2';$tag=$tagColumn='ALM_TAGNAME';
    $desc=$descColumn='ALM_DESCR';$prefix=$wellPrefix='';$zoneFilter=$descSearch='';
    $db=null;
    eval($builder);
    return ['sql'=>implode(' AND ',$baseConditions),
        'params'=>strpos($file,'_export')!==false?$params:$baseParams];
}
$cases=[
    'exact'=>['pozo_exacto'=>'1','pozo'=>'YPF.SC.BB-10'],
    'free'=>['pozo'=>'YPF.SC.BB-10'],
    'empty'=>['pozo_exacto'=>'1','pozo'=>''],
    'literal'=>['pozo_exacto'=>'1','pozo'=>'YPF.SC.BB_10%'],
    'spaced'=>['pozo_exacto'=>'1','pozo'=>'Pozo Sur-1']
];
$out=['filters'=>[]];
foreach(['pozos_alarmas24.php','pozos_alarmas24_export.php'] as $file)
    foreach($cases as $name=>$input)$out['filters'][$file][$name]=build_filter($root,$file,$input);
$source=file_get_contents($root.'/pozos_alarmas24.php');
if(!preg_match('/<\?php if \(\$wellExact\): \?><input type="hidden" name="pozo_exacto" value="1"><\?php endif; \?>/',$source,$hidden))
    throw new RuntimeException('Falta conservar el modo exacto en el formulario.');
foreach([true,false] as $wellExact) {
    ob_start();eval('?>'.$hidden[0]);$out['hidden'][$wellExact?'exact':'free']=ob_get_clean();
}
$out['too_long']=pa_well_exact_filter('ALM_ALMEXTFLD2','BB-100',['DATA_TYPE'=>'varchar','CHARACTER_MAXIMUM_LENGTH'=>5]);
echo json_encode($out,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
'''

completed = subprocess.run(
    [PHP, "-n", "-r", PROGRAM, str(ROOT)],
    capture_output=True, text=True, check=True,
)
assert not completed.stderr, completed.stderr
observed = json.loads(completed.stdout)

database = sqlite3.connect(":memory:")
database.execute("CREATE TABLE wells (ALM_ALMEXTFLD2 TEXT)")
wells = [
    "YPF.SC.BB-10", "YPF.SC.BB-100", "YPF.SC.BB-101",
    "OTRO_YPF.SC.BB-10", "YPF.SC.BB_10%", "Pozo Sur-1", "Pozo Sur-10",
]
database.executemany("INSERT INTO wells VALUES (?)", [(well,) for well in wells])

def rows(predicate):
    sql = re.sub(
        r"CONVERT\((?:n?varchar|n?char)\((?:\d+|max)\),\s*(\?|\[[^\]]+\])\)",
        r"\1", predicate["sql"], flags=re.I,
    )
    return [
        row[0] for row in database.execute(
            "SELECT ALM_ALMEXTFLD2 FROM wells WHERE " + sql, predicate["params"]
        )
    ]

for file, filters in observed["filters"].items():
    assert rows(filters["exact"]) == ["YPF.SC.BB-10"], (file, "mezcló pozos vecinos")
    assert rows(filters["free"]) == wells[:5], (file, "cambió la búsqueda libre")
    assert rows(filters["empty"]) == [], (file, "pozo exacto vacío amplió la consulta")
    assert rows(filters["literal"]) == ["YPF.SC.BB_10%"], (file, "interpretó comodines")
    assert rows(filters["spaced"]) == ["Pozo Sur-1"], (file, "partió el nombre exacto")
    assert filters["exact"]["sql"] == "[ALM_ALMEXTFLD2] = CONVERT(varchar(255), ?)", (
        file, "aplicó funciones sobre el campo indexado"
    )
assert rows(observed["too_long"]) == [], "El parámetro truncado podría seleccionar otro pozo"
for case, screen_filter in observed["filters"]["pozos_alarmas24.php"].items():
    export_filter = observed["filters"]["pozos_alarmas24_export.php"][case]
    assert rows(screen_filter) == rows(export_filter) and screen_filter["params"] == export_filter["params"], (
        case, "Pantalla y Excel deben usar el mismo alcance"
    )

class FormFields(HTMLParser):
    def __init__(self):
        super().__init__()
        self.fields = {}
    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag == "input" and "name" in attrs:
            self.fields[attrs["name"]] = attrs.get("value", "")

form = FormFields()
form.feed(observed["hidden"]["exact"])
form.fields["pozo"] = "YPF.SC.BB-10"
export_params = parse_qs(urlencode(form.fields))
assert export_params["pozo_exacto"] == ["1"] and export_params["pozo"] == ["YPF.SC.BB-10"]
assert observed["hidden"]["free"] == "", "La búsqueda libre no debe forzar el modo exacto"
print("OK alarmas: pantalla/Excel exactos, BB-10 excluye vecinos, comodines literales, búsqueda libre y parámetro de formulario/exportación.")
