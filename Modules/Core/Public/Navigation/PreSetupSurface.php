<?php

declare(strict_types=1);

namespace Modules\Core\Public\Navigation;

/**
 * @link ../../../../.docs/conventions/invariants-from-shipped-failures.md#a-pre-setup-screen-renders-the-application-shell
 */

// The screens a reader passes through before the application is theirs: the
// first-launch splashes, the signup ceremony, the wizard, the forced first
// password change, and the phone's provisioning path. Destination is the
// roster of places a reader may be sent; this is the roster that sends nowhere.
enum PreSetupSurface: string
{
    // Named by route rather than by component, because the shell that must not
    // appear on them is drawn by the layout, and a layout knows which route it
    // is rendering and never which component asked for it.
    case DesktopMigrationSplash = 'desktop.setup';

    case DesktopWelcome = 'desktop.welcome';

    case Signup = 'signup';

    case RecoveryCodes = 'auth.recovery-codes-display';

    // Nothing links to the change-password page: it exists as the destination
    // the forced-change guard redirects to, so the route name alone settles it
    // and no flag has to be read here.
    case ForcedPasswordChange = 'auth.change-password';

    // The URI is /setup-wizard — Desktop's migration splash owns /setup — but
    // the route name is the one the layout reads.
    case SetupWizard = 'setup';

    case MobileWelcome = 'mobile.welcome';

    case MobileImportBootstrap = 'mobile.import';

    case MobileRestoreFromBackup = 'mobile.restore';

    // Reached again from Data & Devices long after setup, and chromeless in
    // both directions on purpose: pairing is a full-screen ceremony wherever
    // it is entered from.
    case MobilePairing = 'mobile.pair';

    case MobileInitialSync = 'mobile.setup';

    case MobileInitialSyncDone = 'mobile.setup.done';

    case MobileSchemaIncomplete = 'mobile.database-incomplete';

    public static function covers(?string $routeName): bool
    {
        return $routeName !== null && self::tryFrom($routeName) instanceof self;
    }
}
