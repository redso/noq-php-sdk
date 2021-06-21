<?php

namespace NoQ\RoomQ;

use Exception;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\GuzzleException;
use NoQ\RoomQ\Exception\InvalidTokenException;
use NoQ\RoomQ\Exception\NotServingException;
use NoQ\RoomQ\Exception\QueueStoppedException;
use Ramsey\Uuid\Uuid;

class RoomQ
{
    private $clientID;
    private $jwtSecret;
    private $ticketIssuer;
    private $debug;
    private $tokenName;
    private $token;
    private $statusEndpoint;

    public function __construct($clientID, $jwtSecret, $ticketIssuer, $statusEndpoint = 'https://roomq-dev.noqstatus.com/api/rooms', $debug = false)
    {
        $this->clientID = $clientID;
        $this->jwtSecret = $jwtSecret;
        $this->ticketIssuer = $ticketIssuer;
        $this->debug = $debug;
        $this->statusEndpoint = $statusEndpoint;
        $this->tokenName = "be_roomq_t_{$clientID}";
        $this->token = $this->getToken();
    }

    /**
     * @return string|null
     */
    private function getToken(): ?string
    {
        $token = null;
        if (isset($_GET["noq_t"])) {
            $token = $_GET["noq_t"];
        } else if (isset($_COOKIE[$this->tokenName])) {
            $token = $_COOKIE[$this->tokenName];
        }
        return $token;
    }

    public function validate($returnURL, $sessionId): ValidationResult
    {
        $token = $this->token;
        $currentURL = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? "https" : "http") . "://$_SERVER[HTTP_HOST]$_SERVER[REQUEST_URI]";

        $needGenerateJWT = false;
        $needRedirect = false;

        if (is_null($token)) {
            $needGenerateJWT = true;
            $needRedirect = true;
            $this->debugPrint("no jwt");
        } else {
            $this->debugPrint("current jwt ${token}");

            try {
                JWT::$leeway = PHP_INT_MAX / 2;
                $payload = JWT::decode($token, $this->jwtSecret, array('HS256'));
                JWT::$leeway = 0;
                if ($sessionId && property_exists($payload, "session_id") && $payload->session_id !== $sessionId) {
                    $needGenerateJWT = true;
                    $needRedirect = true;
                    $this->debugPrint("session id not match");
                } elseif (property_exists($payload, "deadline") &&
                    $payload->deadline < time()) {
                    $needRedirect = true;
                    $this->debugPrint("deadline exceed");
                } elseif ($payload->type === "queue") {
                    $needRedirect = true;
                    $this->debugPrint("in queue");
                } elseif ($payload->type === "self-sign") {
                    $needRedirect = true;
                    $this->debugPrint("self sign token");
                }
            } catch (Exception $ex) {
                $needGenerateJWT = true;
                $needRedirect = true;
                $this->debugPrint("invalid secret");
            }

        }

        if ($needGenerateJWT) {
            $token = $this->generateJWT($sessionId);
            $this->debugPrint("generating new jwt {$token}");
            $this->token = $token;
        }

        setcookie($this->tokenName, $token, time() + (12 * 60 * 60), "");

