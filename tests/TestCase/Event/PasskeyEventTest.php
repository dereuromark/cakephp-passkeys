<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Event;

use Cake\TestSuite\TestCase;
use Passkeys\Event\PasskeyEvent;
use Passkeys\Model\Entity\Passkey;

class PasskeyEventTest extends TestCase
{
    public function testEventExposesPasskeyAndHandle(): void
    {
        $passkey = new Passkey(['id' => 7, 'name' => 'iPhone']);
        $event = new PasskeyEvent($passkey, 'user-handle-abc', ['old' => 'a', 'new' => 'b']);

        $this->assertSame($passkey, $event->getPasskey());
        $this->assertSame('user-handle-abc', $event->getUserHandle());
        $this->assertSame(['old' => 'a', 'new' => 'b'], $event->getData());
    }

    public function testCredentialIdSerializesAsBase64Url(): void
    {
        $passkey = new Passkey([
            'id' => 1,
            'name' => 'X',
            'credential_id' => "\x01\x02\x03\xff",
        ], ['markClean' => true, 'guard' => false]);
        $event = new PasskeyEvent($passkey, 'h');
        $this->assertSame('AQID_w', $event->getCredentialIdBase64Url());
    }

    public function testGetCredentialIdBase64UrlReturnsEmptyWhenMissing(): void
    {
        $passkey = new Passkey(['id' => 1, 'name' => 'X']);
        $event = new PasskeyEvent($passkey, 'h');
        $this->assertSame('', $event->getCredentialIdBase64Url());
    }

    public function testDataDefaultsToEmptyArray(): void
    {
        $passkey = new Passkey(['id' => 1, 'name' => 'X']);
        $event = new PasskeyEvent($passkey, 'h');
        $this->assertSame([], $event->getData());
    }
}
