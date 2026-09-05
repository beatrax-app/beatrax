<?php

declare(strict_types=1);

// iOS reads these out of the app bundle before any PHP runs, keyed by
// Info.plist key rather than by a translation key, and nothing renders them, so
// they sit outside Resources/lang/ where every line must have a call site. The
// reader is a build script that runs with no framework and no translator.
/**
 * @link ../../../../../../.docs/features/mobile/a-purpose-string-in-every-language.md
 */

return [
    'NSCameraUsageDescription' => 'Το Beatrax χρησιμοποιεί την κάμερα για να σαρώσει τον κωδικό σύζευξης που εμφανίζεται στην άλλη σου συσκευή. Τίποτα δεν φωτογραφίζεται ούτε αποθηκεύεται.',
    'NSFaceIDUsageDescription' => 'Το Beatrax χρησιμοποιεί το Face ID για να ξεκλειδώσει τα οικονομικά σου και να απελευθερώσει το κλειδί με το οποίο είναι κρυπτογραφημένα τα δεδομένα σου.',
    'NSLocalNetworkUsageDescription' => 'Το Beatrax χρησιμοποιεί το τοπικό σου δίκτυο για να συγχρονίζει τα οικονομικά σου απευθείας με τις άλλες σου συσκευές Beatrax — τίποτα δεν βγαίνει από το οικιακό σου δίκτυο γι᾽ αυτό.',
];
