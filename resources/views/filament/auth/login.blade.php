{{--
Branded split-screen login (design language from welcome.blade.php:
navy/teal/cream, Montserrat headings, grid + glow, floating ops card).
The form block is Filament's stock $this->content — credentials and the
2FA challenge included — so auth behavior is untouched. Styles are inline
like the welcome page, so no asset rebuild is needed.
--}}

@php
    $isMfaChallenge = filled($this->userUndertakingMultiFactorAuthentication);
@endphp

<div class="hcl-shell">
    {{-- The panel remembers a per-user dark theme in localStorage; the login
    shell is designed light-only (matching the public welcome page), so
    drop the dark class the base layout's head script may have applied.
    The stored preference is left alone and re-applies after sign-in. --}}
    <script>
        document.documentElement.classList.remove('dark');
    </script>

    <style>
        @import url('https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&display=swap');

        .hcl-shell {
            --hcl-navy: #1b3a6b;
            --hcl-navy-dark: #0f2347;
            --hcl-navy-light: #2c5282;
            --hcl-teal: #00969b;
            --hcl-teal-light: #00b5bb;
            --hcl-cream: #f5f7fa;
            --hcl-ink: #1a1a2e;
            --hcl-muted: #64748b;

            display: grid;
            grid-template-columns: minmax(0, 11fr) minmax(0, 9fr);
            min-height: 100dvh;
            background: var(--hcl-cream);
            color: var(--hcl-ink);
        }

        .hcl-shell .hcl-font-heading {
            font-family: 'Montserrat', ui-sans-serif, sans-serif;
            letter-spacing: -0.015em;
        }

        /* ---------- Brand side ---------- */

        .hcl-brand {
            position: relative;
            overflow: hidden;
            display: flex;
            flex-direction: column;
            justify-content: center;
            gap: 2.25rem;
            padding: 4rem 4.5rem 5.5rem;
            background: linear-gradient(160deg, var(--hcl-navy-dark) 0%, var(--hcl-navy) 55%, var(--hcl-navy-light) 100%);
            color: #fff;
        }

        .hcl-brand::before {
            content: '';
            position: absolute;
            inset: 0;
            background-image:
                linear-gradient(to right, rgba(255, 255, 255, 0.05) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(255, 255, 255, 0.05) 1px, transparent 1px);
            background-size: 44px 44px;
            pointer-events: none;
        }

        .hcl-brand::after {
            content: '';
            position: absolute;
            top: -35%;
            right: -20%;
            width: 640px;
            height: 640px;
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(0, 181, 187, 0.28), transparent 70%);
            filter: blur(18px);
            pointer-events: none;
        }

        .hcl-brand>* {
            position: relative;
            z-index: 1;
        }

        .hcl-wordmark {
            display: flex;
            align-items: center;
            gap: 0.65rem;
        }

        .hcl-wordmark-tile {
            width: 2.25rem;
            height: 2.25rem;
            border-radius: 0.55rem;
            background: var(--hcl-teal);
            display: grid;
            place-items: center;
            font-weight: 800;
            font-size: 1.05rem;
            box-shadow: 0 12px 28px -12px rgba(0, 181, 187, 0.7);
        }

        .hcl-wordmark-name {
            font-weight: 700;
            font-size: 1.05rem;
        }

        .hcl-headline {
            font-size: clamp(2rem, 3.1vw, 2.9rem);
            font-weight: 800;
            line-height: 1.06;
            margin: 0;
        }

        .hcl-headline .hcl-grad {
            background: linear-gradient(90deg, #5eead4 0%, var(--hcl-teal-light) 55%, #7cc4fa 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        .hcl-tagline {
            max-width: 30rem;
            margin: 0;
            color: rgba(255, 255, 255, 0.72);
            line-height: 1.65;
        }

        /* Floating live-call card — previews the agent desktop */

        .hcl-card {
            display: flex;
            align-items: center;
            gap: 0.9rem;
            max-width: 23rem;
            padding: 1rem 1.15rem;
            border-radius: 1rem;
            background: #fff;
            color: var(--hcl-ink);
            box-shadow: 0 32px 60px -24px rgba(4, 16, 38, 0.6);
            animation: hclFloat 6s ease-in-out infinite;
        }

        .hcl-card-avatar {
            width: 2.6rem;
            height: 2.6rem;
            flex-shrink: 0;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-weight: 700;
            font-size: 0.85rem;
            color: #fff;
            background: linear-gradient(135deg, var(--hcl-teal), var(--hcl-navy-light));
            animation: hclPulse 1.9s infinite;
        }

        .hcl-card-body {
            flex: 1;
            min-width: 0;
        }

        .hcl-card-kicker {
            font-size: 0.62rem;
            font-weight: 800;
            letter-spacing: 0.14em;
            text-transform: uppercase;
            color: var(--hcl-teal);
        }

        .hcl-card-name {
            font-weight: 600;
            font-size: 0.92rem;
        }

        .hcl-card-sub {
            font-size: 0.74rem;
            color: var(--hcl-muted);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .hcl-waves {
            display: flex;
            align-items: flex-end;
            gap: 3px;
            height: 24px;
        }

        .hcl-waves span {
            width: 3px;
            border-radius: 2px;
            background: var(--hcl-teal);
            transform-origin: center;
            animation: hclWave 1.1s ease-in-out infinite;
        }

        .hcl-trust {
            list-style: none;
            display: flex;
            flex-direction: column;
            gap: 0.55rem;
            margin: 0;
            padding: 0;
            font-size: 0.86rem;
            color: rgba(255, 255, 255, 0.75);
        }

        .hcl-trust li::before {
            content: '✓';
            color: #5eead4;
            font-weight: 700;
            margin-right: 0.6rem;
        }

        .hcl-brand-foot {
            position: absolute;
            bottom: 1.9rem;
            left: 4.5rem;
            z-index: 1;
            font-size: 0.68rem;
            font-weight: 600;
            letter-spacing: 0.16em;
            text-transform: uppercase;
            color: rgba(255, 255, 255, 0.42);
        }

        /* ---------- Form side ---------- */

        .hcl-panel {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 3rem 1.5rem;
            background-color: var(--hcl-cream);
            background-image: radial-gradient(rgba(27, 58, 107, 0.09) 1px, transparent 1px);
            background-size: 22px 22px;
        }

        .hcl-panel-inner {
            width: 100%;
            max-width: 26.5rem;
        }

        .hcl-mobile-brand {
            display: none;
            align-items: center;
            justify-content: center;
            gap: 0.65rem;
            margin-bottom: 1.75rem;
            color: var(--hcl-navy);
        }

        .hcl-panel-card {
            background: #fff;
            border: 1px solid rgba(27, 58, 107, 0.08);
            border-radius: 1.25rem;
            padding: 2.5rem 2.25rem;
            box-shadow: 0 1px 2px rgba(15, 35, 71, 0.04), 0 28px 56px -28px rgba(15, 35, 71, 0.22);
        }

        .hcl-logo {
            height: 3.25rem;
            width: auto;
            mix-blend-mode: multiply;
            margin-bottom: 1.4rem;
        }

        .hcl-heading {
            font-size: 1.55rem;
            font-weight: 700;
            color: var(--hcl-navy);
            margin: 0 0 0.4rem;
        }

        .hcl-sub {
            font-size: 0.92rem;
            color: var(--hcl-muted);
            margin: 0 0 1.75rem;
            line-height: 1.6;
        }

        /* Gentle polish on the stock Filament controls, nothing structural. */
        .hcl-form .fi-btn {
            border-radius: 0.7rem;
            box-shadow: 0 18px 38px -18px rgba(0, 150, 155, 0.65);
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .hcl-form .fi-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 22px 44px -18px rgba(0, 150, 155, 0.75);
        }

        .hcl-foot {
            margin: 1.9rem 0 0;
            text-align: center;
            font-size: 0.74rem;
            color: var(--hcl-muted);
        }

        /* ---------- Motion ---------- */

        .hcl-rise {
            opacity: 0;
            transform: translateY(16px);
            animation: hclRise 0.7s cubic-bezier(0.22, 1, 0.36, 1) forwards;
        }

        @keyframes hclRise {
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes hclFloat {

            0%,
            100% {
                transform: translateY(0);
            }

            50% {
                transform: translateY(-10px);
            }
        }

        @keyframes hclPulse {
            0% {
                box-shadow: 0 0 0 0 rgba(0, 181, 187, 0.5);
            }

            70% {
                box-shadow: 0 0 0 12px rgba(0, 181, 187, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(0, 181, 187, 0);
            }
        }

        @keyframes hclWave {

            0%,
            100% {
                transform: scaleY(0.35);
            }

            50% {
                transform: scaleY(1);
            }
        }

        @media (max-width: 1023px) {
            .hcl-shell {
                grid-template-columns: 1fr;
            }

            .hcl-brand {
                display: none;
            }

            .hcl-mobile-brand {
                display: flex;
            }

            .hcl-panel-card {
                padding: 2rem 1.5rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            .hcl-rise {
                animation: none;
                opacity: 1;
                transform: none;
            }

            .hcl-card,
            .hcl-card-avatar,
            .hcl-waves span {
                animation: none;
            }
        }
    </style>

    <aside class="hcl-brand">
        <div class="hcl-wordmark hcl-rise">
            <span class="hcl-wordmark-tile hcl-font-heading">H</span>
            <span class="hcl-wordmark-name hcl-font-heading">HighlandConnect</span>
        </div>

        <h1 class="hcl-headline hcl-font-heading hcl-rise" style="animation-delay: 0.08s">
            Customer ops,<br />
            <span class="hcl-grad">built for India's</span><br />
            next-gen brands.
        </h1>

        <p class="hcl-tagline hcl-rise" style="animation-delay: 0.16s">
            One agent desktop for voice, WhatsApp and email — blended operations
            with compliance and bilingual support built in from day one.
        </p>

        <ul class="hcl-trust hcl-rise" style="animation-delay: 0.32s">
            <li>DPDP 2023 &amp; TRAI/DND ready</li>
            <li>Bilingual EN + HI from day one</li>
            <li>99.5% platform uptime SLA</li>
        </ul>

        <div class="hcl-brand-foot">Shimla · Women-led · 100% on-site</div>
    </aside>

    <section class="hcl-panel">
        <div class="hcl-panel-inner hcl-rise" style="animation-delay: 0.12s">
            <div class="hcl-mobile-brand">
                <span class="hcl-wordmark-tile hcl-font-heading">H</span>
                <span class="hcl-wordmark-name hcl-font-heading">HighlandConnect</span>
            </div>

            <div class="hcl-panel-card">
                <img src="{{ asset('images/logo.png') }}" alt="HighlandConnect" class="hcl-logo" />

                <h2 class="hcl-heading hcl-font-heading">
                    {{ $isMfaChallenge ? $this->getHeading() : 'Welcome back' }}
                </h2>

                <p class="hcl-sub">
                    {{ $isMfaChallenge
    ? ($this->getSubheading() ?? 'Enter the code from your authenticator app.')
    : 'Sign in to your HighlandConnect workspace.' }}
                </p>

                <div class="hcl-form">
                    {{ $this->content }}
                </div>
            </div>

            <p class="hcl-foot">© {{ date('Y') }} HighlandConnect · DPDP &amp; TRAI compliant</p>
        </div>
    </section>
</div>