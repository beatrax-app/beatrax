<?php

declare(strict_types=1);

return [
    'banner_aria' => 'Alerte de sistem',

    'actions' => [
        'download_and_install' => 'Descarcă și instalează',
        'download_and_install_aria' => 'Descarcă și instalează — marchează alerta de sistem #:id ca rezolvată',
        'skip_version' => 'Omite această versiune',
        'release_notes' => 'Note de versiune →',
        'update_now' => 'Actualizează acum',
        'update_now_aria' => 'Actualizează acum — marchează alerta de sistem #:id ca rezolvată',
        'remind_later' => 'Amintește-mi mai târziu',
        'mark_resolved' => 'Marchează ca rezolvată',
        'mark_resolved_aria' => 'Marchează ca rezolvată — alerta de sistem #:id',
        'assign_in_budgets' => 'Alocă în Bugete',
        'dismiss' => 'Închide',
        'dismiss_aria' => 'Închide — alerta de sistem #:id',
    ],

    'deferred_pass' => [
        'budget-nudges' => 'alertele de buget',
        'daily-triggers' => 'mementourile zilnice și rezumatul',
    ],

    'messages' => [
        'update_available' => 'Actualizare disponibilă — Beatrax :version. Nu se descarcă nimic până nu alegi să instalezi; Beatrax se închide apoi și se redeschide pe versiunea nouă.',
        'update_refused' => 'Beatrax a descărcat versiunea :version și a refuzat să o instaleze — fișierul nu corespundea semnăturii editorului, așa că nimic de pe acest dispozitiv nu a fost modificat. O descărcare deteriorată poate cauza asta. Dacă se repetă, nu instala Beatrax din acea sursă.',
        'update_stale' => 'Folosești versiunea :current — versiunea :latest este disponibilă de 30 de zile. Actualizează acum.',
        'update_critical' => 'Actualizare critică disponibilă — versiunea :version rezolvă :summary. Instaleaz-o cât mai curând.',
        'backup_corrupt_with_path' => 'Copia de rezervă scrisă la :timestamp nu a trecut verificarea de integritate. Verifică :path. Rezolvă problema înainte să te bazezi pe copiile de rezervă.',
        'backup_corrupt_no_path' => 'Copia de rezervă încercată la :timestamp s-a oprit înainte să fie produs vreun fișier — baza de date sursă nu a trecut verificarea de integritate. Rezolvă problema înainte să te bazezi pe copiile de rezervă.',
        'backup_write_failed' => 'Copia de rezervă începută la :timestamp nu s-a finalizat — baza de date a trecut verificările, dar fișierele copiei nu au putut fi scrise. Verifică spațiul liber și permisiunile folderului de copii de rezervă.',
        'backup_restore_failed' => 'Restaurarea începută la :timestamp nu s-a finalizat. Datele tale anterioare au fost salvate în prealabil în :snapshot.',

        'backup_overdue' => 'Cea mai recentă copie de rezervă verificată are o vechime de :hoursh. Beatrax face singur această copie, o dată pe zi, cât timp aplicația este deschisă — nu ai nimic de rulat manual. Dacă rămâne atât de veche, aplicația nu a fost deschisă când a venit rularea zilnică.',
        'backup_none_found' => 'În folderul copiilor de rezervă nu a fost găsită nicio copie verificată. Beatrax face singur această copie, o dată pe zi, cât timp aplicația este deschisă — nu ai nimic de rulat manual.',
        'wal_mode_missing' => 'Baza de date nu este în modul WAL (momentan :mode), așa că salvarea se poate opri cât timp rulează o sarcină în fundal. Beatrax setează WAL la fiecare pornire, deci o repornire rezolvă de obicei acest lucru.',
        'synchronous_misconfigured' => 'Nivelul de durabilitate al bazei de date este :level în loc de NORMAL, cel așteptat. Beatrax îl setează la fiecare pornire, deci o repornire rezolvă de obicei problema.',
        'oauth_scrub_set_failed' => 'Mascarea secretelor OAuth nu funcționează. Jurnalele și extrasele de audit pot conține jetoane nemascate până la următoarea încărcare reușită.',
        'oauth_reauth_required' => 'Secretele OAuth au fost mutate în stocarea per utilizator. Autorizează din nou Gmail și Microsoft pentru a relua scanarea e-mailului. Vechiul fișier de secrete a fost redenumit în :file pentru revenire.',
        'oauth_reconsent' => 'Reconectează-ți contul :provider',
        'auth_recovery_code_consumed' => 'Cod de recuperare folosit de :username.',
        'auth_recovery_code_failed' => 'Încercare eșuată de cod de recuperare pentru :username.',
        'auth_lock_hard_cap_reached' => 'Deconectare după prea multe încercări eșuate de PIN.',
        'open_banking_reconsent' => 'Reconectează-ți banca',
        'open_banking_nothing_imported' => 'Banca ta a trimis tranzacții, dar Beatrax nu a putut înregistra niciuna, așa că nimic nu a ajuns în evidența ta. Deschide setările Open banking ca să vezi de ce.',
        'auth_lock_corrupted_key' => 'PIN-ul tău nu poate debloca aplicația pe acest dispozitiv: cheia stocată nu poate fi citită. Conectează-te cu parola contului pentru a seta un PIN nou.',
        'sync_gdk_rewrap_failed' => 'Reîmpachetarea inelului de chei GDK a eșuat după schimbarea frazei de acces a blocării aplicației — datele criptate pot fi irecuperabile până când inelul este reîmpachetat.',
        'worker_crashed' => 'Procesarea în fundal a Beatrax s-a oprit neașteptat. Importurile și scanările de e-mail sunt în pauză. Redeschide aplicația pentru a o reporni.',
        'auth_lock_key_material_stranded' => 'Criptarea în repaus este activă pentru acest cont, dar niciun înveliș al blocării aplicației nu mai deține cheia de date, așa că fiecare notă, descriere și detaliu de contraparte criptat se citește ca gol. Restaurează o copie de rezervă criptată făcută cât timp cheia încă funcționa sau configurează din nou acest cont pe un dispozitiv care încă o deține.',
        'auth_lock_recovery_wrap_stale' => 'Parola contului s-a schimbat fără ca învelișul de recuperare al blocării aplicației să fie reîmpachetat, așa că acea parolă nu mai deschide blocarea. PIN-ul încă o deschide. Reasociază parola contului din setările de blocare cât timp PIN-ul este încă știut — altfel un PIN uitat nu lasă nimic în urmă.',
        'reconnect_link' => 'Reconectează →',
        'pots_category_link_retired' => 'Bugetarea pe plicuri a înlocuit pușculițele legate de o categorie. Suma :amount din :count pușculiță arhivată este din nou nealocată și așteaptă să o aloci.|Bugetarea pe plicuri a înlocuit pușculițele legate de o categorie. Suma :amount din :count pușculițe arhivate este din nou nealocată și așteaptă să o aloci.|Bugetarea pe plicuri a înlocuit pușculițele legate de o categorie. Suma :amount din :count de pușculițe arhivate este din nou nealocată și așteaptă să o aloci.',
        'notifications_deferred_pass_failed' => 'Beatrax nu a putut calcula :pass pe acest dispozitiv, așa că unele pot lipsi. Încearcă din nou de fiecare dată când deschizi aplicația.',
    ],
];
