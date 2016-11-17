<?php

class ConditionEvaluatorTest extends PHPUnit_Framework_TestCase {
  private function assertEvaluates($context, $conditions) {
    $eval = new ConditionEvaluator();
    $result = $eval->evaluate($context, $conditions);

    $this->assertTrue($result);
  }

  private function assertNotEvaluates($context, $conditions) {
    $eval = new ConditionEvaluator();
    $result = $eval->evaluate($context, $conditions);

    $this->assertFalse($result);
  }

  public function testStringEquals() {
    $context = ['u' => 'abc'];
    $this->assertEvaluates($context, ["StringEquals" => ['u' => 'abc']]);
    $this->assertEvaluates($context, ["StringEquals" => ['u' => ['abc']]]);
    $this->assertEvaluates($context, ["StringEquals" => ['u' => ['bca', 'abc']]]);

    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => 'cba']]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => 'a*']]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => ['cba']]]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => ['bca', 'dcf']]]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => true]]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u' => false]]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['a' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['u*' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringEquals" => ['username' => 'abc']]);
  }

  public function testStringLike() {
    $context = ['u' => 'abc'];
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'abc']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => ['abc']]]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => ['bca', 'abc']]]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'a*']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'abc*']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'a*c']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => '*c']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'a?c']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => 'a??']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => '??c']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => '*']]);
    $this->assertEvaluates($context, ["StringLike" => ['u' => '???']]);

    $this->assertNotEvaluates($context, ["StringLike" => ['u' => 'cba']]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => ['cba']]]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => ['bca', 'dcf']]]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => true]]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => false]]);
    $this->assertNotEvaluates($context, ["StringLike" => ['a' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u*' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringLike" => ['username' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => '??']]);
    $this->assertNotEvaluates($context, ["StringLike" => ['u' => '????']]);
  }

  public function testBoolCondition() {
    $context = ['authz::mfa' => true];
    $this->assertEvaluates($context, ["Bool" => ['authz::mfa' => true]]);
    $this->assertNotEvaluates($context, ["Bool" => ['authz::mfa' => false]]);
  }

  # If "Bool" is used with a non-bool, it always evaluates to false.
  public function testBoolConditionWrongType() {
    $context = ['authz::mfa' => 'yes'];
    $this->assertNotEvaluates($context, ["Bool" => ['authz::mfa' => true]]);
    $this->assertNotEvaluates($context, ["Bool" => ['authz::mfa' => false]]); 
  }

  public function testConditionStringMatchWorks() {
    $context = array(
    	'aws:CurrentTime' => '2016-11-11T11:12:13Z',
    	'aws::username' => 'admin',
    	'aws::host' => 'localhost',
    	'authz::mfa' => true,
        's3::bucket' => 'demo-site',
        's3::resource' => ''
    );

    $conditions = [
    	"StringEquals" => [
    		"aws::username" => ["admin", "root"],
    	],
    	"StringLike" => [
    		"aws::username" => "a*",
    		"aws::host" => "loc*st"
    	],
    	"Bool" => [
    		"authz::mfa" => true,
    	],
		"DateGreaterThan" => [
			"aws:CurrentTime" => "2013-08-16T12:00:00Z"
		],
		# Expired in 2022
		"DateLessThan" => [
			"aws:CurrentTime" => "2022-12-24T23:59:59Z"
		]
    ];

    $this->assertEvaluates($context, $conditions);

    # change one thing, and it breaks
    $context['aws::username'] = 'root';
    $this->assertNotEvaluates($context, $conditions);    
  }
}