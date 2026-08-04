<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    /** @var array<int, string> */
    private const CATEGORIES = ['attendance', 'schedule', 'leave', 'security', 'general'];

    /**
     * Older notifications stored before category tracking existed have no
     * `data->category` value. The notification list infers a category for
     * those from their icon, so counts/filters must use the same fallback
     * or such notifications only ever show up under "General".
     *
     * @var array<string, string>
     */
    private const LEGACY_ICON_CATEGORIES = [
        'clock' => 'attendance',
        'calendar' => 'schedule',
        'leave' => 'leave',
    ];

    public function index(Request $request): View
    {
        $validated = $request->validate([
            'category' => ['nullable', 'string', 'in:'.implode(',', self::CATEGORIES)],
        ]);

        $activeCategory = $validated['category'] ?? null;

        $query = $request->user()->notifications()->latest();

        if ($activeCategory !== null) {
            $this->applyCategoryFilter($query, $activeCategory);
        }

        return view('notifications.index', [
            'notificationPage' => $query->paginate(15)->withQueryString(),
            'currentRole' => $request->user()->roles->first()?->name ?? 'Employee',
            'activeCategory' => $activeCategory,
            'categoryCounts' => $this->categoryCounts($request->user()),
        ]);
    }

    /** @return array<string, int> */
    private function categoryCounts(User $user): array
    {
        $counts = [];

        foreach (self::CATEGORIES as $category) {
            $counts[$category] = $this->applyCategoryFilter($user->notifications(), $category)->count();
        }

        return $counts;
    }

    private function applyCategoryFilter(Builder $query, string $category): Builder
    {
        $legacyIcons = array_keys(array_filter(
            self::LEGACY_ICON_CATEGORIES,
            fn (string $mappedCategory) => $mappedCategory === $category,
        ));

        return $query->where(function ($query) use ($category, $legacyIcons): void {
            $query->where('data->category', $category);

            if ($category === 'general') {
                $query->orWhere(function ($query): void {
                    $query->whereNull('data->category')
                        ->whereNotIn('data->icon', array_keys(self::LEGACY_ICON_CATEGORIES));
                });

                return;
            }

            if ($legacyIcons !== []) {
                $query->orWhere(function ($query) use ($legacyIcons): void {
                    $query->whereNull('data->category')->whereIn('data->icon', $legacyIcons);
                });
            }
        });
    }

    public function open(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);
        $item->markAsRead();
        $url = data_get($item->data, 'action_url');

        if (is_string($url) && $this->isSafeApplicationUrl($request, $url)) {
            return redirect()->to($url);
        }

        return redirect()->route('notifications.index');
    }

    public function markAllRead(Request $request): RedirectResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        return back()->with('success', 'All notifications marked as read.');
    }

    public function toggleRead(Request $request, string $notification): RedirectResponse
    {
        $item = $request->user()->notifications()->findOrFail($notification);

        $item->read_at ? $item->markAsUnread() : $item->markAsRead();

        return back();
    }

    private function isSafeApplicationUrl(Request $request, string $url): bool
    {
        if (str_starts_with($url, '/')) {
            return true;
        }

        $host = parse_url($url, PHP_URL_HOST);
        $applicationHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        return is_string($host) && in_array($host, array_filter([$request->getHost(), $applicationHost]), true);
    }
}
