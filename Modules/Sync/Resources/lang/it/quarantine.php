<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count modifica è stata fatta da una versione più recente di Beatrax|:count modifiche sono state fatte da una versione più recente di Beatrax',
        'body' => "Ciò che è stato rifiutato nomina qualcosa che questa versione di Beatrax non ha, quindi questo dispositivo non aveva dove metterlo. Resta sul dispositivo che l'ha fatto e nulla di tuo è stato eliminato.",
        'action' => "Aggiorna Beatrax su questo dispositivo. Le modifiche fatte dopo l'aggiornamento arrivano normalmente, ma nulla di già rifiutato viene inviato di nuovo — rifai la modifica qui se ti serve anche su questo dispositivo.",
    ],
    'untrusted_author' => [
        'summary' => ':count modifica è stata firmata da un dispositivo che questo non riconosce|:count modifiche sono state firmate da un dispositivo che questo non riconosce',
        'body' => 'Ciò che è stato rifiutato arrivava da un dispositivo mai abbinato a questo, oppure da uno che hai rimosso. Qui non è stato scritto nulla e nulla di ciò che era già qui è stato cambiato.',
        'action' => "Se hai rimosso tu quel dispositivo, è esattamente ciò che comporta rimuoverlo e non c'è nulla da sistemare. In caso contrario, controlla l'elenco dei dispositivi in questa pagina.",
    ],
    'not_verified' => [
        'summary' => ':count modifica non ha superato il controllo di sicurezza su questo dispositivo|:count modifiche non hanno superato il controllo di sicurezza su questo dispositivo',
        'body' => 'Una firma non corrispondeva al dispositivo che diceva di aver fatto la modifica, oppure la modifica era indirizzata a un altro account. Qui non è stato scritto nulla. Tra i tuoi dispositivi questo non dovrebbe succedere.',
        'action' => "Controlla l'elenco dei dispositivi in questa pagina e rimuovi tutto ciò che non riconosci. Se ogni dispositivo lì è tuo e questo continua a succedere, è un difetto di Beatrax e non qualcosa che puoi risolvere da qui.",
    ],
    'diverged' => [
        'summary' => ':count modifica da un altro dispositivo non è stata salvata qui|:count modifiche da un altro dispositivo non sono state salvate qui',
        'body' => "È arrivato qualcosa che questo dispositivo non è riuscito a memorizzare: un record a cui manca una parte di sé, una data che non esiste, una suddivisione che non torna più, un record a cui due dispositivi avevano già dato la stessa identità, oppure un'eliminazione di qualcosa ancora in uso qui. Ciò che è stato rifiutato è sull'altro tuo dispositivo e non su questo, quindi i due non contengono più la stessa cosa.",
        'action' => "Confronta il record sull'altro tuo dispositivo con quello che vedi qui e rifai la modifica qui — oppure eliminalo di nuovo qui, se qualcosa che hai rimosso altrove è ancora presente. Ciò che è stato rifiutato non viene inviato di nuovo da solo.",
    ],
    'last_seen' => 'Più recente: :when',
];
