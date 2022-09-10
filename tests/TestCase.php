<?php
namespace SeanKndy\Poller\Tests;

use PHPUnit\Framework\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function createCallableMock()
    {
        //return $this->createMock(CallableStub::class);
        return $this->getMockBuilder(CallableStub::class)->getMock();
    }
    protected function expectCallableOnce()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke');
        return $mock;
    }
    protected function expectCallableOnceWith(...$value)
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->once())
            ->method('__invoke')
            ->with(...$value);
        return $mock;
    }
    protected function expectCallableNever()
    {
        $mock = $this->createCallableMock();
        $mock
            ->expects($this->never())
            ->method('__invoke');
        return $mock;
    }
}
