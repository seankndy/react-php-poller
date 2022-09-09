<?php
namespace SeanKndy\Poller\Results\Handlers;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use SeanKndy\Poller\Results\Metric;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use React\HttpClient\Response;
use Psr\Log\LoggerInterface;
/**
 * Integration with PagerTree
 */
class PagerTree implements HandlerInterface
{
    /**
     * @var LoopInterface
     */
    private $loop;
    /**
     * @var LoggerInterface
     */
    private $logger;
    /**
     * @var string
     */
    private $apiKey;
    /**
     * @var string
     */
    private $apiSecret;


    public function __construct(LoopInterface $loop, LoggerInterface $logger,
        string $apiKey, string $apiSecret)
    {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->apiKey = $apiKey;
        $this->apiSecret = $apiSecret;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface
    {
        $incident = $check->getIncident();

        $promises = [];
        if ($incident && $incident->isResolved() && $incident->getExternalId()) {
            $url = 'https://api.pagertree.com/incident/' .
                $incident->getExternalId();
            $data = [
                'resolved' => $incident->getResolvedTime(),
                'status' => 'resolved'
            ];
            $promises[] = $this->sendApiRequest(
                $url, 'PUT', $data
            )->otherwise(function (\Exception $e) {
                $this->logger->error("Failed to resolve incident: " .
                    $e->getMessage());
            });
        }

        return \React\Promise\all($promises);
    }

    /**
     * {@inheritDoc} Create new incidents on PagerTree within this method
     * so that we can update $newIncident with the ID returned from PagerTree.
     */
    public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface
    {
        if ($newIncident) {
            $checkMeta = $check->getMeta();
            $url = 'https://api.pagertree.com/incident';
            $title = Result::stateIntToString($newIncident->getToState()) . ' // ' .
                    $checkMeta['port_path'];
            if (strlen($title) > 70) {
                $title = substr($title, 0, 67) . '...';
            }
            $data = [
                'title' => $title,
                'description' => Result::stateIntToString($newIncident->getFromState()) . '->' .
                    Result::stateIntToString($newIncident->getToState()) . ' // ' .
                    $checkMeta['port_path'] . '[' . $checkMeta['port_desc'] . '] // ' .
                    $checkMeta['service_template'] . ' // ' . $checkMeta['ip'] . ' // ' .
                    \date('Y-m-d H:i:s', $result->getTime()),
                'd_team_id' => $checkMeta['pagertree_team_id'],
                'source_id' => 'usr_B13skOruE'
            ];

            return $this->sendApiRequest(
                $url, 'POST', $data
            )->then(function ($resp) use ($newIncident) {
                $newIncident->setExternalId($resp->id);
                return [];
            }, function (\Exception $e) {
                throw new \Exception("Failed to create incident: " .
                    $e->getMessage());
            });
        } else {
            return \React\Promise\resolve([]);
        }
    }

    /**
     * Send API request to Pager Tree
     *
     */
    private function sendApiRequest(string $url, string $method, array $params)
    {
        $deferred = new \React\Promise\Deferred();
        $client = new Client($this->loop);
        $jsonParams = \json_encode($params);
        $request = $client->request($method, $url, [
            'Content-Type' => 'application/json',
            'Content-Length' => strlen($jsonParams),
            'x-api-key' => $this->apiKey,
            'x-api-secret' => $this->apiSecret
        ]);
        $request->on('response', function (Response $response) use ($deferred) {
            if (substr($response->getCode(), 0, 1) != '2') {
                $deferred->reject(new \Exception("Non-2xx response code: " .
                    $response->getCode()));
                $response->close();
                return;
            }
            $respBody = '';
            $response->on('data', function ($chunk) use (&$respBody) {
                $respBody .= $chunk;
            });
            $response->on('end', function() use (&$respBody, $deferred) {
                $respData = \json_decode($respBody);
                if (isset($respData->errors)) {
                    $errors = \array_map(function ($errObj) {
                        return $errObj->message;
                    }, $respData->errors);
                    $deferred->reject(new \Exception("Error(s) returned from PagerTree: " .
                        implode(' ;; ', $errors)));
                } else {
                    $deferred->resolve($respData);
                }
            });
        });
        $request->on('error', function (\Throwable $e) use ($deferred) {
            $deferred->reject($e);
        });
        $request->end($jsonParams);
        return $deferred->promise();
    }
}
