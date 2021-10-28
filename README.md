# Eve Online SSO

## READ FIRST

Before using this class you must register your application with CCP here:

https://developers.eveonline.com/applications/

There you will register the name and description of your app, provide the callback url, and the scopes you'd like to request. When that is completed you will be provided with the clientID and secretKey you will need for working with Eve Online SSO.

### Installation

I recommend using PHP's popular package manager:

    composer require zkillboard/eveonlineoauth2

### Dependencies

EveOnlineSSO requires the curl extension to be installed. EveOnlineSSO will also install the following:

* ircmaxell/random-lib - Used to generate a crypto safe state value
* aura/session - An excellent session management library. Highly recommended.

### Implementation

This code was created to make the usage of EveOnlineSSO very simple. Once you have your clientID and secretKey you can instantiate EveOnlineSSO like so:

    $sso = new EveOnlineSSO($clientID, $secretKey, $callbackURL, $scopes, $userAgent);

$clientID, $secretKey, $callbackURL, and $userAgent are all strings. The $scopes parameter is an array and defaults to an empty array. If $userAgent is not specified, it will default to the $callbackURL.

Once instantiated, you can then retrieve the URL needed for the user to login with Eve Online SSO:

    $loginURL = $sso->getLoginURL($session);

$session can be PHP's $_SESSION, or, an instance of Aura\Session\Segment. More session handling libraries can be added via PR.
    
A typical web application will then redirect the user to this loginURL. This example will use PHP's header command, but I recommend using a framework such as Slim.

    header("Location: $loginURL");

Here the control is out of your hands since the user is verifying their identity with CCP and choosing which character they want to pass back to your application. Once they've completed these steps, the CCP auth server will redirect the user back to your callback URL. Here you will need to do a couple of steps to obtain the user's information.

    $sso = new EveOnlineSSO($clientID, $secretKey, $callbackURL, $scopes, $userAgent);
    $code = filter_input(INPUT_GET, 'code');
    $state = filter_input(INPUT_GET, 'state');
    $userInfo = $sso->handleCallback($code, $state, $session);

The resulting $userInfo array will contain the following keys with their appropriate values:

    characterID
    characterName
    scopes
    tokenType
    refreshToken
    accessToken
    ownerHash

Keep in mind accessTokens are only good for 20 minutes after creation. If your accessToken has expired, you can use the refreshToken to get a new accessToken:

    $sso->getAccessToken($refreshToken);
    
**PLEASE NOTE** At the moment, the above call will return a string. This _WILL_ be changing soon and will be returning an Array. CCP will be rotating the refresh tokens at some point, and the above call will return the new refresh token as well. It will be up to your code to handle the new refresh token.
    
### doCall

The doCall method doesn't necessarily have anything to do with SSO but is provided to make it easy and convenient to access authed Eve Online. doCall can handle GET, POST, PUT, DELETE, and OPTIONS.

We'll start off with a simple GET request:

    $result = $sso->doCall($url, $fields, $accessToken);

doCall does have a fourth field, which defaults to 'GET', but can be GET, POST, PUT, DELETE, or OPTIONS

    $result = $sso->doCall($url, $fields, $accessToken, 'OPTIONS');
    $result = $sso->doCall($url, $fields, $accessToken, 'POST');
    
A rough example for setting the MOTD and free move on a fleet:

    $result = $sso->doCall("https://esi.evetech.net/latest/fleets/1043511252862/", ["motd" => "Hi Mom", "isFreeMove" => true], $accessToken, 'PUT');

Or even deleting a squad:

    $result = $sso->doCall("https://esi.evetech.net/latest/fleets/1043511252862/wings/2053611252862/squads/3108711252862/", [], $accessToken, 'DELETE');

Each call returns the result as a string which will need to be json_decode'ed by your application. I have left this step out so that your application can json_decode to an object:

    $jsonObject = json_decode($result);

or to an array:

    $jsonArray = json_decode($result, true);
    
These calls will also work as a utility for calling the ESI API for scopes that will work on ESI API calls. This is why doCall does not return JSON by default, it is left to the developer to work with the returned data any way they see fit.
  
That's all there is to it! These simple calls will allow you to get started quickly with Eve Online's SSO and use ESI.

#### Errors

If the curl call is unsuccessful for any reason it will throw an exception. I recommend properly surrounding your code with try/catch blocks to handle any exceptions. The Eve Online ESI API can and will go down and/or become unresponsive for various reasons (especially during downtime).

#### Issues

* I tried your example but I got a class not found error

You can either put a use statement at the beginning of your code:

    use zkillboard\eveonlineoauth2\eveonlinesso;

or fully qualify the class name when instantiating:

    $sso = new \zkillboard\eveonlineoauth2\EveOnlineSSO($clientID, $secretKey, $callbackURL, $scopes);
    
* $userInfo came back without a refreshToken

If you do not provide any scopes, or only request the publicData scope, then the call is basically good for authentication only and no refreshToken is needed, therefore the auth server doesn't give out a refreshToken.
