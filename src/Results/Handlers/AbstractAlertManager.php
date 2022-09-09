<?php

namespace SeanKndy\Poller\Results\Handlers;

use React\Promise\PromiseInterface;
use SeanKndy\Poller\Checks\Check;
use SeanKndy\Poller\Checks\Incident;
use SeanKndy\Poller\Results\Result;
use React\EventLoop\LoopInterface;
use React\HttpClient\Client;
use React\HttpClient\Response;
use Psr\Log\LoggerInterface;

/**
 * Send incidents to AlertManager API (react-php-alertmanager)
 * Abstract so that class user can implement buildRequestBody()
 */
abstract class AbstractAlertManager implements HandlerInterface
{
    protected LoopInterface $loop;

    protected LoggerInterface $logger;

    protected string $alertmanagerApiUrl;

    protected ?string $apiUser;

    protected ?string $apiPass;


    public function __construct(
        LoopInterface $loop,
        LoggerInterface $logger,
        string $alertmanagerApiUrl,
        ?string $apiUser = null,
        ?string $apiPass = null
    ) {
        $this->loop = $loop;
        $this->logger = $logger;
        $this->alertmanagerApiUrl = $alertmanagerApiUrl;
        $this->apiUser = $apiUser;
        $this->apiPass = $apiPass;
    }

    /**
     * {@inheritDoc}
     */
    public function process(Check $check, Result $result, Incident $newIncident = null): PromiseInterface
    {
        if ($newIncident || $check->getIncident()) {
            if ($params = $this->buildAlert($check, $result, $newIncident)) {
                return $this->httpPost($params);
            } else {
                return \React\Promise\reject(
                    new \RuntimeException("buildAlert() returned nothing!")
                );
            }
        } else {
            return \React\Promise\resolve([]);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function mutate(Check $check, Result $result, Incident $newIncident = null): PromiseInterface
    {
        return \React\Promise\resolve([]);
    }

    /**
     * This should return an array for the alert being gnerated that when
     * json-encoded meets the specifications of react-php-alertmanager
     *
     * @param Check $check Associated Check object
     * @param Result $result Associated Result object
     * @param Incident $newIncident New incident
     */
    abstract protected function buildAlert(Check $check, Result $result, Incident $newIncident): array;

    /**
     * Post to alertmanager's API
     */
    private function httpPost(array $params): PromiseInterface
    {
        $deferred = new \React\Promise\Deferred();
        $client = new Client($this->loop);
        $jsonParams = \json_encode($params);
        $headers = [
            'Content-Type' => 'application/json',
            'Content-Length' => strlen($jsonParams)
        ];
        if ($this->apiUser && $this->apiPass) {
            $headers['Authorization'] = 'Basic ' .
                \base64_encode($this->apiUser.':'.$this->apiPass);
        }
        $request = $client->request('POST', $this->alertmanagerApiUrl, $headers);
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
                if (isset($respData->status) && $respData->status == 'success') {
                    $deferred->resolve($respData);
                } else {
                    $deferred->reject(new \Exception("Failed to POST to AlertManager API"));
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
