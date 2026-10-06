<?php

declare(strict_types=1);

namespace OneTrace\Laravel\Tests\Fixtures;

use Illuminate\Foundation\Auth\User as Authenticatable;
use OneTrace\Laravel\Contracts\HasOneTraceTraits;

class Customer extends Authenticatable implements HasOneTraceTraits
{
    protected $guarded = [];

    protected $table = 'users';

    public function oneTraceTraits(): array
    {
        return ['email' => $this->email, 'plan' => 'pro'];
    }
}
