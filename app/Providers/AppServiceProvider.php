<?php

namespace App\Providers;

use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyConfig;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Infrastructure\Dns\SystemDnsResolver;
use App\Infrastructure\HttpClient\GuzzlePinnedHttpTransport;
use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(DnsResolverInterface::class, SystemDnsResolver::class);

        $this->app->singleton(OutboundPolicy::class, function ($app): OutboundPolicy {
            return OutboundPolicy::fromConfig(
                OutboundPolicyConfig::fromArray(config('outbound', [])),
                $app->make(DnsResolverInterface::class),
            );
        });

        $this->app->singleton(GuzzlePinnedHttpTransport::class);
        $this->app->singleton(PinnedHttpTransport::class, GuzzlePinnedHttpTransport::class);
        $this->app->singleton(CurlMultiPinnedProbe::class);
        $this->app->singleton(MultiPinnedHttpProbe::class, CurlMultiPinnedProbe::class);
    }

    public function boot(): void
    {
        //
    }
}
