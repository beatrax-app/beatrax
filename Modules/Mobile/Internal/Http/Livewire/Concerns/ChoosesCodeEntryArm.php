<?php

declare(strict_types=1);

namespace Modules\Mobile\Internal\Http\Livewire\Concerns;

use Modules\Core\Public\Support\Lang;
use Modules\Mobile\Internal\Pairing\QrScanBridge;
use Modules\Sync\Public\Enums\PairingSide;
use Modules\Sync\Public\Enums\PairingWizardStep;
use Modules\Sync\Public\Services\PairingGateway;

// Which of the two ways in is on screen — the camera or the keypad — the moves
// between them, and the permission both need before either reaches anything.
// Here rather than on the component because a Livewire screen accretes one
// action per affordance, and these answer a question no later step asks.
trait ChoosesCodeEntryArm
{
    // From the view once painted, not from mount(): the probe blocks for the
    // browse timeout, and the reader should be looking at the pairing screen
    // while iOS asks its question rather than at a blank one. Answering it
    // opens every road to the peer, not only the browse.
    /**
     * @link ../../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md#the-second-gate-and-the-one-a-reader-can-open
     */
    public function askForLocalNetwork(PairingGateway $gateway): void
    {
        if ($this->localNetworkAsked) {
            return;
        }

        $this->localNetworkAsked = true;

        $gateway->askForLocalNetworkAccess();
    }

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
            $this->moveTo(PairingWizardStep::Scan);
            $this->entryStep = PairingWizardStep::Scan->value;
            $this->cameraUnavailableNotice = false;

            return;
        }

        $this->moveTo(PairingWizardStep::EnterCode);
        $this->entryStep = PairingWizardStep::EnterCode->value;
        $this->cameraUnavailableNotice = true;
    }

    // Which road is shut, decided once for the amber slot. With the camera
    // refused AND no search, "enter the code instead" is the order the submit
    // error below has already ruled out, and that pair left the reader with two
    // lines pointing at each other and no third affordance on the screen.
    /**
     * @link ../../../../../../.docs/features/mobile/ios-lan-discovery-entitlement.md
     */
    private function entryArmNotice(bool $typedCodeCanFindPeer): ?string
    {
        if ($this->cameraUnavailableNotice) {
            return $typedCodeCanFindPeer
                ? 'mobile::pairing.camera_off'
                : 'mobile::pairing.camera_off_no_search';
        }

        return $typedCodeCanFindPeer ? null : 'mobile::pairing.no_search';
    }

    // The deliberate "I'd rather type it" choice, unlike cameraDenied()'s
    // forced fallback: same step, no amber notice, because nothing failed.
    public function useWordCode(): void
    {
        $this->flashMessage = '';
        $this->cameraUnavailableNotice = false;
        $this->moveTo(PairingWizardStep::EnterCode);
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
        $this->moveTo(PairingWizardStep::EnterCode);
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
