<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count alteração foi feita por uma versão mais recente do Beatrax|:count alterações foram feitas por uma versão mais recente do Beatrax',
        'body' => 'O que foi recusado refere algo que esta versão do Beatrax não tem, por isso este dispositivo não tinha onde o colocar. Continua no dispositivo que o fez e nada do que é teu foi eliminado.',
        'action' => 'Atualiza o Beatrax neste dispositivo. As alterações feitas depois da atualização chegam normalmente, mas nada que já tenha sido recusado volta a ser enviado — volta a fazer a alteração aqui se também precisares dela neste dispositivo.',
    ],
    'untrusted_author' => [
        'summary' => ':count alteração foi assinada por um dispositivo que este não reconhece|:count alterações foram assinadas por um dispositivo que este não reconhece',
        'body' => 'O que foi recusado veio de um dispositivo que nunca foi emparelhado com este, ou de um que removeste. Não foi escrito nada aqui e nada do que já cá estava foi alterado.',
        'action' => 'Se removeste esse dispositivo, é exatamente isso que remover faz e não há nada a corrigir. Se não foste tu, consulta a lista de dispositivos nesta página.',
    ],
    'not_verified' => [
        'summary' => ':count alteração não passou na verificação de segurança neste dispositivo|:count alterações não passaram na verificação de segurança neste dispositivo',
        'body' => 'Uma assinatura não correspondia ao dispositivo que dizia ter feito a alteração, ou a alteração estava dirigida a outra conta. Não foi escrito nada aqui. Entre os teus próprios dispositivos isto não devia acontecer.',
        'action' => 'Consulta a lista de dispositivos nesta página e remove tudo o que não reconheceres. Se todos os dispositivos aí forem teus e isto continuar a acontecer, é uma falha do Beatrax e não algo que possas corrigir daqui.',
    ],
    'diverged' => [
        'summary' => ':count alteração de outro dispositivo não pôde ser guardada aqui|:count alterações de outro dispositivo não puderam ser guardadas aqui',
        'body' => 'Chegou algo que este dispositivo não conseguiu guardar: um registo a que falta uma parte de si, uma data que não existe, uma divisão que já não bate certo, um registo a que dois dispositivos já tinham dado a mesma identidade, ou uma eliminação de algo que ainda está a ser usado aqui. O que foi recusado está no teu outro dispositivo e não neste, por isso os dois já não têm o mesmo.',
        'action' => 'Compara o registo do teu outro dispositivo com o que vês aqui e volta a fazer a alteração aqui — ou elimina-o outra vez aqui, se algo que removeste noutro sítio ainda cá estiver. Nada do que foi recusado é enviado de novo por si só.',
    ],
    'last_seen' => 'Mais recente: :when',
];
