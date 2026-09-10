<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'O singură plată plătește adesea pentru mai multe altele: decontarea cardului pe contul bancar acoperă o lună de cumpărături cu cardul, iar o retragere de la bancă finanțează o plată din portofel făcută cu câteva zile înainte. Un lanț înregistrează ce debit a plătit ce anume, astfel încât o cumpărătură dintr-un extras poate fi urmărită până la banii care chiar au ieșit din contul tău. Beatrax leagă singur cazurile sigure și le lasă pe celelalte în coada de verificare pentru tine. Confirmă de câteva ori același fel de legătură și nu va mai întreba despre acel fel.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'O sugestie este jumătate de legătură: Beatrax a găsit un capăt al unui lanț de plăți și nu și pe celălalt, așa că a notat ce a văzut în loc să ghicească restul. Cele mai multe se rezolvă singure — o decontare așteaptă aici până când sunt importate cheltuielile individuale pe care le-a plătit, și atunci devine un lanț adevărat. Pe celelalte le poți respinge liniștit; în evidențele tale nu se schimbă nimic în niciun caz.',
];
