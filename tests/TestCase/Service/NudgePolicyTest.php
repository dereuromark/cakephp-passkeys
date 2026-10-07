<?php

declare(strict_types=1);

namespace CakePasskeys\Test\TestCase\Service;

use Cake\Core\Configure;
use Cake\TestSuite\TestCase;
use CakePasskeys\Service\NudgePolicy;

class NudgePolicyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Configure::write('CakePasskeys.enabled', true);
        Configure::write('CakePasskeys.nudge', ['enabled' => true, 'redisplayAfterDays' => 14]);
    }

    public function testShownForUserWithoutPasskey(): void
    {
        $this->assertTrue((new NudgePolicy())->shouldShow(0));
    }

    public function testHiddenWhenUserHasPasskey(): void
    {
        $this->assertFalse((new NudgePolicy())->shouldShow(1));
    }

    public function testHiddenWhenNudgeDisabled(): void
    {
        Configure::write('CakePasskeys.nudge.enabled', false);

        $this->assertFalse((new NudgePolicy())->shouldShow(0));
    }

    public function testHiddenWhenPluginDisabled(): void
    {
        Configure::write('CakePasskeys.enabled', false);

        $this->assertFalse((new NudgePolicy())->shouldShow(0));
    }
}
