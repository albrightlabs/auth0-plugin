<?php namespace AlbrightLabs\Auth0\Components;

use Cms\Classes\ComponentBase;
use AlbrightLabs\Auth0\Models\Settings;
use AlbrightLabs\Auth0\Classes\Auth0Provider;
use October\Rain\Exception\ApplicationException;
use Auth;
use Flash;
use Redirect;
use Exception;

class Auth0Login extends ComponentBase
{
    public function componentDetails()
    {
        return [
            'name'        => 'Auth0 Login',
            'description' => 'Provides Auth0 login functionality'
        ];
    }

    public function defineProperties()
    {
        return [
            'showTraditionalLogin' => [
                'title'       => 'Show Traditional Login',
                'description' => 'Display traditional username/password login alongside Auth0',
                'type'        => 'checkbox',
                'default'     => true
            ],
            'redirectAfterLogin' => [
                'title'       => 'Redirect After Login',
                'description' => 'Page to redirect to after successful login',
                'type'        => 'dropdown',
                'default'     => '/'
            ],
            'buttonText' => [
                'title'       => 'Button Text',
                'description' => 'Text to display on the Auth0 login button',
                'type'        => 'string',
                'default'     => 'Login with Auth0'
            ],
            'buttonClass' => [
                'title'       => 'Button CSS Class',
                'description' => 'CSS class for the Auth0 login button',
                'type'        => 'string',
                'default'     => 'btn btn-primary'
            ]
        ];
    }

    public function getRedirectAfterLoginOptions()
    {
        return [
            '/' => 'Homepage',
            'account' => 'Account Dashboard',
            'profile' => 'User Profile'
        ];
    }

    public function onRun()
    {
        // Handle Auth0 callback
        if ($this->param('auth0') === 'callback') {
            return $this->handleAuth0Callback();
        }

        $this->page['auth0Configured'] = Settings::isConfigured();
        $this->page['auth0LoginUrl'] = $this->getAuth0LoginUrl();
        $this->page['showTraditionalLogin'] = $this->property('showTraditionalLogin');
        $this->page['buttonText'] = $this->property('buttonText');
        $this->page['buttonClass'] = $this->property('buttonClass');
        $this->page['user'] = Auth::user();
    }

    /**
     * Handle Auth0 login redirect
     */
    public function onAuth0Login()
    {
        if (!Settings::isConfigured()) {
            Flash::error('Auth0 is not properly configured');
            return;
        }

        try {
            $provider = new Auth0Provider();
            return $provider->redirectToAuth0();
        } catch (Exception $e) {
            Flash::error('Failed to connect to Auth0: ' . $e->getMessage());
        }
    }

    /**
     * Handle Auth0 callback
     */
    protected function handleAuth0Callback()
    {
        try {
            $provider = new Auth0Provider();
            return $provider->handleCallback();
        } catch (Exception $e) {
            Flash::error('Authentication failed: ' . $e->getMessage());
            return Redirect::to('/');
        }
    }

    /**
     * Get Auth0 login URL
     */
    protected function getAuth0LoginUrl()
    {
        return url($this->page->url . '/auth0/callback');
    }

    /**
     * Handle Auth0 logout
     */
    public function onAuth0Logout()
    {
        Auth::logout();
        
        $provider = new Auth0Provider();
        return $provider->logout();
    }
}