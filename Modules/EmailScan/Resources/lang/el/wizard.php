<?php

declare(strict_types=1);

return [
    'gmail_title' => 'Ρύθμισε τον δικό σου πελάτη OAuth για το Gmail',
    'microsoft_title' => 'Ρύθμισε τον δικό σου πελάτη OAuth για το Microsoft 365',
    'intro' => 'Το Beatrax χρησιμοποιεί το δικό σου έργο Google Cloud ή τη δική σου καταχώριση εφαρμογής Azure, ώστε τα διαπιστευτήριά σου να μην περνούν ποτέ από κοινόχρηστο διακομιστή. Η ρύθμιση γίνεται μία φορά ανά πάροχο.',

    'copied' => 'Αντιγράφηκε',
    'cancel' => 'Άκυρο',
    'save_connect' => 'Αποθήκευση και σύνδεση',

    'secret_help' => 'Αποθηκεύονται σε τοπικό αρχείο ρυθμίσεων εκτός της βάσης δεδομένων, με περιοριστικά δικαιώματα, και δεν φεύγουν ποτέ από αυτή τη συσκευή.',

    'gmail' => [
        'step1_title' => 'Άνοιξε το Google Cloud Console',
        'step1_body' => 'Άνοιξε το Google Cloud Console σε νέα καρτέλα. Συνδέσου με τον λογαριασμό Google που θέλεις να σαρωθεί και μετά δημιούργησε νέο έργο (ή διάλεξε ένα υπάρχον προσωπικό έργο).',
        'step1_link' => 'Άνοιγμα Google Cloud Console',
        'step2_title' => 'Ενεργοποίησε το Gmail API',
        'step2_body' => 'Στο νέο έργο, αναζήτησε «Gmail API» στη βιβλιοθήκη API και κάνε κλικ στο Enable. Έτσι το έργο αποκτά τη δυνατότητα να καλεί το Gmail για λογαριασμό σου.',
        'step3_title' => 'Ρύθμισε την οθόνη συγκατάθεσης OAuth',
        'step3_body' => 'Άνοιξε APIs & Services → OAuth consent screen. Διάλεξε User type «External», δώσε «Beatrax» ως όνομα εφαρμογής και το δικό σου email ως επαφή υποστήριξης και επαφή προγραμματιστή. Πρόσθεσε το scope https://www.googleapis.com/auth/gmail.readonly. Κάνε κλικ στο Save and continue και μετά στο Back to Dashboard.',
        'step4_title' => 'Πέρασε την οθόνη συγκατάθεσης σε «In production»',
        'step4_body' => 'Στη σελίδα της οθόνης συγκατάθεσης OAuth, κάνε κλικ στο Publish App και επιβεβαίωσε. Αυτό είναι απαραίτητο — χωρίς αυτό, τα refresh token που λαμβάνει το Beatrax λήγουν μετά από 7 ημέρες. Η δημοσίευση δεν απαιτεί έλεγχο από την Google όταν ο μόνος χρήστης είσαι εσύ.',
        'step4_checkbox' => 'Δημοσίευσα την οθόνη συγκατάθεσης OAuth σε In production',
        'step5_title' => 'Δημιούργησε το OAuth Client ID',
        'step5_body' => 'Άνοιξε Credentials → Create Credentials → OAuth Client ID. Διάλεξε τύπο εφαρμογής «Web application». Δώσε όνομα «Beatrax». Στο «Authorized redirect URIs» επικόλλησε ακριβώς το URI παρακάτω.',
        'step6_title' => 'Επικόλλησε το client ID και το client secret',
        'client_id_label' => 'Client ID',
        'client_secret_label' => 'Client secret',
    ],

    'microsoft' => [
        'step1_title' => 'Άνοιξε το Azure Portal',
        'step1_body' => 'Άνοιξε το Microsoft Entra admin center σε νέα καρτέλα. Συνδέσου με τον λογαριασμό Microsoft που θέλεις να σαρωθεί.',
        'step1_link' => 'Άνοιγμα Azure Portal',
        'step2_title' => 'Καταχώρισε νέα εφαρμογή',
        'step2_body' => 'Άνοιξε App registrations → New registration. Ονόμασέ τη «Beatrax». Στο «Supported account types» διάλεξε «Accounts in any organizational directory and personal Microsoft accounts» (έτσι μπορείς να συνδέσεις προσωπικά γραμματοκιβώτια Outlook.com και εταιρικά Microsoft 365 με την ίδια εφαρμογή).',
        'step3_title' => 'Πρόσθεσε το redirect URI',
        'step3_body' => 'Στην ίδια φόρμα καταχώρισης, στο «Redirect URI», διάλεξε πλατφόρμα «Web» και επικόλλησε ακριβώς το URI παρακάτω.',
        'step4_title' => 'Δώσε το δικαίωμα Mail.Read',
        'step4_body' => 'Άνοιξε API permissions → Add a permission → Microsoft Graph → Delegated permissions. Διάλεξε Mail.Read και offline_access. Κάνε κλικ στο Add permissions. Για προσωπικό λογαριασμό δεν χρειάζεται admin consent.',
        'step5_title' => 'Δημιούργησε ένα client secret',
        'step5_body' => 'Άνοιξε Certificates & secrets → New client secret. Δώσε περιγραφή «Beatrax» και λήξη 24 μηνών. Αντίγραψε αμέσως την τιμή του secret — το Azure τη δείχνει μόνο μία φορά.',
        'step6_title' => 'Επικόλλησε το application (client) ID και το secret',
        'client_id_label' => 'Application (client) ID',
        'client_secret_label' => 'Client secret value',
    ],

    'errors' => [
        'pick_provider' => 'Διάλεξε πάροχο πριν την υποβολή.',
        'microsoft_client_id' => 'Δώσε το application (client) ID — ένα UUID όπως 12345678-1234-1234-1234-123456789abc.',
        'microsoft_secret' => 'Δώσε την τιμή του client secret που σου έδειξε το Azure όταν το δημιούργησες.',
        'google_client_id' => 'Δώσε ένα OAuth client ID της Google που τελειώνει σε .apps.googleusercontent.com.',
        'google_secret' => 'Δώσε ένα OAuth client secret της Google που ξεκινά με GOCSPX-.',
        'google_published' => 'Επιβεβαίωσε ότι πέρασες την οθόνη συγκατάθεσης OAuth σε «In production».',
        'write_failed' => 'Δεν ήταν δυνατή η αποθήκευση του πελάτη OAuth στον δίσκο — έλεγξε τα δικαιώματα του καταλόγου με τα secrets και δοκίμασε ξανά.',
    ],
];
