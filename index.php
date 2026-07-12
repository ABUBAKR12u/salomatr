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

// Database struktura
$db = [
    'animes' => [],      // ['code' => ['name' => '...', 'episodes' => ['1' => 'file_id']]]
    'channels' => [],    // [['id' => '-100xxx', 'type' => 'open/closed', 'link' => 'https://...']]
    'requests' => []     // ['user_id' => ['channel_id1', 'channel_id2']]
];

if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) {
        $db = array_merge_recursive($db, $json);
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

function generateCode($length = 6) {
    $characters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $characters[rand(0, strlen($characters) - 1)];
    }
    return $code;
}

// Chat join request handler (faqat yopiq kanallar uchun)
if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];
    
    foreach ($db['channels'] as $channel) {
        if ($channel['id'] == $chatId && $channel['type'] == 'closed') {
            if (!isset($db['requests'][$userId])) {
                $db['requests'][$userId] = [];
            }
            if (!in_array($chatId, $db['requests'][$userId])) {
                $db['requests'][$userId][] = $chatId;
                saveDB($db);
            }
            break;
        }
    }
    exit;
}

// Callback query handler (faqat user uchun)
if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];

    // User callback lar
    if ($data == 'check_membership') {
        if (checkAllChannelsJoined($userId)) {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "Muvaffaqiyatli tasdiqlandi! ✅",
                'show_alert' => false
            ]);
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "Xush kelibsiz! 🎉\n\nAnime tomosha qilish uchun anime nomini yozing yoki /animes buyrug'ini ishlatng."
            ]);
        } else {
            bot('answerCallbackQuery', [
                'callback_query_id' => $cbId,
                'text' => "Siz hali barcha kanallarga a'zo bo'lmadingiz! ❌",
                'show_alert' => true
            ]);
        }
        exit;
    }
    
    // Anime episode tanlash
    if (strpos($data, 'episode_') === 0) {
        $parts = explode('_', $data);
        $animeCode = $parts[1];
        $episodeNum = $parts[2];
        
        sendEpisodeWithButtons($chatId, $animeCode, $episodeNum);
        bot('answerCallbackQuery', ['callback_query_id' => $cbId]);
        exit;
    }
    
    exit;
}

// Message handler
if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    // Admin uchun
    if ($userId == ADMIN_ID) {
        $stateFile = 'admin_state_' . $userId;
        
        // Kanal qo'shish holati
        if (file_exists($stateFile)) {
            $state = file_get_contents($stateFile);
            
            if ($state == 'waiting_channel') {
                handleAddChannel($chatId, $text);
                unlink($stateFile);
                exit;
            }
            
            if (strpos($state, 'waiting_episode_') === 0) {
                $animeName = str_replace('waiting_episode_', '', $state);
                handleAddEpisode($chatId, $message, $animeName);
                unlink($stateFile);
                exit;
            }
        }
        
        // Admin commands (reply tugmalar)
        if ($text == '/start') {
            showAdminMenu($chatId);
            exit;
        }
        
        if ($text == '📢 Kanal qo\'shish') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "📢 Kanal qo'shish\n\n📌 Ochiq kanal uchun: Kanal linkini yuboring\n📌 Yopiq kanal uchun: Kanal ID sini yuboring (Masalan: -100123456789)",
                'reply_markup' => json_encode(['keyboard' => [[]], 'resize_keyboard' => true])
            ]);
            file_put_contents($stateFile, 'waiting_channel');
            exit;
        }
        
        if ($text == '📋 Kanallar ro\'yxati') {
            showChannelsList($chatId);
            exit;
        }
        
        if ($text == '🎬 Yangi anime qo\'shish') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "🎬 Yangi anime qo'shish\n\nAnime videosini yuboring va caption ga anime nomini yozing.",
                'reply_markup' => json_encode(['keyboard' => [[]], 'resize_keyboard' => true])
            ]);
            file_put_contents($stateFile, 'waiting_anime_video');
            exit;
        }
        
        if ($text == '📚 Animelar ro\'yxati') {
            showAnimesList($chatId);
            exit;
        }
        
        // Anime video qabul qilish (yangi anime)
        if (isset($message['video']) && file_exists($stateFile) && file_get_contents($stateFile) == 'waiting_anime_video') {
            handleAddNewAnime($chatId, $message);
            unlink($stateFile);
            exit;
        }
    }

    // User uchun kanal tekshiruvi
    if ($userId != ADMIN_ID && !empty($db['channels'])) {
        if (!checkAllChannelsJoined($userId)) {
            showChannelJoinPrompt($chatId, $userId);
            exit;
        }
    }

    // User commands
    if ($text == '/start') {
        if ($userId == ADMIN_ID) {
            showAdminMenu($chatId);
        } else {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "👋 Salom! Anime tomosha qilish uchun anime nomini yozing yoki kod kiriting.\n\n📺 Mavjud animelarni ko'rish uchun /animes buyrug'ini ishlatng."
            ]);
        }
        exit;
    }
    
    if ($text == '/animes' && $userId != ADMIN_ID) {
        showUserAnimesList($chatId);
        exit;
    }

    // User anime kod yoki nom kiritdi
    if ($userId != ADMIN_ID && $text !== '') {
        handleUserAnimeRequest($chatId, $text);
        exit;
    }
}

