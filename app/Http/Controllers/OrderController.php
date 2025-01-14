<?php

namespace App\Http\Controllers;

use App\Models\Meal;
use App\Models\MealOrder;
use App\Models\Order;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class OrderController extends Controller
{
    public function index()
    {
        $cart = session('cart', []);
        $ids = is_array($cart) ? array_keys($cart) : [];
        $models = Meal::whereIn('id', $ids)->get();
        $users = User::where('status','=',1)->get();
        return view('card', compact('models', 'users'));
    }

    public function update(Request $request, $id)
    {
        $action = $request->input('action');
        $cart = session('cart', []);

        if (isset($cart[$id])) {
            if ($action === 'increment') {
                $cart[$id]['quantity']++;
            } elseif ($action === 'decrement' && $cart[$id]['quantity'] > 1) {
                $cart[$id]['quantity']--;
            }
        }

        session(['cart' => $cart]);
        return redirect()->route('cart.index');
    }

    public function remove($id)
    {
        $cart = session('cart', []);
        if (isset($cart[$id])) {
            unset($cart[$id]);
        }

        session(['cart' => $cart]);
        return redirect()->route('cart.index');
    }

    public function confirm(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id',
            'delivery_time' => 'required|date',
            'address' => 'required|string|max:255',
        ]);

        $order = Order::create([
            'user_id' => $validated['user_id'],
            'time' => $validated['delivery_time'],
            'address' => $validated['address'],
        ]);

        $carts = session('cart', []);

        foreach ($carts as $cart) {
            MealOrder::create([
                'meal_id' => $cart['meal_id'],
                'order_id' => $order->id,
                'count' => $cart['quantity']
            ]);
        }
        $user = User::find($request->user_id);

        $message = "<b>Yangi Buyurtma!</b>\n";
        $message .= "🆔 <b>Buyurtma ID:</b> #{$order->id}\n";
        $message .= "📍 <b>Manzil:</b> {$order->address}\n";
        $message .= "⏰ <b>Yetkazib berish vaqti:</b> " . date('d-m-Y H:i', strtotime($order->time)) . "\n";
        $message .= "🍴 <b>Taomlar:</b>\n";

        $totalPrice = 0;
        foreach ($carts as $cart) {
            $meal = Meal::find($cart['meal_id']);
            $quantity = $cart['quantity'];
            $price = $meal->price * $quantity;
            $totalPrice += $price;
            $message .= "🍽️ <b>{$meal->name}</b> - {$quantity}x: " . number_format($price) . " so'm\n";
        }

        $message .= "\n💳 <b>Jami summa:</b> " . number_format($totalPrice) . " so'm\n";

        $keyboard = [
            'inline_keyboard' => [
                [
                    ['text' => '✅ Qabul qilish', 'callback_data' => 'accept_' . $order->id],
                    ['text' => '❌ Rad etish', 'callback_data' => 'reject_' . $order->id]
                ]
            ]
        ];

        $token = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
        $payload = [
            'chat_id' => $user->chat_id,
            'text' => $message,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode($keyboard)
        ];

        Http::post($token . '/sendMessage', $payload);

        $address = $request->address;

        $geocode = explode(',', $address);

        if (count($geocode) == 2) {
            $latitude = trim($geocode[0]);
            $longitude = trim($geocode[1]);
        }

        $locationPayload = [
            'chat_id' => $user->chat_id,
            'latitude' => $latitude,
            'longitude' => $longitude,
        ];

        $response = Http::post($token . '/sendLocation', $locationPayload);

        session()->forget('cart');
        return redirect()->route('meal.index');
    }
}
