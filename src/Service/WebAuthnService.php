<?php

declare(strict_types=1);

namespace Passkeys\Service;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Locator\LocatorAwareTrait;
use Passkeys\Contract\PasskeyUserInterface;
use Passkeys\Model\Entity\Passkey;
use Passkeys\Model\Table\PasskeysTable;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Component\Uid\Uuid;
use Webauthn\AttestationStatement\AttestationStatementSupportManager;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\AuthenticatorSelectionCriteria;
use Webauthn\CeremonyStep\CeremonyStepManagerFactory;
use Webauthn\CredentialRecord;
use Webauthn\Denormalizer\WebauthnSerializerFactory;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\PublicKeyCredentialParameters;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialRpEntity;
use Webauthn\PublicKeyCredentialUserEntity;
use Webauthn\TrustPath\EmptyTrustPath;

/**
 * WebAuthn ceremony service, backed by web-auth/webauthn-lib (Spomky-Labs).
 *
 * Ported from RentCraft's `App\Service\Auth\WebAuthnService`. Abstractions
 * applied during the port:
 *
 *  - User identity moved behind {@see PasskeyUserInterface} (no concrete
 *    User entity coupling).
 *  - Challenge persistence moved behind {@see ChallengeStore} (opaque-key
 *    nonce store) instead of inline `Cache::write/read` calls.
 *  - User lookup during login moved behind {@see UserResolver}.
 *  - AAGUID → vendor-label resolution moved behind {@see AaguidLabelResolver};
 *    the resolved label is persisted on the row at registration time.
 *  - Configure keys moved under the `Passkeys.*` namespace.
 *  - All failures raise {@see WebAuthnException} (single subclass of
 *    `\RuntimeException`).
 *
 * Surface:
 *  - startRegistration / finishRegistration
 *  - startLogin / finishLogin (discoverable lookup)
 *  - startReauth / finishReauth (UV=required, narrowed to one user)
 *
 * The challenge bytes never leave the server: the start* methods stash the
 * full request/creation options in the {@see ChallengeStore} keyed by an
 * opaque random handle, and return that handle in `challengeKey`. The
 * finish* methods accept the handle back from the browser and consume it
 * one-shot.
 */
class WebAuthnService
{
    use LocatorAwareTrait;

    /**
     * @var string
     */
    private const ZERO_UUID = '00000000-0000-0000-0000-000000000000';

    private string $rpName;

    private string $rpId;

    private SerializerInterface $serializer;

    private AuthenticatorAttestationResponseValidator $attestationValidator;

    private AuthenticatorAssertionResponseValidator $assertionValidator;

    /**
     * @param \Passkeys\Service\ChallengeStore $challengeStore
     * @param \Passkeys\Service\UserResolver $userResolver
     * @param \Passkeys\Service\AaguidLabelResolver $aaguidResolver
     *
     * @throws \Passkeys\Service\WebAuthnException
     */
    public function __construct(
        private ChallengeStore $challengeStore,
        private UserResolver $userResolver,
        private AaguidLabelResolver $aaguidResolver,
    ) {
        $this->rpName = (string)Configure::read('Passkeys.rpName', 'My App');
        $this->rpId = (string)Configure::read('Passkeys.rpId', '');
        if ($this->rpId === '') {
            throw new WebAuthnException(
                'Passkeys.rpId is not configured. Set it via Configure or the WEBAUTHN_RP_ID env var.',
            );
        }

        $attestationSupportManager = AttestationStatementSupportManager::create();
        $this->serializer = (new WebauthnSerializerFactory($attestationSupportManager))->create();

        $factory = new CeremonyStepManagerFactory();
        $factory->setAttestationStatementSupportManager($attestationSupportManager);

        $this->attestationValidator = AuthenticatorAttestationResponseValidator::create($factory->creationCeremony());
        $this->assertionValidator = AuthenticatorAssertionResponseValidator::create($factory->requestCeremony());
    }

    /**
     * Builds the registration challenge for an authenticated user.
     *
     * Returns the WebAuthn options dict the browser passes to
     * `navigator.credentials.create`, plus a top-level `challengeKey` the
     * client must echo back on finishRegistration().
     *
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     *
     * @return array<string, mixed>
     */
    public function startRegistration(PasskeyUserInterface $user): array
    {
        $this->assertUnderPasskeyCap($user);

        $excludeCredentials = [];
        foreach ($this->passkeysFor($user) as $row) {
            $excludeCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                (string)$row->credential_id,
            );
        }

