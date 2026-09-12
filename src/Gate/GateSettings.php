<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Gate;

use DateTimeImmutable;
use Throwable;

/**
 * The gate's configuration, read from `lifecycle.gate.collections`.
 *
 * Each collection named there is gated, and the value says from when:
 *
 *   'policies' => '2026-10-01'   entries created on or after that date
 *   'news' => 'all'              every entry, the backlog included
 *
 * The date form is what a site normally wants: the day the gate went on,
 * written down where git can see it. The gate's promise is that content
 * created before then is never gated unless the site explicitly asks, and
 * 'all' is that ask. A bare `true` is neither — it names no day, so the
 * gate cannot keep the promise — and is refused: the collection stays
 * ungated and the problem is reported so somebody fixes the config.
 */
final class GateSettings
{
    /** @var array<string, GateRule> */
    private array $rules = [];

    /** @var list<string> */
    private array $problems = [];

    /** @param  array<string, mixed>  $collections  the `lifecycle.gate.collections` array */
    public function __construct(array $collections)
    {
        foreach ($collections as $collection => $value) {
            $collection = (string) $collection;

            if ($value === null || $value === false || $value === '') {
                continue;
            }

            if ($value === 'all') {
                $this->rules[$collection] = new GateRule(null);

                continue;
            }

            if ($value === true) {
                $this->problems[] = "Lifecycle's gate for [{$collection}] is set to true, which does not say from when. Give it the date the gate started ('2026-10-01'), or 'all' to gate every entry. Until then the collection is not gated.";

                continue;
            }

            $since = self::date($value);

            if ($since === null) {
                $this->problems[] = "Lifecycle's gate for [{$collection}] is set to [".var_export($value, true)."], which is not a date or 'all'. The collection is not gated.";

                continue;
            }

            $this->rules[$collection] = new GateRule($since);
        }
    }

    public function isOn(): bool
    {
        return $this->rules !== [];
    }

    public function for(string $collection): ?GateRule
    {
        return $this->rules[$collection] ?? null;
    }

    /** @return array<string, GateRule> */
    public function rules(): array
    {
        return $this->rules;
    }

    /**
     * Config values that could not be read as a rule, in plain words. The
     * listener reports them; here they are only collected.
     *
     * @return list<string>
     */
    public function problems(): array
    {
        return $this->problems;
    }

    private static function date(mixed $value): ?DateTimeImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value)->setTime(0, 0);
        }

        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return (new DateTimeImmutable($value))->setTime(0, 0);
        } catch (Throwable) {
            return null;
        }
    }
}
