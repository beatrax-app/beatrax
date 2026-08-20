<?php

declare(strict_types=1);

return [
    'page_title' => 'Regler',
    'heading' => 'Regler',
    'intro' => 'Kategorisér transaktioner allerede ved importen. Regler gælder for alle kilder — bank, kort, PayPal og e-mailkvitteringer.',
    'device_local_note' => 'Regler bliver på denne enhed. De deles ikke med dine andre enheder.',

    'reapply' => 'Anvend regler på historikken igen',
    'reapplying' => 'Anvender igen…',
    'new_rule' => 'Ny regel',

    'reapply_progress_lead' => 'Anvender regler igen…',
    'reapply_progress_of' => 'af',
    'reapply_progress_trail' => 'transaktioner kontrolleret',

    'empty_heading' => 'Ingen regler endnu',
    'empty_body' => 'Regler matcher transaktioner på flere betingelser og anvender automatisk ændringer af kategori, modpart, note og skattemærke — ved import og hver gang du anvender dem på din eksisterende historik igen.',
    'empty_cta' => 'Opret din første regel',

    'col_priority' => 'Prioritet',
    'col_conditions' => 'Betingelser',
    'col_actions' => 'Handlinger',
    'col_hits' => 'Træf',
    'col_created' => 'Oprettet',
    'col_row_actions' => 'Handlinger',

    'more_conditions' => '+:count flere',

    'delete_confirm' => 'Slet?',
    'delete_yes' => 'Ja, slet',
    'cancel' => 'Annullér',
    'edit' => 'Redigér',
    'delete' => 'Slet',
    'edit_aria' => 'Redigér regel (prioritet :priority)',
    'delete_aria' => 'Slet regel (prioritet :priority)',

    'footer_note' => 'Regler og forhandlerhistorik arbejder sammen. At slette en regel fjerner ikke det, Beatrax har lært af tidligere kategoriseringer — næste import kan stadig foreslå den samme kategori automatisk ud fra historikken.',

    'chip_category' => 'Kategori: :path',
    'chip_counterparty' => 'Modpart: :path',
    'chip_note' => 'Note',
    'chip_tax_tag' => 'Skattemærke',

    'flash_deleted' => 'Reglen er slettet.',
    'flash_not_found' => 'Reglen blev ikke fundet (den kan være slettet i en anden fane).',
    'flash_saved' => 'Reglen er gemt.',
    'flash_reapplying' => 'Anvender reglerne på din historik igen…',
    'summary_no_changes' => 'Ingen ændringer — din historik passer allerede med dine regler.',
    'summary_updated' => 'Opdaterede :fields felter på tværs af :transactions transaktioner.',
    'summary_reconciled_skipped' => ':count afstemte transaktioner blev sprunget over.',
];
