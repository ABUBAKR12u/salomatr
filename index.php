<?php

error_reporting(0);
ini_set('display_errors', 0);

define('TOKEN', '8556626236:AAHraU5HfOIKOZUDJOAc3i6rV5SYuW3vTf4');
define('ADMIN_ID', 8105737095);
define('DB_FILE', 'database.json');

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) {
    exit;
}

$db = ['animes' => [], 'channels' => [], 'requests' => []];
if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) {
        $db = array_merge($db, $json);
    }
}

function saveDB($data) {
    file_put_contents(DB_FILE, json_encode($data, JSON_PRETTY_PRINT));
}

function bot($method, $data = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot" . TOKEN . "/" . $method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];
    
    $cleanChannels = array_map('strval', $db['channels']);
    if (in_array($chatId, $cleanChannels)) {
        if (!isset($db['requests'][$userId])) {
            $db['requests'][$userId] = [];
        }
        if (!in_array($chatId, $db['requests'][$userId])) {
            $db['requests'][$userId][] = $chatId;
            saveDB($db);
        }
    }
    exit;
}

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];

    if ($data == 'check_request') {
        $allApproved = true;
        if (!empty($db['channels'])) {
            foreach ($db['channels'] as $chId) {
                $chIdStr = (string)$chId;
                $userRequests = isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
                if (!in_array($chIdStr, $userRequests)) {
                    $allApproved = false;
                    break;
                }
            }
        }

        if ($allApproved) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "Muvaffaqiyatli tasdiqlandi! ✅",
                'show_alert' => false
            ]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Xush kelibsiz! 🎉 Endi anime kodini yuborishingiz mumkin 💬"
            ]);
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "Siz hali so'rov yubormadingiz! ❌",
                'show_alert' => true
            ]);
        }
    }
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    if ($userId != ADMIN_ID) {
        if (!empty($db['channels'])) {
            foreach ($db['channels'] as $chId) {
                $chIdStr = (string)$chId;
                $userRequests = isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
                
                if (!in_array($chIdStr, $userRequests)) {
                    $linkRes = bot('createChatInviteLink', [
                        'chat_id' => $chIdStr,
                        'creates_join_request' => true
                    ]);
                    
                    if (isset($linkRes['result']['invite_link'])) {
                        $inviteLink = $linkRes['result']['invite_link'];
                        bot('sendMessage', [
                            'chat_id' => $chatId,
                            'text' => "🛑 Botdan foydalanish uchun quyidagi yopiq kanalga qo'shilish so'rovini yuboring:",
                            'reply_markup' => json_encode([
                                'inline_keyboard' => [
                                    [['text' => "📢 Kanalga so'rov yuborish", 'url' => $inviteLink]],
                                    [['text' => "🔄 A'zolikni tekshirish", 'callback_data' => 'check_request']]
                                ]
                            ])
                        ]);
                        exit;
                    }
                }
            }
        }

        if ($text == '/start') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👋 Salom! Anime kodini yuboring 🔍"
            ]);
            exit;
        }

        if ($text !== '') {
            if (isset($db['animes'][$text])) {
                $fileId = $db['animes'][$text];
                bot('sendVideo', [
                    'chat_id' => $chatId,
                    'video' => $fileId,
                    'caption' => "🎬 Kod: " . $text
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "🤷‍♂️ Afsuski, bu kodga tegishli anime topilmadi."
                ]);
            }
            exit;
        }
    }

    if ($userId == ADMIN_ID) {
        if ($text == '/start') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👑 Admin panelga xush kelibsiz!\n\n🔗 Kanal qo'shish uchun faqat ID yuboring (Masalan: -100123456789)\n📥 Anime qo'shish uchun videoga caption yozib yuboring."
            ]);
            exit;
        }

        if (strpos($text, '-') === 0 && is_numeric($text)) {
            if (!in_array($text, $db['channels'])) {
                $db['channels'][] = $text;
                saveDB($db);
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Yopiq kanal muvaffaqiyatli qo'shildi ID: " . $text
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "⚠️ Bu kanal allaqachon qo'shilgan."
                ]);
            }
            exit;
        }

        if (isset($message['video'])) {
            $fileId = $message['video']['file_id'];
            $caption = isset($message['caption']) ? trim($message['caption']) : '';
            
            if ($caption !== '') {
                $db['animes'][$caption] = $fileId;
                saveDB($db);
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "💾 Anime muvaffaqiyatli saqlandi! Kod: " . $caption
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Xatolik: Videoga caption (tavsif) sifatida anime kodini yozib yuboring."
                ]);
            }
            exit;
        }
    }
}
