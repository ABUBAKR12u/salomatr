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

/*
 DB STRUKTURASI:
 $db = [
     'animes' => [
         '0001' => [
             'name' => 'Anime nomi',
             'parts' => [
                 1 => 'file_id_1',
                 2 => 'file_id_2',
             ]
         ],
     ],
     'channels' => [
         '-100123456789' => ['type' => 'open'],   // faqat a'zo bo'lish kifoya
         '-100987654321' => ['type' => 'closed'],  // join request kerak
     ],
     'requests' => [
         'userId' => ['-100123456789', ...]  // approved bo'lgan (open uchun ham, closed uchun ham) kanallar
     ],
     'states' => [
         'userId' => ['action' => 'awaiting_anime_name'] yoki
                     ['action' => 'awaiting_anime_video', 'code' => '0001'] yoki
                     ['action' => 'awaiting_part_code'] yoki
                     ['action' => 'awaiting_part_video', 'code' => '0001'] yoki
                     ['action' => 'awaiting_channel_id', 'type' => 'open'/'closed']
     ],
     'last_anime_num' => 0
 ]
*/

$db = [
    'animes' => [],
    'channels' => [],
    'requests' => [],
    'states' => [],
    'last_anime_num' => 0
];

if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) {
        $db = array_merge($db, $json);
    }
}

function saveDB($data) {
    file_put_contents(DB_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
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

function setState(&$db, $userId, $state) {
    $db['states'][$userId] = $state;
}

function clearState(&$db, $userId) {
    unset($db['states'][$userId]);
}

function getUserApproved($db, $userId) {
    return isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
}

function markApproved(&$db, $userId, $chatId) {
    if (!isset($db['requests'][$userId])) {
        $db['requests'][$userId] = [];
    }
    if (!in_array($chatId, $db['requests'][$userId])) {
        $db['requests'][$userId][] = $chatId;
    }
}

// Foydalanuvchi barcha kanallarga (ochiq + yopiq) a'zo/approved bo'lganini tekshiradi.
// Ochiq kanallar uchun getChatMember orqali real vaqtda tekshiradi.
function checkAllChannels($db, $userId) {
    $missing = [];
    if (empty($db['channels'])) {
        return $missing;
    }
    $approved = getUserApproved($db, $userId);

    foreach ($db['channels'] as $chId => $info) {
        $chIdStr = (string)$chId;
        $type = isset($info['type']) ? $info['type'] : 'closed';

        if ($type === 'open') {
            $res = bot('getChatMember', [
                'chat_id' => $chIdStr,
                'user_id' => $userId
            ]);
            $status = isset($res['result']['status']) ? $res['result']['status'] : 'left';
            if (!in_array($status, ['member', 'administrator', 'creator'])) {
                $missing[$chIdStr] = $info;
            }
        } else {
            if (!in_array($chIdStr, $approved)) {
                $missing[$chIdStr] = $info;
            }
        }
    }
    return $missing;
}

function sendChannelsPrompt($chatId, $missing) {
    $buttons = [];
    $i = 1;
    foreach ($missing as $chId => $info) {
        $type = isset($info['type']) ? $info['type'] : 'closed';
        if ($type === 'open') {
            $chatInfo = bot('getChat', ['chat_id' => $chId]);
            $inviteLink = isset($chatInfo['result']['invite_link']) ? $chatInfo['result']['invite_link'] : null;
            if (!$inviteLink) {
                $linkRes = bot('createChatInviteLink', ['chat_id' => $chId]);
                $inviteLink = isset($linkRes['result']['invite_link']) ? $linkRes['result']['invite_link'] : null;
            }
            if ($inviteLink) {
                $buttons[] = [['text' => "📢 Kanal #$i ga a'zo bo'lish", 'url' => $inviteLink]];
            }
        } else {
            $linkRes = bot('createChatInviteLink', [
                'chat_id' => $chId,
                'creates_join_request' => true
            ]);
            if (isset($linkRes['result']['invite_link'])) {
                $buttons[] = [['text' => "📢 Kanal #$i ga so'rov yuborish", 'url' => $linkRes['result']['invite_link']]];
            }
        }
        $i++;
    }
    $buttons[] = [['text' => "🔄 A'zolikni tekshirish", 'callback_data' => 'check_request']];

    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "🛑 Botdan foydalanish uchun quyidagi kanal(lar)ga a'zo bo'ling / so'rov yuboring:",
        'reply_markup' => json_encode(['inline_keyboard' => $buttons])
    ]);
}

function adminMainMenu($chatId) {
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👑 Admin panelga xush kelibsiz!",
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [['text' => '🎬 Anime qo\'shish', 'callback_data' => 'admin_add_anime']],
                [['text' => '➕ Anime qismini qo\'shish', 'callback_data' => 'admin_add_part']],
                [['text' => '📢 Ochiq kanal qo\'shish', 'callback_data' => 'admin_add_open']],
                [['text' => '🔒 Yopiq kanal qo\'shish', 'callback_data' => 'admin_add_closed']],
                [['text' => '📋 Kanallar ro\'yxati', 'callback_data' => 'admin_list_channels']],
            ]
        ])
    ]);
}

