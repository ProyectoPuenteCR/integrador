#!/usr/bin/env python3
"""Procesador estadistico de vibraciones TECSS para CLEAR.

Lee VIBR y GPM desde la vista de origen, mantiene una cache acotada en
LC_MDB y publica estados/eventos que la aplicacion PHP puede consultar sin
ejecutar Python durante una peticion web.
"""

from __future__ import annotations

import argparse
import json
import logging
import re
import sys
import traceback
from datetime import datetime, timedelta
from pathlib import Path
from typing import Any, Dict, Iterable, List, Optional, Tuple

import numpy as np
import pandas as pd
try:
    import pyodbc
except ModuleNotFoundError:  # Permite ejecutar --self-test antes de instalar ODBC.
    pyodbc = None  # type: ignore


APP_DIR = Path(__file__).resolve().parent
DEFAULT_CONFIG = APP_DIR / "config.json"
LOG_DIR = APP_DIR / "logs"

REQUIRED_TABLES = (
    "dbo.CLEAR_TECSS_VIBRACIONES_DATO",
    "dbo.CLEAR_TECSS_VIBRACIONES_ESTADO",
    "dbo.CLEAR_TECSS_VIBRACIONES_EVENTO",
    "dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION",
)

STATE_LABELS = {
    "normal": "Normal",
    "frecuente": "Exceso frecuente",
    "alerta": "Alerta",
    "critico": "Crítico",
    "parado": "Parado / Sin señal",
}


def configure_logging() -> None:
    LOG_DIR.mkdir(parents=True, exist_ok=True)
    log_file = LOG_DIR / ("vibraciones_tecss_" + datetime.now().strftime("%Y%m%d") + ".log")
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s | %(levelname)s | %(message)s",
        handlers=[
            logging.FileHandler(log_file, encoding="utf-8"),
            logging.StreamHandler(sys.stdout),
        ],
    )


def load_config(path: Path) -> Dict[str, Any]:
    if not path.exists():
        raise FileNotFoundError(
            f"No existe {path}. Copie config.example.json como config.json y complete las credenciales."
        )
    with path.open("r", encoding="utf-8") as handle:
        cfg = json.load(handle)
    for section in ("source", "destination", "analysis", "runtime"):
        if section not in cfg or not isinstance(cfg[section], dict):
            raise ValueError(f"Falta la seccion '{section}' en {path}")
    for section in ("source", "destination"):
        for field in ("server", "database"):
            if not str(cfg[section].get(field, "")).strip():
                raise ValueError(f"Falta {section}.{field} en {path}")
        if not cfg[section].get("trusted_connection", False):
            for field in ("user", "password"):
                value = str(cfg[section].get(field, "")).strip()
                if not value or value.startswith("COMPLETAR_"):
                    raise ValueError(f"Debe completar {section}.{field} en {path}")
    return cfg


def pick_sql_driver() -> str:
    if pyodbc is None:
        raise RuntimeError(
            "Falta el paquete pyodbc. Ejecute: python -m pip install -r requirements.txt"
        )
    installed = pyodbc.drivers()
    for candidate in ("ODBC Driver 18 for SQL Server", "ODBC Driver 17 for SQL Server"):
        if candidate in installed:
            return candidate
    candidates = [name for name in installed if "SQL Server" in name]
    if not candidates:
        raise RuntimeError(
            "No se encontro un controlador ODBC de SQL Server. Instale Microsoft ODBC Driver 18."
        )
    return candidates[-1]


def make_connection(section: Dict[str, Any], autocommit: bool = False) -> pyodbc.Connection:
    driver = pick_sql_driver()
    parts = [
        f"DRIVER={{{driver}}}",
        f"SERVER={section['server']}",
        f"DATABASE={section['database']}",
        "Encrypt=no",
        "TrustServerCertificate=yes",
    ]
    if section.get("trusted_connection", False):
        parts.append("Trusted_Connection=yes")
    else:
        parts.extend((f"UID={section['user']}", f"PWD={section['password']}"))
    timeout = int(section.get("connection_timeout", 30))
    return pyodbc.connect(";".join(parts) + ";", timeout=timeout, autocommit=autocommit)


def quote_identifier(identifier: str) -> str:
    value = str(identifier).strip()
    if not value:
        raise ValueError("Identificador SQL vacio")
    parts = value.split(".")
    if any(not re.fullmatch(r"[A-Za-z_][A-Za-z0-9_@$#-]*", part) for part in parts):
        raise ValueError(f"Identificador SQL no permitido: {identifier}")
    return ".".join("[" + part.replace("]", "]]" ) + "]" for part in parts)


def normalize_name(value: Any) -> str:
    return re.sub(r"[^A-Z0-9]", "", str(value or "").upper())


