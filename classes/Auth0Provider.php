<?php namespace Albrightlabs\Auth0\Classes;

use Auth;
use Event;
use Session;
use Request;
use Laravel\Socialite\Facades\Socialite;
use Illuminate\Support\Facades\Config;
use SocialiteProviders\Manager\Config as SocialiteConfig;
use RainLab\User\Models\User;
use RainLab\User\Models\UserGroup;
use Albrightlabs\Auth0\Models\Settings;
use October\Rain\Auth\AuthException;
use Exception;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Log;

class Auth0Provider
{
    /**
     * Handle the beforeAuthenticate event to check for Auth0 login
     */
    public function handleBeforeAuthenticate($component, $credentials)
    {
        // Check if this is an Auth0 authentication attempt
        if (!empty($credentials['auth0']) && $credentials['auth0'] === true) {
            return $this->redirectToAuth0();
        }
        
        return null;
    }

    /**
     * Redirect to Auth0 for authentication
     */
    public function redirectToAuth0()
    {
        if (!Settings::isConfigured()) {
            throw new AuthException('Auth0 is not properly configured');
        }

        // Set config dynamically
        $this->setAuth0Config();

        // Store intended URL if session is available
        if (Request::hasSession()) {
            // Check for existing intended URL from various sources
            $intended = '/';
            
            // Priority 1: Check for url.intended (set by ResourceDetail or other components)
            if (Session::has('url.intended')) {
                $intended = Session::pull('url.intended');
            }
            // Priority 2: Check for explicit redirect parameter
            elseif (request()->has('redirect')) {
                $intended = request()->input('redirect');
            }
            // Priority 3: Check if auth0_intended already exists (shouldn't overwrite)
            elseif (Session::has('auth0_intended')) {
                $intended = Session::get('auth0_intended');
            }
            
            Session::put('auth0_intended', $intended);
        }
        
        try {
            $driver = Socialite::driver('auth0');
            
            // If stateless mode is enabled, use it
            if (Settings::get('stateless_mode', false)) {
                return $driver->stateless()->redirect();
            }
            
            return $driver->redirect();
        } catch (Exception $e) {
            // If Socialite fails, try manual redirect
            $settings = Settings::instance();
            $domain = trim($settings->domain, '/');
            if (!preg_match('/^https?:\/\//', $domain)) {
                $domain = 'https://' . $domain;
            }
            
            $params = [
                'client_id' => $settings->client_id,
                'redirect_uri' => $settings->callback_url ?: url('/auth0/callback'),
                'response_type' => 'code',
                'scope' => 'openid profile email',
            ];
            
            $url = $domain . '/authorize?' . http_build_query($params);
            
            return redirect($url);
        }
    }

