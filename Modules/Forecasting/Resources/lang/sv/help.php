<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/forecasting/architecture.md#chain-aware-routing */
    'view_by_funder' => 'Vilket konto en prognostiserad betalning räknas mot. Vissa betalningar lämnar aldrig det konto de ser ut att lämna: ett kortköp regleras senare av en enda debitering på ditt bankkonto, och en plånboksbetalning finansieras av en överföring. Slår du på det här räknas var och en av dem mot kontot som faktiskt betalar den, så att ett kontos linje visar pengarna som verkligen kommer att passera genom det och inte de som bara gick förbi.',
];
