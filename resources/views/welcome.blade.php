<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="scroll-smooth">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    @php
        // Brand + page level — override these via view data when needed.
        $brandName = $brandName ?? config('app.name', 'HighlandConnect');
        $brandTagline = $brandTagline ?? 'Integrated Customer Operations for India\'s Next-Gen Brands';
        $pageTitle = $pageTitle ?? "{$brandName} — {$brandTagline}";

        // Safe fallback so this template works even before Filament is mounted.
        $loginUrl = $loginUrl ?? (function () {
            try {
                return filament()->getPanel('admin')?->getLoginUrl() ?? url('/admin/login');
            } catch (\Throwable $e) {
                return url('/admin/login');
            }
        })();

        $primaryCtaLabel = $primaryCtaLabel ?? 'Client login';
        $secondaryCtaLabel = $secondaryCtaLabel ?? 'Sign in';
        $contextMode = $contextMode ?? 'central';
        $workspaceName = $workspaceName ?? $brandName;

        // Anchor clients — from BRD §2.1. Swap when written permission to use logos is in hand.
        $anchorClients = $anchorClients ?? ['Uber', 'Physics Wallah', 'Metro Brands', 'Holisol', 'Shipkar', 'Exam Pur', 'Arni University'];
    @endphp
    <title>{{ $pageTitle }}</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700;800&family=Montserrat:wght@500;600;700;800;900&display=swap"
        rel="stylesheet">

    @if (file_exists(public_path('build/manifest.json')) || file_exists(public_path('hot')))
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    @else
        <script src="https://cdn.tailwindcss.com"></script>
        <script>
            tailwind.config = {
                theme: {
                    extend: {
                        colors: {
                            navy: { DEFAULT: '#1b3a6b', light: '#2c5282', dark: '#0f2347' },
                            teal: { DEFAULT: '#00969b', light: '#00b5bb', dark: '#007a7f' },
                            cream: '#f5f7fa', dark: '#1a1a2e', muted: '#64748b',
                        },
                        fontFamily: {
                            sans: ['Inter', 'ui-sans-serif', 'system-ui', 'sans-serif'],
                            heading: ['Montserrat', 'sans-serif'],
                        },
                        boxShadow: {
                            soft: '0 1px 2px rgba(15,35,71,.04), 0 8px 24px rgba(15,35,71,.06)',
                            glow: '0 20px 60px -20px rgba(0,150,155,.45)',
                        },
                    }
                }
            }
        </script>
    @endif
    <style>
        html,
        body {
            font-family: 'Inter', sans-serif;
        }

        h1,
        h2,
        h3,
        h4,
        h5,
        h6 {
            font-family: 'Montserrat', sans-serif;
            letter-spacing: -0.015em;
        }

        /* Subtle grid/dot background */
        .bg-dots {
            background-image: radial-gradient(rgba(27, 58, 107, 0.09) 1px, transparent 1px);
            background-size: 22px 22px;
        }

        .bg-grid {
            background-image:
                linear-gradient(to right, rgba(27, 58, 107, 0.06) 1px, transparent 1px),
                linear-gradient(to bottom, rgba(27, 58, 107, 0.06) 1px, transparent 1px);
            background-size: 44px 44px;
        }

        /* Hero glow */
        .hero-glow::before {
            content: "";
            position: absolute;
            inset: -40% -10% auto auto;
            width: 780px;
            height: 780px;
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(0, 181, 187, 0.22), transparent 70%);
            filter: blur(20px);
            z-index: 0;
            pointer-events: none;
        }

        .hero-glow::after {
            content: "";
            position: absolute;
            inset: auto auto -30% -10%;
            width: 620px;
            height: 620px;
            border-radius: 50%;
            background: radial-gradient(closest-side, rgba(27, 58, 107, 0.18), transparent 70%);
            filter: blur(20px);
            z-index: 0;
            pointer-events: none;
        }

        /* Floating animations */
        @keyframes float {

            0%,
            100% {
                transform: translateY(0)
            }

            50% {
                transform: translateY(-10px)
            }
        }

        .float-slow {
            animation: float 6s ease-in-out infinite;
        }

        .float-med {
            animation: float 4.5s ease-in-out infinite;
            animation-delay: .4s;
        }

        @keyframes pulseRing {
            0% {
                box-shadow: 0 0 0 0 rgba(34, 197, 94, .55);
            }

            70% {
                box-shadow: 0 0 0 14px rgba(34, 197, 94, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(34, 197, 94, 0);
            }
        }

        .pulse-ring {
            animation: pulseRing 1.8s infinite;
        }

        /* Waveform bars animating */
        @keyframes wave {

            0%,
            100% {
                transform: scaleY(0.35);
            }

            50% {
                transform: scaleY(1);
            }
        }

        .wave-bar {
            transform-origin: center;
            animation: wave 1.1s ease-in-out infinite;
        }

        /* Ticker / logo cloud */
        @keyframes marquee {
            from {
                transform: translateX(0);
            }

            to {
                transform: translateX(-50%);
            }
        }

        .marquee-track {
            animation: marquee 38s linear infinite;
        }

        /* Gradient text */
        .grad-text {
            background: linear-gradient(90deg, #00969b 0%, #00b5bb 40%, #2c5282 100%);
            -webkit-background-clip: text;
            background-clip: text;
            color: transparent;
        }

        /* Accent rule under eyebrows */
        .eyebrow-rule::before {
            content: "";
            display: inline-block;
            width: 28px;
            height: 1px;
            background: #00969b;
            vertical-align: middle;
            margin-right: 10px;
        }

        /* Bento card hover */
        .bento-card {
            transition: transform .35s cubic-bezier(.4, 0, .2, 1), box-shadow .35s ease, border-color .35s ease;
        }

        .bento-card:hover {
            transform: translateY(-4px);
            border-color: rgba(0, 150, 155, 0.35);
            box-shadow: 0 20px 50px -20px rgba(15, 35, 71, 0.25);
        }

        /* Stepper connector */
        .step-connector::after {
            content: "";
            position: absolute;
            top: 24px;
            left: 100%;
            width: 100%;
            height: 2px;
            background-image: linear-gradient(90deg, rgba(0, 150, 155, .55) 50%, transparent 0);
            background-size: 14px 2px;
            background-repeat: repeat-x;
        }

        @media (max-width: 767px) {
            .step-connector::after {
                display: none
            }
        }

        /* Stat count */
        .stat-num {
            font-feature-settings: "tnum" 1;
        }

        /* Subtle shine on primary button */
        .btn-shine {
            position: relative;
            overflow: hidden;
        }

        .btn-shine::after {
            content: "";
            position: absolute;
            inset: 0;
            background: linear-gradient(120deg, transparent 40%, rgba(255, 255, 255, 0.35) 50%, transparent 60%);
            transform: translateX(-120%);
            transition: transform .7s ease;
        }

        .btn-shine:hover::after {
            transform: translateX(120%);
        }

        /* FAQ marker removal */
        details>summary {
            list-style: none;
        }

        details>summary::-webkit-details-marker {
            display: none;
        }

        /* Scroll reveal */
        .reveal {
            opacity: 0;
            transform: translateY(18px);
            transition: opacity .7s ease, transform .7s ease;
        }

        .reveal.in {
            opacity: 1;
            transform: translateY(0);
        }
    </style>
</head>

<body class="bg-cream text-dark antialiased min-h-screen flex flex-col">

    <!-- Announcement bar -->
    <div class="w-full bg-navy-dark text-white/90 text-xs sm:text-sm">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-2.5 flex items-center justify-center gap-3">
            <span
                class="inline-flex items-center gap-1.5 bg-teal/20 text-teal-light px-2 py-0.5 rounded-full text-[11px] font-semibold tracking-wide uppercase">Live</span>
            <span class="truncate">Now serving 15+ brands across D2C, e-commerce, 3PL & EdTech.</span>
            <a href="#testimonials"
                class="hidden sm:inline-flex items-center gap-1 font-medium text-teal-light hover:text-white transition-colors">
                Our clients
                <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" />
                </svg>
            </a>
        </div>
    </div>

    <!-- Navigation -->
    <header class="w-full sticky top-0 z-50 bg-cream/80 backdrop-blur-md border-b border-transparent" id="siteHeader">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-4 flex justify-between items-center">
            <a href="/" class="flex items-center">
                <img src="{{ asset('images/logo.png') }}" alt="{{ $brandName }}"
                    class="h-14 w-auto md:h-16 mix-blend-multiply">
            </a>

            <nav class="hidden md:flex items-center gap-8 text-sm font-medium text-muted">
                <a href="#features" class="hover:text-teal transition-colors">Capabilities</a>
                <a href="#product" class="hover:text-teal transition-colors">Agent desktop</a>
                <a href="#how-it-works" class="hover:text-teal transition-colors">Onboarding</a>
                <a href="#testimonials" class="hover:text-teal transition-colors">Clients</a>
                <a href="#faq" class="hover:text-teal transition-colors">FAQ</a>
            </nav>

            <div class="flex items-center gap-2 sm:gap-4">
                <button type="button"
                    class="hidden sm:inline-flex items-center gap-1 text-xs font-semibold text-muted hover:text-navy transition-colors px-2 py-1 border border-gray-200 rounded-md"
                    title="Language toggle (EN/HI)">
                    EN / हिं
                </button>
                <a href="{{ $loginUrl }}"
                    class="hidden sm:inline-block text-sm font-medium text-navy hover:text-teal transition-colors px-2">Login</a>
                <a href="{{ $loginUrl }}"
                    class="btn-shine bg-navy hover:bg-navy-light text-white px-4 sm:px-5 py-2.5 rounded-lg font-semibold text-sm transition-colors shadow-sm inline-flex items-center gap-2">
                    {{ $secondaryCtaLabel }}
                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5"
                            d="M13 7l5 5m0 0l-5 5m5-5H6" />
                    </svg>
                </a>
            </div>
        </div>
    </header>

    <main class="flex-grow">

        <!-- Hero -->
        <section class="relative overflow-hidden hero-glow">
            <div class="absolute inset-0 bg-grid opacity-60 pointer-events-none"></div>
            <div
                class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 pt-16 pb-24 lg:pt-24 lg:pb-32 grid lg:grid-cols-12 gap-12 lg:gap-16 items-center z-10">

                <div class="lg:col-span-6">
                    <div
                        class="inline-flex items-center gap-2 bg-white border border-gray-200 shadow-soft rounded-full pl-1.5 pr-4 py-1.5 mb-7">
                        <span
                            class="inline-flex items-center gap-1 bg-teal/10 text-teal-dark text-[11px] font-bold uppercase tracking-wider px-2.5 py-1 rounded-full">
                            <span class="w-1.5 h-1.5 rounded-full bg-teal animate-pulse"></span> Shimla
                        </span>
                        <span class="text-xs sm:text-sm text-muted font-medium">Women-led · 100% on-site · DPDP &amp;
                            TRAI ready</span>
                    </div>

                    <h1
                        class="font-heading font-extrabold text-navy text-[2.6rem] sm:text-5xl lg:text-[4.2rem] leading-[1.02] tracking-tight mb-6">
                        Customer ops,<br />
                        <span class="grad-text">built for India's</span><br />
                        next-gen brands.
                    </h1>

                    <p class="text-lg text-muted leading-relaxed max-w-xl mb-8">
                        {{ $contextMode === 'tenant'
    ? "{$workspaceName} runs on HighlandConnect — blended voice, WhatsApp, and email from one agent desktop, with full DPDP and TRAI compliance built in."
    : 'HighlandConnect runs blended voice and digital operations for D2C, e-commerce, 3PL, and EdTech leaders. One agent desktop, every channel. DPDP-compliant. TRAI-compliant. Bilingual EN + HI from day one.' }}
                    </p>

                    <div class="flex flex-col sm:flex-row items-stretch sm:items-center gap-3 sm:gap-4 mb-10">
                        <a href="{{ $loginUrl }}"
                            class="btn-shine bg-teal hover:bg-teal-light text-white px-7 py-4 rounded-xl font-semibold transition-all shadow-glow inline-flex items-center justify-center gap-2">
                            {{ $primaryCtaLabel }}
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </a>
                        <a href="#product"
                            class="bg-white border border-gray-200 hover:border-teal/40 text-navy px-7 py-4 rounded-xl font-semibold transition-all shadow-sm inline-flex items-center justify-center gap-3">
                            <span class="w-8 h-8 rounded-full bg-teal/10 text-teal flex items-center justify-center">
                                <svg class="w-4 h-4 ml-0.5" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z" />
                                </svg>
                            </span>
                            See the agent desktop
                        </a>
                    </div>

                    <!-- Trust indicators -->
                    <div class="flex flex-wrap items-center gap-x-6 gap-y-3 text-sm text-muted font-medium">
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>
                            Voice + WhatsApp + email in one desk
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>
                            DPDP 2023 &amp; TRAI/DND ready
                        </div>
                        <div class="flex items-center gap-2">
                            <svg class="w-4 h-4 text-teal" fill="currentColor" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>
                            Bilingual EN + HI from day one
                        </div>
                    </div>
                </div>

                <!-- Hero mockup cluster -->
                <div class="lg:col-span-6 relative">
                    <div
                        class="absolute inset-0 bg-gradient-to-tr from-teal/20 via-transparent to-navy/10 rounded-[2rem] -rotate-1 scale-[1.03]">
                    </div>

                    <!-- Main app window -->
                    <div class="relative bg-white rounded-2xl shadow-2xl border border-gray-100 overflow-hidden">
                        <div class="bg-gray-50 border-b border-gray-100 px-4 py-3 flex items-center gap-2">
                            <div class="flex gap-1.5">
                                <div class="w-3 h-3 rounded-full bg-red-400"></div>
                                <div class="w-3 h-3 rounded-full bg-amber-400"></div>
                                <div class="w-3 h-3 rounded-full bg-green-400"></div>
                            </div>
                            <div
                                class="mx-auto bg-white rounded-md text-xs text-center text-muted px-20 py-1 border border-gray-200 inline-flex items-center gap-1">
                                <svg class="w-3 h-3 text-teal" fill="currentColor" viewBox="0 0 20 20">
                                    <path fill-rule="evenodd"
                                        d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z"
                                        clip-rule="evenodd" />
                                </svg>
                                ops.highlandconnect.in
                            </div>
                        </div>

                        <!-- App chrome: sidebar + workspace -->
                        <div class="grid grid-cols-12">
                            <!-- Sidebar -->
                            <aside
                                class="hidden sm:flex col-span-3 bg-navy-dark text-white/80 flex-col py-5 px-3 gap-1 text-xs">
                                <div class="px-2 mb-4 flex items-center gap-2">
                                    <div
                                        class="w-7 h-7 rounded-md bg-teal flex items-center justify-center text-white font-bold text-xs">
                                        H</div>
                                    <div>
                                        <div class="font-semibold text-white text-[11px]">HighlandConnect</div>
                                        <div class="text-white/50 text-[10px]">Ops workspace</div>
                                    </div>
                                </div>
                                <div
                                    class="bg-white/10 text-white rounded-md px-2.5 py-1.5 flex items-center gap-2 font-medium">
                                    <span class="w-1.5 h-1.5 rounded-full bg-teal-light"></span>Live Ops</div>
                                <div class="px-2.5 py-1.5 flex items-center gap-2 text-white/60">Campaigns</div>
                                <div class="px-2.5 py-1.5 flex items-center gap-2 text-white/60">Tickets <span
                                        class="ml-auto bg-teal text-white text-[9px] font-bold rounded-full px-1.5 py-0.5">7</span>
                                </div>
                                <div class="px-2.5 py-1.5 flex items-center gap-2 text-white/60">Calls</div>
                                <div class="px-2.5 py-1.5 flex items-center gap-2 text-white/60">Agents</div>
                                <div class="px-2.5 py-1.5 flex items-center gap-2 text-white/60">Reports</div>
                                <div class="mt-auto pt-4 border-t border-white/10 text-[10px] text-white/40 px-2">All
                                    channels live ✓</div>
                            </aside>

                            <!-- Workspace -->
                            <div class="col-span-12 sm:col-span-9 p-5 bg-gradient-to-b from-white to-cream/60">
                                <div class="flex justify-between items-start mb-5">
                                    <div>
                                        <div
                                            class="text-[10px] uppercase tracking-widest text-muted font-semibold mb-1">
                                            Right now</div>
                                        <h3 class="font-heading font-bold text-navy text-lg leading-tight">Live
                                            operations</h3>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] text-muted uppercase tracking-wider">Agents online</div>
                                        <div class="font-heading text-xl font-bold text-navy stat-num">78 / 82</div>
                                    </div>
                                </div>

                                <!-- Mini live ops board: by channel -->
                                <div class="grid grid-cols-3 gap-2 mb-4">
                                    <div>
                                        <div class="text-[10px] font-semibold text-muted mb-1.5 flex justify-between">
                                            <span>VOICE</span><span>34</span></div>
                                        <div class="h-1 rounded-full bg-gray-200 mb-2">
                                            <div class="h-full rounded-full bg-teal-dark w-4/5"></div>
                                        </div>
                                        <div class="space-y-1.5">
                                            <div
                                                class="bg-white border border-gray-100 rounded-lg p-2 shadow-sm text-[10px]">
                                                <div class="font-semibold text-navy truncate">Inbound queue · PW</div>
                                                <div class="text-muted">12 waiting · SLA 92%</div>
                                            </div>
                                            <div
                                                class="bg-white border border-gray-100 rounded-lg p-2 shadow-sm text-[10px]">
                                                <div class="font-semibold text-navy truncate">Outbound · Uber NDR</div>
                                                <div class="text-muted">22 active dials</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-semibold text-muted mb-1.5 flex justify-between">
                                            <span>WHATSAPP</span><span>21</span></div>
                                        <div class="h-1 rounded-full bg-gray-200 mb-2">
                                            <div class="h-full rounded-full bg-teal w-1/2"></div>
                                        </div>
                                        <div class="space-y-1.5">
                                            <div
                                                class="bg-white border border-teal/30 rounded-lg p-2 shadow-sm text-[10px] ring-1 ring-teal/10">
                                                <div class="font-semibold text-navy truncate">Metro Brands · returns
                                                </div>
                                                <div class="flex justify-between mt-0.5"><span class="text-muted">8
                                                        open</span><span class="text-amber-700 font-medium">2 SLA
                                                        risk</span></div>
                                            </div>
                                            <div
                                                class="bg-white border border-gray-100 rounded-lg p-2 shadow-sm text-[10px]">
                                                <div class="font-semibold text-navy truncate">Exam Pur · enrollment
                                                </div>
                                                <div class="text-muted">13 in flight</div>
                                            </div>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="text-[10px] font-semibold text-muted mb-1.5 flex justify-between">
                                            <span>EMAIL</span><span>9</span></div>
                                        <div class="h-1 rounded-full bg-gray-200 mb-2">
                                            <div class="h-full rounded-full bg-gray-400 w-1/4"></div>
                                        </div>
                                        <div class="space-y-1.5">
                                            <div
                                                class="bg-navy text-white border border-navy rounded-lg p-2 shadow-sm text-[10px]">
                                                <div class="font-semibold truncate">Holisol · L2 escalations</div>
                                                <div class="flex justify-between mt-0.5"><span>6 open</span><span
                                                        class="text-teal-light font-medium">on track</span></div>
                                            </div>
                                            <div
                                                class="bg-white border border-gray-100 rounded-lg p-2 shadow-sm text-[10px]">
                                                <div class="font-semibold text-navy truncate">Arni · admissions</div>
                                                <div class="text-muted">3 open</div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Timeline row -->
                                <div
                                    class="bg-white border border-gray-100 rounded-xl p-3 flex items-center gap-3 shadow-sm">
                                    <div
                                        class="w-8 h-8 rounded-full bg-teal/10 text-teal flex items-center justify-center flex-shrink-0">
                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                        </svg>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[11px] font-semibold text-navy truncate">Live · Priya M. on
                                            call with PW lead</div>
                                        <div class="text-[10px] text-muted">02:18 · queue: enrolment · script v3.2</div>
                                    </div>
                                    <div class="flex items-end gap-0.5">
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-2.5"></div>
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-4"
                                            style="animation-delay:.1s"></div>
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-3"
                                            style="animation-delay:.2s"></div>
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-5"
                                            style="animation-delay:.3s"></div>
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-2"
                                            style="animation-delay:.4s"></div>
                                        <div class="w-0.5 bg-teal rounded-full wave-bar h-4"
                                            style="animation-delay:.5s"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Floating incoming call card -->
                    <div class="absolute -bottom-8 -left-4 sm:-left-12 w-64 sm:w-72 float-slow z-10">
                        <div
                            class="bg-navy-dark text-white rounded-2xl shadow-2xl p-4 border border-white/10 relative overflow-hidden">
                            <div class="absolute -right-8 -top-8 w-24 h-24 rounded-full bg-teal/30 blur-2xl"></div>
                            <div class="flex items-center gap-3 relative">
                                <div class="relative">
                                    <div
                                        class="w-10 h-10 rounded-full bg-white text-navy flex items-center justify-center font-bold pulse-ring">
                                        RK</div>
                                </div>
                                <div class="flex-1 min-w-0">
                                    <div class="text-[10px] uppercase tracking-widest text-teal-light font-bold">Inbound
                                        · 00:14</div>
                                    <div class="font-semibold text-sm truncate">Rohit Kumar</div>
                                    <div class="text-[11px] text-white/60 truncate">Physics Wallah · Tier 1</div>
                                </div>
                                <button
                                    class="w-9 h-9 rounded-full bg-green-500 hover:bg-green-400 flex items-center justify-center transition-colors shadow-lg">
                                    <svg class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Floating WhatsApp card -->
                    <div class="absolute -top-6 -right-2 sm:-right-8 w-60 float-med z-10">
                        <div class="bg-white rounded-2xl shadow-2xl p-4 border border-gray-100">
                            <div class="flex items-center justify-between mb-2">
                                <span
                                    class="text-[10px] font-bold uppercase tracking-widest text-teal-dark bg-teal/10 px-2 py-0.5 rounded-full">WhatsApp
                                    · #2418</span>
                                <span class="text-[10px] text-muted">Just now</span>
                            </div>
                            <div class="font-heading font-semibold text-navy text-sm mb-1">Order #UB-9821 — refund</div>
                            <div class="text-[11px] text-muted leading-relaxed mb-3">"नमस्ते, मेरा refund अभी तक नहीं
                                आया है…"</div>
                            <div class="flex items-center gap-2">
                                <div class="flex -space-x-1.5">
                                    <div
                                        class="w-6 h-6 rounded-full bg-teal text-white text-[10px] flex items-center justify-center font-bold border-2 border-white">
                                        PM</div>
                                    <div
                                        class="w-6 h-6 rounded-full bg-navy text-white text-[10px] flex items-center justify-center font-bold border-2 border-white">
                                        SR</div>
                                </div>
                                <span class="text-[10px] text-muted">Auto-routed · SLA 30m</span>
                            </div>
                        </div>
                    </div>

                </div>
            </div>

            <!-- Logo cloud / trust strip -->
            <div class="relative border-t border-gray-200/60 bg-white/60 backdrop-blur-sm">
                <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">
                    <div class="text-center text-xs font-semibold tracking-widest uppercase text-muted mb-6">Trusted by
                        leading Indian brands</div>
                    <div class="flex flex-wrap justify-center items-center gap-x-10 gap-y-4 text-navy/60">
                        @foreach ($anchorClients as $client)
                            <div class="font-heading font-bold text-lg tracking-tight opacity-70">{{ $client }}</div>
                        @endforeach
                    </div>
                </div>
            </div>
        </section>

        <!-- Metrics Band -->
        <section class="bg-navy text-white py-16 relative overflow-hidden">
            <div class="absolute inset-0 bg-dots opacity-20"></div>
            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 grid grid-cols-2 md:grid-cols-4 gap-8">
                <div class="reveal">
                    <div class="text-5xl lg:text-6xl font-heading font-extrabold grad-text stat-num leading-none"
                        data-count="15" data-suffix="+">15+</div>
                    <div class="text-sm text-white/70 mt-3 leading-snug">Brands served today across D2C, e-commerce, 3PL
                        and EdTech.</div>
                </div>
                <div class="reveal">
                    <div class="text-5xl lg:text-6xl font-heading font-extrabold grad-text stat-num leading-none">&lt;1
                        day</div>
                    <div class="text-sm text-white/70 mt-3 leading-snug">Average new-client onboarding once paperwork is
                        signed.</div>
                </div>
                <div class="reveal">
                    <div class="text-5xl lg:text-6xl font-heading font-extrabold grad-text stat-num leading-none"
                        data-count="100" data-suffix="%">100%</div>
                    <div class="text-sm text-white/70 mt-3 leading-snug">Call recording with configurable retention and
                        DPDP-grade access controls.</div>
                </div>
                <div class="reveal">
                    <div class="text-5xl lg:text-6xl font-heading font-extrabold grad-text stat-num leading-none"
                        data-count="99.5" data-suffix="%">99.5%</div>
                    <div class="text-sm text-white/70 mt-3 leading-snug">Platform uptime SLA across all client tenants.
                    </div>
                </div>
            </div>
        </section>

        <!-- Features — Bento grid -->
        <section id="features" class="bg-white py-24 lg:py-32">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="max-w-3xl mb-14">
                    <div class="eyebrow-rule text-teal-dark font-bold tracking-widest uppercase text-xs mb-4">Why
                        HighlandConnect</div>
                    <h2
                        class="text-4xl lg:text-5xl font-extrabold text-navy font-heading leading-[1.05] tracking-tight mb-5">
                        Everything an Indian BPO needs to scale.<br />
                        <span class="text-muted font-bold">Nothing it doesn't.</span>
                    </h2>
                    <p class="text-lg text-muted max-w-2xl">Purpose-built for blended voice + digital operations —
                        inbound IVR, outbound dialing, WhatsApp, email and tickets in one agent workspace.</p>
                </div>

                <div class="grid grid-cols-12 gap-5 lg:gap-6">

                    <!-- Big card: Blended voice -->
                    <div
                        class="bento-card col-span-12 lg:col-span-7 bg-gradient-to-br from-navy to-navy-dark text-white rounded-3xl p-8 lg:p-10 border border-navy-dark relative overflow-hidden">
                        <div class="absolute -right-24 -top-24 w-72 h-72 rounded-full bg-teal/20 blur-3xl"></div>
                        <div class="relative">
                            <div class="flex items-center gap-2 mb-6">
                                <div class="w-10 h-10 rounded-xl bg-teal flex items-center justify-center shadow-lg">
                                    <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                            d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z" />
                                    </svg>
                                </div>
                                <span
                                    class="text-xs font-bold uppercase tracking-widest text-teal-light">Telephony</span>
                            </div>
                            <h3 class="font-heading text-3xl lg:text-4xl font-bold mb-4 leading-tight">Blended voice —
                                one agent, every direction.</h3>
                            <p class="text-white/70 leading-relaxed max-w-lg mb-8">Inbound IVR with skill routing,
                                progressive outbound dialing, supervisor barge/whisper, and 100% recording. TRAI/DND
                                scrub runs before every outbound dial.</p>

                            <!-- Mini agent softphone UI -->
                            <div class="bg-white/5 backdrop-blur-sm border border-white/10 rounded-2xl p-5 max-w-md">
                                <div class="flex items-center justify-between mb-4">
                                    <div class="flex items-center gap-3">
                                        <div
                                            class="w-10 h-10 rounded-full bg-gradient-to-br from-teal to-teal-dark text-white flex items-center justify-center font-bold text-sm">
                                            AS</div>
                                        <div>
                                            <div class="font-semibold text-sm">Anjali Singh</div>
                                            <div class="text-xs text-white/50">Uber · NDR resolution · Hindi</div>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <div class="text-[10px] text-white/50 uppercase tracking-wider">Talk time</div>
                                        <div class="text-sm font-semibold stat-num">04:12</div>
                                    </div>
                                </div>
                                <div class="flex items-center gap-1 h-8 mb-4">
                                    <div class="flex-1 bg-teal/20 rounded-sm wave-bar" style="height:40%"></div>
                                    <div class="flex-1 bg-teal/40 rounded-sm wave-bar"
                                        style="height:80%; animation-delay:.1s"></div>
                                    <div class="flex-1 bg-teal/30 rounded-sm wave-bar"
                                        style="height:60%; animation-delay:.2s"></div>
                                    <div class="flex-1 bg-teal rounded-sm wave-bar"
                                        style="height:100%; animation-delay:.3s"></div>
                                    <div class="flex-1 bg-teal/50 rounded-sm wave-bar"
                                        style="height:70%; animation-delay:.4s"></div>
                                    <div class="flex-1 bg-teal/30 rounded-sm wave-bar"
                                        style="height:50%; animation-delay:.5s"></div>
                                    <div class="flex-1 bg-teal/40 rounded-sm wave-bar"
                                        style="height:75%; animation-delay:.6s"></div>
                                    <div class="flex-1 bg-teal/20 rounded-sm wave-bar"
                                        style="height:45%; animation-delay:.7s"></div>
                                    <div class="flex-1 bg-teal/50 rounded-sm wave-bar"
                                        style="height:85%; animation-delay:.8s"></div>
                                    <div class="flex-1 bg-teal/30 rounded-sm wave-bar"
                                        style="height:55%; animation-delay:.9s"></div>
                                    <div class="flex-1 bg-teal/40 rounded-sm wave-bar"
                                        style="height:65%; animation-delay:1s"></div>
                                    <div class="flex-1 bg-teal/25 rounded-sm wave-bar"
                                        style="height:35%; animation-delay:1.1s"></div>
                                </div>
                                <div class="flex gap-2">
                                    <button
                                        class="flex-1 bg-white/10 hover:bg-white/20 text-white text-xs font-semibold rounded-lg py-2 transition-colors">Mute</button>
                                    <button
                                        class="flex-1 bg-white/10 hover:bg-white/20 text-white text-xs font-semibold rounded-lg py-2 transition-colors">Transfer</button>
                                    <button
                                        class="flex-1 bg-red-500 hover:bg-red-400 text-white text-xs font-semibold rounded-lg py-2 transition-colors">Dispose</button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Medium card: Campaigns -->
                    <div
                        class="bento-card col-span-12 lg:col-span-5 bg-cream rounded-3xl p-8 border border-gray-200 relative overflow-hidden">
                        <div class="flex items-center gap-2 mb-6">
                            <div
                                class="w-10 h-10 rounded-xl bg-navy text-white flex items-center justify-center shadow-md">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M4 6h16M4 12h10M4 18h6" />
                                </svg>
                            </div>
                            <span class="text-xs font-bold uppercase tracking-widest text-teal-dark">Campaigns</span>
                        </div>
                        <h3 class="font-heading text-2xl font-bold text-navy mb-3">Per-client scripts, dispositions and
                            queues.</h3>
                        <p class="text-muted leading-relaxed mb-5">Configure dispositions, IVR menus, CRM forms and SLAs
                            per tenant. Onboard a new client in under a business day.</p>

                        <div class="bg-white rounded-xl border border-gray-200 p-3 shadow-sm">
                            <div class="flex items-center justify-between text-[11px] font-semibold text-muted mb-2">
                                <span>Dispositions · today</span><span class="text-navy">Uber NDR</span>
                            </div>
                            <div class="grid grid-cols-5 gap-1.5 items-end h-20">
                                <div class="bg-teal/20 rounded-t-md" style="height:35%"></div>
                                <div class="bg-teal/40 rounded-t-md" style="height:55%"></div>
                                <div class="bg-teal/60 rounded-t-md" style="height:75%"></div>
                                <div class="bg-teal rounded-t-md" style="height:90%"></div>
                                <div class="bg-navy rounded-t-md" style="height:100%"></div>
                            </div>
                            <div class="grid grid-cols-5 gap-1.5 text-[9px] text-muted mt-1 text-center font-medium">
                                <span>NoAns</span><span>Busy</span><span>RTO</span><span>Resched</span><span>Resolved</span>
                            </div>
                        </div>
                    </div>

                    <!-- Small card: Tickets -->
                    <div
                        class="bento-card col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-3xl p-8 border border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-teal/10 text-teal flex items-center justify-center mb-5">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-navy mb-3">Tickets across every channel.</h3>
                        <p class="text-muted leading-relaxed text-sm">A ticket is auto-created from any voice, WhatsApp,
                            email or chat interaction. SLA per tenant per priority, with L1 → L2 → L3 escalation built
                            in.</p>
                    </div>

                    <!-- Small card: Dashboards -->
                    <div
                        class="bento-card col-span-12 sm:col-span-6 lg:col-span-4 bg-white rounded-3xl p-8 border border-gray-200">
                        <div class="w-12 h-12 rounded-xl bg-teal/10 text-teal flex items-center justify-center mb-5">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z" />
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-navy mb-3">Live dashboards + DPDP exports.</h3>
                        <p class="text-muted leading-relaxed text-sm">Live ops, campaign pulse and tenant health updated
                            in real time. Daily SLA, AHT and disposition reports exportable to PDF and Excel.</p>
                    </div>

                    <!-- Small card: Compliance -->
                    <div
                        class="bento-card col-span-12 sm:col-span-12 lg:col-span-4 bg-gradient-to-br from-teal/10 to-teal/5 rounded-3xl p-8 border border-teal/20">
                        <div
                            class="w-12 h-12 rounded-xl bg-teal text-white flex items-center justify-center mb-5 shadow-lg">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z" />
                            </svg>
                        </div>
                        <h3 class="font-heading text-xl font-bold text-navy mb-3">DPDP &amp; TRAI, built in.</h3>
                        <p class="text-muted leading-relaxed text-sm">Tenant data isolation, encrypted recordings, DND
                            scrub on every outbound dial, configurable retention and a right-to-erasure workflow on day
                            one.</p>
                    </div>

                </div>
            </div>
        </section>

        <!-- Product Showcase (split: copy + big mock) -->
        <section id="product" class="py-24 lg:py-32 bg-cream relative overflow-hidden">
            <div class="absolute inset-0 bg-dots opacity-60 pointer-events-none"></div>
            <div class="relative max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">

                <div class="grid lg:grid-cols-12 gap-12 lg:gap-16 items-start mb-20">
                    <div class="lg:col-span-5 lg:sticky lg:top-28">
                        <div class="eyebrow-rule text-teal-dark font-bold tracking-widest uppercase text-xs mb-4">The
                            Agent Desktop</div>
                        <h2
                            class="text-4xl lg:text-5xl font-extrabold text-navy font-heading leading-[1.05] tracking-tight mb-6">
                            One desktop. <br />Every channel.
                        </h2>
                        <p class="text-lg text-muted leading-relaxed mb-8">
                            Voice, WhatsApp, email and tickets in a single workspace — with tenant-specific scripts, CRM
                            forms and dispositions auto-loaded for every interaction.
                        </p>

                        <ul class="space-y-4 mb-10">
                            <li class="flex items-start gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-teal/10 text-teal flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div><span class="font-semibold text-navy">Unified inbox.</span> <span
                                        class="text-muted">Calls, WhatsApp, email and tickets in one feed, routed by
                                        skill.</span></div>
                            </li>
                            <li class="flex items-start gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-teal/10 text-teal flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div><span class="font-semibold text-navy">Scripts &amp; CRM forms per tenant.</span>
                                    <span class="text-muted">Auto-loaded by DID or customer ID. Bilingual EN +
                                        HI.</span></div>
                            </li>
                            <li class="flex items-start gap-3">
                                <div
                                    class="w-6 h-6 rounded-full bg-teal/10 text-teal flex items-center justify-center flex-shrink-0 mt-0.5">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                        stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                </div>
                                <div><span class="font-semibold text-navy">Dispositions, callbacks, KB.</span> <span
                                        class="text-muted">Standard agent toolkit — searchable knowledge base for L1 /
                                        L2 included.</span></div>
                            </li>
                        </ul>

                        <a href="{{ $loginUrl }}"
                            class="inline-flex items-center gap-2 text-teal-dark font-semibold hover:text-teal transition-colors group">
                            See the agent desktop
                            <svg class="w-4 h-4 group-hover:translate-x-1 transition-transform" fill="none"
                                stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </a>
                    </div>

                    <div class="lg:col-span-7">
                        <!-- Big agent-desktop mock -->
                        <div class="bg-white rounded-2xl shadow-soft border border-gray-200 overflow-hidden">
                            <div
                                class="bg-gray-50 border-b border-gray-100 px-4 py-3 flex items-center justify-between">
                                <div class="flex items-center gap-2">
                                    <div class="flex gap-1.5">
                                        <div class="w-2.5 h-2.5 rounded-full bg-red-400"></div>
                                        <div class="w-2.5 h-2.5 rounded-full bg-amber-400"></div>
                                        <div class="w-2.5 h-2.5 rounded-full bg-green-400"></div>
                                    </div>
                                </div>
                                <div class="text-[11px] text-muted font-medium">Customer · Rohit Kumar · Physics Wallah
                                </div>
                                <div class="flex gap-1">
                                    <div class="w-6 h-6 rounded bg-gray-100"></div>
                                    <div class="w-6 h-6 rounded bg-gray-100"></div>
                                </div>
                            </div>

                            <!-- Customer header -->
                            <div
                                class="bg-gradient-to-r from-navy via-navy-dark to-navy text-white px-6 py-5 flex items-center gap-4">
                                <div
                                    class="w-14 h-14 rounded-full bg-white text-navy font-bold flex items-center justify-center text-lg">
                                    RK</div>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <h4 class="font-heading font-bold text-lg">Rohit Kumar</h4>
                                        <span
                                            class="inline-flex items-center gap-1 bg-teal/20 text-teal-light text-[10px] font-bold uppercase tracking-wider px-2 py-0.5 rounded-full">Tier
                                            1</span>
                                    </div>
                                    <div class="text-sm text-white/70">Physics Wallah · Enrolment query · Hindi
                                        preferred</div>
                                </div>
                                <button
                                    class="hidden sm:inline-flex items-center gap-1.5 bg-teal hover:bg-teal-light text-white text-xs font-semibold px-3 py-2 rounded-lg transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                        <path
                                            d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                    </svg>
                                    Call back
                                </button>
                            </div>

                            <!-- Timeline -->
                            <div class="p-6 space-y-5">
                                <div class="text-[10px] font-bold uppercase tracking-widest text-muted">Interaction
                                    timeline</div>

                                <!-- Event: Call -->
                                <div class="relative pl-8">
                                    <div
                                        class="absolute left-0 top-0 w-6 h-6 rounded-full bg-teal text-white flex items-center justify-center ring-4 ring-teal/10">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path
                                                d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z" />
                                        </svg>
                                    </div>
                                    <div class="absolute left-[11px] top-7 w-0.5 h-full bg-gray-200"></div>
                                    <div class="bg-cream border border-gray-100 rounded-xl p-4">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="font-semibold text-sm text-navy">Inbound call · 06m 22s</div>
                                            <div class="text-[10px] text-muted">Today · 2:14 PM</div>
                                        </div>
                                        <div class="text-sm text-muted mb-3">Agent confirmed batch availability and
                                            shared payment link via WhatsApp. Disposition: callback scheduled for
                                            tomorrow 11 AM.</div>
                                        <div class="flex items-center gap-2">
                                            <button
                                                class="inline-flex items-center gap-1 text-[11px] font-semibold text-teal-dark hover:text-teal bg-white border border-gray-200 rounded-md px-2 py-1">
                                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                                    <path
                                                        d="M10 18a8 8 0 100-16 8 8 0 000 16zM8 7.75a.75.75 0 011.14-.64l3.5 2.25a.75.75 0 010 1.28l-3.5 2.25A.75.75 0 018 12.25v-4.5z" />
                                                </svg>
                                                Play recording
                                            </button>
                                            <span class="text-[10px] text-muted">Auto-tagged · queue: enrolment</span>
                                        </div>
                                    </div>
                                </div>

                                <!-- Event: WhatsApp -->
                                <div class="relative pl-8">
                                    <div
                                        class="absolute left-0 top-0 w-6 h-6 rounded-full bg-green-600 text-white flex items-center justify-center ring-4 ring-green-600/10">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path d="M2 5a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H7l-3 3V5z" />
                                        </svg>
                                    </div>
                                    <div class="absolute left-[11px] top-7 w-0.5 h-full bg-gray-200"></div>
                                    <div class="bg-cream border border-gray-100 rounded-xl p-4">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="font-semibold text-sm text-navy">WhatsApp · payment link sent
                                            </div>
                                            <div class="text-[10px] text-muted">12 min ago</div>
                                        </div>
                                        <div class="text-sm text-muted">Template message "pw_payment_link" sent in
                                            Hindi. Delivered ✓ Read ✓</div>
                                    </div>
                                </div>

                                <!-- Event: Ticket -->
                                <div class="relative pl-8">
                                    <div
                                        class="absolute left-0 top-0 w-6 h-6 rounded-full bg-amber-500 text-white flex items-center justify-center ring-4 ring-amber-500/10">
                                        <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"
                                            stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M13 16h-1v-4h-1m1-4h.01" />
                                        </svg>
                                    </div>
                                    <div class="bg-cream border border-gray-100 rounded-xl p-4">
                                        <div class="flex items-center justify-between mb-1">
                                            <div class="font-semibold text-sm text-navy">Ticket #4821 · Callback
                                                scheduled</div>
                                            <div
                                                class="text-[10px] text-muted bg-amber-50 text-amber-700 border border-amber-100 px-2 py-0.5 rounded-full">
                                                SLA 22h</div>
                                        </div>
                                        <div class="text-sm text-muted">Auto-created from call. Owner: Priya M. ·
                                            Priority: Normal · Queue: enrolment.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </section>

        <!-- How it works -->
        <section id="how-it-works" class="bg-white py-24 lg:py-32">
            <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center max-w-3xl mx-auto mb-20">
                    <div class="eyebrow-rule text-teal-dark font-bold tracking-widest uppercase text-xs mb-4">Onboarding
                    </div>
                    <h2
                        class="text-4xl lg:text-5xl font-extrabold text-navy font-heading leading-[1.05] tracking-tight mb-5">
                        New clients live in under a day.</h2>
                    <p class="text-lg text-muted">A guided wizard covers brand, channels, hours, SLAs, dispositions,
                        scripts and agents. No multi-week implementation projects.</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-8 md:gap-6 relative">
                    <div class="relative step-connector">
                        <div
                            class="w-12 h-12 rounded-xl bg-navy text-white flex items-center justify-center font-heading font-bold text-lg mb-5 shadow-md">
                            01</div>
                        <h4 class="text-xl font-bold text-navy mb-3 font-heading">Onboard the tenant</h4>
                        <p class="text-muted leading-relaxed">Create a client tenant, set caller IDs, queues, SLAs and
                            brand settings. Role-based access from day one.</p>
                    </div>
                    <div class="relative step-connector">
                        <div
                            class="w-12 h-12 rounded-xl bg-navy text-white flex items-center justify-center font-heading font-bold text-lg mb-5 shadow-md">
                            02</div>
                        <h4 class="text-xl font-bold text-navy mb-3 font-heading">Configure scripts &amp; channels</h4>
                        <p class="text-muted leading-relaxed">Load dispositions, IVR menus, CRM forms, WhatsApp
                            templates and email signatures — bilingual where needed.</p>
                    </div>
                    <div class="relative">
                        <div
                            class="w-12 h-12 rounded-xl bg-teal text-white flex items-center justify-center font-heading font-bold text-lg mb-5 shadow-md">
                            03</div>
                        <h4 class="text-xl font-bold text-navy mb-3 font-heading">Go live</h4>
                        <p class="text-muted leading-relaxed">Agents log in, the dialer is armed, dashboards start
                            streaming. Live operations from hour one.</p>
                    </div>
                </div>
            </div>
        </section>

        <!-- Testimonial -->
        <section id="testimonials"
            class="py-24 bg-gradient-to-br from-navy-dark via-navy to-navy-dark relative overflow-hidden">
            <div class="absolute inset-0 bg-dots opacity-15"></div>
            <div
                class="absolute top-0 right-0 w-[600px] h-[600px] rounded-full bg-teal/20 blur-3xl translate-x-1/3 -translate-y-1/3">
            </div>

            <div class="relative max-w-5xl mx-auto px-4 sm:px-6 lg:px-8">
                <svg class="w-14 h-14 text-teal mb-8 opacity-80" fill="currentColor" viewBox="0 0 24 24">
                    <path
                        d="M4.583 17.321C3.553 16.227 3 15 3 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 01-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179zm10 0C13.553 16.227 13 15 13 13.011c0-3.5 2.457-6.637 6.03-8.188l.893 1.378c-3.335 1.804-3.987 4.145-4.247 5.621.537-.278 1.24-.375 1.929-.311 1.804.167 3.226 1.648 3.226 3.489a3.5 3.5 0 01-3.5 3.5c-1.073 0-2.099-.49-2.748-1.179z" />
                </svg>
                <blockquote
                    class="font-heading text-white font-semibold text-2xl lg:text-4xl leading-[1.2] tracking-tight mb-10 text-pretty">
                    HighlandConnect runs our customer support and tele-sales like an extension of our own team —
                    bilingual, on-time, and with the kind of reporting we used to only get from global BPOs.
                </blockquote>
                <div class="flex items-center gap-4">
                    <div
                        class="w-14 h-14 rounded-full bg-gradient-to-br from-teal to-teal-dark text-white font-bold flex items-center justify-center">
                        —</div>
                    <div>
                        <div class="text-white font-semibold">Anchor client testimonial</div>
                        <div class="text-white/60 text-sm">Quote to be confirmed with client before public launch</div>
                    </div>
                    <div
                        class="hidden sm:flex ml-auto items-center gap-1 bg-white/5 border border-white/10 rounded-full px-4 py-2">
                        <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                        <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                        <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                        <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                        <svg class="w-4 h-4 text-amber-300" fill="currentColor" viewBox="0 0 20 20">
                            <path
                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                        </svg>
                    </div>
                </div>
            </div>
        </section>

        <!-- FAQ -->
        <section id="faq" class="bg-white py-24 lg:py-32">
            <div class="max-w-3xl mx-auto px-4 sm:px-6 lg:px-8">
                <div class="text-center mb-14">
                    <div class="eyebrow-rule text-teal-dark font-bold tracking-widest uppercase text-xs mb-4">FAQ</div>
                    <h2 class="text-4xl lg:text-5xl font-extrabold text-navy font-heading tracking-tight leading-[1.1]">
                        Frequently asked questions</h2>
                </div>

                <div class="space-y-3">
                    <details
                        class="group bg-cream border border-gray-200 rounded-2xl p-6 hover:border-teal/40 transition-colors open:bg-white open:shadow-soft"
                        open>
                        <summary
                            class="flex justify-between items-center cursor-pointer font-semibold text-navy font-heading">
                            <span>Is my client data isolated from other tenants?</span>
                            <div
                                class="w-8 h-8 rounded-full bg-white border border-gray-200 flex items-center justify-center group-open:bg-teal group-open:border-teal group-open:text-white transition-all">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </summary>
                        <p class="text-muted leading-relaxed mt-4 pr-12">Yes. Each client tenant has its own
                            dispositions, scripts, queues, caller IDs and SLAs, with strict logical isolation between
                            tenants. Cross-tenant access requires explicit super-admin role.</p>
                    </details>

                    <details
                        class="group bg-cream border border-gray-200 rounded-2xl p-6 hover:border-teal/40 transition-colors open:bg-white open:shadow-soft">
                        <summary
                            class="flex justify-between items-center cursor-pointer font-semibold text-navy font-heading">
                            <span>Are you DPDP 2023 compliant?</span>
                            <div
                                class="w-8 h-8 rounded-full bg-white border border-gray-200 flex items-center justify-center group-open:bg-teal group-open:border-teal group-open:text-white transition-all">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </summary>
                        <p class="text-muted leading-relaxed mt-4 pr-12">Yes. Consent capture, configurable retention
                            (default 90 days, up to 7 years), audit logging of every admin and export action, and a
                            right-to-erasure workflow are built in.</p>
                    </details>

                    <details
                        class="group bg-cream border border-gray-200 rounded-2xl p-6 hover:border-teal/40 transition-colors open:bg-white open:shadow-soft">
                        <summary
                            class="flex justify-between items-center cursor-pointer font-semibold text-navy font-heading">
                            <span>How do you handle TRAI / DND scrubbing?</span>
                            <div
                                class="w-8 h-8 rounded-full bg-white border border-gray-200 flex items-center justify-center group-open:bg-teal group-open:border-teal group-open:text-white transition-all">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </summary>
                        <p class="text-muted leading-relaxed mt-4 pr-12">Every outbound dial is checked against the TRAI
                            DND list plus any tenant-specific opt-out list before it's placed. Pre-call disclosures play
                            per tenant configuration.</p>
                    </details>

                    <details
                        class="group bg-cream border border-gray-200 rounded-2xl p-6 hover:border-teal/40 transition-colors open:bg-white open:shadow-soft">
                        <summary
                            class="flex justify-between items-center cursor-pointer font-semibold text-navy font-heading">
                            <span>Do your agents speak Hindi?</span>
                            <div
                                class="w-8 h-8 rounded-full bg-white border border-gray-200 flex items-center justify-center group-open:bg-teal group-open:border-teal group-open:text-white transition-all">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </summary>
                        <p class="text-muted leading-relaxed mt-4 pr-12">Yes. Our Shimla-based team operates in English
                            and Hindi natively. Scripts, IVR prompts, WhatsApp templates and email signatures are
                            configurable in both languages.</p>
                    </details>

                    <details
                        class="group bg-cream border border-gray-200 rounded-2xl p-6 hover:border-teal/40 transition-colors open:bg-white open:shadow-soft">
                        <summary
                            class="flex justify-between items-center cursor-pointer font-semibold text-navy font-heading">
                            <span>Can my brand have its own client portal?</span>
                            <div
                                class="w-8 h-8 rounded-full bg-white border border-gray-200 flex items-center justify-center group-open:bg-teal group-open:border-teal group-open:text-white transition-all">
                                <svg class="w-4 h-4 transition-transform group-open:rotate-180" fill="none"
                                    stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                        d="M19 9l-7 7-7-7" />
                                </svg>
                            </div>
                        </summary>
                        <p class="text-muted leading-relaxed mt-4 pr-12">Yes — opt-in per tenant. You get live
                            dashboards, SLA reporting, ticket visibility, and (Phase 2) the ability to push leads to us
                            via API.</p>
                    </details>
                </div>
            </div>
        </section>

        <!-- CTA -->
        <section class="relative overflow-hidden">
            <div class="relative bg-gradient-to-br from-navy via-navy-dark to-navy text-white py-24 lg:py-28">
                <div class="absolute inset-0 bg-dots opacity-15"></div>
                <div class="absolute -top-24 -left-24 w-96 h-96 rounded-full bg-teal/20 blur-3xl"></div>
                <div class="absolute -bottom-24 -right-24 w-96 h-96 rounded-full bg-teal/10 blur-3xl"></div>

                <div class="relative max-w-4xl mx-auto px-4 sm:px-6 lg:px-8 text-center">
                    <div
                        class="inline-flex items-center gap-2 bg-white/10 backdrop-blur-sm border border-white/20 rounded-full px-4 py-1.5 mb-6">
                        <span class="w-2 h-2 rounded-full bg-teal-light animate-pulse"></span>
                        <span class="text-xs font-semibold uppercase tracking-wider text-white/90">Now onboarding new
                            brands</span>
                    </div>
                    <h2
                        class="text-4xl md:text-5xl lg:text-6xl font-extrabold font-heading mb-6 leading-[1.05] tracking-tight">
                        Ready to put your <br class="hidden md:block" />
                        <span class="grad-text">customer ops on autopilot?</span>
                    </h2>
                    <p class="text-lg md:text-xl text-white/70 mb-10 max-w-2xl mx-auto">
                        Pilot HighlandConnect with one campaign, one queue and one SLA. Scale only when you see the
                        numbers.
                    </p>
                    <div class="flex flex-col sm:flex-row justify-center items-stretch sm:items-center gap-3 sm:gap-4">
                        <a href="{{ $loginUrl }}"
                            class="btn-shine bg-teal hover:bg-teal-light text-white px-8 py-4 rounded-xl font-bold text-lg transition-all shadow-glow inline-flex items-center justify-center gap-2">
                            Book a pilot
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                    d="M13 7l5 5m0 0l-5 5m5-5H6" />
                            </svg>
                        </a>
                        <a href="#features"
                            class="bg-white/5 hover:bg-white/10 border border-white/20 text-white px-8 py-4 rounded-xl font-bold text-lg transition-all inline-flex items-center justify-center gap-2">
                            Talk to operations
                        </a>
                    </div>
                    <div class="mt-8 flex flex-wrap justify-center gap-x-6 gap-y-2 text-sm text-white/60 font-medium">
                        <div class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-light" fill="currentColor"
                                viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>Pilot in 1 business day</div>
                        <div class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-light" fill="currentColor"
                                viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>DPDP &amp; TRAI ready</div>
                        <div class="flex items-center gap-2"><svg class="w-4 h-4 text-teal-light" fill="currentColor"
                                viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z"
                                    clip-rule="evenodd" />
                            </svg>EN + HI from day one</div>
                    </div>
                </div>
            </div>
        </section>
    </main>

    <!-- Footer -->
    <footer class="bg-dark text-white/60 py-16 border-t border-white/5">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8">
            <div class="grid grid-cols-2 md:grid-cols-5 gap-10 mb-12">
                <div class="col-span-2">
                    <img src="{{ asset('images/logo.png') }}" alt="{{ $brandName }}"
                        class="h-16 w-auto mix-blend-screen mb-4">
                    <p class="text-sm leading-relaxed max-w-xs">Integrated customer operations for India's
                        fastest-growing brands.</p>
                    <p class="text-xs leading-relaxed max-w-xs mt-3 text-white/40">A business unit of Summerhill
                        Technologies Pvt Ltd · Shimla, Himachal Pradesh.</p>
                </div>
                <div>
                    <div class="text-white text-xs font-bold uppercase tracking-widest mb-4">Platform</div>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#features" class="hover:text-teal transition-colors">Capabilities</a></li>
                        <li><a href="#product" class="hover:text-teal transition-colors">Agent desktop</a></li>
                        <li><a href="#how-it-works" class="hover:text-teal transition-colors">Onboarding</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">Roadmap</a></li>
                    </ul>
                </div>
                <div>
                    <div class="text-white text-xs font-bold uppercase tracking-widest mb-4">Company</div>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-teal transition-colors">About</a></li>
                        <li><a href="#testimonials" class="hover:text-teal transition-colors">Clients</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">Careers</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">Contact</a></li>
                    </ul>
                </div>
                <div>
                    <div class="text-white text-xs font-bold uppercase tracking-widest mb-4">Compliance</div>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" class="hover:text-teal transition-colors">Privacy (DPDP)</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">Terms</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">Security</a></li>
                        <li><a href="#" class="hover:text-teal transition-colors">TRAI / DND</a></li>
                    </ul>
                </div>
            </div>
            <div
                class="flex flex-col md:flex-row justify-between items-center gap-4 pt-8 border-t border-white/10 text-xs">
                <div>&copy; {{ date('Y') }} {{ $brandName }}. All rights reserved.</div>
                <div class="flex items-center gap-2 text-white/50">
                    <span class="w-2 h-2 rounded-full bg-green-400"></span>
                    All systems operational
                </div>
            </div>
        </div>
    </footer>

    <script>
            // Sticky header border on scroll
            (function () {
                const hdr = document.getElementById('siteHeader');
                const onScroll = () => {
                    if (window.scrollY > 8) hdr.classList.add('border-gray-200/80', 'shadow-sm');
                    else hdr.classList.remove('border-gray-200/80', 'shadow-sm');
                };
                onScroll();
                window.addEventListener('scroll', onScroll, { passive: true });
            })();

        // Reveal on scroll
        (function () {
            const io = new IntersectionObserver((entries) => {
                entries.forEach(e => { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } });
            }, { threshold: 0.15 });
            document.querySelectorAll('.reveal').forEach(el => io.observe(el));
        })();

        // Animated counters
        (function () {
            const nums = document.querySelectorAll('[data-count]');
            const io = new IntersectionObserver((entries) => {
                entries.forEach(entry => {
                    if (!entry.isIntersecting) return;
                    const el = entry.target;
                    const target = parseFloat(el.getAttribute('data-count'));
                    const suffix = el.getAttribute('data-suffix') || '';
                    const isFloat = target % 1 !== 0;
                    const duration = 1400;
                    const start = performance.now();
                    const tick = (now) => {
                        const t = Math.min(1, (now - start) / duration);
                        const eased = 1 - Math.pow(1 - t, 3);
                        const val = target * eased;
                        el.textContent = (isFloat ? val.toFixed(1) : Math.round(val)) + suffix;
                        if (t < 1) requestAnimationFrame(tick);
                        else el.textContent = target + suffix;
                    };
                    requestAnimationFrame(tick);
                    io.unobserve(el);
                });
            }, { threshold: 0.4 });
            nums.forEach(n => io.observe(n));
        })();
    </script>

</body>

</html>