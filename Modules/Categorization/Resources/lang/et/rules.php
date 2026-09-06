<?php

declare(strict_types=1);

return [
    'page_title' => 'Reeglid',
    'heading' => 'Reeglid',
    'intro' => 'Kategoriseeri tehingud juba importimisel. Reeglid kehtivad igale allikale — pangale, kaardile, PayPalile ja e-posti kviitungitele.',
    'device_local_note' => 'Reeglid jäävad sellesse seadmesse. Neid ei jagata sinu teiste seadmetega.',

    'reapply' => 'Rakenda reeglid ajaloole uuesti',
    'reapply_confirm' => 'Kas rakendada kõik reeglid uuesti kogu sinu ajaloole? Iga kategooria, vastaspool, märkus ja maksumärgend, mille reegel on lisanud, kirjutatakse üle. See, mille oled käsitsi määranud, jääb alles, samuti kõik, mis on kooskõlastatud väljavõttel või tehingul, mille oled jaotanud. Vanu väärtusi ei too miski tagasi.',
    'reapplying' => 'Rakendan uuesti…',
    'new_rule' => 'Uus reegel',

    'reapply_progress' => 'Rakendan reegleid uuesti… :checked / :count tehing kontrollitud|Rakendan reegleid uuesti… :checked / :count tehingut kontrollitud',

    'empty_heading' => 'Reegleid veel pole',
    'empty_body' => 'Reeglid sobitavad tehinguid mitme tingimuse alusel ning muudavad importimisel automaatselt kategooriat, vastaspoolt ja märkust. Maksumärgendi muutus jõuab kohale siis, kui rakendad reeglid uuesti olemasolevale ajaloole.',
    'empty_cta' => 'Loo oma esimene reegel',

    'col_priority' => 'Prioriteet',
    'col_conditions' => 'Tingimused',
    'col_actions' => 'Toimingud',
    'col_hits' => 'Vasteid',
    'col_created' => 'Loodud',
    'col_row_actions' => 'Toimingud',
    'inactive_badge' => 'Väljas',
    'combinator_all' => 'KÕIK',
    // i18n-review: et · combinator_any — rule_form.match_any says «ükskõik millisele»,
    // which no chip can carry, so MIS TAHES stands in. Confirm the two forms are read
    // as the same thing here.
    'combinator_any' => 'MIS TAHES',
    'inactive_title' => 'See reegel ei tööta. Reegel lülitub välja, kui kustutatakse kategooria või vastaspool, millele see viitab.',

    'more_conditions' => '+:count veel',

    'delete_confirm' => 'Kas kustutada?',
    'delete_yes' => 'Jah, kustuta',
    'cancel' => 'Tühista',
    'edit' => 'Muuda',
    'delete' => 'Kustuta',
    'edit_aria' => 'Muuda reeglit (prioriteet :priority)',
    'delete_aria' => 'Kustuta reegel (prioriteet :priority)',

    'footer_note' => 'Reeglid ja kaupmeeste ajalugu töötavad koos. Reegli kustutamine ei kustuta seda, mida Beatrax on varasematest kategoriseerimistest õppinud — järgmine import võib ajaloo põhjal sama kategooriat ikkagi soovitada.',

    'chip_category' => 'Kategooria: :path',
    'chip_counterparty' => 'Vastaspool: :path',
    'chip_note' => 'Märkus',
    'chip_tax_tag' => 'Maksumärgend',

    'flash_deleted' => 'Reegel on kustutatud.',
    'flash_not_found' => 'Reeglit ei leitud (see võidi kustutada teisel vahelehel).',
    'flash_saved' => 'Reegel on salvestatud.',
    'flash_reapplying' => 'Rakendan reegleid sinu ajaloole uuesti…',
    'summary_no_changes' => 'Muudatusi pole — sinu ajalugu vastab juba reeglitele.',
    'summary_updated' => 'Uuendatud: :fields, :transactions.',
    'summary_fields' => ':count väli|:count välja',
    'summary_transactions' => ':count tehing|:count tehingut',
    'summary_reconciled_skipped' => ':count kooskõlastatud tehing jäeti vahele.|:count kooskõlastatud tehingut jäeti vahele.',
];
