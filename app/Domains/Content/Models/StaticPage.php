<?php

namespace App\Domains\Content\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'title',
    'slug',
    'excerpt',
    'content',
    'meta_title',
    'meta_description',
    'is_published',
    'published_at',
])]
class StaticPage extends Model
{
    protected function casts(): array
    {
        return [
            'is_published' => 'boolean',
            'published_at' => 'datetime',
        ];
    }
}
