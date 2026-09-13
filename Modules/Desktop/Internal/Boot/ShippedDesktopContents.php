<?php

declare(strict_types=1);

namespace Modules\Desktop\Internal\Boot;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

// What a built desktop application tree actually carries, read out of the tree
// rather than out of the exclusion list meant to keep things out of it. The
// list is a claim about a build; this is the build.
final readonly class ShippedDesktopContents
{
    // A container whose whole purpose is to hold a key, so its name answers the
    // question. Nothing in this product's runtime reads one.
    private const array KEY_CONTAINER_EXTENSIONS = ['jks', 'keystore', 'p12', 'pfx', 'p8', 'mobileprovision'];

    // A file holding a ledger. The desktop writes its own under
    // NATIVEPHP_STORAGE_PATH, so one carrying a schema here is the builder's.
    // A byte count cannot be the discriminator: the pragma provider opens the
    // precreated file during boot, which leaves a bare header page behind.
    /** @link ../../../../.docs/architecture/sqlite-file-precreation.md */
    private const array DATA_EXTENSIONS = ['sqlite', 'sqlite3', 'db'];

    // Read as a prefix, and every one of them is a credential belonging to the
    // machine that ran the build rather than to the person who installs it.
    private const array SECRET_ENV_PREFIXES = [
        'ANDROID_KEYSTORE_',
        'ANDROID_KEY_',
        'AWS_SECRET',
        'AZURE_CLIENT_SECRET',
        'BIFROST_',
        'CSC_KEY_PASSWORD',
        'CSC_LINK',
        'DO_SPACES_',
        'GITHUB_TOKEN',
        'NATIVEPHP_APPLE_ID_PASS',
    ];

    // Every PEM private-key header and footer ends this way, whatever algorithm
    // or encryption precedes it: RSA, EC, DSA, OPENSSH and ENCRYPTED all match.
    // Which of the two it is, is decided by what sits in front of it.
    private const string KEY_MARKER = 'PRIVATE KEY-----';

    private const string OPENS = '-----BEGIN';

    private const string CLOSES = '-----END';

    // `-----BEGIN ENCRYPTED ` is the longest prefix either marker takes, so a
    // window this size reaches the whole of one and never the whole of a
    // neighbouring marker's.
    private const int MARKER_LEAD = 24;

    // The smallest DER a private key decodes to is an Ed25519 PKCS#8 at 48.
    private const int KEY_BYTES_FLOOR = 48;

    private const int TEXT_CEILING = 2_000_000;

    // Far past where a schema can begin, and past any file a build has an
    // innocent reason to leave behind.
    private const int SCHEMA_SCAN_CEILING = 1_048_576;

    // Every refusal the tree earns, and an empty list when it earns none.
    /**
     * @return list<string>
     */
    public function refusals(string $root): array
    {
        $refusals = [];

        foreach ($this->everyFile($root) as $file) {
            $relative = substr($file->getPathname(), strlen($root) + 1);
            $text = $this->readableText($file);

            foreach ($this->judge($file, $relative, $text) as $refusal) {
                $refusals[] = $refusal;
            }
        }

        sort($refusals);

        return $refusals;
    }

    // Stat-only, so the command can tell "read the tree, found nothing wrong"
    // apart from "read no tree at all" without paying for the contents twice.
    public function fileCount(string $root): int
    {
        return count($this->everyFile($root));
    }

    // What the bundle may carry is a file the migrator has never reached: zero
    // bytes, or the bare header the pragma provider leaves. A schema means the
    // builder's own application ran against it, and rows are not needed for
    // that to be true.
    /**
     * @link ../../../../.docs/architecture/sqlite-file-precreation.md
     *
     * @return list<string>
     */
    private function ledgerRefusal(SplFileInfo $file, string $relative): array
    {
        if (! $this->carriesASchema($file->getPathname())) {
            return [];
        }

        return ['a database: '.$relative.', holding '.$file->getSize().' bytes'];
    }

    // SQLite keeps every object's CREATE statement verbatim in its schema
    // table, so the bytes answer this without opening a connection -- which on
    // a WAL database would write sidecar files beside the artefact being
    // judged. Unreadable counts as carrying one: refusal is the safe answer.
    private function carriesASchema(string $path): bool
    {
        $size = @filesize($path);
        $head = @file_get_contents($path, false, null, 0, self::SCHEMA_SCAN_CEILING);

        if ($head === false || $size === false) {
            return true;
        }

        if (stripos($head, 'CREATE TABLE') !== false) {
            return true;
        }

        return $size > self::SCHEMA_SCAN_CEILING;
    }

    /**
     * @return list<string>
     */
    private function judge(SplFileInfo $file, string $relative, ?string $text): array
    {
        $extension = strtolower($file->getExtension());

        if (in_array($extension, self::KEY_CONTAINER_EXTENSIONS, true)) {
            return ['key material: '.$relative];
        }

        if (in_array($extension, self::DATA_EXTENSIONS, true)) {
            return $this->ledgerRefusal($file, $relative);
        }

        return array_merge(
            $this->keyMaterialIn($relative, $text),
            $this->devToolingAt($relative),
            array_map(
                static fn (string $name): string => 'a secret in '.$relative.': '.$name,
                $this->secretAssignments($text),
            ),
        );
    }

    // A private key wherever it travels and whatever the file is called, read
    // from the bytes rather than the name: the bundle legitimately carries the
    // CA roots the PHP runtime needs for TLS, and judging `cacert.pem` by its
    // extension would refuse every build for a public list of certificates.
    /**
     * @return list<string>
     */
    private function keyMaterialIn(string $relative, ?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $blocks = $this->privateKeyBlocks($text);

        return $blocks === 0 ? [] : ['key material: '.$relative.' carries '.$blocks.' PEM private key block(s)'];
    }

    // A vendored package's `tools` directory: php-cs-fixer configurations, a
    // fuzzer, an h2spec script, and — in amphp/http-server — the TLS fixtures
    // its example server runs against, a private key among them. None is
    // autoloaded and none is reachable at runtime.
    /**
     * @return list<string>
     */
    private function devToolingAt(string $relative): array
    {
        $segments = explode('/', str_replace('\\', '/', $relative));

        foreach ($segments as $at => $segment) {
            if ($segment === 'vendor' && ($segments[$at + 3] ?? null) === 'tools') {
                return ["a vendored package's dev tooling: ".$relative];
            }
        }

        return [];
    }

    // Header and footer are the same string with a different word in front, so
    // each occurrence is classified by whichever sits closest to its left. A
    // file that merely NAMES the marker opens and closes it too; what tells
    // the two apart is whether real base64 sits between them.
    private function privateKeyBlocks(string $text): int
    {
        $blocks = 0;
        $opened = null;
        $offset = 0;

        while (($at = strpos($text, self::KEY_MARKER, $offset)) !== false) {
            $offset = $at + strlen(self::KEY_MARKER);
            $lead = substr($text, max(0, $at - self::MARKER_LEAD), min($at, self::MARKER_LEAD));

            if ($this->opensABlock($lead)) {
                $opened = $offset;

                continue;
            }

            $blocks += $this->closesABlock($lead, $opened, $text, $at);
            $opened = null;
        }

        return $blocks;
    }

    private function opensABlock(string $lead): bool
    {
        return strrpos($lead, self::OPENS) !== false
            && (strrpos($lead, self::CLOSES) === false || strrpos($lead, self::OPENS) > strrpos($lead, self::CLOSES));
    }

    // The body ends where `-----END` starts, not where the marker's shared tail
    // does: cut at the tail and the dashes of the footer land inside the base64
    // and nothing decodes.
    private function closesABlock(string $lead, ?int $opened, string $text, int $at): int
    {
        $closes = strrpos($lead, self::CLOSES);

        if ($opened === null || $closes === false) {
            return 0;
        }

        $footer = $at - (strlen($lead) - $closes);

        return $this->isAKeyBody(substr($text, $opened, $footer - $opened)) ? 1 : 0;
    }

    // PEM carries base64 and nothing else between its markers, so what decodes
    // is a key and what does not is a file talking about one. A key inlined in
    // JSON begins no line, so the escaped newline is normalised first rather
    // than the body being read line-first.
    private function isAKeyBody(string $between): bool
    {
        $body = '';

        foreach (explode("\n", str_replace(["\r", '\n', '\r'], "\n", $between)) as $line) {
            // An encrypted PEM in the legacy shape carries `Proc-Type:` and
            // `DEK-Info:` headers above its base64, and neither is part of it.
            if (! str_contains($line, ':')) {
                $body .= trim($line);
            }
        }

        $decoded = base64_decode($body, true);

        return is_string($decoded) && strlen($decoded) >= self::KEY_BYTES_FLOOR;
    }

    // An assignment with a value, never a bare name: a stripped credential that
    // is still mentioned in a comment, or left as `KEY=`, carries nothing.
    /**
     * @return list<string>
     */
    private function secretAssignments(?string $text): array
    {
        if ($text === null) {
            return [];
        }

        $found = [];

        foreach (explode("\n", $text) as $line) {
            $line = trim($line);
            $at = strpos($line, '=');

            if ($at === false || str_starts_with($line, '#')) {
                continue;
            }

            $found = array_merge($found, $this->namedSecret(substr($line, 0, $at), substr($line, $at + 1)));
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    private function namedSecret(string $name, string $value): array
    {
        if (trim($value, " \t\"'") === '') {
            return [];
        }

        foreach (self::SECRET_ENV_PREFIXES as $prefix) {
            if (str_starts_with($name, $prefix)) {
                return [$name];
            }
        }

        return [];
    }

    // Null for a file this cannot read as text, which is the answer the callers
    // need: neither of them may treat "unread" as "carries nothing".
    private function readableText(SplFileInfo $file): ?string
    {
        if ($file->getSize() > self::TEXT_CEILING) {
            return null;
        }

        $handle = @fopen($file->getPathname(), 'rb');

        if ($handle === false) {
            return null;
        }

        $contents = (string) stream_get_contents($handle);
        fclose($handle);

        return str_contains(substr($contents, 0, 4096), "\0") ? null : $contents;
    }

    /**
     * @return list<SplFileInfo>
     */
    private function everyFile(string $directory): array
    {
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
        ) as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $files[] = $file;
            }
        }

        return $files;
    }
}