        $ceremony = (array)Configure::read('Passkeys.ceremony', []);
        $challenge = random_bytes(32);
        $opts = PublicKeyCredentialCreationOptions::create(
            $this->rpEntity(),
            $this->userEntity($user),
            $challenge,
            $this->defaultPubKeyCredParams(),
            // residentKey=preferred + UV=preferred is the "passkey" recipe.
            // Chrome / Safari show the full passkey UX (platform authenticator
            // + QR code for cross-device + USB security keys), not the legacy
            // "touch your security key" dialog. authenticatorAttachment stays
            // unset so the user picks the device they want.
            AuthenticatorSelectionCriteria::create(
                userVerification: (string)($ceremony['userVerification']
                    ?? AuthenticatorSelectionCriteria::USER_VERIFICATION_REQUIREMENT_PREFERRED),
                residentKey: (string)($ceremony['residentKey']
                    ?? AuthenticatorSelectionCriteria::RESIDENT_KEY_REQUIREMENT_PREFERRED),
            ),
            attestation: (string)($ceremony['attestation']
                ?? PublicKeyCredentialCreationOptions::ATTESTATION_CONVEYANCE_PREFERENCE_NONE),
            excludeCredentials: $excludeCredentials,
            timeout: max(1, (int)($ceremony['timeout'] ?? 60_000)),
        );

        $challengeKey = $this->challengeStore->put([
            'kind' => 'register',
            'user_id' => (string)$user->getUserId(),
            'options' => $this->serializer->serialize($opts, 'json'),
        ]);

        $payload = $this->serializeForBrowser($opts);
        $payload['challengeKey'] = $challengeKey;

