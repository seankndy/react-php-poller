<?php
namespace SeanKndy\Poller\Tests\Commands;

use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Commands\MySQL;
use SeanKndy\Poller\Tests\TestCase;

class MySQLTest extends TestCase
{
    const HOST = 'localhost';
    const USER = 'vcn';
    const PASS = 'xxx';
    const PORT = 3306;
    const DB = 'mysql';

    public function testRun()
    {
        $loop = \React\EventLoop\Factory::create();
        $command = new MySQL($loop);

        $attributes = [
            'ip' => self::HOST,
            'port' => self::PORT,
            'user' => self::USER,
            'password' => self::PASS,
            'db' => self::DB
        ];
        $check = new Check(1234, $command, $attributes, 10);
        $command->run($check)->then(
            $this->expectCallableOnceWith($this->isInstanceOf(Result::class)),
            $this->expectCallableNever()
        );
        $loop->run();
    }
}