// ==================== ADMIN FUNCTIONS ====================

function showAdminMenu($chatId) {
    $keyboard = [
        'keyboard' => [
            ['📢 Kanal qo\'shish', '📋 Kanallar ro\'yxati'],
            ['🎬 Yangi anime qo\'shish', '📚 Animelar ro\'yxati']
        ],
        'resize_keyboard' => true
    ];
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👑 Admin Panel\n\nQuyidagi tugmalardan birini tanlang:",
        'reply_markup' => json_encode($keyboard)
    ]);
}

function handleAddChannel($chatId, $text) {
    global $db;
    
    // Agar link bo'lsa - ochiq kanal
    if (strpos($text, 't.me/') !== false || strpos($text, 'https://') !== false) {
        $channelData = [
            'id' => 'link_' . md5($text),
            'type' => 'open',
            'link' => $text,
            'display_name' => 'Ochiq kanal'
        ];
        
        $db['channels'][] = $channelData;
        saveDB($db);
        
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Ochiq kanal qo'shildi!\n\nLink: $text\n\nEslatma: Foydalanuvchilar bu kanalga a'zo bo'lishlari shart."
        ]);
    } 
    // Agar raqam bo'lsa - yopiq kanal
    elseif (strpos($text, '-') === 0 && is_numeric($text)) {
        $channelData = [
            'id' => $text,
            'type' => 'closed',
            'link' => '',
            'display_name' => 'Yopiq kanal'
        ];
        
        $db['channels'][] = $channelData;
        saveDB($db);
        
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Yopiq kanal qo'shildi!\n\nID: $text\n\nEslatma: Foydalanuvchilar so'rov yuborishlari kerak."
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Noto'g'ri format!\n\n📌 Ochiq kanal uchun: Link yuboring\n📌 Yopiq kanal uchun: ID yuboring"
        ]);
    }
}

function showChannelsList($chatId) {
    global $db;
    
    if (empty($db['channels'])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "📋 Kanallar ro'yxati\n\nHozirda hech qanday kanal qo'shilmagan."
        ]);
        return;
    }
    
    $text = "📋 Kanallar ro'yxati:\n\n";
    
    foreach ($db['channels'] as $index => $channel) {
        $typeIcon = $channel['type'] == 'open' ? '🔓' : '🔒';
        $text .= "{$typeIcon} #" . ($index + 1) . " - " . $channel['display_name'] . "\n";
        if ($channel['type'] == 'open') {
            $text .= "   Link: " . $channel['link'] . "\n";
        } else {
            $text .= "   ID: " . $channel['id'] . "\n";
        }
        $text .= "\n";
    }
    
    $text .= "O'chirish uchun: /delete_channel_<raqam>\nMasalan: /delete_channel_1";
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text
    ]);
}

function handleAddNewAnime($chatId, $message) {
    global $db;
    
    $caption = isset($message['caption']) ? trim($message['caption']) : '';
    
    if (empty($caption)) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Xatolik: Videoga caption sifatida anime nomini yozing!"
        ]);
        return;
    }
    
    $code = generateCode();
    
    while (isset($db['animes'][$code])) {
        $code = generateCode();
    }
    
    $fileId = $message['video']['file_id'];
    
    $db['animes'][$code] = [
        'name' => $caption,
        'episodes' => [
            '1' => $fileId
        ]
    ];
    
    saveDB($db);
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "✅ Yangi anime qo'shildi!\n\n📺 Nom: $caption\n🔑 Kod: $code\n📹 Qism: 1\n\nKeyingi qismlarni qo'shish uchun '📚 Animelar ro'yxati' tugmasini bosing."
    ]);
}

function handleAddEpisode($chatId, $message, $animeName) {
    global $db;
    
    if (!isset($message['video'])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Bu video emas! Iltimos, video fayl yuboring."
        ]);
        return;
    }
    
    $animeCode = null;
    foreach ($db['animes'] as $code => $anime) {
        if ($anime['name'] == $animeName) {
            $animeCode = $code;
            break;
        }
    }
    
    if (!$animeCode) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Anime topilmadi!"
        ]);
        return;
    }
    
    $episodes = $db['animes'][$animeCode]['episodes'];
    $nextEpisode = count($episodes) + 1;
    
    $fileId = $message['video']['file_id'];
    $db['animes'][$animeCode]['episodes'][(string)$nextEpisode] = $fileId;
    saveDB($db);
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "✅ Qism qo'shildi!\n\n📺 Anime: $animeName\n📹 Qism: $nextEpisode\n🔑 Kod: $animeCode"
    ]);
}

