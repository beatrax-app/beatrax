<?php

declare(strict_types=1);

/*
 * Refresh Modules/FX/Resources/rates-snapshot.json from the ECB's daily
 * reference feed — the file every cross-currency roll-up is priced from on an
 * install that never goes online, because fx_online_enabled is the consent
 * gate for this app's only outbound traffic and it is off by default.
 *
 * RefuseToShipStaleBundledRates names this script: it stops native:build,
 * native:package and mobile:package-android when the snapshot is older than
 * BundledSnapshot::SHIP_WITHIN_DAYS. Run it, commit the result, build again.
 *
 * The currency SET is load-bearing and this script never shrinks it on its
 * own. The thirty codes the file quotes are exactly the rows `currencies` is
 * seeded with and exactly what the two pickers offer, so a code the feed has
 * stopped quoting is carried forward at the figure it already held and named
 * on stdout. Dropping one is a product decision: make it deliberately, and
 * re-run scripts/generate_currency_names.php with it.
 */

const ECB_DAILY = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';
const ECB_NS = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';
const SNAPSHOT = __DIR__.'/../Modules/FX/Resources/rates-snapshot.json';

function fail(string $message): never
{
    fwrite(STDERR, 'refresh_bundled_rates: '.$message.PHP_EOL);
    exit(1);
}

/**
 * @return array{date: string, rates: array<string, string>}
 */
function currentSnapshot(): array
{
    $raw = @file_get_contents(SNAPSHOT);

    if ($raw === false) {
        fail('cannot read '.SNAPSHOT);
    }

    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

    if (! is_array($decoded) || ! is_string($decoded['date'] ?? null) || ! is_array($decoded['rates'] ?? null)) {
        fail(SNAPSHOT.' is not a {date, rates} object');
    }

    /** @var array{date: string, rates: array<string, string>} $decoded */
    return $decoded;
}

/**
 * @return array{date: string, rates: array<string, string>}
 */
function ecbDaily(): array
{
    $body = @file_get_contents(ECB_DAILY);

    if ($body === false) {
        fail('cannot reach '.ECB_DAILY);
    }

    $xml = @simplexml_load_string($body);

    if ($xml === false) {
        fail('the ECB feed is not XML');
    }

    $days = $xml->xpath('//*[local-name()="Cube"][@time]');

    if ($days === null || $days === []) {
        fail('the ECB feed carries no dated Cube');
    }

    $day = $days[0];
    $rates = [];

    $quoted = $day->xpath('*[local-name()="Cube"][@currency]');

    foreach ($quoted ?? [] as $cube) {
        $code = (string) $cube['currency'];
        $rate = (string) $cube['rate'];

        if ($code !== '' && $rate !== '') {
            $rates[$code] = $rate;
        }
    }

    if ($rates === []) {
        fail('the ECB feed carries no rates');
    }

    return ['date' => (string) $day['time'], 'rates' => $rates];
}

$current = currentSnapshot();
$feed = ecbDaily();

$rates = $feed['rates'];
$carried = [];

foreach ($current['rates'] as $code => $rate) {
    if (! array_key_exists($code, $rates)) {
        $rates[$code] = $rate;
        $carried[] = $code;
    }
}

ksort($rates);

$json = json_encode(
    ['date' => $feed['date'], 'rates' => $rates],
    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
);

file_put_contents(SNAPSHOT, $json."\n");

printf('%s -> %s, %d currencies%s', $current['date'], $feed['date'], count($rates), PHP_EOL);

foreach ($carried as $code) {
    printf(
        '  %s carried forward at %s: the feed no longer quotes it%s',
        $code,
        $rates[$code],
        PHP_EOL,
    );
}
