<?php

declare(strict_types=1);

namespace CakePasskeys\Test;

use CBOR\ByteStringObject;
use CBOR\MapItem;
use CBOR\MapObject;
use CBOR\NegativeIntegerObject;
use CBOR\TextStringObject;
use CBOR\UnsignedIntegerObject;
use OpenSSLAsymmetricKey;
use RuntimeException;

/**
 * A software WebAuthn authenticator for tests.
 *
 * Produces the JSON a browser sends after `navigator.credentials.create()`
 * and `.get()`: an ES256 credential with attestation format `none`. It lets
 * the tests run the real ceremony code against real signatures.
 */
class SoftAuthenticator
{
    /**
     * @var int
     */
    private const FLAG_USER_PRESENT = 0x01;

    /**
     * @var int
     */
    private const FLAG_USER_VERIFIED = 0x04;

    /**
     * @var int
     */
    private const FLAG_ATTESTED_DATA = 0x40;

    private OpenSSLAsymmetricKey $key;

    private string $credentialId;

    private string $userHandle = '';

    /**
     * @param string $rpId Relying party id the credential is scoped to
     * @param string $origin Origin reported in the client data
     * @param int $counter Signature counter; 0 behaves like a synced passkey
     *
     * @throws \RuntimeException
     */
    public function __construct(
        private string $rpId = 'localhost',
        private string $origin = 'https://localhost',
        public int $counter = 0,
    ) {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        if ($key === false) {
            throw new RuntimeException('Could not create an EC key.');
        }
        $this->key = $key;
        $this->credentialId = random_bytes(32);
    }

    /**
     * @return string Raw credential id
     */
    public function credentialId(): string
    {
        return $this->credentialId;
    }

    /**
     * Answers creation options as returned by `startRegistration()`.
     *
     * @param array<string, mixed> $options Creation options
     *
     * @return array<string, mixed> The `PublicKeyCredential` JSON
     */
    public function register(array $options): array
    {
        $this->userHandle = $this->decode((string)$options['user']['id']);

        $authData = $this->authenticatorData(self::FLAG_ATTESTED_DATA)
            . str_repeat("\0", 16)
            . pack('n', strlen($this->credentialId))
            . $this->credentialId
            . (string)$this->coseKey();

        $attestationObject = MapObject::create([
            MapItem::create(TextStringObject::create('fmt'), TextStringObject::create('none')),
            MapItem::create(TextStringObject::create('attStmt'), MapObject::create()),
            MapItem::create(TextStringObject::create('authData'), ByteStringObject::create($authData)),
        ]);

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($this->clientData('webauthn.create', (string)$options['challenge'])),
                'attestationObject' => $this->encode((string)$attestationObject),
                'transports' => ['internal'],
            ],
        ];
    }

    /**
     * Answers request options as returned by `startLogin()` or `startReauth()`.
     *
     * @param array<string, mixed> $options Request options
     * @param bool $withUserHandle False behaves like a non-discoverable credential
     *
     * @throws \RuntimeException
     *
     * @return array<string, mixed> The `PublicKeyCredential` JSON
     */
    public function authenticate(array $options, bool $withUserHandle = true): array
    {
        $authData = $this->authenticatorData(0);
        $clientData = $this->clientData('webauthn.get', (string)$options['challenge']);

        $signature = '';
        if (!openssl_sign($authData . hash('sha256', $clientData, true), $signature, $this->key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the assertion.');
        }

        return [
            'id' => $this->encode($this->credentialId),
            'rawId' => $this->encode($this->credentialId),
            'type' => 'public-key',
            'response' => [
                'clientDataJSON' => $this->encode($clientData),
                'authenticatorData' => $this->encode($authData),
                'signature' => $this->encode($signature),
                'userHandle' => $withUserHandle ? $this->encode($this->userHandle) : null,
            ],
        ];
    }

    /**
     * @param int $extraFlags Flags on top of user present and user verified
     *
     * @return string
     */
    private function authenticatorData(int $extraFlags): string
    {
        return hash('sha256', $this->rpId, true)
            . chr((self::FLAG_USER_PRESENT | self::FLAG_USER_VERIFIED | $extraFlags) & 0xFF)
            . pack('N', $this->counter);
    }

    /**
     * @param string $type Client data type
     * @param string $challenge Challenge as the options carried it
     *
     * @return string
     */
    private function clientData(string $type, string $challenge): string
    {
        return (string)json_encode([
            'type' => $type,
            'challenge' => $challenge,
            'origin' => $this->origin,
            'crossOrigin' => false,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @throws \RuntimeException
     *
     * @return \CBOR\MapObject The public key as a COSE EC2 key
     */
    private function coseKey(): MapObject
    {
        $details = openssl_pkey_get_details($this->key);
        if ($details === false) {
            throw new RuntimeException('Could not read the EC key.');
        }

        return MapObject::create([
            MapItem::create(UnsignedIntegerObject::create(1), UnsignedIntegerObject::create(2)),
            MapItem::create(UnsignedIntegerObject::create(3), NegativeIntegerObject::create(-7)),
            MapItem::create(NegativeIntegerObject::create(-1), UnsignedIntegerObject::create(1)),
            MapItem::create(
                NegativeIntegerObject::create(-2),
                ByteStringObject::create(str_pad($details['ec']['x'], 32, "\0", STR_PAD_LEFT)),
            ),
            MapItem::create(
                NegativeIntegerObject::create(-3),
                ByteStringObject::create(str_pad($details['ec']['y'], 32, "\0", STR_PAD_LEFT)),
            ),
        ]);
    }

    /**
     * @param string $bytes Raw bytes
     *
     * @return string Base64url without padding
     */
    private function encode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * @param string $value Base64url
     *
     * @return string Raw bytes
     */
    private function decode(string $value): string
    {
        return (string)base64_decode(strtr($value, '-_', '+/'), true);
    }
}
