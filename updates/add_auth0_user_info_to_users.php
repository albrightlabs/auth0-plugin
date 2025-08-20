<?php namespace Albrightlabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddAuth0UserInfoToUsers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'auth0_user_info')) {
            Schema::table('users', function($table) {
                $table->text('auth0_user_info')->nullable()->after('auth0_id_token');
                $table->timestamp('auth0_user_info_updated_at')->nullable()->after('auth0_user_info');
            });
        }
    }
    
    public function down()
    {
        if (Schema::hasColumn('users', 'auth0_user_info')) {
            Schema::table('users', function($table) {
                $table->dropColumn(['auth0_user_info', 'auth0_user_info_updated_at']);
            });
        }
    }
}