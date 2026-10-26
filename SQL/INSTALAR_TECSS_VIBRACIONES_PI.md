# Eventos 3Sigma PI en Vibraciones CLEAR

La pantalla muestra dos análisis independientes: el estadístico existente y una copia de los Event Frames `3Sigma_Superado` de PI. No modifica las condiciones PI, el correo ni el cálculo Python de CLEAR. Los valores de vibración y umbral PI se leen del elemento al inicio del evento y se multiplican por 1000 una sola vez.

## Preparación y validación en el servidor (pendiente)

1. Revisar el diff y respaldar los archivos del sitio. Ejecutar manualmente `SQL/CLEAR_TECSS_VIBRACIONES_PI.sql` en `LC_MDB`. Sólo crea dos tablas e índice si faltan; no ejecuta capturas ni altera tablas existentes.
2. Desplegar los PHP y los archivos JS/CSS modificados. Hasta la primera sincronización correcta se muestra **Sin sincronizar PI**, conservando el análisis estadístico. El sitio sólo hace SELECT sobre las tablas PI nuevas. El usuario del proceso CLI necesita SELECT sobre el catálogo de pozos y SELECT/INSERT/UPDATE/DELETE sobre las dos tablas nuevas.
3. Copiar `tools/tecss_pi_sync.example.json` a una ruta **fuera de la carpeta pública y fuera de Git**. Confirmar en el servidor la URL, la base AF, la plantilla y el certificado CA que valida PI Web API. No desactivar validación TLS. Proporcionar el encabezado de autenticación en la variable de entorno indicada, mediante el mecanismo existente del administrador; no incluirlo en archivos versionados ni en argumentos del programador.
4. Usar el PHP CLI instalado en el servidor, con su configuración SQL y extensiones. El script reutiliza `config.php` para SQL; no consulta ni cambia `CLEAR_CONFIG`. Ejecutar primero, **sin `--apply`**:

   ```text
   php tools/tecss_pi_sync.php --config=RUTA_EXTERNA.json --well=LH-945
   ```

   Es una lectura de PI y SQL sin escrituras. `--well` limita la validación a un pozo y no puede combinarse con `--apply`. La salida contiene los eventos y permite validar mapeo, horarios, calidad y escala. Los datos de salida son operativos; no publicarlos. Debe aparecer LH-945 con inicio 26/10/2026 03:46:00.192, fin 07:16:15.142, vibración inicial 114 y umbral 67,215, si el histórico no fue corregido desde la revisión. Confirmar también la versión PI Web API: base AF por ruta, Event Frames con búsqueda `Overlapped`, paginación, elementos referenciados, atributos y valores por fecha. La disponibilidad de anotaciones depende de versión y permisos. Repetir sin `--well` para validar el catálogo completo antes de publicar.
5. Revisar el resultado y entonces publicar la copia local con el comando **sin `--well`** agregando `--apply`. Esto sólo modifica las tablas PI nuevas en SQL; todas las llamadas a PI son GET. La publicación es transaccional, con bloqueo para impedir dos importaciones simultáneas. Si falla una consulta obligatoria, no reemplaza la última copia buena y marca el último intento como fallido. Nunca publica una consulta truncada. Una correspondencia ambigua de pozo detiene la publicación; no se emparejan pozos sólo por su número.
6. Comprobar LH-945 en la grilla, detalle, gráfico, Excel y reporte. Deben coexistir el evento PI y el estado estadístico CLEAR normal, con límites 415 y 581. Cambiar el rango para incluir un evento iniciado antes del rango pero cerrado dentro. Confirmar horas de Argentina y escala ×1000. Probar permisos y fallo de PI: la copia anterior debe quedar visible como pendiente de actualización.
7. Tras validar duración/carga de la lectura, configurar una tarea independiente del programador de Windows que ejecute el comando con `--apply`, inicialmente cada 5 minutos y sin superponer instancias. No se crea ni habilita ninguna tarea automáticamente. La vista marca pendiente de actualización si la cobertura tiene más de 30 minutos o al fallar un intento. La primera ejecución lee 90 días (configurable entre 2 y 120). Las siguientes leen desde la cobertura anterior menos 6 horas y vuelven a consultar individualmente los abiertos antiguos, incluso si ya cerraron. Si PI confirma 404 para uno de esos abiertos, se retira de la copia. Programar una reconciliación completa periódica, por ejemplo semanal, con `--full --apply`, para incorporar correcciones/borrados de eventos cerrados anteriores a las 6 horas. Medir la carga antes de activar tareas. No usar el job de captura histórica de 4 horas para esta sincronización.

## Interpretación

- Los indicadores y filtros de estado de la grilla conservan el criterio estadístico CLEAR. Las columnas **3Sigma PI** y **Último evento PI** muestran el criterio PI aparte. El estado abierto corresponde a la última sincronización, no a una lectura en tiempo real.
- El detalle muestra eventos que se solapan con el período solicitado, incluidos abiertos. Hasta 1000 eventos; si hay más, advierte que hay que acortar el rango. Si el rango excede la cobertura o faltan datos, no afirma ausencia de eventos.
- La franja violeta marca el intervalo del evento y la línea violeta su umbral **al inicio**. No se inventa una serie histórica del umbral ni se extiende ese valor fuera del evento. El gráfico usa un eje temporal real y no oculta el evento PI cuando CLEAR está normal.
- El registro de envío se muestra sólo si se reconoce una anotación del servicio `PINotificationsService` con texto `Notification sent to N` y fecha válida. No equivale a recepción del correo. Un formato diferente, falta de permisos o ausencia de anotaciones se muestra como evidencia no disponible, nunca como “correo no enviado”. Confirmar el formato real en la validación del servidor; no inferir el envío a partir de sumar 3 horas.
- No se corrigió el uso del umbral crítico para la detección estadística de alertas sostenidas: requiere revisar el criterio por separado y no es necesario para mostrar ambos análisis.

## Verificación local

```text
php -n tests/tecss_pi_reader_test.php
node --test tests/tecss_vibraciones_pi.test.cjs
```

El test de navegador requiere Playwright y Chrome. Usa respuestas simuladas, sin PI ni SQL, y comprueba valores, horas desde otro huso, ambos análisis, ausencia de sincronización, datos viejos, cobertura incompleta y anotaciones no disponibles. Estas pruebas no reemplazan la lectura de validación del paso 4 ni una prueba de publicación SQL en un entorno de ensayo.

## Reversión

Deshabilitar la tarea nueva y restaurar los archivos anteriores del sitio. Las tablas PI son independientes y pueden conservarse para diagnóstico; no es necesario borrarlas. No tocar los jobs previos, el análisis Python ni las reglas de PI.
