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
import java.lang.ref.WeakReference
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
 * ONE KEY PAIR PER SLOT. A single shared alias made every reader's enrolment
 * one another's: forgetting one reader's entry deleted the pair the others'
 * entries were sealed under, and their next Get minted a replacement mid-read,
 * prompted against it and failed forever on a blob nothing could open. The
 * alias is derived from the slot name, deleted with the slot, and is also the
 * envelope's associated data -- so a blob moved between slots, or restored
 * after a rekey retired the pair, fails on the tag instead of opening.
 *
 * RECOVERY COMPLETES BY SIGNAL (E5-R10). Get returns `async`; the prompt
 * callback stashes the decrypted blob in a transient in-process slot and raises
 * `BiometricVault.Recovered`. The blob never travels in the event payload --
 * PHP fetches it over the bridge with PollRecovered, which names the slot it
 * expects and consumes it on read. The slot also carries a deadline, because a
 * blob nobody claims must not wait for a lifecycle edge that an overlay, a
 * split-screen focus loss or picture-in-picture never delivers.
 */
object BiometricVaultFunctions {

    private const val ALIAS_PREFIX = "beatrax.biometric.vault.wrap.v3."

    // Aliases no build can read any more, deleted rather than migrated. The
    // first is the spike's symmetric key, which Set never once wrote under; the
    // second authorised SHA-256 alone, which the OAEP note below is about; the
    // third is the one pair every reader on the device shared.
    private val RETIRED_ALIASES = listOf(
        "beatrax.biometric.vault.kek",
        "beatrax.biometric.vault.wrap",
        "beatrax.biometric.vault.wrap.v2",
    )

    private const val PREFS_NAME = "beatrax_biometric_vault"
    private const val WRAP_TRANSFORM = "RSA/ECB/OAEPWithSHA-256AndMGF1Padding"
    private const val CONTENT_TRANSFORM = "AES/GCM/NoPadding"
    private const val GCM_TAG_BITS = 128

    private const val EVENT_RECOVERED = "BiometricVault.Recovered"
    private const val EVENT_FAILED = "BiometricVault.Failed"

    // How long a released blob may wait to be claimed. The event crosses the
    // WebView and returns as a Livewire update against a page that is already
    // rendered, which is well inside this; past it, nobody is coming.
    private const val SLOT_TTL_MS = 8_000L

    private val main = Handler(Looper.getMainLooper())

    // The blob the prompt released, and the slot it came out of. The wrap
    // secret lives inside the blob, so any well-formed blob unwraps to some
    // valid data key and only the slot name says whose.
    private class Released(val key: String, val blob: String, val ticket: Int)

    @Volatile
    private var released: Released? = null

    private var expiry: Runnable? = null

    // Keyed on the owner and not on a flag. This is an `object`, the observer
    // binds to one activity's registry, and the process outlives its activity:
    // a flag left a DESTROYED registry watching and refused to attach the live
    // activity's, which made ON_STOP a no-op for the rest of the process.
    @Volatile
    private var watched: WeakReference<LifecycleOwner>? = null

    @Volatile
    private var retiredSwept = false

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

    private fun aliasFor(key: String): String = ALIAS_PREFIX + key

    // The envelope is bound to the alias entitled to open it, which names both
    // the format generation and the slot. There is no data-key epoch this side
    // can compute; the rollback an epoch would have caught is caught instead by
    // the pair being deleted with the entry it sealed.
    private fun aad(key: String): ByteArray = aliasFor(key).toByteArray(Charsets.UTF_8)

    // Once per process, and never as a cost of unlocking. These aliases are
    // gone after the first pass, so the read that proves it is a Keystore call
    // per attempt for nothing.
    private fun sweepRetired(ks: KeyStore) {
        if (retiredSwept) return
        retiredSwept = true

        for (alias in RETIRED_ALIASES) {
            if (ks.containsAlias(alias)) ks.deleteEntry(alias)
        }
    }

