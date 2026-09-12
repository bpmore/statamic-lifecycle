<?php

declare(strict_types=1);

namespace Bpmore\StatamicLifecycle\Listeners;

use Bpmore\StatamicLifecycle\Blueprint\LifecycleFields;
use Bpmore\StatamicLifecycle\Gate\GateResult;
use Bpmore\StatamicLifecycle\Gate\GateSettings;
use Bpmore\StatamicLifecycle\Gate\PublishGate;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use Statamic\Events\EntrySaving;

/**
 * The gate, at the moment an entry is saved.
 *
 * It throws rather than returning false, for the reason A11y Gate found:
 * `false` from an EntrySaving listener stops the save and says nothing, and
 * the control panel turns that into "Couldn't publish entry" — the author is
 * told the editor is broken rather than told what the page is missing. A
 * ValidationException comes back as a 422 the publish form already knows
 * how to show: each message lands under the field it names, which is also
 * where the missing thing gets set.
 *
 * The cost is that the refusal is an exception in every context, an import
 * script included. Accepted: a gate that fails loudly outside the interface
 * is doing its job. The addon's own writes are the one exception, and they
 * say so (PublishGate::suspended).
 */
final class RefuseUnlessOwnedAndDated
{
    /** Config problems are worth one line in the log, not one per save. */
    private static bool $reportedProblems = false;

    public function __construct(
        private readonly PublishGate $gate,
        private readonly GateSettings $settings,
        private readonly LoggerInterface $log,
    ) {}

    public function handle(EntrySaving $event): void
    {
        $this->reportProblemsOnce();

        if (PublishGate::isSuspended() || ! $this->settings->isOn() && $this->settings->problems() === []) {
            return;
        }

        $result = $this->gate->inspect($event->entry);

        if (! $result->shouldRefuse()) {
            return;
        }

        throw ValidationException::withMessages($this->messages($result))->status(422);
    }

    /**
     * One message per missing thing, keyed by the field where it is set, so
     * the control panel shows each beside its input. Plain words: what to do,
     * and why this collection asks for it.
     *
     * @return array<string, list<string>>
     */
    private function messages(GateResult $result): array
    {
        $messages = [];
        $title = $result->collectionTitle;

        if ($result->isMissing(GateResult::OWNER)) {
            $messages[LifecycleFields::CONTENT_OWNER] = [
                __('Choose a content owner before publishing. Every entry in :collection needs one.', ['collection' => $title]),
            ];
        } elseif ($result->isMissing(GateResult::OWNER_GONE)) {
            $messages[LifecycleFields::CONTENT_OWNER] = [
                __('The content owner named here is no longer a user. Choose another before publishing.'),
            ];
        }

        if ($result->isMissing(GateResult::REVIEW_DATE)) {
            $messages[LifecycleFields::REVIEW_BY] = [
                __('Set a review-by date or a review interval before publishing. Every entry in :collection needs one.', ['collection' => $title]),
            ];
        }

        return $messages;
    }

    private function reportProblemsOnce(): void
    {
        if (self::$reportedProblems) {
            return;
        }

        self::$reportedProblems = true;

        foreach ($this->settings->problems() as $problem) {
            $this->log->warning($problem);
        }
    }

    /** For tests, which boot one process for many configurations. */
    public static function forgetReportedProblems(): void
    {
        self::$reportedProblems = false;
    }
}
