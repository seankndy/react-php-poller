<?php
namespace SeanKndy\Poller\Tests\Results\Handlers;

use SeanKndy\Poller\Tests\TestCase;
use SeanKndy\Poller\Results\Handlers\RRDCacheD;
use SeanKndy\Poller\Results\Metric;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Checks\Check;
use Psr\Log\NullLogger;

class RRDCacheDTest extends TestCase
{
    const TMP_RRD_DIR = '/tmp/rrd-test';
    const NUM_CHECKS = 100;

    public function setUp() : void
    {
        if (!\is_dir(self::TMP_RRD_DIR)) {
           \mkdir(self::TMP_RRD_DIR);
        }
    }

    public function testInitilizaitonOfFileStructure()
    {
        $loop = \React\EventLoop\Factory::create();
        $handler = new RRDCacheD($loop, new NullLogger(), self::TMP_RRD_DIR, '/usr/bin/rrdtool', '/var/run/rrdcached.sock');

        $checkResultPairs = [];
        for ($i = 1; $i <= self::NUM_CHECKS; $i++) {
            $result = new Result(Result::STATE_OK, '', [
                new Metric(Metric::TYPE_GAUGE, 'test', 69)
            ]);
            $check = new Check($i, null, [], \time(), 300);
            $checkResultPairs[] = [$check, $result];
        }

        foreach ($checkResultPairs as $pair) {
            list($check, $result) = $pair;
            $this->invokeMethod($handler, 'initFileStructure', [$check, $result]);
        }

        $that = $this;
        $loop->addTimer(1.0, function() use ($checkResultPairs, $handler, $that, $loop) {
            $missingDirs = [];
            $missingFiles = [];

            foreach ($checkResultPairs as $pair) {
                list($check, $result) = $pair;
                $rrdFile = $this->invokeMethod($handler, 'getRrdFilePath', [$check, $result->getMetrics()[0]]);
                $dir = dirname($rrdFile);
                if (!\is_dir($dir)) {
                    $missingDirs[] = $dir;
                } else if (!\file_exists($rrdFile)) {
                    $missingFiles[] = $rrdFile;
                }
            }
            $that->assertCount(0, $missingDirs);
            $that->assertCount(0, $missingFiles);
        });

        $loop->run();
    }

    public function tearDown() : void
    {
        $this->rrmdir(self::TMP_RRD_DIR);
    }

    protected function rrmdir($dir)
    {
        $files = \array_diff(\scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (\is_dir("$dir/$file")) ? $this->rrmdir("$dir/$file") : \unlink("$dir/$file");
        }
        return \rmdir($dir);
    }

    protected function invokeMethod(&$object, $methodName, array $parameters = array())
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
