<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import lõpetatud',
        'receipts' => 'Leiti uued kviitungid',
        'drift' => 'Korduvmakse muutus',
        'forecast' => 'Ees ootab rahavoo puudujääk',
        'budget_nudge' => 'Eelarve on peaaegu kulutatud',
        'savings_prompt' => 'Olemas on odavam pakett',
        'ics_statement_ready' => 'Uus ICS väljavõte on valmis',
        'payment_reminder_confident' => 'Makse tähtaeg :day',
        'payment_reminder_hedged' => 'Makse tähtaeg umbes :day',
        'position_digest_daily' => 'Sinu päevaülevaade',
        'position_digest_weekly' => 'Sinu nädalaülevaade',
    ],

    'body' => [
        'budget_nudge' => ':category — kulutatud :spent / :budget.',
        'receipts_matched' => 'Sinu e-postist sobitati :count kviitung.|Sinu e-postist sobitati :count kviitungit.',
        'import_finished' => 'Imporditud :count tehing.|Imporditud :count tehingut.',
        'drift' => 'Korduvmakse liikus :direction :delta :currency võrra.',
        'forecast' => 'Sinu prognoositav jääk langeb järgmise 30 päeva jooksul alla nulli.',
        'ics_statement_ready' => 'Laadi see ICS portaalist alla ja lisa Beatraxi, et selle kaardi kulud oleksid ajakohased.',
        'payment_reminder_hedged' => ':name — oodatud umbes :day, :amount.',
        'payment_reminder_confident' => ':name — tähtaeg :day (:date), :amount.',
        'savings_prompt' => ':message (:monthly/kuus)',
    ],

    'drift_direction' => [
        'up' => 'üles',
        'down' => 'alla',
    ],

    'digest' => [
        'nothing_notable' => 'Miski ei vaja sinu tähelepanu.',
        'flow' => 'Sisse :in, välja :out, neto :net.',
        'over_budget' => 'Seni :amount üle eelarve.',
        'payments_due' => 'Sel perioodil tuleb tasuda 1 makse.|Sel perioodil tuleb tasuda :count makset.',
        'shortfall' => 'Ees ootab rahavoo puudujääk.',
    ],
];
