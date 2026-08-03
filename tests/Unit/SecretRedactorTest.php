<?php

namespace Tests\Unit;

use App\Domain\Secrets\SecretRedactor;
use PHPUnit\Framework\TestCase;

class SecretRedactorTest extends TestCase
{
    private SecretRedactor $redactor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->redactor = new SecretRedactor();
    }

    public function test_redacts_authorization_header(): void
    {
        $headers = [
            'User-Agent' => 'StatusConnect/1.0',
            'Authorization' => 'Bearer secret_token_12345',
            'x-api-key' => 'sc_key_abcdef',
        ];

        $redacted = $this->redactor->redactHeaders($headers);

        $this->assertEquals('StatusConnect/1.0', $redacted['User-Agent']);
        $this->assertEquals('Bearer [REDACTED]', $redacted['Authorization']);
        $this->assertEquals('[REDACTED]', $redacted['x-api-key']);
    }

    public function test_redacts_sensitive_query_parameters(): void
    {
        $url = 'https://api.example.com/data?token=secret123&user=john&apiKey=key999';
        $redactedUrl = $this->redactor->redactUrl($url);

        $this->assertStringContainsString('token=%5BREDACTED%5D', $redactedUrl);
        $this->assertStringContainsString('user=john', $redactedUrl);
    }

    public function test_redacts_string_secrets(): void
    {
        $text = 'Error connecting with key my_super_secret_key in response';
        $redacted = $this->redactor->redactString($text, ['my_super_secret_key']);

        $this->assertEquals('Error connecting with key [REDACTED] in response', $redacted);
    }
}
