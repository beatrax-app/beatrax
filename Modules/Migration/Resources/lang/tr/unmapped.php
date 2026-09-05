<?php

declare(strict_types=1);

return [
    'label' => [
        'goal' => 'Hedef: :name',
        'category_goal' => ':name hedefi',
        'schedule_untitled' => 'Adsız planlanmış işlem',
        'transaction' => 'İşlem: :name · :date · :amount',
        'transaction_unnamed' => 'İşlem',
        'amount_update' => 'İşlem tutarı güncellemesi',
        'budget_history' => ':currency cinsinden bütçe geçmişi',
        'budget_file_currency' => 'Bütçe dosyasının para birimi',
        'budget_file_mode' => 'Bütçe dosyasının modu',
    ],

    'conflict' => [
        'budget_assignment' => 'Bütçe dağıtımı',
        'budget_for_month' => 'Bütçe: :category · :month',
        'budget_for_category' => 'Bütçe: :category',
        'category_name' => 'Kategori adı',
        'category_name_of' => '“:name” kategorisinin adı',
        'account_name' => 'Hesap adı',
        'account_name_of' => '“:name” hesabının adı',
        'transaction_amount' => 'İşlem tutarı',
        'transaction_amount_of' => 'Tutar: :name',
        'transaction_amount_of_dated' => 'Tutar: :name · :date',
        'transaction_description' => 'İşlem açıklaması',
        'transaction_description_of' => 'Açıklama: :name',
        'transaction_description_of_dated' => 'Açıklama: :name · :date',
        'other' => 'İçe aktarılan değer',
    ],

    'reason' => [
        'fingerprint_collision' => 'Bu işlem, daha önce kaydedilmiş başka bir işlemle çakıştı (aynı parmak izi) ve içe aktarılmadı.',
        'reconciled_status_kept' => "Kaynağın mutabakat durumu uygulanamadı — bu işlem Beatrax'ta mutabakatlı ve bunu yalnızca mutabakatı geri almak değiştirir. Değiştirilmeden bırakıldı.",

        // i18n-review: tr · reason.split_legs_without_category — Turkish selects
        // one arm, so this line covers every count. It leads with :legs because
        // "3 kalemden 1 tanesinin" is the natural order; a :count-first reading
        // would need a different frame.
        'split_legs_without_category' => ':legs bölüştürme kaleminden :count tanesinin kategorisi yok ve kategorisi olmayan bir kalem saklanamaz. İşlem tam tutarıyla içe aktarıldı ve :uncategorized kategorisinde bekliyor.',
        'split_sum_mismatch' => 'Bölüştürme kalemlerinin toplamı :legs, ancak işlem :total; bir bölüştürme kendi işlemiyle tam olarak eşleşmek zorundadır. İşlem, kalemleri olmadan tam tutarıyla içe aktarıldı.',
        'split_unstorable' => 'Beatrax bu bölüştürmeyi bu haliyle saklayamıyor, bu yüzden işlem kalemleri olmadan tek başına içe aktarıldı.',
        'goal_without_target_date' => 'Bu hedefin son tarihi yok; Beatrax bir birikim hedefi oluşturmak için buna ihtiyaç duyar.',
        'goal_without_name' => 'Bu hedefin adı yok; Beatrax bir birikim hedefi oluşturmak için buna ihtiyaç duyar.',
        'goal_def_unsupported' => 'categories.goal_def desteklenmeyen (düz olmayan) bir şablon biçimi kullanıyor — hedef içe aktarılmadı.',
        'budget_currency_mismatch' => ':count bütçe satırı içe aktarılmadı: senin bütçelerin :envelope ile tutuluyor, bu dışa aktarım ise bütçeyi :source ile tutuyor.',
        'amount_apply_collision' => 'Kaynaktaki yeni tutar uygulanamadı — başka bir işlemin parmak iziyle çakışıyor (aynı hesap, tarih, para birimi ve karşı taraf). Değiştirilmeden bırakıldı.',
        'amount_currency_mismatch' => 'İşlem tutarları mutabık kılınmadı: bu işlemler :local ile tutuluyor, bu dışa aktarım ise onları :source ile belirtiyor. Değiştirilmeden bırakıldı.',
        'schedule_unsupported' => 'Beatrax planlanmış ve düzenli işlemleri henüz dış kaynaktan oluşturamıyor — yalnızca not olarak saklandı, Düzenli işlemler altında etkin bir seri olarak değil.',
        'saved_report_unsupported' => 'Kaydedilmiş raporların ve analiz yapılandırmalarının Beatrax\'ta karşılığı yok.',
        'assumed_currency' => ":currency varsayıldı — bu dışa aktarımda 'preferences.currencyCode' satırı bulunamadı.",
        'assumed_budget_type' => ":mode varsayıldı — bu dışa aktarımda 'preferences.budgetType' satırı bulunamadı.",
        'changed_on_both_sides' => "Son içe aktarmadan bu yana bunu hem kaynak dosya hem de Beatrax değiştirdi.\nYerel: :local\nKaynak: :source\nSon içe aktarılan: :baseline",
        'take_source' => 'Onayladığında yeni dışa aktarımdaki değer uygulanacak — yerel değerin değiştirilecek.',
        'keep_local' => 'Yerel değerin korunacak — yeni dışa aktarımdaki değer uygulanmayacak.',
        'compared_values' => ":intro\nYerel: :local · Kaynak: :source · Son içe aktarılan: :baseline",
    ],

    'value' => [
        'none' => '(yok)',
        'quoted' => '“:value”',
    ],
];
