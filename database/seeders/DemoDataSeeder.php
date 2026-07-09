<?php

namespace Database\Seeders;

use App\Actions\SeedCampaignDispositions;
use App\Enums\CallDirection;
use App\Enums\CallOutcome;
use App\Enums\CampaignTemplate;
use App\Enums\LeadStatus;
use App\Enums\RoleName;
use App\Enums\ScriptType;
use App\Enums\TenantStatus;
use App\Models\Call;
use App\Models\Campaign;
use App\Models\Disposition;
use App\Models\Lead;
use App\Models\Script;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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

        $agents = $this->seedLabAgents();
        $this->seedLabCalls($agents);

        $this->command?->info('Demo data seeded. Logins (password "'.self::DEMO_PASSWORD.'"): tl.acme@demo.test, qc.acme@demo.test, tl.edtech@demo.test, qc.edtech@demo.test, abc@gmail.com, def@gmail.com');
    }

    /**
     * The lab agent roster in the Acme demo tenant. The first two are the softphone
     * agents from the telephony phone directory (config/telephony.php → agent.directory),
     * keyed by USER ID: 6 → extension 1003, 7 → extension 1004. They MUST be seeded
     * first so a fresh rebuild (AdminUserSeeder = id 1, demo TL/QC = ids 2–5) lands
     * them on exactly ids 6 and 7 — the original lab emails are kept on purpose (the
     * lab runbook and muscle memory reference them). The remaining eight are pure
     * data/analytics agents (no softphone extension) so the reporting screens and the
     * Live Agent Board (LB-1) have a believable floor to show, not two rows.
     *
     * @return array<int, User> the ten agents, for seedLabCalls to attribute calls to
     */
    private function seedLabAgents(): array
    {
        $tenant = Tenant::query()->where('slug', 'demo-acme-outbound')->firstOrFail();

        $roster = [
            ['name' => 'Abhikesh', 'email' => 'abc@gmail.com'],
            ['name' => 'Demo Agent Two', 'email' => 'def@gmail.com'],
            ['name' => 'Preeti Chauhan', 'email' => 'preeti.agent@demo.test'],
            ['name' => 'Ritika Harnot', 'email' => 'ritika.agent@demo.test'],
            ['name' => 'Abhishek Loumta', 'email' => 'abhishek.agent@demo.test'],
            ['name' => 'Yamini Sharma', 'email' => 'yamini.agent@demo.test'],
            ['name' => 'Priya Chandel', 'email' => 'priya.agent@demo.test'],
            ['name' => 'Madhumita Thakur', 'email' => 'madhumita.agent@demo.test'],
            ['name' => 'Hemlata Hernot', 'email' => 'hemlata.agent@demo.test'],
            ['name' => 'Sunil Verma', 'email' => 'sunil.agent@demo.test'],
        ];

        return array_map(
            fn (array $spec): User => $this->seedDemoUser($tenant, $spec['name'], $spec['email'], RoleName::Agent),
            $roster,
        );
    }

    /**
     * A believable two weeks of call history for the Acme lab agents (Live Agent Board,
     * Slice 1). Written in the EXACT coarse v1 shape the real console wrap-up produces
     * (AgentConsole::recordCall): agent-reported direction / disposition / outcome and a
     * coarse ended_at, with NO call-duration fields — the timing columns stay null just
     * as they do for a real call today, so seeded and real rows are indistinguishable in
     * shape and no screen can ever show a made-up duration as if it were real. The real
     * line's watcher will fill the timing later, for seeded and real rows alike.
     *
     * Seed-once (guarded on any existing call), so re-running the demo seeder never
     * doubles the history. "Today" is intentionally sparse — it fills up as you make
     * real calls through the console on demo day (the manual half of Slice 1).
     *
     * @param  array<int, User>  $agents
     */
    private function seedLabCalls(array $agents): void
    {
        $tenant = Tenant::query()->where('slug', 'demo-acme-outbound')->firstOrFail();

        TenantContext::run($tenant->id, function () use ($agents, $tenant): void {
            if (Call::query()->exists()) {
                return; // seed-once: history already present
            }

            $campaigns = Campaign::query()->with('dispositions')->get();

            if ($campaigns->isEmpty() || $agents === []) {
                return;
            }

            $leadsByCampaign = $campaigns->mapWithKeys(fn (Campaign $campaign): array => [
                $campaign->id => Lead::query()->where('campaign_id', $campaign->id)->get(['id', 'campaign_id', 'phone']),
            ]);

            $ourNumber = config('telephony.outbound.caller_id') ?: '18005550100';

            for ($daysAgo = 13; $daysAgo >= 0; $daysAgo--) {
                foreach ($agents as $agent) {
                    $callCount = fake()->numberBetween(0, 8);

                    for ($i = 0; $i < $callCount; $i++) {
                        $this->makeLabCall(
                            $agent,
                            $campaigns->random(),
                            $leadsByCampaign,
                            $ourNumber,
                            Carbon::today()->subDays($daysAgo)->setTime(fake()->numberBetween(9, 17), fake()->numberBetween(0, 59)),
                        );
                    }
                }
            }

            $this->command?->info('Lab call history seeded for '.$tenant->name.'.');
        });
    }

    /**
     * One lab call in the coarse v1 shape (see seedLabCalls). Direction is ~15% inbound;
     * the disposition + outcome follow the real wrap-up mapping. A slice of answered
     * calls carry a recording so the coverage tile reads a real percentage. A call whose
     * randomised time would land in the future (today, near now) is skipped.
     *
     * @param  Collection<int, Collection<int, Lead>>  $leadsByCampaign
     */
    private function makeLabCall(User $agent, Campaign $campaign, Collection $leadsByCampaign, string $ourNumber, Carbon $when): void
    {
        if ($when->isFuture()) {
            return;
        }

        $leads = $leadsByCampaign->get($campaign->id);
        $lead = $leads instanceof Collection && $leads->isNotEmpty() ? $leads->random() : null;

        $isInbound = fake()->boolean(15);
        $disposition = $this->pickLabDisposition($campaign->dispositions);
        $leadNumber = $lead?->phone ?: fake()->numerify('9#########');

        $call = new Call([
            'direction' => $isInbound ? CallDirection::Inbound : CallDirection::Outbound,
            'from_number' => $isInbound ? $leadNumber : $ourNumber,
            'to_number' => $isInbound ? $ourNumber : $leadNumber,
            'lead_id' => $lead?->id,
            'campaign_id' => $campaign->id,
            'agent_id' => $agent->id,
            'disposition_id' => $disposition?->id,
            'outcome' => $this->labOutcome($disposition),
            'ended_at' => $when->copy()->addMinutes(fake()->numberBetween(1, 6)),
        ]);

        if ($disposition?->is_contact && fake()->boolean(30)) {
            $call->correlation_id = (string) Str::uuid();
            $call->recording_disk = 'recordings';
            $call->recording_path = 'calls/'.Str::uuid().'.mp3';
        }

        // Preserve the historical instant: making the timestamps dirty stops Eloquent
        // overwriting them with now() on insert.
        $call->created_at = $when;
        $call->updated_at = $when;

        // Seeds are silent in the audit log (the SeedBreakCategories posture) — hundreds
        // of demo calls are platform noise, not human actions.
        $call->disableLogging();
        $call->save();
    }

    /**
     * A weighted disposition pick that keeps the demo's outcome mix believable: about
     * one call in ten is ad-hoc (no disposition), the rest lean to contacts with sales
     * the minority — so the Sales tile stays a small slice, not half the board. Falls
     * back to any disposition when a bucket is empty for this campaign's template.
     *
     * @param  Collection<int, Disposition>  $dispositions
     */
    private function pickLabDisposition(Collection $dispositions): ?Disposition
    {
        if ($dispositions->isEmpty() || fake()->boolean(10)) {
            return null;
        }

        $roll = fake()->numberBetween(1, 100);

        $bucket = match (true) {
            $roll <= 45 => $dispositions->where('is_contact', true)->where('is_sale', false),
            $roll <= 85 => $dispositions->where('is_contact', false),
            default => $dispositions->where('is_sale', true), // ~15%: sales stay the minority
        };

        return ($bucket->isNotEmpty() ? $bucket : $dispositions)->random();
    }

    /**
     * The v1 outcome mapping, identical to AgentConsole::outcomeFor — a contact
     * disposition reads Answered, a non-contact reads No answer, and a dispositionless
     * ad-hoc call leaves the outcome null for the trunk-era watcher to fill.
     */
    private function labOutcome(?Disposition $disposition): ?CallOutcome
    {
        if ($disposition === null) {
            return null;
        }

        return $disposition->is_contact ? CallOutcome::Answered : CallOutcome::NoAnswer;
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

    private function seedDemoUser(Tenant $tenant, string $name, string $email, RoleName $role): User
    {
        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make(self::DEMO_PASSWORD), 'email_verified_at' => now()],
        );

        $user->tenants()->syncWithoutDetaching($tenant);

        // Role assignment resolves to this tenant's team via TenantTeamResolver.
        TenantContext::run($tenant->id, fn () => $user->assignRole($role->value));

        return $user;
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
