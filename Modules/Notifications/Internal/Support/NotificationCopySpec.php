<?php

declare(strict_types=1);

namespace Modules\Notifications\Internal\Support;

/**
 * @link ../../../../.docs/features/notifications/reader-language-copy.md
 */
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

    public function title(): ?string
    {
        return $this->title->render();
    }

    public function body(): ?string
    {
        $parts = AllOrNothing::map($this->body, static fn (CopyLine $line): ?string => $line->render());

        return $parts === null ? null : implode(' ', $parts);
    }

    // What the row's own title/body columns get: the OS push and any device on
    // an older release read those, so they always hold something. A key that
    // does not resolve at write time is a defect, and printing it is how it
    // gets found rather than shipped as a blank notification.
    public function storedTitle(): string
    {
        return $this->title() ?? $this->title->key;
    }

    public function storedBody(): string
    {
        return $this->body() ?? implode(' ', array_map(
            static fn (CopyLine $line): string => $line->key,
            $this->body,
        ));
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

        $body = AllOrNothing::map($rawBody, CopyLine::fromArray(...));

        return $body === null ? null : new self($title, array_values($body));
    }
}
