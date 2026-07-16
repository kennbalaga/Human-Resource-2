<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notificationPage' => $request->user()->notifications()->latest()->paginate(15),
            'currentRole' => $request->user()->roles()->value('name') ?? 'Employee',
        ]);
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
