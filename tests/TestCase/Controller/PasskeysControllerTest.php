<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Controller;

use Authentication\AuthenticationService;
use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Datasource\EntityInterface;
use Cake\Event\Event;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\Log\Log;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use CakePasskeys\Contract\RateLimiterInterface;
use CakePasskeys\Controller\PasskeysController;
use CakePasskeys\Event\PasskeyEvent;
use CakePasskeys\Service\Signals;
use CakePasskeys\Service\UnknownCredentialException;
use CakePasskeys\Service\UserResolver;
use CakePasskeys\Service\WebAuthnException;
use CakePasskeys\Service\WebAuthnService;
use CakePasskeys\Test\AuthenticationServiceSpy;
use Migrations\Migrations;
use PHPUnit\Framework\Attributes\DataProvider;

class PasskeysControllerTest extends TestCase
{
    use EmailTrait;
    use IntegrationTestTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
        // The plugin bootstrap registers this dedicated challenge cache; in
        // integration tests we don't always boot the plugin, so register a
        // matching Array engine here for ChallengeStore to write into.
        Cache::drop('passkeys_challenges');
        Cache::setConfig('passkeys_challenges', ['className' => 'Array', 'duration' => 300]);
        // Reset the master switch — one test toggles it false, others rely
        // on the default `enabled => true`.
        Configure::write('CakePasskeys.enabled', true);
        Configure::write('CakePasskeys.rpId', 'localhost');
        Configure::write('CakePasskeys.rpName', 'Test');
        Configure::write('CakePasskeys.maxPerUser', 5);
        Configure::write('CakePasskeys.challengeTtl', 300);
        Configure::write('CakePasskeys.cache', 'default');
        Configure::write('CakePasskeys.login.emailHint', false);
        Configure::write('CakePasskeys.identityResolver', null);
        // Reset the session-key override — one test overrides this to a
        // non-default path, others rely on the default `Auth.id`.
        Configure::write('CakePasskeys.session.userIdKey', 'Auth.id');
        Configure::write('CakePasskeys.session.persistIdentity', true);
        Configure::write('CakePasskeys.rateLimiter', null);
        Configure::write('CakePasskeys.wellKnown', ['enroll' => null, 'manage' => null]);
        Configure::write('CakePasskeys.notify', ['onRegister' => false, 'emailProfile' => 'default', 'manageUrl' => null]);
        Configure::write('CakePasskeys.signals', true);
        Configure::write('CakePasskeys.secondFactor', ['pendingUserKey' => null, 'pendingUserResolver' => null, 'redirect' => null]);
        Configure::write('CakePasskeys.afterLoginRedirect', '/');
        Configure::write('CakePasskeys.ceremony', [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ]);

