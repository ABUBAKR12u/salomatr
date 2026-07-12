<?php

error_reporting(0);
ini_set('display_errors', 0);

define('TOKEN', '8556626236:AAHraU5HfOIKOZUDJOAc3i6rV5SYuW3vTf4');
define('ADMIN_ID', 8105737095);
define('DB_FILE', 'database.json');

$content = file_get_contents("php://input");
$update = json_decode($content, true);
if (!$update) exit;

// Ma'lumotlar bazasi tuzilmasi
$db = [
    'animes' => [],      // [kod => ['name' => '...', 'episodes' => [1 => file_id, 2 => file_id, ...]]]
    'channels' => [],    // [ ['id' => '-100...', 'type' => 'open'/'private'] ]
    'requests' => [],    // [user_id => [channel_id, ...]]
    'last_anime_code' => 0
];

if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) $db = array_merge($db, $json);
}

function saveDB($data) {
    file_put_contents(DB_FILE, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function bot($method, $data = []) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, "https://api.telegram.org/bot".TOKEN."/".$method);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// Kanaldan a'zo bo'lish so'rovi (faqat yopiq kanallar uchun)
if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];

    foreach ($db['channels'] as $channel) {
        if ($channel['id'] == $chatId && $channel['type'] == 'private') {
            if (!isset($db['requests'][$userId])) $db['requests'][$userId] = [];
            if (!in_array($chatId, $db['requests'][$userId])) {
                $db['requests'][$userId][] = $chatId;
                saveDB($db);
            }
            break;
        }
    }
    exit;
}

