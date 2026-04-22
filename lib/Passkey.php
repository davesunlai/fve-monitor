<?php
declare(strict_types=1);

namespace FveMonitor\Lib;

use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\PublicKeyCredentialSource;
use Webauthn\PublicKeyCredentialLoader;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AttestationStatement\AttestationObjectLoader;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AttestationStatement\NoneAttestationStatementSupport;
use Webauthn\TokenBinding\IgnoreTokenBindingHandler;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Cose\Algorithms;

/**
 * Wrapper pro WebAuthn/Passkey operace.
 *
 * Tok registrace:
 *   1. createRegistrationOptions(user) → options (JSON pro prohlížeč)
 *      - uloží challenge do session
 *   2. verifyRegistrationResponse(responseJson) → uloží credential do DB
 *
 * Tok přihlášení:
 *   1. createLoginOptions() → options (JSON pro prohlížeč, i bez usernamu)
 *      - uloží challenge do session
 *   2. verifyLoginResponse(responseJson) → vrátí user_id pokud OK
 */
class Passkey
{
    private string $rpId;
    private string $rpName;

    public function __construct()
    {
        $this->rpId   = $_SERVER['HTTP_HOST'] ?? 'fve.sunlai.org';
        $this->rpName = 'FVE Monitor';

        // Pokud je za sebou port (např. 8080), odříz ho
        if (str_contains($this->rpId, ':')) {
            $this->rpId = explode(':', $this->rpId)[0];
        }
    }

    /**
     * Vygeneruje options pro vytvoření nového passkey.
     * Uloží challenge do session.
     */
    public function createRegistrationOptions(array $user): array
    {
        $rpEntity = PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId);

        $userEntity = PublicKeyCredentialUserEntity::create(
            $user['username'],                    // name (zobrazované)
            (string) $user['id'],                 // id (handle) - user_id jako string
            $user['full_name'] ?? $user['username']  // displayName
        );

