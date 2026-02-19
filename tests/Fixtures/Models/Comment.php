<?php

namespace Devlin\ModelAnalyzer\Tests\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

class Comment extends Model
{
    protected $table = 'comments';

    /** Correct inverse of Post::comments() */
    public function post()
    {
        return $this->belongsTo(Post::class);
    }

    /** Polymorphic – inverse of User::comments() and others */
    public function commentable()
    {
        return $this->morphTo();
    }
}
