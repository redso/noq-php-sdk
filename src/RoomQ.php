<?php

namespace NoQ\RoomQ;

use Exception;
use Firebase\JWT\JWT;
use Ramsey\Uuid\Uuid;

class RoomQ
{
    private $clientID;
    private $jwtSecret;
    private $ticketIssuer;
    private $debug;
    private $tokenName;

    public function __construct($clientID, $jwtSecret, $ticketIssuer, $debug = false)
    {
        $this->clientID = $clientID;
        $this->jwtSecret = $jwtSecret;
        $this->ticketIssuer = $ticketIssuer;
        $this->debug = $debug;
        $this->tokenName = "be_roomq_t_{$clientID}";
    }

    public function validate($returnURL, $sessionId): ValidationResult
    {
        $token = null;

        if (isset($_GET["noq_t"])) {
            $token = $_GET["noq_t"];
        } else if (isset($_COOKIE[$this->tokenName])) {
            $token = $_COOKIE[$this->tokenName];
        }

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
        }

        setcookie($this->tokenName, $token, time() + (12 * 60 * 60), "");

        if ($needRedirect) {
            return $this->redirectToTicketIssuer($token, $returnURL ?? $currentURL);
        } else {
            return $this->enter($currentURL);
        }
    }

    public function getLocker($apiKey, $isDev = false): Locker {
        $token = null;

        if (isset($_GET["noq_t"])) {
            $token = $_GET["noq_t"];
        } else if (isset($_COOKIE[$this->tokenName])) {
            $token = $_COOKIE[$this->tokenName];
        }
        return new Locker($this->clientID, $apiKey, $token, $isDev);
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

}
