<?php

declare(strict_types=1);

return [
    'what_heading' => 'O čemu me obavijestiti',
    'background_note' => 'Beatrax ih priprema dok je aplikacija otvorena. Zakazano pokretanje u pozadini to ne može — zaključavanje aplikacije čuva jedini ključ — pa se ono što čeka preuzima dok nastavljaš koristiti aplikaciju.',
    'background_note_phone' => 'Beatrax ih priprema dok je aplikacija otvorena. U pozadini to ne može — zaključavanje aplikacije čuva jedini ključ — pa ono što čeka stiže kad sljedeći put otvoriš aplikaciju.',

    'reminders' => [
        'label' => 'Podsjetnici na plaćanja',
        'help' => 'Javi mi prije nego što ponavljajuće plaćanje dospije.',
    ],

    'lead_days' => [
        'label' => 'Podsjeti me ___ dana prije',
        'help' => 'Koliko dana prije datuma dospijeća stiže podsjetnik. 1–30 dana.',
    ],

    'budget_nudges' => [
        'label' => 'Poticaji za proračun',
        'help' => 'Javi mi kad je proračun kategorije gotovo potrošen.',
    ],

    'digest' => [
        'label' => 'Tvoja tjedna slika',
        'help' => 'Koliko često dobivaš sažetak stanja u ovom razdoblju.',
        'daily' => 'Dnevno',
        'weekly' => 'Tjedno',
        'off' => 'Isključeno',
    ],

    'savings' => [
        'label' => 'Prijedlozi za uštedu',
        'help' => 'Javi mi kad Beatrax uoči jeftiniji paket ili mjesto na kojem možeš uštedjeti.',
    ],

    'when_heading' => 'Kada i kako',

    'quiet_hours' => [
        'label' => 'Tihi sati',
        'help' => 'U ovom razdoblju nema zvuka ni obavijesti na zaslonu — obavijesti i dalje stižu u tvoj pretinac.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Sakrij pojedinosti u obavijestima',
        'help' => 'Sakrij iznose i nazive trgovaca u samoj obavijesti. Uključi ako bi tvoj zaslon mogli vidjeti drugi.',
    ],

    'save' => 'Spremi postavke obavijesti',
    'saved' => 'Spremljeno.',

    'other_devices' => [
        'summary' => 'Ostali uređaji',
        'empty' => 'Još nema drugih uparenih uređaja.',
        'unnamed' => 'Neimenovani uređaj',

        'summary_line' => 'podsjetnici :reminders · poticaji :nudges · sažetak :digest · uštede :savings',
        'on' => 'uključeno',
        'off' => 'isključeno',
    ],

    'errors' => [
        'save_failed' => 'Tvoje postavke obavijesti nije bilo moguće spremiti. Pokušaj ponovno.',
    ],
];
