<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

// The title and body of a notification as keys and values. It rides along in
// the row's `params` column, which is already registered as sensitive and
// already syncs, so re-renderable copy needs no new column.
final readonly class NotificationCopySpec
{
    public const PARAMS_KEY = 'copy';

    /**
     * @param  list<CopyLine>  $body
     */
    private function __construct(
        private CopyLine $title,
        private array $body,
    ) {}

    /**
     * @param  list<CopyLine>  $body
     */
    public static function make(CopyLine $title, array $body): self
    {
        return new self($title, $body);
    }

    public static function of(CopyLine $title, CopyLine $body): self
    {
        return new self($title, [$body]);
    }

    public function title(): string
    {
        return $this->title->render();
    }

    public function body(): string
    {
        $parts = [];
        foreach ($this->body as $line) {
            $parts[] = $line->render();
        }

        return implode(' ', $parts);
    }

    /**
     * @return array{title: array{key: string, replace: array<string, string|int|float|array{kind: string, value: string}>, count: int|null}, body: list<array{key: string, replace: array<string, string|int|float|array{kind: string, value: string}>, count: int|null}>}
     */
    public function toArray(): array
    {
        $body = [];
        foreach ($this->body as $line) {
            $body[] = $line->toArray();
        }

        return ['title' => $this->title->toArray(), 'body' => $body];
    }

    public static function fromArray(mixed $raw): ?self
    {
        if (! is_array($raw)) {
            return null;
        }

        $title = CopyLine::fromArray($raw['title'] ?? null);
        $rawBody = $raw['body'] ?? null;

        if ($title === null || ! is_array($rawBody) || $rawBody === []) {
            return null;
        }

        $body = [];
        foreach ($rawBody as $rawLine) {
            $line = CopyLine::fromArray($rawLine);
            if ($line === null) {
                return null;
            }
            $body[] = $line;
        }

        return new self($title, $body);
    }
}
