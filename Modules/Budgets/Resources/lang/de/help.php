<?php

declare(strict_types=1);

return [
    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'ready_to_assign' => 'Geld, das eingegangen ist und noch keinen Umschlag hat: die Einnahmen dieser Periode, plus alles, was in der letzten Periode nicht zugewiesen wurde, minus alles, was unten zugewiesen ist. Bring es auf null, dann bleibt nichts ungeplant. Unter null heißt, dass mehr zugewiesen wurde, als tatsächlich eingegangen ist — nimm etwas aus einem Umschlag zurück oder warte auf den nächsten Zahltag.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'if_overspent' => 'Was mit einem Umschlag geschieht, der mehr ausgegeben hat, als er enthält, sobald die Periode endet. Mit „:reduce“ wird der Fehlbetrag zuerst von dem abgezogen, was in der nächsten Periode zu verteilen ist, und der Umschlag selbst beginnt wieder bei null. Mit „:carry“ bleibt der Fehlbetrag dort, wo er entstanden ist: dieser Umschlag startet unter null und muss erst wieder aufgefüllt werden, bevor er etwas bezahlt, und am übrigen Plan ändert sich nichts.',

    /** @link ../../../../../.docs/features/budgets/architecture.md#the-genesis-to-target-fold-carryoverquery */
    'available' => 'Was dieser Umschlag noch bezahlen kann: „:assigned“ für diese Periode, plus „:carried“, plus oder minus „:moved“, minus „:spent“ — die vier Spalten links davon. Es ist kein Kontostand: mehrere Umschläge greifen auf dasselbe Konto zu, und diese Zahl spricht nur für diesen einen. Unter null hat der Umschlag bereits mehr ausgegeben, als er enthält, und das Ende der Periode entscheidet, was mit diesem Fehlbetrag geschieht.',
];
