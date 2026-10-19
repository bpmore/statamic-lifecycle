<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Commands;

use Bpmore\StatamicLifecycle\Doctor\Doctor;
use Bpmore\StatamicLifecycle\Doctor\Finding;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Statamic\Console\RunsInPlease;

/**
 * `lifecycle:doctor`: is the addon installed, configured, tracking the
 * content and being run? Reads only; says what to run for anything it
 * finds. Exit code 1 when something stops the addon working, so a deploy
 * script can notice.
 */
class DoctorLifecycle extends Command
{
    use RunsInPlease;

    protected $signature = 'lifecycle:doctor {--json : Machine-readable output}';

    protected $description = 'Check that Lifecycle is installed, configured, tracking the content, and being run';

    public function handle(Doctor $doctor): int
    {
        $findings = $doctor->examine(Carbon::now()->toImmutable());
        $worst = Doctor::worst($findings);

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'status' => $worst,
                'findings' => array_map(fn (Finding $f): array => $f->toArray(), $findings),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $worst === Finding::FAIL ? self::FAILURE : self::SUCCESS;
        }

        foreach ($findings as $finding) {
            $mark = match ($finding->level) {
                Finding::OK => '<fg=green>✓</>',
                Finding::WARN => '<fg=yellow>!</>',
                default => '<fg=red>✗</>',
            };

            $this->line("  {$mark} <options=bold>{$finding->subject}</>  {$finding->detail}");

            if ($finding->fix !== null) {
                $this->line("      <fg=gray>→ {$finding->fix}</>");
            }
        }

        $this->newLine();

        match ($worst) {
            Finding::OK => $this->components->info('Everything is in order.'),
            Finding::WARN => $this->components->warn('Working, with things worth a look above.'),
            default => $this->components->error('Lifecycle cannot do its job until the failures above are fixed.'),
        };

        return $worst === Finding::FAIL ? self::FAILURE : self::SUCCESS;
    }
}
