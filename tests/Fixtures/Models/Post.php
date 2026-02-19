<?php

namespace Devlin\ModelAnalyzer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Post extends Model
{
    protected $table = 'posts';

    /**
     * Correct inverse of User::posts().
     */
    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Has correct inverse in Comment.
     */
    public function comments()
    {
        return $this->hasMany(Comment::class);
    }
}
