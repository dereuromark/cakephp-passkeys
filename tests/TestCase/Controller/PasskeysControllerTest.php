<?php
declare(strict_types=1);

namespace Passkeys\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\Database\Connection;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventManager;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use Passkeys\Controller\PasskeysController;
use Passkeys\Event\PasskeyEvent;
use Passkeys\Service\WebAuthnService;

class PasskeysControllerTest extends TestCase
{
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
        Configure::write('Passkeys.rpId', 'localhost');
        Configure::write('Passkeys.rpName', 'Test');
        Configure::write('Passkeys.maxPerUser', 5);
        Configure::write('Passkeys.challengeTtl', 300);
        Configure::write('Passkeys.cache', 'default');
        Configure::write('Passkeys.tenancy.column', null);
        Configure::write('Passkeys.ceremony', [
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
            'CREATE TABLE users (' .
            'id INTEGER PRIMARY KEY, ' .
            'email VARCHAR(255), ' .
            'name VARCHAR(255), ' .
            'is_active INTEGER DEFAULT 1' .
            ')',
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

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'Passkeys']);
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
     * @return void
     */
    public function testRenameUpdatesNameAndFiresEvent(): void
    {
        $this->loginAs(1);
        $id = $this->seedPasskey(1, 'Old name');

        /** @var array<string, mixed>|null $fired */
        $fired = null;
        EventManager::instance()->on(
            'Passkeys.afterRename',
            function ($event) use (&$fired): void {
                $fired = $event->getData();
            },
        );

        $this->post("/passkeys/rename/{$id}", ['name' => 'New laptop']);
        $this->assertResponseOk();

        /** @var \Passkeys\Model\Entity\Passkey $row */
        $row = $this->getTableLocator()->get('Passkeys.Passkeys')->get($id);
        $this->assertSame('New laptop', $row->name);
        $this->assertNotNull($fired);
        $this->assertInstanceOf(PasskeyEvent::class, $fired['event']);
        $this->assertSame('Old name', $fired['event']->getData()['old']);
        $this->assertSame('New laptop', $fired['event']->getData()['new']);
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
            'Passkeys.afterDelete',
            function () use (&$fired): void {
                $fired = true;
            },
        );

        $this->delete("/passkeys/delete/{$id}");
        $this->assertResponseOk();
        $this->assertFalse(
            $this->getTableLocator()->get('Passkeys.Passkeys')->exists(['id' => $id]),
        );
        $this->assertTrue($fired);
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
        $passkey = $this->getTableLocator()->get('Passkeys.Passkeys')->newEntity(
            [
                'user_id' => 1,
                'credential_id' => random_bytes(16),
                'public_key' => random_bytes(77),
                'name' => 'Stub',
                'sign_count' => 0,
            ],
            ['accessibleFields' => ['*' => true]],
        );
        $this->getTableLocator()->get('Passkeys.Passkeys')->saveOrFail($passkey);

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
             * @return void
             */
            public function renew(): void
            {
                $this->log[] = 'renew';
            }

            /**
             * @param string|array<string, mixed>|null $name
             * @param mixed $value
             * @return void
             */
            public function write(array|string|null $name, mixed $value = null): void
            {
                $this->log[] = 'write:' . (is_array($name) ? 'array' : (string)$name);
            }
        };

        $service = $this->createMock(WebAuthnService::class);
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
     * Authenticate the test session as the given user id. The test_app
     * middleware reads `Auth.id` from the session and wraps it into an
     * `identity` request attribute that satisfies the controller.
     *
     * @param int $userId
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
     * @return int
     */
    private function seedPasskey(int $userId, string $name): int
    {
        $table = $this->getTableLocator()->get('Passkeys.Passkeys');
        /** @var \Passkeys\Model\Entity\Passkey $entity */
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
