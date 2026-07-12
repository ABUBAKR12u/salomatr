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
    'animes' => [],
    'channels' => [],
    'requests' => []
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

function adminKeyboard() {
    return json_encode([
        'keyboard' => [
            ['📢 Kanal qo\'shish', '📋 Kanallar ro\'yxati'],
            ['🎬 Yangi anime qo\'shish', '📚 Animelar ro\'yxati'],
            ['➕ Qism qo\'shish']
        ],
        'resize_keyboard' => true
    ]);
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

    // ==================== ADMIN ====================
    if ($userId == ADMIN_ID) {
        $stateFile = 'admin_state_' . $userId;
        $tempFile = 'admin_temp_' . $userId;
        
        // State handler
        if (file_exists($stateFile)) {
            $state = file_get_contents($stateFile);
            
            // Kanal qo'shish
            if ($state == 'waiting_channel') {
                handleAddChannel($chatId, $text);
                unlink($stateFile);
                showAdminMenu($chatId);
                exit;
            }
            
            // Anime nomi kutish (video allaqachon qabul qilingan)
            if ($state == 'waiting_anime_name') {
                $animeName = $text;
                
                if (empty($animeName)) {
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ Nom bo'sh bo'lishi mumkin emas. Qaytadan yozing:"
                    ]);
                    exit;
                }
                
                $temp = json_decode(file_get_contents($tempFile), true);
                
                $code = generateCode();
                while (isset($db['animes'][$code])) {
                    $code = generateCode();
                }
                
                $db['animes'][$code] = [
                    'name' => $animeName,
                    'episodes' => ['1' => $temp['file_id']]
                ];
                saveDB($db);
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Anime muvaffaqiyatli saqlandi!\n\n📺 Nom: $animeName\n🔑 Kod: $code\n📹 Qism: 1\n\nEndi boshqa qism qo'shish uchun '➕ Qism qo'shish' tugmasini bosing."
                ], 'reply_markup', adminKeyboard());
                
                unlink($stateFile);
                if (file_exists($tempFile)) unlink($tempFile);
                exit;
            }
            
            // Qaysi animega qism qo'shishni aniqlash
            if ($state == 'waiting_episode_anime_name') {
                $animeName = $text;
                $animeCode = null;
                
                foreach ($db['animes'] as $code => $anime) {
                    if (strtolower($anime['name']) == strtolower($animeName)) {
                        $animeCode = $code;
                        break;
                    }
                }
                
                if (!$animeCode) {
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ \"$animeName\" nomli anime topilmadi.\n\nQaytadan yozing yoki bekor qilish uchun /start bosing."
                    ]);
                    exit;
                }
                
                file_put_contents($tempFile, json_encode([
                    'anime_code' => $animeCode,
                    'anime_name' => $anime['name']
                ]));
                file_put_contents($stateFile, 'waiting_episode_video');
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "📹 \"{$anime['name']}\" uchun qism videosini yuboring.\n\nCaption yozish shart emas."
                ]);
                exit;
            }
            
            // Qism videosini kutish
            if ($state == 'waiting_episode_video') {
                if (!isset($message['video'])) {
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ Iltimos, video fayl yuboring!"
                    ]);
                    exit;
                }
                
                $temp = json_decode(file_get_contents($tempFile), true);
                $animeCode = $temp['anime_code'];
                $animeName = $temp['anime_name'];
                
                $episodes = $db['animes'][$animeCode]['episodes'];
                $nextEpisode = count($episodes) + 1;
                
                $db['animes'][$animeCode]['episodes'][(string)$nextEpisode] = $message['video']['file_id'];
                saveDB($db);
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Qism muvaffaqiyatli qo'shildi!\n\n📺 Anime: $animeName\n📹 Qism: $nextEpisode\n🔑 Kod: $animeCode"
                ], 'reply_markup', adminKeyboard());
                
                unlink($stateFile);
                if (file_exists($tempFile)) unlink($tempFile);
                exit;
            }
            
            // Yangi anime uchun video kutish
            if ($state == 'waiting_anime_video') {
                if (!isset($message['video'])) {
                    bot('sendMessage', [
                        'chat_id' => $chatId,
                        'text' => "❌ Iltimos, video fayl yuboring!\n\nCaption yozish shart emas, keyin nomini so'raymiz."
                    ]);
                    exit;
                }
                
                $fileId = $message['video']['file_id'];
                file_put_contents($tempFile, json_encode(['file_id' => $fileId]));
                file_put_contents($stateFile, 'waiting_anime_name');
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "📝 Endi anime nomini yozing:"
                ]);
                exit;
            }
        }
        
        // Reply tugmalar
        if ($text == '/start') {
            showAdminMenu($chatId);
            exit;
        }
        
        if ($text == '📢 Kanal qo\'shish') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "📢 Kanal qo'shish\n\n📌 Ochiq kanal uchun: Kanal linkini yuboring (t.me/...)\n📌 Yopiq kanal uchun: Kanal ID sini yuboring (Masalan: -100123456789)\n\n❌ Bekor qilish: /start"
            ]);
            file_put_contents('admin_state_' . $userId, 'waiting_channel');
            exit;
        }
        
        if ($text == '📋 Kanallar ro\'yxati') {
            showChannelsList($chatId);
            exit;
        }
        
        if ($text == '🎬 Yangi anime qo\'shish') {
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => "🎬 Anime videosini yuboring.\n\n📌 Caption yozish shart emas, video yuborgandan keyin nomini so'raymiz.\n\n❌ Bekor qilish: /start"
            ]);
            file_put_contents('admin_state_' . $userId, 'waiting_anime_video');
            exit;
        }
        
        if ($text == '📚 Animelar ro\'yxati') {
            showAnimesList($chatId);
            exit;
        }
        
        if ($text == '➕ Qism qo\'shish') {
            if (empty($db['animes'])) {
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "❌ Hozircha hech qanday anime qo'shilmagan.\n\nAvval '🎬 Yangi anime qo'shish' orqali anime qo'shing."
                ]);
                exit;
            }
            
            $listText = "📚 Qaysi animega qism qo'shmoqchisiz?\n\nAnime nomini to'liq yozing:\n\n";
            foreach ($db['animes'] as $code => $anime) {
                $listText .= "• {$anime['name']} (" . count($anime['episodes']) . " qism)\n";
            }
            $listText .= "\n❌ Bekor qilish: /start";
            
            bot('sendMessage', [
                'chat_id' => $chatId,
                'text' => $listText
            ]);
            file_put_contents('admin_state_' . $userId, 'waiting_episode_anime_name');
            exit;
        }
        
        // Kanal o'chirish command
        if (strpos($text, '/delete_channel_') === 0) {
            $index = (int)str_replace('/delete_channel_', '', $text) - 1;
            deleteChannel($chatId, $index);
            exit;
        }
    }

    // ==================== USER ====================
    if ($userId != ADMIN_ID && !empty($db['channels'])) {
        if (!checkAllChannelsJoined($userId)) {
            showChannelJoinPrompt($chatId, $userId);
            exit;
        }
    }

    if ($text == '/start') {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "👋 Salom! Anime tomosha qilish uchun anime nomini yozing yoki kod kiriting.\n\n📺 Mavjud animelarni ko'rish uchun /animes buyrug'ini ishlatng."
        ]);
        exit;
    }
    
    if ($text == '/animes' && $userId != ADMIN_ID) {
        showUserAnimesList($chatId);
        exit;
    }

    if ($userId != ADMIN_ID && $text !== '') {
        handleUserAnimeRequest($chatId, $text);
        exit;
    }
}

