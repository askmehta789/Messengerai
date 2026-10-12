<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Services\MetaGraph;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

class PageController extends Controller
{
    public function index()
    {
        return view('pages', [
            'pages' => Page::withCount('conversations')->with('users:id,name')->orderBy('name')->get(),
            'metaReady' => config('services.meta.app_id') && config('services.meta.app_secret'),
            'webhookUrl' => route('webhook.receive'),
            'callbackUrl' => route('pages.callback'),
            'verifyToken' => config('services.meta.verify_token'),
        ]);
    }

    public function connect(Request $request, MetaGraph $graph)
    {
        if (! config('services.meta.app_id') || ! config('services.meta.app_secret')) {
            return back()->with('error', 'Set META_APP_ID and META_APP_SECRET in the .env file first.');
        }

        $state = Str::random(40);
        $request->session()->put('meta_oauth_state', $state);

        return redirect()->away($graph->loginUrl(route('pages.callback'), $state));
    }

    public function callback(Request $request, MetaGraph $graph)
    {
        $expected = $request->session()->pull('meta_oauth_state');
        if (! $expected || ! hash_equals($expected, (string) $request->query('state'))) {
            return redirect()->route('pages.index')->with('error', 'Facebook login expired. Please try again.');
        }
        if ($request->filled('error')) {
            return redirect()->route('pages.index')->with('error', 'Facebook login was cancelled: '.$request->query('error_description', $request->query('error')));
        }

        try {
            $userToken = $graph->userTokenFromCode((string) $request->query('code'), route('pages.callback'));
            $managed = $graph->managedPages($userToken);
        } catch (RuntimeException $e) {
            return redirect()->route('pages.index')->with('error', $e->getMessage());
        }

        if ($managed === []) {
            return redirect()->route('pages.index')->with('error', 'No pages were shared. Make sure you select your pages in the Facebook dialog.');
        }

        $connected = 0;
        $errors = [];
        foreach ($managed as $p) {
            $page = Page::updateOrCreate(['page_id' => $p['id']], [
                'name' => $p['name'],
                'access_token' => $p['access_token'],
                'picture_url' => $p['picture'],
            ]);
            try {
                $graph->subscribePage($page);
                $page->update(['subscribed' => true, 'last_error' => null]);
                $connected++;
            } catch (RuntimeException $e) {
                $page->update(['subscribed' => false, 'last_error' => $e->getMessage()]);
                $errors[] = "{$page->name}: {$e->getMessage()}";
            }
        }

        $redirect = redirect()->route('pages.index')->with('status', "Connected {$connected} page(s).");

        return $errors ? $redirect->with('error', implode(' | ', $errors)) : $redirect;
    }

    public function update(Request $request, Page $page)
    {
        $data = $request->validate([
            'ai_enabled' => ['sometimes', 'boolean'],
            'ai_instructions' => ['sometimes', 'nullable', 'string', 'max:30000'],
        ]);
        $page->update($data);

        return back()->with('status', "Saved settings for {$page->name}.");
    }

    public function resubscribe(Page $page, MetaGraph $graph)
    {
        try {
            $graph->subscribePage($page);
            $page->update(['subscribed' => true, 'last_error' => null]);
        } catch (RuntimeException $e) {
            $page->update(['subscribed' => false, 'last_error' => $e->getMessage()]);

            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$page->name} is receiving messages.");
    }

    public function destroy(Page $page, MetaGraph $graph)
    {
        try {
            $graph->unsubscribePage($page);
        } catch (RuntimeException) {
            // Token may already be invalid; remove locally anyway.
        }
        $page->delete();

        return back()->with('status', "Disconnected {$page->name}.");
    }
}
