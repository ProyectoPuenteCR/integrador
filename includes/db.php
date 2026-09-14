<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/db.php
   Capa de acceso a SQL Server.
   Drivers soportados:
     'com'         -> COM + ADODB + SQLOLEDB (igual que tu PHPRunner)  [recomendado]
     'sqlsrv'      -> extensión sqlsrv de Microsoft
     'pdo_sqlsrv'  -> PDO con sqlsrv
   Devuelve siempre arrays asociativos.
============================================================= */

class DB
{
    private $driver;
    private $conn;
    private $lastError = '';

    public function __construct(array $cfg)
    {
        $this->driver = $cfg['driver'] ?? 'com';

        switch ($this->driver) {
            case 'pdo_sqlsrv': $this->connectPDO($cfg); break;
            case 'sqlsrv':     $this->connectSqlsrv($cfg); break;
            case 'com':
            default:           $this->connectCom($cfg); break;
        }
        /*
         * IMPORTANTE: una conexión web no debe crear ni alterar objetos SQL.
         * La versión anterior ejecutaba CREATE/ALTER VIEW en cada request,
         * provocando CPU alta, bloqueos de esquema y recompilaciones.
         * Los objetos se instalan una sola vez con los scripts de /SQL.
         */
    }

    /* ---- Método COM/ADODB (el que usa PHPRunner) ---- */
    private function connectCom(array $cfg)
    {
        if (!class_exists('COM')) {
            $this->lastError = 'La extensión com_dotnet (COM) no está disponible en PHP.';
            return;
        }
        $host = $cfg['host'];
        $db   = $cfg['database'];
        $uid  = $cfg['user'];
        $pwd  = $cfg['password'];

        // Mismas cadenas que PHPRunner: primero SQLOLEDB, luego SQLNCLI.
        $strings = [
            "PROVIDER=SQLOLEDB;SERVER=$host;UID=$uid;PWD=$pwd;DATABASE=$db",
            "PROVIDER=SQLNCLI;SERVER=$host;UID=$uid;PWD=$pwd;DATABASE=$db",
            "PROVIDER=MSOLEDBSQL;SERVER=$host;UID=$uid;PWD=$pwd;DATABASE=$db",
        ];

        $cp = defined('CP_UTF8') ? CP_UTF8 : 65001; // 65001 = UTF-8

        $errs = '';
        foreach ($strings as $connStr) {
            try {
                $c = new COM('ADODB.Connection', NULL, $cp);
                $c->Open($connStr);
                $c->CommandTimeout = 120;
                $this->conn = $c;
                return;
            } catch (Exception $e) {
                $errs .= ' | ' . $e->getMessage();
            }
        }
        $this->lastError = 'No se pudo abrir la conexión COM.' . $errs;
        $this->conn = null;
    }

    private function connectSqlsrv(array $cfg)
    {
        if (!function_exists('sqlsrv_connect')) {
            $this->lastError = 'La extensión sqlsrv no está instalada en PHP.';
            return;
        }
        $info = [
            'Database'             => $cfg['database'],
            'UID'                  => $cfg['user'],
            'PWD'                  => $cfg['password'],
            'CharacterSet'         => 'UTF-8',
            'ReturnDatesAsStrings' => true,
        ];
        $this->conn = @sqlsrv_connect($cfg['host'], $info);
        if ($this->conn === false) {
            $this->lastError = $this->formatSqlsrvErrors();
            $this->conn = null;
        }
    }

    private function connectPDO(array $cfg)
    {
        try {
            $dsn = "sqlsrv:Server={$cfg['host']};Database={$cfg['database']}";
            $this->conn = new PDO($dsn, $cfg['user'], $cfg['password'], [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ]);
        } catch (Exception $e) {
            $this->lastError = $e->getMessage();
            $this->conn = null;
        }
    }

    public function ok()    { return $this->conn !== null && $this->conn !== false; }
    public function error() { return $this->lastError; }

