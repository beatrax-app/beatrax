<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliasuri',
    'heading' => 'Aliasuri',
    'subtitle' => 'Nume prietenoase pe care le-ai învățat pe Beatrax pentru descrierile criptice din extrasele tale. Editează tiparul generalizat al unui rând pentru a lărgi sau restrânge ce alte tranzacții moștenesc același nume prietenos.',
    'dismiss' => 'închide',

    'selected_count' => ':count selectate',
    'merge_selected' => 'Îmbină selecția',

    'empty_heading' => 'Încă niciun alias',
    'empty_body' => 'Aliasurile apar aici după ce apeși pe descrierea brută scrisă cursiv dintr-un rând al previzualizării importului și îi dai un nume prietenos.',

    'col_select' => 'Selectează',
    'col_raw' => 'Descriere brută',
    'col_generalized' => 'Tipar generalizat',
    'col_friendly' => 'Nume prietenos',
    'col_actions' => 'Acțiuni',

    'select_alias_aria' => 'Selectează aliasul :name',
    'generalized_pattern_aria' => 'Tipar generalizat',

    'save' => 'Salvează',
    'cancel' => 'Anulează',
    'edit' => 'Editează',
    'delete' => 'Șterge',
    'delete_confirm' => "Ștergi acest alias? Importurile viitoare ale ':pattern' vor reveni la descrierea brută.",

    'backup_transfer' => 'Copie de rezervă și transfer',
    'export_yaml' => 'Exportă aliasurile ca YAML',

    'export_help_html' => 'Descarcă <code class="font-mono">aliases.yaml</code> în formatul corpusului comunitar.',
    'import_from_yaml' => 'Importă din YAML',
    'parse_preview' => 'Analizează și previzualizează',
    'cancel_import' => 'Anulează importul',

    'diff_new' => 'noi,',
    'diff_unchanged' => 'neschimbate,',
    'diff_conflicts' => 'conflicte.',

    'conflicts_heading' => 'Conflicte',
    'conflict_name' => 'nume — existent: :existing → fișier: :file',
    'conflict_pattern_existing' => 'tipar — existent:',
    'conflict_file' => '→ fișier:',
    'resolution_for_aria' => 'Rezolvare pentru :pattern',
    'keep_yours' => 'Păstrează-l pe al tău',
    'replace' => 'Înlocuiește',
    'confirm_import' => 'Confirmă importul',

    'preview_aria' => 'Previzualizare pe tranzacții',
    'test_heading' => 'Testează pe tranzacțiile mele',
    'test_help' => 'Editează tiparul generalizat al unui rând ca să vezi ce tranzacții ar potrivi.',
    'typing' => 'Se scrie…',
    'matches_prefix' => 'Se potrivește cu',
    'matches_suffix' => 'tranzacții din istoricul tău recent.',

    'merge_modal_title' => 'Îmbină :count alias|Îmbină :count aliasuri|Îmbină :count de aliasuri',

    'merge_modal_help_html' => 'Rândul rămas își păstrează descrierea brută; rândurile absorbite sunt păstrate în <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Nume prietenos',
    'generalized_pattern_label' => 'Tipar generalizat',
    'no_prefix_warning' => 'Nu s-a găsit un prefix comun de 4 caractere între aliasurile selectate — scrie manual un tipar înainte de a confirma.',
    'confirm_merge' => 'Confirmă îmbinarea',

    'flash' => [
        'updated' => 'Alias actualizat.',
        'deleted' => 'Alias șters.',
        'merged' => 'Aliasuri îmbinate.',
        'imported' => ':count alias importat.|:count aliasuri importate.|:count de aliasuri importate.',
        'nothing' => 'Nimic de importat.',
    ],

    'errors' => [
        'not_found' => 'Aliasul nu a fost găsit (poate a fost șters în altă filă).',
        'pattern_empty' => 'Tiparul generalizat nu poate fi gol.',
        'select_two' => 'Selectează cel puțin două aliasuri pentru îmbinare.',
        'some_not_found' => 'Unul sau mai multe aliasuri selectate nu au fost găsite.',
        'both_required' => 'Numele prietenos și tiparul generalizat sunt ambele obligatorii.',
        'merge_not_found' => 'Unul sau mai multe aliasuri nu au fost găsite (poate au fost șterse în altă filă).',
        'merge_failed' => 'Îmbinarea a eșuat (:class).',
        'no_file' => 'Niciun fișier încărcat.',
        'unreadable' => 'Fișierul încărcat nu a putut fi citit.',
        'too_short' => 'Tiparul este prea scurt pentru testare.',
    ],
];
