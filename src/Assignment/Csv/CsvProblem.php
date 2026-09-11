<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Assignment\Csv;

/** One row that could not be used, and why, so the person can fix the file. */
final readonly class CsvProblem
{
    public function __construct(
        public int $line,
        public string $reason,
    ) {}
}