    /* Devuelve todas las filas como array asociativo. */
    public function all($sql, array $params = [])
    {
        if (!$this->ok()) return [];
        $sql = $this->applyAlarmFiltersToSelect($sql);

        if ($this->driver === 'com') {
            return $this->hideTechnicalCfnStatus($this->allCom($sql, $params));
        }
        if ($this->driver === 'pdo_sqlsrv') {
            try {
                $stmt = $this->conn->prepare($sql);
                $stmt->execute($params);
                return $this->hideTechnicalCfnStatus($stmt->fetchAll(PDO::FETCH_ASSOC));
            } catch (Exception $e) {
                $this->lastError = $e->getMessage();
                return [];
            }
        }
        // sqlsrv
        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $this->lastError = $this->formatSqlsrvErrors();
            return [];
        }
        $rows = [];
        while (($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) !== null && $row !== false) {
            $rows[] = $row;
        }
        sqlsrv_free_stmt($stmt);
        return $this->hideTechnicalCfnStatus($rows);
    }

    /* Lectura vía COM/ADODB recordset. Como COM no usa parámetros,
       inyectamos los valores de forma segura escapando comillas. */
    private function allCom($sql, array $params)
    {
        if (!empty($params)) {
            $i = 0;
            $sql = preg_replace_callback('/\?/', function () use (&$i, $params) {
                $v = $params[$i] ?? '';
                $i++;
                if (is_int($v) || is_float($v)) return $v;
                return "'" . str_replace("'", "''", (string)$v) . "'";
            }, $sql);
        }

        try {
            $rs = $this->conn->Execute($sql);
        } catch (Throwable $e) {
            $this->lastError = $e->getMessage();
            return [];
        }
        if (!$rs) return [];

        $rows = [];
        try {
            // Consultas como CREATE/INSERT/UPDATE/DELETE no devuelven filas.
            // Acceder a EOF en un recordset cerrado lanza error: lo protegemos.
            if (!is_object($rs)) return [];
            // Si el recordset no está abierto (State != 1), no hay filas que leer.
            $estado = @$rs->State;
            if ($estado !== null && (int)$estado !== 1) return [];

            while (!$rs->EOF) {
                $row = [];
                $n = $rs->Fields->Count;
                for ($f = 0; $f < $n; $f++) {
                    $field = $rs->Fields[$f];
                    $row[$field->Name] = (string)$field->Value;
                }
                $rows[] = $row;
                $rs->MoveNext();
            }
            $rs->Close();
        } catch (Throwable $e) {
            // Consulta sin filas (DDL/DML) o recordset no iterable: devolvemos lo que haya.
            return $rows;
        }
        return $rows;
    }

    /* Ejecuta INSERT/UPDATE/DELETE/DDL y devuelve true si no hubo error. */
    public function execute($sql, array $params = [])
    {
        if (!$this->ok()) return false;
        $this->lastError = '';

        if ($this->driver === 'com') {
            if (!empty($params)) {
                $i = 0;
                $sql = preg_replace_callback('/\?/', function () use (&$i, $params) {
                    $v = $params[$i] ?? '';
                    $i++;
                    if ($v === null) return 'NULL';
                    if (is_int($v) || is_float($v)) return (string)$v;
                    return "'" . str_replace("'", "''", (string)$v) . "'";
                }, $sql);
            }
            try {
                $this->conn->Execute($sql);
                return true;
            } catch (Throwable $e) {
                $this->lastError = $e->getMessage();
                return false;
            }
        }

        if ($this->driver === 'pdo_sqlsrv') {
            try {
                $stmt = $this->conn->prepare($sql);
                return $stmt->execute($params);
            } catch (Exception $e) {
                $this->lastError = $e->getMessage();
                return false;
            }
        }

        $stmt = sqlsrv_query($this->conn, $sql, $params);
        if ($stmt === false) {
            $this->lastError = $this->formatSqlsrvErrors();
            return false;
        }
        sqlsrv_free_stmt($stmt);
        return true;
    }

    /* Primer valor de la primera fila. */
    public function scalar($sql, array $params = [])
    {
        $rows = $this->all($sql, $params);
        if (empty($rows)) return null;
        $first = $rows[0];
        return reset($first);
    }


    /* Instalación manual conservada por compatibilidad administrativa.
       NO se invoca al abrir conexiones web. La instalación normal se hace
       una sola vez con SQL/10_OPTIMIZACION_GLOBAL_CLEAR8.sql. */
    public function ensureAlarmFilterObjects()
    {
        $ddl = [
            "IF OBJECT_ID('dbo.CLEAR_ALARM_FILTERS','U') IS NULL BEGIN CREATE TABLE dbo.CLEAR_ALARM_FILTERS (ID INT IDENTITY(1,1) PRIMARY KEY, CAMPO NVARCHAR(20) NOT NULL DEFAULT 'AMBOS', MODO NVARCHAR(20) NOT NULL DEFAULT 'CONTIENE', VALOR NVARCHAR(500) NOT NULL, ACTIVO BIT NOT NULL DEFAULT 1, FECHA_ALTA DATETIME2 NOT NULL DEFAULT SYSDATETIME(), USUARIO_ALTA NVARCHAR(128) NULL) END",
            "IF NOT EXISTS (SELECT 1 FROM dbo.CLEAR_ALARM_FILTERS WHERE VALOR=N'Shutdown attempt in progress...PTALH03') INSERT INTO dbo.CLEAR_ALARM_FILTERS(CAMPO,MODO,VALOR,ACTIVO,USUARIO_ALTA) VALUES(N'DESCRIPCION',N'CONTIENE',N'Shutdown attempt in progress...PTALH03',1,N'SISTEMA')",
        ];
        foreach ($ddl as $sql) $this->execute($sql);

        $predicateTagDescr = "NOT EXISTS (SELECT 1 FROM dbo.CLEAR_ALARM_FILTERS F WHERE F.ACTIVO=1 AND ((F.CAMPO IN ('TAG','AMBOS') AND ((F.MODO='EXACTO' AND LTRIM(RTRIM(CONVERT(nvarchar(1000),A.ALM_TAGNAME)))=F.VALOR) OR (F.MODO='COMIENZA' AND CONVERT(nvarchar(1000),A.ALM_TAGNAME) LIKE F.VALOR+'%') OR (F.MODO='CONTIENE' AND CONVERT(nvarchar(1000),A.ALM_TAGNAME) LIKE '%'+F.VALOR+'%'))) OR (F.CAMPO IN ('DESCRIPCION','AMBOS') AND ((F.MODO='EXACTO' AND LTRIM(RTRIM(CONVERT(nvarchar(1000),A.ALM_DESCR)))=F.VALOR) OR (F.MODO='COMIENZA' AND CONVERT(nvarchar(1000),A.ALM_DESCR) LIKE F.VALOR+'%') OR (F.MODO='CONTIENE' AND CONVERT(nvarchar(1000),A.ALM_DESCR) LIKE '%'+F.VALOR+'%')))))";
        $predicateRecon = str_replace('A.ALM_TAGNAME','A.TAG_FIX',$predicateTagDescr);
        $predicateSupp = str_replace(['A.ALM_TAGNAME','A.ALM_DESCR'],['A.TagID','A.Descripcion'],$predicateTagDescr);

        $views = [
            'CLEAR_F_FIXALARMS' => "SELECT A.* FROM dbo.FIXALARMS A WHERE $predicateTagDescr",
            'CLEAR_F_FIXALARMS_24H' => "SELECT A.* FROM dbo.FIXALARMS_24H A WHERE $predicateTagDescr",
            'CLEAR_F_FIXALARMS_ONLY' => "SELECT A.* FROM dbo.FIXALARMS_ONLY A WHERE $predicateTagDescr",
            'CLEAR_F_FIXALARMS_RECONOCIDAS' => "SELECT A.* FROM dbo.FIXALARMS_RECONOCIDAS A WHERE $predicateRecon",
            'CLEAR_F_FIXALARMS_SUPRIMIDAS24H' => "SELECT A.* FROM dbo.FIXALARMS_SUPRIMIDAS24H A WHERE $predicateSupp",
            'CLEAR_F_FIXALARMS_TOP20_ALL' => "SELECT ALM_TAGNAME,MAX(CONVERT(nvarchar(1000),ALM_DESCR)) AS DESCRIPCION,COUNT_BIG(*) AS TOTAL_ALARMAS,MAX(CONVERT(nvarchar(500),ALM_ALMEXTFLD2)) AS ALM_ALMEXTFLD2 FROM dbo.CLEAR_F_FIXALARMS WHERE ALM_TAGNAME IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(500),ALM_TAGNAME)))<>'' GROUP BY ALM_TAGNAME",
            'CLEAR_F_FIXALARMS_TOP20_24H' => "SELECT ALM_TAGNAME,MAX(CONVERT(nvarchar(1000),ALM_DESCR)) AS DESCRIPCION,COUNT_BIG(*) AS TOTAL_ALARMAS,MAX(CONVERT(nvarchar(500),ALM_ALMEXTFLD2)) AS ALM_ALMEXTFLD2 FROM dbo.CLEAR_F_FIXALARMS_24H WHERE ALM_TAGNAME IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(500),ALM_TAGNAME)))<>'' GROUP BY ALM_TAGNAME",
            'CLEAR_F_FIXALARMS_TOP20' => "SELECT ALM_TAGNAME,MAX(CONVERT(nvarchar(1000),ALM_DESCR)) AS DESCRIPCION,COUNT_BIG(*) AS TOTAL_ALARMAS,MAX(CONVERT(nvarchar(500),ALM_ALMEXTFLD2)) AS ALM_ALMEXTFLD2 FROM dbo.CLEAR_F_FIXALARMS WHERE ALM_TAGNAME IS NOT NULL AND LTRIM(RTRIM(CONVERT(nvarchar(500),ALM_TAGNAME)))<>'' GROUP BY ALM_TAGNAME",
            'CLEAR_F_FIXALARMS_RANK24H' => "SELECT ROW_NUMBER() OVER(ORDER BY COUNT_BIG(*) DESC) AS Ranking,ALM_TAGNAME,MAX(CONVERT(nvarchar(1000),ALM_DESCR)) AS ALM_DESCR,COUNT_BIG(*) AS Cantidad_Repeticiones FROM dbo.CLEAR_F_FIXALARMS_24H WHERE ALM_TAGNAME IS NOT NULL GROUP BY ALM_TAGNAME",
            'CLEAR_F_FIXALARMS_TENDENCIA_SEM' => "SELECT CONVERT(date,ALM_NATIVETIMEIN) AS Fecha,COUNT_BIG(*) AS Total_Alarmas FROM dbo.CLEAR_F_FIXALARMS WHERE ALM_NATIVETIMEIN>=DATEADD(day,-6,CONVERT(date,GETDATE())) GROUP BY CONVERT(date,ALM_NATIVETIMEIN)",
            'CLEAR_F_FIXALARMS_TEND_SEM_TAGS' => "SELECT CONVERT(date,ALM_NATIVETIMEIN) AS Fecha,ALM_TAGNAME,COUNT_BIG(*) AS Total_Alarmas FROM dbo.CLEAR_F_FIXALARMS WHERE ALM_NATIVETIMEIN>=DATEADD(day,-6,CONVERT(date,GETDATE())) AND ALM_TAGNAME IS NOT NULL GROUP BY CONVERT(date,ALM_NATIVETIMEIN),ALM_TAGNAME",
            'CLEAR_F_FIXALARMS_PRIORITY' => "SELECT ALM_ALMPRIORITY,COUNT_BIG(*) AS TOTAL FROM dbo.CLEAR_F_FIXALARMS GROUP BY ALM_ALMPRIORITY",
            'CLEAR_F_FIXALAMRS_TOP_HML' => "SELECT CONVERT(date,ALM_NATIVETIMEIN) AS Fecha,SUM(CASE WHEN UPPER(CONVERT(nvarchar(100),ALM_ALMPRIORITY)) IN ('HIGH','HI','ALTA','CRITICAL','CRITICA') THEN 1 ELSE 0 END) AS PrioridadAlta,SUM(CASE WHEN UPPER(CONVERT(nvarchar(100),ALM_ALMPRIORITY)) IN ('MED','MEDIUM','MEDIA') THEN 1 ELSE 0 END) AS PrioridadMedia,SUM(CASE WHEN UPPER(CONVERT(nvarchar(100),ALM_ALMPRIORITY)) IN ('LOW','LO','BAJA','INFO') THEN 1 ELSE 0 END) AS PrioridadBaja FROM dbo.CLEAR_F_FIXALARMS WHERE ALM_NATIVETIMEIN>=DATEADD(day,-6,CONVERT(date,GETDATE())) GROUP BY CONVERT(date,ALM_NATIVETIMEIN)",
            'CLEAR_F_FIXALARMS_RECONOCIDAS_usr' => "SELECT ROW_NUMBER() OVER(ORDER BY COUNT_BIG(*) DESC) AS RANKING,TAG_FIX,OPERADOR,COUNT_BIG(*) AS CANTIDAD_RECONOCIMIENTOS FROM dbo.CLEAR_F_FIXALARMS_RECONOCIDAS GROUP BY TAG_FIX,OPERADOR",
        ];
        $policyManaged = (int)$this->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARM_POLICY',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
        foreach ($views as $name => $select) {
            // La migración CFN genera estas vistas dinámicamente para conservar
            // sus columnas y aplicar la política. No sobrescribirlas aquí.
            if ($policyManaged && in_array($name, ['CLEAR_F_FIXALARMS','CLEAR_F_FIXALARMS_24H','CLEAR_F_FIXALARMS_ONLY'], true)) continue;
            $sql = "IF OBJECT_ID('dbo.$name','V') IS NULL EXEC(N'CREATE VIEW dbo.$name AS SELECT 1 AS placeholder')";
            $this->execute($sql);
            $escaped = str_replace("'", "''", "ALTER VIEW dbo.$name AS $select");
            $this->execute("EXEC(N'$escaped')");
        }
    }

    private function applyAlarmFiltersToSelect($sql)
    {
        if (!is_string($sql) || !preg_match('/^\s*(SELECT|WITH)\b/i', $sql)) return $sql;
        $map = [
            '[dbo].[FIXALARMS_24H]'=>'[dbo].[CLEAR_F_FIXALARMS_24H]',
            'dbo.[FIXALARMS_24H]'=>'dbo.[CLEAR_F_FIXALARMS_24H]',
            '[dbo].FIXALARMS_24H'=>'[dbo].CLEAR_F_FIXALARMS_24H',
            '[dbo].[FIXALARMS_ONLY]'=>'[dbo].[CLEAR_F_FIXALARMS_ONLY]',
            'dbo.[FIXALARMS_ONLY]'=>'dbo.[CLEAR_F_FIXALARMS_ONLY]',
            '[dbo].FIXALARMS_ONLY'=>'[dbo].CLEAR_F_FIXALARMS_ONLY',
            '[dbo].[FIXALARMS]'=>'[dbo].[CLEAR_F_FIXALARMS]',
            'dbo.[FIXALARMS]'=>'dbo.[CLEAR_F_FIXALARMS]',
            '[dbo].FIXALARMS'=>'[dbo].CLEAR_F_FIXALARMS',
            'dbo.FIXALARMS_SUPRIMIDAS24H'=>'dbo.CLEAR_F_FIXALARMS_SUPRIMIDAS24H',
            'dbo.FIXALARMS_RECONOCIDAS_usr'=>'dbo.CLEAR_F_FIXALARMS_RECONOCIDAS_usr',
            'dbo.FIXALARMS_RECONOCIDAS'=>'dbo.CLEAR_F_FIXALARMS_RECONOCIDAS',
            'dbo.FIXALARMS_TEND_SEM_TAGS'=>'dbo.CLEAR_F_FIXALARMS_TEND_SEM_TAGS',
            'dbo.FIXALARMS_TENDENCIA_SEM'=>'dbo.CLEAR_F_FIXALARMS_TENDENCIA_SEM',
            'dbo.FIXALARMS_TOP20_24H'=>'dbo.CLEAR_F_FIXALARMS_TOP20_24H',
            'dbo.FIXALARMS_TOP20_ALL'=>'dbo.CLEAR_F_FIXALARMS_TOP20_ALL',
            'dbo.FIXALARMS_RANK24H'=>'dbo.CLEAR_F_FIXALARMS_RANK24H',
            'dbo.FIXALARMS_PRIORITY'=>'dbo.CLEAR_F_FIXALARMS_PRIORITY',
            'dbo.FIXALAMRS_TOP_HML'=>'dbo.CLEAR_F_FIXALAMRS_TOP_HML',
            'dbo.FIXALARMS_TOP20'=>'dbo.CLEAR_F_FIXALARMS_TOP20',
            'dbo.FIXALARMS_24H'=>'dbo.CLEAR_F_FIXALARMS_24H',
            'dbo.FIXALARMS_ONLY'=>'dbo.CLEAR_F_FIXALARMS_ONLY',
            'dbo.FIXALARMS'=>'dbo.CLEAR_F_FIXALARMS',
        ];
        // Reemplazar únicamente nombres completos para no afectar tablas como FIXALARMS_USR.
        uksort($map, function($a, $b) { return strlen($b) <=> strlen($a); });
        foreach ($map as $from => $to) {
            $pattern = '/(?<![A-Z0-9_])' . preg_quote($from, '/') . '(?![A-Z0-9_])/i';
            $sql = preg_replace($pattern, $to, $sql);
        }
        return $sql;
    }

    /* CFN es un estado técnico de transición de iFIX. Su visibilidad se
       gobierna desde CLEAR_ALARM_POLICY. La vista SQL realiza el control
       principal; esta segunda barrera evita exponer CFN si una consulta
       heredada entrega ALM_ALMSTATUS sin pasar por la vista. */
    private function hideTechnicalCfnStatus(array $rows)
    {
        $hasStatusColumn = false;
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            foreach ($row as $column => $value) {
                if (strtoupper((string)$column) === 'ALM_ALMSTATUS') {
                    $hasStatusColumn = true;
                    break 2;
                }
            }
        }
        if (!$hasStatusColumn || clear_alarm_cfn_mode($this) !== 'OCULTAR_ESTADO') return $rows;

        foreach ($rows as &$row) {
            if (!is_array($row)) continue;
            foreach ($row as $column => $value) {
                if (strtoupper((string)$column) !== 'ALM_ALMSTATUS') continue;
                if (strtoupper(trim((string)$value)) === 'CFN') $row[$column] = '';
            }
        }
        unset($row);
        return $rows;
    }

    private function formatSqlsrvErrors()
    {
        $errs = function_exists('sqlsrv_errors') ? sqlsrv_errors() : null;
        if (!$errs) return 'Error desconocido de SQL Server.';
        $msgs = [];
        foreach ($errs as $e) { $msgs[] = $e['message']; }
        return implode(' | ', $msgs);
    }
}

