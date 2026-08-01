<?php

declare(strict_types=1);

return [
    'page_title' => 'Manage :name · beatrax',
    'heading' => 'Manage :name',
    'subtitle' => 'View, reset, or regenerate codes for this user.',

    'set_password' => [
        'heading' => 'Set new password for this user',
        'description' => 'Their next sign-in will ask them to choose a password.',
        'open' => 'Set new password for this user',
        'body' => 'Set a new password for :name. Their next sign-in will ask them to choose a password.',
        'label' => 'New password',
        'submit' => 'Set password',
        'cancel' => 'Cancel',
    ],

    'regenerate' => [
        'heading' => 'Regenerate recovery codes for this user',
        'description' => 'Old codes will be invalidated.',
        'open' => 'Regenerate recovery codes for this user',
        'body' => 'Their existing unused codes will stop working. You will see the 10 new codes once and can hand them off.',
        'confirm_label' => 'Type the username to continue',
        'submit' => 'Regenerate codes',
        'keep' => 'Keep current codes',
        'download' => 'Download as .txt',
    ],

    'error_min_length' => 'Use at least 12 characters.',
    'password_set' => 'Password set for :name. Their next sign-in will ask them to choose a password.',
    'codes_regenerated' => 'Ten new recovery codes generated for :name.',
];
