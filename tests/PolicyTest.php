<?php

use PHPUnit\Framework\TestCase;

class PolicyTest extends TestCase {
  public function testDeny() {
    $policy = new Policy();
    $policy->deny();
    $this->assertEquals(Policy::EFFECT_DENY, $policy->effect);
  }

  public function testDefaultIsAllow() {
    $policy = new Policy();
    $this->assertEquals(Policy::EFFECT_ALLOW, $policy->effect);
    $this->assertEquals(true, $policy->hasAccess());
  }
}
