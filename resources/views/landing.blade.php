{{--
    The public landing page.

    A standalone document, like the auth pages: layouts/app.blade.php reads the
    signed-in account's theme, navigation and badge counts in its first lines,
    and there is no account here to read. It carries no CSRF token and no
    @vite JavaScript entry either, because nothing on it posts or needs script
    to work — every control is a link.

    What it claims, the product does. Every module named below is a route in
    this application, and the line about AI recommendations is the same
    sentence the scheduling wizard prints in its own footer. No invented
    metrics: the kit is explicit that a figure card defines the card and not
    the number, and a signed-out visitor is the last person who should be
    shown this hospital's real headcount.
--}}
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>{{ config('branding.short_name') }} · {{ config('branding.tagline') }}</title>
    <meta name="description" content="{{ config('branding.short_name') }} is the workforce and HR system for {{ config('branding.organization') }}: rostering, attendance, timesheets and leave in one place.">
    {{-- A sign-in page has nothing to offer a search engine, and this page is
         for the staff who already know the address. --}}
    <meta name="robots" content="noindex, follow">
    @include('partials.favicon')
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    @vite('resources/css/landing.css')
</head>
<body class="landing-body">
    <a class="landing-skip" href="#landing-main">Skip to main content</a>

    <header class="landing-head">
        <div class="landing-wrap landing-head-row">
            {{-- The lockup points at this page. It is the only thing here that
                 does, and a logo that goes nowhere is a dead control. --}}
            <a class="landing-lockup" href="{{ route('landing') }}" aria-label="{{ config('branding.short_name') }} home">
                <x-brand-mark :size="34" />
                <span class="landing-lockup-copy">
                    <x-brand-wordmark class="landing-lockup-name" />
                    <span class="landing-lockup-tagline">{{ config('branding.tagline') }}</span>
                </span>
            </a>

            <nav class="landing-head-links" aria-label="Primary">
                <a class="landing-btn landing-btn-ghost" href="{{ route('privacy-policy') }}">Privacy</a>
                <a class="landing-btn landing-btn-primary" href="{{ route('login') }}"><x-icon name="log-in" /> Sign in</a>
            </nav>
        </div>
    </header>

    <main id="landing-main">
        <section class="landing-hero">
            <div class="landing-wrap landing-hero-inner">
                <p class="landing-overline">{{ config('branding.tagline') }}</p>
                <h1>Every shift, every hour, every department &mdash; in one place.</h1>
                <p class="landing-lede">
                    {{ config('branding.short_name') }} is the workforce system for {{ config('branding.organization') }}.
                    Rostering, attendance, timesheets and leave run on one set of records,
                    so what the schedule promised is what the timesheet counts.
                </p>

                <div class="landing-hero-actions">
                    <a class="landing-btn landing-btn-primary" href="{{ route('login') }}"><x-icon name="log-in" /> Sign in to your account</a>
                    <a class="landing-btn landing-btn-ghost" href="#modules">See what is inside <x-icon name="chevron-right" /></a>
                </div>

                <ul class="landing-assurances">
                    <li><x-icon name="shield" /> Two-factor secured sign-in</li>
                    <li><x-icon name="users" /> Role-based access</li>
                    <li><x-icon name="lock" /> Full audit trail</li>
                </ul>
            </div>
        </section>

        {{-- Each card names a module that exists behind the sign-in button.
             Nothing is listed here that a signed-in reader cannot then find. --}}
        <section class="landing-band landing-band-sunken" id="modules" aria-labelledby="modules-title">
            <div class="landing-wrap">
                <div class="landing-band-head is-centred">
                    <h2 id="modules-title">What the system covers</h2>
                    <p>Eight areas of the working week, sharing one record of who works where and when.</p>
                </div>

                <div class="landing-modules">
                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="calendar" /></span>
                        <h3>Scheduling</h3>
                        <p>Roster a whole department for a period, repeat one shift across a date range, or fill a single gap. Coverage, rest and weekly hours are checked as you go.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="clock" /></span>
                        <h3>Attendance</h3>
                        <p>Clock-ins from the entrance scanner or a staff badge, matched to the shift each one belongs to, with late arrivals and overtime surfaced the same day.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="timesheet" /></span>
                        <h3>Timesheets</h3>
                        <p>Worked hours gathered by period, reviewed by the people who supervise them, and approved before anything reaches pay.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="leave" /></span>
                        <h3>Leave</h3>
                        <p>Requests, balances and approvals in one queue, with supporting documents kept against the request they belong to.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="swap" /></span>
                        <h3>Shift swaps &amp; preferences</h3>
                        <p>Staff trade shifts with a colleague and ask for days off; a manager who supervises both sides signs it off, and both schedules move together.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="building" /></span>
                        <h3>Organization</h3>
                        <p>Departments, positions and the people posted to them &mdash; the structure every other module reads from.</p>
                    </article>

                    <article class="landing-module">
                        <span class="landing-module-icon"><x-icon name="analytics" /></span>
                        <h3>Reports &amp; analytics</h3>
                        <p>Exports for attendance, leave and headcount, and the workforce trends behind them, by department and period.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="landing-band" aria-labelledby="rostering-title">
            <div class="landing-wrap landing-split">
                <div>
                    <div class="landing-band-head">
                        <h2 id="rostering-title">A roster that already knows the rules</h2>
                        <p>
                            The scheduling assistant proposes a draft for the department and period you choose,
                            then shows its working: who it picked, who it left out, and why. Every check runs
                            against the shifts already on record, not against the draft alone.
                        </p>
                    </div>

                    {{-- The sentence the wizard itself prints, word for word. --}}
                    <p class="landing-note"><x-icon name="shield" /> AI recommendations never publish automatically.</p>
                </div>

                <ul class="landing-checks">
                    <li>
                        <x-icon name="check-circle" />
                        <span>
                            <strong>Weekly rest day</strong>
                            <span>Labor Code Art. 91 &mdash; rest days are counted across existing shifts, not just the new ones.</span>
                        </span>
                    </li>
                    <li>
                        <x-icon name="check-circle" />
                        <span>
                            <strong>Weekly paid hours</strong>
                            <span>The busiest week in the period is measured against the ceiling before anything is published.</span>
                        </span>
                    </li>
                    <li>
                        <x-icon name="check-circle" />
                        <span>
                            <strong>Rest between shifts</strong>
                            <span>A shift that would leave too little rest after the one before it is flagged, and named.</span>
                        </span>
                    </li>
                    <li>
                        <x-icon name="check-circle" />
                        <span>
                            <strong>Shift coverage</strong>
                            <span>Each shift is held to its department&rsquo;s recorded standard. Below cover blocks publishing.</span>
                        </span>
                    </li>
                    <li>
                        <x-icon name="check-circle" />
                        <span>
                            <strong>Leave and days off</strong>
                            <span>Approved leave and granted days off are never scheduled over.</span>
                        </span>
                    </li>
                </ul>
            </div>
        </section>

        <section class="landing-band landing-band-sunken" aria-labelledby="safeguards-title">
            <div class="landing-wrap">
                <div class="landing-band-head is-centred">
                    <h2 id="safeguards-title">Built for records people depend on</h2>
                    <p>Schedules, hours and pay are the things staff notice when they go wrong. These are the safeguards around them.</p>
                </div>

                <div class="landing-safeguards">
                    <article class="landing-safeguard">
                        <x-icon name="shield" />
                        <h3>Two-factor sign-in</h3>
                        <p>A second factor on the account, and one active session per account at a time.</p>
                    </article>

                    <article class="landing-safeguard">
                        <x-icon name="users" />
                        <h3>Access by role</h3>
                        <p>HR sees the organisation; a department head sees their own unit; staff see themselves.</p>
                    </article>

                    <article class="landing-safeguard">
                        <x-icon name="lock" />
                        <h3>Audited changes</h3>
                        <p>Who changed which record, and when, is written down as the change is made.</p>
                    </article>

                    <article class="landing-safeguard">
                        <x-icon name="hospital" />
                        <h3>Works on the ward</h3>
                        <p>Installable on a phone, so a shift can be checked without finding a desk.</p>
                    </article>
                </div>
            </div>
        </section>

        <section class="landing-band" aria-labelledby="signin-title">
            <div class="landing-wrap">
                <div class="landing-close">
                    <h2 id="signin-title">Accounts are issued by HR</h2>
                    <p>
                        {{ config('branding.short_name') }} is for staff of {{ config('branding.organization') }}.
                        Sign in with the employee ID and password issued to you. If you cannot get in,
                        your HR department can reset access.
                    </p>
                    <a class="landing-btn landing-btn-primary" href="{{ route('login') }}"><x-icon name="log-in" /> Sign in</a>
                </div>
            </div>
        </section>
    </main>

    <footer class="landing-foot">
        <div class="landing-wrap landing-foot-row">
            <small>&copy; {{ now()->year }} {{ config('branding.organization') }}. All rights reserved.</small>
            <a href="{{ route('privacy-policy') }}">Privacy Policy</a>
        </div>
    </footer>
</body>
</html>
