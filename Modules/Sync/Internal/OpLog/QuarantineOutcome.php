<?php

declare(strict_types=1);

namespace Modules\Sync\Internal\OpLog;

// What a reader is told about an operation this device refused and will not
// take again. QuarantineReason names the mechanism that refused it; this names
// the outcome, because one sentence cannot cover a signature that did not
// verify and a column a newer build of Beatrax wrote.
/**
 * @link ../../../../.docs/features/sync/what-the-quarantine-tells-the-reader.md
 */
enum QuarantineOutcome: string
{
    // The entry named a table or a column this build has no schema for. The
    // change exists on the device that made it and this one had nowhere to put
    // it, which is the one case in this enum an update to Beatrax answers.
    case TooNew = 'too_new';

    // Signed by a device this install has no confirmed key for, or by one the
    // reader removed. Removing a device is how this is normally reached, so
    // the copy has to offer that reading before it offers the other one.
    case UntrustedAuthor = 'untrusted_author';

    // The two that should not happen between a household's own devices: a
    // signature that did not verify against the key of the device claiming to
    // have written it, and an entry naming a different account.
    case NotVerified = 'not_verified';

    // The change was admissible and the write still could not be made, so the
    // two devices now hold different things. Whether that reads as a missing
    // row or as a deleted one that is still here depends on the reason, and
    // both are the same fact to the reader: the devices no longer agree.
    case Diverged = 'diverged';

    // What this outcome speaks for, minus whatever recoverable() currently
    // holds. That set is the single authority on which half a reason is in and
    // it moves, so a reason that becomes retried-and-retired leaves these
    // blocks without a second list needing an edit to agree.
    /**
     * @return list<QuarantineReason>
     */
    public function reasons(): array
    {
        $recoverable = QuarantineReason::recoverable();

        return array_values(array_filter(
            $this->whileTerminal(),
            static fn (QuarantineReason $reason): bool => ! in_array($reason->value, $recoverable, true),
        ));
    }

    // The outcome each reason is reported under for as long as it is terminal.
    // A reason stays listed after it moves into recoverable(): reasons() masks
    // it, and keeping the line means a reason moving back needs no archaeology
    // to find the sentence it used to be given.
    /**
     * @return list<QuarantineReason>
     */
    private function whileTerminal(): array
    {
        return match ($this) {
            self::TooNew => [QuarantineReason::UnknownTable, QuarantineReason::UnknownColumn],
            self::UntrustedAuthor => [QuarantineReason::MissingDeviceKey, QuarantineReason::UnconfirmedDevice],
            self::NotVerified => [QuarantineReason::ForgedSignature, QuarantineReason::CrossUser],
            self::Diverged => [
                QuarantineReason::IncompleteCreateRow,
                QuarantineReason::DeleteBlockedByReference,
                QuarantineReason::ImpossibleDate,
                QuarantineReason::PrimaryKeyCollision,
                QuarantineReason::SplitWouldOverfillTransaction,
            ],
        };
    }

    // Null for a reason that clears on its own. Walked rather than matched, so
    // reasons() stays the single statement of the partition and a case added
    // to it cannot disagree with a second copy written the other way round.
    public static function of(QuarantineReason $reason): ?self
    {
        foreach (self::cases() as $outcome) {
            if (in_array($reason, $outcome->reasons(), true)) {
                return $outcome;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    public static function terminalReasonValues(): array
    {
        $values = [];

        foreach (self::cases() as $outcome) {
            foreach ($outcome->reasons() as $reason) {
                $values[] = $reason->value;
            }
        }

        return $values;
    }

    public function summaryKey(): string
    {
        return 'sync::quarantine.'.$this->value.'.summary';
    }

    public function bodyKey(): string
    {
        return 'sync::quarantine.'.$this->value.'.body';
    }

    public function actionKey(): string
    {
        return 'sync::quarantine.'.$this->value.'.action';
    }

    // Danger is spent on the one outcome that is a security event. A removed
    // device and a newer build are ordinary, and painting them the same red
    // teaches the reader to read past the colour.
    public function tone(): string
    {
        return match ($this) {
            self::NotVerified => 'danger',
            self::TooNew => 'info',
            self::UntrustedAuthor, self::Diverged => 'warning',
        };
    }
}
