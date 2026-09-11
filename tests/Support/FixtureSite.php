<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Tests\Support;

use DateTimeImmutable;

/**
 * A deterministic population of entries shaped like a real institutional
 * site, for proving the baseline spreads rather than clusters.
 *
 * Real sites are not uniform. A CMS migration re-saves everything in one
 * week; a content audit touches a tenth of the site on one afternoon; there
 * is a long tail back to 2016 and a burst of recent work. The point of the
 * fixture is those lumps: a spread that only works on smooth input is not a
 * spread.
 *
 * Cohorts, by share of entries:
 *   25%  migration week    seven days around 2022-11-14
 *   45%  long tail         uniform, 2016-01-01 to 2025-12-31
 *   20%  recent            uniform over the 180 days before now
 *   10%  audit afternoon   every one at 2019-06-30 12:00, an exact tie
 *
 * Collections: 80% pages (no interval of their own), 15% news (quarterly),
 * 5% policies (every two years). 2% carry a review_by date, some already
 * past. 5% are drafts. 60% name a last author from the pool given.
 */
final class FixtureSite
{
    public const COLLECTIONS = [
        'fixture_pages' => ['title' => 'Fixture Pages', 'interval' => null],
        'fixture_news' => ['title' => 'Fixture News', 'interval' => 'quarterly'],
        'fixture_policies' => ['title' => 'Fixture Policies', 'interval' => 'every_2_years'],
    ];

    /**
     * @param  list<string>  $authors  user IDs to draw last authors from
     * @return iterable<array{id: string, collection: string, slug: string, title: string, updated_at: int, updated_by: ?string, published: bool, review_interval: ?string, review_by: ?string}>
     */
    public static function entries(int $count, DateTimeImmutable $now, array $authors = [], int $seed = 20260911): iterable
    {
        mt_srand($seed);

        $counters = array_fill_keys(array_keys(self::COLLECTIONS), 0);

        for ($i = 0; $i < $count; $i++) {
            $collection = self::pick(['fixture_pages' => 80, 'fixture_news' => 15, 'fixture_policies' => 5]);
            $n = ++$counters[$collection];
            $slug = sprintf('%s-%06d', str_replace('fixture_', '', $collection), $n);

            $reviewBy = mt_rand(1, 100) <= 2
                ? $now->modify('+'.mt_rand(-60, 300).' days')->format('Y-m-d')
                : null;

            yield [
                'id' => 'fx-'.$slug,
                'collection' => $collection,
                'slug' => $slug,
                'title' => ucfirst(str_replace('fixture_', '', $collection)).' '.$n,
                'updated_at' => self::modifiedAt($now)->getTimestamp(),
                'updated_by' => ($authors !== [] && mt_rand(1, 100) <= 60) ? $authors[mt_rand(0, count($authors) - 1)] : null,
                'published' => mt_rand(1, 100) > 5,
                'review_interval' => self::COLLECTIONS[$collection]['interval'],
                'review_by' => $reviewBy,
            ];
        }
    }

    /** The front matter and body of one entry, as Statamic stores it. */
    public static function fileContents(array $entry): string
    {
        $lines = [
            'id: '.$entry['id'],
            'title: '.$entry['title'],
            'updated_at: '.$entry['updated_at'],
        ];

        if ($entry['updated_by'] !== null) {
            $lines[] = 'updated_by: '.$entry['updated_by'];
        }
        if (! $entry['published']) {
            $lines[] = 'published: false';
        }
        if ($entry['review_interval'] !== null) {
            $lines[] = 'review_interval: '.$entry['review_interval'];
        }
        if ($entry['review_by'] !== null) {
            $lines[] = "review_by: '".$entry['review_by']."'";
        }

        return "---\n".implode("\n", $lines)."\n---\nFixture content.\n";
    }

    private static function modifiedAt(DateTimeImmutable $now): DateTimeImmutable
    {
        return match (self::pick(['migration' => 25, 'tail' => 45, 'recent' => 20, 'audit' => 10])) {
            'migration' => (new DateTimeImmutable('2022-11-14 09:00'))->modify('+'.mt_rand(0, 7 * 86400 - 1).' seconds'),
            'tail' => (new DateTimeImmutable('2016-01-01'))->modify('+'.mt_rand(0, 3652 * 86400 - 1).' seconds'),
            'recent' => $now->modify('-'.mt_rand(0, 180 * 86400 - 1).' seconds'),
            'audit' => new DateTimeImmutable('2019-06-30 12:00'),
        };
    }

    /** @param  array<string, int>  $weights */
    private static function pick(array $weights): string
    {
        $roll = mt_rand(1, array_sum($weights));

        foreach ($weights as $key => $weight) {
            if (($roll -= $weight) <= 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }
}
