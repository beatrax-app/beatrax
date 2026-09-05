<?php

declare(strict_types=1);

return [
    'page_title' => 'Regler',
    'heading' => 'Regler',
    'intro' => 'Kategoriser transaksjoner allerede ved importen. Regler gjelder for alle kilder — bank, kort, PayPal og e-postkvitteringer.',
    'device_local_note' => 'Regler blir på denne enheten. De deles ikke med de andre enhetene dine.',

    'reapply' => 'Bruk reglene på historikken på nytt',
    'reapply_confirm' => 'Vil du bruke alle reglene på hele historikken din på nytt? Hver kategori, motpart, notat og skattemerke som en regel har satt, blir skrevet over. Det du har satt for hånd, blir stående, og det samme gjør alt på en avstemt kontoutskrift eller på en transaksjon du har fordelt. Ingenting setter de gamle verdiene tilbake.',
    'reapplying' => 'Bruker på nytt…',
    'new_rule' => 'Ny regel',

    'reapply_progress' => 'Bruker regler på nytt… :checked av :count transaksjon kontrollert|Bruker regler på nytt… :checked av :count transaksjoner kontrollert',

    'empty_heading' => 'Ingen regler ennå',
    'empty_body' => 'Regler matcher transaksjoner på flere betingelser og gjør endringer av kategori, motpart, notat og skattemerke automatisk — ved import og hver gang du bruker dem på den eksisterende historikken din på nytt.',
    'empty_cta' => 'Opprett din første regel',

    'col_priority' => 'Prioritet',
    'col_conditions' => 'Betingelser',
    'col_actions' => 'Handlinger',
    'col_hits' => 'Treff',
    'col_created' => 'Opprettet',
    'col_row_actions' => 'Handlinger',
    'inactive_badge' => 'Av',
    'combinator_all' => 'ALLE',
    'combinator_any' => 'MINST ÉN',
    'inactive_title' => 'Denne regelen kjører ikke. En regel slås av når kategorien eller motparten den peker på slettes.',

    'more_conditions' => '+:count til',

    'delete_confirm' => 'Slette?',
    'delete_yes' => 'Ja, slett',
    'cancel' => 'Avbryt',
    'edit' => 'Rediger',
    'delete' => 'Slett',
    'edit_aria' => 'Rediger regel (prioritet :priority)',
    'delete_aria' => 'Slett regel (prioritet :priority)',

    'footer_note' => 'Regler og forhandlerhistorikk virker sammen. Å slette en regel fjerner ikke det Beatrax har lært av tidligere kategoriseringer — neste import kan fortsatt foreslå den samme kategorien automatisk ut fra historikken.',

    'chip_category' => 'Kategori: :path',
    'chip_counterparty' => 'Motpart: :path',
    'chip_note' => 'Notat',
    'chip_tax_tag' => 'Skattemerke',

    'flash_deleted' => 'Regelen er slettet.',
    'flash_not_found' => 'Fant ikke regelen (den kan ha blitt slettet i en annen fane).',
    'flash_saved' => 'Regelen er lagret.',
    'flash_reapplying' => 'Bruker reglene på historikken din på nytt…',
    'summary_no_changes' => 'Ingen endringer — historikken din samsvarer allerede med reglene dine.',
    'summary_updated' => 'Oppdaterte :fields på tvers av :transactions.',
    'summary_fields' => ':count felt|:count felter',
    'summary_transactions' => ':count transaksjon|:count transaksjoner',
    'summary_reconciled_skipped' => ':count avstemt transaksjon ble hoppet over.|:count avstemte transaksjoner ble hoppet over.',
];
