<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Mailer;

use Cake\Core\Configure;
use Cake\I18n\DateTime;
use Cake\ORM\Entity;
use Cake\Routing\Router;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;
use Cake\TestSuite\TestEmailTransport;
use CakePasskeys\Mailer\PasskeyMailer;
use CakePasskeys\Model\Entity\Passkey;
use CakePasskeys\Service\ConventionUserAdapter;

class PasskeyMailerTest extends TestCase
{
    use EmailTrait;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        Router::reload();
        $this->loadPlugins(['CakePasskeys' => ['bootstrap' => false, 'routes' => false]]);
        Configure::write('CakePasskeys.rpName', 'Test App');
        Configure::write('CakePasskeys.notify.manageUrl', null);
        Configure::write('CakePasskeys.users.columns', ['id' => 'id', 'email' => 'email', 'displayName' => 'name']);
    }

    /**
     * @return void
     */
    public function testRegisteredRendersTextAndHtml(): void
    {
        Configure::write('CakePasskeys.notify.manageUrl', 'https://example.com/account/passkeys');

        (new PasskeyMailer())->send('registered', [$this->user(), $this->passkey('iCloud Keychain')]);

        $this->assertMailCount(1);
        $this->assertMailSentTo('alice@example.com');
        $this->assertMailSubjectContains('Test App: a passkey was added to your account');
        $this->assertMailContainsText('Name: Work laptop');
        $this->assertMailContainsText('Device: iCloud Keychain');
        $this->assertMailContainsText('If it was not you');
        $this->assertMailContainsText('https://example.com/account/passkeys');
        $this->assertMailContainsHtml('<li>Name: Work laptop</li>');
        $this->assertMailContainsHtml('href="https://example.com/account/passkeys"');
    }

    /**
     * @return void
     */
    public function testRegisteredOmitsMissingDeviceLabelAndLink(): void
    {
        (new PasskeyMailer())->send('registered', [$this->user(), $this->passkey(null)]);

        $this->assertMailCount(1);
        $this->assertMailContainsText('Name: Work laptop');
        $this->assertMailContains('If it was not you');
        $this->assertStringNotContainsString('Device:', TestEmailTransport::getMessages()[0]->getBodyText());
        $this->assertStringNotContainsString('Manage your passkeys', TestEmailTransport::getMessages()[0]->getBodyText());
    }

    /**
     * @return void
     */
    public function testRegisteredEscapesHtml(): void
    {
        $passkey = $this->passkey(null);
        $passkey->name = '<b>x</b>';

        (new PasskeyMailer())->send('registered', [$this->user(), $passkey]);

        $this->assertMailContainsHtml('&lt;b&gt;x&lt;/b&gt;');
    }

    /**
     * @return \CakePasskeys\Service\ConventionUserAdapter
     */
    private function user(): ConventionUserAdapter
    {
        return new ConventionUserAdapter(new Entity(['id' => 1, 'email' => 'alice@example.com', 'name' => 'Alice']));
    }

    /**
     * @param string|null $label
     *
     * @return \CakePasskeys\Model\Entity\Passkey
     */
    private function passkey(?string $label): Passkey
    {
        return new Passkey([
            'id' => 7,
            'user_id' => 1,
            'name' => 'Work laptop',
            'aaguid_label' => $label,
            'created' => new DateTime('2026-11-05 10:00:00'),
        ], ['guard' => false]);
    }
}
