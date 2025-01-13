<?php

namespace App\Http\Controllers;

use App\Mail\SendCode;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Storage;

class BotController extends Controller
{
    public function store(int $chatId, string $text, $replyMarkup = null)
    {
        $token = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
        $payload = [
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'HTML',
        ];

        if ($replyMarkup) {
            $payload['reply_markup'] = json_encode($replyMarkup);
        }

        Http::post($token . '/sendMessage', $payload);
    }

    public function bot(Request $request)
    {
        try {
            $data = $request->all();
            $chat_id = $data['message']['chat']['id'] ?? null;
            $text = $data['message']['text'] ?? null;
            $photo = $data['message']['photo'] ?? null;
            $call = $data['callback_query'] ?? null;
            $message_id = $data['message']['message_id'] ?? null;
            $call_id = $data['callback_query']['message']['chat']['id'] ?? null;
            $callmid = $data['callback_query']['message']['message_id'] ?? null;

            if ($text === '/start') {
                $this->store($chat_id, "Assalomu alaykum! Iltimos, tanlang:", [
                    'keyboard' => [
                        [
                            ['text' => 'Register'],
                            ['text' => 'Login']
                        ]
                    ],
                    'resize_keyboard' => true,
                    'one_time_keyboard' => true,
                ]);
                return;
            }

            if ($text === 'Register') {
                Cache::put("register_step_{$chat_id}", 'name');
                $this->store($chat_id, "Iltimos, ismingizni kiriting:", [
                    'remove_keyboard' => true,
                ]);
                return;
            }

            if (Cache::get("register_step_{$chat_id}") === 'name') {
                if (strlen($text) < 2) {
                    $this->store($chat_id, "Ism kamida 2 ta belgidan iborat bo‘lishi kerak!");
                    return;
                }

                Cache::put("register_name_{$chat_id}", $text);
                Cache::put("register_step_{$chat_id}", 'email');
                $this->store($chat_id, "Iltimos, elektron pochta manzilingizni kiriting:");
                return;
            }

            if (Cache::get("register_step_{$chat_id}") === 'email') {
                if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                    $this->store($chat_id, "Iltimos, haqiqiy email manzil kiriting!");
                    return;
                }

                if (User::where('email', $text)->exists()) {
                    $this->store($chat_id, "Bu email allaqachon ro'yxatdan o'tgan!");
                    return;
                }

                Cache::put("register_email_{$chat_id}", $text);
                Cache::put("register_step_{$chat_id}", 'password');
                $this->store($chat_id, "Iltimos, parolingizni kiriting:");
                return;
            }

            if (Cache::get("register_step_{$chat_id}") === 'password') {
                if (strlen($text) < 6) {
                    $this->store($chat_id, "Parol kamida 6 ta belgidan iborat bo‘lishi kerak!");
                    return;
                }

                Cache::put("register_password_{$chat_id}", $text);
                Cache::put("register_step_{$chat_id}", 'confirmation_code');

                $confirmation_code = Str::random(6);
                $email = Cache::get("register_email_{$chat_id}");
                $name = Cache::get("register_name_{$chat_id}");

                try {
                    Mail::to($email)->send(new SendCode($name, $confirmation_code));
                    Log::info('Email sent successfully');
                    $this->store($chat_id, "Emailizga tasdiqlash kodi yuborildi. Iltimos, uni kiriting.");
                } catch (\Exception $e) {
                    Log::error('Email sending failed: ' . $e->getMessage());
                    $this->store($chat_id, "Tasdiqlash kodi yuborishda xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.");
                }

                Cache::put("confirmation_code_{$chat_id}", $confirmation_code);
                return;
            }

            if (Cache::get("register_step_{$chat_id}") === 'confirmation_code') {
                if ($text === Cache::get("confirmation_code_{$chat_id}")) {
                    Cache::put("register_password_{$chat_id}", bcrypt(Cache::get("register_password_{$chat_id}")));
                    Cache::put("register_step_{$chat_id}", 'image');
                    $this->store($chat_id, "Tasdiqlash kodi to'g'ri. Iltimos, profilingiz uchun rasm yuboring.");
                    Cache::forget("confirmation_code_{$chat_id}");
                } else {
                    $this->store($chat_id, "Tasdiqlash kodi noto'g'ri. Iltimos, to'g'ri kodi kiriting.");
                }
                return;
            }

            if (Cache::get("register_step_{$chat_id}") === 'image') {
                if ($photo) {
                    $file_id = end($photo)['file_id'];

                    $telegram_api = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
                    $file_path_response = file_get_contents("{$telegram_api}/getFile?file_id={$file_id}");
                    $response = json_decode($file_path_response, true);

                    if (isset($response['result']['file_path'])) {
                        $file_path = $response['result']['file_path'];
                        $download_url = "https://api.telegram.org/file/bot" . env('TELEGRAM_BOT_TOKEN') . "/{$file_path}";

                        $image_name = uniqid() . '.jpg';
                        $image_content = file_get_contents($download_url);

                        if ($image_content) {
                            Storage::disk('public')->put("uploads/{$image_name}", $image_content);
                            $image_path = "uploads/{$image_name}";
                        } else {
                            $this->store($chat_id, "Rasmni yuklab olishda xatolik yuz berdi. Iltimos, qaytadan urinib ko'ring.");
                            return;
                        }
                        $user = User::create([
                            'name' => Cache::get("register_name_{$chat_id}"),
                            'email' => Cache::get("register_email_{$chat_id}"),
                            'password' => Cache::get("register_password_{$chat_id}"),
                            'chat_id' => $chat_id,
                            'image' => "uploads/{$image_name}",
                            'email_verified_at' => Carbon::now(),
                        ]);

                        $admin = User::where('role', 'admin')->first();

                        if ($admin) {
                            $userData = "Foydalanuvchi nomi: " . Cache::get("register_name_{$chat_id}") . "\n" .
                                "Email: " . Cache::get("register_email_{$chat_id}") . "\n" .
                                "Chat ID: " . $chat_id;

                            $replyMarkup = [
                                'inline_keyboard' => [
                                    [
                                        ['text' => 'Tasdiqlash✅', 'callback_data' => "confirm_{$chat_id}"],
                                        ['text' => 'Bekor qilish⛔️', 'callback_data' => "cancel_{$chat_id}"],
                                    ]
                                ]
                            ];
                            $this->store($admin->chat_id, $userData, $replyMarkup);
                        } else {
                            $this->store($chat_id, "Admin rolidagi foydalanuvchi topilmadi, shu sababdan sizning statusingiz faol emas");
                        }

                        $this->store($chat_id, "Siz muvaffaqiyatli ro'yxatdan o'tdingiz!");

                        Cache::forget("register_step_{$chat_id}");
                        Cache::forget("register_name_{$chat_id}");
                        Cache::forget("register_email_{$chat_id}");
                        Cache::forget("register_password_{$chat_id}");
                        Cache::forget("confirmation_code_{$chat_id}");
                    } else {
                        $this->store($chat_id, "Rasmni yuklab olishda muammo yuz berdi. Iltimos, qaytadan urinib ko'ring.");
                    }
                } else {
                    $this->store($chat_id, "Iltimos, rasm yuboring!");
                }
                return;
            }

            if ($text === '/profile') {
                $user = User::where('chat_id', $chat_id)->first();

                if ($user) {
                    $profileMessage = "<b>Sizning profilingiz:</b>\n\n" .
                        "<b>Ism:</b> {$user->name}\n" .
                        "<b>Email:</b> {$user->email}";

                    $this->store($chat_id, $profileMessage);

                    if ($user->image) {
                        $filePath = storage_path("app/public/{$user->image}");
                        if (file_exists($filePath)) {
                            Http::attach('photo', file_get_contents($filePath), basename($filePath))
                                ->post("https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN') . "/sendPhoto", [
                                    'chat_id' => $chat_id,
                                ]);
                        } else {
                            $this->store($chat_id, "Profilingiz uchun rasm topilmadi.");
                        }
                    } else {
                        $this->store($chat_id, "Profilingiz uchun rasm yo'q.");
                    }
                } else {
                    $this->store($chat_id, "Profilingiz topilmadi. Iltimos, avval ro'yxatdan o'ting.");
                }
            }
            if ($text === 'Login') {
                Cache::put("login_step_{$chat_id}", 'email');
                $this->store($chat_id, "Iltimos, emailingizni kiriting:", [
                    'remove_keyboard' => true,
                ]);

                return;
            }

