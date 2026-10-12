<?php

namespace Database\Seeders;

use App\Models\Conversation;
use App\Models\Order;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Sample pages, chats, products and orders for trying the inbox locally. Do not run in production. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $shop = Page::updateOrCreate(['page_id' => 'demo-1'], ['name' => 'Demo Shop Nepal', 'access_token' => 'demo', 'subscribed' => true]);
        $fashion = Page::updateOrCreate(['page_id' => 'demo-2'], ['name' => 'Demo Fashion', 'access_token' => 'demo', 'subscribed' => true]);

        $chats = [
            [$shop, 'Shreekrishna Adhikari', 'ad', [
                ['in', 'customer', 'price ?', 30],
                ['out', 'ai', 'Namaste hajur! Dashain special combo ko price Rs. 2,499 ho (pahile Rs. 3,200). Order garna chahanu huncha?', 29],
            ]],
            [$shop, 'Tara Sapkota', 'ad', [
                ['in', 'customer', 'delivery kati din ma huncha?', 90],
                ['out', 'ai', 'Kathmandu valley bhitra 1-2 din ma free delivery huncha hajur. Bahira 2-4 din ra Rs. 150 charge lagcha.', 89],
                ['in', 'customer', 'ok order garchu', 3],
            ]],
            [$fashion, 'Bir Bahadur Tamang', null, [
                ['in', 'customer', 'I want to return my jacket, size is wrong', 200],
                ['out', 'ai', 'Sorry about that! Our team member will help you with the return shortly.', 199],
            ]],
            [$fashion, 'Gopal Lamsal', null, [
                ['in', 'customer', 'Hello, is this available in black?', 1500],
                ['out', 'agent', 'Yes, black is available in M and L.', 1490],
            ]],
        ];

        foreach ($chats as $i => [$page, $name, $ad, $messages]) {
            $conversation = Conversation::updateOrCreate(['page_id' => $page->id, 'psid' => 'demo-user-'.$i], [
                'customer_name' => $name,
                'ad_id' => $ad ? '1202000000'.$i : null,
                'ad_title' => $ad ? '🪔 Dashain special offer - combo pack at Rs. 2,499' : null,
                'needs_human' => $name === 'Bir Bahadur Tamang',
                'ai_enabled' => $name !== 'Gopal Lamsal',
                'labels' => ['Tara Sapkota' => ['Interested', 'Ordered'], 'Shreekrishna Adhikari' => ['Interested'], 'Bir Bahadur Tamang' => ['Complaint'], 'Gopal Lamsal' => ['Follow up']][$name] ?? [],
                'notes' => $name === 'Tara Sapkota' ? "Phone: 9841000001\nAddress: Baneshwor, Kathmandu\n1x Dashain combo, COD" : null,
            ]);
            $conversation->messages()->delete();

            foreach ($messages as [$dir, $type, $text, $minutesAgo]) {
                $at = now()->subMinutes($minutesAgo);
                $conversation->messages()->create(['direction' => $dir, 'sender_type' => $type, 'text' => $text, 'sent_at' => $at]);
                $conversation->fill(['last_message_text' => $text, 'last_message_from' => $type, 'last_message_at' => $at]);
                if ($dir === 'in') {
                    $conversation->last_customer_message_at = $at;
                }
            }
            $conversation->unread_count = end($messages)[0] === 'in' ? 1 : 0;
            $conversation->save();
        }

        // An agent who only handles the shop page.
        $agent = User::updateOrCreate(['email' => 'agent@example.test'], [
            'name' => 'Sushant Kumar',
            'password' => 'demo-agent-123',
            'role' => 'agent',
            'active' => true,
        ]);
        $agent->pages()->sync([$shop->id]);

        $products = [
            [$shop, 'Dashain special combo', 'Rs. 2,499', 'Pack of 5 festive items. Was Rs. 3,200. Offer valid until Dashain.', ['#e85d2a', '#f2b134']],
            [$shop, 'Tihar diyo set (12 pcs)', 'Rs. 650', 'Handmade clay diyos, 12 pieces.', ['#b5452c']],
            [$fashion, 'Winter puffer jacket', 'Rs. 3,800', 'Sizes M, L, XL. Colours black and olive.', ['#2b3a4a', '#556b2f']],
            [null, 'Gift voucher', 'Rs. 1,000', 'Valid on all pages for 6 months.', []],
        ];
        foreach ($products as [$page, $name, $price, $description, $colours]) {
            $product = Product::updateOrCreate(['name' => $name], [
                'page_id' => $page?->id,
                'price' => $price,
                'description' => $description,
                'active' => true,
            ]);
            $product->media->each->delete();
            foreach ($colours as $i => $colour) {
                if ($path = $this->demoImage($name, $colour)) {
                    $product->media()->create(['type' => 'image', 'path' => $path, 'mime' => 'image/png', 'sort' => $i]);
                }
            }
        }

        $this->demoOrders($shop);
    }

    /** Orders spread over the last two weeks so the dashboard has something to show. */
    private function demoOrders(Page $fallbackPage): void
    {
        Order::query()->delete();
        $service = app(OrderService::class);
        $products = Product::orderBy('id')->get()->values();
        $customers = [
            ['Tara Sapkota', '9841000001', 'Baneshwor', 'Kathmandu'],
            ['Shreekrishna Adhikari', '9851000002', 'Lakeside', 'Pokhara'],
            ['Gopal Lamsal', '9861000003', 'Jawalakhel', 'Lalitpur'],
            ['Sita Rai', '9801000004', 'Dharan-8', 'Dharan'],
            ['Bikash Thapa', '9811000005', 'Suryabinayak', 'Bhaktapur'],
        ];
        $statuses = ['delivered', 'delivered', 'delivered', 'shipped', 'confirmed', 'pending', 'cancelled'];
        if ($products->isEmpty()) {
            return;
        }

        mt_srand(7);
        for ($i = 0; $i < 26; $i++) {
            [$name, $phone, $address, $city] = $customers[$i % count($customers)];
            $product = $products[$i % $products->count()];
            $daysAgo = mt_rand(0, 13);
            $order = $service->save(new Order(['source' => $i % 3 === 0 ? 'ai' : 'agent']), [
                'customer_name' => $name,
                'phone' => $phone,
                'address' => $address,
                'city' => $city,
                'payment_method' => ['cod', 'cod', 'esewa', 'khalti'][$i % 4],
                'delivery_charge' => $city === 'Kathmandu' ? 0 : 150,
                'items' => [['product_id' => $product->id, 'name' => $product->name, 'unit_price' => $product->unitPrice(), 'quantity' => 1 + $i % 3]],
                'status' => $daysAgo < 2 ? 'pending' : $statuses[$i % count($statuses)],
            ], $product->page_id ?? $fallbackPage->id);
            $order->forceFill(['created_at' => now()->subDays($daysAgo)->setTime(mt_rand(9, 20), mt_rand(0, 59))])->saveQuietly();
        }
    }

    /** A simple coloured placeholder picture (needs the GD extension; skipped without it). */
    private function demoImage(string $label, string $hex): ?string
    {
        if (! function_exists('imagecreatetruecolor')) {
            return null;
        }
        $img = imagecreatetruecolor(600, 600);
        [$r, $g, $b] = sscanf($hex, '#%02x%02x%02x');
        imagefill($img, 0, 0, imagecolorallocate($img, $r, $g, $b));
        $white = imagecolorallocate($img, 255, 255, 255);
        imagefilledellipse($img, 300, 260, 260, 260, imagecolorallocatealpha($img, 255, 255, 255, 90));
        imagestring($img, 5, (int) (300 - strlen($label) * 4.5), 470, $label, $white);

        ob_start();
        imagepng($img);
        $png = ob_get_clean();

        $path = 'products/demo/'.Str::slug($label).'-'.substr(md5($hex), 0, 6).'.png';
        Storage::disk('media')->put($path, $png);

        return $path;
    }
}
