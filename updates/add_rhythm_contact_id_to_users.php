<?php namespace Albrightlabs\Auth0\Updates;

use Schema;
use October\Rain\Database\Updates\Migration;

class AddRhythmContactIdToUsers extends Migration
{
    public function up()
    {
        if (!Schema::hasColumn('users', 'rhythm_contact_id')) {
            Schema::table('users', function($table) {
                $table->string('rhythm_contact_id', 100)->nullable()->after('rhythm_status')->index();
            });
        }
    }
    
    public function down()
    {
        if (Schema::hasColumn('users', 'rhythm_contact_id')) {
            Schema::table('users', function($table) {
                $table->dropColumn('rhythm_contact_id');
            });
        }
    }
}