            if (Cache::get("login_step_{$chat_id}") === 'email') {
                if (!filter_var($text, FILTER_VALIDATE_EMAIL)) {
                    $this->store($chat_id, "Iltimos, haqiqiy email manzil kiriting!");
                    return;
                }

                Cache::put("login_email_{$chat_id}", $text);
                Cache::put("login_step_{$chat_id}", 'password');
                $this->store($chat_id, "Iltimos, parolingizni kiriting:");
                return;
            }

            if (Cache::get("login_step_{$chat_id}") === 'password') {
                Cache::put("login_password_{$chat_id}", $text);

                $this->del($message_id, $chat_id);

                $email = Cache::get("login_email_{$chat_id}");
                $password = Cache::get("login_password_{$chat_id}");

                $user = User::where('email', $email)->first();

                if ($user && Hash::check($password, $user->password)) {
                    Cache::forget("login_step_{$chat_id}");
                    Cache::forget("login_email_{$chat_id}");
                    Cache::forget("login_password_{$chat_id}");

                    $this->store($chat_id, "Muvaffaqiyatli kirish! Xush kelibsiz, {$user->name}.");
                    $user->update(['chat_id' => $chat_id]);

                    $admin_chat_id = User::where('role', 'admin')->first()->chat_id;

                    $this->store($admin_chat_id, "Iltimos, foydalanuvchilarni tekshiring:", [
                        'keyboard' => [
                            [
                                ['text' => 'Barcha statusi 1 bo\'lgan foydalanuvchilar'],
                                ['text' => 'Barcha statusi 0 bo\'lgan foydalanuvchilar'],
                            ]
                        ],
                        'resize_keyboard' => true,
                        'one_time_keyboard' => true,
                    ]);

                } else {
                    $this->store($chat_id, "Email yoki parol noto'g'ri. Iltimos, qaytadan urinib ko'ring.");
                }
            }