function partsKeyboard($code, $parts, $currentPart = null) {
    $buttons = [];
    $row = [];
    $partNums = array_keys($parts);
    sort($partNums, SORT_NUMERIC);
    foreach ($partNums as $num) {
        $label = ($currentPart == $num) ? "▶️ $num-qism" : "$num-qism";
        $row[] = ['text' => $label, 'callback_data' => "part_{$code}_{$num}"];
        if (count($row) == 4) {
            $buttons[] = $row;
            $row = [];
        }
    }
    if (!empty($row)) {
        $buttons[] = $row;
    }
    return $buttons;
}

function sendAnimePart($chatId, $code, $anime, $partNum) {
    if (!isset($anime['parts'][$partNum])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Bu qism topilmadi."]);
        return;
    }
    $fileId = $anime['parts'][$partNum];
    bot('sendVideo', [
        'chat_id' => $chatId,
        'video' => $fileId,
        'caption' => "🎬 " . $anime['name'] . "\n📌 Kod: " . $code . " | " . $partNum . "-qism",
        'reply_markup' => json_encode([
            'inline_keyboard' => partsKeyboard($code, $anime['parts'], $partNum)
        ])
    ]);
}

// ============ CHAT JOIN REQUEST ============
if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];

    if (isset($db['channels'][$chatId])) {
        markApproved($db, $userId, $chatId);
        saveDB($db);
    }
    exit;
}

// ============ CALLBACK QUERY ============
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];

    // ---- Foydalanuvchi: a'zolikni tekshirish ----
    if ($data == 'check_request') {
        $missing = checkAllChannels($db, $userId);

        if (empty($missing)) {
            foreach ($db['channels'] as $chId => $info) {
                markApproved($db, $userId, (string)$chId);
            }
            saveDB($db);
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
                'text' => "Siz hali barcha shartlarni bajarmadingiz! ❌",
                'show_alert' => true
            ]);
        }
        exit;
    }

    // ---- Foydalanuvchi: anime qismini tanlash ----
    if (strpos($data, 'part_') === 0) {
        $parts = explode('_', $data);
        $code = $parts[1];
        $partNum = (int)$parts[2];

        if (isset($db['animes'][$code])) {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
            sendAnimePart($chatId, $code, $db['animes'][$code], $partNum);
        } else {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Topilmadi', 'show_alert' => true]);
        }
        exit;
    }

    // ---- ADMIN callbacklar ----
    if ($userId == ADMIN_ID) {

        if ($data == 'admin_add_anime') {
            setState($db, $userId, ['action' => 'awaiting_anime_name']);
            saveDB($db);
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✏️ Yangi anime nomini yuboring:"
            ]);
            exit;
        }

        if ($data == 'admin_add_part') {
            if (empty($db['animes'])) {
                bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Avval anime qo\'shing.', 'show_alert' => true]);
                exit;
            }
            setState($db, $userId, ['action' => 'awaiting_part_code']);
            saveDB($db);
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);

            $list = "📋 Qism qo'shmoqchi bo'lgan anime kodini yuboring:\n\n";
            foreach ($db['animes'] as $code => $a) {
                $partsCount = isset($a['parts']) ? count($a['parts']) : 0;
                $list .= "🔹 $code — {$a['name']} ({$partsCount} qism)\n";
            }
            bot('sendMessage', ['chat_id' => $chatId, 'text' => $list]);
            exit;
        }

        if ($data == 'admin_add_open' || $data == 'admin_add_closed') {
            $type = ($data == 'admin_add_open') ? 'open' : 'closed';
            setState($db, $userId, ['action' => 'awaiting_channel_id', 'type' => $type]);
            saveDB($db);
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
            $label = $type == 'open' ? 'Ochiq (a\'zo bo\'lish kifoya)' : 'Yopiq (so\'rov kerak)';
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "🔗 $label kanal ID sini yuboring (Masalan: -100123456789):"
            ]);
            exit;
        }

        if ($data == 'admin_list_channels') {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
            if (empty($db['channels'])) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "📭 Hozircha kanallar yo'q."]);
                exit;
            }
            $buttons = [];
            foreach ($db['channels'] as $chId => $info) {
                $type = isset($info['type']) ? $info['type'] : 'closed';
                $emoji = $type == 'open' ? '📢' : '🔒';
                $chatInfo = bot('getChat', ['chat_id' => $chId]);
                $title = isset($chatInfo['result']['title']) ? $chatInfo['result']['title'] : $chId;
                $buttons[] = [
                    ['text' => "$emoji $title", 'callback_data' => 'noop'],
                    ['text' => "🗑 O'chirish", 'callback_data' => 'del_channel_' . $chId]
                ];
            }
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "📋 Kanallar ro'yxati:",
                'reply_markup' => json_encode(['inline_keyboard' => $buttons])
            ]);
            exit;
        }

        if (strpos($data, 'del_channel_') === 0) {
            $chId = substr($data, strlen('del_channel_'));
            if (isset($db['channels'][$chId])) {
                unset($db['channels'][$chId]);
                saveDB($db);
                bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "O'chirildi ✅"]);
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "🗑 Kanal o'chirildi: $chId"]);
            } else {
                bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => 'Topilmadi', 'show_alert' => true]);
            }
            exit;
        }

        if ($data == 'noop') {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
            exit;
        }
    }

    bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
    exit;
}