        if ($needRedirect) {
            return $this->redirectToTicketIssuer($token, $returnURL ?? $currentURL);
        } else {
            return $this->enter($currentURL);
        }
    }

    public function getLocker($apiKey, $isDev = false): Locker
    {
        return new Locker($this->clientID, $apiKey, $this->token, $isDev);
    }

    /**
     * @throws GuzzleException
     * @throws QueueStoppedException|InvalidTokenException|NotServingException
     */
    public function extend($duration)
    {
        $backend = $this->getBackend();

        try {
            $httpClient = new Client();
            $response = $httpClient->post("https://{$backend}/queue/{$this->clientID}", [
                'json' => [
                    "action" => "beep",
                    "client_id" => $this->clientID,
                    "id" => $this->token,
                    "extend_serving_duration" => $duration * 60
                ]
            ]);
            $json = json_decode($response->getBody(), true);
            $newToken = $json["id"];
            $this->token = $newToken;
            setcookie($this->tokenName, $newToken, time() + (12 * 60 * 60), "");
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new InvalidTokenException();
            } else if ($e->getResponse()->getStatusCode() == 401) {
                throw new NotServingException();
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws QueueStoppedException|InvalidTokenException|NotServingException
     */
    public function getServing(): int
    {
        $backend = $this->getBackend();

        $httpClient = new Client();

        try {
            $response = $httpClient->get("https://{$backend}/rooms/{$this->clientID}/servings/{$this->token}");
            $json = json_decode($response->getBody(), true);
            return $json["deadline"];
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new InvalidTokenException();
            } else if ($e->getResponse()->getStatusCode() == 401) {
                throw new NotServingException();
            } else {
                throw $e;
            }
        }
    }

    /**
     * @throws GuzzleException
     * @throws QueueStoppedException|InvalidTokenException|NotServingException
     */
    public function deleteServing()
    {
        $backend = $this->getBackend();

        try {
            $httpClient = new Client();
            $response = $httpClient->post("https://{$backend}/queue/{$this->clientID}", [
                'json' => [
                    "action" => "delete_serving",
                    "client_id" => $this->clientID,
                    "id" => $this->token
                ]
            ]);
            json_decode($response->getBody(), true);
            JWT::$leeway = PHP_INT_MAX / 2;
            $payload = JWT::decode($this->token, $this->jwtSecret, array('HS256'));
            JWT::$leeway = 0;
            $token = $this->generateJWT($payload->session_id);
            $this->token = $token;
            setcookie($this->tokenName, $token, time() + (12 * 60 * 60), "");
        } catch (ClientException $e) {
            if ($e->getResponse()->getStatusCode() == 401) {
                throw new InvalidTokenException();
            } else if ($e->getResponse()->getStatusCode() == 401) {
                throw new NotServingException();
            } else {
                throw $e;
            }
        }
    }

    private function enter($currentUrl): ValidationResult
    {
        $urlWithoutToken = $this->removeNoQToken($currentUrl);
        // redirect if url contain token
        if ($urlWithoutToken !== $currentUrl) {
            return new ValidationResult($urlWithoutToken);
        }
        return new ValidationResult(null);
    }

    private function redirectToTicketIssuer($token, $currentURL): ValidationResult
    {
        $urlWithoutToken = $this->removeNoQToken($currentURL);
        $params = array(
            'noq_t' => $token,
            'noq_c' => $this->clientID,
            'noq_r' => $urlWithoutToken
        );
        $query = http_build_query($params);
        return new ValidationResult("{$this->ticketIssuer}?{$query}");
    }

    private function generateJWT($sessionId): string
    {
        return JWT::encode(array(
            "room_id" => $this->clientID,
            "session_id" => $sessionId ?? Uuid::uuid4()->toString(),
            "type" => "self-sign",
        ), $this->jwtSecret);
    }

    private function debugPrint($message)
    {
        if ($this->debug) {
            print("[RoomQ] {$message}");
        }
    }

    private function removeNoQToken($currentUrl): string
    {
        $updated = preg_replace('/([&]*)(noq_t=[^&]*)/i', '', $currentUrl);
        $updated = preg_replace('/\\?&/i', '', $updated);
        return preg_replace('/\\?$/i', '', $updated);
    }

    /**
     * @return array
     * @throws GuzzleException
     * @throws QueueStoppedException
     */
    public function getBackend(): string
    {
        $httpClient = new Client();
        $response = $httpClient->get("{$this->statusEndpoint}/{$this->clientID}");
        $json = json_decode($response->getBody(), true);
        $state = $json["state"];
        if ($state == "stopped") {
            throw new QueueStoppedException();
        }
        $backend = $json["backend"];
        return $backend;
    }


}
