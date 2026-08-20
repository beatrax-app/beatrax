<?php

declare(strict_types=1);

return [
    'page_title' => 'Pravila',
    'heading' => 'Pravila',
    'intro' => 'Unaprijed kategoriziraj transakcije pri uvozu. Pravila vrijede za svaki izvor — banku, karticu, PayPal i potvrde iz e-pošte.',
    'device_local_note' => 'Pravila ostaju na ovom uređaju. Ne dijele se s vašim drugim uređajima.',

    'reapply' => 'Ponovno primijeni pravila na povijest',
    'reapplying' => 'Ponovna primjena…',
    'new_rule' => 'Novo pravilo',

    'reapply_progress_lead' => 'Ponovna primjena pravila…',
    'reapply_progress_of' => 'od',
    'reapply_progress_trail' => 'provjerenih transakcija',

    'empty_heading' => 'Još nema pravila',
    'empty_body' => 'Pravila prepoznaju transakcije prema više uvjeta i automatski primjenjuju promjene kategorije, protustranke, bilješke i porezne oznake — pri uvozu i svaki put kad ih ponovno primijeniš na postojeću povijest.',
    'empty_cta' => 'Stvori svoje prvo pravilo',

    'col_priority' => 'Prioritet',
    'col_conditions' => 'Uvjeti',
    'col_actions' => 'Radnje',
    'col_hits' => 'Pogoci',
    'col_created' => 'Stvoreno',
    'col_row_actions' => 'Radnje',

    'more_conditions' => '+:count više',

    'delete_confirm' => 'Izbrisati?',
    'delete_yes' => 'Da, izbriši',
    'cancel' => 'Odustani',
    'edit' => 'Uredi',
    'delete' => 'Izbriši',
    'edit_aria' => 'Uredi pravilo (prioritet :priority)',
    'delete_aria' => 'Izbriši pravilo (prioritet :priority)',

    'footer_note' => 'Pravila i povijest trgovaca rade zajedno. Brisanje pravila ne briše ono što je Beatrax naučio iz prijašnjih kategorizacija — sljedeći uvoz i dalje može automatski predložiti istu kategoriju na temelju povijesti.',

    'chip_category' => 'Kategorija: :path',
    'chip_counterparty' => 'Protustranka: :path',
    'chip_note' => 'Bilješka',
    'chip_tax_tag' => 'Porezna oznaka',

    'flash_deleted' => 'Pravilo je izbrisano.',
    'flash_not_found' => 'Pravilo nije pronađeno (možda je izbrisano u drugoj kartici).',
    'flash_saved' => 'Pravilo je spremljeno.',
    'flash_reapplying' => 'Ponovna primjena pravila na povijest…',
    'summary_no_changes' => 'Nema promjena — tvoja povijest već odgovara pravilima.',
    'summary_updated' => 'Ažurirano :fields polja u :transactions transakcija.',
    'summary_reconciled_skipped' => 'Preskočeno je :count usklađenih transakcija.',
];
