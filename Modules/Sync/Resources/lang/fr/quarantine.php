<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count changement a été fait par une version plus récente de Beatrax|:count changements ont été faits par une version plus récente de Beatrax',
        'body' => "Ce qui a été refusé désigne quelque chose que cette version de Beatrax n'a pas, donc cet appareil n'avait nulle part où le mettre. Cela se trouve toujours sur l'appareil qui l'a fait, et rien de ce qui t'appartient n'a été supprimé.",
        'action' => "Mets Beatrax à jour sur cet appareil. Les changements faits après la mise à jour arrivent normalement, mais rien de ce qui a déjà été refusé n'est renvoyé — refais le changement ici si tu en as besoin sur cet appareil aussi.",
    ],
    'untrusted_author' => [
        'summary' => ':count changement a été signé par un appareil que celui-ci ne reconnaît pas|:count changements ont été signés par un appareil que celui-ci ne reconnaît pas',
        'body' => "Ce qui a été refusé venait d'un appareil qui n'a jamais été appairé avec celui-ci, ou d'un appareil que tu as supprimé. Rien n'a été écrit ici, et rien de ce qui s'y trouvait déjà n'a été modifié.",
        'action' => "Si tu as supprimé cet appareil toi-même, c'est exactement ce que fait une suppression et il n'y a rien à réparer. Sinon, regarde la liste des appareils sur cette page.",
    ],
    'not_verified' => [
        'summary' => ":count changement n'a pas passé le contrôle de sécurité sur cet appareil|:count changements n'ont pas passé le contrôle de sécurité sur cet appareil",
        'body' => "Une signature ne correspondait pas à l'appareil qui prétendait avoir fait le changement, ou le changement était adressé à un autre compte. Rien n'a été écrit ici. Entre tes propres appareils, cela ne devrait pas arriver.",
        'action' => "Regarde la liste des appareils sur cette page et supprime tout ce que tu ne reconnais pas. Si chaque appareil qui s'y trouve est le tien et que cela continue, c'est un défaut de Beatrax et non quelque chose que tu peux corriger d'ici.",
    ],
    'diverged' => [
        'summary' => ":count changement venant d'un autre appareil n'a pas pu être enregistré ici|:count changements venant d'un autre appareil n'ont pas pu être enregistrés ici",
        'body' => "Quelque chose est arrivé que cet appareil ne pouvait pas stocker : un enregistrement auquel il manque une partie de lui-même, une date qui n'existe pas, une ventilation qui ne tombe plus juste, un enregistrement auquel deux appareils avaient déjà donné la même identité, ou une suppression visant quelque chose encore utilisé ici. Ce qui a été refusé est sur ton autre appareil et pas sur celui-ci, donc les deux ne contiennent plus la même chose.",
        'action' => "Compare l'enregistrement sur ton autre appareil avec ce que tu vois ici et refais le changement ici — ou supprime-le de nouveau ici, si quelque chose que tu as supprimé ailleurs est encore là. Ce qui a été refusé n'est pas renvoyé de soi-même.",
    ],
    'last_seen' => 'Le plus récent : :when',
];
