<?php
namespace SeanKndy\Poller\Commands;

use SeanKndy\Poller\Checks\Check;
use React\EventLoop\LoopInterface;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
/**
 * Ookla official speedtest CLI
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
    private $speedTestBin = '';

    public function __construct(LoopInterface $loop, $speedTestBin = '/usr/local/bin/speedtest')
    {
        $this->loop = $loop;

        if (\file_exists($speedTestBin)) {
            $this->speedTestBin = $speedTestBin;
        } else {
            throw new \RuntimeException("SpeedTest++ binary '$speedTestBin' could not be found.");
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
            'server' => '', // host:port
            'download_threshold' => 0, // Mbit/sec
            'upload_threshold' => 0, // Mbit/sec
            'ping_threshold' => 0, // milliseconds
            'jitter_threshold' => 0, // milliseconds
            'loss_threshold' => 20 // %
        ], $check->getAttributes());

        $command = $this->speedTestBin . " -f json -u bps";
        if ($attributes['server']) {
            $command .= " -s {$attributes['server']}";
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
                $stateReason = 'Invalid output from speedtest command.';
            } else {
                $downloadMbits = $stResult->download->bandwidth / 1000 / 1000;
                $uploadMbits = $stResult->upload->bandwidth  / 1000 / 1000;

                if ($attributes['download_threshold'] && $downloadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "Download dropped below threshold.";
                } else if ($attributes['upload_threshold'] && $uploadMbits < $attributes['download_threshold']) {
                    $state = Result::STATE_CRIT;
                    $stateReason = "Upload dropped below threshold.";
                } else if ($attributes['ping_threshold'] && $stResult->ping->latency > $attributes['ping_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "Ping exceeded threshold.";
                } else if ($attributes['jitter_threshold'] && $stResult->ping->jitter > $attributes['jitter_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "Jitter exceeded threshold.";
                } else if ($attributes['loss_threshold'] >= 0 && $stResult->packetLoss > $attributes['loss_threshold']) {
                    $state = Result::STATE_WARN;
                    $stateReason = "Loss exceeded threshold.";
                } else {
                    $state = Result::STATE_OK;
                    $stateReason = '';
                }

                $result = new Result($state, $stateReason);
                $result->setMetrics([
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'latency', $stResult->ping->latency
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'jitter', $stResult->ping->jitter
                    ),
                    new ResultMetric(
                        ResultMetric::TYPE_GAUGE, 'loss', $stResult->packetLoss
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
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'latency'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'jitter'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'loss'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'download_mbps'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'upload_mbps')
        ];
    }
}
