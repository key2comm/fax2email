<?php

namespace App\Http\Middleware;

use App\Models\Account;
use App\Models\Fax;
use App\Models\FaxNumber;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return array_merge(parent::share($request), [
            ...parent::share($request),
            'name' => config('app.name'),
            'flash' => [
                'status' => fn () => $request->session()->get('status'),
            ],
            'version' => config('app.version'),
            'auth' => [
                'user' => $request->user()?->load('account:id,name'),
                'can' => [
                    'manageAccounts' => $request->user()?->can('viewAny', Account::class) ?? false,
                    'manageUsers' => $request->user()?->can('viewAny', User::class) ?? false,
                    'manageSettings' => $request->user()?->can('manage-settings') ?? false,
                    'viewFaxes' => $request->user()?->can('viewAny', Fax::class) ?? false,
                    'manageNumbers' => $request->user()?->can('viewAny', FaxNumber::class) ?? false,
                ],
            ],
        ]);
    }
}
