import Foundation
import LocalAuthentication
import Security

// MARK: - BiometricVault Function Namespace
//
// SPIKE (Tier A proof, iOS). Unlike SecureStorage — which writes items with
// kSecAttrAccessibleWhenUnlockedThisDeviceOnly (readable whenever the device
// is unlocked) — this vault attaches a SecAccessControl requiring
// `.biometryCurrentSet`, so the SECURE ENCLAVE itself refuses to release the
// bytes without a fresh Face ID / Touch ID. `.biometryCurrentSet` also
// auto-invalidates the item if the enrolled biometric set changes (a new
// finger/face is added) — the anti-coercion property the design relies on.
//
// iOS fits the synchronous bridge model: SecItemCopyMatching blocks the
// calling thread while the OS presents the biometric sheet, so Get() can
// return the value (or a cancel/fail error) inline. Android cannot (see the
// .kt file) — that asymmetry is the core spike finding.

enum BiometricVaultFunctions {

    // MARK: - BiometricVault.Set
    class Set: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let key = parameters["key"] as? String else {
                throw BridgeError.invalidParameters("key is required")
            }
            do {
                if let value = parameters["value"] as? String {
                    try BiometricKeychain.save(key: key, value: value)
                } else {
                    try BiometricKeychain.delete(key: key)
                }
                return ["success": true]
            } catch {
                throw BridgeError.executionFailed("BiometricVault.Set failed: \(error.localizedDescription)")
            }
        }
    }

    // MARK: - BiometricVault.Get  (presents Face ID / Touch ID)
    class Get: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let key = parameters["key"] as? String else {
                throw BridgeError.invalidParameters("key is required")
            }
            let reason = (parameters["reason"] as? String) ?? "Unlock Beatrax"
            do {
                // Returns nil for "no entry"; throws for user-cancel / auth-fail
                // (which must NOT be treated as "no key" by the caller).
                if let value = try BiometricKeychain.load(key: key, reason: reason) {
                    return ["value": value, "authenticated": true]
                }
                return ["value": "", "authenticated": false, "missing": true]
            } catch let error as BiometricKeychainError {
                switch error {
                case .userCanceled:
                    return ["value": "", "authenticated": false, "canceled": true]
                case .authFailed:
                    return ["value": "", "authenticated": false, "failed": true]
                default:
                    throw BridgeError.executionFailed("BiometricVault.Get failed: \(error.localizedDescription)")
                }
            } catch {
                throw BridgeError.executionFailed("BiometricVault.Get failed: \(error.localizedDescription)")
            }
        }
    }

    // MARK: - BiometricVault.IsAvailable
    //
    // Whether this device can gate an entry behind a biometric RIGHT NOW.
    //
    // The application used to answer this from PHP_OS_FAMILY, which says only
    // which operating system is running. An iPhone with Face ID switched off
    // for this app, or with no face enrolled, answered the same as one that
    // can — so the reader was offered biometric unlock and the enrolment then
    // failed with nothing to read but the word "false".
    //
    // deviceOwnerAuthenticationWithBiometrics and not the passcode-inclusive
    // policy: the keychain item is written with .biometryCurrentSet, so a
    // passcode this call would admit is one the enclave then refuses.
    class IsAvailable: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            let context = LAContext()
            var error: NSError?
            let available = context.canEvaluatePolicy(.deviceOwnerAuthenticationWithBiometrics, error: &error)

            // The reason travels with the answer. LAError's cases are the same
            // five distinctions Android draws, under different names.
            let reason: String
            switch LAError.Code(rawValue: error?.code ?? 0) {
            case _ where available: reason = "available"
            case .biometryNotEnrolled: reason = "none_enrolled"
            case .biometryNotAvailable: reason = "no_hardware"
            case .biometryLockout: reason = "hardware_unavailable"
            case .passcodeNotSet: reason = "no_passcode"
            default: reason = "unsupported"
            }

            return ["available": available, "reason": reason]
        }
    }

    // MARK: - BiometricVault.PollRecovered

    // iOS recovers synchronously: Get releases the bytes inline once the
    // enclave accepts Face ID, so nothing is ever stashed for a later poll.
    // Declared all the same, because the PHP facade is one file for both
    // platforms and an undeclared function answers "function not found" —
    // which reads as a bridge fault rather than as the empty slot it is.
    class PollRecovered: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return ["value": ""]
        }
    }

    // MARK: - BiometricVault.CancelPrompt

    // iOS presents its prompt inside the synchronous Get and returns only once
    // the enclave has answered, so there is never one standing when the reader
    // takes another road. Declared because the PHP facade is one file for both.
    class CancelPrompt: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            return ["success": true]
        }
    }

    // MARK: - BiometricVault.Delete
    class Delete: BridgeFunction {
        func execute(parameters: [String: Any]) throws -> [String: Any] {
            guard let key = parameters["key"] as? String else {
                throw BridgeError.invalidParameters("key is required")
            }
            do {
                try BiometricKeychain.delete(key: key)
                return ["success": true]
            } catch {
                throw BridgeError.executionFailed("BiometricVault.Delete failed: \(error.localizedDescription)")
            }
        }
    }
}

