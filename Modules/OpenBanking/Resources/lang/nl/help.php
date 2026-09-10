<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/open-banking/consent-window.md#why-the-band-is-14-days-and-the-span-is-180 */
    'consent_status' => 'De toestemming die je bank Beatrax gaf om deze rekening te lezen. Banken zijn verplicht die te laten verlopen: deze toestemming duurt 180 dagen, twee weken van tevoren slaat de status om naar ‘:expiring’, en zolang hij verlopen is wordt er niets opgehaald — met ‘:reconnect’ log je opnieuw in bij je bank en beginnen er 180 nieuwe dagen. Je bank kan hem ook eerder beëindigen vanuit haar eigen app, en wat al is ingelezen gaat hoe dan ook niet verloren.',
];
