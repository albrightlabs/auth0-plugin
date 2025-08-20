<?php namespace AlbrightLabs\Auth0\Classes;

use October\Rain\Network\Http;
use Exception;
use Log;
use Cache;

/**
 * Service class for obtaining JWT tokens for Rhythm API access
 */
class RhythmTokenExchange
{
    const API_AUDIENCE = 'https://api.rhythmsoftware.com';
    const CACHE_PREFIX = 'rhythm_jwt_token_';
    const CACHE_DURATION = 86000; // Cache for ~24 hours (tokens last 24 hours)
    
    /**
     * Get a JWT access token using client credentials
     * 
     * @param string $clientId The client ID from Rhythm admin portal
     * @param string $clientSecret The client secret from Rhythm admin portal
     * @param string $auth0Domain The Auth0 domain (e.g., 'exampletenant.us.auth0.com')
     * @return string|null The JWT access token or null on failure
     */
    public static function getAccessToken($clientId, $clientSecret, $auth0Domain = null)
    {
        if (empty($clientId) || empty($clientSecret)) {
            Log::error('RhythmTokenExchange: Missing client credentials');
            return null;
        }
        
        // Default to Rhythm's standard Auth0 domain if not provided
        if (empty($auth0Domain)) {
            $auth0Domain = 'rhythmsoftware.auth0.com';
        }
        
        // Ensure domain doesn't have https:// prefix
        $auth0Domain = str_replace(['https://', 'http://'], '', $auth0Domain);
        
        // Check cache first
        $cacheKey = self::CACHE_PREFIX . md5($clientId . $clientSecret);
        $cachedToken = Cache::get($cacheKey);
        
        if ($cachedToken) {
            Log::info('RhythmTokenExchange: Using cached JWT token');
            return $cachedToken;
        }
        
        try {
            $endpoint = "https://{$auth0Domain}/oauth/token";
            
            // Use client credentials grant type as per Rhythm docs
            $payload = [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
                'audience' => self::API_AUDIENCE
            ];
            
            Log::info('RhythmTokenExchange: Requesting access token', [
                'endpoint' => $endpoint,
                'client_id' => substr($clientId, 0, 10) . '...',
                'auth0_domain' => $auth0Domain
            ]);
            
            // Use form-urlencoded content type as per OAuth2 spec
            $response = Http::post($endpoint, function($http) use ($payload) {
                $http->header('Content-Type', 'application/x-www-form-urlencoded');
                $http->header('Accept', 'application/json');
                $http->data($payload);
            });
            
            if ($response->code == 200) {
                $data = json_decode($response->body, true);
                $accessToken = $data['access_token'] ?? null;
                
                if ($accessToken) {
                    // Cache the token (tokens are valid for ~24 hours)
                    Cache::put($cacheKey, $accessToken, self::CACHE_DURATION);
                    
                    Log::info('RhythmTokenExchange: Successfully obtained access token', [
                        'expires_in' => $data['expires_in'] ?? 'unknown',
                        'token_type' => $data['token_type'] ?? 'Bearer'
                    ]);
                    
                    return $accessToken;
                }
                
                Log::error('RhythmTokenExchange: No access_token in response', [
                    'response_keys' => array_keys($data ?? [])
                ]);
            } else {
                Log::error('RhythmTokenExchange: Failed to obtain access token', [
                    'status_code' => $response->code,
                    'response_body' => substr($response->body, 0, 500)
                ]);
            }
            
        } catch (Exception $e) {
            Log::error('RhythmTokenExchange: Exception during token request', [
                'error' => $e->getMessage()
            ]);
        }
        
        return null;
    }
    
