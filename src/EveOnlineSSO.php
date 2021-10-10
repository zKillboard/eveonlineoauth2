<?php

namespace zkillboard\eveonlineoauth2;

class EveOnlineSSO
{
    protected $clientID;
    protected $secretKey;
    protected $callbackURL;
    protected $scopes;
    protected $state;

    protected $loginURL = "https://login.eveonline.com/v2/oauth/authorize";
    protected $tokenURL = "https://login.eveonline.com/v2/oauth/token";

    public function __construct($clientID, $secretKey, $callbackURL, $scopes = [])
    {
        $this->clientID = $clientID;
        $this->secretKey = $secretKey;
        $this->callbackURL = $callbackURL;
        $this->scopes = $scopes;
    }

    public function createState()
    {
        $factory = new \RandomLib\Factory;
        $generator = $factory->getGenerator(new \SecurityLib\Strength(\SecurityLib\Strength::MEDIUM));
        $state = $generator->generateString(128, "0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ");

        return $state;
    }

    public function getState()
    {
        return $this->state;
    }

    /*
        Allows the developer to set their own state if they aren't happy with the
        state created by RandomLib.
    */
    public function setState($state)
    {
        $this->state = $state;
    }

    public function getLoginURL(&$session)
    {
        $state = ($this->state === null) ? $this->createState() : $this->state;
        $this->state = $state;
        $this->setSessionState($state, $session);

        $fields = [
            "response_type" => "code", 
            "client_id" => $this->clientID,
            "redirect_uri" => $this->callbackURL, 
            "scope" => implode(' ', $this->scopes),
            "state" => $state
        ];
        $params = $this->buildParams($fields);

        $url = $this->loginURL . "?" . $params;
        return $url;
    }

    protected function setSessionState($state, &$session)
    {
        $class = is_array($session) ? "Array" : get_class($session);
        switch ($class) {
            case "Array":
                $session["oauth2State"] = $state;
                break;
            case "Nette\Http\SessionSection":
                $session->oauth2State = $state;
                break;
            case "Aura\Session\Segment":
                $session->set("oauth2State", $state);
                break;
            default:
                throw new \Exception("Unknown session type");
        }
    }

    protected function getSessionState($session)
    {
        $class = is_array($session) ? "Array" : get_class($session);
        switch ($class) {
            case "Array":
                return @$session["oauth2State"];
            case "Nette\Http\SessionSection":
                return $session->oauth2State;
            case "Aura\Session\Segment":
                return $session->get("oauth2State");
            default:
                throw new \Exception("Unknown session type");
        }
    }

    protected function validateStates($state, $oauth2State)
    {
        if ($oauth2State !== $state) {
            throw new \Exception("Invalid state returned - possible hijacking attempt");
        }
    }

    public function handleCallback($code, $state, $session)
    {
        $oauth2State = $this->getSessionState($session);
        $this->validateStates($state, $oauth2State);

        $fields = ['grant_type' => 'authorization_code', 'code' => $code];
        $tokenString = $this->doCall($this->tokenURL, $fields, null, 'POST');
        $tokenJson = json_decode($tokenString, true);
        $accessToken = $tokenJson['access_token'];
        $decoded = json_decode(base64_decode(str_replace('_', '/', str_replace('-', '+', explode('.', $accessToken)[1]))), true);

        $accessToken = $tokenJson['access_token'];
        $refreshToken = $tokenJson['refresh_token'];

        $retValue = [
            'characterID' => str_replace("CHARACTER:EVE:", "", $decoded['sub']),
            'characterName' => $decoded['name'],
            'scopes' => implode(' ', $decoded['scp']),
            'tokenType' => 'Character',
            'ownerHash' => $decoded['owner'],
            'refreshToken' => $refreshToken,
            'accessToken' => $accessToken,
        ];

        return $retValue;
    }

    public function getAccessToken($refreshToken, $scopes = [])
    {
        $fields = ['grant_type' => 'refresh_token', 'refresh_token' => $refreshToken];
        $accessString = $this->doCall($this->tokenURL, $fields, null, 'POST', true);
        $accessJson = json_decode($accessString, true);
        if (!isset($accessJson['access_token'])) throw new \Exception("Unexpected value returned from call:\n" . print_r($accessJson, true));
        return $accessJson['access_token'];
    }

    public function doCall($url, $fields, $accessToken, $callType = 'GET')
    {
        $callType = strtoupper($callType);
        $header = $accessToken !== null ? 'Authorization: Bearer ' . $accessToken : 'Authorization: Basic ' . base64_encode($this->clientID . ':' . $this->secretKey);
        $headers = [$header];

        $url = $callType != 'GET' ? $url : $url . "?" . $this->buildParams($fields);

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_USERAGENT, $this->callbackURL);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        switch ($callType) {
            case 'DELETE':
            case 'PUT':
            case 'POST_JSON':
                $headers[] = "Content-Type: application/json";
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode(empty($fields) ? (object) NULL : $fields, JSON_UNESCAPED_SLASHES));
                $callType = $callType == 'POST_JSON' ? 'POST' : $callType;
                break;
            case 'POST':
                curl_setopt($ch, CURLOPT_POSTFIELDS, $this->buildParams($fields));
                break;
        }
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $callType);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        $result = curl_exec($ch);

        if (curl_errno($ch) !== 0) {
            throw new \Exception(curl_error($ch), curl_errno($ch));
        }

        return $result;
    }

    protected function buildParams($fields)
    {
        $string = "";
        foreach ($fields as $field=>$value) {
            $string .= $string == "" ? "" : "&";
            $string .= "$field=" . rawurlencode($value);
        }
        return $string;
    }
}
