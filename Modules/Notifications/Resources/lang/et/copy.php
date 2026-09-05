<?php

declare(strict_types=1);

return [
    'title' => [
        'import_finished' => 'Import lõpetatud',
        'receipts' => 'Leiti uued kviitungid',
        'manual_entry' => 'Kassaraamat uuendatud',
        'migration_finished' => 'Migreerimine lõpetatud',
        'drift' => 'Korduvmakse muutus',
        'forecast' => 'Ees ootab rahavoo puudujääk',
        'budget_nudge' => 'Eelarve on peaaegu kulutatud',
        'budget_nudge_spent' => 'Eelarve on kulutatud',
        'budget_nudge_over' => 'Eelarve on ületatud',
        'savings_prompt' => 'Koht, kus saaksid kokku hoida',
        'ics_statement_ready' => 'Uus ICS väljavõte on valmis',
        'payment_reminder_confident' => 'Makse tähtaeg :day (:date)',
        'payment_reminder_hedged' => 'Makse tähtaeg umbes :day (:date)',
        'position_digest_daily' => 'Sinu päevaülevaade',
        'position_digest_weekly' => 'Sinu nädalaülevaade',
    ],

    'body' => [
        'budget_nudge' => ':category — kulutatud :spent / :budget.',
        'receipts_matched' => 'Sinu e-postist sobitati :count kviitung.|Sinu e-postist sobitati :count kviitungit.',
        'import_finished' => 'Imporditud :count tehing.|Imporditud :count tehingut.',
        'manual_entry' => 'Käsitsi lisatud :count kirje.|Käsitsi lisatud :count kirjet.',
        'migration_finished' => 'Sinu eelarve on üle toodud, sealhulgas :count tehing.|Sinu eelarve on üle toodud, sealhulgas :count tehingut.',
        'drift' => 'Korduvmakse liikus :direction :amount võrra.',
        'forecast' => 'Sinu prognoositav jääk langeb :date alla nulli.',
        'forecast_buffer' => 'Sinu prognoositav jääk langeb :date alla sinu puhvri :buffer.',
        'ics_statement_ready' => 'Laadi see ICS portaalist alla ja lisa Beatraxi, et selle kaardi kulud oleksid ajakohased.',
        'payment_reminder_hedged' => ':name — oodatud umbes :day (:date), :amount.',
        'payment_reminder_confident' => ':name — tähtaeg :day (:date), :amount.',
    ],

    'drift_direction' => [
        'up' => 'üles',
        'down' => 'alla',
    ],

    'digest' => [
        'nothing_notable' => 'Miski ei vaja sinu tähelepanu.',
        'flow' => 'Sisse :in, välja :out, neto :net.',
        'net_worth' => 'Netoväärtus :amount.',
        'over_budget' => 'Seni :amount üle eelarve.',
        'payments_due' => 'Sel perioodil tuleb tasuda :count makse.|Sel perioodil tuleb tasuda :count makset.',
        'shortfall' => 'Ees ootab rahavoo puudujääk.',
        'forecast_not_run' => 'Rahavoo prognoosi ei ole veel tehtud.',
    ],
];
