<?php

namespace MatrixOne\Tests\Feature\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;

class Country extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return HasMany<User, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /**
     * @return HasManyThrough<Post, User, $this>
     */
    public function posts(): HasManyThrough
    {
        return $this->hasManyThrough(Post::class, User::class);
    }

    /**
     * @return HasOneThrough<Post, User, $this>
     */
    public function latestPost(): HasOneThrough
    {
        return $this->hasOneThrough(Post::class, User::class)->latestOfMany();
    }
}
