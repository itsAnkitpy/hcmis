<?php

namespace Database\Seeders;

use App\Actions\SeedCampaignDispositions;
use App\Enums\CampaignTemplate;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Enums\ScriptType;
use App\Enums\TenantStatus;
use App\Models\Campaign;
use App\Models\Lead;
use App\Models\Script;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A self-contained demo world for the Phase-1 admin (M4.E, decision D-M4E-4).
 *
 * Local only and kept OUT of the default DatabaseSeeder chain — run explicitly:
 *   php artisan db:seed --class=DemoDataSeeder
 *
 * Builds fresh, clearly-named demo tenants (so it never collides with hand-made
 * data), each with throwaway team-leader / QC logins, a couple of campaigns
 * across templates (one carrying custom fields), template-seeded dispositions,
 * scripts, and a spread of leads that lands in every M4.D filter bucket. Safe
 * to re-run: tenants are matched by slug and per-campaign data is only built on
 * first creation.
 */
class DemoDataSeeder extends Seeder
{
    /** Throwaway password shared by every demo login. */
    private const string DEMO_PASSWORD = 'password';

    public function run(): void
    {
        if (! app()->environment('local')) {
            $this->command?->warn('DemoDataSeeder runs only in the local environment — skipping.');

            return;
        }

        $this->seedDemoTenant(
            name: 'Demo — Acme Outbound',
            slug: 'demo-acme-outbound',
            users: [
                ['name' => 'Demo TL (Acme)', 'email' => 'tl.acme@demo.test', 'role' => RoleName::TeamLeader],
                ['name' => 'Demo QC (Acme)', 'email' => 'qc.acme@demo.test', 'role' => RoleName::Qc],
            ],
            campaigns: [
                ['name' => 'Acme Q3 Outbound', 'template' => CampaignTemplate::OutboundSales, 'custom_fields' => $this->insuranceFields()],
                ['name' => 'Acme Care Callbacks', 'template' => CampaignTemplate::CustomerCareCallback, 'custom_fields' => []],
            ],
        );

        $this->seedDemoTenant(
            name: 'Demo — EdTech Co',
            slug: 'demo-edtech-co',
            users: [
                ['name' => 'Demo TL (EdTech)', 'email' => 'tl.edtech@demo.test', 'role' => RoleName::TeamLeader],
                ['name' => 'Demo QC (EdTech)', 'email' => 'qc.edtech@demo.test', 'role' => RoleName::Qc],
            ],
            campaigns: [
                ['name' => 'EdTech Enrollments', 'template' => CampaignTemplate::EdtechEnrollment, 'custom_fields' => $this->edtechFields()],
                ['name' => 'EdTech COD Checks', 'template' => CampaignTemplate::CodVerification, 'custom_fields' => []],
            ],
        );

        $this->seedLabAgents();

        $this->command?->info('Demo data seeded. Logins (password "'.self::DEMO_PASSWORD.'"): tl.acme@demo.test, qc.acme@demo.test, tl.edtech@demo.test, qc.edtech@demo.test, abc@gmail.com, def@gmail.com');
    }

    /**
     * The two lab softphone agents from the telephony phone directory
     * (config/telephony.php → agent.directory), which is keyed by USER ID:
     * 6 → extension 1003, 7 → extension 1004. Seeded after every other demo
     * login so a fresh rebuild (AdminUserSeeder = id 1, demo TL/QC = ids 2–5)
     * lands them on exactly ids 6 and 7. The original lab emails are kept on
     * purpose — the lab runbook and existing muscle memory reference them.
     */
    private function seedLabAgents(): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-acme-outbound')->firstOrFail();