// Callback query (inline tugmalar)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];
    $msgId = $callback['message']['message_id'];

    // Admin panel bosh menyusi
    if ($data == 'admin_panel' && $userId == ADMIN_ID) {
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "👑 Admin panel\n\nKerakli amalni tanlang:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "➕ Kanal qo'shish", 'callback_data' => 'add_channel']],
                    [['text' => "📋 Kanallar ro'yxati", 'callback_data' => 'list_channels']],
                    [['text' => "🎬 Anime qo'shish", 'callback_data' => 'add_anime']],
                    [['text' => "🔍 Anime qidirish", 'callback_data' => 'search_anime_admin']],
                    [['text' => "📊 Statistika", 'callback_data' => 'stats']]
                ]
            ])
        ]);
        exit;
    }

    // Kanal qo'shish menyusi
    if ($data == 'add_channel' && $userId == ADMIN_ID) {
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "Kanal turini tanlang:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "📢 Ochiq kanal", 'callback_data' => 'add_open_channel']],
                    [['text' => "🔒 Maxsus kanal", 'callback_data' => 'add_private_channel']],
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }

    // Kanal qo'shish uchun xabar so'rash
    if (($data == 'add_open_channel' || $data == 'add_private_channel') && $userId == ADMIN_ID) {
        $type = $data == 'add_open_channel' ? 'open' : 'private';
        // Vaqtinchalik holatni saqlash uchun fayl
        file_put_contents('admin_state_'.$userId.'.txt', $type);
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "Kanal ID yoki username yuboring:\nMasalan: @username yoki -100123456789",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Bekor qilish", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }

    // Kanallar ro'yxati
    if ($data == 'list_channels' && $userId == ADMIN_ID) {
        if (empty($db['channels'])) {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "Hozircha kanallar mavjud emas", 'show_alert' => true]);
        } else {
            $keyboard = [];
            foreach ($db['channels'] as $index => $channel) {
                $chInfo = getChannelInfo($channel['id']);
                $type = $channel['type'] == 'open' ? '🌐' : '🔒';
                $keyboard[] = [['text' => $type." ".($chInfo ? $chInfo : $channel['id']), 'callback_data' => 'channel_'.$index]];
            }
            $keyboard[] = [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']];
            
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "📋 Kanallar ro'yxati (" . count($db['channels']) . " ta):",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
        exit;
    }

    // Kanalni tanlaganda ko'rsatiladigan amallar
    if (strpos($data, 'channel_') === 0 && $userId == ADMIN_ID) {
        $index = (int)str_replace('channel_', '', $data);
        if (isset($db['channels'][$index])) {
            $channel = $db['channels'][$index];
            $chInfo = getChannelInfo($channel['id']);
            $type = $channel['type'] == 'open' ? 'Ochiq' : 'Maxsus';
            
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "Kanal: " . ($chInfo ? $chInfo : $channel['id']) . "\nTuri: " . $type . "\nID: " . $channel['id'],
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "🗑 Kanalni o'chirish", 'callback_data' => 'delete_channel_'.$index]],
                        [['text' => "🔙 Kanallar ro'yxatiga", 'callback_data' => 'list_channels']]
                    ]
                ])
            ]);
        }
        exit;
    }

    // Kanalni o'chirish
    if (strpos($data, 'delete_channel_') === 0 && $userId == ADMIN_ID) {
        $index = (int)str_replace('delete_channel_', '', $data);
        if (isset($db['channels'][$index])) {
            $deleted = $db['channels'][$index];
            array_splice($db['channels'], $index, 1);
            saveDB($db);
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "Kanal o'chirildi", 'show_alert' => true]);
            // Orqaga qaytish
            $keyboard = [];
            foreach ($db['channels'] as $i => $ch) {
                $chInfo = getChannelInfo($ch['id']);
                $keyboard[] = [['text' => ($ch['type']=='open'?'🌐':'🔒')." ".($chInfo ? $chInfo : $ch['id']), 'callback_data' => 'channel_'.$i]];
            }
            $keyboard[] = [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']];
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "📋 Kanallar ro'yxati (" . count($db['channels']) . " ta):",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
        exit;
    }

    // Anime qidirish (admin)
    if ($data == 'search_anime_admin' && $userId == ADMIN_ID) {
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "🔍 Izlash uchun anime nomi yoki kodini yuboring:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        file_put_contents('admin_state_'.$userId.'.txt', 'search_anime');
        exit;
    }

    // Statistika
    if ($data == 'stats' && $userId == ADMIN_ID) {
        $totalAnimes = count($db['animes']);
        $totalChannels = count($db['channels']);
        $totalRequests = 0;
        foreach ($db['requests'] as $uid => $reqs) {
            $totalRequests += count($reqs);
        }
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📊 Statistika:\n\n🎬 Animelar: ".$totalAnimes." ta\n📢 Kanallar: ".$totalChannels." ta\n👥 A'zolik so'rovlari: ".$totalRequests." ta",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }

    // Foydalanuvchi tomonida - a'zolikni tekshirish
    if ($data == 'check_membership') {
        $notJoined = [];
        $allChannels = [];
        
        foreach ($db['channels'] as $channel) {
            $allChannels[] = $channel['id'];
            if ($channel['type'] == 'private') {
                $userReqs = isset($db['requests'][$userId]) ? $db['requests'][$userId] : [];
                if (!in_array($channel['id'], $userReqs)) {
                    $notJoined[] = $channel;
                }
            } else {
                // Ochiq kanal - a'zolikni tekshirish
                $memberStatus = bot('getChatMember', [
                    'chat_id' => $channel['id'],
                    'user_id' => $userId
                ]);
                if (!$memberStatus['ok'] || !in_array($memberStatus['result']['status'], ['member', 'administrator', 'creator'])) {
                    $notJoined[] = $channel;
                }
            }
        }
        
        if (empty($notJoined)) {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "✅ Barcha kanallarga a'zosiz!", 'show_alert' => false]);
            bot('deleteMessage', ['chat_id' => $chatId, 'message_id' => $msgId]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ Xush kelibsiz! Anime kodini yuboring 🎬"
            ]);
        } else {
            $keyboard = [];
            foreach ($notJoined as $ch) {
                $chInfo = getChannelInfo($ch['id']);
                $label = $ch['type'] == 'open' ? "🌐 Qo'shilish" : "🔒 So'rov yuborish";
                $callback = 'join_'.$ch['type'].'_'.$ch['id'];
                $keyboard[] = [['text' => ($chInfo ? $chInfo : $ch['id'])." - ".$label, 'callback_data' => $callback]];
            }
            $keyboard[] = [['text' => "🔄 Tekshirish", 'callback_data' => 'check_membership']];
            
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "⚠️ Botdan foydalanish uchun quyidagi kanallarga a'zo bo'lishingiz kerak:",
                'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
            ]);
        }
        exit;
    }

    // Kanalga qo'shilish tugmasi bosilganda
    if (strpos($data, 'join_open_') === 0) {
        $chId = str_replace('join_open_', '', $data);
        $link = getChannelInviteLink($chId);
        bot('answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => "Kanal ochilmoqda...",
            'show_alert' => false
        ]);
        if ($link) {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👇 Kanalga qo'shilish uchun bosing:",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "📢 Kanalga o'tish", 'url' => $link]],
                        [['text' => "🔄 Tekshirish", 'callback_data' => 'check_membership']]
                    ]
                ])
            ]);
        }
        exit;
    }

    if (strpos($data, 'join_private_') === 0) {
        $chId = str_replace('join_private_', '', $data);
        $linkRes = bot('createChatInviteLink', [
            'chat_id' => $chId,
            'creates_join_request' => true
        ]);
        if (isset($linkRes['result']['invite_link'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "So'rov yuborish havolasi...",
                'show_alert' => false
            ]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👇 So'rov yuborish uchun bosing:",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "📤 So'rov yuborish", 'url' => $linkRes['result']['invite_link']]],
                        [['text' => "🔄 Tekshirish", 'callback_data' => 'check_membership']]
                    ]
                ])
            ]);
        }
        exit;
    }

    exit;
}

