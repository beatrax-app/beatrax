<?php

declare(strict_types=1);

return [
    'page_title' => 'Pravila',
    'heading' => 'Pravila',
    'intro' => 'Unapred kategorizuj transakcije pri uvozu. Pravila važe za svaki izvor — banku, karticu, PayPal i potvrde iz e-pošte.',
    'device_local_note' => 'Pravila ostaju na ovom uređaju. Ne dele se sa vašim drugim uređajima.',

    'reapply' => 'Ponovo primeni pravila na istoriju',
    'reapplying' => 'Ponovna primena…',
    'new_rule' => 'Novo pravilo',

    'reapply_progress_lead' => 'Ponovna primena pravila…',
    'reapply_progress_of' => 'od',
    'reapply_progress_trail' => 'proverenih transakcija',

    'empty_heading' => 'Još nema pravila',
    'empty_body' => 'Pravila prepoznaju transakcije po više uslova i automatski primenjuju izmene kategorije, druge strane, beleške i poreske oznake — pri uvozu i svaki put kada ih ponovo primeniš na postojeću istoriju.',
    'empty_cta' => 'Napravi svoje prvo pravilo',

    'col_priority' => 'Prioritet',
    'col_conditions' => 'Uslovi',
    'col_actions' => 'Radnje',
    'col_hits' => 'Pogoci',
    'col_created' => 'Napravljeno',
    'col_row_actions' => 'Radnje',

    'more_conditions' => '+:count više',

    'delete_confirm' => 'Obrisati?',
    'delete_yes' => 'Da, obriši',
    'cancel' => 'Otkaži',
    'edit' => 'Izmeni',
    'delete' => 'Obriši',
    'edit_aria' => 'Izmeni pravilo (prioritet :priority)',
    'delete_aria' => 'Obriši pravilo (prioritet :priority)',

    'footer_note' => 'Pravila i istorija trgovaca rade zajedno. Brisanje pravila ne briše ono što je Beatrax naučio iz ranijih kategorizacija — sledeći uvoz i dalje može automatski da predloži istu kategoriju na osnovu istorije.',

    'chip_category' => 'Kategorija: :path',
    'chip_counterparty' => 'Druga strana: :path',
    'chip_note' => 'Beleška',
    'chip_tax_tag' => 'Poreska oznaka',

    'flash_deleted' => 'Pravilo je obrisano.',
    'flash_not_found' => 'Pravilo nije pronađeno (možda je obrisano u drugoj kartici).',
    'flash_saved' => 'Pravilo je sačuvano.',
    'flash_reapplying' => 'Ponovna primena pravila na istoriju…',
    'summary_no_changes' => 'Nema izmena — tvoja istorija već odgovara pravilima.',
    'summary_updated' => 'Ažurirano :fields polja u :transactions transakcija.',
    'summary_reconciled_skipped' => 'Preskočeno je :count usaglašenih transakcija.',
];