        $this->seedDemoUser($tenant, 'Abhikesh', 'abc@gmail.com', RoleName::Agent);
        $this->seedDemoUser($tenant, 'Demo Agent Two', 'def@gmail.com', RoleName::Agent);
    }

    /**
     * @param  array<int, array{name: string, email: string, role: RoleName}>  $users
     * @param  array<int, array{name: string, template: CampaignTemplate, custom_fields: array<int, array<string, mixed>>}>  $campaigns
     */
    private function seedDemoTenant(string $name, string $slug, array $users, array $campaigns): void
    {
        $tenant = Tenant::firstOrCreate(
            ['slug' => $slug],
            ['name' => $name, 'status' => TenantStatus::Active],
        );

        foreach ($users as $spec) {
            $this->seedDemoUser($tenant, $spec['name'], $spec['email'], $spec['role']);
        }

        TenantContext::run($tenant->id, function () use ($campaigns) {
            foreach ($campaigns as $spec) {
                $this->seedDemoCampaign($spec);
            }
        });
    }

    private function seedDemoUser(Tenant $tenant, string $name, string $email, RoleName $role): void
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make(self::DEMO_PASSWORD), 'email_verified_at' => now()],
        );

        $user->tenants()->syncWithoutDetaching($tenant);

        // Role assignment resolves to this tenant's team via TenantTeamResolver.
        TenantContext::run($tenant->id, fn () => $user->assignRole($role->value));
    }

    /**
     * @param  array{name: string, template: CampaignTemplate, custom_fields: array<int, array<string, mixed>>}  $spec
     */
    private function seedDemoCampaign(array $spec): void
    {
        $campaign = Campaign::firstOrCreate(
            ['name' => $spec['name']],
            ['template' => $spec['template'], 'is_active' => true, 'custom_fields' => $spec['custom_fields']],
        );

        SeedCampaignDispositions::run($campaign);

        if (! $campaign->wasRecentlyCreated) {
            return;
        }

        $this->seedScripts($campaign);
        $this->seedLeads($campaign);
    }

    private function seedScripts(Campaign $campaign): void
    {
        Script::factory()->forCampaign($campaign)->type(ScriptType::Opening)->create();
        Script::factory()->forCampaign($campaign)->type(ScriptType::Closing)->create();
    }

    /**
     * Four groups, each landing in a distinct status + attempts bucket + age
     * bucket so every M4.D filter has rows to bite on in the demo (~30 leads).
     */
    private function seedLeads(Campaign $campaign): void
    {
        $this->makeLeads($campaign, LeadStatus::New, attempts: 0, daysAgo: 0, count: 8);
        $this->makeLeads($campaign, LeadStatus::InProgress, attempts: 2, daysAgo: 4, count: 7);
        $this->makeLeads($campaign, LeadStatus::Contacted, attempts: 4, daysAgo: 18, count: 8);
        $this->makeLeads($campaign, LeadStatus::Closed, attempts: 8, daysAgo: 60, count: 7);
    }

    private function makeLeads(Campaign $campaign, LeadStatus $status, int $attempts, int $daysAgo, int $count): void
    {
        Lead::factory()
            ->count($count)
            ->forCampaign($campaign)
            ->status($status)
            // self:: is lexical, so it survives the factory rebinding state
            // closures to the factory instance ($this would become the factory).
            ->state(fn (): array => [
                'attempts' => $attempts,
                'created_at' => now()->subDays($daysAgo),
                'custom_fields' => self::leadCustomFields($campaign),
            ])
            ->create();
    }

    /**
     * Sample values for the campaign's defined custom fields, so the demo shows
     * them populated on the lead form. Empty for campaigns with no custom fields.
     *
     * @return array<string, mixed>
     */
    private static function leadCustomFields(Campaign $campaign): array
    {
        $values = [];

        foreach ($campaign->custom_fields ?? [] as $field) {
            $values[$field['key']] = match ($field['type'] ?? 'text') {
                'select' => fake()->randomElement($field['options'] ?? ['—']),
                'number' => (string) fake()->numberBetween(1000, 9999),
                'date' => fake()->date(),
                default => strtoupper(fake()->bothify('??-#####')),
            };
        }

        return $values;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function insuranceFields(): array
    {
        return [
            ['key' => 'policy_number', 'label' => 'Policy Number', 'type' => 'text', 'required' => true],
            ['key' => 'plan', 'label' => 'Plan', 'type' => 'select', 'required' => false, 'options' => ['Gold', 'Silver', 'Bronze']],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function edtechFields(): array
    {
        return [
            ['key' => 'course', 'label' => 'Course', 'type' => 'select', 'required' => true, 'options' => ['Data Science', 'Web Dev', 'UX Design']],
            ['key' => 'budget', 'label' => 'Budget (INR)', 'type' => 'number', 'required' => false],
        ];
    }
}
