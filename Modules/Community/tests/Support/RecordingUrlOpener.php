<?php

declare(strict_types=1);

namespace Modules\Community\Tests\Support;

use Modules\Core\Public\Contracts\ExternalUrlOpener;

/**
 * Replaces Native\Desktop\Fakes\ShellFake here. That fake is real only in the
 * repository Composer root, and it stood in for a contract these tests should
 * never have been typed by: the module under test runs on the phone too.
 */
final class RecordingUrlOpener implements ExternalUrlOpener
{
    /** @var list<string> */
    public array $openCalls = [];

    public function __construct(private readonly bool $takesTheUrl = true) {}

    public function open(string $url): bool
    {
        $this->openCalls[] = $url;

        return $this->takesTheUrl;
    }
}
