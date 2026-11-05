<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\I18n\DateTime;
use Cake\TestSuite\TestCase;
use CakePasskeys\Test\PasskeyCommandFixtureTrait;

class PasskeysListCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;
    use PasskeyCommandFixtureTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSchema();
    }

    /**
     * @return void
     */
    public function testListsPasskeysOfTheUser(): void
    {
        $id = $this->seedPasskey(1, 'Laptop', [
            'aaguid_label' => 'iCloud Keychain',
            'last_used_at' => new DateTime('2026-11-04 08:30:00'),
        ]);
        $this->seedPasskey(1, 'Phone');
        $this->seedPasskey(2, 'Bob key');

        $this->exec('passkeys list 1');

        $this->assertExitSuccess();
        $this->assertOutputRegExp('/\| ' . $id . '\s+\| Laptop\s+\| 2026-11-01 10:00 \| 2026-11-04 08:30 \| iCloud Keychain/');
        $this->assertOutputContains('Phone');
        $this->assertOutputNotContains('Bob key');
    }

    /**
     * @return void
     */
    public function testListWithoutPasskeys(): void
    {
        $this->exec('passkeys list 2');

        $this->assertExitSuccess();
        $this->assertOutputContains('No passkeys found for user 2.');
    }

    /**
     * @return void
     */
    public function testListRequiresUser(): void
    {
        $this->exec('passkeys list');

        $this->assertExitError();
    }
}
