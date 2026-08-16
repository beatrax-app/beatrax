<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Le déverrouillage biométrique n\'est pas disponible sur cet appareil.',
    'error_enroll_locked' => 'Déverrouille l\'app avant d\'activer la biométrie.',
    'error_enroll_failed' => 'Ton appareil a refusé de stocker la clé. Le déverrouillage biométrique est indisponible.',
    'heading' => 'Verrouillage de l\'app',

    'moved_help' => 'Ton PIN, le délai de verrouillage automatique et le déverrouillage biométrique se trouvent dans les paramètres de synchronisation de cet appareil.',
    'moved_cta' => 'Ouvrir Synchronisation et appareil',

    'toggle_label' => 'Verrouiller l\'app avec un PIN',
    'toggle_description' => 'Remplace la connexion quotidienne par un PIN. Les sessions restent actives 30 jours.',

    'setup_heading' => 'Définis un PIN pour activer le verrouillage',
    'new_pin_label' => 'Nouveau PIN (4–10 chiffres)',
    'confirm_pin_label' => 'Confirme le PIN',
    'account_password_label' => 'Mot de passe du compte',
    'account_password_note' => '(nécessaire pour créer une clé de récupération)',
    'account_password_placeholder' => 'Le mot de passe de ton compte',
    'set_pin' => 'Définir le PIN',

    'pin_row_label' => 'PIN',
    'pin_row_description' => 'Change ton PIN actuel.',
    'change_pin' => 'Changer le PIN',
    'forgot_pin_link' => 'PIN oublié ? Réinitialise-le avec le mot de passe de ton compte.',

    'biometric_enrolled_description' => 'Le déverrouillage biométrique est activé sur cet appareil.',
    'biometric_enroll_description' => 'Active le déverrouillage biométrique sur cet appareil.',
    'remove' => 'Retirer',
    'enroll' => 'Activer',
    'biometric_unavailable' => 'Le déverrouillage biométrique n\'est pas disponible sur cet appareil.',

    'deenroll_modal_heading' => 'Retirer le déverrouillage biométrique — confirme avec ton PIN',
    'current_pin_label' => 'PIN actuel',
    'remove_biometric' => 'Retirer la biométrie',
    'keep_biometric' => 'Conserver la biométrie',

    'auto_lock' => 'Verrouillage auto après',
    'idle_1' => '1 minute',
    'idle_5' => '5 minutes',
    'idle_15' => '15 minutes',
    'idle_30' => '30 minutes',

    'disable_modal_heading' => 'Désactiver le verrouillage de l\'app — confirme avec ton PIN',
    'disable_lock' => 'Désactiver le verrouillage',
    'keep_lock' => 'Conserver le verrouillage',

    'forgot_modal_heading' => 'Réinitialiser le PIN — confirme avec le mot de passe du compte',
    'forgot_modal_body' => 'Le mot de passe de ton compte récupère la clé de verrouillage : réinitialiser le PIN ne fait donc jamais perdre de données.',
    'confirm_new_pin_label' => 'Confirme le nouveau PIN',
    'reset_pin' => 'Réinitialiser le PIN',
    'cancel' => 'Annuler',

    'change_modal_heading' => 'Changer le PIN — confirme avec le PIN actuel',
    'keep_pin' => 'Conserver le PIN',

    'error_pin_too_short' => 'Le PIN doit comporter au moins 4 chiffres.',
    'error_pin_mismatch' => 'Les PIN ne correspondent pas. Réessaie.',
    'error_pin_incorrect' => 'PIN incorrect.',
    'error_account_password' => 'Mot de passe du compte incorrect.',
    'change_pin_success' => 'Ta clé de chiffrement est de nouveau protégée, avec ton nouveau PIN.',
    'error_forgot_failed' => 'Échec de la réinitialisation du PIN — la clé de récupération est indisponible.',
    'error_enable_first' => 'Active d\'abord le verrouillage par PIN avant la biométrie.',
];
