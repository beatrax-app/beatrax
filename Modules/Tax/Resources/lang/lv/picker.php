<?php

declare(strict_types=1);

return [
    'dialog_aria' => 'Atzīmēt kā attaisnotos izdevumus',

    'note_label' => 'Piezīme',
    'note_optional' => '(neobligāti)',
    'note_placeholder' => 'Rēķina nr., datums vai cita atsauce…',

    'category_label' => 'Kategorija',
    'category_listbox_aria' => 'Atvieglojuma kategorija',
    'no_category' => 'Bez kategorijas',

    'new_category' => 'Jauna kategorija…',
    'new_category_placeholder' => 'Jaunās kategorijas nosaukums…',
    'new_category_aria' => 'Jaunās kategorijas nosaukums',
    'add' => 'Pievienot',
    'cancel' => 'Atcelt',
    'cancel_new_category_aria' => 'Atcelt jauno kategoriju',

    'assign_year' => 'Piešķirt taksācijas gadam:',

    'save' => 'Saglabāt',
    'remove_tag' => 'Noņemt atzīmi',

    'batch_before' => 'Vai atzīmēt vēl :count no',
    'batch_after' => '?',
    // i18n-review: lv · batch_confirm — Latvian selects arm 0 for zero, so the zero
    // form leads and the singular follows. The banner only appears from two rows up, so
    // that arm ships unread; a native should still check it stands on its own.
    'batch_confirm' => 'Vai atzīmēt vēl :count darījumu no :name :year taksācijas gadā kā attaisnotos izdevumus? Katrs no tiem saņem šo kategoriju un šo piezīmi. Pabeigta saskaņojuma darījumi paliek neskarti, un atzīmi pēc tam var noņemt tikai pa vienam darījumam.|Vai atzīmēt vēl :count darījumu no :name :year taksācijas gadā kā attaisnoto izdevumu? Tas saņem šo kategoriju un šo piezīmi. Pabeigta saskaņojuma darījumi paliek neskarti, un atzīmi pēc tam var noņemt tikai pa vienam darījumam.|Vai atzīmēt vēl :count darījumus no :name :year taksācijas gadā kā attaisnotos izdevumus? Katrs no tiem saņem šo kategoriju un šo piezīmi. Pabeigta saskaņojuma darījumi paliek neskarti, un atzīmi pēc tam var noņemt tikai pa vienam darījumam.',
    'batch_tag_all' => 'Atzīmēt visus',
    'batch_dismiss' => 'Aizvērt',
];
