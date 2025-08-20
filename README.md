# Auth0 Integration for RainLab.User

This plugin extends the RainLab.User plugin to support Auth0 as an identity provider, allowing users to login using their Auth0 accounts.

## Features

- **Single Sign-On (SSO)**: Users can login using their Auth0 accounts
- **Auto User Creation**: Automatically create user accounts on first Auth0 login
- **Profile Sync**: Sync user profile data from Auth0
- **Flexible Authentication**: Support both traditional login and Auth0 login
- **User Group Assignment**: Automatically assign new users to a default group
- **Backend Configuration**: Easy configuration through October CMS backend
- **Rhythm API Integration**: Sync user profiles from Rhythm API using Auth0 tokens
- **Status-Based Access Control**: Gate content based on Rhythm user status

## Installation

1. The plugin has been created in `/plugins/albrightlabs/auth0/`
2. The required packages (Laravel Socialite and Auth0 provider) have been installed via Composer

### Database Migration

Run the following command to create the necessary database fields:
```bash
php artisan october:migrate
```

This will add the following fields to the users table:
- `auth0_id` - Unique Auth0 user identifier
- `auth0_access_token` - For API calls to Auth0
- `auth0_refresh_token` - For refreshing tokens
- `social_avatar` - User's profile picture from Auth0
- `rhythm_profile_data` - JSON field storing complete Rhythm API profile
- `rhythm_profile_updated_at` - Timestamp of last Rhythm sync
- `rhythm_status` - User status from Rhythm (for access control)

## Configuration

### 1. Auth0 Application Setup

