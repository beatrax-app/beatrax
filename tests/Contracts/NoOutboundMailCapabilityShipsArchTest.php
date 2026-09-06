<?php

declare(strict_types=1);

use Modules\Core\Public\Support\PatternScan;
use Tests\Contracts\Support\RepoTree;

// The product's position is that a ledger stays on the machines its owner
// controls, and mail is the one capability that quietly contradicts it: a
// single Mailable and a configured transport would send a reader's financial
// detail to a relay nobody chose. Today none exists — no Mailable subclass, no
// send site, and the only mail package in the tree is a PARSER the inbox scan
// reads with. What was missing is anything that keeps it that way.

/**
 * Every root that ships, from the one place a scope is declared -- which
 * declines tests with the reason, because a test may name a transport it
 * asserts is absent and a fixture may hold a whole message. The walk opened
 * app/ and Modules/, and a Mailable is a class that can be declared anywhere a
 * class can.
 *
 * @return list<string> every PHP source file the shells ship, tests excluded
 */
function outboundMailScannedSources(): array
{
    return RepoTree::files(RepoTree::PRODUCTION_PHP);
}

function outboundMailRelative(string $path): string
{
    return str_replace(RepoTree::root().'/', '', $path);
}

// `Mail::` alone is one of several doors into the same room, and the others
// carry no message text a reader would recognise as mail: a notification
// answers `toMail()`, the channel that delivers it is `MailChannel`, and an
// injected `Mailer` reaches a transport with the facade never written down.
const OUTBOUND_MAIL_SEND_SPELLINGS = [
    'the Mail facade' => '/\bMail::(?:send|to|bcc|cc|raw|html|plain|queue|later|mailer)\s*\(/',
    'an import of the Mail facade' => '/Illuminate\\\\Support\\\\Facades\\\\Mail\b/',
    'a notification rendering itself as mail' => '/\bfunction\s+toMail\s*\(/',
    'the mail notification channel' => '/\bMailChannel\b/',
    'the mailer contract' => '/Illuminate\\\\(?:Contracts\\\\Mail\\\\Mailer|Mail\\\\Mailer)\b/',
    'a mailer taken from the container' => '/\b(?:app|resolve)\s*\(\s*(?:Mailer::class|[\'"]mailer[\'"])\s*\)/',
    'an injected mailer' => '/(?:^|[(,\s])Mailer\s+\$\w+/m',
    'a notification addressed to mail' => '/Notification::route\s*\(\s*[\'"]mail[\'"]|\bfunction\s+routeNotificationForMail\s*\(/',
    'a notification whose channels include mail' => '/function\s+via\s*\([^)]*\)[^{]*\{[^}]*[\'"]mail[\'"]/s',
    'a message handed to a mailer' => '/[$>]\s*\w*[Mm]ailer\s*->\s*(?:send|sendNow|queue|later)\s*\(/',
];

const OUTBOUND_MAIL_COMPOSE_SPELLINGS = [
    'a Mailable subclass' => '/\bextends\s+Mailable\b/',
    'the Mailable contract' => '/Illuminate\\\\(?:Mail\\\\Mailable|Contracts\\\\Mail\\\\Mailable)\b/',
];

// A transport that answers a send without a socket. Everything else reads as
// deliverable, including a driver nobody has heard of yet: an allow-list fails
// closed on the next name Laravel adds, and a deny-list would pass it.
const OUTBOUND_MAIL_UNREACHABLE_TRANSPORTS = ['log', 'array', 'null'];

/**
 * @param  array<string, string>  $spellings
 * @return list<string> the ways $source hands a message on, named
 */
function outboundMailSpellingsIn(string $source, array $spellings): array
{
    $found = [];

    foreach ($spellings as $name => $pattern) {
        if (PatternScan::matches($pattern, $source)) {
            $found[] = $name;
        }
    }

    return $found;
}

/**
 * The two values that SELECT a transport, not the ones that merely describe
 * them. `mail.mailers` is deliberately not read: the framework's own defaults
 * define smtp, ses, postmark, resend, sendmail, failover and roundrobin on
 * every install, this repository publishes no config/mail.php to remove them,
 * and a definition nothing selects opens no socket. Reading that list makes
 * the rule impossible to satisfy while saying nothing about what would be sent.
 *
 * @return list<string> every transport a send would actually reach for
 */
function outboundMailDeliverableTransports(?string $default, ?string $shipped): array
{
    $named = array_filter(
        [$default, $shipped],
        static fn (?string $transport): bool => $transport !== null && $transport !== '',
    );

    $deliverable = array_filter(
        $named,
        static fn (string $transport): bool => ! in_array(strtolower($transport), OUTBOUND_MAIL_UNREACHABLE_TRANSPORTS, true),
    );

    return array_values(array_unique($deliverable));
}

// `.env.example` is the environment every shipped bundle runs on: the release
// workflow copies it over `.env` before it packages anything, so a MAIL_MAILER
// written here is the one a reader's machine would use.
function outboundMailShippedMailer(): ?string
{
    $template = base_path('.env.example');

    if (! is_file($template)) {
        return null;
    }

    $named = PatternScan::first('/^MAIL_MAILER=(\S+)$/m', (string) file_get_contents($template));

    return $named === [] ? null : trim($named[1], '"\'');
}

it('ships no class that composes a message to send', function (): void {
    $sources = outboundMailScannedSources();

    // Counted first: a walk that resolved nothing would report a clean tree,
    // which is the same answer a clean tree gives. The floor sits far under
    // today's 6,681.
    expect(count($sources))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($sources).' shipped PHP files, which is too few to have read the tree.'
    );

    $composers = [];

    foreach ($sources as $path) {
        $found = outboundMailSpellingsIn((string) file_get_contents($path), OUTBOUND_MAIL_COMPOSE_SPELLINGS);

        if ($found !== []) {
            $composers[] = outboundMailRelative($path).' — '.implode(', ', $found);
        }
    }

    expect($composers)->toBe([], "these compose mail to send:\n  ".implode("\n  ", $composers));
});

