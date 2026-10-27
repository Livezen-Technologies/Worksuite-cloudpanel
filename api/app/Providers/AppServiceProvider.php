<?php

namespace App\Providers;

use App\Contracts\CertificateIssuer;
use App\Contracts\DnsResolver;
use App\Contracts\ImageScanner;
use App\Contracts\PaymentGateway;
use App\Contracts\SourceValidator;
use App\Drivers\FakeCertificateIssuer;
use App\Drivers\FakeDnsResolver;
use App\Drivers\FakeImageScanner;
use App\Drivers\FakePaymentGateway;
use App\Drivers\FakeSourceValidator;
use App\Drivers\HttpSourceValidator;
use App\Drivers\NativeDnsResolver;
use App\Models\AccessToken;
use App\Models\Application;
use App\Models\Approval;
use App\Models\Container;
use App\Models\Server;
use App\Models\User;
use App\Services\ApprovalService;
use App\Services\ContainerService;
use App\Support\Rbac;
use App\Support\Tenant;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Sanctum\Sanctum;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(Tenant::class);
        $this->app->scoped(Rbac::class);
        $this->app->singleton(ApprovalService::class);

        $drivers = config('platform.drivers');
        $this->app->singleton(DnsResolver::class, fn () => $drivers['dns'] === 'fake' ? new FakeDnsResolver : new NativeDnsResolver);
        $this->app->singleton(CertificateIssuer::class, FakeCertificateIssuer::class);
        $this->app->singleton(ImageScanner::class, FakeImageScanner::class);
        $this->app->singleton(PaymentGateway::class, FakePaymentGateway::class);
        $this->app->singleton(SourceValidator::class, fn () => $drivers['source'] === 'fake' ? new FakeSourceValidator : new HttpSourceValidator);
    }

    public function boot(): void
    {
        Sanctum::usePersonalAccessTokenModel(AccessToken::class);

        // SEC-009: per token/user and per IP.
        RateLimiter::for('api', fn (Request $request) => [
            Limit::perMinute(240)->by('user:'.($request->user()?->currentAccessToken()?->id ?? $request->user()?->id ?? $request->ip())),
            Limit::perMinute(600)->by('ip:'.$request->ip()),
        ]);
        RateLimiter::for('auth', fn (Request $request) => [
            Limit::perMinute(10)->by('auth:'.$request->ip().'|'.strtolower((string) $request->input('email'))),
            Limit::perMinute(30)->by('auth-ip:'.$request->ip()),
        ]);
        RateLimiter::for('agent', fn (Request $request) => Limit::perMinute(240)->by('agent:'.substr(hash('sha256', (string) $request->bearerToken()), 0, 16).$request->ip()));

        $this->registerApprovalExecutors();
    }

    /** Actions that run when a second person approves them. */
    private function registerApprovalExecutors(): void
    {
        $this->app->afterResolving(ApprovalService::class, function (ApprovalService $approvals) {
            $approvals->register('container.remove', function (Approval $approval, User $requester) {
                $container = Container::findOrFail($approval->resource_id);

                return ['job_id' => app(ContainerService::class)->perform($container, 'remove', $approval->payload ?? [])->id];
            });
            $approvals->register('server.delete', function (Approval $approval) {
                Server::findOrFail($approval->resource_id)->delete();

                return ['deleted' => true];
            });
            $approvals->register('application.delete', function (Approval $approval) {
                $app = Application::findOrFail($approval->resource_id);
                $app->retire();

                return ['deleted' => true];
            });
            $approvals->register('volume.delete', function (Approval $approval) {
                $volume = \App\Models\Volume::findOrFail($approval->resource_id);
                $job = app(\App\Services\JobService::class)->create('volume.remove', ['execute'], $volume);
                app(\App\Services\CommandBus::class)->dispatch($volume->server, 'volume.remove', ['name' => $volume->name], $job);
                $volume->update(['status' => 'removing']);

                return ['job_id' => $job->id];
            });
            $approvals->register('database.delete', function (Approval $approval) {
                \App\Models\Database::findOrFail($approval->resource_id)->delete();

                return ['deleted' => true];
            });
            // terminal.open has no executor: the requester retries with approval_id.
        });
    }
}
