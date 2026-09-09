<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/auth/app-lock-data-key-lifetime.md#the-recovery-wrap-is-built-from-the-password-so-a-password-change-has-to-carry-it */
    // i18n-review: hu · forgot_pin — "Nem vész el adat" matches the same clause in
    // app_lock forgot_modal_body, so it is at least consistent; standing alone
    // without "soha" it is terse, and a native may want "Semmilyen adat nem vész
    // el" instead.
    'forgot_pin' => 'Ha a fiókjelszavad még nyitja ezt a zárat, újra bejelentkezhetsz, beállíthatsz egy új PIN-kódot, és semmi nem vész el. Az a jelszó, amelyet helyreállítási kóddal állítottál vissza, vagy amelyet a fiók tulajdonosa állított be neked, már nem nyitja.',
];
