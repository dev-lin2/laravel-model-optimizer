<?php

namespace Devlin\ModelAnalyzer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Profile extends Model
{
    protected $table = 'profiles';

    /**
     * Creates a circular dependency: User hasOne Profile → Profile hasOne User.
     * Should be belongsTo(User::class) but is intentionally wrong for testing.
     */
    public function user()
    {
        return $this->hasOne(User::class);
    }
}
