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
    'animes' => [],
    'channels' => [],
    'requests' => [],
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

// Kanal so'rovlari
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

// CALLBACK QUERY HANDLER
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];
    $msgId = $callback['message']['message_id'];

    // Admin panel - ASOSIY MENYU
    if ($data == 'admin_panel') {
        if ($userId != ADMIN_ID) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "❌ Siz admin emassiz!",
                'show_alert' => true
            ]);
            exit;
        }
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "👑 Admin Panel\n\nKerakli amalni tanlang:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "➕ Kanal qo'shish", 'callback_data' => 'add_channel_menu']],
                    [['text' => "📋 Kanallar ro'yxati", 'callback_data' => 'list_channels']],
                    [['text' => "🎬 Anime qo'shish yo'riqnomasi", 'callback_data' => 'add_anime_info']],
                    [['text' => "📊 Statistika", 'callback_data' => 'stats']]
                ]
            ])
        ]);
        exit;
    }
    
    // Kanal qo'shish menyusi
    if ($data == 'add_channel_menu') {
        if ($userId != ADMIN_ID) exit;
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📢 Kanal turini tanlang:",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🌐 Ochiq kanal (to'g'ridan-to'g'ri a'zo bo'lish)", 'callback_data' => 'add_open_channel']],
                    [['text' => "🔒 Maxsus kanal (so'rov orqali)", 'callback_data' => 'add_private_channel']],
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }
    
    // Kanal qo'shish - ID so'rash
    if ($data == 'add_open_channel' || $data == 'add_private_channel') {
        if ($userId != ADMIN_ID) exit;
        
        $type = $data == 'add_open_channel' ? 'open' : 'private';
        file_put_contents('admin_state_'.$userId.'.txt', $type);
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "Kanal ID yoki username yuboring:\n\nMasalan:\n• @kanalnomi\n• -100123456789\n• 123456789",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Bekor qilish", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }
    
    // Kanallar ro'yxati
    if ($data == 'list_channels') {
        if ($userId != ADMIN_ID) exit;
        
        if (empty($db['channels'])) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "❌ Hozircha kanallar mavjud emas",
                'show_alert' => true
            ]);
            exit;
        }
        
        $keyboard = [];
        foreach ($db['channels'] as $index => $channel) {
            $chInfo = getChannelInfo($channel['id']);
            $icon = $channel['type'] == 'open' ? '🌐' : '🔒';
            $name = $chInfo ? $chInfo : $channel['id'];
            $keyboard[] = [['text' => $icon . " " . $name, 'callback_data' => 'channel_detail_'.$index]];
        }
        $keyboard[] = [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']];
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📋 Kanallar ro'yxati (" . count($db['channels']) . " ta):",
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
        exit;
    }
    
    // Kanal detallari
    if (strpos($data, 'channel_detail_') === 0) {
        if ($userId != ADMIN_ID) exit;
        
        $index = (int)str_replace('channel_detail_', '', $data);
        if (!isset($db['channels'][$index])) exit;
        
        $channel = $db['channels'][$index];
        $chInfo = getChannelInfo($channel['id']);
        $typeText = $channel['type'] == 'open' ? '🌐 Ochiq kanal' : '🔒 Maxsus kanal';
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📢 Kanal ma'lumotlari:\n\nNomi: " . ($chInfo ? $chInfo : 'Noma\'lum') . "\nID: " . $channel['id'] . "\nTuri: " . $typeText,
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🗑 Kanalni o'chirish", 'callback_data' => 'delete_channel_'.$index]],
                    [['text' => "🔙 Kanallar ro'yxatiga", 'callback_data' => 'list_channels']]
                ]
            ])
        ]);
        exit;
    }
    
    // Kanalni o'chirish
    if (strpos($data, 'delete_channel_') === 0) {
        if ($userId != ADMIN_ID) exit;
        
        $index = (int)str_replace('delete_channel_', '', $data);
        if (!isset($db['channels'][$index])) exit;
        
        $deletedChannel = $db['channels'][$index];
        array_splice($db['channels'], $index, 1);
        saveDB($db);
        
        bot('answerCallbackQuery', [
            'callback_query_id' => $cbId,
            'text' => "✅ Kanal muvaffaqiyatli o'chirildi!",
            'show_alert' => true
        ]);
        
        // Kanallar ro'yxatini yangilash
        if (empty($db['channels'])) {
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $msgId,
                'text' => "📋 Kanallar ro'yxati bo'sh",
                'reply_markup' => json_encode([
                    'inline_keyboard' => [
                        [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                    ]
                ])
            ]);
        } else {
            $keyboard = [];
            foreach ($db['channels'] as $i => $channel) {
                $chInfo = getChannelInfo($channel['id']);
                $icon = $channel['type'] == 'open' ? '🌐' : '🔒';
                $keyboard[] = [['text' => $icon . " " . ($chInfo ? $chInfo : $channel['id']), 'callback_data' => 'channel_detail_'.$i]];
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
    
    // Anime qo'shish yo'riqnomasi
    if ($data == 'add_anime_info') {
        if ($userId != ADMIN_ID) exit;
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📝 Anime qo'shish yo'riqnomasi:\n\n1. Video yuboring\n2. Caption (izoh) qismiga anime nomini yozing\n3. Agar anime avval mavjud bo'lsa, avtomatik yangi qism qo'shiladi\n4. Yangi anime bo'lsa, avtomatik kod beriladi\n\nMisol: 'Naruto' deb yozsangiz, kod avtomatik beriladi",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }
    
    // Statistika
    if ($data == 'stats') {
        if ($userId != ADMIN_ID) exit;
        
        $totalAnimes = count($db['animes']);
        $totalEpisodes = 0;
        foreach ($db['animes'] as $anime) {
            $totalEpisodes += count($anime['episodes']);
        }
        $totalChannels = count($db['channels']);
        $totalRequests = 0;
        foreach ($db['requests'] as $reqs) {
            $totalRequests += count($reqs);
        }
        
        bot('editMessageText', [
            'chat_id' => $chatId,
            'message_id' => $msgId,
            'text' => "📊 Bot Statistikasi:\n\n🎬 Animelar: " . $totalAnimes . " ta\n📺 Jami qismlar: " . $totalEpisodes . " ta\n📢 Kanallar: " . $totalChannels . " ta\n👥 A'zolik so'rovlari: " . $totalRequests . " ta",
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => "🔙 Orqaga", 'callback_data' => 'admin_panel']]
                ]
            ])
        ]);
        exit;
    }
    
    // Foydalanuvchi uchun a'zolikni tekshirish
    if ($data == 'check_membership') {
        $notJoined = checkUserChannels($userId);
        
        if (empty($notJoined)) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "✅ Barcha kanallarga a'zosiz!",
                'show_alert' => false
            ]);
            bot('deleteMessage', [
                'chat_id' => $chatId,
                'message_id' => $msgId
            ]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "✅ Xush kelibsiz! Anime kodini yuboring 🎬"
            ]);
        } else {
            showChannelJoinButtons($chatId, $msgId, $notJoined);
        }
        exit;
    }
    
    // Kanalga qo'shilish
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
                        [['text' => "📤 So'rov yuborish", 'url' => $linkRes['result']['invite_link']],
                        [['text' => "🔄 Tekshirish", 'callback_data' => 'check_membership']]
                    ]
                ])
            ]);
        }
        exit;
    }
    
    // Anime qismlarini ko'rish
    if (strpos($data, 'watch_') === 0) {
        $parts = explode('_', $data);
        $code = $parts[1];
        $epNum = (int)$parts[2];
        
        if (isset($db['animes'][$code]) && isset($db['animes'][$code]['episodes'][$epNum])) {
            $anime = $db['animes'][$code];
            $fileId = $anime['episodes'][$epNum];
            
            // Boshqa qismlar uchun tugmalar
            $keyboard = [];
            $episodeButtons = [];
            foreach ($anime['episodes'] as $ep => $fid) {
                if ($ep != $epNum) {
                    $episodeButtons[] = ['text' => "📺 ".$ep."-qism", 'callback_data' => 'watch_'.$code.'_'.$ep];
                    if (count($episodeButtons) == 3) {
                        $keyboard[] = $episodeButtons;
                        $episodeButtons = [];
                    }
                }
            }
            if (!empty($episodeButtons)) $keyboard[] = $episodeButtons;
            
            bot('sendVideo', [
                'chat_id' => $chatId,
                'video' => $fileId,
                'caption' => "🎬 " . $anime['name'] . " | Kod: " . $code . " | " . $epNum . "-qism",
                'reply_markup' => !empty($keyboard) ? json_encode(['inline_keyboard' => $keyboard]) : null
            ]);
            
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "Video yuklanmoqda...",
                'show_alert' => false
            ]);
        }
        exit;
    }
    
    exit;
}

