<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use Migrations\Migrations;
use Passkeys\Contract\PasskeyUserInterface;
use Passkeys\Service\AaguidLabelResolver;
use Passkeys\Service\ChallengeStore;
use Passkeys\Service\ConventionUserAdapter;
use Passkeys\Service\UserResolver;
use Passkeys\Service\WebAuthnException;
use Passkeys\Service\WebAuthnService;

class WebAuthnServiceTest extends TestCase
{
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
        Configure::write('Passkeys.rpId', 'localhost');
        Configure::write('Passkeys.rpName', 'Test');
        Configure::write('Passkeys.maxPerUser', 5);
        Configure::write('Passkeys.challengeTtl', 300);
        Configure::write('Passkeys.cache', 'default');
        Configure::write('Passkeys.ceremony', [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ]);
        Configure::write('Passkeys.tenancy.column', null);

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'Passkeys']);
        $migrations->rollback(['target' => 0]);
        $migrations->migrate();

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

    public function testFinishRegistrationHappyPathPersistsPasskey(): void
    {
        $this->markTestIncomplete('Pending fixture round-trip helper (real authenticator response capture)');
    }

    public function testMaxPerUserEnforced(): void
    {
        $this->markTestIncomplete('Pending fixture round-trip helper');
    }

    public function testCounterRollbackRejected(): void
    {
        $this->markTestIncomplete('Pending fixture round-trip helper');
    }

    /**
     * @param int $id
     *
     * @return \Passkeys\Contract\PasskeyUserInterface
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