def find_column(columns: Iterable[str], aliases: Iterable[str]) -> Optional[str]:
    normalized = {normalize_name(column): column for column in columns}
    for alias in aliases:
        if normalize_name(alias) in normalized:
            return normalized[normalize_name(alias)]
    return None


def discover_source_columns(connection: pyodbc.Connection, view_name: str) -> Dict[str, str]:
    quoted_view = quote_identifier(view_name)
    probe = pd.read_sql_query(f"SELECT TOP (50) * FROM {quoted_view}", connection)
    columns = list(probe.columns)
    discovered = {
        "well": find_column(columns, ("pozo", "equipo", "well_id")),
        "date": find_column(columns, ("fecha", "timestamp", "fecha_hora", "datetime", "ts")),
        "variable": find_column(
            columns,
            ("nombretipovble", "nombrevble", "variable", "tipo", "tipovariable"),
        ),
        "value": find_column(columns, ("valor", "value", "valorvble", "vblevalue", "dato")),
    }
    if not discovered["value"]:
        excluded = {item for key, item in discovered.items() if key != "value" and item}
        excluded.update(
            column
            for column in columns
            if normalize_name(column) in {
                "BATERIA",
                "DESCRIPCION",
                "UNIDAD",
                "PANTALLA",
            }
        )
        best: Tuple[float, Optional[str]] = (0.0, None)
        for column in columns:
            if column in excluded:
                continue
            numeric_ratio = float(pd.to_numeric(probe[column], errors="coerce").notna().mean())
            if numeric_ratio > best[0]:
                best = (numeric_ratio, column)
        if best[0] > 0:
            discovered["value"] = best[1]
    missing = [key for key, value in discovered.items() if not value]
    if missing:
        raise RuntimeError(
            "No se pudieron detectar las columnas " + ", ".join(missing) +
            f" en {view_name}. Columnas disponibles: {columns}"
        )
    return {key: str(value) for key, value in discovered.items()}


def validate_destination(connection: pyodbc.Connection) -> None:
    cursor = connection.cursor()
    for table in REQUIRED_TABLES:
        cursor.execute("SELECT CASE WHEN OBJECT_ID(?, N'U') IS NULL THEN 0 ELSE 1 END", table)
        if int(cursor.fetchone()[0]) != 1:
            raise RuntimeError(
                f"No existe {table}. Ejecute primero SQL/CLEAR_TECSS_VIBRACIONES.sql en LC_MDB."
            )


def acquire_run_lock(connection: pyodbc.Connection) -> None:
    cursor = connection.cursor()
    cursor.execute(
        "DECLARE @result int; "
        "EXEC @result=sys.sp_getapplock @Resource=N'CLEAR_TECSS_VIBRACIONES_PYTHON',"
        "@LockMode=N'Exclusive',@LockOwner=N'Session',@LockTimeout=0; SELECT @result;"
    )
    result = int(cursor.fetchone()[0])
    if result < 0:
        raise RuntimeError("Ya existe otra ejecución del análisis de vibraciones TECSS.")


def start_run(connection: pyodbc.Connection, cfg: Dict[str, Any]) -> int:
    parameters = json.dumps(cfg.get("analysis", {}), ensure_ascii=False)
    cursor = connection.cursor()
    cursor.execute(
        "INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION "
        "(FECHA_INICIO,ESTADO,PARAMETROS_JSON) VALUES (SYSDATETIME(),'EN_PROCESO',?); "
        "SELECT CONVERT(bigint,SCOPE_IDENTITY());",
        parameters,
    )
    run_id = int(cursor.fetchone()[0])
    connection.commit()
    return run_id


def finish_run(
    connection: pyodbc.Connection,
    run_id: int,
    status: str,
    message: str,
    source_rows: int = 0,
    cache_rows: int = 0,
    wells: int = 0,
    events: int = 0,
) -> None:
    connection.cursor().execute(
        "UPDATE dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION SET "
        "FECHA_FIN=SYSDATETIME(),ESTADO=?,FILAS_ORIGEN=?,FILAS_CACHE=?,"
        "POZOS_ANALIZADOS=?,EVENTOS_GENERADOS=?,MENSAJE=? WHERE ID=?",
        status,
        int(source_rows),
        int(cache_rows),
        int(wells),
        int(events),
        str(message)[:2000],
        int(run_id),
    )
    connection.commit()


def latest_cached_date(connection: pyodbc.Connection) -> Optional[datetime]:
    cursor = connection.cursor()
    cursor.execute("SELECT MAX(FECHA) FROM dbo.CLEAR_TECSS_VIBRACIONES_DATO")
    row = cursor.fetchone()
    return row[0] if row and row[0] else None