function clear_db()
{
    static $db = null;
    if ($db === null) {
        $cfg = require dirname(__DIR__) . '/config.php';
        $db = new DB($cfg['db']);
    }
    return $db;
}

/* Política global CFN. El valor se consulta como máximo una vez por request.
   Si la migración todavía no fue instalada, conserva el comportamiento seguro
   anterior: filas incluidas y texto CFN oculto. */
function clear_alarm_cfn_mode($db = null, $refresh = false)
{
    static $cached = null;
    if ($refresh) $cached = null;
    if ($cached !== null) return $cached;

    $cached = 'OCULTAR_ESTADO';
    $db = $db ?: clear_db();
    if (!$db || !$db->ok()) return $cached;

    $exists = (int)$db->scalar("SELECT CASE WHEN OBJECT_ID(N'dbo.CLEAR_ALARM_POLICY',N'U') IS NULL THEN 0 ELSE 1 END") === 1;
    if (!$exists) return $cached;
    $value = $db->scalar("SELECT TOP 1 MODO_ACTIVO FROM dbo.CLEAR_ALARM_POLICY WHERE ID=1");
    $value = strtoupper(trim((string)$value));
    if (in_array($value, ['OCULTAR_ESTADO','MOSTRAR','EXCLUIR'], true)) $cached = $value;
    return $cached;
}

function clear_alarm_cfn_show_status($db = null)
{
    return clear_alarm_cfn_mode($db) === 'MOSTRAR';
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
