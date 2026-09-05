<?php

declare(strict_types=1);

return [
    'about_body' => 'Ένα ενσωματωμένο αρχείο YAML που αντιστοιχίζει κρυπτικούς κωδικούς καταστάσεων κινήσεων σε φιλικά ονόματα εμπόρων. Με την ενεργοποίηση, το Beatrax διαβάζει τη λίστα όταν κάνεις εισαγωγή· η υποβολή πρότασης ανοίγει το GitHub στο πρόγραμμα περιήγησής σου.',

    'mappings' => ':count αντιστοίχιση|:count αντιστοιχίσεις',
    // i18n-review: el · contributors — the singular is the present participle
    // συνεισφέρων, which is the form the plural συνεισφέροντες implies but reads
    // formal standing beside a numeral in a caption.
    'contributors' => ':count συνεισφέρων|:count συνεισφέροντες',

    'use_shared_list' => [
        'title' => 'Χρήση της κοινής λίστας εμπόρων',
        'help' => 'Επίτρεψε στο Beatrax να διαβάζει την ενσωματωμένη λίστα για να συμπληρώνει φιλικά ονόματα σε εμπόρους που δεν έχεις μετονομάσει εσύ.',
    ],

    'offer_to_contribute' => [
        'title' => 'Πρόταση για συνεισφορά',
        'help' => 'Εμφάνισε το κουμπί «Βοήθησε άλλους να το αναγνωρίσουν» στη γραμμή διαλογής, ώστε να υποβάλλεις πρόταση στην κοινή λίστα με ένα κλικ.',
        // i18n-review: el · help_touch — the same line for a touch
        // screen; check the verb governs this case.
        'help_touch' => 'Εμφάνισε το κουμπί «Βοήθησε άλλους να το αναγνωρίσουν» στη γραμμή διαλογής, ώστε να υποβάλλεις πρόταση στην κοινή λίστα με ένα πάτημα.',
    ],

    // i18n-review: el · update_on_updates.note — "πλαϊνή στήλη" is the form
    // used here; Greek interfaces also say "πλαϊνή μπάρα", and this app names
    // the element in no other line, so a reader has no house term to match it to.
    'update_on_updates' => [
        'title' => 'Ενημέρωση της κοινής λίστας με τις ενημερώσεις της εφαρμογής',
        'help' => 'Ανανέωσε την ενσωματωμένη λίστα κάθε φορά που το Beatrax ενημερώνεται.',
        'help_phone' => 'Ανανέωσε την ενσωματωμένη λίστα κάθε φορά που εγκαθίσταται νέα έκδοση του Beatrax από το App Store ή το Google Play.',
        'note' => 'Ενεργοποιείται με μελλοντική ενημέρωση της εφαρμογής — η έκδοση που χρησιμοποιείς εμφανίζεται στο πάνω μέρος της πλαϊνής στήλης.',
    ],
];
