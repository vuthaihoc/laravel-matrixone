<?php

namespace MatrixOne\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Tag extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsToMany<Post, $this>
     */
    public function posts(): BelongsToMany
    {
        return $this->belongsToMany(Post::class);
    }

    /**
     * @return MorphToMany<Video, $this>
     */
    public function videos(): MorphToMany
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }

    /**
     * @return MorphToMany<Post, $this>
     */
    public function taggedPosts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }
}
