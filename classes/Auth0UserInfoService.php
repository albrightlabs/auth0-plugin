<?php namespace Albrightlabs\Auth0\Classes;

use Albrightlabs\Auth0\Models\Settings;
use October\Rain\Network\Http;
use Exception;
use Log;

class Auth0UserInfoService
{
    protected $domain;

    public function __construct()
    {
        $settings = Settings::instance();
        $this->domain = trim($settings->domain, '/');
        
        // Ensure domain has https://
        if (!preg_match('/^https?:\/\//', $this->domain)) {
            $this->domain = 'https://' . $this->domain;
        }
    }

    /**
     * Get user info from Auth0 using the access token
     *
     * @param string $accessToken The Auth0 access token
     * @return array|null
     */
    public function getUserInfo($accessToken)
    {
        if (empty($accessToken)) {
            throw new Exception('Access token is required');
        }

        try {
            // Build the Auth0 userinfo endpoint URL
            $endpoint = $this->domain . '/userinfo';

            // Make the API request with the access token in Authorization header
            $response = Http::get($endpoint, function($http) use ($accessToken) {
                $http->header('Authorization', 'Bearer ' . $accessToken);
                $http->header('Accept', 'application/json');
                $http->header('Content-Type', 'application/json');
            });

            // Check if request was successful
            if ($response->code != 200) {
                $error = sprintf(
                    'Auth0 User Info request failed with status %d: %s',
                    $response->code,
                    $response->body
                );

                Log::error('Auth0 User Info Error', [
                    'status' => $response->code,
                    'body' => $response->body,
                    'endpoint' => $endpoint
                ]);

                throw new Exception($error);
            }

            // Parse and return the response
            $data = json_decode($response->body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Exception('Failed to parse Auth0 User Info response: ' . json_last_error_msg());
            }

            // Log successful response for debugging
            Log::info('Auth0 User Info retrieved', [
                'sub' => $data['sub'] ?? null,
                'email' => $data['email'] ?? null,
                'custom_claims' => array_filter($data, function($key) {
                    return strpos($key, 'http://') === 0 || strpos($key, 'https://') === 0;
                }, ARRAY_FILTER_USE_KEY)
            ]);

            return $data;

        } catch (\Exception $e) {
            // Log the error
            Log::error('Failed to fetch Auth0 User Info', [
                'error' => $e->getMessage()
            ]);

            // Re-throw to be handled by caller
            throw $e;
        }
    }

    /**
     * Extract Rhythm data from user info
     *
     * @param array $userInfo
     * @return array
     */
    public function extractRhythmData($userInfo)
    {
        if (!is_array($userInfo)) {
            return [];
        }

        // Extract Rhythm-specific custom claims
        $rhythmData = [
            'contact_id' => $userInfo['http://rhythmsoftware.com/contact_id'] ?? null,
            'tenant_id' => $userInfo['http://rhythmsoftware.com/tenant_id'] ?? null,
            'customer_id' => $userInfo['http://rhythmsoftware.com/customer_id'] ?? null,
            'contact_name' => $userInfo['http://rhythmsoftware.com/contact_name'] ?? null,
        ];

        // Also check for standard claims that might contain Rhythm data
        if (isset($userInfo['app_metadata']['rhythm'])) {
            $rhythmData = array_merge($rhythmData, $userInfo['app_metadata']['rhythm']);
        }

        if (isset($userInfo['user_metadata']['rhythm'])) {
            $rhythmData = array_merge($rhythmData, $userInfo['user_metadata']['rhythm']);
        }

        return array_filter($rhythmData); // Remove null values
    }

    /**
     * Update user with Auth0 user info data
     *
     * @param \RainLab\User\Models\User $user
     * @param array $userInfo
     * @return bool
     */
    public function updateUserFromUserInfo($user, $userInfo)
    {
        try {
            // Extract Rhythm data from user info
            $rhythmData = $this->extractRhythmData($userInfo);
            
            // First try to get contact ID from user info
            if (!empty($rhythmData['contact_id'])) {
                $user->rhythm_contact_id = $rhythmData['contact_id'];
            }
            
            // Also check if we have it in the ID token (which is more reliable)
            if (empty($user->rhythm_contact_id) && !empty($user->auth0_id_token)) {
                try {
                    // Decode ID token to get Rhythm contact ID
                    $parts = explode('.', $user->auth0_id_token);
                    if (count($parts) === 3) {
                        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
                        if ($payload && isset($payload['http://rhythmsoftware.com/contact_id'])) {
                            $user->rhythm_contact_id = $payload['http://rhythmsoftware.com/contact_id'];
                        }
                    }
                } catch (Exception $e) {
                    // Ignore decoding errors
                }
            }

            // Update user profile fields if available
            if (isset($userInfo['name']) && !empty($userInfo['name'])) {
                $nameParts = explode(' ', trim($userInfo['name']), 2);
                if (empty($user->first_name) || $user->first_name === 'User') {
                    $user->first_name = $nameParts[0] ?? 'User';
                }
                if (empty($user->last_name) && isset($nameParts[1])) {
                    $user->last_name = $nameParts[1];
                }
            }

            if (isset($userInfo['given_name']) && (empty($user->first_name) || $user->first_name === 'User')) {
                $user->first_name = $userInfo['given_name'];
            }

            if (isset($userInfo['family_name']) && empty($user->last_name)) {
                $user->last_name = $userInfo['family_name'];
            }

            if (isset($userInfo['picture']) && empty($user->social_avatar)) {
                $user->social_avatar = $userInfo['picture'];
            }

            // Store the entire user info for reference
            $user->auth0_user_info = $userInfo;
            $user->auth0_user_info_updated_at = now();

            // Save without validation
            $user->forceSave();

            Log::info('Updated user from Auth0 User Info', [
                'user_id' => $user->id,
                'rhythm_contact_id' => $rhythmData['contact_id'] ?? null
            ]);

            return true;

        } catch (Exception $e) {
            Log::error('Failed to update user from Auth0 User Info', [
                'user_id' => $user->id,
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Test the Auth0 User Info endpoint
     *
     * @param string $accessToken
     * @return array
     */
    public function testConnection($accessToken = null)
    {
        try {
            if (!$accessToken) {
                return [
                    'success' => false,
                    'message' => 'No access token provided. A valid Auth0 access token is required to test the connection.'
                ];
            }

            $userInfo = $this->getUserInfo($accessToken);

            return [
                'success' => true,
                'message' => 'Successfully retrieved user info from Auth0',
                'user_id' => $userInfo['sub'] ?? 'Unknown',
                'email' => $userInfo['email'] ?? 'Unknown',
                'rhythm_data' => $this->extractRhythmData($userInfo)
            ];

        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Connection failed: ' . $e->getMessage()
            ];
        }
    }
}