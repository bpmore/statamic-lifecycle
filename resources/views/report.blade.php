<!DOCTYPE html>
{{-- A governance report goes to an auditor, a records officer, a board. It
     has to be accessible itself: every choice below is about the tagged PDF
     Chrome makes of it, the same pipeline A11y Docs proved against veraPDF. --}}
<html lang="{{ $language }}">
<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        /* Print styles only. No colour carries meaning on its own anywhere in
           this document: every state is spelled out in words. */
        body { font: 11pt/1.5 -apple-system, "Segoe UI", system-ui, sans-serif; color: #111; margin: 2.5cm 2cm; }
        h1 { font-size: 20pt; margin: 0 0 .2em; }
        h2 { font-size: 14pt; margin: 1.6em 0 .4em; }
        p, li { max-width: 36em; }
        table { border-collapse: collapse; width: 100%; margin: .6em 0 1.4em; }
        caption { text-align: left; font-weight: 600; padding-bottom: .3em; }
        th, td { border: 1px solid #999; padding: .35em .6em; text-align: left; vertical-align: top; }
        thead th { background: #f0f0f0; }
        td.n, th.n { text-align: right; }
        .meta { color: #444; font-size: 10pt; }
        /* Emphasis is a class, not a strong element: Chrome maps that to a
           "Strong" structure type with no role map, and the PDF fails PDF/UA
           clause 7.1-5. Measured by A11y Docs, not guessed. */
        .figure { font-weight: 600; }
    </style>
</head>
<body>
<main>
    <h1>{{ $title }}</h1>
    <p class="meta">{{ $site }} · {{ $generatedAt }}</p>

    <p>{{ $report['headline'] }}</p>

    @if ($report['total'] > 0)
        <h2>Who owns the content</h2>
        <p>
            A confirmed owner is a person named on the page. An inferred owner is the
            addon's guess from the last author, shown as a guess until somebody confirms
            it; the owner of last resort is the site's safety net, not a decision about
            the page.
        </p>
        <table>
            <caption>Ownership across {{ \Bpmore\StatamicLifecycle\Reporting\GovernanceReport::entries($report['total']) }}</caption>
            <thead>
                <tr><th scope="col">Owner</th><th scope="col" class="n">Entries</th><th scope="col" class="n">Share</th></tr>
            </thead>
            <tbody>
                @foreach ([
                    'Confirmed: a person named on the page' => $report['owners']['confirmed'],
                    'Inferred from the last author' => $report['owners']['inferred'],
                    'The owner of last resort' => $report['owners']['fallback'],
                    'Nobody' => $report['owners']['nobody'],
                ] as $label => $count)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        <td class="n">{{ number_format($count) }}</td>
                        <td class="n">{{ \Bpmore\StatamicLifecycle\Reporting\GovernanceReport::percent($report['total'] === 0 ? null : $count / $report['total']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>When a person last confirmed each page</h2>
        <p>
            A review is a person's attestation, on a date, that the page was still
            accurate. Editing a page does not count: fixing a typo is not confirming the
            content.
            @if ($report['exempt'] > 0)
                {{ number_format($report['exempt']) }}
                {{ $report['exempt'] === 1 ? 'entry is' : 'entries are' }} exempt from review
                and left out of these figures.
            @endif
        </p>
        <table>
            <caption>Review recency across {{ \Bpmore\StatamicLifecycle\Reporting\GovernanceReport::entries($report['under_review']) }} under review</caption>
            <thead>
                <tr><th scope="col">Last reviewed</th><th scope="col" class="n">Entries</th><th scope="col" class="n">Share</th></tr>
            </thead>
            <tbody>
                @foreach ([
                    'Within the last '.\Bpmore\StatamicLifecycle\Reporting\GovernanceReport::RECENT_MONTHS.' months' => $report['reviews']['recent'],
                    'Longer ago than that' => $report['reviews']['stale'],
                    'Never' => $report['reviews']['never'],
                ] as $label => $count)
                    <tr>
                        <th scope="row">{{ $label }}</th>
                        <td class="n">{{ number_format($count) }}</td>
                        <td class="n">{{ \Bpmore\StatamicLifecycle\Reporting\GovernanceReport::percent($report['under_review'] === 0 ? null : $count / $report['under_review']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
        @if ($report['reviews']['never_since_baseline'] > 0)
            <p>
                Of the {{ number_format($report['reviews']['never']) }} never reviewed,
                {{ number_format($report['reviews']['never_since_baseline']) }}
                {{ $report['reviews']['never_since_baseline'] === 1 ? 'was' : 'were' }}
                given a first review date when Lifecycle was set up and
                {{ $report['reviews']['never_since_baseline'] === 1 ? 'has' : 'have' }}
                not been reviewed since.
            </p>
        @endif

        <h2>Where the overdue content is</h2>
        <p>
            Overdue means past its review date; expired means overdue for long enough
            that the site's expiry actions, where a collection has turned them on, apply.
            Shares are of the entries under review in each row.
        </p>
        @foreach (['by_collection' => 'By collection', 'by_site' => 'By site', 'by_owner' => 'By owner'] as $key => $caption)
            @if ($report[$key] !== [])
                <table>
                    <caption>{{ $caption }}</caption>
                    <thead>
                        <tr>
                            <th scope="col">{{ $caption === 'By owner' ? 'Owner' : ($caption === 'By site' ? 'Site' : 'Collection') }}</th>
                            <th scope="col" class="n">Entries</th>
                            <th scope="col" class="n">Overdue</th>
                            <th scope="col" class="n">Expired</th>
                            <th scope="col" class="n">Share late</th>
                            <th scope="col" class="n">Never reviewed</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($report[$key] as $row)
                            <tr>
                                <th scope="row">{{ $row['label'] }}</th>
                                <td class="n">{{ number_format($row['total']) }}</td>
                                <td class="n">{{ number_format($row['overdue']) }}</td>
                                <td class="n">{{ number_format($row['expired']) }}</td>
                                <td class="n">{{ \Bpmore\StatamicLifecycle\Reporting\GovernanceReport::percent($row['late_share']) }}</td>
                                <td class="n">{{ number_format($row['never_reviewed']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        @endforeach

        <h2>Review activity</h2>
        <p>Reviews recorded each month, and what the reviewer concluded.</p>
        <table>
            <caption>Reviews by month, oldest first</caption>
            <thead>
                <tr>
                    <th scope="col">Month</th>
                    <th scope="col" class="n">Reviews</th>
                    <th scope="col" class="n">Still current</th>
                    <th scope="col" class="n">Updated</th>
                    <th scope="col" class="n">Rewrite scheduled</th>
                    <th scope="col" class="n">Retire</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($report['activity'] as $month)
                    <tr>
                        <th scope="row">{{ $month['label'] }}</th>
                        <td class="n">{{ number_format($month['reviews']) }}</td>
                        <td class="n">{{ number_format($month['outcomes']['current']) }}</td>
                        <td class="n">{{ number_format($month['outcomes']['updated']) }}</td>
                        <td class="n">{{ number_format($month['outcomes']['rewrite_scheduled']) }}</td>
                        <td class="n">{{ number_format($month['outcomes']['retired']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <h2>What this report does not cover</h2>
    <p>
        A review records that a named person confirmed a page on a date. It does not
        verify the content, and this report makes no claim that any page is accurate.
        An inferred owner is a guess; only a confirmed owner has said the page is theirs.
        Content Lifecycle does not track — drafts, and anything created since the last
        nightly recompute — is not counted here.
    </p>
</main>
</body>
</html>
