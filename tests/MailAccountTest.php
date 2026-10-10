<?php

namespace Unico\Core\Tests;

use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Unico\Core\Mail\ImapConnectionFactory;
use Unico\Core\Models\MailAccount;
use Unico\Core\Tests\Support\CoreDatabase;
use Webklex\PHPIMAP\ClientManager;

class MailAccountTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        CoreDatabase::migrateFresh();
    }

    protected function setUp(): void
    {
        CoreDatabase::truncate('mail_accounts');
    }

    private function account(array $attributes = []): MailAccount
    {
        return MailAccount::create($attributes + [
            'name' => 'Casella',
            'email_address' => uniqid().'@example.com',
            'imap_host' => 'imap.example.com',
            'imap_port' => 993,
            'imap_username' => 'user',
            'imap_password' => 'segreta',
        ]);
    }

    public function test_credentials_are_encrypted_in_the_database(): void
    {
        $account = $this->account(['smtp_password' => 'smtp-segreta', 'access_token' => 'tok', 'refresh_token' => 'ref']);

        $raw = CoreDatabase::$capsule->getConnection('core')->table('mail_accounts')->find($account->id);

        foreach (['imap_password' => 'segreta', 'smtp_password' => 'smtp-segreta', 'access_token' => 'tok', 'refresh_token' => 'ref'] as $column => $plain) {
            $this->assertTrue($raw->$column !== $plain, $column);
            $this->assertSame($plain, $account->fresh()->$column);
        }
    }

    public function test_credentials_are_hidden_from_serialisation(): void
    {
        $this->assertSame([], array_intersect(['imap_password', 'smtp_password', 'access_token', 'refresh_token'], array_keys($this->account()->toArray())));
    }

    public function test_token_expiry_has_a_five_minute_margin(): void
    {
        $this->assertTrue($this->account(['auth_type' => 'oauth2', 'token_expires_at' => now()->addMinutes(3)])->isTokenExpired());
        $this->assertFalse($this->account(['auth_type' => 'oauth2', 'token_expires_at' => now()->addHour()])->isTokenExpired());
        $this->assertFalse($this->account(['auth_type' => 'password', 'token_expires_at' => now()->subDay()])->isTokenExpired());
        $this->assertFalse($this->account(['auth_type' => 'oauth2'])->isTokenExpired());
    }

    public function test_smtp_is_configured_only_with_password_auth(): void
    {
        $this->assertTrue($this->account(['smtp_host' => 'smtp.example.com', 'smtp_password' => 'x'])->hasSmtpConfigured());
        $this->assertFalse($this->account(['smtp_host' => 'smtp.example.com'])->hasSmtpConfigured());
        $this->assertFalse($this->account(['auth_type' => 'oauth2', 'smtp_host' => 'smtp.example.com', 'smtp_password' => 'x'])->hasSmtpConfigured());
    }

    public function test_oauth_account_needs_no_smtp_settings(): void
    {
        $account = $this->account(['auth_type' => 'oauth2', 'provider' => 'google', 'imap_password' => null]);

        $this->assertNull($account->fresh()->smtp_host);
    }

    public function test_factory_builds_a_client_for_a_password_account(): void
    {
        $config = (new ImapConnectionFactory(new ClientManager))->make($this->account(['imap_encryption' => 'none']));

        $this->assertSame('imap.example.com', $config->host);
        $this->assertSame('segreta', $config->password);
        $this->assertSame(false, $config->encryption);
        $this->assertNull($config->authentication);
    }

    public function test_factory_refreshes_an_expired_oauth_token(): void
    {
        $account = $this->account([
            'auth_type' => 'oauth2', 'provider' => 'google', 'imap_password' => null,
            'access_token' => 'old', 'refresh_token' => 'stored-refresh', 'token_expires_at' => now()->subHour(),
        ]);

        $driver = new class
        {
            public ?string $received = null;

            public function stateless(): static
            {
                return $this;
            }

            public function refreshToken(string $token): object
            {
                $this->received = $token;

                return (object) ['token' => 'new-access', 'refreshToken' => 'new-refresh', 'expiresIn' => 3600];
            }
        };
        Socialite::swap(new class($driver)
        {
            public function __construct(private object $driver) {}

            public function driver(string $name): object
            {
                return $this->driver;
            }
        });

        $config = (new ImapConnectionFactory(new ClientManager))->make($account);

        $this->assertSame('stored-refresh', $driver->received);
        $this->assertSame('new-access', $config->password);
        $this->assertSame('oauth', $config->authentication);
        $fresh = $account->fresh();
        $this->assertSame('new-access', $fresh->access_token);
        $this->assertSame('new-refresh', $fresh->refresh_token);
        $this->assertTrue($fresh->token_expires_at->isFuture());
    }

    public function test_factory_does_not_touch_a_valid_token(): void
    {
        $account = $this->account([
            'auth_type' => 'oauth2', 'imap_password' => null, 'access_token' => 'valid', 'token_expires_at' => now()->addHour(),
        ]);
        Socialite::swap(new class
        {
            public function driver(): never
            {
                throw new RuntimeException('non va chiamato');
            }
        });

        $this->assertSame('valid', (new ImapConnectionFactory(new ClientManager))->make($account)->password);
    }

    public function test_factory_fails_without_refresh_token(): void
    {
        $account = $this->account(['auth_type' => 'oauth2', 'imap_password' => null, 'token_expires_at' => now()->subHour()]);

        try {
            (new ImapConnectionFactory(new ClientManager))->make($account);
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('nessun refresh token', $e->getMessage());
        }
    }

    public function test_factory_rejects_pop3(): void
    {
        try {
            (new ImapConnectionFactory(new ClientManager))->make($this->account(['protocol' => 'pop3']));
            $this->fail('atteso RuntimeException');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('pop3', $e->getMessage());
        }
    }
}
