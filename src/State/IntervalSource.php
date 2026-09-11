<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\State;

/** Which rung of entry → blueprint → collection → global supplied the interval. */
enum IntervalSource: string
{
    case Entry = 'entry';
    case Blueprint = 'blueprint';
    case Collection = 'collection';
    case Global = 'global';
}
