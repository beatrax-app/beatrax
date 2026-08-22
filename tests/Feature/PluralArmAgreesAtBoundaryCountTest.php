<?php

declare(strict_types=1);

use Illuminate\Support\Facades\App;
use Modules\Core\Public\Support\Lang;

// Boundary counts, not "one and many": every locale below selects a different
// arm at a number English never distinguishes — 21 in Croatian, 2 in Slovenian,
// 20 in Romanian, 0 in Latvian. The parity test counts the arms; only rendering
// one shows whether the words inside it agree with the number that reached it.

/** @return list<array{0: string, 1: string, 2: int, 3: array<string, string|int>, 4: string}> */
function pluralArmBoundaryCases(): array
{
    return [
        // Slovenian: 1 / dual / 3–4 / genitive plural, and the verb moves with
        // the noun. 101 and 102 re-enter the first two arms.
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 1, [], 'Napačen PIN. Preostal je še 1 poskus.'],
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 2, [], 'Napačen PIN. Preostala sta še 2 poskusa.'],
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 3, [], 'Napačen PIN. Preostali so še 3 poskusi.'],
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 5, [], 'Napačen PIN. Preostalo je še 5 poskusov.'],
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 101, [], 'Napačen PIN. Preostal je še 101 poskus.'],
        ['sl', 'auth::lock_screen.error_incorrect_remaining', 102, [], 'Napačen PIN. Preostala sta še 102 poskusa.'],
        ['sl', 'mobile::lock.errors.incorrect_pin_remaining', 1, [], 'Napačen PIN. Preostal je še 1 poskus.'],
        ['sl', 'mobile::lock.errors.incorrect_pin_remaining', 2, [], 'Napačen PIN. Preostala sta še 2 poskusa.'],
        ['sl', 'mobile::lock.errors.incorrect_pin_remaining', 3, [], 'Napačen PIN. Preostali so še 3 poskusi.'],
        ['sl', 'mobile::lock.errors.incorrect_pin_remaining', 5, [], 'Napačen PIN. Preostalo je še 5 poskusov.'],
        ['sl', 'mobile::sync_complete.records', 1, ['peer' => 'Telefon'], 'Iz naprave Telefon je kopiran 1 zapis.'],
        ['sl', 'mobile::sync_complete.records', 2, ['peer' => 'Telefon'], 'Iz naprave Telefon sta kopirana 2 zapisa.'],
        ['sl', 'mobile::sync_complete.records', 3, ['peer' => 'Telefon'], 'Iz naprave Telefon so kopirani 3 zapisi.'],
        ['sl', 'mobile::sync_complete.records', 5, ['peer' => 'Telefon'], 'Iz naprave Telefon je kopiranih 5 zapisov.'],
        ['sl', 'core::dashboard.email_scan_health', 1, [], 'Stanje pregledovanja e-pošte — 1 povezan nabiralnik'],
        ['sl', 'core::dashboard.email_scan_health', 2, [], 'Stanje pregledovanja e-pošte — 2 povezana nabiralnika'],
        ['sl', 'core::dashboard.email_scan_health', 3, [], 'Stanje pregledovanja e-pošte — 3 povezani nabiralniki'],
        ['sl', 'core::dashboard.email_scan_health', 5, [], 'Stanje pregledovanja e-pošte — 5 povezanih nabiralnikov'],
        ['sl', 'core::sidebar.badge.tax', 5, [], '5 postavk, označenih kot davčno pomembnih'],
        ['sl', 'dev::arg_prompt.errors.arg', 1, [], 'argument'],
        ['sl', 'dev::arg_prompt.errors.arg', 2, [], 'argumenta'],
        ['sl', 'dev::arg_prompt.errors.arg', 3, [], 'argumenti'],
        ['sl', 'dev::arg_prompt.errors.arg', 5, [], 'argumenti'],
        ['sl', 'dev::runner.toast.arg', 1, [], 'argument'],
        ['sl', 'dev::runner.toast.arg', 2, [], 'argumenta'],
        ['sl', 'dev::runner.toast.arg', 3, [], 'argumente'],
        ['sl', 'dev::runner.toast.arg', 5, [], 'argumente'],

        // Croatian and Serbian: 21 and 101 return to the singular arm, so an
        // adjective frozen in the plural there reads "21 povezani".
        ['hr', 'core::dashboard.email_scan_health', 1, [], 'Stanje skeniranja e-pošte — 1 povezan pretinac'],
        ['hr', 'core::dashboard.email_scan_health', 2, [], 'Stanje skeniranja e-pošte — 2 povezana pretinca'],
        ['hr', 'core::dashboard.email_scan_health', 5, [], 'Stanje skeniranja e-pošte — 5 povezanih pretinaca'],
        ['hr', 'core::dashboard.email_scan_health', 21, [], 'Stanje skeniranja e-pošte — 21 povezan pretinac'],
        ['hr', 'core::dashboard.email_scan_health', 101, [], 'Stanje skeniranja e-pošte — 101 povezan pretinac'],
        ['hr', 'dev::arg_prompt.errors.arg', 1, [], 'argument'],
        ['hr', 'dev::arg_prompt.errors.arg', 2, [], 'argumenti'],
        ['hr', 'dev::arg_prompt.errors.arg', 5, [], 'argumenti'],
        ['sr', 'core::dashboard.email_scan_health', 1, [], 'Stanje skeniranja e-pošte — 1 povezano sanduče'],
        ['sr', 'core::dashboard.email_scan_health', 2, [], 'Stanje skeniranja e-pošte — 2 povezana sandučeta'],
        ['sr', 'core::dashboard.email_scan_health', 5, [], 'Stanje skeniranja e-pošte — 5 povezanih sandučadi'],
        ['sr', 'core::dashboard.email_scan_health', 21, [], 'Stanje skeniranja e-pošte — 21 povezano sanduče'],
        ['sr', 'dev::arg_prompt.errors.arg', 1, [], 'argument'],
        ['sr', 'dev::arg_prompt.errors.arg', 2, [], 'argumenti'],
        ['sr', 'dev::arg_prompt.errors.arg', 5, [], 'argumenti'],

        // Latvian selects its first arm for zero, not for one, and 21 and 101
        // go back to the singular.
        ['lv', 'core::dashboard.email_scan_health', 0, [], 'E-pasta skenēšanas stāvoklis — pievienotas: 0 pastkastu'],
        ['lv', 'core::dashboard.email_scan_health', 1, [], 'E-pasta skenēšanas stāvoklis — pievienota: 1 pastkaste'],
        ['lv', 'core::dashboard.email_scan_health', 2, [], 'E-pasta skenēšanas stāvoklis — pievienotas: 2 pastkastes'],
        ['lv', 'core::dashboard.email_scan_health', 21, [], 'E-pasta skenēšanas stāvoklis — pievienota: 21 pastkaste'],
        ['lv', 'core::dashboard.email_scan_health', 101, [], 'E-pasta skenēšanas stāvoklis — pievienota: 101 pastkaste'],
        ['lv', 'counterparties::triage.reasoning', 1, ['hits' => 1, 'total' => 1, 'name' => 'Acme'],
            '1 no 1 nesenā darījuma šajā IBAN norāda uz Acme.'],
        ['lv', 'counterparties::triage.reasoning', 2, ['hits' => 2, 'total' => 2, 'name' => 'Acme'],
            '2 no 2 nesenajiem darījumiem šajā IBAN norāda uz Acme.'],
        ['lv', 'counterparties::triage.reasoning', 21, ['hits' => 3, 'total' => 21, 'name' => 'Acme'],
            '3 no 21 nesenā darījuma šajā IBAN norāda uz Acme.'],

        // Romanian inserts "de" from 20 upward and drops it again at 101.
        ['ro', 'community::mystery.card.seen_times', 1, [], 'Văzut o dată'],
        ['ro', 'community::mystery.card.seen_times', 19, [], 'Văzut de 19 ori'],
        ['ro', 'community::mystery.card.seen_times', 20, [], 'Văzut de 20 de ori'],
        ['ro', 'email-scan::inboxes.seen_times', 1, [], 'Văzut o dată'],
        ['ro', 'email-scan::inboxes.seen_times', 19, [], 'Văzut de 19 ori'],
        ['ro', 'email-scan::inboxes.seen_times', 20, [], 'Văzut de 20 de ori'],
        ['ro', 'core::dashboard.email_scan_health', 1, [], 'Starea scanării e-mailului — 1 căsuță poștală conectată'],
        ['ro', 'core::dashboard.email_scan_health', 19, [], 'Starea scanării e-mailului — 19 căsuțe poștale conectate'],
        ['ro', 'core::dashboard.email_scan_health', 20, [], 'Starea scanării e-mailului — 20 de căsuțe poștale conectate'],

        // Turkish leaves the noun unmarked at every count, so one arm has to
        // read correctly for all of them and a second arm is unreachable.
        ['tr', 'chains::index.leg_count', 1, [], '1 ödeme'],
        ['tr', 'chains::index.leg_count', 5, [], '5 ödeme'],
        ['tr', 'chains::index.leg_count', 20, [], '20 ödeme'],
        ['tr', 'dev::overview.queue_summary_batches', 3, [], '3 etkin toplu iş'],

        // The Germanic and Romance locales inflect the attributive adjective
        // for number where English does not mark it at all.
        ['de', 'core::dashboard.email_scan_health', 1, [], 'Status des E-Mail-Scans — 1 verbundenes Postfach'],
        ['de', 'core::dashboard.email_scan_health', 2, [], 'Status des E-Mail-Scans — 2 verbundene Postfächer'],
        ['nl', 'core::dashboard.email_scan_health', 1, [], 'Status e-mailscan — 1 gekoppeld postvak'],
        ['nl', 'core::dashboard.email_scan_health', 2, [], 'Status e-mailscan — 2 gekoppelde postvakken'],
        ['da', 'core::dashboard.email_scan_health', 1, [], 'Status for e-mailscanning — 1 forbundet indbakke'],
        ['da', 'core::dashboard.email_scan_health', 2, [], 'Status for e-mailscanning — 2 forbundne indbakker'],
        ['nb', 'core::dashboard.email_scan_health', 1, [], 'Status for e-postskanning — 1 tilkoblet innboks'],
        ['nb', 'core::dashboard.email_scan_health', 2, [], 'Status for e-postskanning — 2 tilkoblede innbokser'],
        ['sv', 'core::dashboard.email_scan_health', 1, [], 'Status för e-postskanning — 1 ansluten inkorg'],
        ['sv', 'core::dashboard.email_scan_health', 2, [], 'Status för e-postskanning — 2 anslutna inkorgar'],
        ['it', 'core::dashboard.email_scan_health', 1, [], 'Stato della scansione email — 1 casella collegata'],
        ['it', 'core::dashboard.email_scan_health', 2, [], 'Stato della scansione email — 2 caselle collegate'],
        ['pt', 'core::dashboard.email_scan_health', 1, [], 'Estado da análise de e-mail — 1 caixa de entrada ligada'],
        ['pt', 'core::dashboard.email_scan_health', 2, [], 'Estado da análise de e-mail — 2 caixas de entrada ligadas'],

        // Finnish takes a partitive singular after any numeral but one, and
        // Ukrainian an accusative the impersonal "підключено" governs.
        ['fi', 'core::dashboard.email_scan_health', 1, [], 'Sähköpostin skannauksen tila — 1 yhdistetty postilaatikko'],
        ['fi', 'core::dashboard.email_scan_health', 2, [], 'Sähköpostin skannauksen tila — 2 yhdistettyä postilaatikkoa'],
        ['uk', 'core::dashboard.email_scan_health', 1, [], 'Стан сканування пошти — підключено 1 скриньку'],
        ['uk', 'core::dashboard.email_scan_health', 2, [], 'Стан сканування пошти — підключено 2 скриньки'],
        ['uk', 'core::dashboard.email_scan_health', 5, [], 'Стан сканування пошти — підключено 5 скриньок'],
        ['uk', 'core::dashboard.email_scan_health', 21, [], 'Стан сканування пошти — підключено 21 скриньку'],

        // Bulgarian and Hungarian buy a zero form their rule tables cannot
        // select by writing explicit {0} and [2,*] ranges.
        ['bg', 'core::dashboard.email_scan_health', 0, [], 'Състояние на сканирането на имейл — 0 свързани пощенски кутии'],
        ['bg', 'core::dashboard.email_scan_health', 1, [], 'Състояние на сканирането на имейл — 1 свързана пощенска кутия'],
        ['bg', 'core::dashboard.email_scan_health', 2, [], 'Състояние на сканирането на имейл — 2 свързани пощенски кутии'],
        ['hu', 'core::dashboard.email_scan_health', 0, [], 'E-mail-vizsgálat állapota — nincs csatlakoztatott postafiók'],
        ['hu', 'core::dashboard.email_scan_health', 1, [], 'E-mail-vizsgálat állapota — 1 csatlakoztatott postafiók'],
        ['hu', 'core::dashboard.email_scan_health', 2, [], 'E-mail-vizsgálat állapota — 2 csatlakoztatott postafiók'],
    ];
}

it('renders the arm the locale selects with words that agree with the count', function (
    string $locale,
    string $key,
    int $count,
    array $replace,
    string $expected,
): void {
    App::setLocale($locale);

    expect(Lang::choice($key, $count, $replace))->toBe($expected);
})->with(function (): array {
    $named = [];
    foreach (pluralArmBoundaryCases() as $case) {
        $named[$case[0].' · '.$case[1].' · '.$case[2]] = $case;
    }

    return $named;
});
