<?php

declare(strict_types=1);

return [
    'editor_aria' => 'Editor de scenarii — :name',
    'rename_aria' => 'Redenumește scenariul',
    'save' => 'Salvează',
    'save_changes' => 'Salvează modificările',
    'cancel' => 'Anulează',
    'rename' => 'Redenumește',
    'confirm_delete' => 'Confirmă ștergerea',
    'delete_scenario' => 'Șterge scenariul',
    'delete_confirm' => 'Ștergi acest scenariu?',

    'mutations_count' => 'Modificări (:count)',
    'no_mutations' => 'Nicio modificare deocamdată. Adaugă una mai jos ca să vezi cum se compară acest scenariu cu scenariul de bază.',
    'editing' => 'Se editează — :kind',
    'edit' => 'Editează',
    'remove' => 'Elimină',

    'add_mutation' => '+ Adaugă o modificare',
    'add_to_scenario' => 'Adaugă în scenariu',
    'pick_kind' => 'Alege tipul modificării:',

    'kind' => [
        'cancel_series' => [
            'title' => 'Anulează o serie',
            'desc' => 'Elimină fiecare apariție estimată a unei serii aprobate.',
        ],
        'add_one_off' => [
            'title' => 'Adaugă o plată sau o încasare unică',
            'desc' => 'Un singur eveniment ipotetic la o dată anume.',
        ],
        'add_recurring' => [
            'title' => 'Adaugă o serie recurentă',
            'desc' => 'Un abonament sau o sursă de venit ipotetică.',
        ],
        'change_series_amount' => [
            'title' => 'Schimbă suma unei serii',
            'desc' => 'Modelează o creștere sau o scădere de preț la o serie existentă.',
        ],
        'shift_series_date' => [
            'title' => 'Mută data unei serii',
            'desc' => 'Mută înainte următoarea apariție sau toate aparițiile ulterioare.',
        ],
    ],

    'form' => [
        'series_to_cancel' => 'Seria de anulat',
        'pick_series' => '— alege o serie —',
        'date' => 'Dată',
        'amount' => 'Sumă',
        'currency' => 'Monedă',
        'direction' => 'Sens',
        'expense_long' => 'Cheltuială (bani ieșiți)',
        'income_long' => 'Venit (bani intrați)',
        'note' => 'Notă (opțional)',
        'start_date' => 'Dată de început',
        'expense' => 'Cheltuială',
        'income' => 'Venit',
        'cadence' => 'Frecvență',
        'cadence_weekly' => 'Săptămânal',
        'cadence_monthly' => 'Lunar',
        'cadence_quarterly' => 'Trimestrial',
        'cadence_yearly' => 'Anual',
        'series' => 'Serie',
        'new_amount' => 'Sumă nouă',
        'new_next_date' => 'Noua dată următoare',
        'scope' => 'Domeniu',
        'scope_legend' => 'Care apariții se mută',
        'scope_next' => 'Doar următoarea apariție',
        'scope_all' => 'Toate aparițiile ulterioare',
    ],

    'whatif' => [
        'trigger' => 'Simulează un „ce-ar fi dacă”',
        'menu_aria' => 'Simulează un „ce-ar fi dacă” pentru :name',
        'model_cancellation' => 'Simulează anularea',
        'model_amount_change' => 'Simulează schimbarea sumei…',
        'amount_dialog_aria' => 'Simulează schimbarea sumei pentru :name',
        'current_amount' => 'Suma curentă',
        'new_amount' => 'Sumă nouă',
    ],

    'summary' => [
        'cancel' => 'Anulează :name',
        'series_fallback' => 'seria #:id',
        'one_off' => ':amount :currency pe :date',
        'recurring' => ':amount :currency :cadence de la :date',
        'change_amount' => ':name: sumă nouă :amount',
        'shift' => ':name: mută :scope pe :date',
        'scope_all' => 'toate aparițiile ulterioare',
        'scope_next' => 'următoarea',
    ],

    'toast' => [
        'created' => 'Scenariul „:name” a fost creat.',
        'deleted' => 'Scenariu șters.',
        'renamed' => 'Scenariu redenumit.',
        'mutation_added' => 'Modificare adăugată.',
        'mutation_updated' => 'Modificare actualizată.',
        'mutation_removed' => 'Modificare eliminată. Anulează',
    ],

    'errors' => [
        'name_empty' => 'Numele scenariului nu poate fi gol.',
        'name_too_long' => 'Numele scenariului trebuie să aibă cel mult :max caractere.',
        'name_taken' => 'Există deja un scenariu cu acest nume.',
        'pick_kind_first' => 'Alege întâi tipul modificării.',
        'amount_positive' => 'Suma trebuie să fie un număr pozitiv.',
    ],
];
