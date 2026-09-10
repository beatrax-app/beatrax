<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'Bankanın Beatrax’a bu hesabı okuma izni. Bankalar bu izni süreli tutmak zorundadır: bu onay 180 gün sürer, iki hafta kala durum “:expiring” olur ve süresi dolduğu sürece hiçbir şey çekilmez — “:reconnect” seni bankanda yeniden oturum açtırır ve yeni bir 180 gün başlatır. Bankan onu kendi uygulamasından daha erken de sonlandırabilir; içeri aktarılmış olan hiçbir şey her iki durumda da kaybolmaz.',
];
