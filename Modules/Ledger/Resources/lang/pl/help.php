<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/ledger/reconcile-needs-an-anchor.md#the-arithmetic */
    'reconcile' => 'Uzgadnianie to zestawienie Beatrax z liczbą podaną przez sam bank. Saldo rozliczone to saldo początkowe tego konta plus każdy wiersz oznaczony jako rozliczony do daty wyciągu, a różnica to liczba z wyciągu minus to saldo. Zaznaczaj i odznaczaj wiersze na liście transakcji, aż różnica osiągnie zero — ten ekran nigdy nie tworzy zapisu wyrównującego. „:complete” blokuje następnie objęte wiersze: zablokowanego wiersza nie da się edytować, podzielić ani usunąć, dopóki nie odblokujesz go na jego własnej stronie.',

    /** @link ../../../../../.docs/features/ledger/architecture.md#transactionstatuswriter--the-one-writer-of-transactionsstatus */
    'status' => 'Jak wiersz wypada wobec twojego wyciągu bankowego. „:uncleared” znaczy, że Beatrax ma transakcję, ale ty nie zestawiłeś jej jeszcze z wyciągiem; dotknij plakietki, żeby zmienić ją na „:cleared” — to właśnie te znaczniki sumuje ekran uzgadniania. „:reconciled” nie da się dotknąć: stawia ją zakończone uzgodnienie, które blokuje wiersz, więc kategoria, notatka, podział i etykiety podatkowe zostają takie, jakie są, dopóki go nie odblokujesz.',
];