        /** @var \Cake\Database\Connection $conn */
        $conn = ConnectionManager::get('test');
        assert($conn instanceof Connection);
        $conn->execute('DROP TABLE IF EXISTS users');
        $conn->execute(
            'CREATE TABLE users ('
            . 'id INTEGER PRIMARY KEY, '
            . 'email VARCHAR(255), '
            . 'name VARCHAR(255), '
            . 'is_active INTEGER DEFAULT 1'
            . ')',
        );
        $conn->insert('users', [
            'id' => 1,
            'email' => 'alice@example.com',
            'name' => 'Alice',
            'is_active' => 1,
        ]);
        $conn->insert('users', [
            'id' => 2,
            'email' => 'bob@example.com',
            'name' => 'Bob',
            'is_active' => 1,
        ]);

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();

        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->getTableLocator()->clear();
    }

    /**
     * @return void
     */
    protected function tearDown(): void
    {
        $this->getTableLocator()->clear();
        parent::tearDown();
    }

    /**
     * @return void
     */
    public function testRegisterStartRequiresAuth(): void
    {
        $this->post('/passkeys/register/start');
        $this->assertResponseCode(401);
    }

    /**
     * Master-switch: when `CakePasskeys.enabled` is false, EVERY controller
     * action must 404 — not just login/start. Hiding the UI is not
     * enough; a live JSON API can still be probed or abused.
     *
     * `beforeFilter()` raises NotFoundException before `allowMethod()`
     * runs, so any HTTP verb should hit the 404 first; we still use the
     * route's documented verb for realism.
     *
     * @param string $verb
     * @param string $url
     *
     * @return void
     */
    #[DataProvider('provideEndpoints')]
    public function testEndpointsReturn404WhenDisabled(string $verb, string $url): void
    {
        Configure::write('CakePasskeys.enabled', false);
        match ($verb) {
            'post' => $this->post($url),
            'delete' => $this->delete($url),
            default => $this->fail("Unhandled verb: {$verb}"),
        };
        $this->assertResponseCode(404);
    }

    /**
     * Each of the 8 plugin endpoints with its documented verb. `rename`
     * and `delete` carry an `{id}` path segment — the value is irrelevant
     * because beforeFilter() short-circuits before the action body runs.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function provideEndpoints(): array
    {
        return [
            'registerStart' => ['post', '/passkeys/register/start'],
            'registerFinish' => ['post', '/passkeys/register/finish'],
            'loginStart' => ['post', '/passkeys/login/start'],
            'loginFinish' => ['post', '/passkeys/login/finish'],
            'reauthStart' => ['post', '/passkeys/reauth/start'],
            'reauthFinish' => ['post', '/passkeys/reauth/finish'],
            'secondFactorStart' => ['post', '/passkeys/second-factor/start'],
            'secondFactorFinish' => ['post', '/passkeys/second-factor/finish'],
            'rename' => ['post', '/passkeys/rename/1'],
            'delete' => ['delete', '/passkeys/delete/1'],
        ];
    }

    /**
     * @return void
     */
    public function testLoginStartAnonymousReturnsOptions(): void
    {
        $this->post('/passkeys/login/start');
        $this->assertResponseOk();
        $body = $this->decodeJsonBody();
        $this->assertArrayHasKey('challenge', $body);
        $this->assertArrayHasKey('challengeKey', $body);
    }

    /**
     * @return void
     */
    public function testRegisterStartReturnsOptionsForAuthenticatedUser(): void
    {
        $this->loginAs(1);
        $this->post('/passkeys/register/start');
        $this->assertResponseOk();
        $body = $this->decodeJsonBody();
        $this->assertArrayHasKey('challenge', $body);
        $this->assertArrayHasKey('challengeKey', $body);
    }

    /**
     * A host stops `beforeRegister` (say, the sign-in is not recent); both
     * register endpoints then refuse with 403 and the listener's message.
     *
     * @return void
     */
    public function testBeforeRegisterListenerCanRefuseRegistration(): void
    {
        $listener = function (EventInterface $event): void {
            $event->stopPropagation();
            $event->setResult('Sign in again first.');
        };
        EventManager::instance()->on('CakePasskeys.beforeRegister', $listener);
        try {
            $this->loginAs(1);
            $this->post('/passkeys/register/start');
            $this->assertResponseCode(403);
            $this->assertResponseContains('Sign in again first.');
        } finally {
            EventManager::instance()->off('CakePasskeys.beforeRegister', $listener);
        }
    }

    /**
     * A host stops `beforeDelete` (say, the sign-in is not recent): 403, and
     * the passkey stays.
     *
     * @return void
     */
    public function testBeforeDeleteListenerCanRefuseDeletion(): void
    {
        $id = $this->seedPasskey(1, 'Keep me');
        $seen = null;
        $listener = function (EventInterface $event) use (&$seen): void {
            $seen = $event->getData('passkey')->name;
            $event->stopPropagation();
            $event->setResult('Sign in again first.');
        };
        EventManager::instance()->on('CakePasskeys.beforeDelete', $listener);
        try {
            $this->loginAs(1);
            $this->post('/passkeys/delete/' . $id);
        } finally {
            EventManager::instance()->off('CakePasskeys.beforeDelete', $listener);
        }

        $this->assertResponseCode(403);
        $this->assertResponseContains('Sign in again first.');
        $this->assertSame('Keep me', $seen);
        $this->assertTrue($this->getTableLocator()->get('CakePasskeys.Passkeys')->exists(['id' => $id]));
    }

    /**
     * A full bucket answers 429, on the finish steps as well as on start.
     *
     * @param string $url
     *
     * @return void
     */
    #[DataProvider('throttledEndpoints')]
    public function testThrottledEndpointAnswers429(string $url): void
    {
        Configure::write('CakePasskeys.rateLimiter', new class implements RateLimiterInterface {
            public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                return false;
            }
        });
        $this->loginAs(1);
        $this->post($url);
        $this->assertResponseCode(429);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function throttledEndpoints(): array
    {
        return [
            'login start' => ['/passkeys/login/start'],
            'login finish' => ['/passkeys/login/finish'],
            'register start' => ['/passkeys/register/start'],
            'register finish' => ['/passkeys/register/finish'],
            'reauth finish' => ['/passkeys/reauth/finish'],
            'rename' => ['/passkeys/rename/1'],
            'delete' => ['/passkeys/delete/1'],
        ];
    }

    /**
     * @return void
     */
    public function testRateLimiterFalseTurnsLimitingOff(): void
    {
        Configure::write('CakePasskeys.rateLimiter', false);
        Cache::drop('passkeys_rl_off');
        Cache::setConfig('passkeys_rl_off', ['className' => 'Array']);
        Configure::write('CakePasskeys.cache', 'passkeys_rl_off');
        for ($i = 0; $i < 25; $i++) {
            $this->post('/passkeys/login/start');
            $this->assertResponseOk();
        }
    }

    /**
     * @return void
     */
    public function testRenameUpdatesNameAndFiresEvent(): void
    {
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Old name');

        /** @var array<string, mixed>|null $fired */
        $fired = null;
        EventManager::instance()->on(
            'CakePasskeys.afterRename',
            function ($event) use (&$fired): void {
                $fired = $event->getData();
            },
        );

        $this->post("/passkeys/rename/{$id}", ['name' => 'New laptop']);
        $this->assertResponseOk();

        /** @var \CakePasskeys\Model\Entity\Passkey $row */
        $row = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($id);
        $this->assertSame('New laptop', $row->name);
        $this->assertNotNull($fired);
        $this->assertInstanceOf(PasskeyEvent::class, $fired['event']);
        $this->assertSame('Old name', $fired['event']->getData()['old']);
        $this->assertSame('New laptop', $fired['event']->getData()['new']);
    }

    /**
     * @return void
     */
    public function testRenameResponseCarriesBackupFlags(): void
    {
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Phone');
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $table->updateAll(['backup_eligible' => true, 'backed_up' => false], ['id' => $id]);

        $this->post("/passkeys/rename/{$id}", ['name' => 'My phone']);
        $this->assertResponseOk();

        $passkey = $this->decodeJsonBody()['passkey'];
        $this->assertTrue($passkey['backupEligible']);
        $this->assertFalse($passkey['backedUp']);
    }

    /**
     * @return void
     */
    public function testRenameRejectsOtherUsersPasskey(): void
    {
        $this->loginAs(2);
        $id = $this->seedPasskey(1, 'Alices');
        $this->post("/passkeys/rename/{$id}", ['name' => 'Pwned']);
        $this->assertResponseCode(403);
    }

    /**
     * @return void
     */
    public function testDeleteRemovesRowAndFiresEvent(): void
    {
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Mine');

        $fired = false;
        EventManager::instance()->on(
            'CakePasskeys.afterDelete',
            function () use (&$fired): void {
                $fired = true;
            },
        );

        $this->delete("/passkeys/delete/{$id}");
        $this->assertResponseOk();
        $this->assertFalse(
            $this->getTableLocator()->get('CakePasskeys.Passkeys')->exists(['id' => $id]),
        );
        $this->assertTrue($fired);
    }

    /**
     * The delete answer lists the passkeys the user still has, so the browser
     * can drop the deleted one from the authenticator.
     *
     * @return void
     */
    public function testDeleteReturnsAcceptedCredentialsForSignals(): void
    {
        $this->loginAs(1);
        $deleted = $this->seedPasskey(1, 'Old');
        $kept = $this->seedPasskey(1, 'Kept');
        $this->seedPasskey(2, 'Not mine');
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        $keptCredential = (string)$table->get($kept)->get('credential_id');
        $user = (new UserResolver())->byId(1);
        $this->assertNotNull($user);

        $this->delete("/passkeys/delete/{$deleted}");

        $this->assertResponseOk();
        $body = $this->decodeJsonBody();
        $this->assertTrue($body['deleted']);
        $this->assertSame('localhost', $body['rpId']);
        $this->assertSame(Signals::base64Url($user->getPasskeyUserHandle()), $body['userHandle']);
        $this->assertSame([Signals::base64Url($keptCredential)], $body['acceptedCredentialIds']);
    }

    /**
     * @return void
     */
    public function testDeleteOmitsSignalDataWhenSignalsAreOff(): void
    {
        Configure::write('CakePasskeys.signals', false);
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Old');

        $this->delete("/passkeys/delete/{$id}");

        $this->assertResponseOk();
        $this->assertSame(['deleted' => true], $this->decodeJsonBody());
    }

    /**
     * A sign-in with a credential the server does not know answers with a
     * code the browser can act on; the message stays generic.
     *
     * @return void
     */
    public function testLoginFinishReportsUnknownCredentialCode(): void
    {
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willThrowException(new UnknownCredentialException('Unknown passkey credential.'));
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $response = $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame(400, $response->getStatusCode());
        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(Signals::UNKNOWN_CREDENTIAL, $body['error']);
        $this->assertSame('Unknown passkey credential.', $body['message']);
    }

    /**
     * Any other failure, and every failure with signals off, stays a plain
     * 400 without the code.
     *
     * @param \CakePasskeys\Service\WebAuthnException $exception
     * @param bool $signals
     *
     * @return void
     */
    #[DataProvider('loginFailuresWithoutCode')]
    public function testLoginFinishSendsNoCodeForOtherFailures(WebAuthnException $exception, bool $signals): void
    {
        Configure::write('CakePasskeys.signals', $signals);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willThrowException($exception);
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $this->expectException(BadRequestException::class);
        $this->controllerWith($request, $service)->loginFinish();
    }

    /**
     * @return array<string, array{\CakePasskeys\Service\WebAuthnException, bool}>
     */
    public static function loginFailuresWithoutCode(): array
    {
        return [
            'deactivated account' => [new WebAuthnException('Unknown passkey credential.'), true],
            'bad signature' => [new WebAuthnException('Passkey verification failed.'), true],
            'signals off' => [new UnknownCredentialException('Unknown passkey credential.'), false],
        ];
    }

    /**
     * @return void
     */
    public function testDeleteRejectsOtherUsersPasskey(): void
    {
        $this->loginAs(2);
        $id = $this->seedPasskey(1, 'Mine');
        $this->delete("/passkeys/delete/{$id}");
        $this->assertResponseCode(403);
    }

    /**
     * loginStart() must read the email hint from the JSON body (what the
     * shipped JS client sends as `{emailHint: 'alice@example.com'}`). The
     * legacy query-string form (`?email=...`) is still supported, but body
     * wins when both are present.
     *
     * @return void
     */
    public function testLoginStartReadsEmailHintFromBody(): void
    {
        Configure::write('CakePasskeys.login.emailHint', true);
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->once())
            ->method('startLogin')
            ->with('alice@example.com')
            ->willReturn(['challenge' => 'x', 'challengeKey' => 'k']);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['emailHint' => 'alice@example.com']);

        $controller = new class ($request, $service) extends PasskeysController {
            public function __construct(
                ServerRequest $request,
                private WebAuthnService $stub,
            ) {
                parent::__construct($request);
            }

            protected function webauthn(): WebAuthnService
            {
                return $this->stub;
            }
        };

        $controller->loginStart();
    }

    /**
     * Body hint takes precedence when both query and body carry a value —
     * this matches the documented "body wins" contract.
     *
     * @return void
     */
    public function testLoginStartBodyHintWinsOverQuery(): void
    {
        Configure::write('CakePasskeys.login.emailHint', true);
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->once())
            ->method('startLogin')
            ->with('body@example.com')
            ->willReturn(['challenge' => 'x', 'challengeKey' => 'k']);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'query' => ['email' => 'query@example.com'],
        ]))->withParsedBody(['emailHint' => 'body@example.com']);

        $controller = new class ($request, $service) extends PasskeysController {
            public function __construct(
                ServerRequest $request,
                private WebAuthnService $stub,
            ) {
                parent::__construct($request);
            }

            protected function webauthn(): WebAuthnService
            {
                return $this->stub;
            }
        };

        $controller->loginStart();
    }

    /**
     * Session-fixation defense: loginFinish() must rotate the session ID
     * before writing any identity-bearing data. We bypass the real WebAuthn
     * ceremony by injecting a stub service that returns a pre-built Passkey,
     * then spy on the request's Session to assert renew() was called BEFORE
     * the Auth.id write.
     *
     * @return void
     */
    public function testLoginFinishRenewsSession(): void
    {
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->newEntity(
            [
                'user_id' => 1,
                'credential_id' => random_bytes(16),
                'public_key' => random_bytes(77),
                'name' => 'Stub',
                'sign_count' => 0,
            ],
            ['accessibleFields' => ['*' => true]],
        );
        $this->getTableLocator()->get('CakePasskeys.Passkeys')->saveOrFail($passkey);

        $session = new class extends Session {
            /**
             * @var array<int, string>
             */
            public array $log = [];

            public function __construct()
            {
                // Skip parent constructor — we only care about call ordering.
            }

            /**
             * @param string|null $name
             * @param mixed $default
             *
             * @return mixed
             */
            public function read(?string $name = null, mixed $default = null): mixed
            {
                return $name === 'CakePasskeys.login_challenges' ? ['x'] : $default;
            }

            /**
             * @return void
             */
            public function renew(): void
            {
                $this->log[] = 'renew';
            }

            /**
             * @param array<string, mixed>|string|null $name
             * @param mixed $value
             *
             * @return void
             */
            public function write(array|string|null $name, mixed $value = null): void
            {
                $this->log[] = 'write:' . (is_array($name) ? 'array' : (string)$name);
            }
        };

        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'session' => $session,
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'x']);

        $controller = new class ($request, $service) extends PasskeysController {
            public function __construct(
                ServerRequest $request,
                private WebAuthnService $stub,
            ) {
                parent::__construct($request);
            }

            protected function webauthn(): WebAuthnService
            {
                return $this->stub;
            }
        };

        $controller->loginFinish();

        // renew() must appear in the call log AND must precede the Auth.id write.
        $this->assertContains('renew', $session->log);
        $authIndex = array_search('write:Auth.id', $session->log, true);
        $renewIndex = array_search('renew', $session->log, true);
        $this->assertNotFalse($authIndex, 'Auth.id was not written');
        $this->assertNotFalse($renewIndex, 'renew() was not called');
        $this->assertLessThan(
            $authIndex,
            $renewIndex,
            'renew() must run BEFORE Auth.id is written (session-fixation guard)',
        );
    }

    /**
     * loginFinish() must honor `CakePasskeys.session.userIdKey` — hosts using
     * cakephp/authentication map identity to `Identity.id`, not the legacy
     * AuthComponent's `Auth.id`. The previous hard-coded path silently
     * dropped the login for those hosts.
     *
     * @return void
     */
    public function testLoginFinishWritesConfiguredSessionKey(): void
    {
        Configure::write('CakePasskeys.session.userIdKey', 'Identity.user_id');

        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->newEntity(
            [
                'user_id' => 1,
                'credential_id' => random_bytes(16),
                'public_key' => random_bytes(77),
                'name' => 'Stub',
                'sign_count' => 0,
            ],
            ['accessibleFields' => ['*' => true]],
        );
        $this->getTableLocator()->get('CakePasskeys.Passkeys')->saveOrFail($passkey);

        $session = new class extends Session {
            /**
             * @var array<int, string>
             */
            public array $log = [];

            public function __construct()
            {
                // Skip parent constructor — we only care about the write path.
            }

            /**
             * @param string|null $name
             * @param mixed $default
             *
             * @return mixed
             */
            public function read(?string $name = null, mixed $default = null): mixed
            {
                return $name === 'CakePasskeys.login_challenges' ? ['x'] : $default;
            }

            /**
             * @return void
             */
            public function renew(): void
            {
                $this->log[] = 'renew';
            }

            /**
             * @param array<string, mixed>|string|null $name
             * @param mixed $value
             *
             * @return void
             */
            public function write(array|string|null $name, mixed $value = null): void
            {
                $this->log[] = 'write:' . (is_array($name) ? 'array' : (string)$name);
            }
        };

        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
            'session' => $session,
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'x']);

        $controller = new class ($request, $service) extends PasskeysController {
            public function __construct(
                ServerRequest $request,
                private WebAuthnService $stub,
            ) {
                parent::__construct($request);
            }

            protected function webauthn(): WebAuthnService
            {
                return $this->stub;
            }
        };

        $controller->loginFinish();

        $this->assertContains(
            'write:Identity.user_id',
            $session->log,
            'loginFinish() must write to the configured CakePasskeys.session.userIdKey path',
        );
        $this->assertNotContains(
            'write:Auth.id',
            $session->log,
            'loginFinish() must NOT write to the legacy default when a custom key is configured',
        );
    }

    /**
     * @return void
     */
    public function testRenameRejectsEmptyName(): void
    {
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Old');
        $this->post("/passkeys/rename/{$id}", ['name' => '   ']);
        $this->assertResponseCode(400);
    }

    /**
     * Narrowing a login to one account tells an anonymous caller whether
     * that account has passkeys, so the hint is ignored unless enabled.
     *
     * @return void
     */
    public function testLoginStartIgnoresEmailHintByDefault(): void
    {
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->once())
            ->method('startLogin')
            ->with(null)
            ->willReturn(['challenge' => 'x', 'challengeKey' => 'k']);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['emailHint' => 'alice@example.com']);

        $this->controllerWith($request, $service)->loginStart();
    }

    /**
     * A challenge answered elsewhere must not sign this browser in: that is
     * how a login is forced onto somebody else's session.
     *
     * @return void
     */
    public function testLoginFinishRejectsChallengeFromAnotherSession(): void
    {
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->never())->method('finishLogin');

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'started-by-someone-else']);

        $this->expectException(BadRequestException::class);
        $this->controllerWith($request, $service)->loginFinish();
    }

    public function testLoginStartRemembersChallengeInSession(): void
    {
        $service = $this->createStub(WebAuthnService::class);
        $service->method('startLogin')->willReturn(['challenge' => 'x', 'challengeKey' => 'k1']);
        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]);

        $this->controllerWith($request, $service)->loginStart();

        $this->assertSame(['k1'], $request->getSession()->read('CakePasskeys.login_challenges'));
    }

    /**
     * Without required user verification a login proves possession only, so
     * it must not count as a second factor.
     *
     * @return void
     */
    public function testLoginFinishSetsMfaFlagOnlyWithRequiredUserVerification(): void
    {
        Configure::write('CakePasskeys.ceremony.userVerification', 'preferred');
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);
        // Left over from an earlier, user-verified sign-in.
        $request->getSession()->write('CakePasskeys.mfa_satisfied', true);

        $response = $this->controllerWith($request, $service)->loginFinish();

        $this->assertFalse(json_decode((string)$response->getBody(), true)['mfaSatisfied']);
        $this->assertNull($request->getSession()->read('CakePasskeys.mfa_satisfied'));
        $this->assertSame(1, (int)$request->getSession()->read('Auth.id'));
    }

    /**
     * With cakephp/authentication in the request, the full user row goes
     * through persistIdentity(); a bare id under its session key would be
     * read back as an identity missing every other user field.
     *
     * @return void
     */
    public function testLoginFinishPersistsIdentityThroughAuthenticationService(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy();
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $controller = $this->controllerWith($request, $service);
        $response = $controller->loginFinish();

        $this->assertCount(1, $auth->persisted);
        $this->assertInstanceOf(EntityInterface::class, $auth->persisted[0]);
        $this->assertSame('alice@example.com', $auth->persisted[0]->get('email'));
        $this->assertSame('persisted', $response->getHeaderLine('X-Spy'));
        $this->assertSame($auth->persisted[0], $request->getSession()->read('Auth'));
    }

    /**
     * Signing in with a passkey while another user's identity is still in the
     * session must replace it, not keep it.
     *
     * @return void
     */
    public function testLoginFinishReplacesIdentityAlreadyInSession(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy();
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $session = $request->getSession();
        $session->write('CakePasskeys.login_challenges', ['k1']);
        $bob = $this->getTableLocator()->get('Users')->get(2);
        $session->write('Auth', $bob);

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame('alice@example.com', $session->read('Auth')->get('email'));
    }

    /**
     * Against the real cakephp/authentication service and Session
     * authenticator: a passkey sign-in on top of another user's session ends
     * with the passkey owner as the identity of the next request.
     *
     * @return void
     */
    public function testLoginFinishWithRealSessionAuthenticator(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $authentication = new AuthenticationService();
        $authentication->loadAuthenticator('Authentication.Session');
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $authentication);
        $session = $request->getSession();
        $session->write('CakePasskeys.login_challenges', ['k1']);
        $session->write('Auth', $this->getTableLocator()->get('Users')->get(2));

        $this->controllerWith($request, $service)->loginFinish();

        $result = $authentication->authenticate(new ServerRequest(['session' => $session]));
        $this->assertTrue($result->isValid());
        $identity = $result->getData();
        $this->assertInstanceOf(EntityInterface::class, $identity);
        $this->assertSame('alice@example.com', $identity->get('email'));
    }

    /**
     * Copilot's case on #8: an earlier user id under userIdKey and an
     * authenticator that keeps the identity elsewhere (or nowhere in the
     * session). The id must be replaced, not kept.
     *
     * @param string|null $sessionKey Where the authenticator stores the identity.
     *
     * @return void
     */
    #[DataProvider('identityElsewhere')]
    public function testLoginFinishReplacesStaleUserIdWhenIdentityLivesElsewhere(?string $sessionKey): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', new AuthenticationServiceSpy($sessionKey));
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);
        $request->getSession()->write('Auth.id', 2);

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame(1, (int)$request->getSession()->read('Auth.id'));
    }

    /**
     * @return array<string, array<string|null>>
     */
    public static function identityElsewhere(): array
    {
        return ['other session key' => ['Identity'], 'not in the session' => [null]];
    }

    /**
     * An unrelated value under the userIdKey root that the authenticator did
     * not touch does not suppress the userIdKey write.
     *
     * @return void
     */
    public function testLoginFinishWritesSessionKeyNextToUnrelatedRootValue(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy(null);
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);
        $request->getSession()->write('Auth.locale', 'de');

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame(1, (int)$request->getSession()->read('Auth.id'));
    }

    /**
     * An authenticator whose session key is nested under the userIdKey root
     * (here `Auth.id`, as the default userIdKey) keeps its identity.
     *
     * @return void
     */
    public function testLoginFinishKeepsIdentityStoredUnderNestedKey(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy('Auth.id');
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame($auth->persisted[0], $request->getSession()->read('Auth.id'));
    }

    /**
     * An afterLogin listener can add to the JSON answer, e.g. an API token.
     *
     * @return void
     */
    public function testAfterLoginListenerResultIsMergedIntoResponse(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $listener = function (EventInterface $event): void {
            $event->setResult(['token' => 'abc', 'userId' => 'ignored']);
        };
        EventManager::instance()->on('CakePasskeys.afterLogin', $listener);
        try {
            $response = $this->controllerWith($request, $service)->loginFinish();
        } finally {
            EventManager::instance()->off('CakePasskeys.afterLogin', $listener);
        }

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('abc', $body['token']);
        $this->assertSame(1, $body['userId']);
    }

    /**
     * With cakephp/authorization and its requireAuthorizationCheck, every
     * plugin action has to count as checked.
     *
     * @return void
     */
    public function testBeforeFilterMarksAuthorizationAsHandled(): void
    {
        $authorization = new class {
            public bool $skipped = false;

            /**
             * @return $this
             */
            public function skipAuthorization()
            {
                $this->skipped = true;

                return $this;
            }
        };
        $request = (new ServerRequest())->withAttribute('authorization', $authorization);
        $controller = $this->controllerWith($request, $this->createStub(WebAuthnService::class));

        $controller->beforeFilter(new Event('Controller.initialize', $controller));

        $this->assertTrue($authorization->skipped);
    }

    /**
     * A host whose authentication service does not keep the identity in the
     * session still gets the configured session key.
     *
     * @return void
     */
    public function testLoginFinishStillWritesSessionKeyWhenIdentityIsNotInSession(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy(null);
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertCount(1, $auth->persisted);
        $this->assertSame(1, (int)$request->getSession()->read('Auth.id'));
    }

    /**
     * @return void
     */
    public function testLoginFinishFallsBackToSessionKeyWhenPersistIdentityIsOff(): void
    {
        Configure::write('CakePasskeys.session.persistIdentity', false);
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $auth = new AuthenticationServiceSpy();
        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $this->controllerWith($request, $service)->loginFinish();

        $this->assertSame([], $auth->persisted);
        $this->assertSame(1, (int)$request->getSession()->read('Auth.id'));
    }

    /**
     * Listeners get the controller as subject, so they can reach the request
     * and session (record the login, set host session flags).
     *
     * @return void
     */
    public function testAfterLoginEventCarriesControllerAsSubject(): void
    {
        $passkeyId = $this->seedPasskey(1, 'Stub');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($passkeyId);
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishLogin')->willReturn($passkey);

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $subject = null;
        $listener = function (EventInterface $event) use (&$subject): void {
            $subject = $event->getSubject();
        };
        EventManager::instance()->on('CakePasskeys.afterLogin', $listener);
        try {
            $controller = $this->controllerWith($request, $service);
            $controller->loginFinish();
        } finally {
            EventManager::instance()->off('CakePasskeys.afterLogin', $listener);
        }

        $this->assertSame($controller, $subject);
    }

    /**
     * @param string $action
     *
     * @return void
     */
    #[DataProvider('invalidReauthActions')]
    public function testReauthFinishRejectsInvalidActionName(string $action): void
    {
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->never())->method('finishReauth');

        $request = (new ServerRequest([
            'environment' => ['REQUEST_METHOD' => 'POST'],
        ]))->withParsedBody(['response' => [], 'challengeKey' => 'k', 'action' => $action]);
        $request->getSession()->write('Auth.id', 1);

        $this->expectException(BadRequestException::class);
        $this->controllerWith($request, $service)->reauthFinish();
    }

    /**
     * @return array<string, array<string>>
     */
    public static function invalidReauthActions(): array
    {
        return [
            'session path' => ['a.b'],
            'spaces' => ['change email'],
            'too long' => [str_repeat('a', 65)],
        ];
    }

    public function testIdentityResolverClosureDecidesTheUser(): void
    {
        Configure::write('CakePasskeys.identityResolver', fn (ServerRequest $request): int => 2);
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->once())
            ->method('startRegistration')
            ->with($this->callback(fn ($user): bool => $user->getUserId() === 2))
            ->willReturn(['challenge' => 'x', 'challengeKey' => 'k']);

        $request = new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]);

        $this->controllerWith($request, $service)->registerStart();
    }

    /**
     * @return void
     */
    public function testRegisterFinishSendsNoAlertByDefault(): void
    {
        $this->registerFinishWithStub();

        $this->assertNoMailSent();
    }

    /**
     * @return void
     */
    public function testRegisterFinishMailsAlertWhenEnabled(): void
    {
        Configure::write('CakePasskeys.notify.onRegister', true);

        $response = $this->registerFinishWithStub();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertMailCount(1);
        $this->assertMailSentTo('alice@example.com');
        $this->assertMailContainsText('Name: Work laptop');
        $this->assertMailContainsText('Device: YubiKey 5');
    }

    /**
     * The passkey is stored before the mail goes out, so a broken mailer
     * must not turn the registration into an error.
     *
     * @return void
     */
    public function testRegisterFinishSucceedsWhenAlertMailFails(): void
    {
        Configure::write('CakePasskeys.notify.onRegister', true);
        Configure::write('CakePasskeys.notify.emailProfile', 'no-such-profile');
        Log::drop('passkeys_test_memory');
        Log::setConfig('passkeys_test_memory', ['className' => 'Array', 'levels' => ['warning']]);

        try {
            $response = $this->registerFinishWithStub();
            /** @var \Cake\Log\Engine\ArrayLog $log */
            $log = Log::engine('passkeys_test_memory');
            $logged = $log->read();
        } finally {
            Log::drop('passkeys_test_memory');
        }

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNoMailSent();
        $this->assertCount(1, $logged);
        $this->assertStringContainsString('could not send the passkey alert', $logged[0]);
    }

    /**
     * Runs registerFinish() for user 1 against a stub service that returns
     * a stored passkey.
     *
     * @return \Cake\Http\Response
     */
    private function registerFinishWithStub(): Response
    {
        Configure::write('CakePasskeys.rateLimiter', false);
        $passkeyId = $this->seedPasskey(1, 'Work laptop');
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        /** @var \CakePasskeys\Model\Entity\Passkey $passkey */
        $passkey = $table->get($passkeyId);
        $passkey->aaguid_label = 'YubiKey 5';

        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishRegistration')->willReturn($passkey);
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withAttribute('identity', ['id' => 1])
            ->withParsedBody(['response' => [], 'challengeKey' => 'x', 'name' => 'Work laptop']);

        return $this->controllerWith($request, $service)->registerFinish();
    }

    /**
     * @param \Cake\Http\ServerRequest $request
     * @param \CakePasskeys\Service\WebAuthnService $service
     *
     * @return \CakePasskeys\Controller\PasskeysController
     */
    private function controllerWith(ServerRequest $request, WebAuthnService $service): PasskeysController
    {
        return new class ($request, $service) extends PasskeysController {
            public function __construct(
                ServerRequest $request,
                private WebAuthnService $stub,
            ) {
                parent::__construct($request);
            }

            protected function webauthn(): WebAuthnService
            {
                return $this->stub;
            }
        };
    }

    /**
     * Authenticate the test session as the given user id. The test_app
     * middleware reads `Auth.id` from the session and wraps it into an
     * `identity` request attribute that satisfies the controller.
     *
     * @param int $userId
     *
     * @return void
     */
    private function loginAs(int $userId): void
    {
        $this->session(['Auth' => ['id' => $userId]]);
        $this->configRequest([
            'headers' => ['Accept' => 'application/json'],
        ]);
    }

    /**
     * @param int $userId
     * @param string $name
     *
     * @return int
     */
    private function seedPasskey(int $userId, string $name): int
    {
        $table = $this->getTableLocator()->get('CakePasskeys.Passkeys');
        /** @var \CakePasskeys\Model\Entity\Passkey $entity */
        $entity = $table->newEntity(
            [
                'user_id' => $userId,
                'credential_id' => random_bytes(16),
                'public_key' => random_bytes(77),
                'name' => $name,
                'sign_count' => 0,
            ],
            ['accessibleFields' => ['*' => true]],
        );
        $table->saveOrFail($entity);

        return (int)$entity->id;
    }

    /**
     * @return void
     */
    public function testWellKnownReturnsConfiguredUrls(): void
    {
        Configure::write('CakePasskeys.wellKnown', [
            'enroll' => '/account/passkeys/add',
            'manage' => ['plugin' => 'CakePasskeys', 'controller' => 'Passkeys', 'action' => 'loginStart'],
        ]);
        $this->get('/.well-known/passkey-endpoints');
        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertSame([
            'enroll' => 'http://localhost/account/passkeys/add',
            'manage' => 'http://localhost/passkeys/login/start',
        ], $this->decodeJsonBody());
    }

    /**
     * @return void
     */
    public function testWellKnownOmitsUnconfiguredKeys(): void
    {
        Configure::write('CakePasskeys.wellKnown.manage', 'https://example.com/settings/security');
        $this->get('/.well-known/passkey-endpoints');
        $this->assertResponseOk();
        $this->assertSame(['manage' => 'https://example.com/settings/security'], $this->decodeJsonBody());
    }

    /**
     * @return void
     */
    public function testWellKnownReturns404WhenUnconfigured(): void
    {
        $this->get('/.well-known/passkey-endpoints');
        $this->assertResponseCode(404);
    }

    /**
     * @return void
     */
    public function testWellKnownReturns404WhenDisabled(): void
    {
        Configure::write('CakePasskeys.enabled', false);
        Configure::write('CakePasskeys.wellKnown.enroll', '/account/passkeys/add');
        $this->get('/.well-known/passkey-endpoints');
        $this->assertResponseCode(404);
    }

    /**
     * Without a pending-user source the second-factor endpoints do not exist.
     *
     * @param string $url
     *
     * @return void
     */
    #[DataProvider('secondFactorEndpoints')]
    public function testSecondFactorIsOffByDefault(string $url): void
    {
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);
        $this->post($url);
        $this->assertResponseCode(404);
    }

    /**
     * @return array<string, array<string>>
     */
    public static function secondFactorEndpoints(): array
    {
        return [
            'start' => ['/passkeys/second-factor/start'],
            'finish' => ['/passkeys/second-factor/finish'],
        ];
    }

    /**
     * @param string $url
     *
     * @return void
     */
    #[DataProvider('secondFactorEndpoints')]
    public function testSecondFactorWithoutPendingUserAnswers401(string $url): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $this->post($url);
        $this->assertResponseCode(401);
    }

    /**
     * A pending user that the user table no longer serves (deactivated) is
     * treated like no pending user.
     *
     * @return void
     */
    public function testSecondFactorStartRefusesInactivePendingUser(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        Configure::write('CakePasskeys.users.activeColumn', 'is_active');
        $conn = ConnectionManager::get('test');
        assert($conn instanceof Connection);
        $conn->execute('UPDATE users SET is_active = 0 WHERE id = 1');
        $this->seedPasskey(1, 'Laptop');
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);
        try {
            $this->post('/passkeys/second-factor/start');
        } finally {
            Configure::write('CakePasskeys.users.activeColumn', null);
        }
        $this->assertResponseCode(401);
    }

    /**
     * @return void
     */
    public function testSecondFactorStartOffersOnlyThePendingUsersPasskeys(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $own = $this->seedPasskey(1, 'Alice laptop');
        $this->seedPasskey(2, 'Bob laptop');
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);

        $this->post('/passkeys/second-factor/start');

        $this->assertResponseOk();
        $body = $this->decodeJsonBody();
        $credentialId = (string)$this->getTableLocator()->get('CakePasskeys.Passkeys')->get($own)->credential_id;
        $this->assertCount(1, $body['allowCredentials']);
        $this->assertSame(Signals::base64Url($credentialId), $body['allowCredentials'][0]['id']);
        $this->assertSame('required', $body['userVerification']);
        $this->assertSession([$body['challengeKey']], 'CakePasskeys.second_factor_challenges');
    }

    /**
     * @return void
     */
    public function testSecondFactorStartWithoutPasskeysAnswers400(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $this->seedPasskey(2, 'Bob laptop');
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);

        $this->post('/passkeys/second-factor/start');

        $this->assertResponseCode(400);
        $this->assertResponseContains('A passkey cannot be used for this sign-in.');
    }

    /**
     * @return void
     */
    public function testSecondFactorResolverWinsOverSessionKey(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        Configure::write('CakePasskeys.secondFactor.pendingUserResolver', fn (ServerRequest $request): int => 2);
        $this->seedPasskey(1, 'Alice laptop');
        $bob = $this->seedPasskey(2, 'Bob laptop');
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);

        $this->post('/passkeys/second-factor/start');

        $this->assertResponseOk();
        $credentialId = (string)$this->getTableLocator()->get('CakePasskeys.Passkeys')->get($bob)->credential_id;
        $this->assertSame(Signals::base64Url($credentialId), $this->decodeJsonBody()['allowCredentials'][0]['id']);
    }

    /**
     * @param string $url
     *
     * @return void
     */
    #[DataProvider('secondFactorEndpoints')]
    public function testSecondFactorIsThrottledPerPendingUser(string $url): void
    {
        $keys = [];
        Configure::write('CakePasskeys.rateLimiter', new class ($keys) implements RateLimiterInterface {
            /**
             * @param array<string> $keys
             */
            public function __construct(public array &$keys)
            {
            }

            public function hit(string $key, int $maxAttempts, int $decaySeconds): bool
            {
                $this->keys[] = $key;

                return false;
            }
        });
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $this->session(['TwoFactor' => ['pending_user_id' => 1]]);

        $this->post($url);

        $this->assertResponseCode(429);
        $this->assertStringStartsWith('passkeys.second_factor', $keys[0]);
        $this->assertStringEndsWith('.1', $keys[0]);
    }

    /**
     * The happy path hands the sign-in over like loginFinish does.
     *
     * @return void
     */
    public function testSecondFactorFinishCompletesTheSignIn(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        Configure::write('CakePasskeys.secondFactor.redirect', '/dashboard');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($this->seedPasskey(1, 'Stub'));
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->once())->method('finishSecondFactor')
            ->with($this->callback(fn ($user): bool => (string)$user->getUserId() === '1'))
            ->willReturn($passkey);

        $auth = new AuthenticationServiceSpy();
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['response' => [], 'challengeKey' => 'k1'])
            ->withAttribute('authentication', $auth);
        $session = $request->getSession();
        $session->write('CakePasskeys.second_factor_challenges', ['k1']);
        $session->write('TwoFactor.pending_user_id', 1);
        $session->write('Auth', $this->getTableLocator()->get('Users')->get(2));

        $fired = null;
        $listener = function (EventInterface $event) use (&$fired): void {
            $fired = $event;
            $event->setResult(['token' => 'abc']);
        };
        EventManager::instance()->on('CakePasskeys.afterSecondFactor', $listener);
        try {
            $controller = $this->controllerWith($request, $service);
            $response = $controller->secondFactorFinish();
        } finally {
            EventManager::instance()->off('CakePasskeys.afterSecondFactor', $listener);
        }

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame(['redirectTo' => '/dashboard', 'mfaSatisfied' => true, 'userId' => 1, 'token' => 'abc'], $body);
        $this->assertSame('alice@example.com', $session->read('Auth')->get('email'));
        $this->assertCount(1, $auth->persisted);
        $this->assertNull($session->read('TwoFactor.pending_user_id'));
        $this->assertTrue($session->read('CakePasskeys.mfa_satisfied'));
        $this->assertSame([], $session->read('CakePasskeys.second_factor_challenges'));
        $this->assertInstanceOf(EventInterface::class, $fired);
        $this->assertSame($controller, $fired->getSubject());
        $this->assertSame($passkey, $fired->getData('event')->getPasskey());
    }

    /**
     * Without required user verification the MFA flag is not set, and with a
     * resolver the host's own pending state is left alone.
     *
     * @return void
     */
    public function testSecondFactorFinishWithResolverAndPreferredVerification(): void
    {
        Configure::write('CakePasskeys.ceremony.userVerification', 'preferred');
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        Configure::write('CakePasskeys.secondFactor.pendingUserResolver', fn (ServerRequest $request): int => 1);
        Configure::write('CakePasskeys.afterLoginRedirect', '/home');
        $passkey = $this->getTableLocator()->get('CakePasskeys.Passkeys')->get($this->seedPasskey(1, 'Stub'));
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishSecondFactor')->willReturn($passkey);

        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $session = $request->getSession();
        $session->write('CakePasskeys.second_factor_challenges', ['k1']);
        $session->write('TwoFactor.pending_user_id', 1);
        $session->write('CakePasskeys.mfa_satisfied', true);

        $response = $this->controllerWith($request, $service)->secondFactorFinish();

        $body = json_decode((string)$response->getBody(), true);
        $this->assertSame('/home', $body['redirectTo']);
        $this->assertFalse($body['mfaSatisfied']);
        $this->assertNull($session->read('CakePasskeys.mfa_satisfied'));
        $this->assertSame(1, $session->read('TwoFactor.pending_user_id'));
        $this->assertSame(1, (int)$session->read('Auth.id'));
    }

    /**
     * A challenge started in another browser session is refused before any
     * verification runs.
     *
     * @return void
     */
    public function testSecondFactorFinishRejectsChallengeFromAnotherSession(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->never())->method('finishSecondFactor');
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['response' => [], 'challengeKey' => 'started-elsewhere']);
        $request->getSession()->write('TwoFactor.pending_user_id', 1);
        $request->getSession()->write('CakePasskeys.second_factor_challenges', ['mine']);

        $this->expectException(BadRequestException::class);
        $this->controllerWith($request, $service)->secondFactorFinish();
    }

    /**
     * A login challenge of the same session does not count as a
     * second-factor challenge.
     *
     * @return void
     */
    public function testSecondFactorFinishRejectsLoginChallenge(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $service = $this->createMock(WebAuthnService::class);
        $service->expects($this->never())->method('finishSecondFactor');
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $request->getSession()->write('TwoFactor.pending_user_id', 1);
        $request->getSession()->write('CakePasskeys.login_challenges', ['k1']);

        $this->expectException(BadRequestException::class);
        $this->controllerWith($request, $service)->secondFactorFinish();
    }

    /**
     * When verification fails (another user's passkey, a challenge of
     * another user), nobody is signed in and the pending state stays.
     *
     * @return void
     */
    public function testSecondFactorFinishFailureSignsNobodyIn(): void
    {
        Configure::write('CakePasskeys.secondFactor.pendingUserKey', 'TwoFactor.pending_user_id');
        $service = $this->createStub(WebAuthnService::class);
        $service->method('finishSecondFactor')
            ->willThrowException(new WebAuthnException('Unknown passkey credential for this user.'));
        $request = (new ServerRequest(['environment' => ['REQUEST_METHOD' => 'POST']]))
            ->withParsedBody(['response' => [], 'challengeKey' => 'k1']);
        $session = $request->getSession();
        $session->write('TwoFactor.pending_user_id', 1);
        $session->write('CakePasskeys.second_factor_challenges', ['k1']);

        try {
            $this->controllerWith($request, $service)->secondFactorFinish();
            $this->fail('Expected a BadRequestException.');
        } catch (BadRequestException $e) {
            $this->assertSame('Unknown passkey credential for this user.', $e->getMessage());
        }
        $this->assertNull($session->read('Auth.id'));
        $this->assertNull($session->read('CakePasskeys.mfa_satisfied'));
        $this->assertSame(1, $session->read('TwoFactor.pending_user_id'));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeJsonBody(): array
    {
        $response = $this->_response;
        if ($response === null) {
            return [];
        }
        $decoded = json_decode((string)$response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }
}
