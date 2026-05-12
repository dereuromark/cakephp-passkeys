<?php

declare(strict_types=1);

namespace Passkeys\Test\TestCase\Service;

use Cake\TestSuite\TestCase;
use Passkeys\Service\AaguidLabelResolver;

class AaguidLabelResolverTest extends TestCase
{
    public function testResolvesKnownAaguid(): void
    {
        // iCloud Keychain AAGUID (public, well-known)
        $hex = 'adce000235bcc60a648b0b25f1f05503';
        $bin = (string)hex2bin($hex);
        $this->assertSame('iCloud Keychain', (new AaguidLabelResolver())->labelFor($bin));
    }

    public function testReturnsNullForUnknownAaguid(): void
    {
        $bin = random_bytes(16);
        $this->assertNull((new AaguidLabelResolver())->labelFor($bin));
    }

    public function testReturnsNullForEmptyInput(): void
    {
        $this->assertNull((new AaguidLabelResolver())->labelFor(''));
        $this->assertNull((new AaguidLabelResolver())->labelFor(null));
    }

    public function testReturnsNullForMalformedLength(): void
    {
        $this->assertNull((new AaguidLabelResolver())->labelFor("\x00\x01\x02"));
    }
}