    /**
     * Handle the Auth0 callback
     */
    public function handleCallback()
    {
        if (!Settings::isConfigured()) {
            throw new AuthException('Auth0 is not properly configured');
        }

        // Check for error from Auth0
        if (request()->has('error')) {
            $error = request()->input('error');
            $errorDescription = request()->input('error_description', 'Unknown error');
            throw new AuthException('Auth0 Error: ' . $error . ' - ' . $errorDescription);
        }

        // Get authorization code
        $code = request()->input('code');
        if (empty($code)) {
            throw new AuthException('No authorization code received from Auth0');
        }
        
        // Check state parameter
        $state = request()->input('state');
        if (empty($state)) {
            throw new AuthException('No state parameter received from Auth0. This might indicate a CSRF issue.');
        }

        // Set config dynamically
        $this->setAuth0Config();

        // Debug logging - disabled due to permission issues
        // Log::info('Auth0 Callback Debug', [
        //     'code' => substr($code, 0, 10) . '...',
        //     'callback_url' => $this->getCallbackUrl(),
        //     'auth0_config' => Config::get('services.auth0'),
        //     'request_url' => request()->fullUrl(),
        //     'request_host' => request()->getHttpHost()
        // ]);

        try {
            // Check if we should bypass state verification (only in specific cases)
            $driver = Socialite::driver('auth0');
            
            // If we have a state mismatch, log it for debugging
            $sessionState = Session::get('state');
            $requestState = request()->input('state');
            
            if ($sessionState !== $requestState) {
                // Log the state mismatch
                $stateDebug = [
                    'session_state' => $sessionState,
                    'request_state' => $requestState,
                    'session_id' => Session::getId(),
                    'has_session' => Session::isStarted(),
                    'session_domain' => config('session.domain'),
                    'session_secure' => config('session.secure'),
                    'session_same_site' => config('session.same_site'),
                    'cookie_domain' => config('session.cookie'),
                    'app_url' => config('app.url'),
                ];
                // State mismatch detected but continuing
            }
            
            // Check if stateless mode is enabled
            if (Settings::get('stateless_mode', false)) {
                $auth0User = $driver->stateless()->user();
            } else {
                // Use the authorization code to get the user
                $auth0User = $driver->user();
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // Get the response body for more details
            $response = $e->getResponse();
            $body = $response ? (string) $response->getBody() : 'No response body';
            $statusCode = $response ? $response->getStatusCode() : 'Unknown';
            
            Log::error('Auth0 Client Error', [
                'type' => 'ClientException',
                'status_code' => $statusCode,
                'body' => $body,
                'message' => $e->getMessage()
            ]);
            
            throw new AuthException('Auth0 API Error (' . $statusCode . '): ' . $body);
        } catch (Exception $e) {
            Log::error('Auth0 Exception', [
                'type' => 'Exception',
                'class' => get_class($e),
                'message' => $e->getMessage(),
                'callback_url' => $this->getCallbackUrl(),
                'auth0_domain' => Settings::instance()->domain,
                'code' => $e->getCode(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            
            // If message is empty, provide more context
            $errorMessage = $e->getMessage();
            if (empty($errorMessage)) {
                $errorMessage = 'Unknown error - Class: ' . get_class($e) . ' at ' . $e->getFile() . ':' . $e->getLine();
            }
            
            throw new AuthException('Failed to authenticate with Auth0: ' . $errorMessage);
        }

        // Find or create the user
        $user = $this->findOrCreateUser($auth0User);

        // Fire event to allow extensions to process the Auth0 user data
        Event::fire('albrightlabs.auth0.afterUserAuthenticated', [$user, $auth0User]);

        // Login the user
        Auth::login($user, true);

        // Fire the login event
        Event::fire('rainlab.user.login', [$user]);

        // Get the intended URL - ensure it's relative to avoid cross-domain redirects
        $intended = '/';
        if (Request::hasSession()) {
            // Try auth0_intended first, then url.intended as fallback
            $intended = Session::pull('auth0_intended', Session::pull('url.intended', '/'));
        }
        
        // Parse the intended URL to handle both full URLs and relative paths
        if (!empty($intended) && $intended !== '/') {
            $parsedUrl = parse_url($intended);
            
            // Check if it's a full URL
            if (isset($parsedUrl['host'])) {
                // Verify it's for the same host
                if ($parsedUrl['host'] !== request()->getHost()) {
                    // Different host, default to homepage
                    $intended = '/';
                } else {
                    // Same host, extract the path and query
                    $intended = $parsedUrl['path'] ?? '/';
                    if (isset($parsedUrl['query'])) {
                        $intended .= '?' . $parsedUrl['query'];
                    }
                    if (isset($parsedUrl['fragment'])) {
                        $intended .= '#' . $parsedUrl['fragment'];
                    }
                }
            }
            // If no host, it's already a relative path
        }
        
        // Ensure the path starts with /
        if (!empty($intended) && $intended[0] !== '/') {
            $intended = '/' . $intended;
        }
        
        // Build the redirect URL using the current request's base URL
        // This avoids issues with APP_URL being incorrect
        $baseUrl = request()->getSchemeAndHttpHost();
        $redirectUrl = $baseUrl . $intended;

        return redirect()->away($redirectUrl);
    }

    /**
     * Find or create a user from Auth0 data
     */
    protected function findOrCreateUser($auth0User)
    {
        // First try to find by Auth0 ID
        $user = User::where('auth0_id', $auth0User->id)->first();

        if ($user) {
            // Update user data if sync is enabled
            if (Settings::get('sync_user_data', true)) {
                $this->updateUserFromAuth0($user, $auth0User);
            }
            return $user;
        }

        // Try to find by email
        $user = User::where('email', $auth0User->email)->first();

        if ($user) {
            // Link existing user to Auth0
            $this->updateUserFromAuth0($user, $auth0User);
            return $user;
        }

        // Create new user if auto-creation is enabled
        if (!Settings::get('auto_create_users', true)) {
            throw new AuthException('User does not exist and auto-creation is disabled');
        }

        return $this->createUserFromAuth0($auth0User);
    }

    /**
     * Create a new user from Auth0 data
     */
    protected function createUserFromAuth0($auth0User)
    {
        // Try to get name from various sources
        $firstName = '';
        $lastName = '';
        
        // Check for given_name/family_name (standard OAuth)
        if (!empty($auth0User->user['given_name'])) {
            $firstName = $auth0User->user['given_name'];
            $lastName = $auth0User->user['family_name'] ?? '';
        }
        // Fall back to parsing the name field
        elseif (!empty($auth0User->name) && trim($auth0User->name)) {
            $nameParts = $this->parseFullName($auth0User->name);
            $firstName = $nameParts['first'];
            $lastName = $nameParts['last'];
        }
        // Use nickname if available (common for username/password Auth0 accounts)
        elseif (!empty($auth0User->nickname)) {
            // Try to parse nickname in case it contains full name
            if (strpos($auth0User->nickname, ' ') !== false) {
                $nameParts = $this->parseFullName($auth0User->nickname);
                $firstName = $nameParts['first'];
                $lastName = $nameParts['last'];
            } else {
                // Just use nickname as first name
                $firstName = $auth0User->nickname;
            }
        }
        // Last resort - try to use email prefix
        elseif (!empty($auth0User->email)) {
            $emailPrefix = strstr($auth0User->email, '@', true);
            // Check if email prefix might be a full name (contains dot or underscore)
            if (strpos($emailPrefix, '.') !== false) {
                $firstName = str_replace('.', ' ', $emailPrefix);
                $nameParts = $this->parseFullName($firstName);
                $firstName = $nameParts['first'];
                $lastName = $nameParts['last'];
            } elseif (strpos($emailPrefix, '_') !== false) {
                $firstName = str_replace('_', ' ', $emailPrefix);
                $nameParts = $this->parseFullName($firstName);
                $firstName = $nameParts['first'];
                $lastName = $nameParts['last'];
            } else {
                $firstName = $emailPrefix;
            }
        }
        
        // Default to 'User' if still empty
        if (empty($firstName)) {
            $firstName = 'User';
        }
        
        $user = new User();
        
        // Set required fields
        $user->first_name = $firstName;
        $user->last_name = $lastName;
        $user->email = $auth0User->email;
        $user->username = $this->generateUsername($auth0User);
        $user->auth0_id = $auth0User->id;
        $user->auth0_access_token = $auth0User->token;
        $user->auth0_refresh_token = $auth0User->refreshToken ?? null;
        $user->auth0_id_token = $auth0User->accessTokenResponseBody['id_token'] ?? null;
        $user->social_avatar = $auth0User->avatar ?? null;
        $user->activated_at = now(); // Auth0 users are pre-verified
        $user->is_guest = 0;
        $user->created_ip_address = request()->ip();
        $user->last_ip_address = request()->ip();
        
        // Generate a random password (user won't use it with Auth0)
        $user->password = $user->password_confirmation = str_random(32);
        
        // Set primary group if configured to prevent defaulting to 'registered'
        if ($groupId = Settings::get('default_user_group')) {
            $group = UserGroup::find($groupId);
            if ($group) {
                $user->primary_group_id = $groupId;
            }
        }
        
        // Force save without validation
        $user->forceSave();

        // Also add to the group relationship
        if ($groupId = Settings::get('default_user_group')) {
            $group = UserGroup::find($groupId);
            if ($group) {
                $user->groups()->add($group);
            }
        }

        // Fire event to allow extensions to process the new user
        Event::fire('albrightlabs.auth0.userCreated', [$user, $auth0User]);

        return $user;
    }

    /**
     * Update existing user with Auth0 data
     */
    protected function updateUserFromAuth0($user, $auth0User)
    {
        $user->auth0_id = $auth0User->id;
        $user->auth0_access_token = $auth0User->token;
        $user->auth0_refresh_token = $auth0User->refreshToken ?? null;
        $user->auth0_id_token = $auth0User->accessTokenResponseBody['id_token'] ?? null;
        $user->last_ip_address = request()->ip();
        
        if (Settings::get('sync_user_data', true)) {
            // Only update if not already set
            if (empty($user->social_avatar)) {
                $user->social_avatar = $auth0User->avatar ?? null;
            }
            
            // Update name if it's still the default or empty
            if (empty($user->first_name) || $user->first_name === 'User' || empty($user->last_name)) {
                // Try to get name from various sources
                $firstName = '';
                $lastName = '';
                
                // Check for given_name/family_name (standard OAuth)
                if (!empty($auth0User->user['given_name'])) {
                    $firstName = $auth0User->user['given_name'];
                    $lastName = $auth0User->user['family_name'] ?? '';
                }
                // Fall back to parsing the name field
                elseif (!empty($auth0User->name) && trim($auth0User->name)) {
                    $nameParts = $this->parseFullName($auth0User->name);
                    $firstName = $nameParts['first'];
                    $lastName = $nameParts['last'];
                }
                // Use nickname if available (common for username/password Auth0 accounts)
                elseif (!empty($auth0User->nickname)) {
                    // Try to parse nickname in case it contains full name
                    if (strpos($auth0User->nickname, ' ') !== false) {
                        $nameParts = $this->parseFullName($auth0User->nickname);
                        $firstName = $nameParts['first'];
                        $lastName = $nameParts['last'];
                    } else {
                        // Just use nickname as first name
                        $firstName = $auth0User->nickname;
                    }
                }
                // Last resort - try to use email prefix
                elseif (!empty($auth0User->email)) {
                    $emailPrefix = strstr($auth0User->email, '@', true);
                    // Check if email prefix might be a full name (contains dot or underscore)
                    if (strpos($emailPrefix, '.') !== false) {
                        $firstName = str_replace('.', ' ', $emailPrefix);
                        $nameParts = $this->parseFullName($firstName);
                        $firstName = $nameParts['first'];
                        $lastName = $nameParts['last'];
                    } elseif (strpos($emailPrefix, '_') !== false) {
                        $firstName = str_replace('_', ' ', $emailPrefix);
                        $nameParts = $this->parseFullName($firstName);
                        $firstName = $nameParts['first'];
                        $lastName = $nameParts['last'];
                    } else {
                        $firstName = $emailPrefix;
                    }
                }
                
                // Update only if we found better data
                if (!empty($firstName) && (empty($user->first_name) || $user->first_name === 'User')) {
                    $user->first_name = $firstName;
                }
                if (!empty($lastName) && empty($user->last_name)) {
                    $user->last_name = $lastName;
                }
            }
        }
        
        // If user wasn't activated before, activate them now
        if (!$user->activated_at) {
            $user->activated_at = now();
        }
        
        // Force save without validation
        $user->forceSave();
        
        // Fire event to allow extensions to process the updated user
        Event::fire('albrightlabs.auth0.userUpdated', [$user, $auth0User]);
    }

    /**
     * Parse full name into first and last name
     */
    protected function parseFullName($fullName)
    {
        $parts = explode(' ', trim($fullName), 2);
        
        return [
            'first' => $parts[0] ?? '',
            'last' => $parts[1] ?? ''
        ];
    }

    /**
     * Generate a unique username from Auth0 data
     */
    protected function generateUsername($auth0User)
    {
        // Try email prefix first
        $username = strstr($auth0User->email, '@', true);
        
        // Ensure uniqueness
        $baseUsername = $username;
        $counter = 1;
        
        while (User::where('username', $username)->exists()) {
            $username = $baseUsername . $counter;
            $counter++;
        }
        
        return $username;
    }

    /**
     * Logout user from Auth0
     */
    public function logout()
    {
        $settings = Settings::instance();
        
        if (!$settings->domain) {
            return redirect('/');
        }
        
        // Determine the return URL after logout
        $returnUrl = $settings->logout_url;
        if (empty($returnUrl)) {
            // Use the current request's base URL instead of url() helper
            $returnUrl = request()->getSchemeAndHttpHost() . '/';
        }

        $logoutUrl = sprintf(
            'https://%s/v2/logout?client_id=%s&returnTo=%s',
            $settings->domain,
            $settings->client_id,
            urlencode($returnUrl)
        );

        return redirect($logoutUrl);
    }

    /**
     * Get the callback URL for Auth0
     */
    public function getCallbackUrl()
    {
        $settings = Settings::instance();
        $callbackUrl = trim($settings->callback_url);
        
        // If a callback URL is explicitly set, use it exactly as provided
        if (!empty($callbackUrl)) {
            return $callbackUrl;
        }
        
        // Otherwise, generate it from the current request
        // This ensures consistency between what Auth0 expects and what we send
        $request = Request::instance();
        $scheme = $request->secure() || $request->server('HTTP_X_FORWARDED_PROTO') === 'https' ? 'https' : 'http';
        $host = $request->getHttpHost(); // This includes port if non-standard
        
        // Handle forwarded host from proxy/load balancer
        if ($request->hasHeader('X-Forwarded-Host')) {
            $host = $request->header('X-Forwarded-Host');
        }
        
        $baseUrl = $scheme . '://' . $host;
        
        return $baseUrl . '/auth0/callback';
    }

    /**
     * Set Auth0 configuration dynamically
     */
    protected function setAuth0Config()
    {
        $settings = Settings::instance();
        
        // Auth0 domain - ensure it has https://
        $domain = $settings->domain;
        if (!preg_match('/^https?:\/\//', $domain)) {
            $domain = 'https://' . $domain;
        }
        
        // Get the callback URL
        $callbackUrl = $this->getCallbackUrl();
        
        // Set configuration for Laravel Socialite
        Config::set('services.auth0', [
            'base_url' => $domain,  // Auth0 provider expects base_url, not domain
            'client_id' => $settings->client_id,
            'client_secret' => $settings->client_secret,
            'redirect' => $callbackUrl,
        ]);
    }
}