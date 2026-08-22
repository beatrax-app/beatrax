<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire\Concerns;

use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Enums\PairingWizardStep;

// Which of the two ways in is on screen — the camera or the keypad — and every
// move between them. Here rather than on the component because a Livewire
// screen accretes one action per affordance, and these six answer one question
// no later step of the ceremony asks.
trait ChoosesCodeEntryArm
{
    // entryStep is not #[Locked], so a crafted payload can name any step here,
    // and a reset would then land the reader on a later screen than the arm
    // they actually chose. Only the two arms this page offers are honoured.
    private function entryArm(): PairingWizardStep
    {
        $entry = PairingWizardStep::tryFrom($this->entryStep);

        return $entry !== null && $entry->isEntryArm() ? $entry : PairingWizardStep::Scan;
    }

    public function enterACode(QrScanBridge $qrBridge): void
    {
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->side = PairingSide::Responder->value;

        if ($qrBridge->isAvailable()) {
            $this->step = PairingWizardStep::Scan->value;
            $this->entryStep = PairingWizardStep::Scan->value;
            $this->cameraUnavailableNotice = false;

            return;
        }

        $this->step = PairingWizardStep::EnterCode->value;
        $this->entryStep = PairingWizardStep::EnterCode->value;
        $this->cameraUnavailableNotice = true;
    }

    // The deliberate "I'd rather type it" choice, unlike cameraDenied()'s
    // forced fallback: same step, no amber notice, because nothing failed.
    public function useWordCode(): void
    {
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->step = PairingWizardStep::EnterCode->value;
        $this->entryStep = PairingWizardStep::EnterCode->value;
    }

    // Driven from the view rather than mount() so the component is already
    // live when the camera opens: a scan that completes before Livewire is
    // listening would drop its CodeScanned event.
    public function startScan(QrScanBridge $qrBridge): void
    {
        $this->flashMessage = '';

        if (! $qrBridge->open(Lang::get('mobile::pairing.scan_prompt'))) {
            $this->cameraDenied();
        }
    }

    // A runtime permission-denied/no-camera signal, distinct from
    // QrScanBridge::isAvailable(): the plugin can resolve while the OS
    // permission is still denied.
    public function cameraDenied(): void
    {
        $this->cameraUnavailableNotice = true;
        $this->step = PairingWizardStep::EnterCode->value;
        $this->entryStep = PairingWizardStep::EnterCode->value;
    }

    // Deliberately NOT cancelPairing(): nothing has been submitted yet, so
    // there is no token to expire and no reason to leave the ceremony.
    public function backToScan(QrScanBridge $qrBridge): void
    {
        $this->wordCode = '';
        $this->flashMessage = '';
        $this->enterACode($qrBridge);
    }
}
