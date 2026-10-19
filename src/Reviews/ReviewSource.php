<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Reviews;

/**
 * How a review came to be recorded. An attestation is a person saying so;
 * an edit is a save, counted only where the site has decided it counts
 * (reviews.editing_counts). The record keeps them apart.
 */
enum ReviewSource: string
{
    case Attestation = 'attestation';
    case Edit = 'edit';

    public function label(): string
    {
        return match ($this) {
            self::Attestation => __('marked reviewed'),
            self::Edit => __('from an edit'),
        };
    }
}
