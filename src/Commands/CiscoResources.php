<?php
namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;

class CiscoResources implements CommandInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * Path to snmpget binary
     * @var string
     */
    private $snmpGetBin;

    public function __construct(LoopInterface $loop, $snmpGetBin = '/usr/bin/snmpget')
    {
        $this->loop = $loop;
        $this->snmpGetBin = $snmpGetBin;
    }

    public function getProducableMetrics(array $attributes): array
    {
        return [
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'uptime'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'memory'),
            new ResultMetric(ResultMetric::TYPE_GAUGE, 'cpu'),
        ];
    }

    public function run(Check $check): PromiseInterface
    {
        $lastResult = $check->getResult();
        // set default metrics
        $attributes = \array_merge([
            'ip'                      => '',
            'snmp_version'            => '2c',
            'cpu_warn_threshold'      => '85',
            'cpu_crit_threshold'      => '90',
            'mem_warn_threshold'      => '85',
            'mem_crit_threshold'      => '90'
        ], $check->getAttributes());

        $mibs = array(
            'cpu' => 'enterprises.9.2.1.57.0',
            'mem_free' => 'enterprises.9.9.48.1.1.1.6.1',
            'mem_used' => 'enterprises.9.9.48.1.1.1.5.1',
            'uptime' => 'sysUpTimeInstance'
        );

        $command = $this->snmpGetBin . " -OQs -v {$attributes['snmp_version']} -c {$attributes['snmp_read_community']} {$attributes['ip']} ";
        foreach ($mibs as $key => $value) {
            $command .=  "$value ";
        }
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);

        $deferred = new \React\Promise\Deferred();

        $stdoutBuffer = '';
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred,
            $command, $attributes, &$stdoutBuffer, $mibs) {
            if ($exitCode == 1) {
                $reason = "SNMP command ($command) returned status of 1";
                $deferred->resolve(new Result(Result::STATE_CRIT, $reason));
                return;
            }

            $state = Result::STATE_OK;
            $state_reason = '';
            $metrics = [];

            $mem_used = $mem_free = null;
            foreach (\preg_split('/[\r\n]+/', trim($stdoutBuffer)) as $item) {
                list($key, $val) = \preg_split('/\s*=\s*/', trim($item));

                if (($key = \array_search($key, $mibs)) !== false) {
                    if ($key == 'uptime') {
                        // uptime value probably needs converted from string to int
                        $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'uptime', $val);
                    } else if ($key == 'mem_used' || $key == 'mem_free') {
                        if ($key == 'mem_used') {
                            $mem_used = $val;
                        } else if ($key == 'mem_free') {
                            $mem_free = $val;
                        }
                        if ($mem_free !== null && $mem_used !== null) {
                            $prc = ($mem_used / ($mem_free + $mem_used)) * 100.0;
                            if ($prc > $attributes['mem_warn_threshold']) {
                                $state = Result::STATE_WARN;
                                $state_reason = 'Hit warning threshold for memory usage.';
                            }
                            if ($prc > $attributes['mem_crit_threshold']) {
                                $state = Result::STATE_CRIT;
                                $state_reason = 'Hit critical threshold for memory usage.';
                            }
                            $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'memory', $prc);
                        }
                    } else if ($key == 'cpu') {
                        if ($val > $attributes['cpu_warn_threshold']) {
                            $state = Result::STATE_WARN;
                            $state_reason = 'Hit warning threshold for cpu usage.';
                        }
                        if ($val > $attributes['cpu_crit_threshold']) {
                            $state = Result::STATE_CRIT;
                            $state_reason = 'Hit critical threshold for cpu usage.';
                        }
                        $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, 'cpu', $val);
                    }
                }
            }

            $deferred->resolve(new Result($state, $state_reason, $metrics));
        });

        return $deferred->promise();
    }
}
