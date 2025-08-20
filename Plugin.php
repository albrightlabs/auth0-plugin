<?php namespace Albrightlabs\Auth0;

use App;
use Event;
use System\Classes\PluginBase;
use System\Classes\SettingsManager;
use RainLab\User\Controllers\Users as UsersController;
use RainLab\User\Models\User as UserModel;
use RainLab\User\Models\UserGroup;
use Route;

class Plugin extends PluginBase
{
    public $require = ['RainLab.User'];

    public function pluginDetails()
    {
        return [
            'name'        => 'Auth0 Integration',
            'description' => 'Provides Auth0 authentication for RainLab.User plugin',
            'author'      => 'Albright Labs',
            'icon'        => 'icon-shield',
            'homepage'    => 'https://albrightlabs.com'
        ];
    }

    public function register()
    {
        // Register SocialiteProviders manager
        $this->app->register(\SocialiteProviders\Manager\ServiceProvider::class);
    }

    public function boot()
    {
        // Register socialite providers first, before any routes
        $this->bootSocialiteProviders();
        $this->registerRoutes();
        $this->extendUserModel();
        $this->extendUserController();
        $this->registerAuthenticationEvents();
    }

    public function registerComponents()
    {
        return [
            \Albrightlabs\Auth0\Components\Auth0Login::class => 'auth0Login',
        ];
    }

    public function registerSettings()
    {
        return [
            'settings' => [
                'label' => 'Auth0 Settings',
                'description' => 'Configure Auth0 authentication settings',
                'category' => SettingsManager::CATEGORY_USERS,
                'icon' => 'icon-shield',
                'class' => \Albrightlabs\Auth0\Models\Settings::class,
                'order' => 600,
                'permissions' => ['rainlab.users.access_settings'],
                'size' => 'adaptive'
            ]
        ];
    }


    protected function extendUserModel()
    {
        UserModel::extend(function($model) {
            $model->addFillable([
                'auth0_id',
                'auth0_access_token',
                'auth0_refresh_token',
                'social_avatar'
            ]);
            
            // Add accessor for compatibility
            $model->addDynamicMethod('getNameAttribute', function() use ($model) {
                return $model->first_name;
            });
            
            $model->addDynamicMethod('getSurnameAttribute', function() use ($model) {
                return $model->last_name;
            });
            
        });
    }

    protected function extendUserController()
    {
        UsersController::extendFormFields(function($form, $model, $context) {
            if (!$model instanceof UserModel) {
                return;
            }

            $fields = [
                'auth0_id' => [
                    'label' => 'Auth0 ID',
                    'type' => 'text',
                    'tab' => 'Auth0',
                    'disabled' => true
                ],
                'social_avatar' => [
                    'label' => 'Social Avatar URL',
                    'type' => 'text',
                    'tab' => 'Auth0',
                    'disabled' => true
                ]
            ];
            
            $form->addTabFields($fields);
        });
    }

    protected function registerAuthenticationEvents()
    {
        Event::listen('rainlab.user.beforeAuthenticate', function($component, $credentials) {
            $auth0Provider = new \Albrightlabs\Auth0\Classes\Auth0Provider();
            return $auth0Provider->handleBeforeAuthenticate($component, $credentials);
        });
    }

    protected function registerRoutes()
    {
        Route::group(['middleware' => ['web']], function () {
            Route::get('/auth0/callback', function() {
                try {
                    $provider = new \Albrightlabs\Auth0\Classes\Auth0Provider();
                    return $provider->handleCallback();
                } catch (\Exception $e) {
                    // Enhanced error display when main logging fails
                    if (!App::environment('production')) {
                        return response('<pre>Auth0 Error: ' . $e->getMessage() . "\n\nTrace:\n" . $e->getTraceAsString() . '</pre>', 500);
                    }
                    throw $e;
                }
            });

            Route::post('/auth0/webhook', function() {
                // Fire event for webhook processing
                $payload = request()->all();
                Event::fire('albrightlabs.auth0.webhookReceived', [$payload]);
                return response()->json(['success' => true]);
            });

            Route::get('/auth0/login', function() {
                // Check if there's an intended URL stored by ResourceDetail or other components
                if (\Session::has('url.intended')) {
                    // Transfer it to auth0_intended for the Auth0Provider to use
                    $intendedUrl = \Session::get('url.intended');
                    \Session::put('auth0_intended', $intendedUrl);
                    \Session::forget('url.intended'); // Clean up the old key
                }
                
                $provider = new \Albrightlabs\Auth0\Classes\Auth0Provider();
                return $provider->redirectToAuth0();
            });

            Route::get('/auth0/logout', function() {
                \Auth::logout();
                $provider = new \Albrightlabs\Auth0\Classes\Auth0Provider();
                return $provider->logout();
            });
        });
    }

    protected function bootSocialiteProviders()
    {
        // Configure Socialite Providers event listener
        Event::listen(\SocialiteProviders\Manager\SocialiteWasCalled::class, function($socialiteWasCalled) {
            $socialiteWasCalled->extendSocialite('auth0', \SocialiteProviders\Auth0\Provider::class);
        });
        
        // Also try direct registration as a fallback
        $socialite = $this->app->make('Laravel\Socialite\Contracts\Factory');
        $socialite->extend('auth0', function ($app) use ($socialite) {
            $config = $app['config']['services.auth0'];
            return $socialite->buildProvider(\SocialiteProviders\Auth0\Provider::class, $config);
        });
    }
}