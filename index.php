<?php

error_reporting(0);
ini_set('display_errors', 0);

define('TOKEN', '8556626236:AAHraU5HfOIKOZUDJOAc3i6rV5SYuW3vTf4');
define('ADMIN_ID', 8105737095);
define('DB_FILE', 'database.json');

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$db = ['animes' => [], 'channels' => [], 'requests' => [], 'states' => []];
if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) $db = array_merge($db, $json);
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

function checkSubscription($userId, $db) {
    if (empty($db['channels'])) return true;
    foreach ($db['channels'] as $chId => $type) {
        if ($type === 'yopiq') {
            $userRequests = isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
            if (!in_array((string)$chId, $userRequests)) return false;
        } else {
            $res = bot('getChatMember', ['chat_id' => $chId, 'user_id' => $userId]);
            $status = isset($res['result']['status']) ? $res['result']['status'] : '';
            if (!in_array($status, ['creator', 'administrator', 'member'])) return false;
        }
    }
    return true;
}

function getSubscriptionKeyboard($db, $userId) {
    $keyboard = [];
    foreach ($db['channels'] as $chId => $type) {
        if ($type === 'yopiq') {
            $linkRes = bot('createChatInviteLink', ['chat_id' => $chId, 'creates_join_request' => true]);
            $link = isset($linkRes['result']['invite_link']) ? $linkRes['result']['invite_link'] : '';
        } else {
            $chatRes = bot('getChat', ['chat_id' => $chId]);
            $link = isset($chatRes['result']['invite_link']) ? $chatRes['result']['invite_link'] : '';
            if (empty($link) && isset($chatRes['result']['username'])) {
                $link = "https://t.me/" . $chatRes['result']['username'];
            }
        }
        if ($link) {
            $keyboard[] = [['text' => "📢 Kanalga qo'shilish", 'url' => $link]];
        }
    }
    $keyboard[] = [['text' => "🔄 A'zolikni tekshirish", 'callback_data' => 'check_sub']];
    return json_encode(['inline_keyboard' => $keyboard]);
}

function getAdminKeyboard() {
    return json_encode([
        'inline_keyboard' => [
            [['text' => "➕ Kanal qo'shish", 'callback_data' => 'adm_add_ch'], ['text' => "🗑 Kanallar ro'yxati", 'callback_data' => 'adm_list_ch']],
            [['text' => "🍿 Yangi Anime yaratish", 'callback_data' => 'adm_new_anime'], ['text' => "➕ Animega qism qo'shish", 'callback_data' => 'adm_add_part']]
        ]
    ]);
}

