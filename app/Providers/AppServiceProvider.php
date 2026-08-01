<?php

namespace App\Providers;

use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
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
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Meter the endpoints this application serves to people who are not
     * operators.
     *
     * Filed here rather than beside the four limiters in FortifyServiceProvider
     * because those meter authentication, and this meters a supporter. The
     * shared vocabulary is the *shape* of the key, not the concern.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('unsubscribe', function (Request $request) {
            // **This key names a caller, not a person, and that decides its
            // scope (L-24).** A key naming a person -- an address, an operator
            // id -- is campaign-scoped, because identities live in each
            // campaign's own database and one address is a different human
            // being in each. A key naming a caller is deliberately not, because
            // one caller is one caller wherever they knock: campaign-scoping
            // this would hand anybody holding a list of campaign hostnames a
            // fresh budget for every name on it.
            //
            // **So the framework's own limiter escaping the tenancy package's
            // cache wrapper -- measured three separate times in this project as
            // a defect -- is here the behaviour that is wanted.** It is
            // platform-wide, which is exactly what a caller-keyed budget must
            // be. Recorded because it reads like the bug and is not.
            //
            // **There is deliberately no second, person-keyed limit**, unlike
            // every limiter in FortifyServiceProvider. Those meter an identity
            // the request asserts before it is believed; here the only identity
            // is the token, and metering by token would meter the victim rather
            // than the attacker -- somebody could lock a supporter out of
            // unsubscribing by spending their budget for them.
            //
            // Twenty a minute is generous for a person following a link once
            // and stingy for anything walking the space. It has to be generous:
            // an office or a mobile carrier puts many genuine supporters behind
            // one address, and the failure this endpoint must not produce is
            // somebody unable to get out.
            return Limit::perMinute(20)->by('unsubscribe:address:'.($request->ip() ?? 'unknown'));
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
