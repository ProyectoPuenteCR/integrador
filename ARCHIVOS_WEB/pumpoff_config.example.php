<?php
/* Copiar como pumpoff_config.php y completar con datos confirmados de operación.
 * El archivo local no se sobrescribe al actualizar la pantalla.
 * Sin ese archivo, se muestra el diseño con el alcance pendiente de confirmar.
 */
return [
    // pending | all_bm (solo si se confirma que TODOS tienen PUMP OFF) | list
    'scope'=>'pending',
    // Para list: POZO exacto => ['tag'=>'TAG real', 'jefe_produccion'=>'Nombre'].
    'wells'=>[],
    // No equiparar estos cargos sin confirmación.
    'use_zone_chief_as_production_chief'=>false,
    'zones'=>[
        'LHCG'=>['LHCG','LH Zona LHCG'],
        'CED I'=>['CED I','CED Zona I'],
        'CED II'=>['CED II','CED Zona II'],
    ],
    // Coincidencias textuales explícitas. Telecontrol y FONDO NO se traducen.
    // Una regla puede combinar YT:LLAVE, YT:CONTROL, YT:LLAVE-AUTO y YT:DEVICE.
    'rules'=>[
        ['when'=>['YT:LLAVE'=>'MANUAL'],'state'=>'MANUAL'],
        ['when'=>['YT:LLAVE'=>'AUTOMATICO'],'state'=>'AUTOMATICO'],
        ['when'=>['YT:LLAVE'=>'HOA'],'state'=>'HOA'],
    ],
];