        return $payload;
    }

    /**
     * Verifies the browser's attestation response and persists a passkey.
     *
     * `$clientResponse` must contain:
     *  - `challengeKey` — opaque handle returned from {@see startRegistration()}
     *  - `response` — the verbatim JSON `PublicKeyCredential` value the
     *    browser produced (id, rawId, type, response{…})
     *
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     * @param array<string, mixed> $clientResponse
     * @param string $passkeyName
     * @param string|null $emoji
     *
     * @throws \Passkeys\Service\WebAuthnException
     *
     * @return \Passkeys\Model\Entity\Passkey
     */
    public function finishRegistration(
        PasskeyUserInterface $user,
        array $clientResponse,
        string $passkeyName,
        ?string $emoji = null,
    ): Passkey {
        $this->assertUnderPasskeyCap($user);

        $key = isset($clientResponse['challengeKey']) ? (string)$clientResponse['challengeKey'] : '';
        if ($key === '') {
            throw new WebAuthnException('Registration challenge key missing.');
        }
        $stash = $this->challengeStore->consume($key);
        if ($stash === null || !isset($stash['options']) || (string)($stash['kind'] ?? '') !== 'register') {
            throw new WebAuthnException('Registration challenge missing or expired.');
        }
        if ((string)($stash['user_id'] ?? '') !== (string)$user->getUserId()) {
            // Challenge handles are bound to the user that started the
            // ceremony — never let user A finish user B's registration.
            throw new WebAuthnException('Registration challenge does not belong to this user.');
        }

        $credentialJson = $this->encodeBrowserResponse($clientResponse['response'] ?? $clientResponse);

        $opts = $this->serializer->deserialize(
            (string)$stash['options'],
            PublicKeyCredentialCreationOptions::class,
            'json',
        );
        $publicKeyCredential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

        $attestationResponse = $publicKeyCredential->response;
        if (!$attestationResponse instanceof AuthenticatorAttestationResponse) {
            throw new WebAuthnException('Expected attestation response.');
        }

        $source = $this->attestationValidator->check(
            $attestationResponse,
            $opts,
            $this->rpId,
        );

        $aaguidString = $source->aaguid->__toString();
        $aaguidBytes = $aaguidString !== self::ZERO_UUID ? $this->uuidToBytes($aaguidString) : null;
        $aaguidLabel = $aaguidBytes !== null ? $this->aaguidResolver->labelFor($aaguidBytes) : null;

        $passkeysTable = $this->passkeys();
        $entity = $passkeysTable->newEmptyEntity();
        $entity->set('user_id', $user->getUserId());
        $entity->set('credential_id', $source->publicKeyCredentialId);
        $entity->set('public_key', $source->credentialPublicKey);
        $entity->set('aaguid', $aaguidBytes);
        $entity->set('aaguid_label', $aaguidLabel);
        $entity->set('transports', $this->joinTransports(array_values($source->transports)));
        $entity->set('sign_count', (int)$source->counter);
        $entity->set('name', $this->normalizeName($passkeyName));
        $entity->set('emoji', $this->normalizeEmoji($emoji));
        $entity->set('last_used_at', null);

        $passkeysTable->saveOrFail($entity);

        return $entity;
    }

    /**
     * Builds the login challenge — discoverable lookup unless an email hint
     * is supplied (in which case allowCredentials is narrowed to that user).
     *
     * @param string|null $emailHint
     *
     * @return array<string, mixed>
     */
    public function startLogin(?string $emailHint = null): array
    {
        $ceremony = (array)Configure::read('Passkeys.ceremony', []);
        $challenge = random_bytes(32);

        $allowCredentials = [];
        if ($emailHint !== null && $emailHint !== '') {
            $user = $this->userResolver->byEmail($emailHint);
            if ($user !== null) {
                foreach ($this->passkeysFor($user) as $row) {
                    $allowCredentials[] = PublicKeyCredentialDescriptor::create(
                        PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                        (string)$row->credential_id,
                    );
                }
            }
        }

        $opts = PublicKeyCredentialRequestOptions::create(
            $challenge,
            rpId: $this->rpId,
            allowCredentials: $allowCredentials,
            userVerification: (string)($ceremony['userVerification']
                ?? PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED),
            timeout: max(1, (int)($ceremony['timeout'] ?? 60_000)),
        );

        $challengeKey = $this->challengeStore->put([
            'kind' => 'login',
            'options' => $this->serializer->serialize($opts, 'json'),
        ]);

        $payload = $this->serializeForBrowser($opts);
        $payload['challengeKey'] = $challengeKey;

        return $payload;
    }

    /**
     * Verifies the browser's assertion, returns the matched Passkey row with
     * sign_count + last_used_at refreshed.
     *
     * @param array<string, mixed> $clientResponse
     *
     * @throws \Passkeys\Service\WebAuthnException
     *
     * @return \Passkeys\Model\Entity\Passkey
     */
    public function finishLogin(array $clientResponse): Passkey
    {
        $key = isset($clientResponse['challengeKey']) ? (string)$clientResponse['challengeKey'] : '';
        if ($key === '') {
            throw new WebAuthnException('Login challenge key missing.');
        }
        $stash = $this->challengeStore->consume($key);
        if ($stash === null || !isset($stash['options']) || (string)($stash['kind'] ?? '') !== 'login') {
            throw new WebAuthnException('Login challenge missing or expired.');
        }

        $credentialJson = $this->encodeBrowserResponse($clientResponse['response'] ?? $clientResponse);

        $opts = $this->serializer->deserialize(
            (string)$stash['options'],
            PublicKeyCredentialRequestOptions::class,
            'json',
        );
        $publicKeyCredential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

        $assertionResponse = $publicKeyCredential->response;
        if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
            throw new WebAuthnException('Expected assertion response.');
        }

        // rawId on the deserialized object is the *decoded* binary credential
        // identifier — that's what we stored on the Passkey row.
        $passkey = $this->passkeys()
            ->find()
            ->where(['Passkeys.credential_id' => $publicKeyCredential->rawId])
            ->first();
        if ($passkey === null) {
            throw new WebAuthnException('Unknown passkey credential.');
        }

        $source = $this->passkeyToSource($passkey);

        $updated = $this->assertionValidator->check(
            $source,
            $assertionResponse,
            $opts,
            $this->rpId,
            null,
        );

        $newCounter = (int)$updated->counter;
        $oldCounter = (int)$passkey->sign_count;
        // Synced passkeys (Apple iCloud, Google Password Manager, …) always
        // return 0. Per spec, accept 0 unconditionally. Anything non-zero must
        // strictly advance, otherwise treat as cloned-credential rollback.
        if ($newCounter !== 0 && $newCounter <= $oldCounter) {
            throw new WebAuthnException('Sign-count rollback detected; refusing authentication.');
        }

        $passkey->set('sign_count', $newCounter);
        $passkey->set('last_used_at', DateTime::now());
        $this->passkeys()->saveOrFail($passkey);

        return $passkey;
    }

    /**
     * Builds a step-up reauth challenge for an already-authenticated user.
     * Always UV=required and narrowed to the user's own credentials.
     *
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     *
     * @return array<string, mixed>
     */
    public function startReauth(PasskeyUserInterface $user): array
    {
        $challenge = random_bytes(32);
        $ceremony = (array)Configure::read('Passkeys.ceremony', []);

        $allowCredentials = [];
        foreach ($this->passkeysFor($user) as $row) {
            $allowCredentials[] = PublicKeyCredentialDescriptor::create(
                PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
                (string)$row->credential_id,
            );
        }

        $opts = PublicKeyCredentialRequestOptions::create(
            $challenge,
            rpId: $this->rpId,
            allowCredentials: $allowCredentials,
            userVerification: PublicKeyCredentialRequestOptions::USER_VERIFICATION_REQUIREMENT_REQUIRED,
            timeout: max(1, (int)($ceremony['timeout'] ?? 60_000)),
        );

        $challengeKey = $this->challengeStore->put([
            'kind' => 'reauth',
            'user_id' => (string)$user->getUserId(),
            'options' => $this->serializer->serialize($opts, 'json'),
        ]);

        $payload = $this->serializeForBrowser($opts);
        $payload['challengeKey'] = $challengeKey;

        return $payload;
    }

    /**
     * Verifies a reauth assertion. Returns true on success, throws otherwise.
     *
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     * @param array<string, mixed> $clientResponse
     *
     * @throws \Passkeys\Service\WebAuthnException
     *
     * @return bool
     */
    public function finishReauth(PasskeyUserInterface $user, array $clientResponse): bool
    {
        $key = isset($clientResponse['challengeKey']) ? (string)$clientResponse['challengeKey'] : '';
        if ($key === '') {
            throw new WebAuthnException('Reauth challenge key missing.');
        }
        $stash = $this->challengeStore->consume($key);
        if ($stash === null || !isset($stash['options']) || (string)($stash['kind'] ?? '') !== 'reauth') {
            throw new WebAuthnException('Reauth challenge missing or expired.');
        }
        if ((string)($stash['user_id'] ?? '') !== (string)$user->getUserId()) {
            throw new WebAuthnException('Reauth challenge does not belong to this user.');
        }

        $credentialJson = $this->encodeBrowserResponse($clientResponse['response'] ?? $clientResponse);

        $opts = $this->serializer->deserialize(
            (string)$stash['options'],
            PublicKeyCredentialRequestOptions::class,
            'json',
        );
        $publicKeyCredential = $this->serializer->deserialize($credentialJson, PublicKeyCredential::class, 'json');

        $assertionResponse = $publicKeyCredential->response;
        if (!$assertionResponse instanceof AuthenticatorAssertionResponse) {
            throw new WebAuthnException('Expected assertion response.');
        }

        $passkey = $this->passkeys()
            ->find()
            ->where([
                'Passkeys.credential_id' => $publicKeyCredential->rawId,
                'Passkeys.user_id' => $user->getUserId(),
            ])
            ->first();
        if ($passkey === null) {
            throw new WebAuthnException('Unknown passkey credential for this user.');
        }

        $source = $this->passkeyToSource($passkey);

        $updated = $this->assertionValidator->check(
            $source,
            $assertionResponse,
            $opts,
            $this->rpId,
            null,
        );

        $newCounter = (int)$updated->counter;
        $oldCounter = (int)$passkey->sign_count;
        if ($newCounter !== 0 && $newCounter <= $oldCounter) {
            throw new WebAuthnException('Sign-count rollback detected; refusing authentication.');
        }

        $passkey->set('sign_count', $newCounter);
        $passkey->set('last_used_at', DateTime::now());
        $this->passkeys()->saveOrFail($passkey);

        return true;
    }

    /**
     * @return \Webauthn\PublicKeyCredentialRpEntity
     */
    private function rpEntity(): PublicKeyCredentialRpEntity
    {
        return PublicKeyCredentialRpEntity::create($this->rpName, $this->rpId);
    }

    /**
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     *
     * @return \Webauthn\PublicKeyCredentialUserEntity
     */
    private function userEntity(PasskeyUserInterface $user): PublicKeyCredentialUserEntity
    {
        return PublicKeyCredentialUserEntity::create(
            $user->getPasskeyDisplayEmail(),
            $user->getPasskeyUserHandle(),
            $user->getPasskeyDisplayName(),
        );
    }

    /**
     * Default COSE algorithm selection: ES256 (most compatible) + RS256
     * (Windows Hello fallback) + EdDSA (modern).
     *
     * @return list<\Webauthn\PublicKeyCredentialParameters>
     */
    private function defaultPubKeyCredParams(): array
    {
        return [
            PublicKeyCredentialParameters::create('public-key', -8), // EdDSA
            PublicKeyCredentialParameters::create('public-key', -7), // ES256
            PublicKeyCredentialParameters::create('public-key', -257), // RS256
        ];
    }

    /**
     * Convert PublicKeyCredentialCreationOptions / RequestOptions to a JSON-
     * serializable array the browser can pass to navigator.credentials.*.
     * The serializer emits binary fields as base64url strings, which is the
     * format JS expects (no further conversion needed).
     *
     * @param object $opts
     *
     * @return array<string, mixed>
     */
    private function serializeForBrowser(object $opts): array
    {
        $json = $this->serializer->serialize($opts, 'json', [
            'json_encode_options' => JSON_UNESCAPED_SLASHES,
        ]);
        $decoded = json_decode($json, true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Normalize whatever shape the browser handed us into the JSON string
     * the library's denormalizer expects.
     *
     * @param mixed $response
     *
     * @throws \Passkeys\Service\WebAuthnException
     *
     * @return string
     */
    private function encodeBrowserResponse(mixed $response): string
    {
        if (is_string($response)) {
            return $response;
        }
        if (is_array($response)) {
            $json = json_encode($response, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new WebAuthnException('Could not re-encode browser response.');
            }

            return $json;
        }

        throw new WebAuthnException('Unexpected browser response shape.');
    }

    /**
     * @param \Passkeys\Model\Entity\Passkey $passkey
     *
     * @return \Webauthn\CredentialRecord
     */
    private function passkeyToSource(Passkey $passkey): CredentialRecord
    {
        return CredentialRecord::create(
            (string)$passkey->credential_id,
            PublicKeyCredentialDescriptor::CREDENTIAL_TYPE_PUBLIC_KEY,
            $this->splitTransports((string)($passkey->transports ?? '')),
            'none',
            EmptyTrustPath::create(),
            // AAGUID is not consulted during assertion check; a fresh UUID is fine.
            Uuid::v4(),
            (string)$passkey->public_key,
            (string)$passkey->user_id,
            (int)$passkey->sign_count,
        );
    }

    /**
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     *
     * @return list<\Passkeys\Model\Entity\Passkey>
     */
    private function passkeysFor(PasskeyUserInterface $user): array
    {
        /** @var list<\Passkeys\Model\Entity\Passkey> $rows */
        $rows = $this->passkeys()
            ->find()
            ->where(['Passkeys.user_id' => $user->getUserId()])
            ->all()
            ->toList();

        return $rows;
    }

    /**
     * @param list<string> $transports
     *
     * @return string|null
     */
    private function joinTransports(array $transports): ?string
    {
        if ($transports === []) {
            return null;
        }
        $allowed = ['usb', 'nfc', 'ble', 'internal', 'hybrid'];
        $clean = array_values(array_intersect($allowed, array_map('strtolower', $transports)));

        return $clean !== [] ? implode(',', $clean) : null;
    }

    /**
     * @param string $joined
     *
     * @return list<string>
     */
    private function splitTransports(string $joined): array
    {
        if ($joined === '') {
            return [];
        }

        return array_values(array_filter(explode(',', $joined)));
    }

    /**
     * @param string $raw
     *
     * @return string
     */
    private function normalizeName(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return 'Passkey';
        }

        return mb_substr($trimmed, 0, 80);
    }

    /**
     * @param string|null $raw
     *
     * @return string|null
     */
    private function normalizeEmoji(?string $raw): ?string
    {
        if ($raw === null) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return null;
        }

        return mb_substr($trimmed, 0, 8);
    }

    /**
     * @param string $uuid
     *
     * @return string
     */
    private function uuidToBytes(string $uuid): string
    {
        $bytes = hex2bin(str_replace('-', '', $uuid));

        return $bytes !== false ? $bytes : '';
    }

    /**
     * @return \Passkeys\Model\Table\PasskeysTable
     */
    private function passkeys(): PasskeysTable
    {
        /** @var \Passkeys\Model\Table\PasskeysTable $table */
        $table = $this->fetchTable('Passkeys.Passkeys');

        return $table;
    }

    /**
     * Enforce the configured passkey cap server-side as well as in the UI.
     *
     * @param \Passkeys\Contract\PasskeyUserInterface $user
     *
     * @throws \Passkeys\Service\WebAuthnException
     *
     * @return void
     */
    private function assertUnderPasskeyCap(PasskeyUserInterface $user): void
    {
        $maxPerUser = (int)Configure::read('Passkeys.maxPerUser', 5);
        if ($maxPerUser <= 0) {
            return;
        }

        $count = $this->passkeys()->find()
            ->where(['Passkeys.user_id' => $user->getUserId()])
            ->count();
        if ($count >= $maxPerUser) {
            throw new WebAuthnException('Too many passkeys for this user');
        }
    }
}
