<?php

declare(strict_types=1);

return [
    'page_title' => 'Aliases',
    'heading' => 'Aliases',
    'subtitle' => "Friendly names you've taught Beatrax for the cryptic descriptions on your statements. Edit a row's generalized pattern to widen or narrow which other transactions inherit the same friendly name.",
    'dismiss' => 'dismiss',

    'selected_count' => ':count selected',
    'merge_selected' => 'Merge selected',

    'empty_heading' => 'No aliases yet',
    'empty_body' => 'Aliases appear here after you click the italic raw description on an import preview row and give it a friendly name.',

    'col_select' => 'Select',
    'col_raw' => 'Raw description',
    'col_generalized' => 'Generalized pattern',
    'col_friendly' => 'Friendly name',
    'col_actions' => 'Actions',

    'select_alias_aria' => 'Select alias :name',
    'generalized_pattern_aria' => 'Generalized pattern',

    'save' => 'Save',
    'cancel' => 'Cancel',
    'edit' => 'Edit',
    'delete' => 'Delete',
    'delete_confirm' => "Delete this alias? Future imports of ':pattern' will go back to the raw description.",

    'backup_transfer' => 'Backup & transfer',
    'export_yaml' => 'Export aliases as YAML',

    'export_help_html' => 'Downloads <code class="font-mono">aliases.yaml</code> in the community-corpus format.',
    'import_from_yaml' => 'Import from YAML',
    'parse_preview' => 'Parse & preview',
    'cancel_import' => 'Cancel import',

    'diff_summary' => ':new, :unchanged, :conflicts.',
    'diff_new' => ':count new|:count new',
    'diff_unchanged' => ':count unchanged|:count unchanged',
    'diff_conflicts' => ':count conflict|:count conflicts',

    'conflicts_heading' => 'Conflicts',
    'conflict_name' => 'name — existing: :existing → file: :file',
    'conflict_pattern_existing' => 'pattern — existing:',
    'conflict_file' => '→ file:',
    'resolution_for_aria' => 'Resolution for :pattern',
    'keep_yours' => 'Keep yours',
    'replace' => 'Replace',
    'confirm_import' => 'Confirm import',

    'preview_aria' => 'Preview against transactions',
    'test_heading' => 'Test against my transactions',
    'test_help' => "Edit a row's generalized pattern to see which transactions it would match.",
    'typing' => 'Typing…',
    'matches' => 'Matches :count transaction in your recent history.|Matches :count transactions in your recent history.',

    'merge_modal_title' => 'Merge :count alias|Merge :count aliases',

    'merge_modal_help_html' => 'The remaining row keeps its raw description; the absorbed rows are preserved in <code class="font-mono text-xs">merged_from</code>.',
    'friendly_name_label' => 'Friendly name',
    'generalized_pattern_label' => 'Generalized pattern',
    'no_prefix_warning' => 'No shared 4-character prefix was found across the selected aliases — type a pattern manually before confirming.',
    'confirm_merge' => 'Confirm merge',

    'flash' => [
        'updated' => 'Alias updated.',
        'deleted' => 'Alias deleted.',
        'merged' => 'Aliases merged.',
        'imported' => 'Imported :count alias.|Imported :count aliases.',
        'nothing' => 'Nothing to import.',
    ],

    'errors' => [
        'not_found' => 'Alias not found (it may have been deleted in another tab).',
        'pattern_empty' => 'Generalized pattern cannot be empty.',
        'select_two' => 'Select at least two aliases to merge.',
        'some_not_found' => 'One or more selected aliases were not found.',
        'both_required' => 'Friendly name and generalized pattern are both required.',
        'merge_not_found' => 'One or more aliases were not found (they may have been deleted in another tab).',
        'merge_failed' => 'Merge failed (:class).',
        'no_file' => 'No file uploaded.',
        'unreadable' => 'Could not read the uploaded file.',
        'too_short' => 'Pattern is too short to test.',
        'file_not_yaml' => 'This file is not valid YAML, so nothing in it could be read. Export your aliases again and upload the file you get.',
        'file_unreadable_as_yaml' => 'This file could not be read as an alias list. Export your aliases again and upload the file you get.',
        'file_has_no_entries_list' => 'This file does not start with a top-level entries: list, so there are no aliases in it to import. Check you picked the right file.',
        'entry_is_not_a_mapping' => 'Entry :entry is a plain value where a pattern and a name were expected. Give it both fields, or remove it, and upload the file again.',
        'entry_is_missing_a_field' => 'Entry :entry is missing its pattern or its name, and an alias needs both. Fill in what is missing, or remove that entry, and upload the file again.',
    ],
];