def extract_source(
    connection: pyodbc.Connection,
    view_name: str,
    columns: Dict[str, str],
    since: datetime,
    until: datetime,
) -> pd.DataFrame:
    view = quote_identifier(view_name)
    well = quote_identifier(columns["well"])
    date = quote_identifier(columns["date"])
    variable = quote_identifier(columns["variable"])
    value = quote_identifier(columns["value"])
    sql = (
        f"SELECT {well} AS Pozo,{date} AS Fecha,{variable} AS Variable,{value} AS Valor "
        f"FROM {view} WHERE {date}>=? AND {date}<=? "
        f"AND UPPER(LTRIM(RTRIM(CONVERT(nvarchar(100),{variable})))) IN ('VIBR','GPM')"
    )
    logging.info("Extrayendo origen desde %s hasta %s", since, until)
    return pd.read_sql_query(sql, connection, params=[since, until])


def normalize_and_pivot(source: pd.DataFrame) -> pd.DataFrame:
    if source.empty:
        return pd.DataFrame(columns=["Pozo", "Fecha", "VIBR", "GPM"])
    data = source.copy()
    data["Pozo"] = data["Pozo"].astype(str).str.strip()
    data["Fecha"] = pd.to_datetime(data["Fecha"], errors="coerce", dayfirst=True)
    data["Variable"] = data["Variable"].astype(str).str.strip().str.upper()
    data["Valor"] = pd.to_numeric(data["Valor"], errors="coerce")
    data = data.dropna(subset=["Pozo", "Fecha", "Variable", "Valor"])
    data = data[(data["Pozo"] != "") & data["Variable"].isin(["VIBR", "GPM"])]
    pivot = (
        data.pivot_table(
            index=["Pozo", "Fecha"], columns="Variable", values="Valor", aggfunc="mean"
        )
        .reset_index()
        .sort_values(["Pozo", "Fecha"])
        .reset_index(drop=True)
    )
    pivot.columns.name = None
    for column in ("VIBR", "GPM"):
        if column not in pivot.columns:
            pivot[column] = np.nan
    gpm_zero = pivot["GPM"].notna() & (pivot["GPM"] <= 0)
    invalid_vibration = gpm_zero & pivot["VIBR"].notna() & (pivot["VIBR"] > 0)
    if invalid_vibration.any():
        logging.info(
            "Se anularon %s lecturas de VIBR porque GPM era cero.",
            int(invalid_vibration.sum()),
        )
    pivot.loc[gpm_zero, "VIBR"] = np.nan
    return pivot[["Pozo", "Fecha", "VIBR", "GPM"]]


def native_number(value: Any) -> Optional[float]:
    return None if pd.isna(value) else float(value)


def replace_cache_window(
    connection: pyodbc.Connection,
    pivot: pd.DataFrame,
    batch_size: int,
) -> None:
    if pivot.empty:
        return
    start = pd.Timestamp(pivot["Fecha"].min()).to_pydatetime()
    rows = [
        (
            str(row.Pozo),
            pd.Timestamp(row.Fecha).to_pydatetime(),
            native_number(row.VIBR),
            native_number(row.GPM),
        )
        for row in pivot.itertuples(index=False)
    ]
    cursor = connection.cursor()
    cursor.execute("DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_DATO WHERE FECHA>=?", start)
    cursor.fast_executemany = True
    insert_sql = (
        "INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_DATO (POZO,FECHA,VIBR,GPM) VALUES (?,?,?,?)"
    )
    for offset in range(0, len(rows), batch_size):
        cursor.executemany(insert_sql, rows[offset: offset + batch_size])
    connection.commit()


def prune_cache(connection: pyodbc.Connection, retention_days: int) -> None:
    cutoff = datetime.now() - timedelta(days=retention_days)
    connection.cursor().execute(
        "DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_DATO WHERE FECHA<?", cutoff
    )
    connection.commit()


def load_analysis_cache(connection: pyodbc.Connection, history_days: int) -> pd.DataFrame:
    since = datetime.now() - timedelta(days=history_days)
    data = pd.read_sql_query(
        "SELECT POZO AS Pozo,FECHA AS Fecha,VIBR,GPM "
        "FROM dbo.CLEAR_TECSS_VIBRACIONES_DATO WHERE FECHA>=? ORDER BY POZO,FECHA",
        connection,
        params=[since],
    )
    if not data.empty:
        data["Fecha"] = pd.to_datetime(data["Fecha"], errors="coerce")
        data["VIBR"] = pd.to_numeric(data["VIBR"], errors="coerce")
        data["GPM"] = pd.to_numeric(data["GPM"], errors="coerce")
        data = data.dropna(subset=["Pozo", "Fecha"])
    return data


