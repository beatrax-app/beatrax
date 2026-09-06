<?php

declare(strict_types=1);

return [
    'what_heading' => 'O čemu da te obaveštavam',
    'background_note' => 'Beatrax ih priprema dok je aplikacija otvorena. Zakazano pokretanje u pozadini to ne može — zaključavanje aplikacije čuva jedini ključ — pa se ono što čeka preuzima dok nastavljaš da koristiš aplikaciju.',
    'background_note_phone' => 'Beatrax ih priprema dok je aplikacija otvorena. U pozadini to ne može — zaključavanje aplikacije čuva jedini ključ — pa ono što čeka stiže kad sledeći put otvoriš aplikaciju.',
    'system_grant_refused' => 'Tvoj uređaj ne dozvoljava Beatraxu da prikazuje obaveštenja, pa te ništa od navedenog ne može dosegnuti. Uključi ih za Beatrax u podešavanjima uređaja.',

    'reminders' => [
        'label' => 'Podsetnici na plaćanja',
        'help' => 'Javi mi pre nego što ponavljajuće plaćanje dospe.',
    ],

    'lead_days' => [
        'label' => 'Podseti me ___ dana ranije',
        'help' => 'Koliko dana pre datuma dospeća stiže podsetnik. 1–30 dana.',
    ],

    'budget_nudges' => [
        'label' => 'Podsticaji za budžet',
        'help' => 'Javi mi kad je budžet kategorije skoro potrošen.',
    ],

    'digest' => [
        'label' => 'Tvoja slika',
        'help' => 'Koliko često dobijaš sažetak stanja u ovom periodu.',
        'daily' => 'Dnevno',
        'weekly' => 'Nedeljno',
        'off' => 'Isključeno',
    ],

    'savings' => [
        'label' => 'Predlozi za uštedu',
        'help' => 'Javi mi kad Beatrax uoči jeftiniji paket ili mesto na kome možeš da uštediš.',
    ],

    'when_heading' => 'Kada i kako',

    'quiet_hours' => [
        'label' => 'Tihi sati',
        'help' => 'U ovom periodu nema zvuka ni obaveštenja na ekranu — obaveštenja i dalje stižu u tvoje sanduče.',
        'from' => 'Od',
        'to' => 'Do',
    ],

    'hide_details' => [
        'label' => 'Sakrij detalje u obaveštenjima',
        'help' => 'Sakrij iznose i nazive trgovaca u samom obaveštenju. Uključi ako bi tvoj ekran mogli da vide drugi.',
    ],

    'save' => 'Sačuvaj podešavanja obaveštenja',
    'saved' => 'Sačuvano.',

    'other_devices' => [
        'summary' => 'Ostali uređaji',
        'empty' => 'Još nema drugih uparenih uređaja.',
        'unnamed' => 'Neimenovani uređaj',

        'summary_line' => 'podsetnici :reminders · podsticaji :nudges · sažetak :digest · uštede :savings',
        'on' => 'uključeno',
        'off' => 'isključeno',
    ],

    'errors' => [
        'save_failed' => 'Tvoja podešavanja obaveštenja nije bilo moguće sačuvati. Probaj ponovo.',
    ],
];