// XABARLAR BILAN ISHLASH
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // Admin state tekshirish
    $adminStateFile = 'admin_state_'.$userId.'.txt';
    $adminState = file_exists($adminStateFile) ? trim(file_get_contents($adminStateFile)) : '';

    // ADMIN - KANAL QO'SHISH
    if ($userId == ADMIN_ID && in_array($adminState, ['open', 'private']) && !empty($text)) {
        $chId = formatChannelId($text);
        
        if ($chId) {
            // Takrorlanmasligini tekshirish
            $exists = false;
            foreach ($db['channels'] as $ch) {
                if ($ch['id'] == $chId) {
                    $exists = true;
                    break;
                }
            }
            
            if ($exists) {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "⚠️ Bu kanal allaqachon qo'shilgan!"
                ]);
            } else {
                $db['channels'][] = ['id' => $chId, 'type' => $adminState];
                saveDB($db);
                
                $typeText = $adminState == 'open' ? 'Ochiq kanal' : 'Maxsus kanal';
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Kanal muvaffaqiyatli qo'shildi!\n\n📢 ID: " . $chId . "\n🏷 Turi: " . $typeText,
                    'reply_markup' => json_encode([
                        'inline_keyboard' => [
                            [['text' => "👑 Admin panelga qaytish", 'callback_data' => 'admin_panel']]
                        ]
                    ])
                ]);
            }
            
            unlink($adminStateFile);
            exit;
        } else {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "❌ Noto'g'ri format! Qaytadan yuboring:\n\nTo'g'ri formatlar:\n• @kanalnomi\n• -100123456789\n• 123456789"
            ]);
            exit;
        }
    }

    // FOYDALANUVCHI UCHUN
    if ($userId != ADMIN_ID) {
        // Kanallarni tekshirish
        $notJoined = checkUserChannels($userId);
        
        if (!empty($notJoined)) {
            if ($text == '/start') {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "👋 Assalomu alaykum!\n\nBotdan foydalanish uchun quyidagi kanallarga a'zo bo'ling:",
                    'reply_markup' => getChannelsKeyboard($notJoined)
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "⚠️ Avval barcha kanallarga a'zo bo'lishingiz kerak!",
                    'reply_markup' => getChannelsKeyboard($notJoined)
                ]);
            }
            exit;
        }
        
        // Anime kodini qidirish
        if (!empty($text)) {
            if ($text == '/start') {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "👋 Anime kodini yuboring 🎬"
                ]);
                exit;
            }
            
            if (isset($db['animes'][$text])) {
                $anime = $db['animes'][$text];
                $episodes = $anime['episodes'];
                ksort($episodes);
                
                // Birinchi qismni aniqlash
                $firstEp = min(array_keys($episodes));
                
                // Barcha qismlar uchun tugmalar
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
                
                // Birinchi qismni video sifatida yuborish
                bot('sendVideo', [
                    'chat_id' => $chatId,
                    'video' => $episodes[$firstEp],
                    'caption' => "🎬 " . $anime['name'] . " | Kod: " . $text . " | " . $firstEp . "-qism",
                    'reply_markup' => !empty($keyboard) ? json_encode(['inline_keyboard' => $keyboard]) : null
                ]);
            } else {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "🤷‍♂️ Bunday kodli anime topilmadi"
                ]);
            }
            exit;
        }
    }
    
    // ADMIN UCHUN
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
        
        // Video qabul qilish (anime qo'shish)
        if (isset($message['video'])) {
            $fileId = $message['video']['file_id'];
            $caption = isset($message['caption']) ? trim($message['caption']) : '';
            
            if (empty($caption)) {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Iltimos, video bilan birga caption sifatida anime nomini yozing!\n\nMisol: Naruto"
                ]);
                exit;
            }
            
            // Mavjud anime nomini qidirish
            $existingCode = null;
            foreach ($db['animes'] as $code => $anime) {
                if (mb_strtolower($anime['name']) == mb_strtolower($caption)) {
                    $existingCode = $code;
                    break;
                }
            }
            
            if ($existingCode) {
                // Mavjud animega yangi qism
                $nextEp = count($db['animes'][$existingCode]['episodes']) + 1;
                $db['animes'][$existingCode]['episodes'][$nextEp] = $fileId;
                saveDB($db);
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ \"".$caption."\" animesiga " . $nextEp . "-qism qo'shildi!\n\n📌 Kod: " . $existingCode . "\n📺 Jami qismlar: " . $nextEp . " ta"
                ]);
            } else {
                // Yangi anime
                $db['last_anime_code']++;
                $newCode = str_pad($db['last_anime_code'], 4, '0', STR_PAD_LEFT);
                $db['animes'][$newCode] = [
                    'name' => $caption,
                    'episodes' => [1 => $fileId]
                ];
                saveDB($db);
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Yangi anime qo'shildi!\n\n🎬 Nomi: " . $caption . "\n🔑 Kod: " . $newCode . "\n📺 Qism: 1 ta"
                ]);
            }
            exit;
        }
    }
}

