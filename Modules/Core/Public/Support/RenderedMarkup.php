<?php

declare(strict_types=1);

namespace Modules\Core\Public\Support;

use Dom\Element;
use Dom\HTMLDocument;
use Modules\Core\Public\Exceptions\MarkupParseFailedException;
use Throwable;

// A rendered response IS a document, so it is read by the HTML5 parser rather
// than by patterns: nesting, attribute order and quoting stop being the
// caller's problem, and `data-testid="x" [\s\S]*? >2<` stops matching a `2`
// that belongs to a different element three sections down the page.
/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-guard-that-reads-html-with-a-regex
 */
final class RenderedMarkup
{
    private function __construct(
        private readonly HTMLDocument $document,
        private readonly ?Element $element,
    ) {}

    public static function of(string $html): self
    {
        if (trim($html) === '') {
            throw new MarkupParseFailedException('an empty document', '');
        }

        try {
            $document = HTMLDocument::createFromString($html, LIBXML_NOERROR);
        } catch (Throwable $failure) {
            throw new MarkupParseFailedException($failure->getMessage(), substr($html, 0, 60));
        }

        self::assertRead($document, $html);

        return new self($document, null);
    }

    /**
     * @return list<self>
     */
    public function all(string $selector): array
    {
        $found = [];

        foreach ($this->scope()->querySelectorAll($selector) as $element) {
            $found[] = new self($this->document, $element);
        }

        return $found;
    }

    public function first(string $selector): ?self
    {
        $element = $this->scope()->querySelector($selector);

        return $element === null ? null : new self($this->document, $element);
    }

    public function firstOrFail(string $selector): self
    {
        $element = $this->first($selector);

        if ($element === null) {
            throw new MarkupParseFailedException('no element matched `'.$selector.'`', substr($this->html(), 0, 60));
        }

        return $element;
    }

    public function has(string $selector): bool
    {
        return $this->scope()->querySelector($selector) !== null;
    }

    public function count(string $selector): int
    {
        return $this->scope()->querySelectorAll($selector)->length;
    }

    public function text(): string
    {
        $flat = str_replace(["\r", "\n", "\t"], ' ', $this->scope()->textContent ?? '');

        return implode(' ', array_filter(explode(' ', $flat), static fn (string $word): bool => $word !== ''));
    }

    public function attribute(string $name): ?string
    {
        return $this->element !== null && $this->element->hasAttribute($name)
            ? $this->element->getAttribute($name)
            : null;
    }

    public function tag(): string
    {
        return $this->element === null ? '#document' : strtolower($this->element->tagName);
    }

    public function html(): string
    {
        return $this->document->saveHtml($this->element);
    }

    private function scope(): HTMLDocument|Element
    {
        return $this->element ?? $this->document;
    }

    // An HTML5 parse never reports failure: it answers html/head/body for any
    // bytes at all, including bytes in an encoding it guessed wrong and read as
    // spaced-out nonsense. So the two things it cannot fake are checked here
    // instead, and both raise rather than becoming an empty element list.
    private static function assertRead(HTMLDocument $document, string $html): void
    {
        if (mb_check_encoding($html, 'UTF-8') === false) {
            throw new MarkupParseFailedException('bytes the parser could only guess an encoding for', bin2hex(substr($html, 0, 16)));
        }

        if ($document->documentElement === null) {
            throw new MarkupParseFailedException('a document with no root element', substr($html, 0, 60));
        }
    }
}