def analyze_frequency(pivot: pd.DataFrame, cfg: Dict[str, Any]) -> Tuple[pd.DataFrame, float, int, int]:
    rows: List[Dict[str, Any]] = []
    for well, group in pivot.groupby("Pozo"):
        ordered = group.sort_values("Fecha")
        differences = ordered["Fecha"].diff().dt.total_seconds().div(60).dropna()
        differences = differences[differences > 0]
        if differences.empty:
            continue
        rows.append(
            {
                "Pozo": well,
                "Freq_mediana_min": round(float(differences.median()), 2),
                "N_registros": int(len(ordered)),
            }
        )
    frequency = pd.DataFrame(rows)
    global_frequency = (
        float(frequency["Freq_mediana_min"].median()) if not frequency.empty else 10.0
    )
    if not np.isfinite(global_frequency) or global_frequency <= 0:
        global_frequency = 10.0
    alert_periods = max(2, round(float(cfg["alert_sustained_minutes"]) / global_frequency))
    critical_periods = max(
        2, round(float(cfg["critical_sustained_minutes"]) / global_frequency)
    )
    return frequency, global_frequency, alert_periods, critical_periods


def calculate_thresholds(pivot: pd.DataFrame, cfg: Dict[str, Any]) -> pd.DataFrame:
    rows: List[Dict[str, Any]] = []
    for well, group in pivot.groupby("Pozo"):
        vibration = group["VIBR"].dropna()
        vibration = vibration[vibration > 0]
        if len(vibration) < 3:
            continue
        median = float(vibration.median())
        if median <= 0:
            continue
        rows.append(
            {
                "Pozo": well,
                "VIBR_mediana": round(median, 3),
                "VIBR_p25": round(float(vibration.quantile(0.25)), 3),
                "VIBR_p75": round(float(vibration.quantile(0.75)), 3),
                "Umbral_ALERTA": round(median * float(cfg["alert_multiplier"]), 3),
                "Umbral_CRITICO": round(median * float(cfg["critical_multiplier"]), 3),
                "N_registros_VIBR": int(len(vibration)),
            }
        )
    return pd.DataFrame(rows)


def detect_sustained_alerts(
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    alert_periods: int,
    critical_periods: int,
    cfg: Dict[str, Any],
) -> pd.DataFrame:
    columns = ["Pozo", "Timestamp", "Nivel", "VIBR_valor", "VIBR_umbral", "Tipo"]
    if thresholds.empty:
        return pd.DataFrame(columns=columns)
    threshold_map = thresholds.set_index("Pozo")[
        ["Umbral_ALERTA", "Umbral_CRITICO"]
    ].to_dict("index")
    alerts: List[Dict[str, Any]] = []
    cooldown_seconds = float(cfg["cooldown_hours"]) * 3600
    for well, group in pivot.groupby("Pozo", sort=False):
        if well not in threshold_map:
            continue
        critical_threshold = float(threshold_map[well]["Umbral_CRITICO"])
        ordered = group.sort_values("Fecha").reset_index(drop=True)
        values = ordered["VIBR"].fillna(0)
        over = (values > critical_threshold) & (values > 0)
        streak_group = (~over).cumsum()
        streaks = over.groupby(streak_group).cumsum()
        for level, periods in (("CRITICO", critical_periods), ("ALERTA", alert_periods)):
            triggers = streaks[streaks >= periods]
            if level == "ALERTA":
                triggers = triggers[streaks[triggers.index] < critical_periods]
            last_time: Optional[pd.Timestamp] = None
            for index in triggers.index:
                event_time = pd.Timestamp(ordered.loc[index, "Fecha"])
                if last_time is None or (event_time - last_time).total_seconds() >= cooldown_seconds:
                    alerts.append(
                        {
                            "Pozo": well,
                            "Timestamp": event_time,
                            "Nivel": level,
                            "VIBR_valor": round(float(values.iloc[index]), 3),
                            "VIBR_umbral": round(critical_threshold, 3),
                            "Tipo": "VIBR " + level,
                        }
                    )
                    last_time = event_time
    return pd.DataFrame(alerts, columns=columns)


def detect_frequent_excesses(
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    cfg: Dict[str, Any],
) -> Dict[str, Dict[str, Any]]:
    result: Dict[str, Dict[str, Any]] = {}
    if thresholds.empty or pivot.empty:
        return result
    threshold_map = thresholds.set_index("Pozo")["Umbral_CRITICO"].to_dict()
    now = pd.Timestamp(pivot["Fecha"].max())
    since = now - pd.Timedelta(hours=float(cfg["recent_window_hours"]))
    for well, group in pivot.groupby("Pozo"):
        if well not in threshold_map:
            continue
        threshold = float(threshold_map[well])
        recent = group[
            (group["Fecha"] >= since) & group["VIBR"].notna() & (group["VIBR"] > 0)
        ]
        count = int((recent["VIBR"] > threshold).sum())
        if count >= int(cfg["frequent_excess_count"]):
            result[well] = {"count": count, "threshold": threshold}
    return result


