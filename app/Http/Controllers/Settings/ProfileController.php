<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use App\Operators\RemoveOperator;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     *
     * **Refused to the last operator who may govern the campaign while anybody
     * else stays** (§7 criterion 3). This is the surface an operator actually
     * uses to leave, and until Phase 6 Step 4 it removed them unconditionally:
     * measured then, the sole Owner of a campaign that also had a Staff
     * operator left through it, and the campaign was run from that moment by
     * somebody who could govern nothing and whom the platform would not
     * replace. RemoveOperator asks the question and signs them out only once
     * the answer is yes, so a refused operator is still signed in and still
     * reading the reason.
     */
    public function destroy(ProfileDeleteRequest $request, RemoveOperator $remove): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $remove(
            $user,
            __('You are the last operator who can govern this campaign, and leaving would leave the others with nobody who can. Make somebody else an Owner first.'),
            beforeDeleting: fn () => Auth::logout(),
        );

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
