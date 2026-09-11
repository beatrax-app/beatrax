<?php

declare(strict_types=1);

namespace Modules\Ingestion\Internal\Adapters\Ics;

use Smalot\PdfParser\Element\ElementArray;
use Smalot\PdfParser\Element\ElementXRef;
use Smalot\PdfParser\Page;
use Smalot\PdfParser\PDFObject;

// How many bytes of content stream a page will hand the layout reader, summed
// without concatenating them. The three branches are the ones Page::getText()
// resolves; a shape none of them recognise answers zero, so this bounds what it
// can see and never refuses a page it could not measure.
final class PdfContentSize
{
    public static function ofPage(Page $page): int
    {
        $contents = $page->get('Contents');

        if ($contents instanceof ElementArray) {
            return self::ofElements($contents->getContent());
        }

        if ($contents instanceof PDFObject) {
            $header = $contents->getHeader();
            $elements = $header === null ? [] : $header->getElements();

            return is_array($elements) && is_numeric(key($elements))
                ? self::ofElements($elements)
                : self::lengthOf($contents);
        }

        return 0;
    }

    private static function ofElements(mixed $elements): int
    {
        if (! is_array($elements)) {
            return 0;
        }

        $bytes = 0;

        foreach ($elements as $element) {
            $resolved = $element instanceof ElementXRef ? $element->getObject() : $element;
            if ($resolved instanceof PDFObject) {
                $bytes += self::lengthOf($resolved);
            }
        }

        return $bytes;
    }

    private static function lengthOf(PDFObject $object): int
    {
        $content = $object->getContent();

        return is_string($content) ? strlen($content) : 0;
    }
}
