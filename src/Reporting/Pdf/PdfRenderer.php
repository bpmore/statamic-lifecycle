<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting\Pdf;

use RuntimeException;

/**
 * Turns the report's HTML into a tagged PDF.
 *
 * An interface because the renderer is a system dependency and system
 * dependencies come and go — and because a site with no browser installed
 * should still get the HTML.
 *
 * The pipeline is A11y Docs' (spec §0): accessible HTML printed by headless
 * Chrome, then an XMP packet so the file passes PDF/UA validation. Proven
 * there against veraPDF; carried here rather than re-solved.
 */
interface PdfRenderer
{
    public function isAvailable(): bool;

    /**
     * The title is passed in rather than looked up, so a renderer works outside
     * a booted framework — which is what makes it testable on its own.
     *
     * @throws RuntimeException when the render fails
     */
    public function render(string $html, string $destination, string $title = 'Content Governance Report'): void;
}
