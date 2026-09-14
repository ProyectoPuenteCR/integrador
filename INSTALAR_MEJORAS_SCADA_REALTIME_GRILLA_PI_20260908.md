# SCADA Real time — mejoras de grilla, PI y alarmas

## Instalación

1. Copiar la carpeta `clear` sobre la instalación actual.
2. Ejecutar solamente `SQL/CLEAR_SCADA_REALTIME_MEJORAS_GRILLA_PI_20260908.sql` en `LC_MDB`.
3. Verificar `RESULTADO = CORRECTO` y revisar `TAGS_CON_WEBID`, `TAGS_SIN_WEBID` y `TAGS_CON_UNIDAD`.
4. Actualizar el navegador con `Ctrl+F5`.

## Cambios incluidos

- Sincronización de `WebId`, nombre PI y `EngineeringUnits` desde `dbo.PI_Points_Stage` hacia la tabla de relación de CLEAR.
- La sincronización ocurre inicialmente y luego cada 6 horas mediante SQL Server Agent; no forma parte del refresco web de 10 segundos.
- TAG sin `WebId` marcado con subrayado punteado rojo.
- Orden ascendente/descendente al pulsar los encabezados de la grilla.
- Selección de cualquier fila para cargar su histórico PI.
- Combo de TAG dependiente de Zona, Batería, Nodo y Área/equipo.
- Campana en Estado con cantidad de alarmas; abre el histórico SQL en una ventana emergente con comentarios.
- Cantidad de alarmas en tarjetas de batería y en el indicador general.
- La columna Fecha OPC fue retirada; la fecha máxima se muestra una sola vez como `Fecha de datos`.
- Exportación a Excel de los valores representados en el gráfico PI.

La tabla fuente SCADA, `dbo.PI_Points_Stage` y `dbo.FIXALARMS` permanecen sin modificaciones.
