<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Um pagamento paga muitas vezes vários outros: o acerto do cartão na conta bancária cobre um mês de compras com cartão, e um levantamento do banco financia um pagamento por carteira feito dias antes. Uma cadeia regista que débito pagou o quê, para que uma compra num extrato possa ser seguida até ao dinheiro que saiu mesmo da tua conta. O Beatrax liga sozinho os casos de que tem a certeza e deixa os restantes na fila de revisão. Confirma o mesmo tipo de ligação algumas vezes e ele deixa de perguntar por esse tipo.',
];
