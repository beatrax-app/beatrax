<?php

declare(strict_types=1);

return [
    'page_title' => 'Reguły',
    'heading' => 'Reguły',
    'intro' => 'Wstępnie kategoryzuj transakcje podczas importu. Reguły działają dla każdego źródła — banku, karty, PayPal i paragonów z e-maili.',
    'device_local_note' => 'Reguły pozostają na tym urządzeniu. Nie są udostępniane innym Twoim urządzeniom.',

    'reapply' => 'Zastosuj reguły ponownie do historii',
    'reapply_confirm' => 'Zastosować ponownie wszystkie reguły do całej Twojej historii? Każda kategoria, kontrahent, notatka i znacznik podatkowy ustawione przez regułę zostaną nadpisane. To, co ustawiono ręcznie, zostaje, tak samo jak wszystko na uzgodnionym wyciągu lub na transakcji, którą podzielono. Nic nie przywróci starych wartości.',
    'reapplying' => 'Ponowne stosowanie…',
    'new_rule' => 'Nowa reguła',

    'reapply_progress' => 'Ponowne stosowanie reguł… :checked z :count sprawdzonej transakcji|Ponowne stosowanie reguł… :checked z :count sprawdzonych transakcji|Ponowne stosowanie reguł… :checked z :count sprawdzonych transakcji',

    'empty_heading' => 'Brak reguł',
    'empty_body' => 'Reguły dopasowują transakcje na podstawie wielu warunków i automatycznie zmieniają kategorię, kontrahenta, notatkę oraz znacznik podatkowy — podczas importu i przy każdym ponownym zastosowaniu do istniejącej historii.',
    'empty_cta' => 'Utwórz pierwszą regułę',

    'col_priority' => 'Priorytet',
    'col_conditions' => 'Warunki',
    'col_actions' => 'Akcje',
    'col_hits' => 'Trafienia',
    'col_created' => 'Utworzono',
    'col_row_actions' => 'Akcje',
    'inactive_badge' => 'Wyłączona',
    'combinator_all' => 'WSZYSTKIE',
    'combinator_any' => 'DOWOLNY',
    'inactive_title' => 'Ta reguła nie działa. Reguła wyłącza się, gdy kategoria lub kontrahent, na które wskazuje, zostaną usunięte.',

    'more_conditions' => '+:count więcej',

    'delete_confirm' => 'Usunąć?',
    'delete_yes' => 'Tak, usuń',
    'cancel' => 'Anuluj',
    'edit' => 'Edytuj',
    'delete' => 'Usuń',
    'edit_aria' => 'Edytuj regułę (priorytet :priority)',
    'delete_aria' => 'Usuń regułę (priorytet :priority)',

    'footer_note' => 'Reguły i historia sprzedawców działają razem. Usunięcie reguły nie kasuje tego, czego Beatrax nauczył się z wcześniejszych kategoryzacji — kolejny import może nadal automatycznie zaproponować tę samą kategorię na podstawie historii.',

    'chip_category' => 'Kategoria: :path',
    'chip_counterparty' => 'Kontrahent: :path',
    'chip_note' => 'Notatka',
    'chip_tax_tag' => 'Znacznik podatkowy',

    'flash_deleted' => 'Reguła usunięta.',
    'flash_not_found' => 'Nie znaleziono reguły (mogła zostać usunięta w innej karcie).',
    'flash_saved' => 'Reguła zapisana.',
    'flash_reapplying' => 'Ponowne stosowanie reguł do historii…',
    'summary_no_changes' => 'Brak zmian — historia już odpowiada Twoim regułom.',
    'summary_updated' => 'Zaktualizowano: :fields, :transactions.',
    'summary_fields' => ':count pole|:count pola|:count pól',
    'summary_transactions' => ':count transakcja|:count transakcje|:count transakcji',
    'summary_reconciled_skipped' => 'Pominięto :count uzgodnioną transakcję.|Pominięto :count uzgodnione transakcje.|Pominięto :count uzgodnionych transakcji.',
];
