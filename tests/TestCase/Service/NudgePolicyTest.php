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
        Configure::write('CakePasskeys.maxPerUser', 5);
    }

    public function testShownForUserWithoutPasskey(): void
    {
        $this->assertTrue((new NudgePolicy())->shouldShow(0));
    }

    public function testHiddenWhenUserHasPasskey(): void
    {
        $this->assertFalse((new NudgePolicy())->shouldShow(1));
    }

    public function testShownWhenEveryPasskeyIsDeviceBound(): void
    {
        $policy = new NudgePolicy();

        $this->assertTrue($policy->shouldShow(2, 2));
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, $policy->reason(2, 2));
        $this->assertSame(NudgePolicy::REASON_NO_PASSKEY, $policy->reason(0));
    }

    public function testHiddenWhenOnePasskeyMaySync(): void
    {
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, (new NudgePolicy())->reason(2, 2));
        $this->assertNull((new NudgePolicy())->reason(2, 1));
    }

    public function testDeviceBoundNudgeHiddenAtPasskeyLimit(): void
    {
        Configure::write('CakePasskeys.maxPerUser', 2);
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, (new NudgePolicy())->reason(1, 1));
        $this->assertNull((new NudgePolicy())->reason(2, 2));

        Configure::write('CakePasskeys.maxPerUser', 0);
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, (new NudgePolicy())->reason(2, 2));
    }

    public function testDeviceBoundNudgeCanBeTurnedOff(): void
    {
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, (new NudgePolicy())->reason(1, 1));

        Configure::write('CakePasskeys.nudge.deviceBoundOnly', false);
        $this->assertNull((new NudgePolicy())->reason(1, 1));
        $this->assertSame(NudgePolicy::REASON_NO_PASSKEY, (new NudgePolicy())->reason(0));
    }

    public function testDeviceBoundNudgeHiddenWhenNudgeDisabled(): void
    {
        $this->assertSame(NudgePolicy::REASON_DEVICE_BOUND_ONLY, (new NudgePolicy())->reason(1, 1));

        Configure::write('CakePasskeys.nudge.enabled', false);
        $this->assertNull((new NudgePolicy())->reason(1, 1));
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