// MARK: - Biometric-gated Keychain helper

// LocalizedError, not a bare `var localizedDescription`. The throw sites catch
// `Error`, and on that existential Swift's own NSError bridging wins over a
// plain property of the same name — so every message below was unreachable.
// Read off an iPhone 12 mini: "The operation couldn't be completed.
// (NativePHP.(unknown context at $1062cdb7c).BiometricKeychainError error 0.)".
private enum BiometricKeychainError: Error, LocalizedError {
    case encodingError
    case decodingError
    case accessControlCreateFailed
    case userCanceled
    case authFailed
    case saveFailed(OSStatus)
    case loadFailed(OSStatus)
    case deleteFailed(OSStatus)

    var errorDescription: String? {
        switch self {
        case .encodingError: return "Failed to encode value"
        case .decodingError: return "Failed to decode value"
        case .accessControlCreateFailed: return "Failed to create SecAccessControl"
        case .userCanceled: return "User canceled biometric prompt"
        case .authFailed: return "Biometric authentication failed"
        case .saveFailed(let s): return "Keychain save failed (\(s))"
        case .loadFailed(let s): return "Keychain load failed (\(s))"
        case .deleteFailed(let s): return "Keychain delete failed (\(s))"
        }
    }
}

private class BiometricKeychain {

    private static let service = (Bundle.main.bundleIdentifier ?? "com.beatrax.mobile") + ".biometricvault"

    static func save(key: String, value: String) throws {
        guard let data = value.data(using: .utf8) else { throw BiometricKeychainError.encodingError }

        // Idempotent write: delete-then-add so the access control is (re)applied
        // and a rotated blob replaces the old one. NOT atomic — if SecItemAdd
        // fails after the delete, the prior blob is gone and re-enrollment is
        // required. Acceptable for a KEK-wrap blob (re-enroll from a PIN unlock).
        try? delete(key: key)

        var acError: Unmanaged<CFError>?
        guard let access = SecAccessControlCreateWithFlags(
            nil,
            // Requires a device passcode to exist; never leaves the device.
            kSecAttrAccessibleWhenPasscodeSetThisDeviceOnly,
            // The enclave gates reads on the CURRENT biometric set; adding a
            // new finger/face invalidates the item (anti-coercion).
            .biometryCurrentSet,
            &acError
        ) else {
            throw BiometricKeychainError.accessControlCreateFailed
        }

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecValueData as String: data,
            kSecAttrAccessControl as String: access
        ]

        let status = SecItemAdd(query as CFDictionary, nil)
        guard status == errSecSuccess else { throw BiometricKeychainError.saveFailed(status) }
    }

    static func load(key: String, reason: String) throws -> String? {
        let context = LAContext()
        context.localizedReason = reason

        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key,
            kSecReturnData as String: true,
            kSecMatchLimit as String: kSecMatchLimitOne,
            // Attaching the LAContext makes SecItemCopyMatching present the
            // biometric sheet and block until the user responds.
            kSecUseAuthenticationContext as String: context,
            kSecUseOperationPrompt as String: reason
        ]

        var result: AnyObject?
        let status = SecItemCopyMatching(query as CFDictionary, &result)

        switch status {
        case errSecSuccess:
            guard let data = result as? Data,
                  let value = String(data: data, encoding: .utf8) else {
                throw BiometricKeychainError.decodingError
            }
            return value
        case errSecItemNotFound:
            return nil
        case errSecUserCanceled:
            throw BiometricKeychainError.userCanceled
        case errSecAuthFailed:
            throw BiometricKeychainError.authFailed
        default:
            throw BiometricKeychainError.loadFailed(status)
        }
    }

    static func delete(key: String) throws {
        let query: [String: Any] = [
            kSecClass as String: kSecClassGenericPassword,
            kSecAttrService as String: service,
            kSecAttrAccount as String: key
        ]
        let status = SecItemDelete(query as CFDictionary)
        guard status == errSecSuccess || status == errSecItemNotFound else {
            throw BiometricKeychainError.deleteFailed(status)
        }
    }
}