function showAnimesList($chatId) {
    global $db;
    
    if (empty($db['animes'])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "📚 Animelar ro'yxati\n\nHozirda hech qanday anime qo'shilmagan."
        ]);
        return;
    }
    
    $text = "📚 Animelar ro'yxati:\n\n";
    
    foreach ($db['animes'] as $code => $anime) {
        $episodeCount = count($anime['episodes']);
        $text .= "🎬 {$anime['name']}\n";
        $text .= "   🔑 Kod: $code\n";
        $text .= "   📹 Qismlar: $episodeCount\n\n";
    }
    
    $text .= "Qism qo'shish uchun: /add_episode_<anime_kodi>\nMasalan: /add_episode_ABC123";
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text
    ]);
}

// ==================== USER FUNCTIONS ====================

function checkAllChannelsJoined($userId) {
    global $db;
    
    foreach ($db['channels'] as $channel) {
        if ($channel['type'] == 'open') {
            $result = bot('getChatMember', [
                'chat_id' => $channel['link'],
                'user_id' => $userId
            ]);
            
            if (!isset($result['result']) || !in_array($result['result']['status'], ['member', 'administrator', 'creator'])) {
                return false;
            }
        } else {
            $userRequests = isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
            if (!in_array((string)$channel['id'], $userRequests)) {
                return false;
            }
        }
    }
    
    return true;
}

function showChannelJoinPrompt($chatId, $userId) {
    global $db;
    
    $keyboard = ['inline_keyboard' => []];
    $text = "🛑 Botdan foydalanish uchun quyidagi kanallarga a'zo bo'ling:\n\n";
    
    foreach ($db['channels'] as $channel) {
        if ($channel['type'] == 'open') {
            $text .= "📢 " . $channel['display_name'] . "\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "🔗 A'zo bo'lish", 'url' => $channel['link']]
            ];
        } else {
            $linkRes = bot('createChatInviteLink', [
                'chat_id' => $channel['id'],
                'creates_join_request' => true
            ]);
            
            if (isset($linkRes['result']['invite_link'])) {
                $text .= "🔒 " . $channel['display_name'] . "\n";
                $keyboard['inline_keyboard'][] = [
                    ['text' => "📩 So'rov yuborish", 'url' => $linkRes['result']['invite_link']]
                ];
            }
        }
    }
    
    $keyboard['inline_keyboard'][] = [
        ['text' => "✅ A'zollikni tekshirish", 'callback_data' => 'check_membership']
    ];
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => json_encode($keyboard)
    ]);
}

function showUserAnimesList($chatId) {
    global $db;
    
    if (empty($db['animes'])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "📺 Hozirda hech qanday anime mavjud emas."
        ]);
        return;
    }
    
    $text = "📺 Mavjud animelar:\n\n";
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($db['animes'] as $code => $anime) {
        $episodeCount = count($anime['episodes']);
        $text .= "🎬 {$anime['name']} ($episodeCount qism)\n";
        
        $keyboard['inline_keyboard'][] = [
            ['text' => "▶️ Ko'rish", 'callback_data' => 'episode_' . $code . '_1']
        ];
    }
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => json_encode($keyboard)
    ]);
}

function handleUserAnimeRequest($chatId, $text) {
    global $db;
    
    if (isset($db['animes'][$text])) {
        sendEpisodeWithButtons($chatId, $text, '1');
        return;
    }
    
    foreach ($db['animes'] as $code => $anime) {
        if (stripos($anime['name'], $text) !== false) {
            sendEpisodeWithButtons($chatId, $code, '1');
            return;
        }
    }
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "🤷‍♂️ Afsuski, \"$text\" nomi yoki kodi bilan anime topilmadi.\n\n/animes buyrug'i orqali barcha animelarni ko'rishingiz mumkin."
    ]);
}

function sendEpisodeWithButtons($chatId, $animeCode, $episodeNum) {
    global $db;
    
    if (!isset($db['animes'][$animeCode])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Anime topilmadi!"
        ]);
        return;
    }
    
    $anime = $db['animes'][$animeCode];
    
    if (!isset($anime['episodes'][$episodeNum])) {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Bu qism mavjud emas!"
        ]);
        return;
    }
    
    $fileId = $anime['episodes'][$episodeNum];
    $totalEpisodes = count($anime['episodes']);
    
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    
    for ($i = 1; $i <= $totalEpisodes; $i++) {
        $row[] = [
            'text' => $i == $episodeNum ? "✅ $i" : "$i",
            'callback_data' => 'episode_' . $animeCode . '_' . $i
        ];
        
        if (count($row) == 5) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    
    if (!empty($row)) {
        $keyboard['inline_keyboard'][] = $row;
    }
    
    bot('sendVideo', [
        'chat_id' => $chatId,
        'video' => $fileId,
        'caption' => "🎬 {$anime['name']}\n📹 Qism: $episodeNum / $totalEpisodes\n🔑 Kod: $animeCode",
        'reply_markup' => json_encode($keyboard)
    ]);
}