1. Log in to your [Auth0 Dashboard](https://manage.auth0.com/)
2. Create a new Application (Regular Web Application)
3. Configure the following settings:
   - **Allowed Callback URLs**: `https://yoursite.com/auth0/callback`
   - **Allowed Logout URLs**: `https://yoursite.com/`
   - **Allowed Web Origins**: `https://yoursite.com/`

### 2. Plugin Configuration

1. Go to **Settings > Users > Auth0 Settings** in the October CMS backend
2. Enter your Auth0 credentials:
   - **Domain**: Your Auth0 domain (e.g., `your-tenant.auth0.com`)
   - **Client ID**: From your Auth0 application
   - **Client Secret**: From your Auth0 application
3. Configure additional options:
   - **Automatically Create Users**: Enable to create new users on first login
   - **Sync User Data**: Update user profiles from Auth0 on each login
   - **Default User Group**: Assign new users to a specific group

### 3. Rhythm API Configuration (Optional)

If your Auth0 setup includes Rhythm Software integration:

1. Go to the **Rhythm API** tab in Auth0 Settings
2. Configure the integration:
   - **Enable Rhythm Profile Sync**: Toggle to activate the integration
   - **Rhythm API URL**: Default is `https://api.rhythmsoftware.com`
   - **Rhythm Tenant ID**: Your organization's tenant ID (required)
3. The integration will:
   - Use the Auth0 access token to authenticate with Rhythm
   - Fetch user profile data from `/contacts/{tenantId}/current`
   - Store the complete profile in `rhythm_profile_data`
   - Extract and store the user's status for access control
   - Update user fields (name, company, phone, location) from Rhythm

## Usage

### Adding Auth0 Login to Your Pages

#### Method 1: Using the Auth0Login Component

Add the `auth0Login` component to your page:

```twig
{% component 'auth0Login' %}
```

Component properties:
- `showTraditionalLogin` - Show/hide traditional login form
- `redirectAfterLogin` - Page to redirect after successful login
- `buttonText` - Custom text for the Auth0 login button
- `buttonClass` - CSS classes for the button

#### Method 2: Integrating with RainLab.User Authentication Component

If you're already using the RainLab.User authentication component, you can add the Auth0 button:

```twig
<!-- After your existing login form -->
{% partial '@auth0_button' 
    buttonText="Login with Auth0"
    buttonClass="btn btn-primary btn-block"
%}
```

#### Method 3: Direct Links

You can also create direct links to Auth0:
- Login: `/auth0/login`
- Logout: `/auth0/logout`

### Example Page

```twig
title = "Login"
url = "/login"

[authentication]
[auth0Login]
redirectAfterLogin = "/account"
showTraditionalLogin = true
==
<div class="container">
    <div class="row">
        <div class="col-md-6 col-md-offset-3">
            <h2>Login</h2>
            
            <!-- Traditional login form -->
            {% component 'authentication' %}
            
            <!-- Auth0 login button -->
            {% component 'auth0Login' %}
        </div>
    </div>
</div>
```

## How It Works

1. **User clicks "Login with Auth0"**: Redirected to Auth0 login page
2. **User authenticates**: Auth0 validates credentials
3. **Auth0 redirects back**: To `/auth0/callback` with user data
4. **Plugin processes callback**:
   - Finds existing user by Auth0 ID or email
   - Creates new user if enabled and user doesn't exist
   - Updates user profile data if sync is enabled
   - Logs the user into October CMS
5. **User is redirected**: To the intended page or configured redirect

## Events

The plugin integrates with RainLab.User events:
- `rainlab.user.beforeAuthenticate` - Intercepts Auth0 login attempts
- `rainlab.user.login` - Fired after successful Auth0 login

## Security Considerations

- Auth0 handles authentication security
- Access tokens are stored encrypted in the database
- CSRF protection is enabled for all Auth0 routes
- Users authenticated via Auth0 are marked as verified

## Troubleshooting

### Plugin not working after installation
1. Clear application cache: `php artisan cache:clear`
2. Ensure migrations have run: `php artisan october:migrate`
3. Check Auth0 configuration in backend settings

### Users can't login
1. Verify Auth0 credentials are correct
2. Check callback URL matches Auth0 application settings
3. Ensure Auth0 domain includes protocol (https://)
4. Check browser console for JavaScript errors

### Profile data not syncing
1. Enable "Sync User Data" in plugin settings
2. Ensure Auth0 is returning user profile data
3. Check user permissions in Auth0

## Rhythm API Integration

### How It Works

When Rhythm sync is enabled:
1. User logs in via Auth0
2. Plugin receives Auth0 access token
3. Token is used to call Rhythm API: `GET /contacts/{tenantId}/current`
4. Full profile data is stored in `rhythm_profile_data` JSON field
5. Status is extracted to `rhythm_status` for quick access
6. User fields are updated (first name, last name, company, phone, address)

### Profile Data Available

The Rhythm API provides comprehensive contact information:
- Personal details (name, email, phone numbers, addresses)
- Organization information (company, job title)
- Communication preferences (opt-outs, do not call/mail)
- Social media profiles
- Custom field values
- Contact roles and status

### Status-Based Access Control

Use the stored Rhythm status to control access:

```php
// In your component
if ($user->rhythm_status === 'active') {
    // Allow access
}

// Helper methods available
if ($user->hasRhythmStatus('active')) {
    // Allow access
}

if ($user->hasRhythmStatus(['active', 'premium'])) {
    // Check multiple statuses
}
```

### Backend Features

- View Rhythm profile data in user edit form (Auth0 tab)
- See status in user list
- Manual sync button to refresh profile
- Last sync timestamp displayed

## Troubleshooting

### Plugin not working after installation
1. Clear application cache: `php artisan cache:clear`
2. Ensure migrations have run: `php artisan october:migrate`
3. Check Auth0 configuration in backend settings

### Users can't login
1. Verify Auth0 credentials are correct
2. Check callback URL matches Auth0 application settings
3. Ensure Auth0 domain includes protocol (https://)
4. Check browser console for JavaScript errors

### Profile data not syncing
1. Enable "Sync User Data" in plugin settings
2. Ensure Auth0 is returning user profile data
3. Check user permissions in Auth0

### Rhythm API issues
1. Verify Rhythm Tenant ID is set correctly
2. Check Laravel logs for Rhythm sync errors
3. Ensure Auth0 token has proper scopes for Rhythm API
4. Verify API endpoint URL is correct

## Support

For issues or questions:
1. Check Auth0 logs in your Auth0 Dashboard
2. Review October CMS system logs
3. Enable debug mode for detailed error messages
4. Check rhythm_sync_error.log for Rhythm-specific issues