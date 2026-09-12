<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Reporting\GovernanceReport;
use Bpmore\StatamicLifecycle\Reporting\HtmlReport;
use Bpmore\StatamicLifecycle\Reporting\Pdf\PdfRenderer;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use DateTimeImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Console\RunsInPlease;

/**
 * `php please lifecycle:report [--format=table|json|csv|html|pdf] [--out=path]`
 *
 * The governance report (spec §4), from what the tables hold now — it never
 * recomputes. Tables on the console by default; the other formats go to
 * --out, or to standard output where that makes sense (json, csv, html).
 * The PDF is tagged and passes PDF/UA validation, because a governance
 * report an auditor cannot read is not one.
 */
class ReportLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:report
        {--format=table : table, json, csv, html or pdf}
        {--out= : Write the report to this path (required for pdf)}';

    protected $description = 'The governance report: ownership, review recency, overdue content, and review activity';

    public function handle(LifecycleDatabase $database, GovernanceReport $report): int
    {
        if (! $database->isInstalled()) {
            $this->components->error('Lifecycle is not installed on ['.LifecycleDatabase::connectionName().']. Run `php please lifecycle:install` first.');

            return self::FAILURE;
        }

        $format = strtolower((string) $this->option('format'));
        $out = $this->option('out');
        $now = Carbon::now()->toImmutable();

        return match ($format) {
            'table' => $this->tables($report->build($now)),
            'json' => $this->write(json_encode($this->json($report->build($now)), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n", $out, 'JSON report'),
            'csv' => $this->write($this->csv($report->build($now)), $out, 'CSV report'),
            'html' => $this->write(app(HtmlReport::class)->render($now), $out, 'Accessible HTML report'),
            'pdf' => $this->pdf($now, $out),
            default => $this->unknownFormat($format),
        };
    }

    /** @param  array<string, mixed>  $data */
    private function tables(array $data): int
    {
        $this->newLine();
        $this->line('  <options=bold>'.$data['headline'].'</>');
        $this->newLine();

        if ($data['total'] === 0) {
            $this->components->warn('Run `php please lifecycle:baseline` to start tracking content.');

            return self::SUCCESS;
        }

        $percent = fn (int $part, int $whole): string => GovernanceReport::percent($whole === 0 ? null : $part / $whole);

        $this->table(['Owner', 'Entries', 'Share'], [
            ['Confirmed: a person named on the page', number_format($data['owners']['confirmed']), $percent($data['owners']['confirmed'], $data['total'])],
            ['Inferred from the last author', number_format($data['owners']['inferred']), $percent($data['owners']['inferred'], $data['total'])],
            ['The owner of last resort', number_format($data['owners']['fallback']), $percent($data['owners']['fallback'], $data['total'])],
            ['Nobody', number_format($data['owners']['nobody']), $percent($data['owners']['nobody'], $data['total'])],
        ]);

        $this->table(['Last reviewed', 'Entries', 'Share of those under review'], [
            ['Within the last '.GovernanceReport::RECENT_MONTHS.' months', number_format($data['reviews']['recent']), $percent($data['reviews']['recent'], $data['under_review'])],
            ['Longer ago than that', number_format($data['reviews']['stale']), $percent($data['reviews']['stale'], $data['under_review'])],
            ['Never', number_format($data['reviews']['never']), $percent($data['reviews']['never'], $data['under_review'])],
            ['  of which never since baselining', number_format($data['reviews']['never_since_baseline']), ''],
        ]);

        foreach (['by_collection' => 'Collection', 'by_site' => 'Site', 'by_owner' => 'Owner'] as $key => $heading) {
            if ($data[$key] === []) {
                continue;
            }

            $this->table(
                [$heading, 'Entries', 'Overdue', 'Expired', 'Share late', 'Never reviewed'],
                array_map(fn (array $row): array => [
                    $row['label'],
                    number_format($row['total']),
                    number_format($row['overdue']),
                    number_format($row['expired']),
                    GovernanceReport::percent($row['late_share']),
                    number_format($row['never_reviewed']),
                ], $data[$key]),
            );
        }

        $this->table(
            ['Month', 'Reviews', 'Still current', 'Updated', 'Rewrite scheduled', 'Retire'],
            array_map(fn (array $month): array => [
                $month['label'],
                number_format($month['reviews']),
                number_format($month['outcomes']['current']),
                number_format($month['outcomes']['updated']),
                number_format($month['outcomes']['rewrite_scheduled']),
                number_format($month['outcomes']['retired']),
            ], $data['activity']),
        );

        return self::SUCCESS;
    }

    /**
     * The same numbers, with the date as text.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function json(array $data): array
    {
        $data['generated_at'] = $data['generated_at']->format(DATE_ATOM);

        return $data;
    }

    /**
     * The three breakdowns as one flat table: a spreadsheet reads it, and
     * the headline numbers are one filter away.
     *
     * @param  array<string, mixed>  $data
     */
    private function csv(array $data): string
    {
        $handle = fopen('php://temp', 'r+');
        $put = fn (array $row) => fputcsv($handle, $row, escape: '');

        $put(['group', 'key', 'label', 'entries', 'overdue', 'expired', 'late_share', 'never_reviewed', 'exempt']);

        foreach (['collection' => 'by_collection', 'site' => 'by_site', 'owner' => 'by_owner'] as $group => $key) {
            foreach ($data[$key] as $row) {
                $put([
                    $group,
                    $row['key'] ?? '',
                    $row['label'],
                    $row['total'],
                    $row['overdue'],
                    $row['expired'],
                    $row['late_share'] === null ? '' : round($row['late_share'], 4),
                    $row['never_reviewed'],
                    $row['exempt'],
                ]);
            }
        }

        foreach ($data['activity'] as $month) {
            $put(['month', $month['month'], $month['label'], $month['reviews'], '', '', '', '', '']);
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    private function write(string $content, ?string $out, string $what): int
    {
        if ($out === null || $out === '') {
            $this->output->write($content);

            return self::SUCCESS;
        }

        file_put_contents($out, $content);
        $this->components->info("{$what} written to {$out}.");

        return self::SUCCESS;
    }

    /**
     * A PDF when a browser is there to print it. A site with no browser gets
     * a sentence saying why, and the HTML is one option away.
     */
    private function pdf(DateTimeImmutable $now, ?string $out): int
    {
        if ($out === null || $out === '') {
            $this->components->error('A PDF needs somewhere to go: --out=storage/governance-report.pdf');

            return self::FAILURE;
        }

        $renderer = app(PdfRenderer::class);

        if (! $renderer->isAvailable()) {
            $this->components->warn('No browser was found to render the PDF with. Install Chrome, or export the HTML instead: --format=html --out=report.html');

            return self::FAILURE;
        }

        $renderer->render(app(HtmlReport::class)->render($now), $out, __(HtmlReport::TITLE));
        $this->components->info("Tagged PDF report written to {$out}.");

        return self::SUCCESS;
    }

    private function unknownFormat(string $format): int
    {
        $this->components->error("Unknown format [{$format}]. Use table, json, csv, html or pdf.");

        return self::FAILURE;
    }
}
