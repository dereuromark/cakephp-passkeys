<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use ArrayObject;
use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\IdentityResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

class IdentityResolverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.identityResolver', null);
        Configure::write('CakePasskeys.session.userIdKey', 'Auth.id');
    }

    protected function tearDown(): void
    {
        Configure::write('CakePasskeys.identityResolver', null);
        parent::tearDown();
    }

    /**
     * @param mixed $identity
     * @param string|int|null $expected
     *
     * @return void
     */
    #[DataProvider('identities')]
    public function testReadsTheIdentityAttribute(mixed $identity, string|int|null $expected): void
    {
        $request = (new ServerRequest())->withAttribute('identity', $identity);

        $this->assertSame($expected, (new IdentityResolver())->userId($request));
    }

    /**
     * @return array<string, array{mixed, string|int|null}>
     */
    public static function identities(): array
    {
        $withMethod = new class {
            public function getIdentifier(): string
            {
                return '7f0c6d0e-uuid';
            }
        };
        $withProperty = new stdClass();
        $withProperty->id = 5;

        return [
            'getIdentifier' => [$withMethod, '7f0c6d0e-uuid'],
            'array' => [['id' => 3], 3],
            'ArrayAccess' => [new ArrayObject(['id' => 4]), 4],
            'property' => [$withProperty, 5],
            'empty string id' => [['id' => ''], null],
            'no identity' => [null, null],
        ];
    }

    public function testFallsBackToTheSessionKey(): void
    {
        $request = new ServerRequest();
        $request->getSession()->write('Auth.id', 9);

        $this->assertSame(9, (new IdentityResolver())->userId($request));
    }

    public function testEmptySessionKeyDisablesTheFallback(): void
    {
        Configure::write('CakePasskeys.session.userIdKey', '');
        $request = new ServerRequest();
        $request->getSession()->write('Auth.id', 9);

        $this->assertNull((new IdentityResolver())->userId($request));
    }

    /**
     * A configured resolver is the only source: a guest answer from it must
     * not fall through to the session.
     *
     * @return void
     */
    public function testConfiguredResolverIsAuthoritative(): void
    {
        Configure::write('CakePasskeys.identityResolver', fn (ServerRequest $request): ?int => null);
        $request = (new ServerRequest())->withAttribute('identity', ['id' => 3]);
        $request->getSession()->write('Auth.id', 9);

        $this->assertNull((new IdentityResolver())->userId($request));
    }
}
