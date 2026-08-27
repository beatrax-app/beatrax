<?php

declare(strict_types=1);

require_once __DIR__.'/nativephp_scaffold_root.php';

/*
 * Give Android a Share.File so the recovery codes can leave the device.
 *
 * The codes screen is shown exactly once and is the only way back into an
 * account. On a phone the app does not download them: a blob URL and an
 * `<a download>` is dropped silently by both WebViews, so the screen asks a
 * route that writes the file into the app's own container and hands it to the
 * OS share sheet. iOS registers Share.File and that works. Android registers
 * neither Share.File nor Share.Url — `NativeActions.share()` is ACTION_SEND
 * with `text/plain` and EXTRA_TEXT, which shares a message, not a file — so
 * `nativephp_can('Share.File')` answered false and the reader was told,
 * correctly, that their codes had not been saved. Correct, and still no file.
 *
 * The file is handed over as a FileProvider content URI rather than a file://
 * one, which Android has refused to let leave the app since N. The provider is
 * already declared in the generated manifest with grantUriPermissions; only
 * its path list needed the internal files directory, because the share is
 * scoped to the single URI in the intent and grants nothing else.
 *
 * Success means the chooser actually started. A URI that cannot be built, or a
 * device with nothing to receive the intent, returns success=false so the
 * screen keeps telling the truth — the failure this replaces was honest, and
 * an implementation that reports success it did not achieve would be worse
 * than the gap.
 *
 * Idempotent and marker-guarded, and applied per build rather than per
 * composer run, because the build regenerates the project that carries it.
 */

$root = beatraxScaffoldPath('android/app/src/main') ?? '';

if ($root === '' || ! is_dir($root)) {
    fwrite(STDOUT, "nativephp_android_share_file: no Android scaffold yet — skipping.\n");
    exit(0);
}

$functionsTarget = $root.'/java/com/nativephp/mobile/bridge/functions/BeatraxShareFunctions.kt';
$registrationTarget = $root.'/java/com/nativephp/mobile/bridge/BridgeFunctionRegistration.kt';
$pathsTarget = $root.'/res/xml/file_paths.xml';

foreach ([$registrationTarget, $pathsTarget] as $required) {
    if (! is_file($required)) {
        fwrite(STDERR, "nativephp_android_share_file: expected {$required} in the generated project.\n");
        exit(1);
    }
}

$function = <<<'KOTLIN'
package com.nativephp.mobile.bridge.functions

import android.content.Context
import android.content.Intent
import android.util.Log
import androidx.core.content.FileProvider
import com.nativephp.mobile.bridge.BridgeFunction
import java.io.File

/*
 * Added by scripts/nativephp_android_share_file.php — see that file for why
 * the generated shell needs it.
 */
object BeatraxShareFunctions {

    class ShareFile(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val path = parameters["filePath"] as? String ?: ""
            val title = parameters["title"] as? String ?: ""
            val message = parameters["message"] as? String ?: ""

            val file = File(path)
            if (path.isEmpty() || !file.isFile) {
                Log.e("Share.File", "No file at $path")
                return mapOf("success" to false)
            }

            // getUriForFile throws when the path is outside every <paths> entry
            // the provider declares. That is a build-time mistake rather than a
            // device condition, and it must not surface as a share sheet that
            // opens onto nothing.
            val uri = try {
                FileProvider.getUriForFile(context, context.packageName + ".fileprovider", file)
            } catch (e: IllegalArgumentException) {
                Log.e("Share.File", "No FileProvider path covers $path", e)
                return mapOf("success" to false)
            }

            val intent = Intent(Intent.ACTION_SEND).apply {
                type = "text/plain"
                putExtra(Intent.EXTRA_STREAM, uri)
                putExtra(Intent.EXTRA_SUBJECT, title)
                putExtra(Intent.EXTRA_TITLE, title)
                if (message.isNotEmpty()) {
                    putExtra(Intent.EXTRA_TEXT, message)
                }
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }

            val chooser = Intent.createChooser(intent, title).apply {
                addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION)
            }

            return try {
                context.startActivity(chooser)
                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("Share.File", "Nothing accepted the share intent", e)
                mapOf("success" to false)
            }
        }
    }
}
KOTLIN;

if (file_put_contents($functionsTarget, $function."\n") === false) {
    fwrite(STDERR, "nativephp_android_share_file: could not write {$functionsTarget}.\n");
    exit(1);
}

$registration = (string) file_get_contents($registrationTarget);

if (! str_contains($registration, 'BeatraxShareFunctions')) {
    $importAnchor = "import com.nativephp.mobile.bridge.functions.SystemFunctions\n";
    $registerAnchor = '    registry.register("System.OpenAppSettings", SystemFunctions.OpenAppSettings(context))'."\n";

    foreach ([$importAnchor, $registerAnchor] as $anchor) {
        if (! str_contains($registration, $anchor)) {
            fwrite(STDERR, "nativephp_android_share_file: anchor not found in {$registrationTarget}.\n");
            fwrite(STDERR, "The generated registration file changed shape; re-derive the anchor.\n");
            exit(1);
        }
    }

    $registration = str_replace(
        $importAnchor,
        "import com.nativephp.mobile.bridge.functions.BeatraxShareFunctions\n".$importAnchor,
        $registration,
    );

    $registration = str_replace(
        $registerAnchor,
        $registerAnchor."\n".'    registry.register("Share.File", BeatraxShareFunctions.ShareFile(context))'."\n",
        $registration,
    );

    if (file_put_contents($registrationTarget, $registration) === false) {
        fwrite(STDERR, "nativephp_android_share_file: could not write {$registrationTarget}.\n");
        exit(1);
    }
}

$paths = (string) file_get_contents($pathsTarget);

// The export is written under the app's internal files directory, which the
// generated <paths> did not list at all — it declares only the cache. The
// provider stays exported="false" and each intent grants read on one URI.
if (! str_contains($paths, 'beatrax-internal')) {
    $pathsAnchor = '<cache-path name="cache" path="." />';

    if (! str_contains($paths, $pathsAnchor)) {
        fwrite(STDERR, "nativephp_android_share_file: cache-path anchor not found in {$pathsTarget}.\n");
        exit(1);
    }

    $paths = str_replace(
        $pathsAnchor,
        $pathsAnchor."\n".'    <files-path name="beatrax-internal" path="." />',
        $paths,
    );

    if (file_put_contents($pathsTarget, $paths) === false) {
        fwrite(STDERR, "nativephp_android_share_file: could not write {$pathsTarget}.\n");
        exit(1);
    }
}

fwrite(STDOUT, "nativephp_android_share_file: Share.File registered.\n");