            if ($text === 'Barcha statusi 1 bo\'lgan foydalanuvchilar') {
                $users = User::where('role', '!=', 'admin')->where('status', 1)->get();

                if ($users->isEmpty()) {
                    $this->store($chat_id, "Bunday statusli foydalanuvchilar mavjud emas.");
                } else {
                    $userList = "Barcha statusi 1 bo'lgan foydalanuvchilar:\n";
                    $counter = 1;

                    foreach ($users as $user) {
                        $userList .= "{$counter}) Foydalanuvchi nomi: {$user->name}, Email: {$user->email}\n";
                        $counter++;
                    }

                    $this->store($chat_id, $userList . "\nIltimos, foydalanuvchi raqamini kiriting, uning statusini teskari qilish uchun.");
                }
            }

            if ($text === 'Barcha statusi 0 bo\'lgan foydalanuvchilar') {
                $users = User::where('role', '!=', 'admin')->where('status', 0)->get();

                if ($users->isEmpty()) {
                    $this->store($chat_id, "Bunday statusli foydalanuvchilar mavjud emas.");
                } else {
                    $userList = "Barcha statusi 0 bo'lgan foydalanuvchilar:\n";
                    $counter = 1;

                    foreach ($users as $user) {
                        $userList .= "{$counter}) Foydalanuvchi nomi: {$user->name}, Email: {$user->email}\n";
                        $counter++;
                    }

                    $this->store($chat_id, $userList . "\nIltimos, foydalanuvchi raqamini kiriting, uning statusini teskari qilish uchun.");
                }
            }

