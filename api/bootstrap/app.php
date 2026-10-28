<?php

use App\Exceptions\ApiException;
use App\Exceptions\ApprovalRequired;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('agent/v1')->middleware(['api'])->group(base_path('routes/agent.php'));
            Route::prefix('internal/relay')->middleware(['api', \App\Http\Middleware\VerifyRelaySecret::class])->group(base_path('routes/relay.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->prepend(\App\Http\Middleware\AssignRequestId::class);
        $middleware->alias([
            'tenant' => \App\Http\Middleware\SetTenant::class,
            'perm' => \App\Http\Middleware\RequirePermission::class,
            'idempotent' => \App\Http\Middleware\Idempotency::class,
            'audit' => \App\Http\Middleware\AuditMutations::class,
            'agent' => \App\Http\Middleware\AuthenticateAgent::class,
        ]);
        $middleware->redirectGuestsTo(fn () => null);
        $middleware->trimStrings(except: ['yaml', 'source.compose', 'compose_yaml', 'password', 'value']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $error = function (Request $request, string $code, string $message, int $status, array $fields = [], array $extra = []) {
            return response()->json(['error' => array_merge([
                'code' => $code,
                'message' => $message,
                'fields' => (object) $fields,
                'request_id' => $request->attributes->get('request_id'),
            ], $extra)], $status, $status === 429 ? ['Retry-After' => 60] : []);
        };

        $exceptions->shouldRenderJsonWhen(fn (Request $request) => $request->is('api/*', 'agent/*') || $request->expectsJson());
        $exceptions->dontReport([ApiException::class, ApprovalRequired::class]);

        $exceptions->render(function (Throwable $e, Request $request) use ($error) {
            if (! $request->is('api/*', 'agent/*') && ! $request->expectsJson()) {
                return null;
            }

            return match (true) {
                $e instanceof ApprovalRequired => response()->json(['data' => [
                    'approval_id' => $e->approval->id,
                    'status' => 'pending_approval',
                    'action' => $e->approval->action,
                    'message' => $e->getMessage(),
                ]], 202),
                $e instanceof ApiException => $error($request, $e->errorCode, $e->getMessage(), $e->status, $e->fields, $e->extra),
                $e instanceof ValidationException => $error($request, 'validation_failed', $e->getMessage(), 422, $e->errors()),
                $e instanceof AuthenticationException => $error($request, 'unauthenticated', 'Sign in to continue.', 401),
                $e instanceof AuthorizationException => $error($request, 'forbidden', $e->getMessage() ?: 'Forbidden.', 403),
                $e instanceof ModelNotFoundException, $e instanceof NotFoundHttpException => $error($request, 'not_found', 'The requested resource was not found.', 404),
                $e instanceof ThrottleRequestsException => $error($request, 'rate_limited', 'Too many requests. Try again in a minute.', 429),
                $e instanceof HttpExceptionInterface => $error($request, match ($e->getStatusCode()) {
                    401 => 'unauthenticated', 403 => 'forbidden', 404 => 'not_found', 409 => 'conflict', 429 => 'rate_limited', 402 => 'quota_exceeded', 422 => 'validation_failed', default => 'server_error',
                }, $e->getMessage() ?: 'Request failed.', $e->getStatusCode()),
                default => $error($request, 'server_error', config('app.debug') ? $e->getMessage() : 'Something went wrong on our side. Try again, or contact support with the request id.', 500),
            };
        });
    })->create();
