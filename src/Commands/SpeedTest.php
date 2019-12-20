<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use React\EventLoop\LoopInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
/**
 * speedtest-cli (speedtest.net) command
 *
 */
class SpeedTest implements CommandInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var string
     */
    private $speedTestCliBin = '';

    public function __construct(LoopInterface $loop, $speedTestCliBin = '/usr/bin/speedtest-cli')
    {
        $this->loop = $loop;

        if (\file_exists($speedTestCliBin)) {
            $this->speedTestCliBin = $speedTestCliBin;
        } else {
            throw new \RuntimeException("speedtest-cli binary '$speedTestCliBin' could not be found.");
        }
    }

    /**
     * {@inheritDoc}
     */
    public function run(Check $check)
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = array_merge([
            'server' => '',
            'timeout' => 10,
            'secure' => 0,
            'download_threshold' => 0, // Mbit/sec
            'upload_threshold' => 0, // Mbit/sec
            'ping_threshold' => 0 // milliseconds
        ], $check->getAttributes());

        $command = $this->speedTestCliBin . " --json --timeout {$attributes['timeout']}";
        if ($attributes['secure']) {
            $command .= " --secure";
        }
        if ($attributes['server']) {
            $command .= " --server {$attributes['server']}";
        }
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);

        $deferred = new \React\Promise\Deferred();
        $stdoutBuffer = '';
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $attributes, $command, &$stdoutBuffer) {
            if (!($stResult = \json_decode($stdoutBuffer))) {
                $state = Result::STATE_UNKNOWN;
                $stateReason = 'Invalid output from speedtest-cli command.';
            } else {
                $downloadMbits = $stResult->download / 1000 / 1000;
                $uploadMbits = $stResult->upload / 1000 / 1000;

                if ($attributes['download_threshold'] && $downloadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "Download dropped below threshold.";
                } else if ($attributes['upload_threshold'] && $uploadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "Upload dropped below threshold.";
                } else if ($attributes['ping_threshold'] && $stResult->ping > $attributes['ping_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "Ping exceeded threshold.";
                } else {
                    $state = Result::STATE_OK;
                    $stateReason = '';
                }

                $result = new Result($state, $stateReason);
                $result->setMetrics([
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'ping', $result->ping
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'download_mbps', $downloadMbits
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'upload_mbps', $uploadMbits
                    )
                ]);

                $deferred->resolve($result);
            }
        });

        return $deferred->promise();
    }

    /**
     * {@inheritDoc}
     */
    public function getProducableMetrics(array $attributes)
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'ping'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'download_mbps'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'upload_mbps')
        ];
    }
}