it('ships no call that hands a message to a transport', function (): void {
    $sources = outboundMailScannedSources();

    expect(count($sources))->toBeGreaterThan(
        3000,
        'RepoTree returned '.count($sources).' shipped PHP files, which is too few to have read the tree.'
    );

    $senders = [];

    foreach ($sources as $path) {
        $found = outboundMailSpellingsIn((string) file_get_contents($path), OUTBOUND_MAIL_SEND_SPELLINGS);

        if ($found !== []) {
            $senders[] = outboundMailRelative($path).' — '.implode(', ', $found);
        }
    }

    expect($senders)->toBe([], "these send mail:\n  ".implode("\n  ", $senders));
});

// A guard that cannot go red is a guard that says nothing, and this one reports
// on a tree that holds none of what it looks for. Each door is therefore opened
// against the reader rather than against the tree, beside the mail vocabulary
// the inbox scan is built on, which reads mail and never sends it.
it('recognises every door to a transport and leaves the parser alone', function (string $body, array $named): void {
    expect(outboundMailSpellingsIn('<?php '.$body, [...OUTBOUND_MAIL_SEND_SPELLINGS, ...OUTBOUND_MAIL_COMPOSE_SPELLINGS]))
        ->toBe($named);
})->with([
    'the facade' => ['Mail::to($user)->send(new Statement);', ['the Mail facade']],
    'the facade, queued' => ['Mail::to($user)->queue(new Statement);', ['the Mail facade']],
    'the facade on another transport' => ['Mail::mailer("smtp")->send($m);', ['the Mail facade']],
    'a notification rendering itself' => ['public function toMail($notifiable) { return new MailMessage; }', ['a notification rendering itself as mail']],
    'the channel itself' => ['return [MailChannel::class];', ['the mail notification channel']],
    'the mailer contract' => ['use Illuminate\Contracts\Mail\Mailer;', ['the mailer contract']],
    'a mailer from the container' => ['app(Mailer::class)->send($message);', ['a mailer taken from the container']],
    'an injected mailer' => ['public function __construct(private Mailer $mailer) {}', ['an injected mailer']],
    'a notification addressed to mail' => ['Notification::route("mail", $address)->notify($n);', ['a notification addressed to mail']],
    'a channel list naming mail' => ['public function via($notifiable) { return ["mail"]; }', ['a notification whose channels include mail']],
    'a message handed to a mailer' => ['$this->mailer->send($message);', ['a message handed to a mailer']],
    'a Mailable subclass' => ['final class Statement extends Mailable {}', ['a Mailable subclass']],
    'the Mailable contract' => ['use Illuminate\Contracts\Mail\Mailable;', ['the Mailable contract']],
    'the parser the inbox scan reads with' => ['$parsed = MailMimeParser::parse($raw); $subject = $parsed->getHeaderValue("subject");', []],
    'a mailbox address a reader typed' => ['$address = $connection->emailAddress(); $folder = "INBOX";', []],
    'the word in a column name' => ['$table->string("mail_scan_state");', []],
]);

// The requirement has two halves, and this is the second: what the bundle
// selects. Nothing publishes config/mail.php today, so mail.default falls to
// the framework's own value and the transport a send would reach comes from the
// environment the packager copies. Both are read, because either one alone can
// be made deliverable.
it('configures no transport that could deliver a message', function (): void {
    $default = config('mail.default');

    expect($default === null || is_string($default))->toBeTrue('mail.default is not a transport name.');

    $deliverable = outboundMailDeliverableTransports(
        is_string($default) ? $default : null,
        outboundMailShippedMailer(),
    );

    expect($deliverable)->toBe([], implode("\n", [
        'These transports would put a reader\'s financial detail on a network:',
        '  '.implode(', ', $deliverable),
        '',
        'Nothing the bundle selects may reach a network. Only log, array and null',
        'answer a send without a socket; a driver this list does not name reads',
        'as deliverable, including one Laravel adds after this was written.',
        '',
        'This reads mail.default and the MAIL_MAILER the packager ships, because',
        'those are what a send resolves through. It does not read mail.mailers:',
        'the framework defines every transport on every install, and a definition',
        'nothing selects opens nothing.',
    ]));

    // The shipped environment names one rather than leaving the key out: an
    // absent MAIL_MAILER is the framework's own default, which is smtp.
    expect(outboundMailShippedMailer())->toBeIn(
        OUTBOUND_MAIL_UNREACHABLE_TRANSPORTS,
        '.env.example names '.var_export(outboundMailShippedMailer(), true).' as MAIL_MAILER. The packager copies '
        .'this file over .env before it bundles anything, so an absent or deliverable value is the transport a '
        ."reader's machine would send through. Name log, array or null."
    );
});

it('tells a transport that could deliver from one that could not', function (?string $default, ?string $shipped, array $deliverable): void {
    expect(outboundMailDeliverableTransports($default, $shipped))->toBe($deliverable);
})->with([
    'nothing selected, log in the shipped environment' => [null, 'log', []],
    'the array transport everywhere' => ['array', 'array', []],
    'a selected smtp default' => ['smtp', 'log', ['smtp']],
    'a transport nobody has named before' => ['sendgrid', 'log', ['sendgrid']],
    'a shipped environment that names smtp' => [null, 'smtp', ['smtp']],
    'a shipped environment naming one the default does not' => ['log', 'ses', ['ses']],
    'nothing selected and nothing shipped' => [null, null, []],
]);
