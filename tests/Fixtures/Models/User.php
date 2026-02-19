<?php

namespace Devlin\ModelAnalyzer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class User extends Model
{
    protected $table = 'users';

    /** Has correct inverse in Post */
    public function posts()
    {
        return $this->hasMany(Post::class);
    }

    /** Creates circular dependency: User hasOne Profile, Profile hasOne User */
    public function profile()
    {
        return $this->hasOne(Profile::class);
    }

    /** Polymorphic – no simple inverse required */
    public function comments()
    {
        return $this->morphMany(Comment::class, 'commentable');
    }
}
