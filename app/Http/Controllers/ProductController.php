<?php

namespace App\Http\Controllers;

use App\Models\Page;
use App\Models\Product;
use App\Models\ProductMedia;
use App\Services\MediaStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** Product catalog with photos/videos that the team and the AI can send in chats. */
class ProductController extends Controller
{
    public function index(Request $request)
    {
        $user = $request->user();

        $products = Product::visibleTo($user)
            ->with(['media', 'page:id,name'])
            ->when($request->filled('page'), fn ($q) => $request->query('page') === 'all'
                ? $q->whereNull('page_id')
                : $q->where('page_id', $request->integer('page')))
            ->when($request->filled('q'), fn ($q) => $q->where('name', 'like', '%'.$request->query('q').'%'))
            ->orderByDesc('active')
            ->orderBy('name')
            ->get();

        return view('products.index', [
            'products' => $products,
            'pages' => Page::visibleTo($user)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function create(Request $request)
    {
        return view('products.form', [
            'product' => new Product(['page_id' => $request->integer('page') ?: null]),
            'pages' => Page::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request, MediaStore $store)
    {
        $data = $this->validated($request);
        $product = Product::create($data);
        $this->saveMedia($request, $product, $store);

        return redirect()->route('products.index')->with('status', "Saved {$product->name}.");
    }

    public function edit(Request $request, Product $product)
    {
        Gate::authorize('manage-product', $product);

        return view('products.form', [
            'product' => $product->load('media'),
            'pages' => Page::visibleTo($request->user())->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request, Product $product, MediaStore $store)
    {
        Gate::authorize('manage-product', $product);

        $product->update($this->validated($request));
        $this->saveMedia($request, $product, $store);

        if ($request->filled('remove_media')) {
            $product->media()->whereIn('id', (array) $request->input('remove_media'))->get()->each->delete();
        }

        return redirect()->route('products.edit', $product)->with('status', 'Saved.');
    }

    public function destroy(Product $product)
    {
        Gate::authorize('manage-product', $product);

        $product->media->each->delete();
        $product->delete();

        return redirect()->route('products.index')->with('status', "Deleted {$product->name}.");
    }

    public function destroyMedia(ProductMedia $media)
    {
        Gate::authorize('manage-product', $media->product);
        $media->delete();

        return back()->with('status', 'Removed the file.');
    }

    private function validated(Request $request): array
    {
        $user = $request->user();
        $allowedPages = Page::visibleTo($user)->pluck('id')->all();

        $data = $request->validate([
            'name' => ['required', 'string', 'max:200'],
            'price' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:5000'],
            // Admins may share a product with all pages (empty value); agents must pick one of their pages.
            'page_id' => [$user->isAdmin() ? 'nullable' : 'required', 'integer', Rule::in($allowedPages)],
            'files' => ['array', 'max:10'],
            'files.*' => MediaStore::rules(),
        ], [
            'files.*.mimetypes' => 'Only JPG, PNG, WEBP, GIF photos and MP4/MOV videos can be uploaded.',
            'files.*.max' => 'Each file must be smaller than 25 MB (Messenger limit).',
        ]);
        $data['active'] = $request->boolean('active', true);

        return collect($data)->only(['name', 'price', 'description', 'page_id', 'active'])->all();
    }

    private function saveMedia(Request $request, Product $product, MediaStore $store): void
    {
        $sort = (int) $product->media()->max('sort');
        foreach ($request->file('files', []) as $file) {
            $product->media()->create($store->store($file, 'products') + ['sort' => ++$sort]);
        }
    }
}
