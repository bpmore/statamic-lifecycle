<?php

declare(strict_types=1);

use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Models\Review;
use Bpmore\StatamicLifecycle\Reporting\GovernanceReport;
use Bpmore\StatamicLifecycle\Reporting\HtmlReport;
use Bpmore\StatamicLifecycle\Reporting\Pdf\ChromePdfRenderer;
use Bpmore\StatamicLifecycle\Reporting\Pdf\PdfRenderer;
use Bpmore\StatamicLifecycle\Reporting\Pdf\PdfUaMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;
use Symfony\Component\Process\Process;

/**
 * The governance report (spec §4): the numbers, the console, JSON and CSV,
 * the accessible HTML, and the tagged PDF printed from it.
 */
uses(RefreshDatabase::class);

beforeEach(function () {
    Carbon::setTestNow('2026-09-12 10:00:00');
    Collection::make('pages')->title('Pages')->save();
    Collection::make('news')->title('News')->save();
    User::make()->id('alice')->email('alice@example.edu')->data(['name' => 'Alice Ahmed'])->save();
    User::make()->id('bob')->email('bob@example.edu')->data(['name' => 'Bob Baptiste'])->save();

    $row = function (string $id, string $collection, string $status, ?string $owner, ?string $source, ?string $reviewed, bool $baselined = false) {
        Entry::make()->id($id)->collection($collection)->slug($id)->published(true)->data(['title' => ucfirst($id)])->save();
        EntryState::query()->insert([
            'entry_id' => $id, 'site' => 'default', 'collection' => $collection,
            'owner_id' => $owner, 'owner_type' => $owner === null ? null : 'user', 'owner_source' => $source,
            'interval_days' => 365, 'next_review_at' => '2026-09-01 00:00:00', 'status' => $status,
            'last_reviewed_at' => $reviewed, 'last_reviewed_by' => $reviewed === null ? null : 'bob',
            'baselined' => $baselined, 'computed_at' => '2026-09-12 00:00:00',
        ]);
    };

    // Ten entries: 4 confirmed owners, 2 inferred, 1 fallback, 3 nobody.
    // Eight under review (two exempt): 3 reviewed this year, 2 longer ago, 3 never (2 of them baselined).
    $row('a', 'pages', 'fresh', 'alice', 'assigned', '2026-03-04 09:00:00');
    $row('b', 'pages', 'fresh', 'alice', 'assigned', '2026-06-01 09:00:00');
    $row('c', 'pages', 'overdue', 'bob', 'assigned', '2025-01-15 09:00:00');
    $row('d', 'pages', 'expired', 'bob', 'assigned', null, true);
    $row('e', 'news', 'overdue', 'alice', 'inferred', '2025-05-20 09:00:00');
    $row('f', 'news', 'fresh', 'alice', 'inferred', '2026-08-30 09:00:00');
    $row('g', 'news', 'due_soon', 'bob', 'fallback', null, true);
    $row('h', 'news', 'fresh', null, null, null);
    $row('i', 'news', 'exempt', null, null, null);
    $row('j', 'pages', 'exempt', null, null, '2020-01-01 09:00:00');

    // Review activity: three this month, one in July, two a year ago (outside the window).
    foreach ([['a', '2026-09-02', 'current'], ['b', '2026-09-05', 'updated'], ['c', '2026-09-10', 'rewrite_scheduled'], ['f', '2026-07-14', 'current'], ['e', '2025-08-01', 'current'], ['e', '2025-09-01', 'retired']] as [$entry, $day, $outcome]) {
        Review::query()->insert(['entry_id' => $entry, 'reviewed_by' => 'bob', 'reviewed_at' => "$day 09:00:00", 'outcome' => $outcome, 'note' => null, 'content_changed' => false, 'entry_revision_ref' => null, 'created_at' => "$day 09:00:00"]);
    }

    $this->tempFile = fn (string $extension): string => tempnam(sys_get_temp_dir(), 'lifecycle-report').'.'.$extension;
});

afterEach(fn () => Carbon::setTestNow());