def calculate_severity(
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    cfg: Dict[str, Any],
) -> Dict[str, Dict[str, Any]]:
    result: Dict[str, Dict[str, Any]] = {}
    if thresholds.empty or pivot.empty:
        return result
    threshold_map = thresholds.set_index("Pozo")[["Umbral_ALERTA", "Umbral_CRITICO"]].to_dict("index")
    now = pd.Timestamp(pivot["Fecha"].max())
    since = now - pd.Timedelta(hours=float(cfg["recent_window_hours"]))
    for well, group in pivot.groupby("Pozo"):
        if well not in threshold_map:
            continue
        alert_threshold = float(threshold_map[well]["Umbral_ALERTA"])
        critical_threshold = float(threshold_map[well]["Umbral_CRITICO"])
        recent = group[group["Fecha"] >= since][["Fecha", "VIBR"]].dropna(subset=["VIBR"])
        recent = recent[recent["VIBR"] > 0]
        total = max(len(recent), 1)
        over_critical = recent[recent["VIBR"] > critical_threshold]
        over_alert = recent[recent["VIBR"] > alert_threshold]
        if not over_critical.empty:
            reference = critical_threshold
            over = over_critical
            reference_level = "CRITICO"
        elif not over_alert.empty:
            reference = alert_threshold
            over = over_alert
            reference_level = "ALERTA"
        else:
            result[well] = {
                "score": 0.0,
                "average_excess": 0.0,
                "maximum_excess": 0.0,
                "count": 0,
                "reference_level": "-",
                "peaks": [],
            }
            continue
        excess = (over["VIBR"] - reference) / reference * 100
        average = round(float(excess.mean()), 2)
        maximum = round(float(excess.max()), 2)
        count = int(len(over))
        peaks: List[Dict[str, Any]] = []
        if not over_critical.empty:
            ordered = recent.sort_values("Fecha").reset_index(drop=True)
            mask = ordered["VIBR"] > critical_threshold
            groups = (~mask).cumsum()
            for _, peak_group in ordered[mask].groupby(groups[mask]):
                if len(peak_group) < 3:
                    for _, row in peak_group.iterrows():
                        peaks.append(
                            {
                                "date": pd.Timestamp(row["Fecha"]),
                                "vibration": round(float(row["VIBR"]), 3),
                                "threshold": round(critical_threshold, 3),
                                "readings": int(len(peak_group)),
                            }
                        )
        result[well] = {
            "score": round(average * count / total, 2),
            "average_excess": average,
            "maximum_excess": maximum,
            "count": count,
            "reference_level": reference_level,
            "peaks": peaks,
        }
    return result


def is_stopped(well: str, pivot: pd.DataFrame, cfg: Dict[str, Any]) -> bool:
    values = pivot[pivot["Pozo"] == well]["VIBR"].dropna()
    if values.empty:
        return True
    last = values.tail(int(cfg["zero_last_readings"]))
    return bool((last <= float(cfg["zero_threshold"])).all())


def is_clean(
    well: str,
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    now: pd.Timestamp,
    cfg: Dict[str, Any],
) -> bool:
    if thresholds.empty or well not in set(thresholds["Pozo"]):
        return True
    alert_threshold = float(
        thresholds.loc[thresholds["Pozo"] == well, "Umbral_ALERTA"].iloc[0]
    )
    since = now - pd.Timedelta(hours=float(cfg["clean_hours"]))
    recent = pivot[(pivot["Pozo"] == well) & (pivot["Fecha"] >= since)]["VIBR"].dropna()
    recent = recent[recent > 0]
    return True if recent.empty else bool((recent <= alert_threshold).all())


def well_state(
    well: str,
    alerts: pd.DataFrame,
    frequent: Dict[str, Dict[str, Any]],
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    now: pd.Timestamp,
    cfg: Dict[str, Any],
) -> str:
    if is_stopped(well, pivot, cfg):
        return "parado"
    if not alerts.empty:
        subset = alerts[alerts["Pozo"] == well]
        if not subset.empty:
            window = now - pd.Timedelta(hours=float(cfg["active_state_hours"]))
            recent = subset[subset["Timestamp"] >= window]
            if not recent.empty:
                if "CRITICO" in set(recent["Nivel"]):
                    return "critico"
                if "ALERTA" in set(recent["Nivel"]):
                    return "alerta"
            elif not is_clean(well, pivot, thresholds, now, cfg):
                return "alerta"
    if well in frequent:
        return "frecuente"
    return "normal"