        // Požadujeme ES256 (P-256 ECDSA) + RS256 (RSA) pro maximální kompatibilitu
        $publicKeyCredentialParametersList = [
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_ES256),
            PublicKeyCredentialParameters::create('public-key', Algorithms::COSE_ALGORITHM_RS256),
        ];

        // Vyfiltrujeme existující credentials (nelze přidat stejné zařízení 2x)
        $existingCredentials = self::getUserCredentials((int) $user['id']);
        $excludeCredentials = array_map(
            fn($row) => PublicKeyCredentialDescriptor::create('public-key', $row['credential_id']),
            $existingCredentials
        );

        // Authenticator selection - required = biometric/PIN
        $authenticatorSelection = AuthenticatorSelectionCriteria::create()
            ->setUserVerification(AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_REQUIRED)
            ->setResidentKey(AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED)
            ->setAuthenticatorAttachment(null);  // null = platform OR cross-platform (oba ok)

        $options = PublicKeyCredentialCreationOptions::create(
            $rpEntity,
            $userEntity,
            random_bytes(32),                      // challenge
            $publicKeyCredentialParametersList
        )
            ->setTimeout(60000)                    // 60 s
            ->excludeCredentials(...$excludeCredentials)
            ->setAuthenticatorSelection($authenticatorSelection)
            ->setAttestation(PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE);

        // Uložit do session pro následné ověření - jako JSON string, ne pole
        Auth::start();
        $_SESSION['webauthn_register_options'] = json_encode($options);

        return $options->jsonSerialize();
    }

    /**
     * Ověří registration response z prohlížeče a uloží credential do DB.
     *
     * @param string $responseJson  JSON z navigator.credentials.create()
     * @param string $deviceName    Volitelný název zařízení (pro UI)
     * @return array ['success' => bool, 'credential_id' => int, 'error' => ?]
     */
    public function verifyRegistrationResponse(string $responseJson, ?string $deviceName = null): array
    {
        Auth::start();
        if (empty($_SESSION['webauthn_register_options'])) {
            return ['success' => false, 'error' => 'Chybí registrační options v session (timeout?)'];
        }
        if (!Auth::isLoggedIn()) {
            return ['success' => false, 'error' => 'Musíš být přihlášen'];
        }

        try {
            $user = Auth::currentUser();

            $optionsJson = $_SESSION['webauthn_register_options'];
            $options = PublicKeyCredentialCreationOptions::createFromString($optionsJson);

            // Loader + Validator
            $attestationStatementManager = AttestationStatementSupportManager::create();
            $attestationStatementManager->add(NoneAttestationStatementSupport::create());

            $attestationObjectLoader = AttestationObjectLoader::create($attestationStatementManager);
            $loader = PublicKeyCredentialLoader::create($attestationObjectLoader);
            $credential = $loader->load($responseJson);

            if (!$credential->response instanceof AuthenticatorAttestationResponse) {
                return ['success' => false, 'error' => 'Špatný typ response'];
            }

            $csmFactory = new CeremonyStepManagerFactory();
            $creationCSM = $csmFactory->creationCeremony();

            $validator = AuthenticatorAttestationResponseValidator::create(
                $attestationStatementManager, // attestationStatementSupportManager
                null, // publicKeyCredentialSourceRepository
                null, // tokenBindingHandler
                null, // extensionOutputCheckerHandler
                null, // eventDispatcher
                $creationCSM
            );

            $publicKeyCredentialSource = $validator->check(
                $credential->response,
                $options,
                $this->rpId
            );

            // Ulož do DB
            $stmt = Database::pdo()->prepare(
                'INSERT INTO webauthn_credentials
                    (user_id, credential_id, public_key, sign_count, transports, aaguid, attestation_type, device_name)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([
                (int) $user['id'],
                $publicKeyCredentialSource->publicKeyCredentialId,
                base64_encode(serialize($publicKeyCredentialSource)),
                $publicKeyCredentialSource->counter,
                implode(',', $publicKeyCredentialSource->transports),
                $publicKeyCredentialSource->aaguid->__toString(),
                $publicKeyCredentialSource->attestationType,
                $deviceName ?: 'Zařízení',
            ]);

            unset($_SESSION['webauthn_register_options']);

            return [
                'success' => true,
                'credential_id' => (int) Database::pdo()->lastInsertId(),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /**
     * Vygeneruje options pro passwordless login.
     * Uloží challenge do session.
     */
    public function createLoginOptions(): array
    {
        $options = PublicKeyCredentialRequestOptions::create(random_bytes(32))
            ->setTimeout(60000)
            ->setRpId($this->rpId)
            ->setUserVerification(PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED);

        Auth::start();
        $_SESSION['webauthn_login_options'] = json_encode($options);

        return $options->jsonSerialize();
    }

    /**
     * Ověří login response a přihlásí uživatele (vytvoří session).
     * @return array ['success' => bool, 'user' => ?, 'error' => ?]
     */
    public function verifyLoginResponse(string $responseJson): array
    {
        Auth::start();
        if (empty($_SESSION['webauthn_login_options'])) {
            return ['success' => false, 'error' => 'Chybí login options v session'];
        }

        try {
            $optionsJson = $_SESSION['webauthn_login_options'];
            $options = PublicKeyCredentialRequestOptions::createFromString($optionsJson);

            $attestationStatementManager = AttestationStatementSupportManager::create();
            $attestationStatementManager->add(NoneAttestationStatementSupport::create());

            $attestationObjectLoader = AttestationObjectLoader::create($attestationStatementManager);
            $loader = PublicKeyCredentialLoader::create($attestationObjectLoader);
            $credential = $loader->load($responseJson);

            if (!$credential->response instanceof AuthenticatorAssertionResponse) {
                return ['success' => false, 'error' => 'Špatný typ response'];
            }

            $credentialId = $credential->rawId;

            // Najdi credential v DB
            $row = Database::one(
                'SELECT c.*, u.id AS user_id_fk, u.username, u.role, u.is_active
                 FROM webauthn_credentials c
                 JOIN users u ON u.id = c.user_id
                 WHERE c.credential_id = ? AND u.is_active = 1',
                [$credentialId]
            );

            if (!$row) {
                return ['success' => false, 'error' => 'Neznámý passkey'];
            }

            $publicKeyCredentialSource = unserialize(base64_decode($row['public_key']));

            $csmFactory = new CeremonyStepManagerFactory();
            $requestCSM = $csmFactory->requestCeremony();

            $validator = AuthenticatorAssertionResponseValidator::create(
                null, // publicKeyCredentialSourceRepository
                null, // tokenBindingHandler
                null, // extensionOutputCheckerHandler
                null, // algorithmManager
                null, // eventDispatcher
                $requestCSM
            );

            $validatedSource = $validator->check(
                $publicKeyCredentialSource,
                $credential->response,
                $options,
                $this->rpId,
                (string) $row['user_id']
            );

            // Aktualizuj counter
            Database::pdo()->prepare(
                'UPDATE webauthn_credentials
                 SET sign_count = ?, public_key = ?, last_used_at = NOW()
                 WHERE id = ?'
            )->execute([
                $validatedSource->counter,
                base64_encode(serialize($validatedSource)),
                $row['id'],
            ]);

            // Aktualizuj user last_login
            Database::pdo()->prepare(
                'UPDATE users SET last_login_at = NOW() WHERE id = ?'
            )->execute([$row['user_id']]);

            // Vytvoř session (stejně jako klasický login)
            session_regenerate_id(true);
            $_SESSION['user_id']    = (int) $row['user_id'];
            $_SESSION['username']   = $row['username'];
            $_SESSION['role']       = $row['role'];
            $_SESSION['login_time'] = time();

            unset($_SESSION['webauthn_login_options']);

            return [
                'success' => true,
                'user' => [
                    'id'       => (int) $row['user_id'],
                    'username' => $row['username'],
                    'role'     => $row['role'],
                ],
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }

    /** Vrátí passkey credentials konkrétního uživatele. */
    public static function getUserCredentials(int $userId): array
    {
        return Database::all(
            'SELECT * FROM webauthn_credentials WHERE user_id = ? ORDER BY created_at DESC',
            [$userId]
        );
    }

    /** Smaže credential (jen vlastník nebo admin). */
    public static function deleteCredential(int $credentialRecordId, int $userId): bool
    {
        $stmt = Database::pdo()->prepare(
            'DELETE FROM webauthn_credentials WHERE id = ? AND user_id = ?'
        );
        $stmt->execute([$credentialRecordId, $userId]);
        return $stmt->rowCount() > 0;
    }
}
