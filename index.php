<?php
require 'vendor/autoload.php';

use MongoDB\Client;
use MongoDB\BSON\UTCDateTime;

// تنظیمات لاگ
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
$log_file = 'bot.log';
function log_message($message) {
    global $log_file;
    file_put_contents($log_file, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND);
}

// تنظیمات ربات
$TOKEN = getenv('TOKEN') ?: '7881643365:AAEkvX2FvEBHHKvCLVLwBNiXXIidwNGwAzE';
$ADMIN_ID = 5637609683;
$MONGO_URI = getenv('MONGO_URI') ?: 'mongodb+srv://mohsenfeizi1386:p%40ssw0rd%279%27%21@cluster0.ounkvru.mongodb.net/?retryWrites=true&w=majority&appName=Cluster0';
$WEBHOOK_URL = getenv('WEBHOOK_URL') ?: 'https://chatgpt-qg71.onrender.com';
$PORT = getenv('PORT') ?: 1000;

// اتصال به MongoDB
try {
    $mongo = new Client($MONGO_URI, [
        'tls' => true,
        'connectTimeoutMS' => 60000,
        'serverSelectionTimeoutMS' => 60000,
        'socketTimeoutMS' => 60000
    ]);
    $db = $mongo->chatroom_db;
    $users_collection = $db->users;
    $messages_collection = $db->messages;
    $mongo->selectDatabase('admin')->command(['ping' => 1]);
    log_message('Successfully connected to MongoDB');
} catch (Exception $e) {
    log_message('MongoDB connection failed: ' . $e->getMessage());
    exit('MongoDB connection failed');
}

// کلمات ممنوعه
$FORBIDDEN_NAMES = ['admin', 'administrator', 'mod', 'moderator', 'support'];

// وضعیت ربات
$bot_active = true;

// متن قوانین
$RULES_TEXT = <<<EOT
سلام کاربر @%s  
به ربات Chat Room خوش آمدید!  

اینجا می‌توانید به‌صورت ناشناس با دیگر اعضای گروه چت کنید، با هم آشنا شوید و لذت ببرید.  

اما قوانینی وجود دارد که باید رعایت کنید تا از ربات مسدود نشوید:  

1. این ربات صرفاً برای سرگرمی، چت و دوست‌یابی است. از ربات برای تبلیغات، درخواست پول یا موارد مشابه استفاده نکنید.  
2. ارسال گیف به‌دلیل شلوغ نشدن ربات ممنوع است. اما ارسال عکس، موسیقی و موارد مشابه آزاد است، به‌شرطی که محتوای غیراخلاقی نباشد.  
3. ربات دارای سیستم ضداسپم است. در صورت اسپم کردن، به‌مدت ۲ دقیقه محدود خواهید شد.  
4. به یکدیگر احترام بگذارید. اگر فحاشی یا محتوای غیراخلاقی دیدید، با ریپلای روی پیام و ارسال دستور /report به ادمین اطلاع دهید.  

ربات در نسخه اولیه است و آپدیت‌های جدید در راه است.  
دوستان خود را به ربات دعوت کنید تا تجربه بهتری از چت داشته باشید.  
موفق باشید!
EOT;