    /**
     * Legacy method for backward compatibility
     * Expects credentials in format "clientId:clientSecret" or "clientId:clientSecret:auth0Domain"
     * 
     * @param string $apiToken The credentials string
     * @return string|null The JWT access token or null on failure
     */
    public static function exchangeToken($apiToken)
    {
        if (empty($apiToken)) {
            Log::error('RhythmTokenExchange: No credentials provided');
            return null;
        }
        
        // Parse credentials from the token string
        // Expected format: "clientId:clientSecret" or "clientId:clientSecret:auth0Domain"
        $parts = explode(':', $apiToken);
        
        if (count($parts) < 2) {
            Log::error('RhythmTokenExchange: Invalid credentials format. Expected clientId:clientSecret[:auth0Domain]');
            return null;
        }
        
        $clientId = $parts[0];
        $clientSecret = $parts[1];
        $auth0Domain = $parts[2] ?? null;
        
        return self::getAccessToken($clientId, $clientSecret, $auth0Domain);
    }
    
    /**
     * Clear cached token (useful when token is revoked or expired)
     * 
     * @param string $clientId The client ID to clear cache for
     * @param string $clientSecret The client secret
     */
    public static function clearCache($clientId, $clientSecret = null)
    {
        if ($clientId) {
            // If only clientId provided, assume it's in the legacy format
            if (!$clientSecret && strpos($clientId, ':') !== false) {
                $parts = explode(':', $clientId);
                $clientId = $parts[0];
                $clientSecret = $parts[1] ?? '';
            }
            
            $cacheKey = self::CACHE_PREFIX . md5($clientId . $clientSecret);
            Cache::forget($cacheKey);
            Log::info('RhythmTokenExchange: Cleared cached token');
        }
    }
    
    /**
     * Test if credentials can be successfully used to get an access token
     * 
     * @param string $clientId The client ID
     * @param string $clientSecret The client secret
     * @param string $auth0Domain The Auth0 domain (optional)
     * @param string $tenantId The tenant ID for testing (optional)
     * @return array Test results with success status and message
     */
    public static function testCredentials($clientId, $clientSecret, $auth0Domain = null, $tenantId = 'cxpaglobal.org')
    {
        // Get access token
        $accessToken = self::getAccessToken($clientId, $clientSecret, $auth0Domain);
        
        if ($accessToken) {
            // Test the access token with the get by email endpoint
            try {
                // Use a test email to verify API access
                $testEmail = 'test@example.com';
                $encodedEmail = urlencode($testEmail);
                $testUrl = "https://rolodex.api.rhythmsoftware.com/contacts/{$tenantId}/emailAddress/{$encodedEmail}";
                
                $response = Http::get($testUrl, function($http) use ($accessToken) {
                    $http->header('Authorization', 'Bearer ' . $accessToken);
                    $http->header('Accept', 'application/json');
                });
                
                // 200 = found, 404 = not found (both are valid responses)
                if ($response->code == 200 || $response->code == 404) {
                    return [
                        'success' => true,
                        'message' => 'Successfully authenticated and verified API access',
                        'token_preview' => substr($accessToken, 0, 50) . '...',
                        'api_test_code' => $response->code
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Got access token but API request failed',
                        'api_response_code' => $response->code,
                        'api_response' => substr($response->body, 0, 200)
                    ];
                }
            } catch (Exception $e) {
                return [
                    'success' => false,
                    'message' => 'Got access token but test failed: ' . $e->getMessage()
                ];
            }
        }
        
        return [
            'success' => false,
            'message' => 'Failed to obtain access token. Check your client credentials.'
        ];
    }
    
    /**
     * Legacy test method for backward compatibility
     * 
     * @param string $apiToken The credentials string in format "clientId:clientSecret[:auth0Domain]"
     * @return array Test results with success status and message
     */
    public static function testToken($apiToken)
    {
        // Parse credentials
        $parts = explode(':', $apiToken);
        
        if (count($parts) < 2) {
            return [
                'success' => false,
                'message' => 'Invalid credentials format. Expected clientId:clientSecret[:auth0Domain]'
            ];
        }
        
        $clientId = $parts[0];
        $clientSecret = $parts[1];
        $auth0Domain = $parts[2] ?? null;
        
        return self::testCredentials($clientId, $clientSecret, $auth0Domain);
    }
}