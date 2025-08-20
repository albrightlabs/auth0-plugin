<?php namespace AlbrightLabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

/**
 * AddMembershipCertificationFieldsToUsers Migration
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
            if (!Schema::hasColumn('users', 'rhythm_membership_data')) {
                $table->json('rhythm_membership_data')->nullable()->after('rhythm_profile_updated_at');
            }
            
            if (!Schema::hasColumn('users', 'rhythm_certification_data')) {
                $table->json('rhythm_certification_data')->nullable()->after('rhythm_membership_data');
            }
        });
    }

    /**
     * down reverses the migration
     */
    public function down()
    {
        Schema::table('users', function(Blueprint $table) {
            if (Schema::hasColumn('users', 'rhythm_membership_data')) {
                $table->dropColumn('rhythm_membership_data');
            }
            
            if (Schema::hasColumn('users', 'rhythm_certification_data')) {
                $table->dropColumn('rhythm_certification_data');
            }
        });
    }
};