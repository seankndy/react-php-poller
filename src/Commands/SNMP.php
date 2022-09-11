<?php

namespace SeanKndy\Poller\Commands;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric as ResultMetric;
use React\EventLoop\LoopInterface;

//define('DEBUG', true);
//define('DEBUG_IP', '209.193.82.59'); //not optional
//define('DEBUG_SNMP_IF_ID', '513');

class SNMP implements CommandInterface
{
    private LoopInterface $loop;

    private string $snmpGetBin;

    /**
     * @throws \RuntimeException
     */
    public function __construct(LoopInterface $loop, $snmpGetBin = '/usr/bin/snmpget')
    {
        $this->loop = $loop;

        if (!\file_exists($snmpGetBin)) {
            throw new \RuntimeException("snmpget binary '$snmpGetBin' not found!");
        }

        $this->snmpGetBin = $snmpGetBin;
    }

    public function run(Check $check): PromiseInterface
    {
        $lastResult = $check->getResult();
        $start = microtime(true);
        // set default metrics
        $attributes = $this->mergeWithDefaultAttributes($check->getAttributes());

        // parse threshold values into new array
        $thresholds = [];
        foreach (['warning_max_thresholds', 'critical_max_thresholds', 'warning_min_thresholds', 'critical_min_thresholds'] as $keyName) {
            $thresholds[$keyName] = [];
            foreach ($attributes[$keyName] as $t) {
                if (\strpos($t, '=') === false) continue;
                list($key,$val) = \preg_split('/\s*=\s*/', $t);
                // strip 'foo:' from 'foo:bar'
                $key = \preg_replace('/^(.+):+(.+)$/', '$2', $key);

                if (($p = \strpos($val, '%')) !== false) {
                    $perc = \substr($val, 0, $p) / 100.0;
                    $thresholds[$keyName][$key] = \bcmul($this->humanSizeToBytes($attributes['port_speed']), $perc);
                } else {
                    $thresholds[$keyName][$key] = $this->humanSizeToBytes($val);
                }
            }
        }

        if (\strpos($attributes['snmp_if_id'], ',') !== false) {
            //special shit for adtrans and zhones.
            //example: '4018.vtuc, 4018.vtur'
            //example: '49, 500049'
            $snmpIfIds = \preg_split('/,\s*/', $attributes['snmp_if_id']);
        } else {
            $snmpIfIds = array($attributes['snmp_if_id']);
        }

        // check the snmp interface code
        $command = $this->snmpGetBin . " -r {$attributes['snmp_retries']} -t {$attributes['snmp_timeout']} -OQs";
        if ($attributes['snmp_output_numeric'] == 'true') {
            $command .= "b";
        }
        $command .= " -v {$attributes['snmp_version']} -c {$attributes['snmp_read_community']} {$attributes['ip']} ";

        $mibOutputMaps = array();
        foreach ($attributes['snmp_output_mib_maps'] as $key => $value) {
            if (\preg_match('/^(.+)\=(.+)$/', $value, $m)) {
                $mibOutputMaps[$m[1]] = $m[2];
            }
        }

        $renameLabels = array();
        foreach (['snmp_status_mibs', 'snmp_incremental_mibs', 'snmp_gauge_mibs'] as $keyName) {
            foreach ($attributes[$keyName] as $key => $value) {
                if (\preg_match('/^(.+)\=(.+)$/', $value, $m)) {
                    $mib = $m[1];
                    $attributes[$keyName][$key] = \preg_replace('/^(.+):+(.+)$/', '$2', $mib);
                    $renameLabels[$attributes[$keyName][$key]] = $m[2];
                } else {
                    $mib = $value;
                }

                if (($pos = \strpos($mib, ';')) !== false) {
                    $i = \substr($mib, $pos+1, strlen($mib));
                    $mib = \substr($mib, 0, $pos);
                    $snmpIfId = isset($snmpIfIds[$i]) ? $snmpIfIds[$i] : $snmpIfIds[0];
                } else {
                    $snmpIfId = $snmpIfIds[0];
                }

                if (\strpos($mib, '.') === false) {
                    $command .=  "'$mib.$snmpIfId' ";
                } else {
                    $command .=  "'$mib' ";
                }
            }
        }
        //$command .= "2>/dev/null";

        if (defined('DEBUG')) {
            if ($attributes['ip'] == DEBUG_IP) {
                if(!defined('DEBUG_SNMP_IF_ID') || DEBUG_SNMP_IF_ID == $snmpIfId){
                    echo "-----------------------------------------------------------\n";
                    print_r($snmpIfIds);
                    print_r($attributes);
                    print_r($renameLabels);
                    echo "$mib / $snmpIfId\n";
                    echo $command . "\n";
                }
            }
        }

        $deferred = new \React\Promise\Deferred();
        $stdoutBuffer = '';

        //$process = new \React\ChildProcess\Process($command, null, null, $pipes);
        $process = new \React\ChildProcess\Process($command);
        $process->start($this->loop);
        $process->stdout->on('data', function ($chunk) use (&$stdoutBuffer) {
            $stdoutBuffer .= $chunk;
        });
        $process->on('exit', function($exitCode, $termSignal) use ($deferred, $lastResult, $command,
            $attributes, &$stdoutBuffer, $mibOutputMaps, $thresholds, $renameLabels, $snmpIfIds) {
            if ($exitCode == 1) {
               $deferred->resolve(new Result(Result::STATE_UNKNOWN, 'snmpget command returned status ' . $exitCode));
               return;
            }

            $postProcessValues = array();
            foreach ($attributes['post_process_values'] as $v) {
                if (\strpos($v, '=') === false) continue;
                list($key,$val) = \preg_split('/\s*=\s*/', $v);
                // strip 'foo:' from 'foo:bar'
                $key = \preg_replace('/^(.+):+(.+)$/', '$2', $key);

                $postProcessValues[$key] = $val;
            }

            $curTimestamp = time();
            $state = Result::STATE_UNKNOWN;
            $stateReason = '';

            $metrics = [];
            foreach (\preg_split('/[\r\n]+/', \trim($stdoutBuffer)) as $item) {
                $splitItem = \preg_split('/\s*=\s*/', trim($item));
                if (count($splitItem) != 2) {
                    $deferred->resolve(new Result(Result::STATE_UNKNOWN, 'snmpget command returned unknown line: ' . $item));
                    return;
                }
                list($key, $val) = $splitItem;

                if (isset($mibOutputMaps[$key])) {
                    $key = $mibOutputMaps[$key];
                } else {
                    if (($dotpos = \strpos($key, '.')) !== false) {
                        $snmpIfId = \substr($key, $dotpos+1, \strlen($key));
                        $key_noindex = \substr($key, 0, $dotpos);
                    } else {
                        $key_noindex = $key;
                    }
                    if (in_array($key_noindex, \preg_replace('/\;[0-9]+$/', '', \array_merge($attributes['snmp_status_mibs'], $attributes['snmp_incremental_mibs'], $attributes['snmp_gauge_mibs'])))) {
                        $key = $key_noindex;
                    }
                }

                if (is_array($snmpIfIds) && ($snmpIfIds_index = array_search($snmpIfId, $snmpIfIds)) !== false && isset($renameLabels[$key_noindex . ';' . $snmpIfIds_index])) {
                    $label = $renameLabels[$key_noindex . ';' . $snmpIfIds_index];
                } else {
                    $label = isset($renameLabels[$key]) ? $renameLabels[$key] : $key;
                }

                $postProcessValue = isset($postProcessValues[$key]) ? $postProcessValues[$key] : 1.0;

                if (defined('DEBUG')) {
                    if ($attributes['ip'] == DEBUG_IP) {
                        if (!defined('DEBUG_SNMP_IF_ID') || DEBUG_SNMP_IF_ID == $snmpIfId) {
                            echo "$item = $key / $key_noindex / $label\n";
                            \print_r(\preg_replace('/\;[0-9]+$/', '', $attributes['snmp_guage_mibs']));
                        }
                    }
                }

                if (\in_array($key, \preg_replace('/\;[0-9]+$/', '', $attributes['snmp_status_mibs']))) {
                    if (\strtolower($attributes['status_up_value']) == \strtolower($val)) {
                        if ($state == Result::STATE_UNKNOWN) {
                            $state = Result::STATE_OK;
                        }

                        // state is up for this key
                        $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, $label, 1);
                        //$this->insertResultData(new ServiceCommandResultData(1, null, $label));
                    } else if (\strtolower($attributes['status_down_value']) == \strtolower($val)) {
                        $state = Result::STATE_CRIT;
                        $stateReason = 'Port status down';

                        // state is down for this key
                        $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, $label, 0);
                        //$this->insertResultData(new ServiceCommandResultData(0, null, $label));
                    }
                } else if (\in_array($key, \preg_replace('/\;[0-9]+$/', '', $attributes['snmp_gauge_mibs']))) {
                    if (\preg_match('/^(\-?[0-9\.]+) ([0-9\.]+).*/', $val, $m)) { // ill take this to mean [value] [multiplier]
                        $val = $m[1] * $m[2];
                    } else {
                        $val = \preg_replace('/[^0-9\.\-]/', '', $val);
                    }

                    // store gauge data for this key
                    //$this->insertResultData(new ServiceCommandResultData(\bcmul($val, $postProcessValue, 3), null, $label, false));
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, $label, \bcmul($val, $postProcessValue, 3));

                    if ($state == Result::STATE_CRIT) { // state is already critical, don't do any more value checks
                        continue;
                    } else if ($state == Result::STATE_UNKNOWN) {
                        $state = Result::STATE_OK;
                    }

                    $lbl = isset($renameLabels[$key]) ? $renameLabels[$key] : $key;

                    if (isset($thresholds['warning_min_thresholds'][$key])) {
                        if (\bccomp($val, $thresholds['warning_min_thresholds'][$key]) < 0) {
                            $state = Result::STATE_WARN;
                            $stateReason = 'Value (' . $val . ') hit min threshold (' . $thresholds['warning_min_thresholds'][$key] . ') for ' . $lbl;
                        }
                    }
                    if (isset($thresholds['warning_max_thresholds'][$key])) {
                        if (\bccomp($val, $thresholds['warning_max_thresholds'][$key]) > 0) {
                            $state = Result::STATE_WARN;
                            $stateReason = 'Value (' . $val . ') hit max threshold (' . $thresholds['warning_max_thresholds'][$key] . ') for ' . $lbl;
                        }
                    }
                    if (isset($thresholds['critical_min_thresholds'][$key])) {
                        if (\bccomp($val, $thresholds['critical_min_thresholds'][$key]) < 0) {
                            $state = Result::STATE_CRIT;
                            $stateReason = 'Value (' . $val . ') hit min threshold (' . $thresholds['critical_min_thresholds'][$key] . ') for ' . $lbl;
                        }
                    }
                    if (isset($thresholds['critical_max_thresholds'][$key])) {
                        if (\bccomp($val, $thresholds['critical_max_thresholds'][$key]) > 0) {
                            $state = Result::STATE_CRIT;
                            $stateReason = 'Value (' . $val . ') hit max threshold (' . $thresholds['critical_max_thresholds'][$key] . ') for ' . $lbl;
                        }
                    }
                } else if (\in_array($key, $attributes['snmp_incremental_mibs'])) {
                    //$this->insertResultData(new ServiceCommandResultData(\bcmul($val, $postProcessValue, 3), null, $label, true));
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_COUNTER, $label, \bcmul($val, $postProcessValue, 3));

                    if ($state == Result::STATE_CRIT) { // state is already critical, don't do any more value checks
                        continue;
                    }

                    if ($lastResult instanceof Result) {
                        foreach ($lastResult->getMetrics() as $metric) {
                            if ($metric->getName() != $key) {
                                continue;
                            }

                            $timeDiff = $curTimestamp - $metric->getTime();
                            if ($timeDiff == 0) { // times are the same, skip it
                                continue;
                            }

                            // check if counter rolled
                            if (\bccomp($val, $metric->getValue()) < 0) {
                                if ($metric->getValue() > pow(2, 32)-1) { // high speed counter
                                    if ($metric->getValue() > pow(2, 48)-1) {
                                        $maxVal = pow(2, 64)-1;
                                    } else {
                                        $maxVal = pow(2, 48)-1;
                                    }
                                } else {
                                    $maxVal = pow(2, 32)-1;
                                }

                                $diff = \bcdiv(\bcadd(\bcsub($maxVal, $metric->getValue()), $val), $timeDiff);
                            } else {
                                $diff = \bcdiv(\bcsub($val, $metric->getValue()), $timeDiff);
                            }

                            $lbl = $renameLabels[$key] ?? $key;

                            if (isset($thresholds['warning_min_thresholds'][$key])) {
                                if (\bccomp($diff, $thresholds['warning_min_thresholds'][$key]) < 0) {
                                    $state = Result::STATE_WARN;
                                    $stateReason = 'Hit min threshold (' . $thresholds['warning_min_thresholds'][$key] . ') for ' . $lbl . '; Delta=' . $diff;
                                    //echo "{$options['ip']} {$options['port_speed']} WARN because $diff < warn min of {$thresholds['warning_min_thresholds'][$key]}\n";
                                }
                            }
                            if (isset($thresholds['warning_max_thresholds'][$key])) {
                                if (\bccomp($diff, $thresholds['warning_max_thresholds'][$key]) > 0) {
                                    $state = Result::STATE_WARN;
                                    $stateReason = 'Hit max threshold (' . $thresholds['warning_max_thresholds'][$key] . ') for ' . $lbl .  '; Delta=' . $diff;
                                    //echo "{$options['ip']} {$options['port_speed']} WARN because $diff > warn max of {$thresholds['warning_max_thresholds'][$key]}\n";
                                }
                            }
                            if (isset($thresholds['critical_min_thresholds'][$key])) {
                                if (\bccomp($diff, $thresholds['critical_min_thresholds'][$key]) < 0) {
                                    $state = Result::STATE_CRIT;
                                    $stateReason = 'Hit min threshold (' . $thresholds['critical_min_thresholds'][$key] . ') for ' . $lbl . '; Delta=' . $diff;
                                    //echo "{$options['ip']} {$options['port_speed']} CRIT because $diff < crit min of {$thresholds['critical_min_thresholds'][$key]}\n";
                                }
                            }
                            if (isset($thresholds['critical_max_thresholds'][$key])) {
                                if (\bccomp($diff, $thresholds['critical_max_thresholds'][$key]) > 0) {
                                    $state = Result::STATE_CRIT;
                                    $stateReason = 'Hit max threshold (' . $thresholds['critical_max_thresholds'][$key] . ') for ' . $lbl . '; Delta=' . $diff;
                                    //echo "{$options['ip']} {$options['port_speed']} CRIT because $diff > crit max of {$thresholds['critical_max_thresholds'][$key]}\n";
                                }
                            }

                            break;
                        }
                    }
                }
            }

            if (defined('DEBUG')) {
                if ($attributes['ip'] == DEBUG_IP) {
                    if (!defined('DEBUG_SNMP_IF_ID') || DEBUG_SNMP_IF_ID == $snmpIfId)
                        echo "-----------------------------------------------------------\n";
                }
            }

            $result = new Result($state, $stateReason, $metrics);
            $deferred->resolve($result);
        });

        return $deferred->promise();
    }

    public function getProducableMetrics(array $attributes): array
    {
        // set default metrics
        $attributes = $this->mergeWithDefaultAttributes($attributes);

        $metrics = [];
        foreach (['snmp_status_mibs','snmp_incremental_mibs','snmp_gauge_mibs'] as $attrKey) {
            foreach ($attributes[$attrKey] as $key => $value) {
                if (\preg_match('/^(.+)\=(.+)$/', $value, $m)) {
                    $label = $m[2];
                } else {
                    $label = $value;
                }

                if ($attrKey == 'snmp_incremental_mibs') {
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_COUNTER, $label);
                } else {
                    $metrics[] = new ResultMetric(ResultMetric::TYPE_GAUGE, $label);
                }
            }
        }

        return $metrics;
    }

    private function humanSizeToBytes($size): int
    {
        if (\preg_match('/^\-?([0-9\.]+)\s*([A-Za-z]+)$/', $size, $m)) {
            $num = $m[1];
            $sizeAbbrev = $m[2];

            switch ($sizeAbbrev) {
                case 'B':
                case 'Bytes':
                    return intval($num);
                case 'KB':
                case 'kB':
                case 'Kilobytes':
                    return intval($num * 1024);
                case 'MB':
                case 'Megabytes':
                    return intval($num * 1024 * 1024);
                case 'GB':
                case 'Gigabytes':
                    return intval($num * 1024 * 1024 * 1024);
                case 'TB':
                case 'Terabytes':
                    return intval($num * 1024 * 1024 * 1024 * 1024);

                case 'b':
                case 'bits':
                    return intval($num/8);
                case 'Kb':
                case 'kb':
                case 'kbit':
                case 'Kilobits':
                    return intval($num/8 * 1024);
                case 'm':
                case 'M':
                case 'Mb':
                case 'Mbit':
                case 'Megabits':
                    return intval($num/8 * 1024 * 1024);
                case 'Gb':
                case 'Gbit':
                case 'Gigabits':
                    return intval($num/8 * 1024 * 1024 * 1024);
                case 'Tb':
                case 'Tbit':
                case 'Terabits':
                    return intval($num/8 * 1024 * 1024 * 1024 * 1024);
            }

            return intval($num);
        } else {
            return intval(\preg_replace('/[^\-0-9\.]/', '', $size));
        }
    }

    private function mergeWithDefaultAttributes(array $attributes): array
    {
        return array_merge([
            'ip'                      => '',
            'snmp_status_mibs'        => array('ifAdminStatus','ifOperStatus'),
            'snmp_incremental_mibs'   => array('ifInOctets','ifOutOctets'),
            'snmp_gauge_mibs'          => [],
            // snmp_output_mib_maps is for when you pass in one mib, and it outputs another
            'snmp_output_mib_maps'    => [],
            'post_process_values'     => [],
            'snmp_version'            => '2c',
            'status_up_value'         => 'up',
            'status_down_value'       => 'down',
            'snmp_output_numeric'     => 'false',
            'warning_max_thresholds'  => array('ifInOctets=60%', 'ifOutOctets=60%'),
            'critical_max_thresholds' => array('ifInOctets=90%', 'ifOutOctets=90%'),
            'warning_min_thresholds'  => array('ifInOctets=2%', 'ifOutOctets=2%'),
            'critical_min_thresholds' => array('ifInOctets=1%', 'ifOutOctets=1%'),
            'ds_groups'                  => array('ifInOctets', 'ifOutOctets'),
            'ds_groups_min_max'       => [],
            'graph_disable'              => [],
            'snmp_retries'            => 3,
            'snmp_timeout'            => 3,
            'snmp_read_community'     => 'public',
            'port_speed'              => '1Gb'
        ], $attributes);
    }
}
