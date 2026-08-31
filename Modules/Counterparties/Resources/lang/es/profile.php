<?php

declare(strict_types=1);

return [
    'page_title' => 'Contraparte',
    'fallback_account' => 'Cuenta',
    'fallback_counterparty' => 'Contraparte',

    'edit_display_name' => 'Editar el nombre visible',

    'hero_net_received' => 'Neto recibido',
    'hero_12mo_total' => 'Total de 12 meses',
    'hero_transactions' => 'Transacciones',
    'hero_first_seen' => 'Primera aparición',

    'tabs' => [
        'overview' => 'Resumen',
        'transactions' => 'Transacciones',
        'chains' => 'Cadenas',
        'aliases' => 'Alias',
        'transfers' => 'Transferencias',
        'entries' => 'Entradas',
        'payments' => 'Pagos',
        'tax_years' => 'Ejercicios fiscales',
    ],

    'tablist_aria' => 'Secciones de la contraparte',

    'tab_note_personal' => '— no hay cadenas de financiación para contactos personales',
    'tab_note_bank' => '— una contraparte de comisiones bancarias no genera cadenas de financiación',
    'tab_note_bank_institution' => '— no hay cadenas de financiación para contrapartes institucionales',
    'tab_note_government' => '— no hay cadenas de financiación para contrapartes de la Administración',

    'recent_activity' => 'Actividad reciente',
    'recurring' => 'Recurrente',
    'uncategorized' => 'Sin categoría',
    'no_recent_transactions' => 'Aún no hay transacciones registradas para esta contraparte.',
    'see_all' => 'Ver las :count →',

    'bank' => [
        'fees_heading' => 'Comisiones bancarias por categoría',
        'activity_heading' => 'Actividad por categoría',
        'no_fees' => 'Aún no hay comisiones registradas en esta contraparte.',
    ],

    'government' => [
        'intro' => 'Desglose anual de todos los años con actividad. Se destaca el año en curso.',
        'no_payments' => 'Aún no hay pagos registrados para esta contraparte.',
    ],

    'merchant' => [
        'categories' => 'Categorías',

        'categories_empty_html' => 'Aún no hay categorías — las transacciones sin categoría aparecen en <a href="/categorization" style="color: var(--color-text); text-decoration: underline;">Categorización</a>.',
        'no_recurring' => 'No se han detectado patrones recurrentes.',
        'per_month_suffix' => '/mes',
        'funding_chain' => 'Cadena de financiación',
        'no_funding_chain' => 'Aún no se ha detectado ninguna cadena de financiación. Para resolverlas hacen falta importaciones de datos de ASN + PayPal.',
        'open_chains' => 'Abrir la revisión de Cadenas →',
    ],

    'personal' => [
        'contact' => 'Contacto',
        'add_tag' => '+ Añadir etiqueta',
        'no_recurring' => 'No se ha detectado recurrencia — las transferencias personales rara vez siguen una frecuencia estricta; incluso un alquiler compartido regular puede cambiar de fecha.',
    ],

    'unknown' => [
        'not_labelled_heading' => 'Esta contraparte aún no está etiquetada',
        'not_labelled_body' => 'Etiquetar las desconocidas ayuda a que el panel muestre totales mensuales y cadenas de financiación exactos.',
        'label_cta' => 'Etiquetar esta contraparte',
    ],

    'support' => [
        'contact_help' => 'Contacto y ayuda',
        'sign_in_apply' => 'Acceder · solicitar',
        'your_rights' => 'Tus derechos · reclamar',
        'cancel' => 'Cancelar',
        'help_support' => 'Ayuda y soporte',
        'cheaper_plan' => 'Plan más barato',
        'aria_gov' => 'Cómo conseguir ayuda',
        'aria_merchant' => 'Soporte y cancelación',
        'heading_gov' => 'Cómo conseguir ayuda',
        'heading_merchant' => 'Soporte y cancelación',
        'cancel_by_email' => 'Cancelar por correo',
    ],
];
