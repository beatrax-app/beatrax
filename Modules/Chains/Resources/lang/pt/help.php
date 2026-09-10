<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/chains/architecture.md#what-this-module-is-for */
    'index' => 'Um pagamento paga muitas vezes vários outros: o acerto do cartão na conta bancária cobre um mês de compras com cartão, e um levantamento do banco financia um pagamento por carteira feito dias antes. Uma cadeia regista que débito pagou o quê, para que uma compra num extrato possa ser seguida até ao dinheiro que saiu mesmo da tua conta. O Beatrax liga sozinho os casos de que tem a certeza e deixa os restantes na fila de revisão. Confirma o mesmo tipo de ligação algumas vezes e ele deixa de perguntar por esse tipo.',

    /** @link ../../../../../.docs/features/chains/architecture.md#a-hint-does-not-outlive-the-gap-it-described */
    'hints' => 'Uma pista é meia ligação: o Beatrax encontrou um lado de uma cadeia de pagamentos e não o outro, por isso anotou o que viu em vez de adivinhar o resto. A maioria resolve-se sozinha — uma liquidação fica aqui à espera até que os débitos individuais que pagou sejam importados, e então torna-se uma cadeia a sério. As restantes podem ser dispensadas sem risco, e de qualquer forma nada muda no teu registo.',
];
