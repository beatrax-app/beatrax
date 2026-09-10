<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Eno plačilo pogosto plača več drugih: obračun kartice na bančnem računu pokrije mesec nakupov s kartico, dvig z banke pa financira plačilo iz denarnice, opravljeno nekaj dni prej. Veriga zabeleži, katera bremenitev je plačala kaj, tako da je nakup na enem izpisku mogoče izslediti do denarja, ki je res odšel s tvojega računa. Beatrax gotove primere poveže sam, ostale pa pusti v vrsti za pregled tebi. Nekajkrat potrdi isto vrsto povezave in za to vrsto te ne bo več spraševal.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Namig je pol povezave: Beatrax je našel eno stran plačilne verige, druge pa ne, zato je zapisal, kar je videl, namesto da bi preostalo ugibal. Večina se razreši sama — poravnava tu čaka, dokler se ne uvozijo posamezne bremenitve, ki jih je plačala, in tedaj postane prava veriga. Ostale lahko mirno zavrneš; v tvojih zapisih se tako ali tako nič ne spremeni.',
];