def load_battery_mapping(connection: pyodbc.Connection, cfg: Dict[str, Any]) -> Dict[str, str]:
    mapping_cfg = cfg.get("mapping", {})
    table = quote_identifier(mapping_cfg.get("table", "dbo.TECSS_RTQP"))
    well_column = quote_identifier(mapping_cfg.get("well_column", "POZO"))
    battery_column = quote_identifier(mapping_cfg.get("battery_column", "BATERIA"))
    try:
        rows = connection.cursor().execute(
            f"SELECT {well_column},{battery_column} FROM {table} "
            f"WHERE {well_column} IS NOT NULL"
        ).fetchall()
    except pyodbc.Error as error:
        logging.warning("No se pudo cargar el mapeo de baterias: %s", error)
        return {}
    result: Dict[str, str] = {}
    for row in rows:
        well = str(row[0] or "").strip()
        battery = str(row[1] or "").strip()
        if well:
            result[normalize_name(well)] = battery or "SIN BATERIA"
    return result


def last_numeric(group: pd.DataFrame, column: str) -> Optional[float]:
    values = group[column].dropna()
    return None if values.empty else float(values.iloc[-1])


def build_results(
    pivot: pd.DataFrame,
    thresholds: pd.DataFrame,
    frequency: pd.DataFrame,
    alerts: pd.DataFrame,
    frequent: Dict[str, Dict[str, Any]],
    severity: Dict[str, Dict[str, Any]],
    battery_mapping: Dict[str, str],
    cfg: Dict[str, Any],
) -> Tuple[List[Tuple[Any, ...]], List[Tuple[Any, ...]]]:
    analysis_time = datetime.now().replace(microsecond=0)
    now = pd.Timestamp(pivot["Fecha"].max())
    threshold_map = thresholds.set_index("Pozo").to_dict("index") if not thresholds.empty else {}
    frequency_map = frequency.set_index("Pozo").to_dict("index") if not frequency.empty else {}
    state_rows: List[Tuple[Any, ...]] = []
    event_rows: List[Tuple[Any, ...]] = []
    recent_since = now - pd.Timedelta(hours=float(cfg["recent_window_hours"]))

    for well, group in pivot.groupby("Pozo", sort=True):
        ordered = group.sort_values("Fecha")
        threshold = threshold_map.get(well, {})
        well_alerts = alerts[alerts["Pozo"] == well] if not alerts.empty else pd.DataFrame()
        recent_alerts = (
            well_alerts[well_alerts["Timestamp"] >= recent_since]
            if not well_alerts.empty else well_alerts
        )
        latest_alert = None if well_alerts.empty else pd.Timestamp(well_alerts["Timestamp"].max())
        critical = (
            well_alerts[well_alerts["Nivel"] == "CRITICO"]
            if not well_alerts.empty else pd.DataFrame()
        )
        latest_critical = None if critical.empty else pd.Timestamp(critical["Timestamp"].max())
        state = well_state(well, alerts, frequent, pivot, thresholds, now, cfg)
        metrics = severity.get(well, {})
        battery = battery_mapping.get(normalize_name(well), "SIN BATERIA")
        state_rows.append(
            (
                str(well), str(well), battery, state, STATE_LABELS[state],
                pd.Timestamp(ordered["Fecha"].max()).to_pydatetime(),
                last_numeric(ordered, "VIBR"), last_numeric(ordered, "GPM"),
                native_number(threshold.get("VIBR_mediana")),
                native_number(threshold.get("VIBR_p25")),
                native_number(threshold.get("VIBR_p75")),
                native_number(threshold.get("Umbral_ALERTA")),
                native_number(threshold.get("Umbral_CRITICO")),
                native_number(frequency_map.get(well, {}).get("Freq_mediana_min")),
                int(threshold.get("N_registros_VIBR", 0)),
                int(len(recent_alerts)), int(frequent.get(well, {}).get("count", 0)),
                float(metrics.get("score", 0.0)),
                float(metrics.get("average_excess", 0.0)),
                float(metrics.get("maximum_excess", 0.0)),
                latest_alert.to_pydatetime() if latest_alert is not None else None,
                latest_critical.to_pydatetime() if latest_critical is not None else None,
                round(float((now - latest_alert).total_seconds() / 3600), 2)
                if latest_alert is not None else None,
                analysis_time,
            )
        )
        for peak in metrics.get("peaks", []):
            event_rows.append(
                (
                    str(well), pd.Timestamp(peak["date"]).to_pydatetime(), "PICO",
                    "VIBR PICO AISLADO", float(peak["vibration"]),
                    float(peak["threshold"]), 1, int(peak["readings"]), analysis_time,
                )
            )

    if not alerts.empty:
        for row in alerts.itertuples(index=False):
            event_rows.append(
                (
                    str(row.Pozo), pd.Timestamp(row.Timestamp).to_pydatetime(), str(row.Nivel),
                    str(row.Tipo), float(row.VIBR_valor), float(row.VIBR_umbral),
                    0, None, analysis_time,
                )
            )
    return state_rows, event_rows


