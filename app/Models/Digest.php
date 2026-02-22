<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Digest extends Model
{
    /** @use HasFactory<\Database\Factories\DigestFactory> */
    use HasFactory;

    use HasUuids;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    public $incrementing = false;

    protected $fillable = [
        'feed_url',
        'name',
        'timezone',
        'filters',
        'only_prior_to_today',
    ];

    protected function casts(): array
    {
        return [
            'filters' => 'array',
            'only_prior_to_today' => 'boolean',
        ];
    }
}
