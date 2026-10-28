# Eventos 3Sigma PI en Vibraciones CLEAR

La pantalla muestra dos análisis independientes: el estadístico existente y una copia de los Event Frames `3Sigma_Superado` de PI. No modifica las condiciones PI, el correo ni el cálculo Python de CLEAR. Los valores de vibración y umbral PI se leen del elemento al inicio del evento y se multiplican por 1000 una sola vez.

## Preparación y validación en el servidor

1. Revisar el diff y respaldar los archivos del sitio. Ejecutar manualmente `SQL/CLEAR_TECSS_VIBRACIONES_PI.sql` en `LC_MDB`. Sólo crea dos tablas e índice si faltan; no ejecuta capturas ni altera tablas existentes.
2. Desplegar los PHP y los archivos JS/CSS modificados. Hasta la primera sincronización correcta se muestra **Sin sincronizar PI**, conservando el análisis estadístico. El sitio sólo hace SELECT sobre las tablas PI nuevas. El usuario del proceso CLI necesita SELECT sobre el catálogo de pozos y SELECT/INSERT/UPDATE/DELETE sobre las dos tablas nuevas.
3. Copiar `tools/tecss_pi_sync.clear.example.json` a una ruta **fuera de la carpeta pública y fuera de Git** para reutilizar la conexión PI que funciona en CLEAR (`connection: clear`). Lee por SELECT la base y credenciales existentes de `CLEAR_CONFIG`, con el mismo respaldo `pi.auth` de `config.php`; no crea la tabla ni cambia sus valores. No hace falta copiar contraseñas ni crear `CLEAR_PI_AUTH`. Confirmar la base AF, la plantilla y el certificado CA que valida PI Web API. El importador conserva la validación TLS: si la conexión web antigua no tiene CA configurada, completar `ca_bundle` con un certificado de confianza que valide el servidor; no desactivar TLS. Si la CA ya está en CLEAR, puede omitirse del JSON para reutilizarla. No agregar una base distinta al JSON: se rechaza para no enviar la credencial de CLEAR a otro destino. Como alternativa sigue disponible `tools/tecss_pi_sync.example.json`, con autenticación independiente en la variable de entorno indicada; no incluirla en archivos versionados ni en argumentos del programador.
4. Usar el PHP CLI instalado en el servidor, con su configuración SQL y extensiones. El script reutiliza `config.php` para SQL y, al seleccionar `connection: clear`, lee la conexión PI existente sin modificarla. Ejecutar primero, **sin `--apply`**:

   ```text
   php tools/tecss_pi_sync.php --config=RUTA_EXTERNA.json --well=LH-945
   ```

   Es una lectura de PI y SQL sin escrituras. `--well` limita la validación a un pozo y no puede combinarse con `--apply`. La salida contiene los eventos y permite validar mapeo, horarios, calidad y escala. Los datos de salida son operativos; no publicarlos. Debe aparecer LH-945 con inicio 26/10/2026 03:46:00.192, fin 07:16:15.142, vibración inicial 114 y umbral 67,215, si el histórico no fue corregido desde la revisión. Confirmar también la versión PI Web API: base AF por ruta, Event Frames con búsqueda `Overlapped`, paginación, elementos referenciados, atributos y valores por fecha. La disponibilidad de anotaciones depende de versión y permisos. Repetir sin `--well` para validar el catálogo completo antes de publicar.
5. Revisar el resultado y entonces publicar la copia local con el comando **sin `--well`** agregando `--apply`. Esto sólo modifica las tablas PI nuevas en SQL; todas las llamadas a PI son GET. La publicación es transaccional, con bloqueo para impedir dos importaciones simultáneas. Si falla una consulta obligatoria, no reemplaza la última copia buena y marca el último intento como fallido. Nunca publica una consulta truncada. Una correspondencia ambigua de pozo detiene la publicación; no se emparejan pozos sólo por su número.
6. Comprobar LH-945 en la grilla, detalle, gráfico, Excel y reporte. Deben coexistir el evento PI y el estado estadístico CLEAR normal, con límites 415 y 581. Cambiar el rango para incluir un evento iniciado antes del rango pero cerrado dentro. Confirmar horas de Argentina y escala ×1000. Probar permisos y fallo de PI: la copia anterior debe quedar visible como pendiente de actualización.
7. Tras validar duración/carga de la lectura, configurar una tarea independiente del programador de Windows que ejecute el comando con `--apply`, inicialmente cada 5 minutos y sin superponer instancias. No se crea ni habilita ninguna tarea automáticamente. La vista marca pendiente de actualización si la cobertura tiene más de 30 minutos o al fallar un intento. La primera ejecución lee 90 días (configurable entre 2 y 120). Las siguientes leen desde la cobertura anterior menos 6 horas y vuelven a consultar individualmente los abiertos antiguos, incluso si ya cerraron. Si PI confirma 404 para uno de esos abiertos, se retira de la copia. Programar una reconciliación completa periódica, por ejemplo semanal, con `--full --apply`, para incorporar correcciones/borrados de eventos cerrados anteriores a las 6 horas. Medir la carga antes de activar tareas. No usar el job de captura histórica de 4 horas para esta sincronización.

