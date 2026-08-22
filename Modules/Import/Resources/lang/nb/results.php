<?php

declare(strict_types=1);

return [
    'page_title' => 'Importen er fullført',
    'heading' => 'Importen er fullført',

    'summary' => 'Importerte :count transaksjon|Importerte :count transaksjoner',
    'summary_duplicates' => ' · hoppet over :count duplikat| · hoppet over :count duplikater',
    'summary_enriched' => ' · :count beriket',
    'summary_errors' => ' · :count feil| · :count feil',

    'show_duplicates' => 'Vis duplikater som ble hoppet over (:count)',
    'duplicates_help' => 'Duplikater er rader som allerede finnes blant transaksjonene dine — de hoppes stille over ved ny import.',
    'show_errors' => 'Vis feil (:count)',
    'errors_help' => 'Feil er rader som ikke kunne leses inn; de ble ikke lagt til blant transaksjonene dine.',

    'upload_another' => 'Last opp en ny kontoutskrift',

    'issues' => [
        'row' => 'Rad :row: :reason',
        'file_stopped' => 'Filen kunne ikke leses lenger enn til rad :row. Ingenting etter den raden ble importert.',
        'file_none' => 'Filen kunne ikke leses i det hele tatt.',
        'detail' => 'Innleseren meldte: :reason',
        'duplicate' => 'Rad :row var allerede i hovedboken din.',
        'more' => '+ :count ikke listet',
    ],
];
