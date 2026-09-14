# Corrección SCADA Real time — fecha y vínculo PI

Esta actualización corrige los dos comportamientos comprobados en las capturas:

- SQL mostraba `16:45`, pero la web interpretaba el mismo `datetime` como UTC y lo mostraba `13:45`.
- El TAG SCADA `BCE008_PMP01_DEN01-A` no coincidía literalmente con el punto PI almacenado como `"LHC_BCE008_PMP01_DEN01-A"`.

## Instalación

1. Copiar las carpetas del parche sobre la instalación actual de `clear`.
2. Ejecutar solamente `SQL/CLEAR_SCADA_REALTIME_CORRECCION_FECHA_PI_20260908.sql` en `LC_MDB`.
3. Confirmar que la última grilla muestre `RESULTADO = CORRECCION APLICADA` y `STALE_SECONDS = 900`.
4. Actualizar el navegador con `Ctrl+F5`.
5. Abrir `BCE008_PMP01_DEN01-A` y seleccionar `24 h`.

La web ahora resuelve primero el `WebId` ya disponible en `dbo.PI_Points_Stage`. Reconoce el TAG exacto, el prefijo `LHC_`, otros prefijos terminados en `_TAG`, comillas y espacios invisibles. PI Web API se usa después para leer `/streams/{WebId}/recorded` o `interpolated`.

El script no modifica la tabla fuente del colector, `dbo.PI_Points_Stage` ni `dbo.FIXALARMS`.
