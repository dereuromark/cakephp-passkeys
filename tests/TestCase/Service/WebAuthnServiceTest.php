<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;
use CakePasskeys\Contract\PasskeyUserInterface;
use CakePasskeys\Service\AaguidLabelResolver;
use CakePasskeys\Service\ChallengeStore;
use CakePasskeys\Service\ConventionUserAdapter;
use CakePasskeys\Service\UserResolver;
use CakePasskeys\Service\WebAuthnException;
use CakePasskeys\Service\WebAuthnService;
use Migrations\Migrations;

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
        Configure::write('CakePasskeys.rpId', 'localhost');
        Configure::write('CakePasskeys.rpName', 'Test');
        Configure::write('CakePasskeys.maxPerUser', 5);
        Configure::write('CakePasskeys.challengeTtl', 300);
        Configure::write('CakePasskeys.cache', 'default');
        Configure::write('CakePasskeys.ceremony', [
            'userVerification' => 'required',
            'residentKey' => 'preferred',
            'attestation' => 'none',
            'timeout' => 60_000,
        ]);
        Configure::write('CakePasskeys.tenancy.column', null);

        $migrations = new Migrations(['connection' => 'test', 'plugin' => 'CakePasskeys']);
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
