<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\Reauth;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;

class ReauthTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.identityResolver', null);
        Configure::write('CakePasskeys.session.userIdKey', 'Auth.id');
    }

    /**
     * @param mixed $stored
     * @param bool $expected
     *
     * @return void
     */
    #[DataProvider('storedValues')]
    public function testIsFresh(mixed $stored, bool $expected): void
    {
        $request = $this->requestFor(1);
        $request->getSession()->write('CakePasskeys.recent_reauth.change-email', $stored);

        $this->assertSame($expected, Reauth::isFresh($request, 'change-email'));
    }

    /**
     * @return array<string, array{mixed, bool}>
     */
    public static function storedValues(): array
    {
        $soon = (new DateTimeImmutable('+5 minutes'))->format(DATE_ATOM);

        return [
            'in the future' => [['until' => $soon, 'userId' => '1'], true],
            'expired' => [['until' => (new DateTimeImmutable('-1 second'))->format(DATE_ATOM), 'userId' => '1'], false],
            'confirmed by another user' => [['until' => $soon, 'userId' => '2'], false],
            'no user recorded' => [['until' => $soon], false],
            'missing' => [null, false],
            'garbage date' => [['until' => 'not a date', 'userId' => '1'], false],
            'wrong type' => [$soon, false],
        ];
    }

    public function testOtherActionIsNotFresh(): void
    {
        $request = $this->requestFor(1);
        $request->getSession()->write('CakePasskeys.recent_reauth.change-email', $this->record('1'));

        $this->assertFalse(Reauth::isFresh($request, 'delete-account'));
    }

    public function testGuestIsNeverFresh(): void
    {
        $request = new ServerRequest();
        $request->getSession()->write('CakePasskeys.recent_reauth.change-email', $this->record('1'));

        $this->assertFalse(Reauth::isFresh($request, 'change-email'));
    }

    /**
     * @param int $userId
     *
     * @return \Cake\Http\ServerRequest
     */
    private function requestFor(int $userId): ServerRequest
    {
        return (new ServerRequest())->withAttribute('identity', ['id' => $userId]);
    }

    /**
     * @param string $userId
     *
     * @return array<string, string>
     */
    private function record(string $userId): array
    {
        return ['until' => (new DateTimeImmutable('+5 minutes'))->format(DATE_ATOM), 'userId' => $userId];
    }
}
