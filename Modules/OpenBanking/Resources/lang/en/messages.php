<?php

declare(strict_types=1);

return [

    'page' => [
        'back_link' => 'Settings',
        'heading' => 'Open banking',
        'subtitle' => 'Automatically fetch transactions from ASN or SNS through Enable Banking, a third-party PSD2 aggregator. Off by default.',
        'toggle_label' => 'Enable open banking',
        'toggle_connected' => 'Connected to :bank via Enable Banking.',
        'toggle_off_help' => 'Off by default. Requires a one-time acknowledgement and guided setup.',
        'connect_another' => 'Connect another bank',
        'credentials_unreadable' => 'The open banking credentials saved on this device cannot be read, so Beatrax cannot reach your bank.',
        'credentials_unreadable_next' => 'Run the guided setup again to replace them. Transactions already imported are not affected.',
        'reconfirm_body' => 'Your acknowledgement expired before we could finish connecting. Re-confirm to finish enabling open banking.',
        'reconfirm_button' => 'Re-confirm to finish enabling',
    ],

    'status_row' => [
        'heading' => 'Open banking',
        'manage' => 'Manage open banking',
        'not_connected' => 'No bank connected. Connect one to import transactions automatically.',
        'expired' => 'Consent expired — reconnect needed.',
        'revoked' => 'Your bank ended the connection — reconnect needed.',
        'connected' => 'Connected to :bank via Enable Banking. Last synced :when.',
        'never' => 'never',
    ],

    'transparency' => [
        'aggregator_label' => 'Aggregator',
        'bank_label' => 'Bank',
        'consent_status_label' => 'Consent status',
        'pill_expired' => 'Expired — reconnect',
        'pill_expiring' => 'Expiring soon',
        'pill_connected' => 'Connected',
        'pill_revoked' => 'Ended by your bank — reconnect',
        'whats_fetched_label' => 'What’s fetched',
        'whats_fetched' => 'Booked transactions + balances, last 90 days',
        'last_successful_sync_label' => 'Last successful sync',
        'never' => 'Never',
        'last_attempt_label' => 'Last attempt',
        'last_attempt_failed' => ':when — failed (:reason)',
        'reason_consent_expired' => 'consent expired',
        'reason_error' => 'error',
        'reason_truncated' => 'stopped early',
        'reason_nothing_imported' => 'nothing could be filed',
        'reason_consent_revoked' => 'ended by your bank',
        'disconnect_button' => 'Disconnect',
    ],

    'consent_banner' => [
        'heading' => 'Consent expired — reconnect',
        'heading_revoked' => 'Your bank ended the connection',
        'body' => 'Your last successful sync was :when. Reconnect to resume automatic syncing.',
        'body_revoked' => 'Your bank or Enable Banking withdrew access, so syncing has stopped. Your last successful sync was :when. Reconnect to resume.',
        'never' => 'never',
        'reconnect' => 'Reconnect',
    ],

    'sync' => [
        'review_import' => 'Review import',
        'reconnect_first' => 'Reconnect first',
        'auto_caption' => 'Syncs automatically once a day.',
        'sync_now' => 'Sync now',

        'consent_expired' => 'Consent expired — reconnect.',
        'unavailable' => 'Enable Banking is temporarily unavailable. Try again shortly.',
        'new_found' => ':count new transaction found.|:count new transactions found.',
        'none' => 'No new transactions.',
        'none_importable' => 'Your bank sent transactions, but none of them could be filed. Open the import review to see why.',
        'in_progress' => 'A sync is already running. Try again in a moment.',
        'truncated' => 'Your bank had more transactions than one sync can fetch, so this run stopped early. Nothing was recorded as synced — the next sync starts from the same point.',
    ],

    'disconnect' => [
        'heading' => 'Disconnect open banking?',
        'body' => 'This removes your stored Enable Banking credentials and consent. Automatic syncing stops immediately. Transactions already imported into Beatrax are not affected.',
        'confirm' => 'Disconnect',
        'cancel' => 'Keep connected',
    ],

    'ics' => [
        'section_label' => 'File import — no credentials stored',
        'heading' => 'ICS credit card statement',
        'step_login' => 'Log in',
        'step_download' => 'Download statement',
        'pdf_statement' => 'PDF statement',
        'step_drop' => 'Drop it below',
        'drop_zone_label' => 'Drop your statement file here',
        'drop_zone_hint' => 'or browse for a file',
        'browse_aria' => 'Browse for an ICS statement file',
        'import_button' => 'Import statement',
        'validation' => [
            'required' => 'Drop the ICS statement you downloaded from Mijn ICS.',
            'max' => 'That file is too large. ICS PDF statements are normally under 1 MB each.',
            'extensions' => "That isn't a PDF. Mijn ICS only exports PDF statements.",
        ],
        'could_not_read' => 'Could not read :filename. The full error is in /dev/logs.',
    ],

    'warning' => [
        'heading' => 'Before you connect a third party',
        'body' => 'Enabling open banking sends your bank login consent, and then your transaction and balance data, directly from this device to Enable Banking and your bank. Beatrax does not operate a server that sees this data — but Enable Banking and your bank do. This is different from every other import method in Beatrax, which never sends data anywhere.',
        'acknowledge' => 'I understand my transaction data will be shared with Enable Banking and my bank.',
        'confirm' => 'Enable open banking',
        'cancel' => 'Cancel',
    ],

    'wizard' => [
        'heading' => 'Connect your bank',
        'intro' => 'Beatrax uses your own Enable Banking application so your credentials never touch a shared server. This is a one-time setup per bank.',

        'step1_title' => 'Generate your local key pair',
        'step1_body' => 'Beatrax generates an RSA key pair on this device. The private key never leaves it.',
        'generate_keypair' => 'Generate keypair',
        'public_key_label' => 'Public key',
        'copy_public_key' => 'Copy public key',
        'copied' => 'Copied',
        'redirect_uri_label' => 'Redirect URI',
        'copy_redirect_uri' => 'Copy redirect URI',

        'step2_title' => 'Register the application in Enable Banking',
        'step2_body' => 'Open the Enable Banking developer portal, create an application, and paste in the public key and redirect URI from step 1.',
        'open_portal' => 'Open Enable Banking portal ↗',

        'step3_title' => 'Paste your application ID',
        'application_id_label' => 'Application ID',
        'step3_help' => 'Stored in a local file outside the database with owner-only permissions. It identifies your application to Enable Banking, so it travels with every request — your private key never does.',

        'step4_title' => 'Choose your bank',
        'via_enable_banking' => 'via Enable Banking',
        'other_institution' => 'Other institution',
        'institution_id_placeholder' => 'Institution id',

        'step5_title' => 'Complete consent in your browser',
        'step5_body' => "Click below to open your bank's login and consent screen. Complete the login and any 2-factor step, then you'll be brought back here automatically to finish enabling Open Banking.",
        'step5_body_touch' => "Tap below to open your bank's login and consent screen. Complete the login and any 2-factor step, then you'll be brought back here automatically to finish enabling Open Banking.",

        'cancel' => 'Cancel',
        'continue' => 'Continue →',
        'continue_to_bank' => 'Continue to :bank →',
        'your_bank' => 'your bank',

        'errors' => [
            'save_keypair_failed' => 'Could not save your key pair to disk — check your secrets-directory permissions and try again.',
            'generate_failed' => 'Could not generate a key pair on this device — check your OpenSSL configuration.',
            'export_failed' => 'Could not export the generated key pair.',
            'read_public_failed' => 'Could not read the generated public key.',
            'generate_first' => 'Generate a key pair before continuing.',
            'paste_application_id' => 'Paste the application ID from the Enable Banking portal before continuing.',
            'save_application_id_failed' => 'Could not save your application ID to disk — check your secrets-directory permissions and try again.',
            'choose_bank' => 'Choose a bank before continuing.',
        ],
    ],

    'errors' => [
        'wizard_incomplete' => 'Finish the Open Banking setup wizard first.',
        'no_bank_chosen' => 'Choose a bank before connecting.',
        'no_consent_url' => 'Enable Banking did not return a consent URL.',
        'unparseable_consent_url' => 'Enable Banking returned an unparseable consent URL.',
        'non_public_consent_host' => 'Enable Banking returned a non-public consent host.',
        'unsafe_consent_url' => 'Enable Banking returned an unsafe consent URL.',
        'no_authorization_code' => 'Enable Banking callback returned no authorization code.',
        'no_session_id' => 'Enable Banking did not return a session id.',
        'bank_not_linked' => 'That bank is not linked on this device. Reconnect it to resume syncing.',
        'oauth_state_mismatch' => 'That connection link has expired or was already used. Start connecting your bank again.',
    ],
];
