<?php

declare(strict_types=1);

return [
    'sensitivity_label' => 'Įspėjimų jautrumas',
    'sensitivity_help' => 'Pažymėti mokėjimus, kurie daugiau nei :percent% viršija įprastas tavo išlaidas tam prekybininkui ar kategorijai.',

    'min_amount_label' => 'Mažiausia mokėjimo suma',
    'min_amount_help' => 'Nepaisyti anomalijų mokėjimuose, mažesniuose už šią sumą. Saugoma centais (:symbol) — 1000 reiškia :example.',

    'save' => 'Išsaugoti anomalijų nustatymus',
    'saved' => 'Išsaugota.',

    'suppression' => [
        'summary' => 'Slopinimo taisyklės',
        'empty' => 'Kol kas slopinimo taisyklių nėra. Kai pažymėsi mokėjimą kaip tikėtiną, čia atsiras taisyklė.',
        'remove' => 'Pašalinti',
        'remove_aria' => 'Pašalinti slopinimo taisyklę',
        'removed_toast' => 'Taisyklė pašalinta',
    ],

    'unknown_merchant' => 'Nežinomas prekybininkas',

    'detectors' => [
        'large' => 'Didelis mokėjimas',
        'first_time' => 'Pirmas kartas',
        'duplicate' => 'Dublikatas',
    ],

    'errors' => [
        'sensitivity_range' => 'Jautrumas turi būti nuo 1 iki 100.',
        'min_amount_negative' => 'Mažiausia mokėjimo suma negali būti neigiama.',
    ],
];
