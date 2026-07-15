<?php

declare(strict_types=1);

namespace KurseInformatike\Shared\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = ['b', 'strong', 'i', 'em', 'a', 'br'];

    public function inline(string $html): string
    {
        if ($html === '') {
            return '';
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<?xml encoding="UTF-8"><div id="km-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('km-root');
        if (!$root) {
            return htmlspecialchars(strip_tags($html), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }

        $this->cleanChildren($root);
        $output = '';
        foreach ($root->childNodes as $child) {
            $output .= $document->saveHTML($child);
        }
        return $output;
    }

    public function plainText(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public function isHttpUrl(string $url): bool
    {
        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https'], true);
    }

    private function cleanChildren(DOMNode $parent): void
    {
        for ($node = $parent->firstChild; $node !== null;) {
            $next = $node->nextSibling;
            if ($node instanceof DOMElement) {
                $tag = strtolower($node->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    $this->cleanChildren($node);
                    while ($node->firstChild) {
                        $parent->insertBefore($node->firstChild, $node);
                    }
                    $parent->removeChild($node);
                } else {
                    $this->cleanElement($node, $tag);
                    $this->cleanChildren($node);
                }
            }
            $node = $next;
        }
    }

    private function cleanElement(DOMElement $element, string $tag): void
    {
        $href = $tag === 'a' ? $element->getAttribute('href') : '';
        while ($element->attributes->length > 0) {
            $element->removeAttributeNode($element->attributes->item(0));
        }
        if ($tag === 'a' && $this->isHttpUrl($href)) {
            $element->setAttribute('href', $href);
            $element->setAttribute('rel', 'noopener noreferrer');
        } elseif ($tag === 'a') {
            $element->removeAttribute('href');
        }
    }
}