// Oddiy xabarlar
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // Admin holatini tekshirish
    $adminStateFile = 'admin_state_'.$userId.'.txt';
    $adminState = file_exists($adminStateFile) ? trim(file_get_contents($adminStateFile)) : '';

    if ($userId == ADMIN_ID && $adminState == 'open' || $adminState == 'private') {
        // Kanal qo'shish
        if (!empty($text) && ($text[0] == '@' || strpos($text, '-100') === 0 || is_numeric($text))) {
            $chId = $text;
            if (is_numeric($text) && $text > 0) $chId = '-100'.$text;
            
            // Takrorlanmasligini tekshirish
            foreach ($db['channels'] as $ch) {
                if ($ch['id'] == $chId) {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "⚠️ Bu kanal allaqachon qo'shilgan"]);
                    unlink($adminStateFile);
                    exit;
                }
            }
            
            $db['channels'][] = ['id' => $chId, 'type' => $adminState];
            saveDB($db);
            unlink($adminStateFile);
            
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ Kanal muvaffaqiyatli qo'shildi\nID: " . $chId . "\nTuri: " . ($adminState == 'open' ? 'Ochiq' : 'Maxsus'),
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "👑 Admin panel", 'callback_data' => 'admin_panel']]
                    ]
                ])
            ]);
            exit;
        }
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Noto'g'ri format. Qaytadan urinib ko'ring."]);
        exit;
    }

    // Foydalanuvchi uchun a'zolik tekshiruvi
    if ($userId != ADMIN_ID) {
        $notJoined = [];
        foreach ($db['channels'] as $channel) {
            if ($channel['type'] == 'private') {
                $userReqs = isset($db['requests'][$userId]) ? $db['requests'][$userId] : [];
                if (!in_array($channel['id'], $userReqs)) {
                    $notJoined[] = $channel;
                }
            } else {
                $memberStatus = bot('getChatMember', ['chat_id' => $channel['id'], 'user_id' => $userId]);
                if (!$memberStatus['ok'] || !in_array($memberStatus['result']['status'], ['member', 'administrator', 'creator'])) {
                    $notJoined[] = $channel;
                }
            }
        }

        if (!empty($notJoined)) {
            $keyboard = [];
            foreach ($notJoined as $ch) {
                $chInfo = getChannelInfo($ch['id']);
                $label = $ch['type'] == 'open' ? "🌐 Qo'shilish" : "🔒 So'rov yuborish";
                $keyboard[] = [['text' => ($chInfo ? $chInfo : $ch['id'])." - ".$label, 'callback_data' => 'join_'.$ch['type'].'_'.$ch['id']]];
            }
            $keyboard[] = [['text' => "🔄 Tekshirish", 'callback_data' => 'check_membership']];
            
            if ($text == '/start' || empty($text)) {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "👋 Assalomu alaykum!\n\nBotdan foydalanish uchun quyidagi kanallarga a'zo bo'ling:",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "⚠️ Avval kanallarga a'zo bo'lishingiz kerak!",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
            }
            exit;
        }

        // Foydalanuvchi anime kodini yuborgan
        if (!empty($text)) {
            if ($text == '/start') {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "👋 Anime kodini yuboring 🎬"]);
                exit;
            }
            
            // Kod bo'yicha anime qidirish
            if (isset($db['animes'][$text])) {
                $anime = $db['animes'][$text];
                $episodes = $anime['episodes'];
                ksort($episodes);
                
                $keyboard = [];
                $episodeButtons = [];
                foreach ($episodes as $epNum => $fileId) {
                    $episodeButtons[] = ['text' => "📺 ".$epNum."-qism", 'callback_data' => 'watch_'.$text.'_'.$epNum];
                    if (count($episodeButtons) == 3) {
                        $keyboard[] = $episodeButtons;
                        $episodeButtons = [];
                    }
                }
                if (!empty($episodeButtons)) $keyboard[] = $episodeButtons;
                
                // Birinchi qismni yuborish
                $firstEp = min(array_keys($episodes));
                bot('sendVideo', [
                    'chat_id' => $chatId,
                    'video' => $episodes[$firstEp],
                    'caption' => "🎬 " . $anime['name'] . " | Kod: " . $text . " | 1-qism",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
            } else {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "🤷‍♂️ Bunday kod topilmadi"]);
            }
            exit;
        }
    }

    // Admin uchun
    if ($userId == ADMIN_ID) {
        if ($text == '/start') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👑 Admin panelga xush kelibsiz!",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "👑 Panelni ochish", 'callback_data' => 'admin_panel']]
                    ]
                ])
            ]);
            exit;
        }

        // Anime qo'shish (video yuborilganda)
        if (isset($message['video'])) {
            $fileId = $message['video']['file_id'];
            $caption = isset($message['caption']) ? trim($message['caption']) : '';
            
            if (empty($caption)) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Iltimos, video bilan birga anime nomini caption sifatida yuboring"]);
                exit;
            }
            
            // Yangi anime yoki mavjudiga qism qo'shish
            $existingCode = null;
            foreach ($db['animes'] as $code => $anime) {
                if (mb_strtolower($anime['name']) == mb_strtolower($caption)) {
                    $existingCode = $code;
                    break;
                }
            }
            
            if ($existingCode) {
                // Mavjud animega yangi qism qo'shish
                $epNum = count($db['animes'][$existingCode]['episodes']) + 1;
                $db['animes'][$existingCode]['episodes'][$epNum] = $fileId;
                saveDB($db);
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ \"".$caption."\" animesiga ".$epNum."-qism qo'shildi!\nKod: " . $existingCode
                ]);
            } else {
                // Yangi anime yaratish
                $db['last_anime_code']++;
                $newCode = str_pad($db['last_anime_code'], 4, '0', STR_PAD_LEFT);
                $db['animes'][$newCode] = [
                    'name' => $caption,
                    'episodes' => [1 => $fileId]
                ];
                saveDB($db);
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Yangi anime qo'shildi!\nNomi: " . $caption . "\nKod: " . $newCode . "\n1-qism saqlandi"
                ]);
            }
            exit;
        }
        
        // Admin panel state'larini boshqarish
        if ($adminState == 'search_anime' && !empty($text)) {
            unlink($adminStateFile);
            $results = [];
            foreach ($db['animes'] as $code => $anime) {
                if (stripos($anime['name'], $text) !== false || $code == $text) {
                    $results[$code] = $anime;
                }
            }
            
            if (empty($results)) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "🔍 Hech narsa topilmadi"]);
            } else {
                $keyboard = [];
                foreach ($results as $code => $anime) {
                    $keyboard[] = [['text' => $anime['name']." (".$code.") - ".count($anime['episodes'])." qism", 'callback_data' => 'admin_anime_'.$code]];
                }
                $keyboard[] = [['text' => "🔙 Admin panel", 'callback_data' => 'admin_panel']];
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "🔍 Topilgan animelar:",
                    'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
                ]);
            }
            exit;
        }
        
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "👑 Noma'lum buyruq. Panelni oching:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "👑 Admin panel", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
    }
}

function getChannelInfo($chatId) {
    $chat = bot('getChat', ['chat_id' => $chatId]);
    if ($chat['ok']) {
        return isset($chat['result']['title']) ? $chat['result']['title'] : 
               (isset($chat['result']['username']) ? '@'.$chat['result']['username'] : $chatId);
    }
    return null;
}

function getChannelInviteLink($chatId) {
    $chat = bot('getChat', ['chat_id' => $chatId]);
    if ($chat['ok'] && isset($chat['result']['username'])) {
        return "https://t.me/".$chat['result']['username'];
    }
    $link = bot('exportChatInviteLink', ['chat_id' => $chatId]);
    return $link['ok'] ? $link['result'] : null;
}