    private fun getOrCreateKeyPair(alias: String): KeyStore.PrivateKeyEntry {
        val ks = keyStore()
        sweepRetired(ks)

        (ks.getEntry(alias, null) as? KeyStore.PrivateKeyEntry)?.let { return it }

        val gen = KeyPairGenerator.getInstance(KeyProperties.KEY_ALGORITHM_RSA, "AndroidKeyStore")
        gen.initialize(
            KeyGenParameterSpec.Builder(
                alias,
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

        return keyStore().getEntry(alias, null) as KeyStore.PrivateKeyEntry
    }

    private fun deleteAlias(alias: String) {
        val ks = keyStore()
        if (ks.containsAlias(alias)) ks.deleteEntry(alias)
    }

    // --- The transient slot --------------------------------------------------

    private fun stash(key: String, blob: String, ceremony: Int) {
        synchronized(this) {
            released = Released(key, blob, ceremony)
            cancelExpiry()

            val deadline = Runnable { expire(ceremony) }
            expiry = deadline
            main.postDelayed(deadline, SLOT_TTL_MS)
        }
    }

    // An exact slot match or nothing. A poll naming another slot is a session
    // asking for a key that is not its own, and the answer to that is that
    // nobody gets it -- the wrap secret rides inside the blob, so the slot name
    // is the only thing that says whose key this is.
    private fun claim(key: String): String? = synchronized(this) {
        val held = released ?: return@synchronized null
        drop()

        if (held.key != key) {
            Log.w("BiometricVault", "a poll named a slot the released blob does not belong to; the blob was discarded")
            return@synchronized null
        }

        held.blob
    }

    private fun drop() {
        synchronized(this) {
            released = null
            cancelExpiry()
        }
    }

    private fun cancelExpiry() {
        expiry?.let { main.removeCallbacks(it) }
        expiry = null
    }

    private fun expire(ceremony: Int) {
        synchronized(this) {
            if (released?.ticket != ceremony) return@synchronized

            Log.d("BiometricVault", "a released blob went unclaimed within its deadline and was dropped")
            drop()
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

            return try {
                if (value == null) {
                    forget(context, key)
                } else {
                    context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE)
                        .edit().putString(key, seal(value, key)).apply()
                }

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

            val enrolled = try {
                val ks = keyStore()
                sweepRetired(ks)
                ks.containsAlias(aliasFor(key))
            } catch (e: Exception) {
                Log.e("BiometricVault.Get", "the keystore would not answer: ${e.message}", e)
                return mapOf("failed" to true)
            }

            // Nothing left to authenticate against. Reading is not the place to
            // mint a key pair: the one it would make cannot open this blob, so
            // the prompt it then raises can only ever be answered for nothing.
            if (!enrolled) {
                Log.w("BiometricVault.Get", "the entry has no key pair left; it cannot be read again")
                forgetQuietly(activity, key)
                return mapOf("missing" to true)
            }

            val cipher = try {
                unwrapCipher(aliasFor(key))
            } catch (e: KeyPermanentlyInvalidatedException) {
                // The enrolled set changed, which is exactly what the key was
                // built to notice. Nothing can read this blob again.
                Log.w("BiometricVault.Get", "the enrolled biometric changed; the entry cannot be read again")
                forgetQuietly(activity, key)
                return mapOf("missing" to true)
            } catch (e: Exception) {
                Log.e("BiometricVault.Get", "could not prepare the unwrap: ${e.message}", e)
                return mapOf("failed" to true)
            }

            main.post { prompt(activity, cipher, reason, stored, key) }

            return mapOf("async" to true)
        }
    }

    /**
     * PollRecovered: hands over the blob the prompt released, once, and only to
     * the slot it was released from. Consume on read is one part of the
     * contract; the deadline the stash carries and the lifecycle observer are
     * the other two.
     */
    class PollRecovered(@Suppress("UNUSED_PARAMETER") activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")

            return mapOf("value" to (claim(key) ?: ""))
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
     * CancelPrompt: stand down. It takes the prompt off the screen and drops
     * any blob a prompt already released, which is the pair of things that must
     * not survive the reader answering another way — or the app re-locking
     * while it is still in the foreground, where no lifecycle edge fires at all.
     *
     * The registration template hands every bridge function the activity;
     * standing down needs nothing from it, and holding it would keep a
     * destroyed activity alive in this process-wide object.
     */
    class CancelPrompt(@Suppress("UNUSED_PARAMETER") activity: FragmentActivity) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            // Read here rather than inside the posted dismissal, so the caller
            // is told what was standing at the moment it asked.
            val standing = active != null
            main.post { dismiss() }

            return mapOf("success" to true, "standing" to standing)
        }
    }

    class Delete(private val context: Context) : BridgeFunction {
        override fun execute(parameters: Map<String, Any>): Map<String, Any> {
            val key = parameters["key"] as? String
                ?: throw BridgeError.InvalidParameters("key is required")

            return try {
                forget(context, key)
                mapOf("success" to true)
            } catch (e: Exception) {
                Log.e("BiometricVault.Delete", "the entry's key pair would not delete: ${e.message}", e)
                mapOf("success" to false)
            }
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
                    stash(key, open(stored, unwrap, key), mine)
                    NativeActionCoordinator.dispatchEvent(activity, EVENT_RECOVERED, "{}")
                } catch (e: KeyPermanentlyInvalidatedException) {
                    forgetQuietly(activity, key)
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
        drop()
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
        drop()
        active?.cancelAuthentication()
        active = null
    }

    private fun forget(context: Context, key: String) {
        // The pair first: if it will not delete, the entry it seals stays put
        // and the caller is told the key is still held, rather than being left
        // with a gated pair nothing points at.
        deleteAlias(aliasFor(key))
        context.getSharedPreferences(PREFS_NAME, Context.MODE_PRIVATE).edit().remove(key).apply()
    }

    // For the read paths, where the removal is housekeeping behind an answer
    // that is already decided and throwing would rewrite it as a bridge fault.
    private fun forgetQuietly(context: Context, key: String) {
        try {
            forget(context, key)
        } catch (e: Exception) {
            Log.e("BiometricVault", "the unreadable entry would not delete: ${e.message}", e)
        }
    }

    // A blob that outlived the foreground is one a later dispatch could claim
    // without a prompt behind it. A backstop and not the guarantee: an overlay,
    // a split-screen focus loss and picture-in-picture all pause without
    // stopping, so the deadline the stash carries is what bounds every case.
    private fun watchLifecycle(activity: FragmentActivity) {
        if (watched?.get() === activity) return
        watched = WeakReference(activity)

        activity.lifecycle.addObserver(LifecycleEventObserver { _: LifecycleOwner, event: Lifecycle.Event ->
            if (event == Lifecycle.Event.ON_STOP) {
                drop()
            }
        })
    }

    // --- Envelope ------------------------------------------------------------

    // A content key per write, wrapped by the enclave-bound public half. The
    // prompt authorises the unwrap of that content key and nothing larger,
    // which is what keeps the stored value's length out of this file.
    private fun seal(value: String, key: String): String {
        val content: SecretKey = KeyGenerator.getInstance("AES")
            .apply { init(256) }
            .generateKey()

        val body = Cipher.getInstance(CONTENT_TRANSFORM).apply {
            init(Cipher.ENCRYPT_MODE, content)
            updateAAD(aad(key))
        }

        val ciphertext = body.doFinal(value.toByteArray(Charsets.UTF_8))

        val wrapped = Cipher.getInstance(WRAP_TRANSFORM).apply {
            init(Cipher.ENCRYPT_MODE, getOrCreateKeyPair(aliasFor(key)).certificate.publicKey, oaepSpec())
        }.doFinal(content.encoded)

        return listOf(wrapped, body.iv, ciphertext).joinToString(":") {
            Base64.encodeToString(it, Base64.NO_WRAP)
        }
    }

    private fun open(stored: String, unwrap: Cipher, key: String): String {
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
            updateAAD(aad(key))
        }.doFinal(Base64.decode(parts[2], Base64.NO_WRAP))

        return String(plaintext, Charsets.UTF_8)
    }

    // Strictly the pair that is already there. Get checks for it first and
    // answers `missing` when it is gone, so a read never mints the key that
    // would have made the prompt unanswerable.
    private fun unwrapCipher(alias: String): Cipher {
        val entry = keyStore().getEntry(alias, null) as? KeyStore.PrivateKeyEntry
            ?: throw IllegalStateException("no key pair is enrolled under $alias")

        return Cipher.getInstance(WRAP_TRANSFORM).apply {
            init(Cipher.DECRYPT_MODE, entry.privateKey, oaepSpec())
        }
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
