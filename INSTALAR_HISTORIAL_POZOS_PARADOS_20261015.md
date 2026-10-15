# CLEAR — Instalación Historial de Pozos Parados (15/10/2026)

## Alcance
Se implementó **en la misma URL** \`monitoreo_pozos.php\`, con dos pestañas:
- **Monitoreo actual:** conserva su grilla, funciones, alarmas y filtros ocultos mediante el botón Filtros.
- **Historial de pozos parados:** muestra exclusivamente **eventos** de paro y recuperación detectados a partir de \`CLEAR_POZOS_PARADOS_CACHE\`, con calendario Desde/Hasta, búsqueda, filtros por sistema/batería, paginación SQL de 100 registros, KPIs y configuración.

No se agregan consultas RTQP desde el navegador. El job **CLEAR - Reporte Pozos Parados** conserva su frecuencia de 10 minutos y su cálculo de pérdidas 24 h. El historial existente \`CLEAR_POZOS_PARADOS_HIST\` **no se toca**, porque sigue siendo usado por el cálculo actual.

## Instalación
1. Realizar respaldo de \`LC_MDB\` y desplegar los archivos nuevos/modificados mediante \`git pull\` desde \`main\`.
2. Desde SQL Server Management Studio (cuenta con permisos de DDL en LC_MDB y de gestión de jobs en msdb), ejecutar **una sola vez**:
   \`SQL/CLEAR_HISTORIAL_POZOS_PARADOS_20261015.sql\`
3. Verificar que SQL Server Agent esté iniciado y exista el job \`CLEAR - Reporte Pozos Parados\`. El instalador agrega \`SP_CLEAR_HIST_PAROS_SINCRONIZAR\` al mismo paso SQL, después de \`SP_CLEAR_POZOS_PARADOS_REFRESCAR\` (sin crear un segundo job).
4. Si se desea forzar una primera detección sin esperar 10 minutos, ejecutar después del refresco actual:
   \`EXEC dbo.SP_CLEAR_HIST_PAROS_SINCRONIZAR;\`
5. Ir a **Telemetría de Pozos → Monitoreo Pozos → Historial de pozos parados**. Abrir Configuración historial (administradores) para establecer el plazo: 30 días predeterminado, 60, 90 o personalizado de 7 a 365 días.
6. Actualizar navegador con Ctrl + F5.

## Comprobación SQL
\`\`\`sql
USE LC_MDB;
SELECT RETENCION_DIAS,MODIFICADO_POR,FECHA_MODIFICACION
FROM dbo.CLEAR_HIST_PAROS_CONFIG WHERE ID=1;

SELECT TOP (30) SISTEMA,POZO,ESTADO_POZO,FECHA_INICIO,FECHA_FIN,
       PRODUCCION_PETROLEO,PRODUCCION_AGUA,FECHA_TEST
FROM dbo.CLEAR_HIST_PAROS_EVENTOS
ORDER BY ID DESC;

USE msdb;
SELECT J.name,S.step_id,S.database_name,S.command
FROM dbo.sysjobs J
JOIN dbo.sysjobsteps S ON S.job_id=J.job_id
WHERE J.name=N'CLEAR - Reporte Pozos Parados';
\`\`\`

## Reglas y performance
- Solo abre un evento por \`SISTEMA + POZO_CLAVE\` mientras el pozo esté parado: índice único filtrado para eventos abiertos.
- Se consideran los diagnósticos \`PARADO\`, \`PARO REAL\`, \`PARO CONTROLADO\`, \`PROBABLE PARO\`. Los diagnósticos contradictorios \`INCONSISTENCIA\` y \`VERIFICAR PARO\` no originan eventos.
- Si el paro desaparece en dos verificaciones consecutivas separadas al menos 8 minutos, se registra la **recuperación estimada**, siempre que las cachés estén actualizadas y no se detecte una caída masiva sospechosa. No es un timestamp exacto de PI.
- Si no hay caché reciente, no se crean recuperaciones. Si una desaparición completa involucra 10 o más paros abiertos, se evita cerrar masivamente por precaución; debe verificarse la salud de la fuente.
- La producción se toma al crear el evento del último test INFOIL Q164 disponible. \`AGUA = MAX(0, LÍQUIDO - PETRÓLEO)\` cuando existen ambos valores; de otro modo se muestra **Sin dato**. No es pérdida acumulada ni medición instantánea al minuto del paro.
- Cada KPI suma **una sola producción por pozo físico** (último evento del rango filtrado), aunque un pozo tenga varios paros. Las etiquetas muestran \`m³/d\`.
- La purga es indexada, por lotes de máximo 1000 eventos finalizados por ciclo, y considera **fecha de fin**: no borra eventos abiertos. Por defecto 30 días.
- No se crea ni ejecuta un SQL Server \`TRIGGER\`: el disparador funcional es la detección de nuevos paros dentro del job de 10 minutos, evitando escrituras por cada lectura RTQP.

## Archivos
**Nuevos**
- \`SQL/CLEAR_HISTORIAL_POZOS_PARADOS_20261015.sql\`
- \`includes/monitoreo_pozos_historial.php\`

**Modificados**
- \`monitoreo_pozos.php\` (selector de modo dentro de la misma URL)
- \`includes/telemetry_grid.php\` (dos pestañas solo en Monitoreo Pozos)
- \`SQL/CLEAR_REPORTE_POZOS_PARADOS.sql\` (conserva el enganche del historial si se reinstala en el futuro)

## Limitaciones importantes
- No genera históricos anteriores a la instalación. Los eventos nuevos aparecen cuando el job los detecta.
- No ejecutar nuevamente \`SQL/CLEAR_REPORTE_POZOS_PARADOS.sql\` solamente para activar este cambio: ese script configura/recrea el job original. **El nuevo instalador es suficiente** si el job ya existe.
- Si los nombres de pozo de RTQP y Q164 son distintos, las producciones podrían mostrarse como \`Sin dato\`. Revisar la correspondencia de códigos en las primeras detecciones reales.
- Sin acceso a la instancia productiva desde GitHub, las consultas y el procedimiento deben validarse en el servidor al desplegar.
