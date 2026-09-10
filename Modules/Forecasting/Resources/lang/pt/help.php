<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'A que conta é imputado um pagamento previsto. Alguns pagamentos nunca saem da conta de onde parecem sair: uma compra com cartão é liquidada mais tarde por um único débito na tua conta bancária, e um pagamento por carteira é alimentado por uma transferência. Com isto ligado, cada um deles é imputado à conta que realmente o paga, por isso a linha de uma conta mostra o dinheiro que vai mesmo passar por ela e não o que apenas passou ao lado.',
];
