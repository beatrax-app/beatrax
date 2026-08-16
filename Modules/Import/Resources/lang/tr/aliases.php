<?php

declare(strict_types=1);

return [
    'page_title' => 'Takma adlar',
    'heading' => 'Takma adlar',
    'subtitle' => "Hesap ekstrelerindeki anlaşılması güç açıklamalar için Beatrax'a öğrettiğin anlaşılır adlar. Aynı anlaşılır adı hangi diğer işlemlerin devralacağını genişletmek veya daraltmak için bir satırın genelleştirilmiş kalıbını düzenle.",
    'dismiss' => 'kapat',

    'selected_count' => ':count seçildi',
    'merge_selected' => 'Seçilenleri birleştir',

    'empty_heading' => 'Henüz takma ad yok',
    'empty_body' => 'Takma adlar, bir içe aktarma önizleme satırındaki italik ham açıklamaya tıklayıp ona anlaşılır bir ad verdikten sonra burada görünür.',

    'col_select' => 'Seç',
    'col_raw' => 'Ham açıklama',
    'col_generalized' => 'Genelleştirilmiş kalıp',
    'col_friendly' => 'Anlaşılır ad',
    'col_actions' => 'Eylemler',

    'select_alias_aria' => ':name takma adını seç',
    'generalized_pattern_aria' => 'Genelleştirilmiş kalıp',

    'save' => 'Kaydet',
    'cancel' => 'İptal',
    'edit' => 'Düzenle',
    'delete' => 'Sil',
    'delete_confirm' => "Bu takma ad silinsin mi? ':pattern' için gelecekteki içe aktarmalar ham açıklamaya geri döner.",

    'backup_transfer' => 'Yedekleme ve aktarım',
    'export_yaml' => 'Takma adları YAML olarak dışa aktar',

    'export_help_html' => '<code class="font-mono">aliases.yaml</code> dosyasını topluluk derlemi biçiminde indirir.',
    'import_from_yaml' => "YAML'den içe aktar",
    'parse_preview' => 'Ayrıştır ve önizle',
    'cancel_import' => 'İçe aktarmayı iptal et',

    'diff_new' => 'yeni,',
    'diff_unchanged' => 'değişmemiş,',
    'diff_conflicts' => 'çakışma.',

    'conflicts_heading' => 'Çakışmalar',
    'conflict_name' => 'ad — mevcut: :existing → dosya: :file',
    'conflict_pattern_existing' => 'kalıp — mevcut:',
    'conflict_file' => '→ dosya:',
    'resolution_for_aria' => ':pattern için çözüm',
    'keep_yours' => 'Mevcut olanı koru',
    'replace' => 'Değiştir',
    'confirm_import' => 'İçe aktarmayı onayla',

    'preview_aria' => 'İşlemler üzerinde önizleme',
    'test_heading' => 'İşlemlerimde test et',
    'test_help' => 'Hangi işlemlerle eşleşeceğini görmek için bir satırın genelleştirilmiş kalıbını düzenle.',
    'typing' => 'Yazılıyor…',
    'matches_prefix' => 'Son geçmişindeki',
    'matches_suffix' => 'işlemle eşleşiyor.',

    'merge_modal_title' => ':count takma adı birleştir',

    'merge_modal_help_html' => 'Kalan satır ham açıklamasını korur; devralınan satırlar <code class="font-mono text-xs">merged_from</code> içinde saklanır.',
    'friendly_name_label' => 'Anlaşılır ad',
    'generalized_pattern_label' => 'Genelleştirilmiş kalıp',
    'no_prefix_warning' => 'Seçilen takma adlar arasında ortak 4 karakterlik bir önek bulunamadı — onaylamadan önce elle bir kalıp yaz.',
    'confirm_merge' => 'Birleştirmeyi onayla',

    'flash' => [
        'updated' => 'Takma ad güncellendi.',
        'deleted' => 'Takma ad silindi.',
        'merged' => 'Takma adlar birleştirildi.',
        'imported' => ':count takma ad içe aktarıldı.',
        'nothing' => 'İçe aktarılacak bir şey yok.',
    ],

    'errors' => [
        'not_found' => 'Takma ad bulunamadı (başka bir sekmede silinmiş olabilir).',
        'pattern_empty' => 'Genelleştirilmiş kalıp boş olamaz.',
        'select_two' => 'Birleştirmek için en az iki takma ad seç.',
        'some_not_found' => 'Seçilen takma adlardan biri veya birkaçı bulunamadı.',
        'both_required' => 'Anlaşılır ad ve genelleştirilmiş kalıp birlikte zorunludur.',
        'merge_not_found' => 'Bir veya daha fazla takma ad bulunamadı (başka bir sekmede silinmiş olabilirler).',
        'merge_failed' => 'Birleştirme başarısız oldu (:class).',
        'no_file' => 'Dosya yüklenmedi.',
        'unreadable' => 'Yüklenen dosya okunamadı.',
        'too_short' => 'Kalıp test edilemeyecek kadar kısa.',
    ],
];
