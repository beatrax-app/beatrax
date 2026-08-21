<?php

declare(strict_types=1);

use Modules\Ingestion\Internal\Exceptions\SniffMismatchException;
use Modules\Ingestion\Public\Dto\SniffResult;
use Modules\Ingestion\Public\Services\HeaderSniffer;
use Modules\Receipts\Public\Pipeline\EmlHeaderProfile;
use Modules\Receipts\Public\Pipeline\MboxHeaderProfile;

beforeEach(function (): void {
    $this->sniffer = $this->app->make(HeaderSniffer::class);
});

function writeTempEml(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-eml-').'.eml';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

function writeTempMbox(string $body): string
{
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-mbox-').'.mbox';
    file_put_contents($tmp, $body);
    register_shutdown_function(static function () use ($tmp): void {
        @unlink($tmp);
    });

    return $tmp;
}

it('accepts a real .eml head and reports an eml SniffResult', function (): void {
    $path = writeTempEml("From: a@b.test\r\nSubject: hi\r\n\r\nBody text.\r\n");
    $result = $this->sniffer->sniff($path, EmlHeaderProfile::FORMAT);

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe(EmlHeaderProfile::FORMAT);
    expect($result->encoding)->toBe(EmlHeaderProfile::SOURCE_ENCODING);
    expect($result->hasHeader)->toBeFalse();
    expect($result->columnCount)->toBe(0);
});

it('rejects a non-.eml extension when sniffing as eml', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-bad-').'.txt';
    file_put_contents($tmp, 'plain text body');

    expect(fn () => $this->sniffer->sniff($tmp, EmlHeaderProfile::FORMAT))
        ->toThrow(SniffMismatchException::class, "That file doesn't look like an email message. Drop in a .eml file.");

    @unlink($tmp);
});

it('rejects an .eml file whose body lacks RFC 822 header anchors', function (): void {
    $path = writeTempEml('this is just plain text with no header line');

    expect(fn () => $this->sniffer->sniff($path, EmlHeaderProfile::FORMAT))
        ->toThrow(SniffMismatchException::class, "That file doesn't look like an email message. Drop in a .eml file.");
});

it('accepts a real .mbox head and reports an mbox SniffResult', function (): void {
    $path = writeTempMbox("From sender@example.test Thu Jan 01 00:00:00 2026\r\nFrom: sender@example.test\r\nSubject: test\r\n\r\nBody text.\r\n");
    $result = $this->sniffer->sniff($path, MboxHeaderProfile::FORMAT);

    expect($result)->toBeInstanceOf(SniffResult::class);
    expect($result->format)->toBe(MboxHeaderProfile::FORMAT);
    expect($result->hasHeader)->toBeFalse();
});

it('rejects a non-.mbox extension when sniffing as mbox', function (): void {
    $tmp = tempnam(sys_get_temp_dir(), 'sniff-bad-').'.txt';
    file_put_contents($tmp, 'From sender@example.test plain text');

    expect(fn () => $this->sniffer->sniff($tmp, MboxHeaderProfile::FORMAT))
        ->toThrow(SniffMismatchException::class, "That file doesn't look like a mailbox archive. Drop in a .mbox file.");

    @unlink($tmp);
});

it('rejects an .mbox file that does not start with "From "', function (): void {
    $path = writeTempMbox("Subject: missing From-line\r\nThis body has no mboxrd prefix.");

    expect(fn () => $this->sniffer->sniff($path, MboxHeaderProfile::FORMAT))
        ->toThrow(SniffMismatchException::class, "That file doesn't look like a mailbox archive. Drop in a .mbox file.");
});
