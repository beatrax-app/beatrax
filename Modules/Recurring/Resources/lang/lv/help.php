<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Pārskats ir plakans datumu un summu saraksts, un nekas tajā nepasaka, kuras rindas ir viena un tā pati regulārā saistība. Beatrax grupē rindas pēc tā, kam maksāts, atmet summas, kas no grupas izkrīt, un piedāvā sēriju tikai tad, kad atstarpes starp tām iekārtojas vienmērīgā nedēļas, mēneša, ceturkšņa vai gada ritmā — visu neregulārāko tā nepiedāvā vispār. Atpakaļ tā lasa tikai tik tālu, cik atļauj „:setting“ iestatījumos, un tas sākas ar īsāko posmu, ar kādu vispār var strādāt, tāpēc ikgadējs rēķins paliek neredzams, līdz to paplašini. Ar taviem datiem šeit nekas nenotiek, kamēr neesi apstiprinājis.',
];
