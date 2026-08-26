<?php

declare(strict_types=1);

return [
    'page_title' => 'Regler',
    'heading' => 'Regler',
    'intro' => 'Kategorisera transaktioner redan vid importen. Regler gäller för alla källor — bank, kort, PayPal och e-postkvitton.',
    'device_local_note' => 'Regler stannar på den här enheten. De delas inte med dina andra enheter.',

    'reapply' => 'Tillämpa regler på historiken igen',
    'reapplying' => 'Tillämpar igen…',
    'new_rule' => 'Ny regel',

    'reapply_progress_lead' => 'Tillämpar regler igen…',
    'reapply_progress_of' => 'av',
    'reapply_progress_trail' => 'transaktioner kontrollerade',

    'empty_heading' => 'Inga regler ännu',
    'empty_body' => 'Regler matchar transaktioner mot flera villkor och tillämpar automatiskt ändringar av kategori, motpart, anteckning och skatteetikett — vid import och varje gång du tillämpar dem på din befintliga historik igen.',
    'empty_cta' => 'Skapa din första regel',

    'col_priority' => 'Prioritet',
    'col_conditions' => 'Villkor',
    'col_actions' => 'Åtgärder',
    'col_hits' => 'Träffar',
    'col_created' => 'Skapad',
    'col_row_actions' => 'Åtgärder',
    'inactive_badge' => 'Av',
    'inactive_title' => 'Den här regeln körs inte. En regel stängs av när kategorin eller motparten den pekar på tas bort.',

    'more_conditions' => '+:count till',

    'delete_confirm' => 'Ta bort?',
    'delete_yes' => 'Ja, ta bort',
    'cancel' => 'Avbryt',
    'edit' => 'Redigera',
    'delete' => 'Ta bort',
    'edit_aria' => 'Redigera regel (prioritet :priority)',
    'delete_aria' => 'Ta bort regel (prioritet :priority)',

    'footer_note' => 'Regler och handlarhistorik fungerar tillsammans. Att ta bort en regel raderar inte det Beatrax har lärt sig av tidigare kategoriseringar — nästa import kan fortfarande föreslå samma kategori automatiskt utifrån historiken.',

    'chip_category' => 'Kategori: :path',
    'chip_counterparty' => 'Motpart: :path',
    'chip_note' => 'Anteckning',
    'chip_tax_tag' => 'Skatteetikett',

    'flash_deleted' => 'Regeln har tagits bort.',
    'flash_not_found' => 'Regeln hittades inte (den kan ha tagits bort i en annan flik).',
    'flash_saved' => 'Regeln har sparats.',
    'flash_reapplying' => 'Tillämpar reglerna på din historik igen…',
    'summary_no_changes' => 'Inga ändringar — din historik stämmer redan med dina regler.',
    'summary_updated' => 'Uppdaterade :fields i :transactions.',
    'summary_fields' => ':count fält|:count fält',
    'summary_transactions' => ':count transaktion|:count transaktioner',
    'summary_reconciled_skipped' => ':count avstämd transaktion hoppades över.|:count avstämda transaktioner hoppades över.',
];
