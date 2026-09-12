<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Weather;

use Bpmore\SiteWeather\Contracts\WeatherContributor;
use Bpmore\SiteWeather\Reading;
use Bpmore\SiteWeather\State;
use Bpmore\StatamicLifecycle\Models\EntryState;
use Bpmore\StatamicLifecycle\Reporting\Overview;
use Bpmore\StatamicLifecycle\State\Status;
use Bpmore\StatamicLifecycle\Storage\LifecycleDatabase;
use Carbon\CarbonImmutable;

/**
 * The Freshness band on Site Weather's tile.
 *
 * Site Weather is an optional companion, not a dependency: this class is
 * tagged by name in the service provider and only ever loaded when Site
 * Weather resolves the tag, so the interface it implements need not exist on
 * a site that has not installed it.
 *
 * It reads. Two aggregate queries over lifecycle_states — the same numbers
 * the utility dashboard leads with — and never recomputes. Not installed, or
 * nothing tracked yet, reads as unknown, never as clear.
 *
 * The weather, decided here because this addon knows its own data. "Late" is
 * overdue or expired; "expired" is past the expiry line the site itself drew,
 * so it weighs more than merely overdue:
 *
 *   clear     nothing late
 *   fair      something overdue, under a tenth of the content, nothing expired
 *   overcast  a tenth late, or anything expired
 *   rain      a quarter late, or expired content on a site a tenth late
 *   storm     half the content late, or expired content across a tenth of it
 *
 * Proportions are of content under review — tracked entries that are not
 * exempt — so a large archive the site has deliberately exempted neither
 * dilutes nor hides the weather.
 */
final class FreshnessContributor implements WeatherContributor
{
    /** Late across this share of the content moves the weather one step worse. */
    private const WIDESPREAD_SHARE = 0.10;

    /** Late across this share is a step worse again. */
    private const PREVALENT_SHARE = 0.25;

    /** Late across this share, or expired across a tenth, is a storm. */
    private const STORM_SHARE = 0.50;

    private const STORM_EXPIRED_SHARE = 0.10;

    public function __construct(
        private Overview $overview,
        private LifecycleDatabase $database,
    ) {}

    public function key(): string
    {
        return 'freshness';
    }

    public function label(): string
    {
        return 'Freshness';
    }

    public function reading(): Reading
    {
        $url = cp_route('lifecycle.dashboard');

        // Installed but never set up — no tables yet. Asking first costs one
        // schema query and turns a caught exception into an answer.
        if (! $this->database->isInstalled()) {
            return Reading::unknown('Not set up yet: run php please lifecycle:install', $url);
        }

        $statuses = $this->overview->statuses();
        $total = array_sum($statuses);

        if ($total === 0) {
            return Reading::unknown('Nothing tracked yet: run php please lifecycle:baseline', $url);
        }

        $underReview = $total - $statuses[Status::Exempt->value];

        if ($underReview === 0) {
            return Reading::unknown(sprintf('All %s are exempt from review', self::entries($total)), $url);
        }

        $overdue = $statuses[Status::Overdue->value];
        $expired = $statuses[Status::Expired->value];
        $late = $overdue + $expired;
        $computedAt = $this->latestComputation();

        if ($late === 0) {
            return new Reading(State::Clear, sprintf('Nothing overdue across %s', self::entries($underReview)), $url, $computedAt);
        }

        $share = $late / $underReview;
        $expiredShare = $expired / $underReview;

        $state = match (true) {
            $share >= self::STORM_SHARE || $expiredShare >= self::STORM_EXPIRED_SHARE => State::Storm,
            $share >= self::PREVALENT_SHARE || ($expired > 0 && $share >= self::WIDESPREAD_SHARE) => State::Rain,
            $share >= self::WIDESPREAD_SHARE || $expired > 0 => State::Overcast,
            default => State::Fair,
        };

        $headline = sprintf('%s of %s overdue', number_format($late), self::entries($underReview));

        if ($expired > 0) {
            $headline .= sprintf(', %s past expiry', number_format($expired));
        }

        return new Reading($state, $headline, $url, $computedAt);
    }

    /** When the numbers were last worked out: the newest state row's computed_at. */
    private function latestComputation(): ?CarbonImmutable
    {
        $latest = EntryState::query()->max('computed_at');

        return $latest === null ? null : CarbonImmutable::parse($latest);
    }

    private static function entries(int $count): string
    {
        return number_format($count).' '.($count === 1 ? 'entry' : 'entries');
    }
}
