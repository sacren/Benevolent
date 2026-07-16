<?php

namespace App\Providers;

use App\Actions\Fortify\CreateNewUser;
use App\Actions\Fortify\ResetUserPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Laravel\Fortify\Features;
use Laravel\Fortify\Fortify;
use Stancl\Tenancy\Contracts\Tenant;
use Stancl\Tenancy\Tenancy;

class FortifyServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureActions();
        $this->configureViews();
        $this->configureRateLimiting();
    }

    /**
     * Configure Fortify actions.
     */
    private function configureActions(): void
    {
        Fortify::resetUserPasswordsUsing(ResetUserPassword::class);
        Fortify::createUsersUsing(CreateNewUser::class);
    }

    /**
     * Configure Fortify views.
     */
    private function configureViews(): void
    {
        Fortify::loginView(fn (Request $request) => Inertia::render('auth/Login', [
            'canResetPassword' => Features::enabled(Features::resetPasswords()),
            'status' => $request->session()->get('status'),
        ]));

        Fortify::resetPasswordView(fn (Request $request) => Inertia::render('auth/ResetPassword', [
            'email' => $request->email,
            'token' => $request->route('token'),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::requestPasswordResetLinkView(fn (Request $request) => Inertia::render('auth/ForgotPassword', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::verifyEmailView(fn (Request $request) => Inertia::render('auth/VerifyEmail', [
            'status' => $request->session()->get('status'),
        ]));

        Fortify::registerView(fn () => Inertia::render('auth/Register', [
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]));

        Fortify::twoFactorChallengeView(fn () => Inertia::render('auth/TwoFactorChallenge'));

        Fortify::confirmPasswordView(fn () => Inertia::render('auth/ConfirmPassword'));
    }

    /**
     * Configure rate limiting.
     *
     * Every limiter below returns *two* limits, and the split between them is
     * the whole design: a key naming a person is scoped to the campaign that
     * person belongs to, while a key naming a network address is deliberately
     * left platform-wide.
     *
     * Operators live in their own campaign's database (D-1), so an email
     * address or an operator id identifies a different person in each campaign.
     * The `ada@example.com` of one campaign is not the `ada@example.com` of
     * another, and neither is operator 1. Keyed without the campaign, five
     * failed sign-ins against one campaign lock that address out of every other
     * campaign on the platform — a campaign that has never heard of the caller
     * refusing someone it does know. Measured before this split: a limiter hit
     * recorded in one campaign was read back at the same count from another
     * campaign, and from central.
     *
     * A network address is the opposite case. It identifies one caller wherever
     * they knock, so scoping it per campaign would hand anyone holding a list
     * of campaign hostnames a fresh budget for every name on it. Those limits
     * stay global on purpose, and they are the backstop the per-person limits
     * cannot be: a caller working through a thousand distinct addresses never
     * fills a per-person bucket at all.
     *
     * The campaign is carried in the *key* rather than by making the limiter's
     * cache campaign-aware, and that is a deliberate choice rather than the
     * easy one. Laravel builds its rate limiter as a container singleton over
     * `$app->make('cache')->driver(...)`, and `driver()` is a real method on the
     * framework's cache manager, so the per-campaign tagging our tenancy package
     * applies — which it applies only through `__call` — never runs. (The same
     * bypass, through the neighbouring `store()`, is why D-3 rejected a
     * permission package.) That could be worked around by forgetting the
     * singleton on every campaign switch, but doing so would scope *every*
     * limit per campaign, including the ones that must not be. Stating what a
     * key means is both thinner and the only form that gets both halves right.
     *
     * One trap worth knowing before anyone edits `fortify.limiters` in config.
     * Fortify carries a *second* login limiter, `LoginRateLimiter`, keyed
     * `lower(email)|ip` with no campaign in it at all. It is not live here:
     * Fortify's default login pipeline reads
     * `config('fortify.limiters.login') ? null : EnsureLoginIsNotThrottled`, so
     * naming a limiter — which we do — drops the action that would consult it.
     * `AttemptToAuthenticate` still increments that key on every failed
     * sign-in, but nothing ever reads the counter. Clearing `limiters.login` to
     * fall back on Fortify's own throttling would therefore silently restore
     * the platform-wide key this method exists to replace. The campaign test
     * covering these limiters proves the action is inactive today, because a
     * correct sign-in on a second campaign survives another campaign's
     * exhausted budget for the very same address.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('two-factor', function (Request $request) {
            // The challenge is answered against an operator id parked in the
            // session, and operator ids restart at 1 in every campaign — so
            // this key is meaningless without the campaign attached to it.
            return [
                Limit::perMinute(5)->by($this->withinCampaign('two-factor:'.$request->session()->get('login.id'))),
                Limit::perMinute(30)->by($this->callerAddress($request, 'two-factor')),
            ];
        });

        RateLimiter::for('login', function (Request $request) {
            return [
                Limit::perMinute(5)->by($this->withinCampaign('login:'.$this->submittedUsername($request))),
                Limit::perMinute(30)->by($this->callerAddress($request, 'login')),
            ];
        });

        // The endpoints Fortify itself gives no limiter hook for. `limiters`
        // below reaches login, the two-factor challenge, passkeys and email
        // verification; registration and both halves of the password-reset flow
        // accept unauthenticated POSTs and were entirely unmetered. This limiter
        // is listed on Fortify's `middleware` key, so it sees every route
        // Fortify registers and declines -- explicitly, by returning no limit at
        // all -- to touch the ones already covered above.
        RateLimiter::for('auth-writes', function (Request $request) {
            return match ($request->route()?->getName()) {
                // Registration has no prior identity to key on: the address
                // being registered is by definition one nobody has claimed yet.
                // So the caller is all there is to meter, and that key is
                // deliberately platform-wide.
                //
                // A campaign-wide registration budget was considered and left
                // out. It would cap a campaign's registrations however many
                // callers they came from, which sounds stricter and is worse:
                // one caller could then spend a campaign's whole budget and
                // block every legitimate operator from joining it. Open
                // registration's real answer is onboarding by invitation, which
                // is a recorded deferral rather than something throttling fixes
                // -- metering slows a hostname-guesser without changing who
                // wins the race to claim a fresh campaign as Owner.
                'register.store' => Limit::perMinute(5)->by($this->callerAddress($request, 'register')),

                // Requesting a link is the tightest budget in this application,
                // because it is the only unauthenticated endpoint that makes the
                // platform *send*. Unmetered it is a way to have a campaign mail
                // somebody over and over at the platform's sending reputation's
                // expense, and it writes a reset-token row carrying that address
                // every time.
                'password.email' => [
                    Limit::perMinute(3)->by($this->withinCampaign('password-reset-request:'.$this->submittedUsername($request))),
                    Limit::perMinute(10)->by($this->callerAddress($request, 'password-reset-request')),
                ],

                // Submitting a reset is deliberately a *separate* budget from
                // requesting one, rather than the same bucket seen twice. An
                // operator who asked for two links and then used one would
                // otherwise be spending a third of their allowance on the step
                // that finally succeeds, and be refused for having followed the
                // instructions carefully.
                'password.update' => [
                    Limit::perMinute(5)->by($this->withinCampaign('password-reset-submit:'.$this->submittedUsername($request))),
                    Limit::perMinute(10)->by($this->callerAddress($request, 'password-reset-submit')),
                ],

                default => Limit::none(),
            };
        });

        RateLimiter::for('passkeys', function (Request $request) {
            // A credential id names a row in one campaign's own `passkeys`
            // table. The session id it falls back to is central rather than
            // campaign-scoped, but it is per-visitor either way, so scoping
            // both to the campaign costs nothing and keeps one rule here.
            $credential = (string) ($request->input('credential.id') ?: $request->session()->getId());

            return [
                Limit::perMinute(10)->by($this->withinCampaign('passkeys:'.$credential)),
                Limit::perMinute(60)->by($this->callerAddress($request, 'passkeys')),
            ];
        });
    }

    /**
     * Scope a rate-limit key to the campaign whose request is being limited.
     *
     * A request that reaches a limiter outside campaign context is a central
     * one, and it gets a bucket of its own rather than quietly sharing the
     * first campaign's. That case is not currently reachable — every throttled
     * route in this application is served through the `tenant` middleware
     * group, which resolves the campaign before any route middleware runs — but
     * returning a campaign-less key would be indistinguishable from the bug
     * this method exists to prevent, so it is spelled rather than assumed.
     */
    private function withinCampaign(string $key): string
    {
        $campaign = $this->app->make(Tenancy::class)->tenant;

        $campaignKey = $campaign instanceof Tenant
            ? (string) $campaign->getTenantKey()
            : 'central';

        return $campaignKey.'|'.$key;
    }

    /**
     * The caller's network address within one family of endpoints.
     *
     * The scope is not decoration. Laravel keys a throttled request by the
     * limiter's *name* plus the key, so two limits in two differently-named
     * limiters cannot collide — but `auth-writes` covers three endpoint families
     * under one name, and registration allows a different number of attempts per
     * minute than a password-reset request does. Without the scope those three
     * would share a single counter measured against whichever ceiling the
     * current route happens to carry, which is not a limit anyone chose.
     *
     * A request with no address at all falls into a shared bucket rather than
     * getting an empty key, which is stricter than no limit and therefore the
     * safe direction to fail in.
     */
    private function callerAddress(Request $request, string $scope): string
    {
        return $scope.':address:'.($request->ip() ?? 'unknown');
    }

    /**
     * The operator address a request is submitting, normalized for keying.
     *
     * Transliterated and lowercased so that two spellings of one address cannot
     * be handed two budgets, which is the normalization Fortify applies to its
     * own throttle key and the reason this repeats it rather than inventing one.
     */
    private function submittedUsername(Request $request): string
    {
        return Str::transliterate(Str::lower((string) $request->input(Fortify::username())));
    }
}