def persist_results(
    connection: pyodbc.Connection,
    state_rows: List[Tuple[Any, ...]],
    event_rows: List[Tuple[Any, ...]],
    analysis_start: datetime,
    retention_days: int,
    log_days: int,
    batch_size: int,
) -> None:
    state_sql = (
        "INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_ESTADO "
        "(POZO,NOMBRE,BATERIA,ESTADO,ESTADO_ETIQUETA,FECHA_ULTIMO_DATO,VIBR_ACTUAL,GPM_ACTUAL,"
        "VIBR_MEDIANA,VIBR_P25,VIBR_P75,UMBRAL_ALERTA,UMBRAL_CRITICO,FRECUENCIA_MEDIANA_MIN,"
        "N_REGISTROS,ALERTAS_48H,EXCESOS_48H,SCORE_SEVERIDAD,EXCESO_PROMEDIO_PCT,"
        "EXCESO_MAXIMO_PCT,ULTIMA_ALERTA,ULTIMA_CRITICA,HORAS_ULTIMA_ALERTA,FECHA_ANALISIS) "
        "VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    )
    event_sql = (
        "INSERT INTO dbo.CLEAR_TECSS_VIBRACIONES_EVENTO "
        "(POZO,FECHA_EVENTO,NIVEL,TIPO,VIBR_VALOR,VIBR_UMBRAL,ES_PICO,N_LECTURAS,FECHA_ANALISIS) "
        "VALUES (?,?,?,?,?,?,?,?,?)"
    )
    cursor = connection.cursor()
    try:
        cursor.execute("DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_ESTADO")
        cursor.fast_executemany = True
        for offset in range(0, len(state_rows), batch_size):
            cursor.executemany(state_sql, state_rows[offset: offset + batch_size])
        cursor.execute(
            "DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_EVENTO WHERE FECHA_EVENTO>=?",
            analysis_start,
        )
        for offset in range(0, len(event_rows), batch_size):
            cursor.executemany(event_sql, event_rows[offset: offset + batch_size])
        event_cutoff = datetime.now() - timedelta(days=retention_days)
        cursor.execute(
            "DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_EVENTO WHERE FECHA_EVENTO<?",
            event_cutoff,
        )
        log_cutoff = datetime.now() - timedelta(days=log_days)
        cursor.execute(
            "DELETE FROM dbo.CLEAR_TECSS_VIBRACIONES_EJECUCION "
            "WHERE FECHA_INICIO<? AND ESTADO<>'EN_PROCESO'",
            log_cutoff,
        )
        connection.commit()
    except Exception:
        connection.rollback()
        raise


def run_analysis(cfg: Dict[str, Any]) -> Dict[str, int]:
    analysis_cfg = cfg["analysis"]
    runtime_cfg = cfg["runtime"]
    destination = make_connection(cfg["destination"])
    run_id: Optional[int] = None
    source_rows = cache_rows = wells = events = 0
    try:
        validate_destination(destination)
        acquire_run_lock(destination)
        run_id = start_run(destination, cfg)
        latest = latest_cached_date(destination)
        if latest:
            since = latest - timedelta(hours=float(analysis_cfg["overlap_hours"]))
        else:
            since = datetime.now() - timedelta(days=int(analysis_cfg["history_days"]))
        until = datetime.now() + timedelta(minutes=1)
        source = make_connection(cfg["source"])
        try:
            view_name = str(cfg["source"].get("view", "dbo.vwDatosEyesOnVibracionGpm"))
            columns = discover_source_columns(source, view_name)
            source_data = extract_source(source, view_name, columns, since, until)
        finally:
            source.close()
        source_rows = int(len(source_data))
        pivot_new = normalize_and_pivot(source_data)
        replace_cache_window(destination, pivot_new, int(runtime_cfg.get("batch_size", 1000)))
        prune_cache(destination, int(analysis_cfg["retention_days"]))
        pivot = load_analysis_cache(destination, int(analysis_cfg["history_days"]))
        cache_rows = int(len(pivot))
        if pivot.empty:
            raise RuntimeError("No hay datos VIBR/GPM disponibles para analizar.")
        frequency, global_frequency, alert_periods, critical_periods = analyze_frequency(
            pivot, analysis_cfg
        )
        logging.info(
            "Frecuencia global %.2f min; alerta %s periodos; critico %s periodos.",
            global_frequency, alert_periods, critical_periods,
        )
        thresholds = calculate_thresholds(pivot, analysis_cfg)
        alerts = detect_sustained_alerts(
            pivot, thresholds, alert_periods, critical_periods, analysis_cfg
        )
        frequent = detect_frequent_excesses(pivot, thresholds, analysis_cfg)
        severity = calculate_severity(pivot, thresholds, analysis_cfg)
        battery_mapping = load_battery_mapping(destination, cfg)
        state_rows, event_rows = build_results(
            pivot, thresholds, frequency, alerts, frequent, severity, battery_mapping, analysis_cfg
        )
        persist_results(
            destination,
            state_rows,
            event_rows,
            pd.Timestamp(pivot["Fecha"].min()).to_pydatetime(),
            int(analysis_cfg["retention_days"]),
            int(runtime_cfg.get("log_days", 30)),
            int(runtime_cfg.get("batch_size", 1000)),
        )
        wells = len(state_rows)
        events = len(event_rows)
        message = f"Analisis completado: {wells} pozos y {events} eventos."
        finish_run(destination, run_id, "OK", message, source_rows, cache_rows, wells, events)
        logging.info(message)
        return {
            "source_rows": source_rows,
            "cache_rows": cache_rows,
            "wells": wells,
            "events": events,
        }
    except Exception as error:
        try:
            destination.rollback()
        except Exception:
            pass
        if run_id is not None:
            try:
                finish_run(
                    destination, run_id, "ERROR", str(error), source_rows, cache_rows, wells, events
                )
            except Exception:
                logging.exception("No se pudo registrar el error en la tabla de ejecuciones.")
        raise
    finally:
        destination.close()


def check_connections(cfg: Dict[str, Any]) -> None:
    destination = make_connection(cfg["destination"])
    try:
        validate_destination(destination)
        destination.cursor().execute("SELECT 1").fetchone()
        mapping = load_battery_mapping(destination, cfg)
        logging.info("Destino OK. Mapeos de bateria disponibles: %s", len(mapping))
    finally:
        destination.close()
    source = make_connection(cfg["source"])
    try:
        view_name = str(cfg["source"].get("view", "dbo.vwDatosEyesOnVibracionGpm"))
        columns = discover_source_columns(source, view_name)
        logging.info("Origen OK. Columnas detectadas: %s", columns)
    finally:
        source.close()


def self_test() -> None:
    cfg = {
        "alert_multiplier": 2.5,
        "critical_multiplier": 3.5,
        "cooldown_hours": 12,
        "zero_threshold": 1.0,
        "zero_last_readings": 5,
        "alert_sustained_minutes": 60,
        "critical_sustained_minutes": 120,
        "frequent_excess_count": 10,
        "recent_window_hours": 48,
        "active_state_hours": 6,
        "clean_hours": 10,
    }
    dates = pd.date_range(end=pd.Timestamp.now().floor("min"), periods=300, freq="10min")
    frames = []
    for well in ("TECSS-PRUEBA-NORMAL", "TECSS-PRUEBA-CRITICO"):
        vibration = np.full(len(dates), 10.0)
        if well.endswith("CRITICO"):
            vibration[-15:] = 50.0
        frames.append(
            pd.DataFrame({"Pozo": well, "Fecha": dates, "VIBR": vibration, "GPM": 5.0})
        )
    pivot = pd.concat(frames, ignore_index=True)
    frequency, _, alert_periods, critical_periods = analyze_frequency(pivot, cfg)
    thresholds = calculate_thresholds(pivot, cfg)
    alerts = detect_sustained_alerts(pivot, thresholds, alert_periods, critical_periods, cfg)
    frequent = detect_frequent_excesses(pivot, thresholds, cfg)
    severity = calculate_severity(pivot, thresholds, cfg)
    state_rows, event_rows = build_results(
        pivot, thresholds, frequency, alerts, frequent, severity, {}, cfg
    )
    states = {row[0]: row[3] for row in state_rows}
    if states.get("TECSS-PRUEBA-NORMAL") != "normal":
        raise AssertionError(f"Estado normal inesperado: {states}")
    if states.get("TECSS-PRUEBA-CRITICO") != "critico":
        raise AssertionError(f"Estado critico inesperado: {states}")
    logging.info("Autoprueba OK: %s estados, %s eventos.", len(state_rows), len(event_rows))


def parse_args() -> argparse.Namespace:
    parser = argparse.ArgumentParser(description="Analisis de Vibraciones TECSS para CLEAR")
    parser.add_argument("--config", default=str(DEFAULT_CONFIG), help="Ruta al archivo config.json")
    parser.add_argument("--check", action="store_true", help="Verifica conexiones y objetos SQL")
    parser.add_argument("--self-test", action="store_true", help="Ejecuta una prueba sintetica sin SQL")
    return parser.parse_args()


def main() -> int:
    configure_logging()
    args = parse_args()
    try:
        if args.self_test:
            self_test()
            return 0
        cfg = load_config(Path(args.config).resolve())
        if args.check:
            check_connections(cfg)
        else:
            result = run_analysis(cfg)
            logging.info("Resultado: %s", result)
        return 0
    except Exception as error:
        logging.error("ERROR: %s", error)
        logging.debug(traceback.format_exc())
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
