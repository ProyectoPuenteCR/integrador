# Dashboard Pozos: comunicación, fallas e indicadores

## Instalación

1. Actualizar desde Git los archivos de esta modificación. No reemplazar `config.php` ni volver a instalar scripts anteriores.
2. En SQL Server Management Studio, con una cuenta autorizada para crear objetos en `LC_MDB` y jobs en `msdb`, ejecutar **`SQL/CLEAR_DASHBOARD_POZOS_20261021.sql`** una vez. El script también realiza una primera captura real.
3. Confirmar que SQL Server Agent esté iniciado y que el job **CLEAR - Dashboard Pozos e historico** termine correctamente. Se programa cada cinco minutos, separado del refresco existente de telemetría.
4. Abrir `dashboard_pozos.php` y pulsar **Actualizar**. El reporte **Pozos en falla** aparece al pie del dashboard. La tarjeta superior lleva directamente a ese bloque.

El script se puede ejecutar nuevamente: conserva los datos y utiliza sus propios objetos. No modifica la estructura ni la carga de `dbo.FIXALARMS`, las vistas RTQP, el refresco de telemetría o los jobs de otros módulos. El usuario SQL `fix`, si existe, recibe solamente lectura sobre las nuevas tablas; si IIS usa otro principal, otorgarle esos mismos permisos de lectura.

### Día operativo

El corte inicial es **00:00:00**, configurable. Es un valor inicial explícito: no había una hora de corte definida para este dashboard. Antes de acumular historia, ajustar el corte al horario utilizado por operación. Por ejemplo, para las 08:00:

```sql
USE [LC_MDB];
EXEC dbo.SP_CLEAR_DASH_POZOS_CONFIGURAR
    @HoraCorte='08:00:00',
    @RetencionDias=400,
    @MaxAntiguedadMin=30;
EXEC dbo.SP_CLEAR_DASH_POZOS_REFRESCAR;
```

Las fechas siguen la hora local del servidor SQL, igual que las cachés existentes. Debe coincidir con Argentina y con `app.tz` de la aplicación. El reporte muestra el inicio y el fin del día efectivo. Los cierres con horas de corte diferentes no se mezclan en una comparación.

## Cambios visibles

- Se retiran las tarjetas de producción de petróleo, líquido y gas, baterías activas, comunicación promedio y registros procesados.
- Se retira el gráfico Top 10 de producción y también sus datos del resumen incorporable al reporte. El dashboard deja de consultar Query 164.
- El Top de pozos sin comunicación ordena por días observados sin comunicación, de mayor a menor. La fecha de refresco nunca se toma como inicio retrospectivo de la pérdida.
- El reporte Pozos en falla aparece debajo de los gráficos y resúmenes del dashboard, con tarjetas Total, BM, BES y PCP; filtros, ordenamiento, selector de columnas, 50/100/Todas las filas, exportación CSV para Excel, impresión/PDF e incorporación al reporte común. Los filtros y la paginación permanecen en el dashboard. `pozos_falla.php` conserva la vista independiente para exportar e imprimir.
- La grilla contiene Zona, Instalación, Supervisor, Detalle de falla, Cant. de fallas acumuladas durante el día operativo y Método de extracción. Instalación identifica el pozo y puede mostrar su batería como dato adicional.
- Se agregan Semana vs semana y Mes vs mes para pozos sin comunicación, comunicando y en falla.

### Actualización de presentación: reporte integrado

Si el SQL de esta modificación ya está instalado, integrar el reporte al dashboard **no requiere volver a ejecutarlo**. Actualizar los archivos de este commit, incluyendo `includes/pozos_falla_report.php` y `assets/css/pozos_falla.css`, y recargar la página. Ambas vistas comparten el mismo reporte y sus estilos se incorporan en el HTML generado por PHP. El dashboard reutiliza la lectura de telemetría ya realizada: filtrar el bloque de fallas no modifica los indicadores generales ni agrega una segunda carga de datos. «Incluir resumen completo» incorpora también los pozos en falla filtrados de todas las páginas.

## Reglas de cálculo

**Universo:** un registro por pozo normalizado, incluso si existe en ambas fuentes. Los resúmenes SCADA/TECSS conservan sus estados por fuente y pueden compartir pozos; los indicadores generales cuentan cada pozo una sola vez. No se limita la lectura a 5.000 registros.

