<?php namespace AlbrightLabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class CreateAuth0FieldsInUsersTable extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'auth0_id')) {
            Schema::table('users', function($table) {
                $table->string('auth0_id')->nullable()->unique()->after('id');
                $table->text('auth0_access_token')->nullable();
                $table->text('auth0_refresh_token')->nullable();
                $table->string('social_avatar', 500)->nullable();
                
                $table->index('auth0_id');
            });
        }
    }

    public function down()
    {
        if (Schema::hasColumn('users', 'auth0_id')) {
            Schema::table('users', function($table) {
                $table->dropIndex(['auth0_id']);
                $table->dropColumn('auth0_id');
                $table->dropColumn('auth0_access_token');
                $table->dropColumn('auth0_refresh_token');
                $table->dropColumn('social_avatar');
            });
        }
    }
}