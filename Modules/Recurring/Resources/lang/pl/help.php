<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Wyciąg to płaska lista dat i kwot i nic w nim nie mówi, które wiersze są tym samym stałym zobowiązaniem. Beatrax grupuje wiersze według odbiorcy płatności, odrzuca kwoty odstające od grupy i proponuje serię dopiero wtedy, gdy odstępy między nimi układają się w stały rytm tygodniowy, miesięczny, kwartalny lub roczny — wszystko mniej regularne nie jest proponowane w ogóle. Sięga wstecz tylko tak daleko, jak pozwala „:setting” w Ustawieniach, a to zaczyna się od najkrótszego okresu, z jakim w ogóle da się pracować, więc roczny rachunek pozostaje niewidoczny, dopóki go nie poszerzysz. Nic tutaj nie zmienia twoich danych, dopóki tego nie zatwierdzisz.',
];