**Comunicación:** se toma la última muestra por pozo y fuente. Un pozo comunica si al menos una fuente vigente comunica. Los datos vencidos, futuros o desconocidos no se transforman en comunicación válida.

**Falla actual y fallas acumuladas:** la tarjeta y los KPI cuentan pozos con estado actual En falla, distinto de Parado. Las expresiones SIN FALLA, NO ERROR y SIN ALARMA no son activaciones. El reporte cuenta, por separado, los registros `ALM_VALUE IN ('FALLA','FAULT')` dentro del día operativo, respetando los filtros vigentes. Puede mostrar cero si la falla continúa desde el día anterior. Si el cálculo no está disponible, muestra **Sin dato**. Una lectura correcta acredita los eventos registrados; no certifica por sí sola la salud de la ingestión SCADA.

**Método:** proviene de Zafiro o del método explícito disponible en la caché TECSS. El origen técnico BM/TECSS no se usa para inventar un método. Los pozos sin clasificación siguen visibles.

**Antigüedad sin comunicación:** se guarda el comienzo observado y se conserva mientras continúa el estado. Si el pozo ya estaba caído al iniciar el seguimiento, aparece **≥**, indicando duración mínima conocida. Tras una interrupción prolongada de captura, el seguimiento se reinicia como estimado. Con datos vencidos, la duración se congela en la última observación.

**Comparaciones:** se comparan los promedios de las cantidades de pozos al cierre de cada día operativo. Semana vs semana usa las dos últimas semanas completas de **miércoles a martes**, como el resto de CLEAR. Mes vs mes usa los dos últimos meses calendario completos. Se excluye el período en curso. La variación muestra diferencia de pozos y porcentaje; una base anterior cero no genera un porcentaje infinito.

El histórico comienza al instalar el job. No se reconstruyen estados pasados desde la caché actual. Cada comparación muestra la cobertura y permanece pendiente hasta disponer de ambos períodos completos; los días faltantes no se sustituyen por cero.

## Rendimiento y comprobación en el servidor

La web solamente lee cachés locales. El job toma una muestra histórica cuando cambia `FECHA_CACHE`, prepara los resultados fuera de la transacción de publicación y conserva una fila de cierre por día, con retención inicial de 400 días. No guarda una copia completa de todos los pozos cada cinco minutos.

Para las ocurrencias de falla, se utiliza la caché local de alarmas si expone los campos necesarios y una fecha de carga vigente; de lo contrario se lee la vista filtrada local y, como último recurso, `FIXALARMS`. La consulta limita por el rango nativo de fecha del día operativo y usa `MAXDOP 1`. El script no crea índices sobre `FIXALARMS`. Si se utiliza ese último origen, comprobar el plan y las lecturas de la primera ejecución; el repositorio ya contempla un índice por `ALM_NATIVETIMEIN` en los scripts de optimización existentes, pero este cambio no presupone que esté instalado.

```sql
SELECT * FROM dbo.CLEAR_DASH_POZOS_ESTADO_FUENTES WHERE ID=1;
SELECT TOP (10) POZO, COMUNICACION, ESTADO, SIN_COM_DESDE,
    SIN_COM_INICIO_ESTIMADO, FECHA_CACHE
FROM dbo.CLEAR_DASH_POZOS_ACTUAL
ORDER BY POZO;
SELECT TOP (20) * FROM dbo.CLEAR_DASH_POZOS_DIARIO
ORDER BY DIA_OPERATIVO DESC;
```

Revisar que las fechas avancen con la caché de origen y que el estado de fuentes no indique errores. Probar una falla real conocida y comparar su cantidad diaria con los registros de origen. Verificar que una recuperación quite el pozo del reporte de fallas y cierre la duración sin comunicación cuando corresponda.

## Validación del cambio

Incluye pruebas PHP para consolidación, metadatos, antigüedad, períodos, cobertura y variaciones. Se ejecutan con:

```text
php tests/dashboard_pozos_data.test.php
php tests/dashboard_pozos_kpi_test.php
```

La revisión local valida PHP, el comportamiento del reporte con datos de prueba y el SQL por inspección. La conexión real con `LC_MDB`, los permisos del servidor y la ejecución del job se verifican durante la instalación; no se han ejecutado sobre el servidor productivo desde este entorno.