## Interpretación

### Instalación autorizada en producción el 26/10/2026

Se instaló una copia independiente en `C:\CLEAR\PiSync`: PHP CLI 8.3, `app/` con el importador, `connection.json`, `pi-ca.pem`, `php.ini`, `run.php` y `logs/`. `app/config.php` reutiliza la configuración SQL del sitio por referencia, sin copiar contraseñas. La conexión PI se lee del sitio; el certificado y el nombre TLS se validan sólo para este proceso. No se reemplazó el PHP de IIS. El lanzador versionado `tools/tecss_pi_sync_runner.php` se instala como `run.php` en esa carpeta; acepta únicamente `validate` y `publish`, sólo desde CLI, y guarda salida, errores y código de terminación en `logs/`.

La tarea `CLEAR - PI TECSS sincronizacion` ejecuta el lanzador como SYSTEM cada 5 minutos, con límite de 30 minutos e ignorando intentos superpuestos. Tras validar LH-945 en el servidor, la primera publicación terminó correctamente a las 17:44:36; la ejecución automática de las 17:51:27 terminó a las 17:51:33 con código 0. Se comprobó una fila de sincronización y 26 eventos retenidos en SQL. La retención autorizada para el arranque es de **2 días**, no 90: un rango anterior muestra advertencia de cobertura incompleta. El job histórico de 4 horas sigue siendo independiente.

La web PHP y API ya estaban publicadas, pero los archivos JS/CSS del servidor eran anteriores y dejaban vacío el panel PI del detalle. Con autorización adicional se publicaron `assets/js/tecss_vibraciones.js`, `assets/css/tecss_vibraciones.css` y la página con versión de recursos `20261026-pi-2`. Los originales quedaron en `C:\CLEAR\PiSync\backup-web-assets`. Se verificó en la grilla, el detalle y el gráfico real que LH-945 muestra sus cuatro eventos PI junto al estado estadístico CLEAR Normal. El evento del 26/10 conserva escala ×1000 y envío registrado a las 06:46.

Las siguientes actualizaciones del importador deben desplegarse también en `C:\CLEAR\PiSync\app`; el despliegue habitual del sitio no cambia esa copia aislada. Para revertir el proceso, deshabilitar exclusivamente la tarea nueva y conservar las tablas para diagnóstico. Los recursos visuales se pueden restaurar desde el respaldo. No se cambiaron condiciones PI ni plantillas de correo. Las exportaciones y el reporte no se ejercitaron en producción durante este cierre; sí se ejecutaron las pruebas locales del lector y del navegador.

Con autorización adicional se corrigió el colapso del gráfico causado por el personalizador global: al seleccionar Líneas, Barras o Dona reemplazaba el eje temporal y eliminaba el eje GPM. El canvas de vibraciones declara `data-clear-chart-local="true"`, preservando sus ejes y colores semánticos. Se reprodujo el fallo antes del cambio y la prueba pasó después con los tres tipos de preferencia guardada. Respaldo previo: `backup-web-assets/tecss_vibraciones.before-chart-fix.php`.

Después se publicó el diseño solicitado a pantalla completa: gráfico principal a la izquierda, eventos PI en un panel lateral con desplazamiento independiente e indicadores compactos; en móvil, disposición vertical. Sólo cambiaron la página y su CSS, con versión CSS `20261026-pi-3`. Respaldo de esta etapa: `backup-web-assets/before-fullscreen`. Las tres pruebas de navegador pasaron, incluyendo distribución sin desbordes a 1920×1080, 1366×768 y 390×844. Se verificó también la página real con LH-945 y el rango 25–26/10. Las capturas de producción confirman ambos análisis y la sincronización automática posterior a la carga inicial.

### Certificado y PHP comprobados el 26/10/2026

