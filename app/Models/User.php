<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\RoleName;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthentication;
use Filament\Auth\MultiFactor\App\Concerns\InteractsWithAppAuthenticationRecovery;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, InteractsWithAppAuthentication, InteractsWithAppAuthenticationRecovery, Notifiable;

    /**
     * Who may log into the panel (FR-U01). A user qualifies if they are a
     * global HC role holder (super admin / no tenant membership) or they belong
     * to at least one client. Email must be verified.
     */
    public function canAccessPanel(Panel $panel): bool
    {
        if ($panel->getId() !== 'admin') {
            return false;
        }

        if (! $this->hasVerifiedEmail()) {
            return false;
        }

        return $this->hasRole(RoleName::SuperAdmin->value) || $this->tenants()->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * The clients this user may operate in (membership). Source of truth for
     * the client switcher and the access check.
     *
     * @return BelongsToMany<Tenant, $this>
     */
    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(Tenant::class, 'user_tenant')->withTimestamps();
    }

    /**
     * Whether this user is allowed to operate in the given client. The single
     * server-side gate the tenant-context bridge relies on (R-06).
     */
    public function mayAccessTenant(Tenant|int $tenant): bool
    {
        $tenantId = $tenant instanceof Tenant ? $tenant->getKey() : $tenant;

        return $this->tenants()->whereKey($tenantId)->exists();
    }

    /**
     * The client to drop the user into when they haven't chosen one.
     */
    public function defaultTenant(): ?Tenant
    {
        return $this->tenants()->orderBy('tenants.id')->first();
    }

    /**
     * Whether this user holds a global HC role (super_admin / hc_admin /
     * ops_manager). Such users operate across clients at the global team and
     * must NOT be pinned to a single client's context — doing so would hide
     * their global-team roles. Checked with no tenant context (global team).
     */
    public function operatesGlobally(): bool
    {
        return $this->hasAnyRole(RoleName::globalValues());
    }
}
