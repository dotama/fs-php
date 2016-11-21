<?php

class ConditionEvaluatorTest extends PHPUnit_Framework_TestCase {
  private function assertEvaluates($context, $conditions) {
    $eval = new ConditionEvaluator();
    list($result, $reason) = $eval->evaluate($context, $conditions);

    $this->assertEquals([true, null], [$result, $reason]);
  }

  private function assertNotEvaluates($context, $conditions) {
    $eval = new ConditionEvaluator();
    list($result, $reason) = $eval->evaluate($context, $conditions);

    $this->assertFalse($result);
    $this->assertNotEquals("", $reason);
  }

  public function testResolve() {
    $e = new ConditionEvaluator();
    $this->assertEquals('abc', $e->resolve('a${b}c', ['b' => 'b']));
    $this->assertEquals('abbc', $e->resolve('a${b}${b}c', ['b' => 'b']));
    $this->assertEquals('abc', $e->resolve('${ab}${b}', ['ab' => 'ab', 'b' => 'c']));
    $this->assertEquals('abc', $e->resolve('${a}${b}${c}', ['a' => 'a', 'b' => 'b', 'c' => 'c']));
  }

  public function testVariableReplacement() {
    $context = [
        'user' => 'aaa',
        'a' => 'a',
        'b' => 'a',
        'c' => 'a',
    ];
    // the left handside is resolved once with a variable from the context
    // the right handside is replaced with variabled from the context, when enclosed in php-style curly braces
    $this->assertEvaluates($context, ["StringEquals" => ["user" => '${a}${b}${c}']]);
  }

  public function testStringNotEquals() {
    $context = ['u' => 'abc'];
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['u' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['u' => ['abc']]]);
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['u' => ['bca', 'abc']]]);

    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => 'cba']]);
    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => 'a*']]);
    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => ['cba']]]);
    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => ['bca', 'dcf']]]);
    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => true]]);
    $this->assertEvaluates($context, ["StringNotEquals" => ['u' => false]]);
    
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['a' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['u*' => 'abc']]);
    $this->assertNotEvaluates($context, ["StringNotEquals" => ['username' => 'abc']]);
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


    # If "Bool" is used with a non-bool, it always evaluates to false.
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