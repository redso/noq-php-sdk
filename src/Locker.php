<?php

namespace NoQ\RoomQ;

use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;

class Locker
{
    private $clientID;
    private $token;
    private $apiKey;
    private $httpClient;

    public function __construct($clientID, $apiKey, $token, $isDev)
    {
        $this->clientID = $clientID;
        $this->apiKey = $apiKey;
        $this->token = $token;

        $this->httpClient = new Client([
            'base_uri' => $isDev ? 'https://roomq-locker-dev.noq.guru' : 'https://roomq-locker.noq.guru',
        ]);
    }

    /**
     * @throws Exception|GuzzleException
     */
    public function findSessions($key, $value)
    {
        try {
            $response = $this->httpClient->request('GET', "/api/lockers/" . urlencode($this->clientID) . "/sessions",
                [
                    'headers' => [
                        'Api-Key' => $this->apiKey
                    ],
                    'query' => [
                        'key' => $key,
                        'value' => $value,
                    ]
                ]
            );
            return json_decode($response->getBody(), true)['sessions'];
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new Exception("Invalid api-key");
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function fetch()
    {
        try {
            $response = $this->httpClient->request('GET', "/api/lockers/" . urlencode($this->clientID) . "/sessions/" . urlencode($this->token),
                [
                    'headers' => [
                        'Api-Key' => $this->apiKey
                    ],
                ]
            );
            return json_decode($response->getBody(), true);
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new Exception("Invalid api-key");
            } else {
                throw $e;
            }
        }
    }

    /**
     * @param array $items
     * @param integer $expireAt
     * @throws Exception|GuzzleException
     */
    public function put(array $items, int $expireAt)
    {
        try {
            $this->httpClient->put("/api/lockers/" . urlencode($this->clientID) . "/sessions/" . urlencode($this->token),
                [
                    'headers' => [
                        'Api-Key' => $this->apiKey
                    ],
                    'json' => [
                        'data' => array_map(function ($item) {
                            return [
                                "key" => $item->key,
                                "value" => $item->value,
                                "limit" => $item->limit,
                                "kvLimit" => $item->kvLimit
                            ];
                        }, $items),
                        "expireAt" => $expireAt,
                    ]
                ]
            );
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new Exception("Invalid api-key");
            } else if ($e->getResponse()->getStatusCode() == 403) {
                throw new Exception("Reached limit");
            } else {
                throw $e;
            }
        }
    }

    /**
     * @param string $key
     * @throws GuzzleException
     * @throws Exception
     */
    public function delete(string $key)
    {
        try {
            $this->httpClient->delete("/api/lockers/" . urlencode($this->clientID) . "/sessions/" . urlencode($this->token) . "/" . $key,
                ['headers' =>
                    [
                        'Api-Key' => $this->apiKey
                    ],
                ]
            );
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new Exception("Invalid api-key");
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws Exception
     */
    public function flush()
    {
        try {
            $this->httpClient->delete("/api/lockers/" . urlencode($this->clientID) . "/sessions/" . urlencode($this->token),
                [
                    'headers' =>
                        [
                            'Api-Key' => $this->apiKey
                        ],
                ]
            );
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new Exception("Invalid api-key");
            } else {
                throw $e;
            }
        }
    }

}
