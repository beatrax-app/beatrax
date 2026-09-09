package com.beatrax.biometricvault

import android.content.Context
import android.security.keystore.KeyGenParameterSpec
import android.security.keystore.KeyPermanentlyInvalidatedException
import android.security.keystore.KeyProperties
import android.os.Handler
import android.os.Looper
import android.util.Base64
import android.util.Log
import androidx.biometric.BiometricManager
import androidx.biometric.BiometricPrompt
import androidx.core.content.ContextCompat
import androidx.fragment.app.FragmentActivity
import androidx.lifecycle.Lifecycle
import androidx.lifecycle.LifecycleEventObserver
import androidx.lifecycle.LifecycleOwner
import com.nativephp.mobile.bridge.BridgeError
import com.nativephp.mobile.bridge.BridgeFunction
import com.nativephp.mobile.utils.NativeActionCoordinator
import java.security.KeyPairGenerator
import java.security.KeyStore
import java.security.spec.MGF1ParameterSpec
import javax.crypto.Cipher
import javax.crypto.KeyGenerator
import javax.crypto.SecretKey
import javax.crypto.spec.GCMParameterSpec
import javax.crypto.spec.OAEPParameterSpec
import javax.crypto.spec.PSource
import javax.crypto.spec.SecretKeySpec

/**
 * BiometricVault — the Android half of cold-start biometric unlock.
 *
 * WHY A KEY PAIR AND NOT AES. A Keystore secret key created with
 * `setUserAuthenticationRequired(true)` gates EVERY Cipher operation behind a
 * BiometricPrompt, encryption included. Enrolment cannot pay that: the PHP
 * caller re-verifies the PIN to obtain the live data key, calls Set, and then
 * `sodium_memzero`s the key on the very next line -- an asynchronous Set would
 * have to hold that plaintext across a UI round trip. Only the PRIVATE half of
 * an asymmetric Keystore key requires user authentication; the public half does
 * not. So Set stays synchronous and returns a real answer, and Get is the one
 * that prompts. The enclave binding is unchanged: the bytes are released only
 * for a live BIOMETRIC_STRONG authentication.
 *
 * The value is enveloped rather than encrypted with RSA directly, so nothing
 * here has an opinion about how long the stored blob is.
 *
 * `setInvalidatedByBiometricEnrollment(true)` is the anti-coercion property,
 * matching iOS's `.biometryCurrentSet`: enrolling a new fingerprint destroys
 * the key. Get answers that case as `missing` and clears the entry, because an
 * undecryptable blob is not an authentication that failed -- it is nothing left
 * to authenticate against, and the reader has to enrol again.
 *
 * RECOVERY COMPLETES BY SIGNAL (E5-R10). Get returns `async`; the prompt
 * callback stashes the decrypted blob in a transient in-process slot and raises
 * `BiometricVault.Recovered`. The blob never travels in the event payload --
 * PHP fetches it over the bridge with PollRecovered, which consumes the slot on
 * read. A slot that survived its read, or a backgrounding, could be replayed by
 * a later dispatch and admit a session with no live biometric behind it.
 */
object BiometricVaultFunctions {

    private const val KEY_ALIAS = "beatrax.biometric.vault.wrap.v2"

    // Aliases no build can read any more, deleted rather than migrated. The
    // first is the spike's symmetric key, which Set never once wrote under. The
    // second authorised SHA-256 alone, which the OAEP note below is about.
    private val RETIRED_ALIASES = listOf(
        "beatrax.biometric.vault.kek",
        "beatrax.biometric.vault.wrap",
    )

    private const val PREFS_NAME = "beatrax_biometric_vault"
    private const val WRAP_TRANSFORM = "RSA/ECB/OAEPWithSHA-256AndMGF1Padding"
    private const val CONTENT_TRANSFORM = "AES/GCM/NoPadding"
    private const val GCM_TAG_BITS = 128

    private const val EVENT_RECOVERED = "BiometricVault.Recovered"
    private const val EVENT_FAILED = "BiometricVault.Failed"

    // Consumed by PollRecovered and dropped when the app leaves the foreground.
    @Volatile
    private var recovered: String? = null

