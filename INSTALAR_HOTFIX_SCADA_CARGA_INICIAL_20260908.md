# CLEAR — Hotfix de carga inicial de SCADA Real time

Este parche corrige el error HTTP 500 que dejaba la pantalla detenida en
`Cargando configuración de SCADA Real time...`.

## Instalación

1. Copiar la carpeta `clear` del ZIP sobre la instalación actual de CLEAR.
2. Reemplazar los dos archivos cuando Windows lo solicite.
3. No ejecutar ningún SQL.
4. Abrir `SCADA Real time` y actualizar con `Ctrl + F5`.

## Cambio aplicado

- La carga inicial ya no intenta devolver los más de 20.000 TAGs.
- El combo TAG se carga bajo demanda para la batería seleccionada.
- La grilla y el combo se solicitan de forma independiente.
- Si PHP produce un error interno, el API devuelve el detalle legible en JSON.
- No se modifican la fuente SCADA, `PI_Points_Stage`, `dbo.FIXALARMS`, las
  cachés ni los Jobs.

## Resultado esperado

La pantalla debe mostrar primero las baterías y los valores actuales. El combo
TAG indicará brevemente `Cargando TAGs...` y luego mostrará solamente los TAGs
de la batería activa.
