<?php
$TELEMETRY = [
    'key'=>'monitoreo_pozos','title'=>'Monitoreo Pozos','subtitle'=>'Monitoreo de bombeo mecánico desde BM_RTQP','table'=>'BM_RTQP','order'=>'POZO',
    'columns'=>['POZO','BATERIA','ESTADO','Fecha','ECO','ESTADO-GRAL','PT:LINEA','YAT:COM','PANTALLA','CARTAS','ET:VARIADOR','FP','FT:AGUA_ACU','FT:AGUA_CIERRE','FT:FONDO-LLENADO.PV','FT:LLENADO.SP','FT:LLENADO-ACTUAL','FT:OIL_ACU','FT:OIL_CIERRE','FT:PROD_ACU','FT:PROD-PROY','FT:PROY-AGUA','FT:PROY-OIL','WT:SUP_MAX','WT:SPMIN','YT:CONTROL','YT:DEVICE','YT:LLAVE','YT:LLAVE-AUTO','ZT:CARRERA-MIN','ZT:CARRERA-ACTUAL','ZT:FONDO-POS','ZT:PUMPOFF','ZT:SUP-CARRERA','PT:CABEZA'],
    'communication_column'=>'YAT:COM','communication_date_column'=>'Fecha','state_column'=>'ESTADO','general_column'=>'ESTADO-GRAL','well_column'=>'POZO','battery_column'=>'BATERIA',
    'production_q164'=>true,
    'remote_stop'=>true,
    'remote_stop_after'=>'ESTADO',
    'force_columns'=>['PT:LINEA'],
    'display_order'=>['POZO','BATERIA','ALM','__COMUNICACION','ESTADO','ESTADO-GRAL','PT:LINEA','PANTALLA','CARTAS'],
    'column_state_version'=>'340',
    'labels'=>['Fecha'=>'ÚLTIMA ACTUALIZACIÓN','PT:LINEA'=>'PRESIÓN LÍNEA','PRODUCCION_PETROLEO'=>'PRODUCCIÓN PETRÓLEO','PRODUCCION_LIQUIDO'=>'PRODUCCIÓN LÍQUIDO','PRODUCCION_GAS'=>'PRODUCCIÓN GAS'],
    'non_numeric'=>['POZO','BATERIA','ESTADO','Fecha','ECO','ESTADO-GRAL','YAT:COM','PANTALLA','CARTAS','YT:CONTROL','YT:DEVICE','YT:LLAVE','YT:LLAVE-AUTO']
];
require __DIR__.'/includes/telemetry_grid.php';
