<?php namespace AlbrightLabs\Auth0\Models;

use Model;
use RainLab\User\Models\UserGroup;

class Settings extends Model
{
    public $implement = ['System\Behaviors\SettingsModel'];

    public $settingsCode = 'albrightlabs_auth0_settings';

    public $settingsFields = 'fields.yaml';

    protected $cache = [];

    public function initSettingsData()
    {
        $this->domain = '';
        $this->client_id = '';
        $this->client_secret = '';
        $this->callback_url = '';
        $this->logout_url = '';
        $this->auto_create_users = true;
        $this->sync_user_data = true;
        $this->default_user_group = null;
        $this->stateless_mode = false;
    }

    public static function getAuth0Config()
    {
        $settings = self::instance();
        
        return [
            'domain' => $settings->domain,
            'client_id' => $settings->client_id,
            'client_secret' => $settings->client_secret,
            'redirect' => $settings->callback_url ?: url('/auth0/callback'),
            'logout_url' => $settings->logout_url ?: url('/'),
        ];
    }

    public static function isConfigured()
    {
        $settings = self::instance();
        
        return !empty($settings->domain) && 
               !empty($settings->client_id) && 
               !empty($settings->client_secret);
    }

    public function getDefaultUserGroupOptions()
    {
        return UserGroup::orderBy('name')->lists('name', 'id');
    }
}