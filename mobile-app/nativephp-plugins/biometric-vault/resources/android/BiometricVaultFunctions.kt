package com.beatrax.biometricvault

import android.content.Context
import android.os.Build
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyProperties
import android.util.Base64
import android.util.Log
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.fragment.app.FragmentActivity
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import java.security.KeyStore
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec

/**
 * BiometricVault — SPIKE (Tier A proof, Android).
 *
 * KEY FINDING (the reason cold-start biometric unlock is its own phase):
 * Android cannot mirror the iOS synchronous keychain. A Keystore key created
 * with `setUserAuthenticationRequired(true)` gates EVERY Cipher operation
 * (both encrypt and decrypt) behind a `BiometricPrompt.authenticate(CryptoObject)`
 * call, which is asynchronous and must run on a FragmentActivity's UI thread.
 * A synchronous bridge `Get()` therefore cannot return the plaintext inline —
 * the recover path MUST be event-based:
 *
 *     PHP: BiometricVault::recover(key)  ->  native dispatches BiometricPrompt
 *     native: on success  ->  emit "BiometricVault.Recovered" { key, value }
 *             on cancel/fail -> emit "BiometricVault.Failed" { key, reason }
 *
 * The Keystore CONFIG below (the security-critical part) is complete and
 * correct; the BiometricPrompt wiring is a structured skeleton that needs an
 * Activity handle + the NativePHP event-emit API and on-device iteration — the
 * work S2 must budget for. `setInvalidatedByBiometricEnrollment(true)` is the
 * anti-coercion property (a newly enrolled fingerprint invalidates the key),
 * matching iOS's `.biometryCurrentSet`.
 *
 * Store shape: `set()` encrypts under the biometric-bound key (itself an async
 * prompt at enroll time — acceptable, the user is present) and persists
 * base64(iv):base64(ciphertext) in plain SharedPreferences; the security is in
 * the Keystore key, not the prefs.
 */
object BiometricVaultFunctions {

    private const val KEY_ALIAS = "beatrax.biometric.vault.kek"
    private const val PREFS_NAME = "beatrax_biometric_vault"
    private const val TRANSFORM = "AES/GCM/NoPadding"
    private const val GCM_TAG_BITS = 128

    // --- Keystore key (security-critical; complete) --------------------------

    private fun getOrCreateKey(): SecretKey {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        (ks.getEntry(KEY_ALIAS, null) as? KeyStore.SecretKeyEntry)?.let { return it.secretKey }

        val gen = KeyGenerator.getInstance(KeyProperties.KEY_ALGORITHM_AES, "AndroidKeyStore")
        val builder = KeyGenParameterSpec.Builder(
            KEY_ALIAS,
            KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
        )
            .setBlockModes(KeyProperties.BLOCK_MODE_GCM)
            .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_NONE)
            .setKeySize(256)
            // Every use requires a fresh biometric.
            .setUserAuthenticationRequired(true)
            // A newly enrolled fingerprint/face invalidates this key.
            .setInvalidatedByBiometricEnrollment(true)

