<?php

error_reporting(0);
ini_set('display_errors', 0);

define('TOKEN', '8556626236:AAHraU5HfOIKOZUDJOAc3i6rV5SYuW3vTf4');
define('ADMIN_ID', 8105737095);
define('DB_FILE', 'database.json');

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update) exit;

$db = ['animes' => [], 'channels' => [], 'requests' => []];
if (file_exists(DB_FILE)) {
    $json = json_decode(file_get_contents(DB_FILE), true);
    if (is_array($json)) $db = array_merge_recursive($db, $json);
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

function generateNumericCode() {
    global $db;
    $maxCode = 0;
    foreach ($db['animes'] as $code => $anime) {
        if (is_numeric($code) && (int)$code > $maxCode) $maxCode = (int)$code;
    }
    return (string)($maxCode + 1);
}

function adminKeyboard() {
    return [
        'keyboard' => [
            ['📢 Kanal', '📋 Kanallar'],
            ['🎬 Anime', '📚 Animelar'],
            ['➕ Qism']
        ],
        'resize_keyboard' => true
    ];
}

function getRemainingChannels($userId) {
    global $db;
    $remaining = [];
    
    foreach ($db['channels'] as $channel) {
        $joined = false;
        
        if ($channel['type'] == 'closed') {
            $userRequests = isset($db['requests'][$userId]) ? array_map('strval', $db['requests'][$userId]) : [];
            if (in_array((string)$channel['id'], $userRequests)) {
                $joined = true;
            }
        }
        
        if (!$joined) $remaining[] = $channel;
    }
    
    return $remaining;
}

if (isset($update['chat_join_request'])) {
    $cjr = $update['chat_join_request'];
    $userId = (string)$cjr['from']['id'];
    $chatId = (string)$cjr['chat']['id'];
    
    foreach ($db['channels'] as $channel) {
        if ($channel['id'] == $chatId && $channel['type'] == 'closed') {
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

if (isset($update['callback_query'])) {
    $callback = $update['callback_query'];
    $cbId = $callback['id'];
    $chatId = $callback['message']['chat']['id'];
    $userId = (string)$callback['from']['id'];
    $data = $callback['data'];
    $messageId = $callback['message']['message_id'];

    if ($data == 'check_membership') {
        $remaining = getRemainingChannels($userId);
        
        if (empty($remaining)) {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "✅ Tasdiqlandi!"]);
            
            bot('editMessageText', [
                'chat_id' => $chatId,
                'message_id' => $messageId,
                'text' => "✅ Xush kelibsiz!\n\n/animes - animelar"
            ]);
        } else {
            bot('answerCallbackQuery', ['callback_query_id' => $cbId, 'text' => "❌ A'zo bo'lmadingiz!", 'show_alert' => true]);
            showChannelJoinPrompt($chatId, $userId, $messageId);
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

if (isset($update['message'])) {
    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $userId = (string)$message['from']['id'];
    $text = isset($message['text']) ? trim($message['text']) : '';

    if ($userId == ADMIN_ID) {
        $stateFile = 'admin_state_' . $userId;
        $tempFile = 'admin_temp_' . $userId;
        
        if (file_exists($stateFile)) {
            $state = file_get_contents($stateFile);
            
            if ($state == 'waiting_channel') {
                handleAddChannel($chatId, $text);
                unlink($stateFile);
                showAdminMenu($chatId);
                exit;
            }
            
            if ($state == 'waiting_anime_name') {
                $animeName = $text;
                
                if (empty($animeName)) {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Nom yozing:"]);
                    exit;
                }
                
                $temp = json_decode(file_get_contents($tempFile), true);
                $code = generateNumericCode();
                
                $db['animes'][$code] = ['name' => $animeName, 'episodes' => ['1' => $temp['file_id']]];
                saveDB($db);
                
                bot('sendMessage', [
                    'chat_id' => $chatId,
                    'text' => "✅ Saqlandi!\n\n📺 $animeName\n🔑 Kod: $code",
                    'reply_markup' => adminKeyboard()
                ]);
                
                unlink($stateFile);
                if (file_exists($tempFile)) unlink($tempFile);
                exit;
            }
            
            if ($state == 'waiting_episode_anime_name') {
                $animeName = $text;
                $animeCode = null;
                $foundAnime = null;
                
                foreach ($db['animes'] as $code => $anime) {
                    if (strtolower($anime['name']) == strtolower($animeName)) {
                        $animeCode = $code;
                        $foundAnime = $anime;
                        break;
                    }
                }
                
                if (!$animeCode) {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Topilmadi. /start"]);
                    exit;
                }
                
                file_put_contents($tempFile, json_encode(['anime_code' => $animeCode, 'anime_name' => $foundAnime['name']]));
                file_put_contents($stateFile, 'waiting_episode_video');
                
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "📹 Video yuboring:"]);
                exit;
            }
            
            if ($state == 'waiting_episode_video') {
                if (!isset($message['video'])) {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Video yuboring!"]);
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
                    'text' => "✅ Qism $nextEpisode qo'shildi!",
                    'reply_markup' => adminKeyboard()
                ]);
                
                unlink($stateFile);
                if (file_exists($tempFile)) unlink($tempFile);
                exit;
            }
            
            if ($state == 'waiting_anime_video') {
                if (!isset($message['video'])) {
                    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Video yuboring!"]);
                    exit;
                }
                
                $fileId = $message['video']['file_id'];
                file_put_contents($tempFile, json_encode(['file_id' => $fileId]));
                file_put_contents($stateFile, 'waiting_anime_name');
                
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "📝 Nom yozing:"]);
                exit;
            }
        }
        
        if ($text == '/start') {
            showAdminMenu($chatId);
            exit;
        }
        
        if ($text == '📢 Kanal') {
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "📌 Yopiq kanal ID yuboring (-100...):"]);
            file_put_contents('admin_state_' . $userId, 'waiting_channel');
            exit;
        }
        
        if ($text == '📋 Kanallar') {
            showChannelsList($chatId);
            exit;
        }
        
        if ($text == '🎬 Anime') {
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "📹 Video yuboring:"]);
            file_put_contents('admin_state_' . $userId, 'waiting_anime_video');
            exit;
        }
        
        if ($text == '📚 Animelar') {
            showAnimesList($chatId);
            exit;
        }
        
        if ($text == '➕ Qism') {
            if (empty($db['animes'])) {
                bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Anime yo'q"]);
                exit;
            }
            
            $listText = "Qaysi anime?\n\n";
            foreach ($db['animes'] as $code => $anime) {
                $listText .= "$code - {$anime['name']}\n";
            }
            
            bot('sendMessage', ['chat_id' => $chatId, 'text' => $listText]);
            file_put_contents('admin_state_' . $userId, 'waiting_episode_anime_name');
            exit;
        }
        
        if (strpos($text, '/delete_channel_') === 0) {
            $index = (int)str_replace('/delete_channel_', '', $text) - 1;
            deleteChannel($chatId, $index);
            exit;
        }
    }

    if ($userId != ADMIN_ID && !empty($db['channels'])) {
        $remaining = getRemainingChannels($userId);
        if (!empty($remaining)) {
            showChannelJoinPrompt($chatId, $userId);
            exit;
        }
    }

    if ($text == '/start') {
        if ($userId == ADMIN_ID) {
            showAdminMenu($chatId);
        } else {
            bot('sendMessage', ['chat_id' => $chatId, 'text' => "👋 Salom!\n\n/animes - animelar"]);
        }
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

function showAdminMenu($chatId) {
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => "👑 Admin",
        'reply_markup' => adminKeyboard()
    ]);
}

