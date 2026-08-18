<?php

declare(strict_types=1);

return [
    'page_title' => 'Tranzakció',
    'heading' => 'Tranzakció',

    'counterparty' => 'Partner',
    'amount_native' => 'Összeg (eredeti deviza)',
    'amount_settled' => 'Összeg (elszámolt EUR)',
    'effective_rate' => 'Tényleges árfolyam',
    'ics_markup' => 'Tartalmazza az esetleges ICS-felárat.',

    'split' => [
        'category' => 'Kategória',
        'open' => 'Felosztás kategóriákra',
        'heading' => 'Felosztás kategóriák között',
        'total' => 'Összesen :amount',
        'tax_per_category' => 'Az adócímkéket lent, kategóriánként állíthatod be.',
        'choose_category' => 'Válassz kategóriát',
        'note_label' => 'Megjegyzés',
        'note_placeholder' => 'Megjegyzés (opcionális)',
        'tax_deductible' => 'Adóból leírható',
        'remove_leg_aria' => 'Ennek a kategóriának az eltávolítása',
        'add_category' => '+ Kategória hozzáadása',
        'soft_cap' => ':count / ~20 kategória — érdemes összevonni a kis összegeket.',
        'remaining_zero' => 'Hátralévő :amount ✓',
        'remaining_to_assign' => 'Még kiosztható: :amount',
        'over_allocated' => 'Túlosztás :amount értékkel — csökkents egy tételt.',
        'save' => 'Felosztás mentése',
        'saving' => 'Mentés…',
        'unsplit' => 'Felosztás megszüntetése',
        'remove_to_one' => 'Ha ezt eltávolítod, egy kategória marad — a tranzakció ez lesz: :category.',
        'remove_to_one_fallback' => 'ez a kategória',
        'remove_category' => 'Kategória eltávolítása',
        'keep_category' => 'Kategória megtartása',
        'restore_single' => 'Visszaállítod egyetlen kategóriára?',
        'confirm_unsplit' => 'Igen, megszüntetem',
        'keep_split' => 'Felosztás megtartása',
    ],

    'tax' => [
        'section_aria' => 'Adócímke',
        'label' => 'Adóból leírható',
    ],

    'reclassify' => [
        'heading' => 'Újrabesorolás',
        'help' => 'Írd felül az észlelt típust. Ha ez a tranzakció párban áll egy másikkal, az átutalástól eltérő típus választása mindkét oldal párosítását megszünteti.',
        'choose_aria' => 'Új tranzakciótípus választása',
        'choose_option' => 'Válassz típust…',
        'save' => 'Mentés',
    ],

    'note' => [
        'heading' => 'Megjegyzés',
        'help' => 'Személyes megjegyzés ehhez a tranzakcióhoz. Csak te látod.',
        'label' => 'Megjegyzés',
        'placeholder' => 'Megjegyzés hozzáadása…',
        'save' => 'Megjegyzés mentése',
        'saved' => 'Mentve',
    ],

    'reassign' => [
        'heading' => 'Partner újbóli hozzárendelése',
        'help' => 'Írd felül a felismert partnert ennél a tranzakciónál.',
        'choose_aria' => 'Partner választása',
        'choose_option' => 'Válassz partnert…',
        'submit' => 'Hozzárendelés',
    ],

    'goal' => [
        'heading' => 'Megtakarítási cél',
        'help' => 'Számítsd bele ezt a tranzakciót valamelyik megtakarítási célodba.',
        'choose_aria' => 'Válassz megtakarítási célt',
        'choose_option' => 'Válassz célt…',
        'submit' => 'Hozzáadás a célhoz',
        'remove_aria' => ':name eltávolítása',
    ],

    'delete' => [
        'heading' => 'Tranzakció törlése',
        'help' => 'Véglegesen eltávolítja ezt a tranzakciót. A művelet nem vonható vissza.',
        'button' => 'Törlés',
        'confirm_prompt' => 'Biztos vagy benne?',
        'confirm' => 'Igen, törlöm',
        'cancel' => 'Mégse',
    ],

    'chain' => [
        'view' => 'Lánc megtekintése',
    ],

    'toast' => [
        'reconciled_locked' => 'Ez a tranzakció egyeztetve van. A módosításhoz szüntesd meg az egyeztetést.',
        'reclassified_pair_removed' => 'Újrabesorolva: :type — a párosítás megszüntetve',
        'reclassified' => 'Újrabesorolva: :type',
        'note_saved' => 'Megjegyzés mentve',
        'unreconciled' => 'Egyeztetés megszüntetve — újra szerkesztheted ezt a tranzakciót.',
        'counterparty_updated' => 'Partner frissítve',
        'goal_attributed' => 'Beleszámít ebbe a célba',
        'goal_attribution_removed' => 'Már nem számít bele ebbe a célba',
        'split_saved' => 'Felosztás mentve',
        'removed_one_remains' => 'Eltávolítva — egy kategória maradt',
        'unsplit_restored' => 'Felosztás megszüntetve — visszaállítva egyetlen kategóriára',
    ],

    'errors' => [
        'totals_must_match' => 'A mentés nem sikerült — a tételek összegének pontosan egyeznie kell a tranzakció végösszegével.',
        'not_found' => 'A tranzakció nem található.',
        'amount_zero' => 'Az összeg nem lehet €0,00',
        'choose_category' => 'Válassz kategóriát.',
        'choose_before_removing' => 'Az eltávolítás előtt válassz kategóriát.',
        'choose_before_unsplitting' => 'A felosztás megszüntetése előtt válassz kategóriát.',
        'not_found_or_unowned' => 'A tranzakció nem található, vagy nem a felhasználóhoz tartozik.',
        'reconciled_split' => 'Ez a tranzakció egyeztetve van. A felosztás módosításához szüntesd meg az egyeztetést.',
        'not_splittable' => "A(z) ':type' tranzakciótípus nem osztható fel.",
        'min_two_legs' => 'A felosztáshoz legalább 2 tétel szükséges.',
        'legs_non_zero' => 'A tételek összege nem lehet nulla.',
        'legs_parent_sign' => 'A tételek összegének a fő tranzakcióval azonos előjelűnek kell lennie.',
        'leg_category_not_accessible' => 'A tétel kategóriája nem található, vagy a felhasználó nem fér hozzá.',
        'survivor_not_accessible' => 'A megmaradó kategória nem található, vagy a felhasználó nem fér hozzá.',
        'survivor_must_be_current' => 'A megmaradó kategóriának a felosztás jelenlegi tételkategóriái közül kell kikerülnie.',
    ],
];