    @Volatile
    private var watchingLifecycle = false

    // The prompt outlives the screen that asked for it. It is dispatched from
    // the lock screen's own mount, so a reader who types the PIN instead leaves
    // it standing over an app that is already unlocked.
    @Volatile
    private var active: BiometricPrompt? = null

    // cancelAuthentication() answers through the main executor, and prompt()
    // already runs there, so the cancelled prompt's error arrives AFTER the
    // replacement has been mounted. Without a ticket to compare, that late
    // callback clears the live prompt's handle and nothing can take it down.
    @Volatile
    private var ticket = 0

    // --- Keystore key (security-critical) ------------------------------------

    private fun keyStore(): KeyStore =
        KeyStore.getInstance("AndroidKeyStore").apply { load(null) }

    private fun getOrCreateKeyPair(): java.security.KeyStore.PrivateKeyEntry {
        val ks = keyStore()

        for (alias in RETIRED_ALIASES) {
            if (ks.containsAlias(alias)) ks.deleteEntry(alias)
        }

        (ks.getEntry(KEY_ALIAS, null) as? KeyStore.PrivateKeyEntry)?.let { return it }

        val gen = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_RSA, "AndroidKeyStore")
        gen.initialize(
            KeyGenParameterSpec.Builder(
                KEY_ALIAS,
                KeyProperties.PURPOSE_ENCRYPT or KeyProperties.PURPOSE_DECRYPT
            )
                .setDigests(KeyProperties.DIGEST_SHA256, KeyProperties.DIGEST_SHA1)
                .setEncryptionPaddings(KeyProperties.ENCRYPTION_PADDING_RSA_OAEP)
                .setKeySize(2048)
                // Only the private half is gated by this; encryption is not.
                .setUserAuthenticationRequired(true)
                .setInvalidatedByBiometricEnrollment(true)
                .setUserAuthenticationParameters(0, KeyProperties.AUTH_BIOMETRIC_STRONG)
                .build()
        )
        gen.generateKeyPair()

