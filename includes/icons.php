<?php
/* =============================================================
   CLEAR PLATAFORMA — includes/icons.php
   Iconos SVG inline (estilo Tabler/Lucide, trazo).
   Uso: echo icon('home'); — sin dependencias externas.
============================================================= */

function icon($name, $cls = '')
{
    $paths = [
        'droplet'    => '<path d="M12 3l5 6.5a6 6 0 1 1 -10 0z"/>',
        'home'       => '<path d="M5 12l-2 0l9 -9l9 9l-2 0"/><path d="M5 12v7a2 2 0 0 0 2 2h10a2 2 0 0 0 2 -2v-7"/><path d="M9 21v-6a2 2 0 0 1 2 -2h2a2 2 0 0 1 2 2v6"/>',
        'search'     => '<circle cx="10" cy="10" r="7"/><path d="M21 21l-6 -6"/>',
        'chevron'    => '<path d="M6 9l6 6l6 -6"/>',
        'chevron-r'  => '<path d="M9 6l6 6l-6 6"/>',
        'file'       => '<path d="M14 3v4a1 1 0 0 0 1 1h4"/><path d="M17 21h-10a2 2 0 0 1 -2 -2v-14a2 2 0 0 1 2 -2h7l5 5v11a2 2 0 0 1 -2 2z"/>',
        'chart'      => '<path d="M4 19l0 -14"/><path d="M4 19l16 0"/><path d="M8 15l3 -4l3 2l3 -5"/>',
        'trend'      => '<path d="M3 17l6 -6l4 4l8 -8"/><path d="M14 7l7 0l0 7"/>',
        'grid'       => '<rect x="4" y="4" width="6" height="6" rx="1"/><rect x="14" y="4" width="6" height="6" rx="1"/><rect x="4" y="14" width="6" height="6" rx="1"/><rect x="14" y="14" width="6" height="6" rx="1"/>',
        'map'        => '<path d="M3 7l6 -3l6 3l6 -3v13l-6 3l-6 -3l-6 3z"/><path d="M9 4l0 13"/><path d="M15 7l0 13"/>',
        'stetho'     => '<path d="M6 4h-1a1 1 0 0 0 -1 1v3.5a5.5 5.5 0 0 0 11 0v-3.5a1 1 0 0 0 -1 -1h-1"/><path d="M8 15a6 6 0 1 0 12 0v-3"/><circle cx="20" cy="10" r="2"/>',
        'tools'      => '<path d="M3 21h4l13 -13a2 2 0 0 0 -4 -4l-13 13v4"/><path d="M14.5 5.5l4 4"/>',
        'wrench'     => '<path d="M7 4a3 3 0 0 1 3 5l8 8a2 2 0 0 1 -3 3l-8 -8a3 3 0 0 1 -5 -3l2.5 2.5"/>',
        'loss'       => '<path d="M4 5l0 14"/><path d="M4 19l16 0"/><path d="M8 9l3 4l3 -2l4 5"/>',
        'history'    => '<path d="M12 8l0 4l3 3"/><path d="M3.05 11a9 9 0 1 1 .5 4"/><path d="M3 4v3h3"/>',
        'versions'   => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="6" r="2"/><circle cx="12" cy="18" r="2"/><path d="M8 6h8"/><path d="M6 8v3a7 7 0 0 0 6 7"/><path d="M18 8v3a7 7 0 0 1 -6 7"/>',
        'merma'      => '<path d="M3 17l6 -6l4 4l8 -8"/><path d="M14 7l7 0l0 7"/>',
        'bell'       => '<path d="M10 5a2 2 0 1 1 4 0a7 7 0 0 1 4 6v3a4 4 0 0 0 2 3h-16a4 4 0 0 0 2 -3v-3a7 7 0 0 1 4 -6"/><path d="M9 17v1a3 3 0 0 0 6 0v-1"/>',
        'gauge'      => '<circle cx="12" cy="13" r="8"/><path d="M12 13l3 -3"/><path d="M12 5v-2"/>',
        'monitor'    => '<rect x="3" y="4" width="18" height="12" rx="1"/><path d="M7 20h10"/><path d="M9 16v4"/><path d="M15 16v4"/>',
        'shield'     => '<path d="M12 3a12 12 0 0 0 8.5 3a12 12 0 0 1 -8.5 15a12 12 0 0 1 -8.5 -15a12 12 0 0 0 8.5 -3"/>',
        'wave'       => '<path d="M3 12h2l2 -6l4 12l3 -8l2 2h5"/>',
        'cpu'        => '<rect x="5" y="5" width="14" height="14" rx="2"/><rect x="9" y="9" width="6" height="6"/><path d="M3 10h2M3 14h2M19 10h2M19 14h2M10 3v2M14 3v2M10 19v2M14 19v2"/>',
        'logout'     => '<path d="M14 8v-2a2 2 0 0 0 -2 -2h-7a2 2 0 0 0 -2 2v12a2 2 0 0 0 2 2h7a2 2 0 0 0 2 -2v-2"/><path d="M9 12h12l-3 -3M18 15l3 -3"/>',
        'check'      => '<circle cx="12" cy="12" r="9"/><path d="M9 12l2 2l4 -4"/>',
        'calendar'   => '<rect x="4" y="5" width="16" height="15" rx="2"/><path d="M8 3v4M16 3v4M4 10h16"/>',
        'download'   => '<path d="M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2 -2v-2"/><path d="M7 11l5 5l5 -5"/><path d="M12 4l0 12"/>',
        'plus'       => '<path d="M12 5l0 14"/><path d="M5 12l14 0"/>',
        'trash'      => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 14h10l1 -14"/><path d="M9 7v-3h6v3"/>',
        'message'    => '<path d="M5 5h14a2 2 0 0 1 2 2v8a2 2 0 0 1 -2 2h-7l-4 4v-4h-3a2 2 0 0 1 -2 -2v-8a2 2 0 0 1 2 -2z"/>',
        'oil'        => '<path d="M5 21h14"/><path d="M6 21v-8l4 -2v-4l4 2v4l4 2v8"/><path d="M10 7v-3"/>',
    ];

    $p = isset($paths[$name]) ? $paths[$name] : '<circle cx="12" cy="12" r="9"/>';
    $c = $cls ? ' class="' . h($cls) . '"' : '';
    return '<svg' . $c . ' viewBox="0 0 24 24" fill="none" stroke="currentColor" '
         . 'stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'
         . $p . '</svg>';
}
