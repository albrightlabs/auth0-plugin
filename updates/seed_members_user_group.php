<?php namespace AlbrightLabs\Auth0\Updates;

use RainLab\User\Models\UserGroup;
use October\Rain\Database\Updates\Seeder;

class SeedMembersUserGroup extends Seeder
{
    public function run()
    {
        // Check if Members group already exists
        $membersGroup = UserGroup::where('code', 'members')->first();
        
        if (!$membersGroup) {
            UserGroup::create([
                'name' => 'Members',
                'code' => 'members',
                'description' => 'Default group for authenticated members'
            ]);
        }
    }
}