            if (is_numeric($text) && $text > 0 && $text <= count(User::where('role', '!=', 'admin')->get())) {
                $userIndex = (int) $text - 1;

                $allUsers = User::where('role', '!=', 'admin')->get();
                if (isset($allUsers[$userIndex])) {
                    $user = $allUsers[$userIndex];

                    $user->status = !$user->status;
                    $user->save();

                    $newStatus = $user->status ? '1' : '0';
                    $this->store($chat_id, "Foydalanuvchining statusi teskari qilindi. Yangi status: {$newStatus}.");
                } else {
                    $this->store($chat_id, "Bunday foydalanuvchi topilmadi. Iltimos, to'g'ri raqamni kiriting.");
                }
            }
            if ($call) {
                $calldata = $call['data'];

                if (Str::startsWith($calldata, 'confirm_')) {
                    $call_id = Str::after($calldata, 'confirm_');
                    $user = User::where('chat_id', $call_id)->first();

                    if ($user) {
                        $user->status = 1;
                        $user->save();

                        $this->store($call_id, "Sizning profilingiz admin tomonidan tasdiqlandi! Endi tizimdan foydalanishingiz mumkin.");
                        $this->store(User::where('role', 'admin')->first()->chat_id, "Foydalanuvchi muvaffaqiyatli tasdiqlandi.");
                    } else {
                        $this->store(User::where('role', 'admin')->first()->chat_id, "Foydalanuvchini topib bo'lmadi.");
                    }
                    return;
                }

                if (Str::startsWith($calldata, 'cancel_')) {
                    $call_id = Str::after($calldata, 'cancel_');
                    $user = User::where('chat_id', $call_id)->first();

                    if ($user) {
                        $user->delete();
                        $this->store($call_id, "Sizning profilingiz admin tomonidan bekor qilindi.");
                        $this->store(User::where('role', 'admin')->first()->chat_id, "Foydalanuvchi muvaffaqiyatli o'chirildi.");
                    } else {
                        $this->store(User::where('role', 'admin')->first()->chat_id, "Foydalanuvchini topib bo'lmadi.");
                    }
                    return;
                }

                if (Str::startsWith($calldata, 'accept')) {
                    Log::info('accept');
                    $userId = Str::after($calldata, 'accept_');
                    $user = User::find($userId);
                    if ($user) {
                        $this->removeInlineKeyboard($callmid, $user->chat_id);
                        $this->store($user->chat_id,'Buyurtma muvaffaqiyatli qabul qilindi');
                    } else {
                        $this->store($user->chat_id, "Buyurtma topilmadi.");
                    }
                } elseif (Str::startsWith($calldata, 'reject')) {
                    Log::info('reject');
                    $userId = Str::after($calldata, 'reject_');
                    $user = User::find($userId);
                    if ($user) {
                        $this->del($callmid, $user->chat_id);
                        $this->store($user->chat_id, "Buyurtma rad etildi.");
                    } else {
                        $this->store($user->chat_id, "Buyurtma topilmadi.");
                    }
                }
            }
        } catch (\Exception $exception) {
            Log::error($exception);
            return response()->json([
                'status' => 'error',
                'message' => $exception->getMessage()
            ]);
        }
    }
    public function del($message_id, $chat_id)
    {
        $token = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
        $payload = [
            'chat_id' => $chat_id,
            'message_id' => $message_id
        ];
        Http::post($token . '/deletemessage', $payload);
    }
    public function edit($message_id, $chat_id, $new_message)
    {
        $token = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
        $payload = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $new_message,
            'parse_mode' => 'HTML',
        ];
        Http::post($token . '/editMessageText', $payload);
    }

    public function removeInlineKeyboard($message_id, $chat_id)
    {
        $token = "https://api.telegram.org/bot" . env('TELEGRAM_BOT_TOKEN');
        $payload = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'reply_markup' => json_encode(['inline_keyboard' => []])
        ];
        Http::post($token . '/editMessageReplyMarkup', $payload);
    }

}