// Yordamchi funksiyalar
function checkUserChannels($userId) {
    global $db;
    $notJoined = [];
    
    foreach ($db['channels'] as $channel) {
        if ($channel['type'] == 'private') {
            $userReqs = isset($db['requests'][$userId]) ? $db['requests'][$userId] : [];
            if (!in_array($channel['id'], $userReqs)) {
                $notJoined[] = $channel;
            }
        } else {
            $memberStatus = bot('getChatMember', [
                'chat_id' => $channel['id'],
                'user_id' => $userId
            ]);
            if (!$memberStatus['ok'] || !in_array($memberStatus['result']['status'], ['member', 'administrator', 'creator'])) {
                $notJoined[] = $channel;
            }
        }
    }
    
    return $notJoined;
}

function getChannelsKeyboard($channels) {
    $keyboard = [];
    foreach ($channels as $ch) {
        $chInfo = getChannelInfo($ch['id']);
        $name = $chInfo ? $chInfo : $ch['id'];
        $label = $ch['type'] == 'open' ? "🌐 Qo'shilish" : "🔒 So'rov yuborish";
        $keyboard[] = [['text' => $name . " - " . $label, 'callback_data' => 'join_' . $ch['type'] . '_' . $ch['id']]];
    }
    $keyboard[] = [['text' => "🔄 A'zolikni tekshirish", 'callback_data' => 'check_membership']];
    
    return json_encode(['inline_keyboard' => $keyboard]);
}