// ============ MESSAGE ============
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // ================= ODDIY FOYDALANUVCHI =================
    if ($userId != ADMIN_ID) {

        $missing = checkAllChannels($db, $userId);
        if (!empty($missing)) {
            sendChannelsPrompt($chatId, $missing);
            exit;
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
                $anime = $db['animes'][$text];
                if (!empty($anime['parts'])) {
                    sendAnimePart($chatId, $text, $anime, 1);
                } else {
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "⏳ \"" . $anime['name'] . "\" tez orada qo'shiladi, hali qismlari yo'q."
                    ]);
                }
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "🤷‍♂️ Afsuski, bu kodga tegishli anime topilmadi."
                ]);
            }
            exit;
        }
        exit;
    }

    // ================= ADMIN =================
    if ($userId == ADMIN_ID) {

        $state = isset($db['states'][$userId]) ? $db['states'][$userId] : null;

        if ($text == '/start') {
            clearState($db, $userId);
            saveDB($db);
            adminMainMenu($chatId);
            exit;
        }

        // ---- Kanal ID kutilmoqda ----
        if ($state && $state['action'] == 'awaiting_channel_id') {
            if (strpos($text, '-') === 0 && is_numeric($text)) {
                if (!isset($db['channels'][$text])) {
                    $db['channels'][$text] = ['type' => $state['type']];
                    clearState($db, $userId);
                    saveDB($db);
                    $label = $state['type'] == 'open' ? 'Ochiq' : 'Yopiq';
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "✅ $label kanal muvaffaqiyatli qo'shildi ID: " . $text
                    ]);
                } else {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "⚠️ Bu kanal allaqachon qo'shilgan."]);
                }
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Noto'g'ri format. Masalan: -100123456789"]);
            }
            exit;
        }

        // ---- Anime nomi kutilmoqda ----
        if ($state && $state['action'] == 'awaiting_anime_name') {
            if ($text !== '') {
                $db['last_anime_num']++;
                $code = str_pad($db['last_anime_num'], 4, '0', STR_PAD_LEFT);
                $db['animes'][$code] = ['name' => $text, 'parts' => []];
                setState($db, $userId, ['action' => 'awaiting_anime_video', 'code' => $code]);
                saveDB($db);
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Anime qo'shildi!\n📌 Kod: $code\n🎬 Nom: $text\n\n📥 Endi 1-qism videosini yuboring:"
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Anime nomini matn ko'rinishida yuboring."]);
            }
            exit;
        }

        // ---- Anime uchun 1-qism video kutilmoqda ----
        if ($state && $state['action'] == 'awaiting_anime_video' && isset($message['video'])) {
            $code = $state['code'];
            $fileId = $message['video']['file_id'];
            $db['animes'][$code]['parts'][1] = $fileId;
            clearState($db, $userId);
            saveDB($db);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "💾 1-qism saqlandi!\n📌 Kod: $code\n\nKeyingi qismlarni qo'shish uchun \"➕ Anime qismini qo'shish\" tugmasidan foydalaning."
            ]);
            exit;
        }

        // ---- Qism qo'shish uchun kod kutilmoqda ----
        if ($state && $state['action'] == 'awaiting_part_code') {
            if (isset($db['animes'][$text])) {
                setState($db, $userId, ['action' => 'awaiting_part_video', 'code' => $text]);
                saveDB($db);
                $nextPart = count($db['animes'][$text]['parts']) + 1;
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "📥 \"" . $db['animes'][$text]['name'] . "\" uchun {$nextPart}-qism videosini yuboring:"
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Bunday kod topilmadi. Qaytadan urinib ko'ring."]);
            }
            exit;
        }

        // ---- Qism video kutilmoqda ----
        if ($state && $state['action'] == 'awaiting_part_video' && isset($message['video'])) {
            $code = $state['code'];
            $fileId = $message['video']['file_id'];
            $nextPart = count($db['animes'][$code]['parts']) + 1;
            $db['animes'][$code]['parts'][$nextPart] = $fileId;
            clearState($db, $userId);
            saveDB($db);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "💾 {$nextPart}-qism saqlandi!\n📌 Kod: $code"
            ]);
            exit;
        }

        // Hech qanday state bo'lmasa — menyuni ko'rsatish
        adminMainMenu($chatId);
        exit;
    }
}
