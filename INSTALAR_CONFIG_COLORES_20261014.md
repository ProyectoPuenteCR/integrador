# CLEAR | Configuración de colores - 14/10/2026

## Instalación en producción
1. **Primero** ejecutar SQL/CLEAR_CONFIG_COLORES_20261014.sql en SQL Server, base LC_MDB.
2. Hacer Pull de la rama main en el servidor web de CLEAR.
3. Ingresar como Administrador > Colores y estados (config_colores.php).
4. Ajustar un color, guardar, recargar monitoreo_pozos.php y verificar el resultado.
5. Verificar telemetria_pcp.php, telemetria_bes.php y telemetria_tecss.php.

## Seguridad y alcance
- Solo Administradores pueden modificar la paleta global; control CSRF y verificación del formato hexadecimal.
- Se registra histórico en dbo.CLEAR_CONFIG_COLORES_LOG, incluyendo usuario y colores anteriores y nuevos.
- La personalización es global, almacenada en LC_MDB.
- Sin la migración SQL, la pantalla avisa y las grillas mantienen sus colores actuales.
- Se aplica a las grillas que usan includes/telemetry_grid.php: paro remoto, COM, estado, Zafiro, YT:POZO/estado por RPM, alarmas y filas con falla de comunicación.
- No cambia consultas, algoritmos, filtros, paros reales ni alarmas; solo aspecto visual.

## Límite de esta etapa
- Otras pantallas que NO usan includes/telemetry_grid.php todavía mantienen sus propios colores.
- Estados no incluidos en el catálogo conservan el color anterior.
- La selección de filas no se modifica.

## Volver atrás
- Revertir los commits PHP recupera la presentación anterior.
- No borrar tablas SQL de auditoría; son aditivas e inocuas para la versión anterior.
