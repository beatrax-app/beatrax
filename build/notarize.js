// Staged over NativePHP's published afterSign hook, which returns quietly
// without credentials, catches a notarisation rejection, and logs "done
// notarizing" either way. Every one of those paths throws here instead.

import { notarize } from '@electron/notarize';

const REQUIRED = ['NATIVEPHP_APPLE_ID', 'NATIVEPHP_APPLE_ID_PASS', 'NATIVEPHP_APPLE_TEAM_ID'];

export default async (context) => {
    if (process.platform !== 'darwin') return;
    if (context.packager.platform.name !== 'mac') return;

    console.log('aftersign hook triggered, start to notarize app.');

    const missing = REQUIRED.filter((key) => !process.env[key]);

    if (missing.length > 0) {
        if (process.env.NATIVEPHP_SKIP_NOTARIZE === '1') {
            console.warn(`skipping notarization on request (missing: ${missing.join(', ')}).`);
            return;
        }

        throw new Error(
            `Refusing to finish an un-notarized macOS build. Missing: ${missing.join(', ')}. ` +
                'Set NATIVEPHP_SKIP_NOTARIZE=1 for a deliberately local unsigned build.',
        );
    }

    const { appOutDir } = context;
    const appName = context.packager.appInfo.productFilename;

    // NATIVEPHP_APP_ID belongs to the mobile root and is unset here, so the
    // published hook passed undefined as the bundle id it was notarising.
    const appBundleId = context.packager.appInfo.macBundleIdentifier;

    await notarize({
        appBundleId,
        appPath: `${appOutDir}/${appName}.app`,
        appleId: process.env.NATIVEPHP_APPLE_ID,
        appleIdPassword: process.env.NATIVEPHP_APPLE_ID_PASS,
        teamId: process.env.NATIVEPHP_APPLE_TEAM_ID,
        tool: 'notarytool',
    });

    console.log(`done notarizing ${appBundleId}.`);
};