if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];
    if (isset($db['channels'][$chatId]) && $db['channels'][$chatId] === 'yopiq') {
        if (!isset($db['requests'][$userId])) $db['requests'][$userId] = [];
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
    $messageId = $callback['message']['message_id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];

    if ($data === 'check_sub') {
        if (checkSubscription($userId, $db)) {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "Muvaffaqiyatli tasdiqlandi! ✅"]);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "👋 Xush kelibsiz! Anime kodini yuborishingiz mumkin 🔍"]);
        } else {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "Hamma kanallarga a'zo bo'lmadingiz! ❌", 'show_alert' => true]);
        }
        exit;
    }

    if (strpos($data, 'view_part_') === 0) {
        $parts = explode('_', $data);
        $animeId = $parts[2];
        $partNum = $parts[3];
        if (isset($db['animes'][$animeId]['parts'][$partNum])) {
            $fileId = $db['animes'][$animeId]['parts'][$partNum];
            $animeName = $db['animes'][$animeId]['name'];
            
            $buttons = [];
            foreach ($db['animes'][$animeId]['parts'] as $pNum => $fId) {
                $buttons[] = ['text' => "$pNum-qism " . ($pNum == $partNum ? "•" : ""), 'callback_data' => "view_part_{$animeId}_{$pNum}"];
            }
            $keyboard = array_chunk($buttons, 4);
            
            bot('sendVideo', [
                'chat_id' => $chatId,
                'video' => $fileId,
                'caption' => "🍿 Anime: $animeName\n🔢 Qism: $partNum\n🆔 Kod: $animeId",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
        }
        exit;
    }

    if ($userId == ADMIN_ID) {
        if ($data === 'adm_add_ch') {
            $db['states'][$userId] = 'wait_ch_id';
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "Kanal ID raqamini yuboring (Masalan: -100123456789):"]);
        }
        elseif ($data === 'adm_list_ch') {
            if (empty($db['channels'])) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Kanallar qo'shilmagan.", 'reply_markup' => getAdminKeyboard()]);
            } else {
                $text = "📢 Kanallar ro'yxati:\n\n";
                $buttons = [];
                foreach ($db['channels'] as $id => $type) {
                    $text .= "🆔 `$id` [Type: $type]\n";
                    $buttons[] = [['text' => "🗑 O'chirish: $id", 'callback_data' => "del_ch_$id"]];
                }
                $buttons[] = [['text' => "🔙 Orqaga", 'callback_data' => 'adm_main']];
                bot('sendMessage', ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown', 'reply_markup' => json_encode(['inline_keyboard' => $buttons])]);
            }
        }
        elseif (strpos($data, 'del_ch_') === 0) {
            $targetCh = substr($data, 7);
            if (isset($db['channels'][$targetCh])) {
                unset($db['channels'][$targetCh]);
                saveDB($db);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Kanal muvaffaqiyatli o'chirildi!", 'reply_markup' => getAdminKeyboard()]);
            }
        }
        elseif ($data === 'adm_new_anime') {
            $db['states'][$userId] = 'wait_anime_name';
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "🍿 Yangi anime nomini kiriting:"]);
        }
        elseif ($data === 'adm_add_part') {
            $db['states'][$userId] = 'wait_part_code';
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "Qism qo'shmoqchi bo'lgan anime kodini yuboring:"]);
        }
        elseif ($data === 'adm_main') {
            unset($db['states'][$userId]);
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "👑 Admin boshqaruv paneli:", 'reply_markup' => getAdminKeyboard()]);
        }
        bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
    }
    exit;
}

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';
    $state = isset($db['states'][$userId]) ? $db['states'][$userId] : '';

    if ($userId != ADMIN_ID) {
        if (!checkSubscription($userId, $db)) {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "🛑 Botdan foydalanish uchun quyidagi kanallarga a'zo bo'ling yoki so'rov yuboring:",
                'reply_markup' => getSubscriptionKeyboard($db, $userId)
            ]);
            exit;
        }

        if ($text === '/start') {
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "👋 Salom! Anime kodini yuboring 🔍"]);
            exit;
        }

        if ($text !== '') {
            if (isset($db['animes'][$text])) {
                $anime = $db['animes'][$text];
                if (isset($anime['parts'][1])) {
                    $fileId = $anime['parts'][1];
                    $buttons = [];
                    foreach ($anime['parts'] as $pNum => $fId) {
                        $buttons[] = ['text' => "$pNum-qism" . ($pNum == 1 ? " •" : ""), 'callback_data' => "view_part_{$text}_{$pNum}"];
                    }
                    $keyboard = array_chunk($buttons, 4);
                    
                    bot('sendVideo', [
                        'chat_id' => $chatId,
                        'video' => $fileId,
                        'caption' => "🍿 Anime: " . $anime['name'] . "\n🔢 Qism: 1\n🆔 Kod: " . $text,
                        'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                    ]);
                }
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "🤷‍♂️ Afsuski, bu kodga tegishli anime topilmadi."]);
            }
            exit;
        }
    }

    if ($userId == ADMIN_ID) {
        if ($text === '/start') {
            unset($db['states'][$userId]);
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "👑 Admin panelga xush kelibsiz! Kerakli amalni tanlang:", 'reply_markup' => getAdminKeyboard()]);
            exit;
        }

        if ($state === 'wait_ch_id') {
            if (strpos($text, '-') === 0 && is_numeric($text)) {
                $db['temp_ch_id'] = $text;
                $db['states'][$userId] = 'wait_ch_type';
                saveDB($db);
                
                $typeKb = json_encode([
                    'inline_keyboard' => [
                        [['text' => "🔓 Ochiq (Public)", 'callback_data' => "set_type_ochiq"], ['text' => "🔒 Yopiq (Private/Request)", 'callback_data' => "set_type_yopiq"]]
                    ]
                ]);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Kanal turini tanlang:", 'reply_markup' => $typeKb]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Xato ID format. Qayta urinib ko'ring:", 'reply_markup' => getAdminKeyboard()]);
            }
            exit;
        }

        if (isset($update['callback_query']) || ($state === 'wait_ch_type' && strpos($text, 'set_type_') === 0)) {
            // Handled type logic inside regular post if text acts as triggers or callback
        }

        if ($state === 'wait_anime_name' && $text !== '') {
            $newCode = rand(1000, 9999);
            while (isset($db['animes'][$newCode])) {
                $newCode = rand(1000, 9999);
            }
            $db['animes'][$newCode] = ['name' => $text, 'parts' => []];
            $db['states'][$userId] = "wait_anime_video_" . $newCode;
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "🍿 Anime: *$text* muvaffaqiyatli ochildi!\n🆔 Avtomatik Kod: `$newCode` \n\nEndi ushbu animening *1-qism* videosini yuboring (caption shart emas):", 'parse_mode' => 'Markdown']);
            exit;
        }

        if (strpos($state, 'wait_anime_video_') === 0) {
            $animeCode = substr($state, 17);
            if (isset($message['video'])) {
                $fileId = $message['video']['file_id'];
                $db['animes'][$animeCode]['parts'][1] = $fileId;
                unset($db['states'][$userId]);
                saveDB($db);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ Anime muvaffaqiyatli saqlandi!\n🍿 Nomi: " . $db['animes'][$animeCode]['name'] . "\n🆔 Kod: `$animeCode`", 'parse_mode' => 'Markdown', 'reply_markup' => getAdminKeyboard()]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Iltimos, video formatida yuboring!"]);
            }
            exit;
        }

        if ($state === 'wait_part_code' && $text !== '') {
            if (isset($db['animes'][$text])) {
                $nextPart = count($db['animes'][$text]['parts']) + 1;
                $db['states'][$userId] = "wait_video_for_{$text}_{$nextPart}";
                saveDB($db);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "🍿 Anime: *" . $db['animes'][$text]['name'] . "*\n🔢 Qo'shilayotgan qism: *$nextPart*\n\nUshbu qism videosini yuklang:", 'parse_mode' => 'Markdown']);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Bunday kodli anime topilmadi! Qayta kiriting:", 'reply_markup' => getAdminKeyboard()]);
            }
            exit;
        }

        if (strpos($state, 'wait_video_for_') === 0) {
            $dataParts = explode('_', substr($state, 15));
            $animeCode = $dataParts[0];
            $partNum = $dataParts[1];
            if (isset($message['video'])) {
                $fileId = $message['video']['file_id'];
                $db['animes'][$animeCode]['parts'][$partNum] = $fileId;
                unset($db['states'][$userId]);
                saveDB($db);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ *" . $db['animes'][$animeCode]['name'] . "* animesiga *$partNum-qism* muvaffaqiyatli qo'shildi!", 'parse_mode' => 'Markdown', 'reply_markup' => getAdminKeyboard()]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "Iltimos, video yuboring!"]);
            }
            exit;
        }
    }
}

// Qo'shimcha callback_query holati - Kanal Turini Tanlash mantiqi uchun
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];
    $chatId = $callback['message']['chat']['id'];
    
    if ($userId == ADMIN_ID && strpos($data, 'set_type_') === 0) {
        $type = substr($data, 9);
        $tempId = $db['temp_ch_id'];
        if ($tempId) {
            $db['channels'][$tempId] = $type;
            unset($db['temp_ch_id']);
            unset($db['states'][$userId]);
            saveDB($db);
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ Kanal muvaffaqiyatli saqlandi!\n🆔 ID: $tempId\n🌐 Turi: $type", 'reply_markup' => getAdminKeyboard()]);
        }
    }
}
