<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count sprememba je bila narejena z novejšo različico Beatraxa|:count spremembi sta bili narejeni z novejšo različico Beatraxa|:count spremembe so bile narejene z novejšo različico Beatraxa|:count sprememb je bilo narejenih z novejšo različico Beatraxa',
        'body' => 'Tisto, kar je bilo zavrnjeno, navaja nekaj, česar ta različica Beatraxa nima, zato tega ta naprava ni imela kam shraniti. Še vedno je na napravi, ki je to naredila, in nič tvojega ni bilo izbrisano.',
        'action' => 'Posodobi Beatrax na tej napravi. Spremembe, narejene po posodobitvi, prispejo običajno, ničesar že zavrnjenega pa nič ne pošlje znova — če spremembo potrebuješ tudi na tej napravi, jo naredi tukaj še enkrat.',
    ],
    'untrusted_author' => [
        'summary' => ':count spremembo je podpisala naprava, ki je ta ne prepozna|:count spremembi je podpisala naprava, ki je ta ne prepozna|:count spremembe je podpisala naprava, ki je ta ne prepozna|:count sprememb je podpisala naprava, ki je ta ne prepozna',
        'body' => 'Tisto, kar je bilo zavrnjeno, je prišlo z naprave, ki s to ni bila nikoli seznanjena, ali z naprave, ki si jo odstranil. Sem ni bilo nič zapisanega in nič, kar je bilo tu že prej, ni bilo spremenjeno.',
        'action' => 'Če si to napravo odstranil sam, je to natanko tisto, kar odstranitev naredi, in ni ničesar za popraviti. Če je nisi, poglej seznam naprav na tej strani.',
    ],
    'not_verified' => [
        'summary' => ':count sprememba ni prestala varnostnega preverjanja na tej napravi|:count spremembi nista prestali varnostnega preverjanja na tej napravi|:count spremembe niso prestale varnostnega preverjanja na tej napravi|:count sprememb ni prestalo varnostnega preverjanja na tej napravi',
        'body' => 'Podpis se ni ujemal z napravo, ki je trdila, da je naredila spremembo, ali pa je bila sprememba naslovljena na drug račun. Sem ni bilo nič zapisanega. Med tvojimi napravami se to ne bi smelo dogajati.',
        'action' => 'Poglej seznam naprav na tej strani in odstrani vse, česar ne prepoznaš. Če je vsaka naprava tam tvoja in se to še naprej dogaja, gre za napako v Beatraxu in ne za nekaj, kar bi lahko uredil od tu.',
    ],
    'diverged' => [
        'summary' => ':count sprememba z druge naprave se tukaj ni mogla shraniti|:count spremembi z druge naprave se tukaj nista mogli shraniti|:count spremembe z druge naprave se tukaj niso mogle shraniti|:count sprememb z druge naprave se tukaj ni moglo shraniti',
        'body' => 'Prispelo je nekaj, česar ta naprava ni mogla shraniti: zapis, ki mu manjka del njega samega, datum, ki ne obstaja, razdelitev, ki se ne izide več, zapis, ki sta mu dve napravi že dali isto identiteto, ali izbris nečesa, kar je tu še v uporabi. Tisto, kar je bilo zavrnjeno, je na tvoji drugi napravi in ne na tej, zato napravi ne vsebujeta več istega.',
        'action' => 'Primerjaj zapis na svoji drugi napravi s tem, kar vidiš tukaj, in spremembo tukaj naredi znova — ali jo tukaj znova izbriši, če je nekaj, kar si odstranil drugje, še vedno tu. Nič zavrnjenega se samo od sebe ne pošlje znova.',
    ],
    'last_seen' => 'Najnovejše: :when',
];
