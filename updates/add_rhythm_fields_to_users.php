<?php namespace Albrightlabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * AddRhythmFieldsToUsers Migration
 *
 * @link https://docs.octobercms.com/3.x/extend/database/structure.html
 */
return new class extends Migration
{
    /**
     * up builds the migration
     */
    public function up()
    {
        Schema::table('users', function(Blueprint $table) {
            if (!Schema::hasColumn('users', 'rhythm_profile_data')) {
                $table->json('rhythm_profile_data')->nullable()->after('auth0_refresh_token');
            }
            
            if (!Schema::hasColumn('users', 'rhythm_profile_updated_at')) {
                $table->timestamp('rhythm_profile_updated_at')->nullable()->after('rhythm_profile_data');
            }
        });
    }

    /**
     * down reverses the migration
     */
    public function down()
    {
        Schema::table('users', function(Blueprint $table) {
            if (Schema::hasColumn('users', 'rhythm_profile_data')) {
                $table->dropColumn('rhythm_profile_data');
            }
            
            if (Schema::hasColumn('users', 'rhythm_profile_updated_at')) {
                $table->dropColumn('rhythm_profile_updated_at');
            }
        });
    }
};
