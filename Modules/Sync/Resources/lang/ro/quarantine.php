<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count modificare a fost făcută de o versiune mai nouă a Beatrax|:count modificări au fost făcute de o versiune mai nouă a Beatrax|:count de modificări au fost făcute de o versiune mai nouă a Beatrax',
        'body' => 'Ceea ce a fost refuzat indică ceva ce această versiune a Beatrax nu are, așa că acest dispozitiv nu avea unde să îl pună. Se află în continuare pe dispozitivul care l-a făcut și nimic din ce este al tău nu a fost șters.',
        'action' => 'Actualizează Beatrax pe acest dispozitiv. Modificările făcute după actualizare ajung normal, dar nimic din ce a fost deja refuzat nu mai este trimis din nou — fă modificarea aici încă o dată dacă îți trebuie și pe acest dispozitiv.',
    ],
    'untrusted_author' => [
        'summary' => ':count modificare a fost semnată de un dispozitiv pe care acesta nu îl recunoaște|:count modificări au fost semnate de un dispozitiv pe care acesta nu îl recunoaște|:count de modificări au fost semnate de un dispozitiv pe care acesta nu îl recunoaște',
        'body' => 'Ceea ce a fost refuzat a venit de la un dispozitiv care nu a fost niciodată împerecheat cu acesta sau de la unul pe care l-ai eliminat. Aici nu s-a scris nimic și nimic din ce era deja aici nu s-a schimbat.',
        'action' => 'Dacă ai eliminat tu acel dispozitiv, exact asta face eliminarea și nu este nimic de reparat. Dacă nu, uită-te la lista de dispozitive de pe această pagină.',
    ],
    'not_verified' => [
        'summary' => ':count modificare nu a trecut verificarea de securitate pe acest dispozitiv|:count modificări nu au trecut verificarea de securitate pe acest dispozitiv|:count de modificări nu au trecut verificarea de securitate pe acest dispozitiv',
        'body' => 'O semnătură nu s-a potrivit cu dispozitivul care susținea că a făcut modificarea sau modificarea era adresată altui cont. Aici nu s-a scris nimic. Între propriile tale dispozitive acest lucru nu ar trebui să se întâmple.',
        'action' => 'Uită-te la lista de dispozitive de pe această pagină și elimină tot ce nu recunoști. Dacă fiecare dispozitiv de acolo este al tău și acest lucru continuă, este o defecțiune a aplicației Beatrax, nu ceva ce poți îndrepta de aici.',
    ],
    'diverged' => [
        // i18n-review: ro · diverged.summary — the third arm carries the "de" a
        // numeral from 20 up requires, and it lands straight in front of "de la
        // alt dispozitiv": "21 de modificări de la alt dispozitiv". A native eye
        // should say whether that stacking wants a different frame.
        'summary' => ':count modificare de la alt dispozitiv nu a putut fi salvată aici|:count modificări de la alt dispozitiv nu au putut fi salvate aici|:count de modificări de la alt dispozitiv nu au putut fi salvate aici',
        'body' => 'A sosit ceva ce acest dispozitiv nu a putut stoca: o înregistrare căreia îi lipsește o parte din ea însăși, o dată care nu există, o împărțire care nu mai iese la socoteală, o înregistrare căreia două dispozitive îi dăduseră deja aceeași identitate sau o ștergere pentru ceva încă folosit aici. Ceea ce a fost refuzat se află pe celălalt dispozitiv al tău și nu pe acesta, așa că cele două nu mai conțin același lucru.',
        'action' => 'Compară înregistrarea de pe celălalt dispozitiv al tău cu ce vezi aici și fă modificarea din nou aici — sau șterge-o din nou aici, dacă ceva ce ai eliminat în altă parte este încă aici. Nimic refuzat nu se retrimite de la sine.',
    ],
    'last_seen' => 'Cel mai recent: :when',
];
