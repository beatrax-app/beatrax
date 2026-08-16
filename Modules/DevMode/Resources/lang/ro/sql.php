<?php

declare(strict_types=1);

return [
    'tables' => 'Tabele',
    'schema_viewer_aria' => 'Vizualizator de schemă',
    'columns' => 'coloane',
    'indexes' => 'indecși',
    'foreign_keys' => 'chei străine',
    'browse' => 'Răsfoiește',
    'heading' => 'SQL',

    'subtitle_html' => 'Panou de interogare doar pentru SELECT. Validatorul (la parsare) și PRAGMA <code class="font-mono text-xs">query_only = 1</code> (la nivel de motor) resping orice nu este SELECT. Limită dură de 5 secunde de rulare.',
    'advanced_off_strong' => 'Modul Advanced este OPRIT.',
    'advanced_off_hint' => 'Activează Advanced (Dev Mode → Advanced) ca să rulezi interogări.',
    'statement_label' => 'Instrucțiune SELECT',
    'run' => 'Rulează',
    'rows_meta' => ':rows rânduri · :durationms',
    'no_rows' => 'Interogarea nu a returnat niciun rând.',

    'errors' => [
        'advanced_off' => 'Activează Advanced (Dev Mode → Advanced) ca să rulezi interogări.',
        'only_select' => 'Sunt permise doar instrucțiunile SELECT. Motivul respingerii: :reason.',
        'timeout' => 'Interogarea a depășit limita de 5 secunde. Rafinează interogarea și încearcă din nou.',
        'engine' => 'Eroare SQL: :message',
        'unknown_table' => 'Tabel necunoscut.',
    ],
];
