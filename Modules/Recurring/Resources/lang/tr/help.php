<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/recurring/series-detection.md#the-pipeline */
    'review' => 'Ekstre, tarihlerden ve tutarlardan oluşan düz bir listedir; içinde hangi satırların aynı düzenli taahhüt olduğunu söyleyen hiçbir şey yoktur. Beatrax satırları kime ödendiğine göre gruplar, grubun geri kalanından sapan tutarları eler ve ancak aralar düzenli bir haftalık, aylık, üç aylık veya yıllık ritme oturduğunda bir seri önerir; bundan düzensiz olan hiçbir şey önerilmez. Geriye yalnızca ayarlardaki “:setting” kadar bakar; o da çalışabildiği en kısa süreden başlar, dolayısıyla yıllık bir fatura sen bu süreyi genişletene kadar görünmez kalır. Sen onaylamadan burada verilerine hiçbir şey uygulanmaz.',
];