        // Per-use STRONG-biometric gating. setUserAuthenticationParameters +
        // AUTH_BIOMETRIC_STRONG are API 30+ (Android R); on API 28/29 (the
        // manifest floor) fall back to the pre-30 "-1 = require auth for every
        // use" validity duration. Without this gate the key can't be created on
        // 28/29 (NoSuchMethodError).
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.R) {
            builder.setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
        } else {
            @Suppress("DEPRECATION")
            builder.setUserAuthenticationValidityDurationSeconds(-1)
        }

        gen.init(builder.build())
        return gen.generateKey()
    }

    fun deleteKey() {
        val ks = KeyStore.getInstance("AndroidKeyStore").apply { load(null) }
        if (ks.containsAlias(KEY_ALIAS)) ks.deleteEntry(KEY_ALIAS)
    }

    // --- Bridge functions ----------------------------------------------------

    /**
     * Set: encrypt+persist. NOTE: because the key requires auth, initialising
     * the encrypt Cipher also triggers a BiometricPrompt — so on-device this
     * too routes through promptAndRun() (async). Modeled here as the crypto
     * skeleton; the prompt wiring is shared with Get.
     */
    class Set(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            val value = parameters["value"] as? String
            if (value == null) {
                context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().remove(key).apply()
                return mapOf("success" to true)
            }
            // On-device: dispatch BiometricPrompt(CryptoObject(encryptCipher)),
            // then in the callback do cipher.doFinal(value) and persist. Emit
            // an event with the outcome. See promptAndRun() below.
            if (setIsAsyncOnly()) {
                Log.w("BiometricVault.Set", "Async BiometricPrompt required on Android — see class docblock.")
                return mapOf("success" to false, "async_required" to true)
            }

            return mapOf("success" to false)
        }
    }

    /**
     * Get: MUST be async on Android. Returns a marker telling PHP to expect the
     * "BiometricVault.Recovered" / "BiometricVault.Failed" event instead of an
     * inline value.
     */
    class Get(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            // On-device: build decrypt Cipher from the stored IV, dispatch
            // BiometricPrompt(CryptoObject(decryptCipher)); on success emit
            // "BiometricVault.Recovered" { key, value }, else "…Failed".
            return mapOf("async" to true, "event" to "BiometricVault.Recovered")
        }
    }

    /**
     * Whether this device can gate an entry behind a biometric RIGHT NOW.
     *
     * The application used to answer this from PHP_OS_FAMILY, which says only
     * which operating system is running. A phone with no fingerprint enrolled,
     * or one whose sensor the OS has locked out, answered the same as one that
     * can — so the reader was offered biometric unlock and the enrolment then
     * failed with nothing to read but the word "false".
     *
     * BIOMETRIC_STRONG and nothing weaker: the Keystore key is created with
     * AUTH_BIOMETRIC_STRONG, so anything this call would admit that the key
     * would not is a promise the enclave then breaks. A device credential is
     * not a biometric and deliberately does not count.
     *
     * "Right now" also covers this plugin: while Set() answers `async_required`
     * the vault cannot hold a key on any Android build, however ready the
     * sensor is, and this call says `async_unimplemented` rather than yes.
     */
    class IsAvailable(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val status = BiometricManager.from(context)
                .canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)

            val sensorReady = status == BiometricManager.BIOMETRIC_SUCCESS

            // The reason travels with the answer: "no" has six causes here and
            // only one of them is worth telling a reader about. A ready sensor
            // is still a no while Set() cannot write — the sensor was the only
            // thing being asked, and every Android phone got an Enroll button.
            val reason = when {
                sensorReady && setIsAsyncOnly() -> "async_unimplemented"
                sensorReady -> "available"
                status == BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> "none_enrolled"
                status == BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE -> "no_hardware"
                status == BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE -> "hardware_unavailable"
                status == BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED -> "security_update_required"
                else -> "unsupported"
            }

            Log.d("BiometricVault.IsAvailable", "canAuthenticate(STRONG)=$status ($reason)")

            return mapOf(
                "available" to (reason == "available"),
                "reason" to reason,
            )
        }
    }

    /**
     * Whether Set() still has to refuse. Read by Set, which returns the refusal,
     * and by IsAvailable, which must not offer what Set will refuse — one fact
     * with two readers rather than two places to remember when the wiring lands.
     */
    private fun setIsAsyncOnly(): Boolean = true

    class Delete(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().remove(key).apply()
            return mapOf("success" to true)
        }
    }

    // --- Async BiometricPrompt skeleton (needs Activity + event API) ---------

    /**
     * The shared prompt runner S2 must complete. `activity` is the current
     * FragmentActivity (NativePHP exposes the host activity); `onResult` fires
     * the NativePHP event. Left as a documented skeleton because it needs a
     * live Activity + the plugin event-emit API this spike cannot compile.
     */
    private fun promptAndRun(
        activity: FragmentActivity,
        cipher: Cipher,
        reason: String,
        onSuccess: (Cipher) -> Unit,
        onFailure: (String) -> Unit,
    ) {
        val executor = androidx.core.content.ContextCompat.getMainExecutor(activity)
        val prompt = BiometricPrompt(activity, executor, object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                result.cryptoObject?.cipher?.let(onSuccess) ?: onFailure("no_cipher")
            }
            override fun onAuthenticationError(code: Int, msg: CharSequence) = onFailure("error:$code")
            override fun onAuthenticationFailed() = onFailure("failed")
        })
        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle("Unlock Beatrax")
            .setSubtitle(reason)
            .setNegativeButtonText("Use PIN")
            .setAllowedAuthenticators(androidx.biometric.BiometricManager.Authenticators.BIOMETRIC_STRONG)
            .build()
        prompt.authenticate(info, BiometricPrompt.CryptoObject(cipher))
    }

    @Suppress("unused")
    private fun encryptCipher(): Cipher =
        Cipher.getInstance(TRANSFORM).apply { init(Cipher.ENCRYPT_MODE, getOrCreateKey()) }

    @Suppress("unused")
    private fun decryptCipher(iv: ByteArray): Cipher =
        Cipher.getInstance(TRANSFORM).apply { init(Cipher.DECRYPT_MODE, getOrCreateKey(), GCMParameterSpec(GCM_TAG_BITS, iv)) }

    @Suppress("unused")
    private fun encode(iv: ByteArray, ct: ByteArray): String =
        Base64.encodeToString(iv, Base64.NO_WRAP) + ":" + Base64.encodeToString(ct, Base64.NO_WRAP)
}