        return keyStore().getEntry(KEY_ALIAS, null) as KeyStore.PrivateKeyEntry
    }

    fun deleteKey() {
        val ks = keyStore()
        for (alias in RETIRED_ALIASES + KEY_ALIAS) {
            if (ks.containsAlias(alias)) ks.deleteEntry(alias)
        }
    }

    // --- Bridge functions ----------------------------------------------------

    /**
     * Set: synchronous. The public half of the Keystore pair needs no
     * authentication, so enrolment answers for itself rather than through an
     * event the caller has already zeroed its key to wait for.
     */
    class Set(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            val value = parameters["value"] as? String

            val prefs = context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)

            if (value == null) {
                prefs.edit().remove(key).apply()
                return mapOf("success" to true)
            }

            return try {
                prefs.edit().putString(key, seal(value)).apply()
                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("BiometricVault.Set", "could not seal the entry: ${e.message}", e)
                mapOf("success" to false)
            }
        }
    }

    /**
     * Get: asynchronous, and the only call that prompts. Answers `async` so the
     * caller stops waiting for a value, then raises Recovered or Failed.
     */
    class Get(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            val reason = parameters["reason"] as? String ?: "Unlock Beatrax"

            val stored = activity
                .getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                .getString(key, null)
                ?: return mapOf("missing" to true)

            val cipher = try {
                unwrapCipher()
            } catch (e: KeyPermanentlyInvalidatedException) {
                // The enrolled set changed, which is exactly what the key was
                // built to notice. Nothing can read this blob again.
                Log.w("BiometricVault.Get", "the enrolled biometric changed; the entry cannot be read again")
                forget(activity, key)
                return mapOf("missing" to true)
            } catch (e: Exception) {
                Log.e("BiometricVault.Get", "could not prepare the unwrap: ${e.message}", e)
                return mapOf("failed" to true)
            }

            Handler(Looper.getMainLooper()).post {
                prompt(activity, cipher, reason, stored, key)
            }

            return mapOf("async" to true)
        }
    }

    /**
     * PollRecovered: hands over the blob the prompt released, once. Consume on
     * read is half the contract; the other half is the lifecycle observer that
     * drops the slot when the app stops.
     */
    class PollRecovered(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val value = recovered
            recovered = null

            return mapOf("value" to (value ?: ""))
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
     */
    class IsAvailable(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val status = BiometricManager.from(context)
                .canAuthenticate(BiometricManager.Authenticators.BIOMETRIC_STRONG)

            val reason = when (status) {
                BiometricManager.BIOMETRIC_SUCCESS -> "available"
                BiometricManager.BIOMETRIC_ERROR_NONE_ENROLLED -> "none_enrolled"
                BiometricManager.BIOMETRIC_ERROR_NO_HARDWARE -> "no_hardware"
                BiometricManager.BIOMETRIC_ERROR_HW_UNAVAILABLE -> "hardware_unavailable"
                BiometricManager.BIOMETRIC_ERROR_SECURITY_UPDATE_REQUIRED -> "security_update_required"
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
     * CancelPrompt: for the race the lock screen really runs. Its mount fires
     * the prompt, and the PIN pad underneath stays live the whole time, so the
     * two paths finish in either order and only one of them is the reader's.
     */
    class CancelPrompt(private val activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            Handler(Looper.getMainLooper()).post { dismiss() }

            return mapOf("success" to true)
        }
    }

    class Delete(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")
            context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().remove(key).apply()
            return mapOf("success" to true)
        }
    }

    // --- The prompt ----------------------------------------------------------

    private fun prompt(
        activity: FragmentActivity,
        cipher: Cipher,
        reason: String,
        stored: String,
        key: String,
    ) {
        watchLifecycle(activity)
        dismiss()

        val mine = ++ticket

        val callback = object : BiometricPrompt.AuthenticationCallback() {
            override fun onAuthenticationSucceeded(result: BiometricPrompt.AuthenticationResult) {
                if (mine != ticket) return

                val unwrap = result.cryptoObject?.cipher
                if (unwrap == null) {
                    // The prompt authenticated something other than the Cipher
                    // it was handed, so nothing here is enclave-released.
                    fail(activity, "no_cipher")
                    return
                }

                active = null

                try {
                    recovered = open(stored, unwrap)
                    NativeActionCoordinator.dispatchEvent(activity, EVENT_RECOVERED, "{}")
                } catch (e: KeyPermanentlyInvalidatedException) {
                    forget(activity, key)
                    fail(activity, "invalidated")
                } catch (e: Exception) {
                    Log.e("BiometricVault", "the entry did not open after a successful prompt: ${e.message}", e)
                    fail(activity, "unreadable")
                }
            }

            // Not a refusal: the sensor read a finger it did not recognise and
            // the prompt is still up. Answering here would retire a ceremony
            // the reader is still in.
            override fun onAuthenticationFailed() {
                Log.d("BiometricVault", "a presented biometric was not recognised; the prompt is still up")
            }

            override fun onAuthenticationError(code: Int, msg: CharSequence) {
                if (mine != ticket) return

                fail(activity, if (code == BiometricPrompt.ERROR_USER_CANCELED ||
                    code == BiometricPrompt.ERROR_NEGATIVE_BUTTON ||
                    code == BiometricPrompt.ERROR_CANCELED
                ) "canceled" else "error:$code")
            }
        }

        val info = BiometricPrompt.PromptInfo.Builder()
            .setTitle("Unlock Beatrax")
            .setSubtitle(reason)
            // BIOMETRIC_STRONG alone admits no device credential, so the prompt
            // must carry its own way out.
            .setNegativeButtonText("Use PIN")
            .setAllowedAuthenticators(BiometricManager.Authenticators.BIOMETRIC_STRONG)
            .build()

        try {
            // Assigned before authenticate(), never after: a synchronous failure
            // inside it would otherwise have its cleanup overwritten by a handle
            // to a prompt that never mounted.
            val prompt = BiometricPrompt(activity, ContextCompat.getMainExecutor(activity), callback)
            active = prompt
            prompt.authenticate(info, BiometricPrompt.CryptoObject(cipher))
        } catch (e: Exception) {
            Log.e("BiometricVault", "the prompt did not mount: ${e.message}", e)
            fail(activity, "unreadable")
        }
    }

    private fun fail(activity: FragmentActivity, reason: String) {
        recovered = null
        active = null
        Log.d("BiometricVault", "recovery did not complete: $reason")
        NativeActionCoordinator.dispatchEvent(activity, EVENT_FAILED, """{"reason":"$reason"}""")
    }

    // Takes the prompt down without an answer. The callback still fires with
    // ERROR_CANCELED, which raises Failed — a signal the lock screen is
    // deliberately deaf to, because a cancelled ceremony earns no state.
    private fun dismiss() {
        // The slot is dropped here rather than in the callback, because the
        // ticket now silences a superseded one: a ceremony the reader answered
        // another way must not leave a released blob behind for the next
        // dispatch to claim.
        ticket++
        recovered = null
        active?.cancelAuthentication()
        active = null
    }

    private fun forget(activity: Context, key: String) {
        activity.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().remove(key).apply()
        deleteKey()
    }

    // A blob that outlived the foreground is one a later dispatch could claim
    // without a prompt behind it.
    private fun watchLifecycle(activity: FragmentActivity) {
        if (watchingLifecycle) return
        watchingLifecycle = true

        activity.lifecycle.addObserver(LifecycleEventObserver { _: LifecycleOwner, event: Lifecycle.Event ->
            if (event == Lifecycle.Event.ON_STOP) {
                recovered = null
            }
        })
    }

    // --- Envelope ------------------------------------------------------------

    // A content key per write, wrapped by the enclave-bound public half. The
    // prompt authorises the unwrap of that content key and nothing larger,
    // which is what keeps the stored value's length out of this file.
    private fun seal(value: String): String {
        val content: SecretKey = KeyGenerator.getInstance("AES")
            .apply { init(256) }
            .generateKey()

        val body = Cipher.getInstance(CONTENT_TRANSFORM).apply {
            init(Cipher.ENCRYPT_MODE, content)
        }

        val ciphertext = body.doFinal(value.toByteArray(Charsets.UTF_8))

        val wrapped = Cipher.getInstance(WRAP_TRANSFORM).apply {
            init(Cipher.ENCRYPT_MODE, getOrCreateKeyPair().certificate.publicKey, oaepSpec())
        }.doFinal(content.encoded)

        return listOf(wrapped, body.iv, ciphertext).joinToString(":") {
            Base64.encodeToString(it, Base64.NO_WRAP)
        }
    }

    private fun open(stored: String, unwrap: Cipher): String {
        val parts = stored.split(":")
        require(parts.size == 3) { "the stored entry is not an envelope" }

        val content = SecretKeySpec(
            unwrap.doFinal(Base64.decode(parts[0], Base64.NO_WRAP)),
            "AES"
        )

        val plaintext = Cipher.getInstance(CONTENT_TRANSFORM).apply {
            init(
                Cipher.DECRYPT_MODE,
                content,
                GCMParameterSpec(GCM_TAG_BITS, Base64.decode(parts[1], Base64.NO_WRAP))
            )
        }.doFinal(Base64.decode(parts[2], Base64.NO_WRAP))

        return String(plaintext, Charsets.UTF_8)
    }

    private fun unwrapCipher(): Cipher =
        Cipher.getInstance(WRAP_TRANSFORM).apply {
            init(Cipher.DECRYPT_MODE, getOrCreateKeyPair().privateKey, oaepSpec())
        }

    // Nothing about OAEP is left to a default here, and the reason is a failure
    // that survives a correct prompt. `OAEPWithSHA-256AndMGF1Padding` names the
    // message digest only; the JCA default for the MGF1 digest is SHA-1, and
    // Keymaster checks that second digest against the key's authorised set. A
    // public-key encrypt can run outside the TEE and succeeds either way, so the
    // mismatch appears only on the private-key `doFinal` — reported as the
    // generic KM_ERROR_UNKNOWN_ERROR (-1000), after the fingerprint was accepted
    // and with nothing in the message about digests. Read off a Galaxy A51.
    private fun oaepSpec(): OAEPParameterSpec = OAEPParameterSpec(
        "SHA-256",
        "MGF1",
        MGF1ParameterSpec.SHA1,
        PSource.PSpecified.DEFAULT,
    )
}
