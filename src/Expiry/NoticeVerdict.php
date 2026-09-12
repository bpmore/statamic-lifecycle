<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Expiry;

/**
 * What the notice period sorted a run into.
 *
 * @param  list<Candidate>  $allowed  noticed long enough ago: may happen now
 * @param  list<Waiting>  $waiting  noticed, but not long enough ago
 * @param  list<Candidate>  $toNotify  never noticed for this cycle: tell the owner, act later
 */
final readonly class NoticeVerdict
{
    public function __construct(
        public array $allowed,
        public array $waiting,
        public array $toNotify,
    ) {}
}
