<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting\Pdf;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Prints the report through headless Chrome.
 *
 * Chrome's print-to-PDF produces a genuinely tagged PDF: structure tree,
 * MarkInfo, language, title and DisplayDocTitle. Measured, not assumed — in
 * A11y Docs the output is run through that addon's own PDF inspector and
 * veraPDF; here the test suite runs veraPDF where it is installed.
 *
 * The one thing it does not write is an XMP metadata packet, which PDF/UA
 * requires, so {@see PdfUaMetadata} adds one afterwards.
 */
final class ChromePdfRenderer implements PdfRenderer
{
    /** The usual places a browser lives, in the order worth trying. */
    private const CANDIDATES = [
        '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
        '/Applications/Chromium.app/Contents/MacOS/Chromium',
        '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
        '/usr/bin/google-chrome',
        '/usr/bin/chromium',
        '/usr/bin/chromium-browser',
        '/snap/bin/chromium',
    ];

    public function __construct(
        private readonly ?string $binary = null,
        private readonly int $timeoutSeconds = 120,
        private readonly PdfUaMetadata $metadata = new PdfUaMetadata,
    ) {}

    public function isAvailable(): bool
    {
        return $this->binary() !== null;
    }

    public function render(string $html, string $destination, string $title = 'Content Governance Report'): void
    {
        $binary = $this->binary()
            ?? throw new RuntimeException(
                'No browser was found to render the PDF with. Install Chrome, or export the HTML instead.'
            );

        // Written to a file rather than passed as a data: URL, because a report
        // over a large site is larger than a command line.
        $source = tempnam(sys_get_temp_dir(), 'lifecycle-report').'.html';
        file_put_contents($source, $html);

        try {
            $process = new Process([
                $binary,
                '--headless',
                '--disable-gpu',
                '--no-sandbox',
                // Chrome's default header and footer stamp a URL and a date
                // into the margins as untagged artifacts.
                '--no-pdf-header-footer',
                '--print-to-pdf='.$destination,
                'file://'.$source,
            ]);
            $process->setTimeout($this->timeoutSeconds);
            $process->run();

            // Chromium has reported "bytes written" on a real server while the
            // file looked absent from here: PHP caches stat results, and the
            // write is not always visible the instant the process exits.
            // Clearing the cache and looking again for a moment covers both.
            $bytes = 0;

            for ($attempt = 0; $attempt < 20; $attempt++) {
                clearstatcache(true, $destination);
                $bytes = is_file($destination) ? (int) filesize($destination) : 0;

                if ($bytes > 0) {
                    break;
                }

                usleep(50_000);
            }

            if ($bytes === 0) {
                throw new RuntimeException(sprintf(
                    'The browser %s but wrote nothing to %s.%s%s',
                    ($process->getExitCode() ?? -1) === 0 ? 'exited cleanly' : 'failed (exit '.$process->getExitCode().')',
                    $destination,
                    $this->confinementHint($destination),
                    "\n".trim($process->getErrorOutput() ?: 'no output'),
                ));
            }
        } finally {
            @unlink($source);
        }

        // Without this the report is a tagged PDF that still fails PDF/UA
        // validation on one clause.
        $this->metadata->addTo($destination, $title);
    }

    /**
     * Ubuntu ships Chromium as a snap, and a snap gets a private /tmp and
     * cannot write outside the user's home. It exits 0 and reports the bytes it
     * wrote, to a path nothing else can see, so the failure looks like the
     * browser succeeding and this addon being wrong. Measured on a Forge
     * server by A11y Docs: --pdf=/tmp/report.pdf produced nothing, the same
     * run under the site directory produced a valid PDF.
     */
    private function confinementHint(string $destination): string
    {
        $binary = $this->binary() ?? '';

        $confined = str_starts_with($binary, '/snap/')
            || str_contains($binary, 'chromium-browser')
            || is_dir('/snap/chromium');

        if (! $confined || str_starts_with($destination, (string) getenv('HOME'))) {
            return '';
        }

        return ' This browser looks snap-confined, and a snap cannot write outside your home directory.'
            .' Choose a destination inside the site, such as storage/report.pdf.';
    }

    private function binary(): ?string
    {
        if ($this->binary !== null) {
            return is_executable($this->binary) ? $this->binary : null;
        }

        foreach (self::CANDIDATES as $candidate) {
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