function handleAddChannel($chatId, $text) {
    global $db;
    
    if (strpos($text, '-') === 0 && is_numeric($text)) {
        $db['channels'][] = ['id' => $text, 'type' => 'closed', 'display_name' => 'Yopiq'];
        saveDB($db);
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ Yopiq kanal qo'shildi"]);
    } else {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Faqat yopiq kanal ID (-100...)"]);
    }
}

function showChannelsList($chatId) {
    global $db;
    
    if (empty($db['channels'])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "📋 Kanallar yo'q"]);
        return;
    }
    
    $text = "📋 Kanallar:\n\n";
    foreach ($db['channels'] as $index => $channel) {
        $text .= "🔒 #" . ($index + 1) . " - " . $channel['id'] . "\n";
    }
    $text .= "\n/delete_channel_<raqam>";
    
    bot('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
}

function deleteChannel($chatId, $index) {
    global $db;
    
    if (isset($db['channels'][$index])) {
        unset($db['channels'][$index]);
        $db['channels'] = array_values($db['channels']);
        saveDB($db);
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "✅ O'chirildi"]);
    } else {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Topilmadi"]);
    }
}

function showAnimesList($chatId) {
    global $db;
    
    if (empty($db['animes'])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "📚 Animelar yo'q"]);
        return;
    }
    
    $text = "📚 Animelar:\n\n";
    foreach ($db['animes'] as $code => $anime) {
        $text .= "$code - {$anime['name']} (" . count($anime['episodes']) . ")\n";
    }
    
    bot('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
}

