<?php
echo "loaded IAMTest\n";

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../lib/iam.php';

class PolicyTest extends PHPUnit_Framework_TestCase {
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
