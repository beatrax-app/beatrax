<?php

declare(strict_types=1);

return [
    'error_enroll_unsupported' => 'Cette version de Beatrax n\'a nulle part où ranger une clé de déverrouillage, le déverrouillage biométrique n\'est donc pas proposé. La limite n\'est pas ton appareil.',
    'error_enroll_unprotected' => 'Le déverrouillage biométrique a besoin d\'un magasin de clés du système d\'exploitation, et cette installation n\'en a pas. L\'enrôlement laisserait la clé de déverrouillage lisible à côté de tes données, il n\'est donc pas proposé ici.',
    'error_enroll_locked' => 'Déverrouille l\'app avant d\'activer la biométrie.',
    'error_enroll_failed' => 'Ton appareil a refusé de stocker la clé. Le déverrouillage biométrique est indisponible.',
    'heading' => 'Verrouillage de l\'app',

    'toggle_label' => 'Verrouiller l\'app avec un PIN',
    'toggle_description' => 'Remplace la connexion quotidienne par un PIN. Les sessions restent actives 30 jours.',

    'setup_heading' => 'Définis un PIN pour activer le verrouillage',
    'new_pin_label' => 'Nouveau PIN (6–10 chiffres)',
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
    'biometric_unavailable' => 'Cette version de Beatrax ne peut pas proposer le déverrouillage biométrique. Ton PIN est ici le seul déverrouillage.',

    'deenroll_modal_heading' => 'Retirer le déverrouillage biométrique — confirme avec ton PIN',
    'current_pin_label' => 'PIN actuel',
    'remove_biometric' => 'Retirer la biométrie',
    'keep_biometric' => 'Conserver la biométrie',

    'auto_lock' => 'Verrouillage auto après',
    'auto_lock_note' => 'Beatrax se verrouille après ce délai sans activité — et plus tôt si tu le quittes : passer à une autre app, ou masquer ou fermer la fenêtre, verrouille Beatrax en moins de :window, quel que soit ce paramètre.',
    'idle_1' => '1 minute',
    'idle_5' => '5 minutes',
    'idle_15' => '15 minutes',
    'idle_30' => '30 minutes',

    'disable_modal_heading' => 'Désactiver le verrouillage de l\'app — confirme avec ton PIN',
    'disable_lock' => 'Désactiver le verrouillage',
    'keep_lock' => 'Conserver le verrouillage',

    'forgot_modal_heading' => 'Réinitialiser le PIN — confirme avec le mot de passe du compte',
    'forgot_modal_body' => "Le mot de passe de ton compte récupère la clé de verrouillage : réinitialiser le PIN ne fait donc perdre aucune donnée, tant que ce mot de passe ouvre encore le verrou. Un mot de passe réinitialisé avec un code de récupération, ou défini pour toi par le propriétaire du compte, ne l'ouvre plus.",
    'confirm_new_pin_label' => 'Confirme le nouveau PIN',
    'reset_pin' => 'Réinitialiser le PIN',
    'cancel' => 'Annuler',

    'change_modal_heading' => 'Changer le PIN — confirme avec le PIN actuel',
    'keep_pin' => 'Conserver le PIN',

    'error_pin_too_short' => 'Le PIN doit comporter au moins 6 chiffres.',
    'error_pin_digits' => 'Le PIN doit comporter :min à :max chiffres — uniquement des chiffres.',
    'error_pin_mismatch' => 'Les PIN ne correspondent pas. Réessaie.',
    'error_pin_required' => 'Saisis ton PIN.',
    'error_pin_incorrect' => 'PIN incorrect.',
    'error_account_password_required' => 'Saisis le mot de passe de ton compte.',
    'error_account_password' => 'Mot de passe du compte incorrect.',
    'change_pin_success' => 'Ta clé de chiffrement est de nouveau protégée, avec ton nouveau PIN.',
    'error_forgot_failed' => 'Échec de la réinitialisation du PIN — la clé de récupération est indisponible.',
    'error_enable_first' => 'Active d\'abord le verrouillage par PIN avant la biométrie.',
    'error_disable_blocked_by_encryption' => 'Tes notes et les détails de tes tiers sont chiffrés avec la clé que détient ce verrou d\'application ; le désactiver les rendrait illisibles. Le verrou reste actif — change plutôt ton code PIN.',
    'error_key_material_lost' => "Cet appareil ne détient plus la clé qui ouvre tes données chiffrées, donc un nouveau code PIN ne les rendra pas lisibles. Restaure une sauvegarde chiffrée réalisée pendant que la clé fonctionnait encore — cet appareil ne peut pas revenir par un appairage, car l'appairage a besoin du verrou d'application que cette clé ouvre.",
    'error_recovery_wrap_stale' => 'Ton mot de passe du compte n\'ouvre plus ce verrouillage de l\'app — il a été changé après la mise en place du verrou. Ton PIN fonctionne encore, mais il n\'y a plus rien derrière si tu l\'oublies. Relie ton mot de passe du compte maintenant.',
    'relink_recovery' => 'Relier le mot de passe du compte',
    'relink_modal_heading' => 'Relier le mot de passe du compte — confirme avec ton PIN',
    'relink_recovery_success' => 'Ton mot de passe du compte peut de nouveau récupérer ce verrouillage de l\'app.',
];
