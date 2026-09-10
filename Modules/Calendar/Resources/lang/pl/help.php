<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/calendar/architecture.md#entries-the-balance-line-does-not-reach */
    'balance_column' => 'Z jakich kont budowane jest prognozowane saldo dnia. Sąsiednia kolumna „:entries” rozstrzyga co innego — czy płatności z tego konta w ogóle rysują się w siatce — więc konto może być widoczne, nie licząc się, albo liczyć się, nie będąc widocznym. Tam, gdzie te dwie rzeczy się rozchodzą, dzień mówi o tym pod swoim saldem, zamiast pozwolić, by liczba po cichu złożyła się z mniejszej całości, niż się spodziewasz.',
];
