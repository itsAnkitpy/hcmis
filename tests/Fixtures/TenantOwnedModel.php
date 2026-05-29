<?php

namespace Tests\Fixtures;

use App\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Test-only model that exercises the BelongsToTenant trait without depending
 * on a real domain model (those arrive in M4). Its table is created per-test.
 */
class TenantOwnedModel extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_owned_models';

    protected $guarded = [];

    public $timestamps = false;
}
