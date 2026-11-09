<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Notifications;

/**
 * Content and people's names, made safe to print inside a Markdown mail.
 *
 * A Markdown mail is Blade first and CommonMark second. `{{ }}` escapes the
 * characters HTML reads and leaves the ones Markdown reads, so a page
 * titled `[Review it](https://elsewhere)` became a link to elsewhere in
 * the digest, in the one place the reader is told to click. CommonMark lets
 * any ASCII punctuation be backslash-escaped, so the characters that open
 * a link, an image or emphasis are, and nothing else: `<`, `>` and `&` are
 * Blade's to escape, and escaping them here would print the entity's name.
 * A line break in a title would start a new block, so it becomes a space.
 *
 * The `@lifecycleText` directive does this and then what `{{ }}` does.
 */
final class MarkdownText
{
    public static function escape(mixed $text): string
    {
        $text = (string) preg_replace('/\R/u', ' ', (string) $text);

        return (string) preg_replace('/([\\\\`*_\[\]])/', '\\\\$1', $text);
    }
}