function showChannelJoinButtons($chatId, $msgId, $channels) {
    bot('editMessageText', [
        'chat_id' => $chatId,
        'message_id' => $msgId,
        'text' => "⚠️ Botdan foydalanish uchun barcha kanallarga a'zo bo'ling:",
        'reply_markup' => getChannelsKeyboard($channels)
    ]);
}

function formatChannelId($text) {
    $text = trim($text);
    
    // @username format
    if (strpos($text, '@') === 0) {
        return $text;
    }
    
    // -100 format
    if (strpos($text, '-100') === 0 && is_numeric(substr($text, 4))) {
        return $text;
    }
    
    // Raqam bo'lsa, -100 qo'shish
    if (is_numeric($text) && strlen($text) >= 9) {
        return '-100' . $text;
    }
    
    return false;
}

function getChannelInfo($chatId) {
    $chat = bot('getChat', ['chat_id' => $chatId]);
    if ($chat['ok']) {
        return isset($chat['result']['title']) ? $chat['result']['title'] : 
               (isset($chat['result']['username']) ? '@'.$chat['result']['username'] : null);
    }
    return null;
}

function getChannelInviteLink($chatId) {
    // Avval username orqali
    $chat = bot('getChat', ['chat_id' => $chatId]);
    if ($chat['ok'] && isset($chat['result']['username'])) {
        return "https://t.me/".$chat['result']['username'];
    }
    
    // Invite link yaratish
    $link = bot('exportChatInviteLink', ['chat_id' => $chatId]);
    return $link['ok'] ? $link['result'] : null;
}
