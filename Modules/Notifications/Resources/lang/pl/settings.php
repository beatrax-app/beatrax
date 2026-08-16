<?php

declare(strict_types=1);

return [
    'what_heading' => 'O czym powiadamiać',

    'reminders' => [
        'label' => 'Przypomnienia o płatnościach',
        'help' => 'Otrzymuj sygnał, zanim nadejdzie termin płatności cyklicznej.',
    ],

    'lead_days' => [
        'label' => 'Przypomnij ___ dni wcześniej',
        'help' => 'Ile dni przed terminem ma pojawić się przypomnienie. 1–30 dni.',
    ],

    'budget_nudges' => [
        'label' => 'Sygnały o budżecie',
        'help' => 'Otrzymasz powiadomienie, gdy budżet kategorii będzie prawie wyczerpany.',
    ],

    'digest' => [
        'label' => 'Twoja tygodniowa sytuacja',
        'help' => 'Jak często dostajesz podsumowanie sytuacji w bieżącym okresie.',
        'daily' => 'Codziennie',
        'weekly' => 'Co tydzień',
        'off' => 'Wyłączone',
    ],

    'savings' => [
        'label' => 'Podpowiedzi o oszczędnościach',
        'help' => 'Otrzymasz powiadomienie, gdy Beatrax wypatrzy tańszy plan albo miejsce na oszczędność.',
    ],

    'when_heading' => 'Kiedy i jak',

    'quiet_hours' => [
        'label' => 'Godziny ciszy',
        'help' => 'W tym oknie bez dźwięku i banera — powiadomienia i tak trafiają do skrzynki.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Ukryj szczegóły w powiadomieniach',
        'help' => 'Pokazuj kwoty i nazwy sprzedawców w samym banerze powiadomienia. Wyłącz, jeśli Twój ekran mogą widzieć inni.',
    ],

    'save' => 'Zapisz ustawienia powiadomień',
    'saved' => 'Zapisano.',

    'other_devices' => [
        'summary' => 'Inne urządzenia',
        'empty' => 'Nie sparowano jeszcze żadnych innych urządzeń.',
        'unnamed' => 'Urządzenie bez nazwy',

        'summary_line' => 'przypomnienia :reminders · sygnały :nudges · podsumowanie :digest · oszczędności :savings',
        'on' => 'wł.',
        'off' => 'wył.',
    ],

    'errors' => [
        'save_failed' => 'Nie udało się zapisać ustawień powiadomień. Spróbuj ponownie.',
    ],
];
