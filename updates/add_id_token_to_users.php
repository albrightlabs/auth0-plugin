<?php namespace AlbrightLabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddIdTokenToUsers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'auth0_id_token')) {
            Schema::table('users', function($table) {
                $table->text('auth0_id_token')->nullable()->after('auth0_refresh_token');
            });
        }
    }
    
    public function down()
    {
        if (Schema::hasColumn('users', 'auth0_id_token')) {
            Schema::table('users', function($table) {
                $table->dropColumn('auth0_id_token');
            });
        }
    }
}