// تابع برای ارسال درخواست به API تلگرام
function send_telegram_request($method, $params = []) {
    global $TOKEN;
    $url = "https://api.telegram.org/bot$TOKEN/$method";
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// تابع پاک‌سازی پیام‌های قدیمی
function clean_old_messages() {
    global $messages_collection;
    try {
        $threshold = new UTCDateTime((time() - 24 * 3600) * 1000);
        $messages_collection->deleteMany(['timestamp' => ['$lte' => $threshold]]);
        log_message('Cleaned old messages');
    } catch (Exception $e) {
        log_message('Error cleaning old messages: ' . $e->getMessage());
    }
}

// تنظیم وب‌هوک
function set_webhook() {
    global $WEBHOOK_URL, $TOKEN;
    $response = send_telegram_request('setWebhook', ['url' => "$WEBHOOK_URL/$TOKEN"]);
    if ($response['ok']) {
        log_message("Webhook set to $WEBHOOK_URL/$TOKEN");
    } else {
        log_message('Webhook setup failed: ' . json_encode($response));
    }
}

// پردازش آپدیت‌ها
$update = json_decode(file_get_contents('php://input'), true);
if (!empty($update)) {
    // پاک‌سازی پیام‌های قدیمی (فقط یک‌بار در هر درخواست)
    if (rand(1, 100) <= 5) { // ۵٪ شانس اجرا در هر درخواست
        clean_old_messages();
    }

    // هندلر برای پیام‌ها
    if (isset($update['message'])) {
        $message = $update['message'];
        $user_id = $message['from']['id'];
        $chat_id = $message['chat']['id'];
        $text = $message['text'] ?? '';
        $user = $users_collection->findOne(['user_id' => $user_id]);

        // بررسی بن بودن کاربر
        if ($user && isset($user['banned']) && $user['banned']) {
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'شما از ربات مسدود شده‌اید.'
            ]);
            exit;
        }

        // دستور /start
        if ($text === '/start') {
            if ($user && isset($user['registered']) && $user['registered']) {
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "قبلاً ثبت‌نام کردی، خوش اومدی @{$user['username']}!"
                ]);
            } else {
                $keyboard = [
                    ['inline_keyboard' => [[['text' => 'تأیید', 'callback_data' => 'confirm_start']]]]
                ];
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => 'لطفاً برای ادامه، یک پیام به @netgoris ارسال کنید و سپس روی دکمه تأیید کلیک کنید.',
                    'reply_markup' => json_encode($keyboard)
                ]);
            }
            exit;
        }

        // دستورات مدیریت
        if ($text === '/ban' && $user_id == $ADMIN_ID) {
            if (!isset($message['reply_to_message'])) {
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => 'لطفاً روی پیام کاربر ریپلای کنید.'
                ]);
                exit;
            }
            $target_user_id = $message['reply_to_message']['from']['id'];
            $users_collection->updateOne(
                ['user_id' => $target_user_id],
                ['$set' => ['banned' => true]],
                ['upsert' => true]
            );
            $target_user = $users_collection->findOne(['user_id' => $target_user_id]);
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "کاربر @{$target_user['username']} بن شد."
            ]);
            exit;
        }

        if ($text === '/unban' && $user_id == $ADMIN_ID) {
            if (!isset($message['reply_to_message'])) {
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => 'لطفاً روی پیام کاربر ریپلای کنید.'
                ]);
                exit;
            }
            $target_user_id = $message['reply_to_message']['from']['id'];
            $users_collection->updateOne(
                ['user_id' => $target_user_id],
                ['$set' => ['banned' => false]],
                ['upsert' => true]
            );
            $target_user = $users_collection->findOne(['user_id' => $target_user_id]);
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "کاربر @{$target_user['username']} آن‌بان شد."
            ]);
            exit;
        }

        if ($text === '/report') {
            if (!isset($message['reply_to_message'])) {
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => 'لطفاً روی پیام موردنظر ریپلای کنید.'
                ]);
                exit;
            }
            $target_user_id = $message['reply_to_message']['from']['id'];
            $target_user = $users_collection->findOne(['user_id' => $target_user_id]);
            send_telegram_request('sendMessage', [
                'chat_id' => $ADMIN_ID,
                'text' => "گزارش از @{$message['from']['username']}: پیام از @{$target_user['username']}\nمتن: {$message['reply_to_message']['text']}"
            ]);
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'گزارش شما به ادمین ارسال شد.'
            ]);
            exit;
        }

        if ($text === '/toggle' && $user_id == $ADMIN_ID) {
            global $bot_active;
            $bot_active = !$bot_active;
            $status = $bot_active ? 'روشن' : 'خاموش';
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => "ربات $status شد."
            ]);
            exit;
        }

        // بررسی غیرفعال بودن ربات
        if (!$bot_active && $user_id != $ADMIN_ID) {
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'ربات در حال حاضر غیرفعال است.'
            ]);
            exit;
        }

        // بررسی گیف
        if (isset($message['animation'])) {
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'ارسال گیف ممنوع است!'
            ]);
            exit;
        }

        // ثبت نام کاربر
        if (!$user || !isset($user['registered']) || !$user['registered']) {
            if ($user && isset($user['state']) && $user['state'] === 'awaiting_username') {
                $username = trim($text);
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
                    send_telegram_request('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => 'نام کاربری باید به انگلیسی و بدون کاراکترهای خاص باشد.'
                    ]);
                    exit;
                }
                if (in_array(strtolower($username), $FORBIDDEN_NAMES)) {
                    send_telegram_request('sendMessage', [
                        'chat_id' => $chat_id,
                        'text' => 'این نام کاربری مجاز نیست. نام دیگری انتخاب کنید.'
                    ]);
                    exit;
                }
                $users_collection->updateOne(
                    ['user_id' => $user_id],
                    ['$set' => ['username' => $username, 'registered' => true, 'state' => 'active']],
                    ['upsert' => true]
                );
                send_telegram_request('sendMessage', [
                    'chat_id' => $chat_id,
                    'text' => "نام کاربری @$username ثبت شد. حالا می‌توانید چت کنید!"
                ]);
                exit;
            }
            exit;
        }

        // ضد اسپم
        $now = new UTCDateTime(time() * 1000);
        $messages_collection->insertOne([
            'user_id' => $user_id,
            'timestamp' => $now,
            'message_id' => $message['message_id'],
            'chat_id' => $chat_id
        ]);
        $recent_messages = $messages_collection->countDocuments([
            'user_id' => $user_id,
            'timestamp' => ['$gte' => new UTCDateTime((time() - 10) * 1000)]
        ]);
        if ($recent_messages > 5) {
            $users_collection->updateOne(
                ['user_id' => $user_id],
                ['$set' => ['muted_until' => new UTCDateTime((time() + 2 * 60) * 1000)]]
            );
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'شما به دلیل اسپم به مدت ۲ دقیقه محدود شدید.'
            ]);
            exit;
        }

        if (isset($user['muted_until']) && $user['muted_until']->toDateTime()->getTimestamp() > time()) {
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'شما موقتاً محدود شده‌اید. لطفاً کمی صبر کنید.'
            ]);
            exit;
        }

        // ارسال پیام به همه کاربران فعال
        $username = $user['username'] ?? ($user_id == $ADMIN_ID ? 'ادمین' : 'ناشناس');
        $message_text = "@$username: $text";
        if (isset($message['reply_to_message'])) {
            $reply_user_id = $message['reply_to_message']['from']['id'];
            $reply_user = $users_collection->findOne(['user_id' => $reply_user_id]);
            $reply_username = $reply_user['username'] ?? 'ناشناس';
            $message_text = "@$username در پاسخ به @$reply_username: $text";
        }

        $active_users = $users_collection->find(['registered' => true, 'banned' => ['$ne' => true]]);
        foreach ($active_users as $active_user) {
            if ($active_user['user_id'] != $user_id) {
                try {
                    send_telegram_request('sendMessage', [
                        'chat_id' => $active_user['user_id'],
                        'text' => $message_text,
                        'reply_to_message_id' => isset($message['reply_to_message']) ? $message['reply_to_message']['message_id'] : null
                    ]);
                } catch (Exception $e) {
                    log_message("Error sending message to {$active_user['user_id']}: " . $e->getMessage());
                }
            }
        }
    }

    // هندلر برای دکمه‌های شیشه‌ای
    if (isset($update['callback_query'])) {
        $call = $update['callback_query'];
        $user_id = $call['from']['id'];
        $chat_id = $call['message']['chat']['id'];
        $message_id = $call['message']['message_id'];
        $data = $call['data'];
        $user = $users_collection->findOne(['user_id' => $user_id]);

        if ($data === 'confirm_start') {
            send_telegram_request('deleteMessage', [
                'chat_id' => $chat_id,
                'message_id' => $message_id
            ]);
            $keyboard = [
                ['inline_keyboard' => [[['text' => 'قوانین', 'callback_data' => 'show_rules']]]]
            ];
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'آیا قوانین و مقررات را تأیید می‌کنید؟',
                'reply_markup' => json_encode($keyboard)
            ]);
        } elseif ($data === 'show_rules') {
            $username = $user['username'] ?? 'کاربر';
            send_telegram_request('editMessageText', [
                'chat_id' => $chat_id,
                'message_id' => $message_id,
                'text' => sprintf($RULES_TEXT, $username)
            ]);
            $keyboard = [
                ['inline_keyboard' => [[['text' => 'تأیید قوانین', 'callback_data' => 'confirm_rules']]]]
            ];
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'لطفاً قوانین را تأیید کنید.',
                'reply_markup' => json_encode($keyboard)
            ]);
        } elseif ($data === 'confirm_rules') {
            send_telegram_request('deleteMessage', [
                'chat_id' => $chat_id,
                'message_id' => $message_id
            ]);
            $users_collection->updateOne(
                ['user_id' => $user_id],
                ['$set' => ['state' => 'awaiting_username']],
                ['upsert' => true]
            );
            send_telegram_request('sendMessage', [
                'chat_id' => $chat_id,
                'text' => 'لطفاً نام کاربری خود را (به انگلیسی) ارسال کنید. از اسامی مانند admin خودداری کنید.'
            ]);
        }
    }
    http_response_code(200);
    exit;
}

// تنظیم وب‌هوک در اولین اجرا
set_webhook();
