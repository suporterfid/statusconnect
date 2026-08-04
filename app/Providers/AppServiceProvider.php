<?php

namespace App\Providers;

use App\Application\Incidents\IncidentService;
use App\Application\GrandpaSson\HttpIntrospectionClient;
use App\Application\GrandpaSson\IntrospectionClientInterface;
use App\Domain\Incidents\FlapPolicy;
use App\Domain\Incidents\IncidentStateMachine;
use App\Domain\Outbound\DnsResolverInterface;
use App\Domain\Outbound\OutboundPolicy;
use App\Domain\Outbound\OutboundPolicyConfig;
use App\Domain\Outbound\IpClassifier;
use App\Domain\Outbound\TcpTargetValidator;
use App\Domain\Shared\Clock;
use App\Domain\Shared\SystemClock;
use App\Infrastructure\Dns\SystemDnsResolver;
use App\Infrastructure\HttpClient\GuzzlePinnedHttpTransport;
use App\Infrastructure\HttpClient\CurlMultiPinnedProbe;
use App\Infrastructure\HttpClient\MultiPinnedHttpProbe;
use App\Infrastructure\HttpClient\PinnedHttpTransport;
use App\Infrastructure\Tcp\PinnedTcpProbe;
use App\Infrastructure\Tcp\StreamSelectPinnedTcpProbe;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Clock::class, SystemClock::class);
        $this->app->singleton(HttpIntrospectionClient::class);
        $this->app->singleton(IntrospectionClientInterface::class, HttpIntrospectionClient::class);
        $this->app->singleton(IncidentService::class, function ($app): IncidentService {
            return new IncidentService(
                $app->make(IncidentStateMachine::class),
                $app->make(FlapPolicy::class),
                (int) config('incidents.flap_threshold', 5),
                (int) config('incidents.flap_window_minutes', 60),
            );
        });
        $this->app->singleton(DnsResolverInterface::class, SystemDnsResolver::class);

        $this->app->singleton(OutboundPolicy::class, function ($app): OutboundPolicy {
            return OutboundPolicy::fromConfig(
                OutboundPolicyConfig::fromArray(config('outbound', [])),
                $app->make(DnsResolverInterface::class),
            );
        });
        $this->app->singleton(TcpTargetValidator::class, function ($app): TcpTargetValidator {
            return new TcpTargetValidator(
                $app->make(DnsResolverInterface::class),
                new IpClassifier((array) config('outbound.metadata_ips', [])),
                (array) config('outbound.allowed_ports', [80, 443]),
            );
        });

        $this->app->singleton(GuzzlePinnedHttpTransport::class);
        $this->app->singleton(PinnedHttpTransport::class, GuzzlePinnedHttpTransport::class);
        $this->app->singleton(CurlMultiPinnedProbe::class);
        $this->app->singleton(MultiPinnedHttpProbe::class, CurlMultiPinnedProbe::class);
        $this->app->singleton(StreamSelectPinnedTcpProbe::class);
        $this->app->singleton(PinnedTcpProbe::class, StreamSelectPinnedTcpProbe::class);
    }

    public function boot(): void
    {
        //
    }
}