function showChannelJoinPrompt($chatId, $userId, $messageId = null) {
    $channelsToJoin = getRemainingChannels($userId);
    
    if (empty($channelsToJoin)) {
        $text = "✅ Xush kelibsiz!\n\n/animes - animelar";
        $keyboard = ['inline_keyboard' => []];
    } else {
        $text = "🛑 Kanallarga a'zo bo'ling:\n\n";
        $keyboard = ['inline_keyboard' => []];
        
        foreach ($channelsToJoin as $index => $channel) {
            $num = $index + 1;
            
            $linkRes = bot('createChatInviteLink', [
                'chat_id' => $channel['id'],
                'creates_join_request' => true
            ]);
            
            if (isset($linkRes['result']['invite_link'])) {
                $keyboard['inline_keyboard'][] = [
                    ['text' => "Kanal $num", 'url' => $linkRes['result']['invite_link']]
                ];
            }
        }
        
        $keyboard['inline_keyboard'][] = [
            ['text' => "✅ Tasdiqlash", 'callback_data' => 'check_membership']
        ];
    }
    
    $params = [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $keyboard
    ];
    
    if ($messageId) {
        $params['message_id'] = $messageId;
        bot('editMessageText', $params);
    } else {
        bot('sendMessage', $params);
    }
}

function showUserAnimesList($chatId) {
    global $db;
    
    if (empty($db['animes'])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "📺 Anime yo'q"]);
        return;
    }
    
    $text = "📺 Animelar:\n\n";
    $keyboard = ['inline_keyboard' => []];
    
    foreach ($db['animes'] as $code => $anime) {
        $text .= "$code - {$anime['name']}\n";
        $keyboard['inline_keyboard'][] = [
            ['text' => "▶️ Ko'rish", 'callback_data' => 'episode_' . $code . '_1']
        ];
    }
    
    bot('sendMessage', [
        'chat_id' => $chatId,
        'text' => $text,
        'reply_markup' => $keyboard
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
    
    bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Topilmadi"]);
}

function sendEpisodeWithButtons($chatId, $animeCode, $episodeNum) {
    global $db;
    
    if (!isset($db['animes'][$animeCode])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Anime yo'q"]);
        return;
    }
    
    $anime = $db['animes'][$animeCode];
    
    if (!isset($anime['episodes'][$episodeNum])) {
        bot('sendMessage', ['chat_id' => $chatId, 'text' => "❌ Qism yo'q"]);
        return;
    }
    
    $fileId = $anime['episodes'][$episodeNum];
    $totalEpisodes = count($anime['episodes']);
    
    $keyboard = ['inline_keyboard' => []];
    $row = [];
    
    for ($i = 1; $i <= $totalEpisodes; $i++) {
        $row[] = [
            'text' => $i == $episodeNum ? "✅$i" : "$i",
            'callback_data' => 'episode_' . $animeCode . '_' . $i
        ];
        
        if (count($row) == 5) {
            $keyboard['inline_keyboard'][] = $row;
            $row = [];
        }
    }
    
    if (!empty($row)) $keyboard['inline_keyboard'][] = $row;
    
    bot('sendVideo', [
        'chat_id' => $chatId,
        'video' => $fileId,
        'caption' => "🎬 {$anime['name']}\n📹 $episodeNum/$totalEpisodes",
        'reply_markup' => $keyboard
    ]);
}
