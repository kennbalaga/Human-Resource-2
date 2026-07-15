<?php

namespace Tests\Unit;

use Tests\TestCase;

class GmailMailConfigurationTest extends TestCase
{
    public function test_gmail_mailer_uses_starttls_without_changing_the_default_mailer(): void
    {
        $gmail = config('mail.mailers.gmail');

        $this->assertSame('array', config('mail.default'));
        $this->assertSame('smtp', $gmail['transport']);
        $this->assertSame('smtp', $gmail['scheme']);
        $this->assertSame('smtp.gmail.com', $gmail['host']);
        $this->assertSame(587, $gmail['port']);
        $this->assertTrue($gmail['require_tls']);
        $this->assertSame(15, $gmail['timeout']);
    }
}