describe('the numbers', function () {
    it('answer the audit questions from the tables', function () {
        $report = app(GovernanceReport::class)->build(new DateTimeImmutable('2026-09-12 10:00:00'));

        expect($report['total'])->toBe(10)
            ->and($report['exempt'])->toBe(2)
            ->and($report['under_review'])->toBe(8)
            ->and($report['late'])->toBe(3)
            ->and($report['owners'])->toBe(['confirmed' => 4, 'inferred' => 2, 'fallback' => 1, 'nobody' => 3, 'confirmed_share' => 0.4])
            ->and($report['reviews'])->toBe(['recent' => 3, 'recent_share' => 0.375, 'stale' => 2, 'never' => 3, 'never_since_baseline' => 2])
            ->and($report['headline'])->toBe('40% of 10 entries have a confirmed owner. 38% of the 8 entries under review were reviewed in the last 12 months. 3 are overdue.');
    });

    it('say where the overdue content is, by collection, site and owner', function () {
        $report = app(GovernanceReport::class)->build(new DateTimeImmutable('2026-09-12 10:00:00'));

        $pages = collect($report['by_collection'])->firstWhere('key', 'pages');
        expect($pages)->toMatchArray(['label' => 'Pages', 'total' => 5, 'overdue' => 1, 'expired' => 1, 'late' => 2, 'late_share' => 0.5, 'never_reviewed' => 1, 'exempt' => 1])
            ->and(collect($report['by_site'])->firstWhere('key', 'default')['late'])->toBe(3)
            ->and(collect($report['by_owner'])->pluck('late', 'label')->all())->toBe(['Bob Baptiste' => 2, 'Alice Ahmed' => 1, 'Nobody' => 0]);
    });

    it('count review activity month by month, quiet months included', function () {
        $report = app(GovernanceReport::class)->build(new DateTimeImmutable('2026-09-12 10:00:00'));

        expect($report['activity'])->toHaveCount(12)
            ->and($report['activity'][0]['month'])->toBe('2025-10')
            ->and($report['activity'][11])->toMatchArray(['month' => '2026-09', 'label' => 'Sep 2026', 'reviews' => 3, 'outcomes' => ['current' => 1, 'updated' => 1, 'rewrite_scheduled' => 1, 'retired' => 0]])
            ->and($report['activity'][9]['reviews'])->toBe(1)   // July
            ->and(collect($report['activity'])->sum('reviews'))->toBe(4);   // the two from a year ago are outside the window
    });

    it('do not round 99.6% up to a claim of 100%', function () {
        expect(GovernanceReport::percent(0.996))->toBe('99%')
            ->and(GovernanceReport::percent(1.0))->toBe('100%')
            ->and(GovernanceReport::percent(0.375))->toBe('38%')
            ->and(GovernanceReport::percent(null))->toBe('—');
    });

    it('say so when nothing is tracked', function () {
        EntryState::query()->delete();

        expect(app(GovernanceReport::class)->build(new DateTimeImmutable)['headline'])->toBe('Nothing is tracked yet.');
    });
});

describe('the console', function () {
    it('prints the headline and the tables', function () {
        $this->artisan('lifecycle:report')
            ->expectsOutputToContain('40% of 10 entries have a confirmed owner.')
            ->expectsOutputToContain('Confirmed: a person named on the page')
            ->expectsOutputToContain('Sep 2026')
            ->assertExitCode(0);
    });

    it('gives JSON and CSV, to the screen or a file', function () {
        $json = ($this->tempFile)('json');
        $this->artisan("lifecycle:report --format=json --out={$json}")->expectsOutputToContain('JSON report written')->assertExitCode(0);
        $decoded = json_decode((string) file_get_contents($json), true);
        expect($decoded['owners']['confirmed'])->toBe(4)->and($decoded['generated_at'])->toStartWith('2026-09-12T10:00:00');

        $csv = ($this->tempFile)('csv');
        $this->artisan("lifecycle:report --format=csv --out={$csv}")->assertExitCode(0);
        $lines = explode("\n", trim((string) file_get_contents($csv)));
        expect($lines[0])->toBe('group,key,label,entries,overdue,expired,late_share,never_reviewed,exempt')
            ->and($lines)->toContain('collection,pages,Pages,5,1,1,0.5,1,1')
            ->and($lines)->toContain('owner,bob,"Bob Baptiste",3,1,1,0.6667,2,0')
            ->and($lines)->toContain('month,2026-09,"Sep 2026",3,,,,,');

        @unlink($json);
        @unlink($csv);
    });

    it('refuses an unknown format, a PDF with nowhere to go, and an uninstalled addon', function () {
        $this->artisan('lifecycle:report --format=docx')->expectsOutputToContain('Unknown format [docx]')->assertExitCode(1);
        $this->artisan('lifecycle:report --format=pdf')->expectsOutputToContain('A PDF needs somewhere to go')->assertExitCode(1);
    });
});

describe('the HTML', function () {
    it('would pass its own standards as a web page', function () {
        $html = app(HtmlReport::class)->render(new DateTimeImmutable('2026-09-12 10:00:00'));

        expect($html)->toContain('<!DOCTYPE html>')
            ->and($html)->toMatch('/<html lang="[a-z]{2}/')
            ->and($html)->toContain('<title>Content Governance Report</title>')
            ->and(substr_count($html, '<h1'))->toBe(1)
            ->and($html)->toContain('<main>')
            // Real table semantics, not a grid of divs.
            ->and($html)->toContain('<caption>')
            ->and($html)->toContain('scope="col"')
            ->and($html)->toContain('scope="row"')
            // No <strong>: Chrome maps it to a structure type PDF/UA does not know.
            ->and($html)->not->toContain('<strong')
            ->and($html)->toContain('40% of 10 entries have a confirmed owner.')
            ->and($html)->toContain('Bob Baptiste')
            ->and($html)->toContain('What this report does not cover');
    });

    it('is written out by the command', function () {
        $path = ($this->tempFile)('html');

        $this->artisan("lifecycle:report --format=html --out={$path}")
            ->expectsOutputToContain('Accessible HTML report written')
            ->assertExitCode(0);

        expect(file_get_contents($path))->toContain('Content Governance Report');

        @unlink($path);
    });
});

