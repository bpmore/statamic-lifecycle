<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reporting;

use DateTimeImmutable;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Statamic\Facades\Site;

/**
 * The exported report, as accessible HTML.
 *
 * HTML first and PDF second, because a tagged PDF is made by printing a
 * well-structured page — headings in order, real table headers with scopes, a
 * language, a title, and no meaning carried by colour alone. Get the HTML right
 * and the PDF follows; get it wrong and no amount of post-processing saves it.
 */
final class HtmlReport
{
    public const TITLE = 'Content Governance Report';

    public function __construct(
        private readonly GovernanceReport $report,
        private readonly ViewFactory $views,
    ) {}

    public function render(DateTimeImmutable $now): string
    {
        return $this->views->make('lifecycle::report', $this->data($now))->render();
    }

    /** @return array<string, mixed> */
    public function data(DateTimeImmutable $now): array
    {
        $site = Site::current();

        return [
            'title' => __(self::TITLE),
            'site' => (string) $site->name(),
            'language' => str_replace('_', '-', (string) ($site->lang() ?: 'en')),
            'generatedAt' => $now->format('l, F jS, Y \a\t H:i'),
            'report' => $this->report->build($now),
        ];
    }
}
