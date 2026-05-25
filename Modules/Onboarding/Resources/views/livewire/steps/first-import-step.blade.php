{{--
    First-import step — wizard step 5. Mounts the existing
    `Modules\Import` UploadWizard inline inside the wide variant of
    the wizard card (1120px). This is the UI-SPEC §"Density rules"
    locked exception — every other wizard step is 620px-max, this one
    relaxes to 1120px because the preview table needs the room.

    UploadWizard's submit() redirects to /imports/{id}/preview on
    success — that's the existing pipeline-shape and is NOT modified
    by this plan. When the user confirms the preview the wizard's
    resume logic advances the `first-import` row on the next /setup-
    wizard hit. The manual "I've imported" affordance exists so a
    user who already imported during the connector steps can advance
    without re-uploading.

    Note: this card uses `<x-onboarding::wiz-card :wide="true">` —
    the wiz-card--wide CSS class is the load-bearing visual signal
    for FirstImportStepWidthTest.
--}}
<section class="wiz-step wiz-step-first-import" aria-labelledby="wiz-first-import-h1">
    <x-onboarding::wiz-card :wide="true">
        <p class="wiz-eyebrow">📥 Step 5 — Your first import</p>
        <h1 id="wiz-first-import-h1" class="wiz-h1">
            Drop a statement and see what beatrax makes of it
        </h1>
        <p class="wiz-lede">
            Upload any statement — your ASN export, the ICS PDF you just downloaded,
            or a PayPal Activity CSV. We'll show you what we found before anything
            saves.
        </p>

        <livewire:import.upload-wizard />

        <x-onboarding::wiz-actions>
            <button
                type="button"
                class="pill-btn-primary"
                wire:click="markImported"
            >
                I've imported — continue →
            </button>
        </x-onboarding::wiz-actions>
    </x-onboarding::wiz-card>
</section>
