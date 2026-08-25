<?php

declare(strict_types=1);

return [
    'back' => 'Zpět do Beatraxu',

    '404' => [
        'title' => 'Tahle stránka neexistuje',
        'body' => 'Odkaz může být starý, nebo se stránka přejmenovala. S tvými daty je všechno v pořádku.',
    ],
    '4xx' => [
        'title' => 'Tento požadavek nelze zpracovat',
        'body' => 'Stránka byla otevřena způsobem, který neočekává. Tvoje data se nezměnila.',
    ],

    '419' => [
        'title' => 'Tvoje relace vypršela',
        'body' => 'Byl jsi pryč dost dlouho na to, aby stránka zestárla. Otevři Beatrax znovu a pokračuj.',
    ],

    '500' => [
        'title' => 'Něco se pokazilo',
        'body' => 'Problém je zapsaný v logu na tomhle zařízení. Tvoje data se nezměnila.',
    ],

    '503' => [
        'title' => 'Beatrax je chvíli nedostupný',
        'body' => 'Dokončuje se aktualizace nebo údržba. Zkus to za okamžik.',
    ],
];
