<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Model\Entity\Passkey;
use CakePasskeys\Service\AaguidLabelResolver;
use CakePasskeys\Service\ChallengeStore;
use CakePasskeys\Service\ConventionUserAdapter;
use CakePasskeys\Service\UnknownCredentialException;
use CakePasskeys\Service\UserResolver;
use CakePasskeys\Service\WebAuthnException;
use CakePasskeys\Service\WebAuthnService;
use CakePasskeys\Test\SoftAuthenticator;
use Migrations\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;

class WebAuthnServiceTest extends TestCase
{
    /**
     * @var string
     */
    private const YUBIKEY_5 = 'cb69481e-8ff7-4039-93ec-0a2729a154a8';

    private WebAuthnService $service;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        // ChallengeStore writes to a dedicated `passkeys_challenges` engine
        // (registered by the plugin's bootstrap with the configured TTL);
        // mirror that here so service tests don't have to boot the plugin.
        Cache::drop('passkeys_challenges');
        Cache::setConfig('passkeys_challenges', ['className' => 'Array', 'duration' => 300]);
        Configure::write('CakePasskeys.rpId', 'localhost');
        Configure::write('CakePasskeys.rpName', 'Test');
        Configure::write('CakePasskeys.maxPerUser', 5);
        Configure::write('CakePasskeys.challengeTtl', 300);
        Configure::write('CakePasskeys.cache', 'default');
        Configure::write('CakePasskeys.allowedAaguids', null);
        Configure::write('CakePasskeys.ceremony', [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ]);

        Configure::write('CakePasskeys.users', [
            'table' => 'Users',
            'columns' => ['id' => 'id', 'email' => 'email', 'displayName' => 'name'],
            'activeColumn' => null,
        ]);
        $connection = $this->connection();
        $connection->execute('DROP TABLE IF EXISTS users');
        $connection->execute(
            'CREATE TABLE users (id INTEGER PRIMARY KEY, email VARCHAR(255), name VARCHAR(255), is_active INTEGER DEFAULT 1)',
        );
        foreach ([1, 2] as $id) {
            $connection->insert('users', ['id' => $id, 'email' => "user{$id}@example.com", 'name' => "User{$id}", 'is_active' => 1]);
        }

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();
        $this->getTableLocator()->clear();