La lectura real de LH-945 recuperó cuatro eventos que se solapan con el 25 y 26 de octubre, incluido el iniciado el 26/10 a las 03:46:00.192 y cerrado a las 07:16:15.142, con envío registrado a las 06:46:21.486, vibración inicial 114 y umbral 67,21459329128265. No se publicaron filas SQL durante esta comprobación.

El usuario confirmó estas correspondencias de identidad el 26/10/2026: `CG-457` → `CG-457[00]`, `CG-544` → `CG-544[00]`, `CG-715` → `CG-715[00]` y `CnE-320` → `CnE-320[00]`. Se declaran individualmente en `well_aliases` del ejemplo de conexión CLEAR. El importador no elimina sufijos de forma general: exige destino exacto presente en el catálogo y rechaza reemplazar la identidad de otro pozo. Revisar esos alias antes de utilizar el ejemplo en otra instalación. La primera validación del catálogo completo se detuvo por esos nombres, sin publicar datos.

El certificado HTTPS es autofirmado, identifica `SWPCRDLHSCD51` y no la IP de la URL. Su huella SHA-1 es `1F07AE078C22EBBAB3213C0EED4A20EBF5263A17`; se comprobó que coincide con la vinculación HTTPS `10.17.40.37:443` leída del registro del servidor. Se repitió la consulta verificando cadena y nombre con su certificado público como CA aislada, sin instalar confianza en Windows. Para ese caso usar `tls_server_name: SWPCRDLHSCD51` y un `ca_bundle` externo. El transporte del importador conecta a la IP de la base existente usando el nombre del certificado; requiere cURL. No altera el transporte ni la configuración de la web y no desactiva TLS. Si se renueva el certificado, volver a comprobar su identidad antes de reemplazar el archivo público de confianza.

`C:\php\php.exe` tiene tamaño 0 bytes; no sirve para ejecutar la tarea CLI. `C:\php v5\php.exe` es 5.4.12 y no es compatible con este importador. Preparar un PHP CLI compatible (7.4 o posterior, preferentemente una versión mantenida) con cURL y el controlador SQL usado por CLEAR, de forma independiente del PHP de IIS. No reemplazar ejecutables de producción sin respaldo y autorización. La prueba desde esta estación con PHP 8.3 y COM accedió a SQL por lectura, pero no acredita que el CLI del servidor esté listo.

- Los indicadores y filtros de estado de la grilla conservan el criterio estadístico CLEAR. Las columnas **3Sigma PI** y **Último evento PI** muestran el criterio PI aparte. El estado abierto corresponde a la última sincronización, no a una lectura en tiempo real.
- El detalle muestra eventos que se solapan con el período solicitado, incluidos abiertos. Hasta 1000 eventos; si hay más, advierte que hay que acortar el rango. Si el rango excede la cobertura o faltan datos, no afirma ausencia de eventos.
- La franja violeta marca el intervalo del evento y la línea violeta su umbral **al inicio**. No se inventa una serie histórica del umbral ni se extiende ese valor fuera del evento. El gráfico usa un eje temporal real y no oculta el evento PI cuando CLEAR está normal.
- El registro de envío se muestra sólo si se reconoce una anotación del servicio `PINotificationsService` con texto `Notification sent to N` y fecha válida. No equivale a recepción del correo. Un formato diferente, falta de permisos o ausencia de anotaciones se muestra como evidencia no disponible, nunca como “correo no enviado”. Confirmar el formato real en la validación del servidor; no inferir el envío a partir de sumar 3 horas.
- No se corrigió el uso del umbral crítico para la detección estadística de alertas sostenidas: requiere revisar el criterio por separado y no es necesario para mostrar ambos análisis.

## Verificación local

```text
php -n tests/tecss_pi_reader_test.php
php -n tests/tecss_pi_connection_test.php
node --test tests/tecss_vibraciones_pi.test.cjs
```

El test de navegador requiere Playwright y Chrome. Usa respuestas simuladas, sin PI ni SQL, y comprueba valores, horas desde otro huso, ambos análisis, ausencia de sincronización, datos viejos, cobertura incompleta y anotaciones no disponibles. Estas pruebas no reemplazan la lectura de validación del paso 4 ni una prueba de publicación SQL en un entorno de ensayo.

## Reversión

Deshabilitar la tarea nueva y restaurar los archivos anteriores del sitio. Las tablas PI son independientes y pueden conservarse para diagnóstico; no es necesario borrarlas. No tocar los jobs previos, el análisis Python ni las reglas de PI.
