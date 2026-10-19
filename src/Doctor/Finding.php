<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Doctor;

/**
 * One thing the doctor looked at: what it found, how much it matters, and
 * what to do about it when something does.
 */
final readonly class Finding
{
    public const OK = 'ok';

    /** Worth knowing; the addon still works. */
    public const WARN = 'warn';

    /** The addon cannot do its job until this is fixed. */
    public const FAIL = 'fail';

    public function __construct(
        public string $level,
        public string $subject,
        public string $detail,
        public ?string $fix = null,
    ) {}

    public static function ok(string $subject, string $detail): self
    {
        return new self(self::OK, $subject, $detail);
    }

    public static function warn(string $subject, string $detail, ?string $fix = null): self
    {
        return new self(self::WARN, $subject, $detail, $fix);
    }

    public static function fail(string $subject, string $detail, ?string $fix = null): self
    {
        return new self(self::FAIL, $subject, $detail, $fix);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['level' => $this->level, 'subject' => $this->subject, 'detail' => $this->detail, 'fix' => $this->fix];
    }
}