        $this->service = new WebAuthnService(
            new ChallengeStore(),
            new UserResolver(),
            new AaguidLabelResolver(),
        );
    }

    protected function tearDown(): void
    {
        $this->getTableLocator()->clear();
        Cache::drop('passkeys_challenges');
        parent::tearDown();
    }

    public function testStartRegistrationReturnsOptions(): void
    {
        $user = $this->makeUser(1);
        $opts = $this->service->startRegistration($user);
        $this->assertArrayHasKey('challenge', $opts);
        $this->assertArrayHasKey('challengeKey', $opts);
        $this->assertSame('localhost', $opts['rp']['id']);
        $this->assertSame('required', $opts['authenticatorSelection']['userVerification']);
    }

    public function testStartLoginReturnsOptionsWithChallengeKey(): void
    {
        $opts = $this->service->startLogin();
        $this->assertArrayHasKey('challenge', $opts);
        $this->assertArrayHasKey('challengeKey', $opts);
    }

    public function testFinishRegistrationRejectsConsumedChallenge(): void
    {
        $this->expectException(WebAuthnException::class);
        $this->service->finishRegistration(
            $this->makeUser(1),
            ['response' => [], 'challengeKey' => 'nonexistent-key'],
            'My laptop',
        );
    }

    public function testRegistrationPersistsPasskey(): void
    {
        $authenticator = new SoftAuthenticator();

        $passkey = $this->register($this->makeUser(1), $authenticator);

        $this->assertSame(1, $passkey->user_id);
        $this->assertSame($authenticator->credentialId(), $passkey->credential_id);
        $this->assertSame('Laptop', $passkey->name);
        $this->assertSame('internal', $passkey->transports);
    }

    /**
     * A discoverable credential returns the user handle it was registered
     * with; one that is not discoverable returns none. Both have to log in.
     *
     * @param bool $withUserHandle
     *
     * @return void
     */
    #[DataProvider('userHandleModes')]
    public function testLoginSucceeds(bool $withUserHandle): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);

        $options = $this->service->startLogin();
        $passkey = $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options, $withUserHandle),
        ]);

        $this->assertSame(1, $passkey->user_id);
        $this->assertNotNull($passkey->last_used_at);
    }

    /**
     * An app that registered passkeys before adopting the plugin keeps its own
     * user handle through `users.userHandle`; the authenticator returns that
     * handle at sign-in and the check has to accept it.
     *
     * @return void
     */
    public function testLoginAcceptsCredentialRegisteredWithCustomUserHandle(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        // The credential was created by the app's earlier code, which used
        // the packed id as WebAuthn user handle.
        (fn () => $this->userHandle = pack('J', 1))->call($authenticator);

        Configure::write('CakePasskeys.users.userHandle', fn (EntityInterface $user): string => pack('J', $user->get('id')));
        try {
            $options = $this->service->startLogin();
            $passkey = $this->service->finishLogin([
                'challengeKey' => $options['challengeKey'],
                'response' => $authenticator->authenticate($options, true),
            ]);

            $this->assertSame(1, $passkey->user_id);
        } finally {
            Configure::delete('CakePasskeys.users.userHandle');
        }
    }

    /**
     * @return array<string, array<bool>>
     */
    public static function userHandleModes(): array
    {
        return ['discoverable credential' => [true], 'credential without user handle' => [false]];
    }

    public function testReauthSucceeds(): void
    {
        $user = $this->makeUser(1);
        $authenticator = new SoftAuthenticator();
        $this->register($user, $authenticator);

        $options = $this->service->startReauth($user);

        $this->assertTrue($this->service->finishReauth($user, [
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]));
    }

    /**
     * @return array<string, array{bool, bool}>
     */
    public static function backupFlags(): array
    {
        return [
            'device-bound' => [false, false],
            'eligible, not synced' => [true, false],
            'synced' => [true, true],
        ];
    }

    #[DataProvider('backupFlags')]
    public function testRegistrationStoresBackupFlags(bool $eligible, bool $backedUp): void
    {
        $authenticator = new SoftAuthenticator(backupEligible: $eligible, backedUp: $backedUp);

        $this->register($this->makeUser(1), $authenticator);

        /** @var \CakePasskeys\Model\Entity\Passkey $stored */
        $stored = $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()->firstOrFail();
        $this->assertSame($eligible, $stored->backup_eligible);
        $this->assertSame($backedUp, $stored->backed_up);
    }

    public function testLoginUpdatesBackupState(): void
    {
        $authenticator = new SoftAuthenticator(backupEligible: true);
        $this->register($this->makeUser(1), $authenticator);

        $authenticator->backedUp = true;
        $options = $this->service->startLogin();
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);

        /** @var \CakePasskeys\Model\Entity\Passkey $stored */
        $stored = $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()->firstOrFail();
        $this->assertTrue($stored->backup_eligible);
        $this->assertTrue($stored->backed_up);
    }

    public function testLoginFillsUnknownBackupEligibility(): void
    {
        $authenticator = new SoftAuthenticator(backupEligible: true, backedUp: true);
        $this->register($this->makeUser(1), $authenticator);
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $table->updateAll(['backup_eligible' => null, 'backed_up' => null], []);

        $options = $this->service->startLogin();
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);

        /** @var \CakePasskeys\Model\Entity\Passkey $stored */
        $stored = $table->find()->firstOrFail();
        $this->assertTrue($stored->backup_eligible);
        $this->assertTrue($stored->backed_up);
    }

    public function testReauthUpdatesBackupState(): void
    {
        $user = $this->makeUser(1);
        $authenticator = new SoftAuthenticator(backupEligible: true, backedUp: true);
        $this->register($user, $authenticator);

        $authenticator->backedUp = false;
        $options = $this->service->startReauth($user);
        $this->service->finishReauth($user, [
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);

        /** @var \CakePasskeys\Model\Entity\Passkey $stored */
        $stored = $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()->firstOrFail();
        $this->assertTrue($stored->backup_eligible);
        $this->assertFalse($stored->backed_up);
    }

    public function testLoginRejectsChangedBackupEligibility(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);

        $authenticator->backupEligible = true;
        $options = $this->service->startLogin();

        $this->expectException(WebAuthnException::class);
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);
    }

    public function testLoginRejectsPasskeyOfAnotherUserOnReauth(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        $other = $this->makeUser(2);

        $options = $this->service->startReauth($other);

        $this->expectException(WebAuthnException::class);
        $this->service->finishReauth($other, [
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);
    }

    /**
     * A passkey outlives its account. Once the user is deactivated or gone,
     * the credential must stop working.
     *
     * @param string $sql Statement that takes the account away
     *
     * @return void
     */
    #[DataProvider('removedAccounts')]
    public function testLoginRejectsIneligibleUser(string $sql): void
    {
        Configure::write('CakePasskeys.users.activeColumn', 'is_active');
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        $this->connection()->execute($sql);

        $options = $this->service->startLogin();

        $this->expectException(WebAuthnException::class);
        $this->expectExceptionMessage('Unknown passkey credential.');
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function removedAccounts(): array
    {
        return [
            'deactivated' => ['UPDATE users SET is_active = 0 WHERE id = 1'],
            'deleted' => ['DELETE FROM users WHERE id = 1'],
        ];
    }

    /**
     * Only a credential the server no longer stores is reported as unknown.
     * A passkey whose account is gone or deactivated is still on record.
     *
     * @param string $sql
     * @param bool $unknown
     *
     * @return void
     */
    #[DataProvider('unknownCredentialCases')]
    public function testLoginFlagsOnlyCredentialsTheServerNoLongerKnows(string $sql, bool $unknown): void
    {
        Configure::write('CakePasskeys.users.activeColumn', 'is_active');
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        $this->connection()->execute($sql);

        $options = $this->service->startLogin();

        try {
            $this->service->finishLogin([
                'challengeKey' => $options['challengeKey'],
                'response' => $authenticator->authenticate($options),
            ]);
            $this->fail('Login must fail.');
        } catch (WebAuthnException $e) {
            $this->assertSame($unknown, $e instanceof UnknownCredentialException);
            $this->assertSame('Unknown passkey credential.', $e->getMessage());
        }
    }

    /**
     * @return array<string, array{string, bool}>
     */
    public static function unknownCredentialCases(): array
    {
        return [
            'passkey deleted' => ['DELETE FROM passkeys', true],
            'account deleted' => ['DELETE FROM users WHERE id = 1', false],
            'account deactivated' => ['UPDATE users SET is_active = 0 WHERE id = 1', false],
        ];
    }

    /**
     * Same credential and a valid signature, reported from another site: what
     * a phishing page relaying the ceremony would produce.
     *
     * @return void
     */
    public function testLoginRejectsAssertionFromAnotherOrigin(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        $authenticator->origin = 'https://evil.example';

        $options = $this->service->startLogin();

        try {
            $this->service->finishLogin([
                'challengeKey' => $options['challengeKey'],
                'response' => $authenticator->authenticate($options),
            ]);
            $this->fail('An assertion from another origin was accepted.');
        } catch (WebAuthnException $e) {
            $this->assertStringContainsString('rpId mismatch', (string)$e->getPrevious()?->getMessage());
        }
    }

    /**
     * Malformed input and failed library checks are client errors, reported
     * with the plugin's own exception type.
     *
     * @return void
     */
    public function testLoginReportsMalformedResponseAsWebAuthnException(): void
    {
        $options = $this->service->startLogin();

        $this->expectException(WebAuthnException::class);
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => ['id' => '!!', 'rawId' => '!!', 'type' => 'public-key', 'response' => ['clientDataJSON' => '!!']],
        ]);
    }

    public function testChallengeIsSingleUse(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        $options = $this->service->startLogin();
        $response = ['challengeKey' => $options['challengeKey'], 'response' => $authenticator->authenticate($options)];
        $this->service->finishLogin($response);

        $this->expectException(WebAuthnException::class);
        $this->service->finishLogin($response);
    }

    public function testMaxPerUserEnforced(): void
    {
        Configure::write('CakePasskeys.maxPerUser', 1);
        $user = $this->makeUser(1);
        $this->register($user, new SoftAuthenticator());

        $this->expectException(WebAuthnException::class);
        $this->service->startRegistration($user);
    }

    public function testCounterRollbackRejected(): void
    {
        $authenticator = new SoftAuthenticator(counter: 5);
        $this->register($this->makeUser(1), $authenticator);
        $authenticator->counter = 6;
        $options = $this->service->startLogin();
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);

        // A cloned authenticator replays an older counter.
        $authenticator->counter = 6;
        $options = $this->service->startLogin();

        $this->expectException(WebAuthnException::class);
        $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);
    }

    /**
     * @return void
     */
    public function testRegistrationAcceptsAllowedAaguidCaseInsensitively(): void
    {
        Configure::write('CakePasskeys.allowedAaguids', ['CB69481E-8FF7-4039-93EC-0A2729A154A8']);

        $passkey = $this->register($this->makeUser(1), new SoftAuthenticator(aaguid: self::YUBIKEY_5));

        $this->assertSame('YubiKey 5', $passkey->aaguid_label);
    }

    /**
     * @param string $aaguid
     *
     * @return void
     */
    #[DataProvider('provideRefusedAaguids')]
    public function testRegistrationRefusesAaguidOutsideAllowlist(string $aaguid): void
    {
        Configure::write('CakePasskeys.allowedAaguids', [self::YUBIKEY_5]);

        try {
            $this->register($this->makeUser(1), new SoftAuthenticator(aaguid: $aaguid));
            $this->fail('The registration was not refused.');
        } catch (WebAuthnException $e) {
            $this->assertSame('This type of passkey is not allowed here.', $e->getMessage());
        }
        $this->assertSame(0, $this->getTableLocator()->get('CakePasskeys.Passkeys')->find()->count());
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function provideRefusedAaguids(): array
    {
        return [
            'other model' => ['adce0002-35bc-c60a-648b-0b25f1f05503'],
            'zero aaguid' => ['00000000-0000-0000-0000-000000000000'],
        ];
    }

    /**
     * @return void
     */
    public function testAllowlistGivenAsStringIsOneEntry(): void
    {
        Configure::write('CakePasskeys.allowedAaguids', self::YUBIKEY_5);

        $this->expectException(WebAuthnException::class);
        $this->register($this->makeUser(1), new SoftAuthenticator(aaguid: 'adce0002-35bc-c60a-648b-0b25f1f05503'));
    }

    /**
     * @return void
     */
    public function testInvalidAllowlistFailsClosed(): void
    {
        Configure::write('CakePasskeys.allowedAaguids', true);

        $this->expectException(WebAuthnException::class);
        $this->register($this->makeUser(1), new SoftAuthenticator(aaguid: self::YUBIKEY_5));
    }

    /**
     * @return void
     */
    public function testEmptyAllowlistAcceptsAnyAuthenticator(): void
    {
        Configure::write('CakePasskeys.allowedAaguids', []);

        $passkey = $this->register($this->makeUser(1), new SoftAuthenticator());

        $this->assertNull($passkey->aaguid);
    }

    /**
     * The list only applies to new registrations; passkeys stored before
     * keep signing in.
     *
     * @return void
     */
    public function testAllowlistDoesNotAffectExistingPasskeys(): void
    {
        $authenticator = new SoftAuthenticator();
        $this->register($this->makeUser(1), $authenticator);
        Configure::write('CakePasskeys.allowedAaguids', [self::YUBIKEY_5]);

        $options = $this->service->startLogin();
        $passkey = $this->service->finishLogin([
            'challengeKey' => $options['challengeKey'],
            'response' => $authenticator->authenticate($options),
        ]);

        $this->assertSame(1, $passkey->user_id);
    }

    /**
     * @param \CakePasskeys\Contract\PasskeyUserInterface $user
     * @param \CakePasskeys\Test\SoftAuthenticator $authenticator
     *
     * @return \CakePasskeys\Model\Entity\Passkey
     */
    private function register(PasskeyUserInterface $user, SoftAuthenticator $authenticator): Passkey
    {
        $options = $this->service->startRegistration($user);

        return $this->service->finishRegistration(
            $user,
            ['challengeKey' => $options['challengeKey'], 'response' => $authenticator->register($options)],
            'Laptop',
        );
    }

    /**
     * @return \Cake\Database\Connection
     */
    private function connection(): Connection
    {
        $connection = ConnectionManager::get('test');
        assert($connection instanceof Connection);

        return $connection;
    }

    /**
     * @param int $id
     *
     * @return \CakePasskeys\Contract\PasskeyUserInterface
     */
    private function makeUser(int $id): PasskeyUserInterface
    {
        $entity = new Entity([
            'id' => $id,
            'email' => "user{$id}@example.com",
            'name' => "User{$id}",
        ]);

        return new ConventionUserAdapter($entity);
    }
}
