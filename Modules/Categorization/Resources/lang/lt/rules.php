<?php

declare(strict_types=1);

return [
    'page_title' => 'Taisyklės',
    'heading' => 'Taisyklės',
    'intro' => 'Iš anksto priskirk kategorijas importuojamoms operacijoms. Taisyklės taikomos visiems šaltiniams — bankui, kortelei, PayPal ir el. pašto kvitams.',

    'reapply' => 'Taikyti taisykles istorijai iš naujo',
    'reapplying' => 'Taikoma iš naujo…',
    'new_rule' => 'Nauja taisyklė',

    'reapply_progress_lead' => 'Taisyklės taikomos iš naujo…',
    'reapply_progress_of' => 'iš',
    'reapply_progress_trail' => 'operacijų patikrinta',

    'empty_heading' => 'Kol kas taisyklių nėra',
    'empty_body' => 'Taisyklės atrenka operacijas pagal kelias sąlygas ir automatiškai pritaiko kategorijos, kitos šalies, pastabos ir mokesčių žymos pakeitimus — importuojant ir kaskart, kai jas iš naujo pritaikai esamai istorijai.',
    'empty_cta' => 'Sukurti pirmąją taisyklę',

    'col_priority' => 'Prioritetas',
    'col_conditions' => 'Sąlygos',
    'col_actions' => 'Veiksmai',
    'col_hits' => 'Atitikimai',
    'col_created' => 'Sukurta',
    'col_row_actions' => 'Veiksmai',

    'more_conditions' => 'dar :count',

    'delete_confirm' => 'Ištrinti?',
    'delete_yes' => 'Taip, ištrinti',
    'cancel' => 'Atšaukti',
    'edit' => 'Redaguoti',
    'delete' => 'Ištrinti',
    'edit_aria' => 'Redaguoti taisyklę (prioritetas :priority)',
    'delete_aria' => 'Ištrinti taisyklę (prioritetas :priority)',

    'footer_note' => 'Taisyklės ir prekybininkų istorija veikia kartu. Ištrynus taisyklę neišvaloma tai, ko Beatrax išmoko iš ankstesnių kategorijų priskyrimų — kitas importas vis tiek gali automatiškai pasiūlyti tą pačią kategoriją iš istorijos.',

    'chip_category' => 'Kategorija: :path',
    'chip_counterparty' => 'Kita šalis: :path',
    'chip_note' => 'Pastaba',
    'chip_tax_tag' => 'Mokesčių žyma',

    'flash_deleted' => 'Taisyklė ištrinta.',
    'flash_not_found' => 'Taisyklė nerasta (ji galėjo būti ištrinta kitoje kortelėje).',
    'flash_saved' => 'Taisyklė išsaugota.',
    'flash_reapplying' => 'Taisyklės iš naujo taikomos tavo istorijai…',
    'summary_no_changes' => 'Pakeitimų nėra — tavo istorija jau atitinka taisykles.',
    'summary_updated' => 'Atnaujinta laukų: :fields, operacijų: :transactions.',
    'summary_reconciled_skipped' => 'Praleista suderintų operacijų: :count.',
];
