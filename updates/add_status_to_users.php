<?php namespace AlbrightLabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * AddStatusToUsers Migration
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
            if (!Schema::hasColumn('users', 'rhythm_status')) {
                $table->string('rhythm_status', 50)->nullable()->after('rhythm_profile_updated_at')->index();
            }
        });
    }

    /**
     * down reverses the migration
     */
    public function down()
    {
        Schema::table('users', function(Blueprint $table) {
            if (Schema::hasColumn('users', 'rhythm_status')) {
                $table->dropColumn('rhythm_status');
            }
        });
    }
};
