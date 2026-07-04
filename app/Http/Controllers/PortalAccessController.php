<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Vendor;
use App\Notifications\PortalAccessNotification;
use Filament\Facades\Filament;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

/**
 * Signed-link set-password + forgot-password flow shared by both portals. The
 * guard ('customer' | 'vendor') comes from the route default, never user input.
 */
class PortalAccessController extends Controller
{
    /** @var array<string, class-string<\Illuminate\Database\Eloquent\Model>> */
    private const MODELS = ['customer' => Customer::class, 'vendor' => Vendor::class];

    /** Email column per guard (Customer's is non-standard). */
    private const EMAIL_COLUMN = ['customer' => 'customer_email', 'vendor' => 'email'];

    public function showSetPassword(Request $request, int $id): View
    {
        $guard = $this->guard($request);
        $this->modelClass($guard)::findOrFail($id);

        return view('portal.set-password', ['action' => $request->fullUrl()]);
    }

    public function setPassword(Request $request, int $id): RedirectResponse
    {
        $guard = $this->guard($request);
        $request->validate(['password' => ['required', 'confirmed', Password::defaults()]]);

        $model = $this->modelClass($guard)::findOrFail($id);
        $model->password = $request->string('password')->value();
        $model->save();

        return redirect(Filament::getPanel($guard)->getLoginUrl())
            ->with('status', 'Password set — please log in.');
    }

    public function showForgot(Request $request): View
    {
        $guard = $this->guard($request);

        return view('portal.forgot', ['action' => route("portal.{$guard}.forgot.send")]);
    }

    public function sendForgot(Request $request): RedirectResponse
    {
        $guard = $this->guard($request);
        $request->validate(['email' => ['required', 'email']]);

        $model = $this->modelClass($guard)::query()
            ->where(self::EMAIL_COLUMN[$guard], $request->string('email')->value())
            ->first();

        // Send only for a real record, but always return the same message so a
        // caller can't probe which emails exist (no account enumeration).
        $model?->notify(new PortalAccessNotification($guard));

        return back()->with('status', 'If that email is on file, a set-password link has been sent.');
    }

    private function guard(Request $request): string
    {
        $guard = (string) $request->route('guard');
        abort_unless(array_key_exists($guard, self::MODELS), 404);

        return $guard;
    }

    /**
     * @return class-string<\Illuminate\Database\Eloquent\Model>
     */
    private function modelClass(string $guard): string
    {
        return self::MODELS[$guard];
    }
}