describe('the PDF', function () {
    it('says so rather than failing when there is no browser to print with', function () {
        app()->bind(PdfRenderer::class, fn (): PdfRenderer => new ChromePdfRenderer('/no/such/chrome'));

        $this->artisan('lifecycle:report --format=pdf --out='.($this->tempFile)('pdf'))
            ->expectsOutputToContain('No browser was found')
            ->assertExitCode(1);
    });

    it('is tagged, titled, language-tagged and carries the PDF/UA metadata', function () {
        $path = ($this->tempFile)('pdf');

        $this->artisan("lifecycle:report --format=pdf --out={$path}")->expectsOutputToContain('Tagged PDF report written')->assertExitCode(0);

        $pdf = (string) file_get_contents($path);
        expect($pdf)->toStartWith('%PDF-')
            ->and($pdf)->toContain('/StructTreeRoot')
            ->and($pdf)->toContain('/Marked true')
            ->and($pdf)->toContain('/Lang')
            ->and($pdf)->toContain('/Metadata')
            ->and($pdf)->toContain('<pdfuaid:part>1</pdfuaid:part>')
            ->and($pdf)->toContain('Content Governance Report');

        @unlink($path);
    })->skip(fn (): bool => ! (new ChromePdfRenderer)->isAvailable(), 'No browser installed to render a PDF with.');

    it('passes PDF/UA-1 validation', function () {
        // The authoritative answer. Chrome writes no XMP packet, so without
        // PdfUaMetadata this fails clause 7.1-8 — a tagged PDF that is still
        // not conformant, which is the screenshot to avoid.
        $path = ($this->tempFile)('pdf');
        $this->artisan("lifecycle:report --format=pdf --out={$path}")->assertExitCode(0);

        $process = new Process(['verapdf', '-f', 'ua1', '--format', 'json', $path]);
        $process->setTimeout(120);
        $process->run();
        $result = json_decode($process->getOutput(), true);

        expect($process->getExitCode())->toBe(0, 'veraPDF: '.substr($process->getOutput(), 0, 2000))
            ->and(data_get($result, 'report.jobs.0.validationResult.0.compliant') ?? data_get($result, 'report.jobs.0.validationResult.compliant'))->toBeTrue();

        @unlink($path);
    })->skip(fn (): bool => ! (new ChromePdfRenderer)->isAvailable() || ! veraPdfInstalled(), 'Needs both a browser and veraPDF.');

    it('leaves a PDF alone when it cannot safely add metadata, and never adds it twice', function () {
        $path = ($this->tempFile)('pdf');
        file_put_contents($path, "%PDF-1.7\nnot really a pdf\n");
        (new PdfUaMetadata)->addTo($path, 'Report');
        expect(file_get_contents($path))->toBe("%PDF-1.7\nnot really a pdf\n");

        file_put_contents($path, "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Metadata 2 0 R >>\nendobj\ntrailer\n<< /Size 3 /Root 1 0 R >>\nstartxref\n9\n%%EOF\n");
        $before = file_get_contents($path);
        (new PdfUaMetadata)->addTo($path, 'Report');
        expect(file_get_contents($path))->toBe($before);

        @unlink($path);
    });

    it('accepts a PDF written by the browser process', function () {
        // The success path without a real Chrome: a stub browser that writes
        // a minimal classic-xref PDF where it is told to.
        $destination = ($this->tempFile)('pdf');
        @unlink($destination);

        $browser = ($this->tempFile)('sh');
        file_put_contents($browser, <<<'SH'
            #!/bin/sh
            for arg in "$@"; do
              case "$arg" in
                --print-to-pdf=*) printf '%%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Size 2 /Root 1 0 R >>\nstartxref\n9\n%%%%EOF\n' > "${arg#--print-to-pdf=}" ;;
              esac
            done
            SH);
        chmod($browser, 0755);

        (new ChromePdfRenderer($browser))->render('<html lang="en"><title>x</title><main><h1>x</h1></main></html>', $destination, 'Stub');

        $pdf = (string) file_get_contents($destination);
        expect($pdf)->toStartWith('%PDF-1.4')
            ->and($pdf)->toContain('/Metadata 2 0 R')
            ->and($pdf)->toContain('<dc:title><rdf:Alt><rdf:li xml:lang="x-default">Stub</rdf:li></rdf:Alt></dc:title>')
            ->and($pdf)->toContain('/Prev 9');

        @unlink($destination);
        @unlink($browser);
    });
});
