@php
    /*
     * The public privacy notice. Reachable without signing in, because the
     * people who most need it are the ones staring at the login form deciding
     * whether to hand this system their location and their leave attachments.
     *
     * Everything below is written against docs/DATA_PRIVACY.md, which was
     * compiled by reading the migrations rather than guessing. Where that
     * document says a control is proposed but not yet enforced, this page says
     * so too — a privacy notice that overstates what the software does is
     * worse than none at all.
     */
    $policy = config('privacy.policy');
    $updatedAt = \Illuminate\Support\Carbon::parse($policy['updated_at']);
    $retention = config('privacy.retention_days', []);
    $retentionEnforced = collect($retention)->filter()->isNotEmpty();
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Privacy Policy - {{ config('branding.organization') }}</title>
    <meta name="description" content="How the {{ config('branding.organization') }} system collects, uses, and protects personnel data under RA 10173.">
    @include('partials.favicon')
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">

    @vite(['resources/css/style.css'])
</head>
<body class="legal-page">

    <a href="#policy" class="legal-skip">Skip to the policy</a>

    <header class="legal-topbar">
        <a href="{{ route('login') }}" class="legal-brand">
            <x-brand-mark :size="44" class="logo-seal" />
            <span>{{ config('branding.organization') }} <br> {{ config('branding.tagline') }}</span>
        </a>
        <a href="{{ route('login') }}" class="legal-back">
            <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to login
        </a>
    </header>

    <main class="legal-doc" id="policy">
        <p class="legal-eyebrow">Privacy notice</p>
        <h1>Privacy Policy</h1>
        <p class="legal-lead">
            This notice explains what personal data the Human Resource Management System of
            {{ config('branding.organization') }} holds about you as a member
            of hospital personnel, why it is held, who can see it, and the rights you hold over
            it under the Data Privacy Act of 2012 (Republic Act No. 10173).
        </p>

        <dl class="legal-meta">
            <div>
                <dt>Last updated</dt>
                <dd><time datetime="{{ $updatedAt->toDateString() }}">{{ $updatedAt->format('j F Y') }}</time></dd>
            </div>
            <div>
                <dt>Applies to</dt>
                <dd>Everyone with an HRMS account</dd>
            </div>
            <div>
                <dt>Governing law</dt>
                <dd>RA 10173 and its IRR</dd>
            </div>
        </dl>

        <nav class="legal-toc" aria-labelledby="toc-heading">
            <h2 id="toc-heading">On this page</h2>
            <ol>
                <li><a href="#controller">Who is responsible for your data</a></li>
                <li><a href="#collected">What this system holds</a></li>
                <li><a href="#not-collected">What it deliberately does not hold</a></li>
                <li><a href="#purpose">Why it is collected, and on what basis</a></li>
                <li><a href="#sharing">Who can see it</a></li>
                <li><a href="#ai">Automated processing and AI features</a></li>
                <li><a href="#retention">How long it is kept</a></li>
                <li><a href="#security">How it is protected</a></li>
                <li><a href="#rights">Your rights as a data subject</a></li>
                <li><a href="#exercise">How to exercise those rights</a></li>
                <li><a href="#cookies">Cookies and on-device storage</a></li>
                <li><a href="#changes">Changes to this notice</a></li>
                <li><a href="#contact">Contact</a></li>
            </ol>
        </nav>

        <section id="controller">
            <h2>1. Who is responsible for your data</h2>
            <p>
                {{ config('branding.organization') }} is the Personal Information
                Controller for the data described here. The hospital's Data Protection Officer is
                accountable for how that data is handled and is your first point of contact for any
                privacy question or request — see <a href="#contact">Contact</a>.
            </p>
        </section>

        <section id="collected">
            <h2>2. What this system holds</h2>
            <p>The HRMS stores the following categories of personal data about hospital personnel:</p>
            <div class="legal-table-wrap">
                <table class="legal-table">
                    <caption class="legal-table-caption">Personal data held by the HRMS, by category.</caption>
                    <thead>
                        <tr>
                            <th scope="col">Category</th>
                            <th scope="col">What it includes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <th scope="row">Identity and contact</th>
                            <td>Your name and suffix, work email, employee ID, phone number, and address.</td>
                        </tr>
                        <tr>
                            <th scope="row">Employment record</th>
                            <td>Department, position, employment status, and your assigned role in this system.</td>
                        </tr>
                        <tr>
                            <th scope="row">Attendance</th>
                            <td>
                                Check-in and check-out times, and — where a punch is made from a device — the
                                latitude and longitude, IP address, and browser or device identifier recorded
                                with it.
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Schedules and preferences</th>
                            <td>Shift assignments, rotations, day-off preferences, and shift-swap requests.</td>
                        </tr>
                        <tr>
                            <th scope="row">Leave</th>
                            <td>
                                Leave applications, balances, approvals, and any file you attach. Attachments
                                may contain health information, such as a medical certificate.
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Timesheets</th>
                            <td>Hours worked per pay period and their approval history.</td>
                        </tr>
                        <tr>
                            <th scope="row">Account security</th>
                            <td>
                                Your password (stored only as a one-way hash, never in readable form),
                                two-factor settings, active sessions, and the QR-signing secret tied to your
                                attendance code.
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Biometric device linkage</th>
                            <td>
                                The reference ID issued by the biometric terminal and the metadata of each
                                scan event.
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Audit trail</th>
                            <td>
                                A record of significant actions taken in the system, with the IP address and
                                device that made them.
                            </td>
                        </tr>
                        <tr>
                            <th scope="row">Workload indicator</th>
                            <td>
                                A daily burnout risk score, worked out from the attendance, roster and leave
                                records above, including how many sick and emergency leave requests you filed
                                recently. Nothing new is collected to produce it.
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section id="not-collected">
            <h2>3. What it deliberately does not hold</h2>
            <p>
                It is as important to state what is absent. This system does <strong>not</strong> store
                government identification numbers (SSS, PhilHealth, TIN, or Pag-IBIG), emergency-contact
                details, or salary figures.
            </p>
            <p>
                Despite the word "biometric" appearing in the attendance features, <strong>no fingerprint
                or facial template is stored in this application</strong>. When the hospital uses a
                biometric terminal, the template stays on that device or with its vendor; all this system
                keeps is a reference ID and the fact that a scan happened at a given time.
            </p>
        </section>

        <section id="purpose">
            <h2>4. Why it is collected, and on what basis</h2>
            <p>Each category above exists to serve one of the purposes this system was built for:</p>
            <ul>
                <li>
                    <strong>Verifying attendance.</strong> Location, IP, and device details exist so that a
                    recorded punch can be tied to a real workplace at a real time, and so that duplicate or
                    out-of-area check-ins can be detected.
                </li>
                <li>
                    <strong>Scheduling the workforce.</strong> Names, roles, departments, and availability are
                    the minimum needed to staff every shift and to keep coverage safe.
                </li>
                <li>
                    <strong>Administering leave and preparing payroll input.</strong> Leave entitlements and
                    approved hours have to be recorded accurately for you to be paid correctly.
                </li>
                <li>
                    <strong>Accountability and security.</strong> Audit logs and session records exist so that
                    a disputed change or a suspected compromise can be reconstructed.
                </li>
                <li>
                    <strong>Preventing overwork.</strong> The workload indicator exists so that rosters give
                    staff who have been working long hours without a break more rest. It is not used for
                    disciplinary, performance, or promotion decisions.
                </li>
            </ul>
            <p>
                The lawful bases relied on are those in Sections 12 and 13 of RA 10173: processing necessary
                to fulfil the employment relationship, processing necessary for the hospital to comply with a
                legal obligation, and the legitimate interests of the hospital in running a safe and
                accountable workforce. Health information in leave attachments is processed for the
                establishment and exercise of your legal rights as an employee, and is restricted to the
                staff who must act on it.
            </p>
            <p>
                Nothing is collected for a purpose beyond these. If a new feature needs a new category of
                personal data, this notice is updated before that collection begins.
            </p>
        </section>

        <section id="sharing">
            <h2>5. Who can see it</h2>
            <p>
                Access inside the system is decided by role, not by curiosity. In broad terms: you can see
                your own records; a supervisor or department head can see the records of the staff they
                schedule and approve for; HR and system administrators can see what their duties require;
                and administrators can read the audit trail.
            </p>
            <p>
                Outside the hospital, your personal data is <strong>never sold, rented, or used for
                advertising</strong>. It is disclosed only where the hospital is required or permitted to do
                so — for example, to the Department of Health, the Civil Service Commission, the Commission
                on Audit, or another government body acting within its mandate, or in response to a lawful
                order.
            </p>
        </section>

        <section id="ai">
            <h2>6. Automated processing and AI features</h2>
            <p>
                Where the hospital has switched on the optional AI scheduling and analytics features, a
                third-party AI service (Google Gemini) is used to write plain-language explanations of
                results the system has already computed.
            </p>
            <p>
                What is sent to that service is limited to <strong>aggregate figures and pseudonymous
                records</strong> — never your name, employee ID, contact details, location history, or leave
                attachments. The AI does not decide who is scheduled, who is approved, or who is flagged:
                eligibility and ranking are computed inside this application, and every recommendation
                requires human review before it takes effect.
            </p>
            <p>
                The workload indicator is computed automatically, inside this application, and is never
                sent to that service. You can see your own level, and what is driving it, on your
                dashboard. HR managers can see everyone's and a department head their own unit's; system administrators see only their own. When
                your level is high, the scheduling tools give you more rest days and fewer long weeks and
                night shifts. A manager can still schedule you past those limits, but only by recording a
                reason. The indicator is not a medical assessment.
            </p>
            <p>
                No decision that produces a legal effect on you or similarly significantly affects you is
                made by automated processing alone.
            </p>
        </section>

        <section id="retention">
            <h2>7. How long it is kept</h2>
            <p>
                Employment records must be retained for a period even after they stop being useful
                day-to-day, because the Labor Code and audit rules expect an employer to be able to produce
                them. The hospital's approved retention schedule governs; the working proposal is three
                years for attendance, timesheet, and leave records, and five years for audit logs, which
                exist precisely to resolve later disputes.
            </p>
            @if ($retentionEnforced)
                <p>
                    Records past the configured window are deleted automatically by a scheduled maintenance
                    task.
                </p>
            @else
                <p>
                    <strong>In the interest of accuracy:</strong> automatic deletion is not yet switched on in
                    this deployment. Until the hospital confirms and enables each retention window, records
                    are kept and are removed only on request or through a reviewed administrative action.
                </p>
            @endif
            @if ((int) config('burnout.retention_days') > 0)
                <p>
                    Workload indicator scores are kept for {{ (int) config('burnout.retention_days') }} days
                    and then removed by a nightly task. They can always be worked out again from the records
                    above, so nothing the law requires is lost.
                </p>
            @endif
        </section>

        <section id="security">
            <h2>8. How it is protected</h2>
            <ul>
                <li>Passwords are stored only as one-way hashes and can never be read back, including by administrators.</li>
                <li>Access is enforced by role, so an account can only reach the records its duties require.</li>
                <li>Two-factor authentication is available, and required for administrative accounts.</li>
                <li>Sign-in attempts and password resets are rate-limited, and only one active session is allowed per account.</li>
                <li>Idle sessions time out, and the installed mobile app can be locked behind a device PIN or fingerprint.</li>
                <li>Uploaded leave attachments are stored outside the public web root and are served only to authorised viewers.</li>
                <li>Significant actions are written to an audit log that ordinary users cannot alter.</li>
            </ul>
            <p>
                No system is perfectly secure. If a breach occurs that is likely to put your rights at risk,
                the hospital will notify you and the National Privacy Commission as RA 10173 requires.
            </p>
        </section>

        <section id="rights">
            <h2>9. Your rights as a data subject</h2>
            <p>
                Under RA 10173 you hold the following rights over your personal data. They are yours to
                exercise at any time, at no cost, and using them will never be held against you.
            </p>
            <dl class="legal-rights">
                <div>
                    <dt>Right to be informed</dt>
                    <dd>To know that your personal data is being collected and processed, and why — which is what this notice is for.</dd>
                </div>
                <div>
                    <dt>Right to access</dt>
                    <dd>To be given a copy of the personal data held about you, along with how it was obtained, who it has been disclosed to, and how long it will be kept.</dd>
                </div>
                <div>
                    <dt>Right to rectification</dt>
                    <dd>To have inaccurate or incomplete data about you corrected, and to have the correction passed on to anyone it was previously disclosed to.</dd>
                </div>
                <div>
                    <dt>Right to erasure or blocking</dt>
                    <dd>To have your data removed or withheld from further processing where it is incomplete, outdated, false, unlawfully obtained, or no longer necessary — subject to the records the hospital is legally required to retain.</dd>
                </div>
                <div>
                    <dt>Right to object</dt>
                    <dd>To object to processing, including processing based on consent or on legitimate interests, and to withdraw consent you previously gave.</dd>
                </div>
                <div>
                    <dt>Right to data portability</dt>
                    <dd>To obtain your data in an electronic, structured, commonly used format that you can move elsewhere.</dd>
                </div>
                <div>
                    <dt>Right to damages</dt>
                    <dd>To be indemnified for damage suffered because of inaccurate, incomplete, outdated, false, or unlawfully obtained use of your personal data.</dd>
                </div>
                <div>
                    <dt>Right to file a complaint</dt>
                    <dd>To lodge a complaint with the hospital's Data Protection Officer and, if unsatisfied, with the National Privacy Commission at <a href="https://www.privacy.gov.ph" rel="noopener noreferrer" target="_blank">privacy.gov.ph</a>.</dd>
                </div>
            </dl>
            <p class="legal-note">
                These rights are inherited by your lawful heirs and assigns should you pass away or become
                incapacitated.
            </p>
        </section>

        <section id="exercise">
            <h2>10. How to exercise those rights</h2>
            <p>
                <strong>These requests are handled by people, not by a button in this app.</strong> There is
                no self-service export or account-deletion flow in the HRMS today, and this notice will not
                pretend otherwise. To make a request, contact the Data Protection Officer using the details
                below, stating which right you are exercising and enough detail to identify your record.
            </p>
            <p>
                You can expect an acknowledgement and a response within a reasonable period, and the hospital
                will tell you if a request cannot be granted in full — for example, where a record must be
                retained under labour or audit rules — along with the reason.
            </p>
            <p>
                Some corrections are faster to make directly: your own contact details can be updated from
                your profile page once you are signed in, and errors in attendance or leave records are
                usually best raised with your supervisor or the HR office first.
            </p>
        </section>

        <section id="cookies">
            <h2>11. Cookies and on-device storage</h2>
            <p>
                This system sets no advertising or third-party tracking cookies. What it stores on your
                device is limited to what the app needs to work:
            </p>
            <ul>
                <li>A session cookie that keeps you signed in, and a CSRF token that protects forms from forgery.</li>
                <li>Local preferences kept in your browser and never sent to the hospital — your theme choice, whether the sidebar is collapsed, and whether you dismissed the install prompt.</li>
                <li>If you set up the app lock, a scrambled form of your PIN that stays on that device and is never transmitted.</li>
            </ul>
            <p>Clearing your browser's site data removes all of the above.</p>
        </section>

        <section id="changes">
            <h2>12. Changes to this notice</h2>
            <p>
                This notice is updated when what the system does with personal data changes. The
                "Last updated" date at the top of this page always reflects the last substantive revision,
                and material changes are announced to personnel through the usual hospital channels.
            </p>
        </section>

        <section id="contact">
            <h2>13. Contact</h2>
            <div class="legal-contact">
                <p class="legal-contact-role">
                    Data Protection Officer{{ filled($policy['dpo_name']) ? ' — '.$policy['dpo_name'] : '' }}
                </p>
                <p>{{ $policy['office'] }}</p>
                @if (filled($policy['dpo_email']) || filled($policy['dpo_phone']))
                    <ul class="legal-contact-list">
                        @if (filled($policy['dpo_email']))
                            <li>
                                <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                                <a href="mailto:{{ $policy['dpo_email'] }}">{{ $policy['dpo_email'] }}</a>
                            </li>
                        @endif
                        @if (filled($policy['dpo_phone']))
                            <li>
                                <i class="fa-solid fa-phone" aria-hidden="true"></i>
                                <span>{{ $policy['dpo_phone'] }}</span>
                            </li>
                        @endif
                    </ul>
                @else
                    <p class="legal-note">
                        Requests may be filed in person at the office named above, or through your
                        department head.
                    </p>
                @endif
            </div>
            <p>
                If you believe your rights under RA 10173 have been violated and the hospital's response
                does not resolve it, you may complain to the National Privacy Commission at
                <a href="https://www.privacy.gov.ph" rel="noopener noreferrer" target="_blank">privacy.gov.ph</a>.
            </p>
        </section>

        <p class="legal-closing">
            This notice describes how this software actually behaves today. Where a safeguard is planned
            but not yet switched on, it says so rather than claiming otherwise.
        </p>
    </main>

    <footer class="legal-footer">
        <span class="copyright">&copy; {{ now()->year }} {{ config('branding.organization') }}. All rights reserved.</span>
        <a href="{{ route('login') }}">Back to login</a>
    </footer>

</body>
</html>