// ==================== ADMIN FUNCTIONS ====================

function showAdminMenu($chatId) {
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👑 Admin Panel\n\nQuyidagi tugmalardan birini tanlang:",
        'reply_markup' => adminKeyboard()
    ]);
}

function handleAddChannel($chatId, $text) {
    global $db;
    
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
            'text' => "✅ Ochiq kanal qo'shildi!\n\nLink: $text"
        ]);
    } 
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
            'text' => "✅ Yopiq kanal qo'shildi!\n\nID: $text"
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
    
    $text .= "🗑 O'chirish uchun quyidagini yuboring:\n/delete_channel_<raqam>\n\nMasalan: /delete_channel_1";
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text
    ]);
}

function deleteChannel($chatId, $index) {
    global $db;
    
    if (isset($db['channels'][$index])) {
        $deleted = $db['channels'][$index];
        unset($db['channels'][$index]);
        $db['channels'] = array_values($db['channels']);
        saveDB($db);
        
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "✅ Kanal o'chirildi!\n\n" . $deleted['display_name']
        ]);
    } else {
        bot('sendMessage', [
            'chat_id' => $chatId,
            'text' => "❌ Bunday raqamli kanal topilmadi."
        ]);
    }
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
            $text .= "📢 Kanal\n";
            $keyboard['inline_keyboard'][] = [
                ['text' => "🔗 A'zo bo'lish", 'url' => $channel['link']]
            ];
        } else {
            $linkRes = bot('createChatInviteLink', [
                'chat_id' => $channel['id'],
                'creates_join_request' => true
            ]);
            
            if (isset($linkRes['result']['invite_link'])) {
                $text .= "🔒 Kanal\n";
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
        if (strtolower($anime['name']) == strtolower($text)) {
            sendEpisodeWithButtons($chatId, $code, '1');
            return;
        }
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
        'caption' => "🎬 {$anime['name']}\n📹 Qism: $episodeNum / $totalEpisodes",
        'reply_markup' => json_encode($keyboard)
    ]);
}
