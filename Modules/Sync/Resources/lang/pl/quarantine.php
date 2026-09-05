<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count zmianę wprowadziła nowsza wersja Beatrax|:count zmiany wprowadziła nowsza wersja Beatrax|:count zmian wprowadziła nowsza wersja Beatrax',
        'body' => 'To, co zostało odrzucone, odwołuje się do czegoś, czego ta wersja Beatrax nie ma, więc to urządzenie nie miało gdzie tego zapisać. Nadal jest na urządzeniu, które to wprowadziło, i nic z twoich danych nie zostało usunięte.',
        'action' => 'Zaktualizuj Beatrax na tym urządzeniu. Zmiany wprowadzone po aktualizacji docierają normalnie, ale nic, co zostało już odrzucone, nie jest wysyłane ponownie — wprowadź zmianę tutaj jeszcze raz, jeśli potrzebujesz jej także na tym urządzeniu.',
    ],
    'untrusted_author' => [
        'summary' => ':count zmianę podpisało urządzenie, którego to urządzenie nie rozpoznaje|:count zmiany podpisało urządzenie, którego to urządzenie nie rozpoznaje|:count zmian podpisało urządzenie, którego to urządzenie nie rozpoznaje',
        'body' => 'To, co zostało odrzucone, przyszło z urządzenia, które nigdy nie było sparowane z tym, albo z urządzenia, które usunąłeś. Nic tu nie zostało zapisane i nic z tego, co już tu było, się nie zmieniło.',
        'action' => 'Jeśli sam usunąłeś to urządzenie, właśnie to robi usunięcie i nie ma czego naprawiać. Jeśli nie, sprawdź listę urządzeń na tej stronie.',
    ],
    'not_verified' => [
        'summary' => ':count zmiana nie przeszła kontroli bezpieczeństwa na tym urządzeniu|:count zmiany nie przeszły kontroli bezpieczeństwa na tym urządzeniu|:count zmian nie przeszło kontroli bezpieczeństwa na tym urządzeniu',
        'body' => 'Podpis nie zgadzał się z urządzeniem, które twierdziło, że wprowadziło zmianę, albo zmiana była skierowana do innego konta. Nic tu nie zostało zapisane. Między twoimi własnymi urządzeniami nie powinno się to zdarzać.',
        'action' => 'Sprawdź listę urządzeń na tej stronie i usuń wszystko, czego nie rozpoznajesz. Jeśli każde urządzenie na liście jest twoje, a to się powtarza, jest to usterka Beatraxa, a nie coś, co możesz naprawić stąd.',
    ],
    'diverged' => [
        'summary' => ':count zmiana z innego urządzenia nie mogła zostać tu zapisana|:count zmiany z innego urządzenia nie mogły zostać tu zapisane|:count zmian z innego urządzenia nie mogło zostać tu zapisanych',
        'body' => 'Przyszło coś, czego to urządzenie nie mogło zapisać: rekord, któremu brakuje części siebie, data, która nie istnieje, podział, który już się nie zgadza, rekord, któremu dwa urządzenia nadały już tę samą tożsamość, albo usunięcie czegoś, co jest tu jeszcze w użyciu. To, co zostało odrzucone, jest na twoim drugim urządzeniu, a nie na tym, więc oba nie zawierają już tego samego.',
        'action' => 'Porównaj rekord na swoim drugim urządzeniu z tym, co widzisz tutaj, i wprowadź zmianę tutaj jeszcze raz — albo usuń to tutaj ponownie, jeśli coś, co usunąłeś gdzie indziej, wciąż tu jest. Nic odrzuconego nie jest wysyłane ponownie samo z siebie.',
    ],
    'last_seen' => 'Najnowsze: :when',
];
