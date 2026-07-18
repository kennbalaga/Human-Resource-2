<?php

namespace App\Services\Security;

final class ProductionSecurityChecker
{
    /**
     * @return array<int, array{name: string, passed: bool, severity: string, message: string}>
     */
    public function checks(): array
    {
        $url = (string) config('app.url');
        $cspMode = (string) config('security.content_security_policy.mode', 'off');
        $scanEnabled = (bool) config('security.attachments.malware_scanning.enabled', false);

        return [
            $this->check('Application key', filled(config('app.key')), 'critical', 'APP_KEY must be generated and kept stable.'),
            $this->check('Debug mode', ! config('app.debug'), 'critical', 'APP_DEBUG must be false.'),
            $this->check('HTTPS application URL', str_starts_with($url, 'https://'), 'critical', 'APP_URL must use HTTPS.'),
            $this->check('Secure session cookie', (bool) config('session.secure'), 'critical', 'SESSION_SECURE_COOKIE must be true.'),
            $this->check('HTTP-only session cookie', (bool) config('session.http_only'), 'critical', 'SESSION_HTTP_ONLY must be true.'),
            $this->check('Encrypted server session', (bool) config('session.encrypt'), 'critical', 'SESSION_ENCRYPT must be true.'),
            $this->check('Content Security Policy', in_array($cspMode, ['report-only', 'enforce'], true), 'critical', 'CSP_MODE must be report-only or enforce.'),
            $this->check('Enforced Content Security Policy', $cspMode === 'enforce', 'warning', 'Keep report-only during rollout, then change CSP_MODE to enforce.'),
            $this->check('Attachment malware scanning', $scanEnabled, 'critical', 'MALWARE_SCANNING_ENABLED must be true.'),
            $this->check('Fail-closed malware scanning', ! $scanEnabled || (bool) config('security.attachments.malware_scanning.fail_closed'), 'critical', 'MALWARE_SCANNING_FAIL_CLOSED must be true.'),
        ];
    }

    /** @return array<int, array{name: string, passed: bool, severity: string, message: string}> */
    public function criticalFailures(): array
    {
        return array_values(array_filter(
            $this->checks(),
            fn (array $check): bool => ! $check['passed'] && $check['severity'] === 'critical',
        ));
    }

    /** @return array{name: string, passed: bool, severity: string, message: string} */
    private function check(string $name, bool $passed, string $severity, string $message): array
    {
        return compact('name', 'passed', 'severity', 'message');
    }
}
