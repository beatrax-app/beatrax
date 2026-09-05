<?php

declare(strict_types=1);

return [
    'too_new' => [
        'summary' => ':count change was made by a newer version of Beatrax|:count changes were made by a newer version of Beatrax',
        'body' => 'What was refused names something this version of Beatrax does not have, so this device had nowhere to put it. It is still on the device that made it, and nothing of yours was deleted.',
        'action' => 'Update Beatrax on this device. Changes made after the update arrive normally, but nothing already refused is sent again — make the change again here if you need it on this device too.',
    ],
    'untrusted_author' => [
        'summary' => ':count change was signed by a device this one does not recognise|:count changes were signed by a device this one does not recognise',
        'body' => 'What was refused came from a device that was never paired with this one, or from one you removed. Nothing was written here, and nothing that was already here was changed.',
        'action' => 'If you removed that device yourself, this is what removing it does and there is nothing to fix. If you did not, check the device list on this page.',
    ],
    'not_verified' => [
        'summary' => ':count change failed the security check on this device|:count changes failed the security check on this device',
        'body' => 'A signature did not match the device that claimed to have made the change, or the change was addressed to a different account. Nothing was written here. Between your own devices this should not happen.',
        'action' => 'Check the device list on this page and remove anything you do not recognise. If every device there is yours and this keeps happening, it is a fault in Beatrax rather than something you can put right from here.',
    ],
    'diverged' => [
        'summary' => ':count change from another device could not be saved here|:count changes from another device could not be saved here',
        'body' => 'Something arrived that this device could not store: a record that is missing part of itself, a date that does not exist, a split that no longer adds up, a record that two devices had already given the same identity, or a deletion for something still in use here. What was refused is on your other device and not on this one, so the two no longer hold the same thing.',
        'action' => 'Compare the record on your other device against what you see here and make the change again on this device — or delete it again here, if something you removed elsewhere is still present. Nothing refused is sent again on its own.',
    ],
    'last_seen' => 'Most recent: :when',
];
