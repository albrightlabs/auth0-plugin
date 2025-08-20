<?php

use October\Rain\Database\Schema\Blueprint;
use October\Rain\Database\Updates\Migration;

class IncreaseSocialAvatarColumnLength extends Migration
{
    public function up()
    {
        Schema::table('users', function (Blueprint $table) {
            // Change social_avatar from varchar(500) to text to handle long OAuth URLs
            $table->text('social_avatar')->nullable()->change();
        });
    }

    public function down()
    {
        Schema::table('users', function (Blueprint $table) {
            // Revert back to original size if needed
            $table->string('social_avatar', 500)->nullable()->change();
        });
    }
}