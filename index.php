<?php
// MHCN - MadHatChatNet
// Version 1.0
// Developed with PHP, HTML, CSS and JS

// Initial settings
session_start();
error_reporting(0);
header('Content-Type: text/html; charset=utf-8');
ob_start();

// Constants
define('MHCN_VERSION', '1.0');
define('ACCOUNTS_DIR', 'accounts');
define('CHATS_DIR', 'chats');
define('USERFILES_DIR', 'userfiles');
define('MAX_FILE_SIZE', 1048576); // 1MB
define('MESSAGE_LIFETIME', 604800); // 1 week in seconds
define('ACCOUNT_CREATION_COOLDOWN', 259200); // 72 hours in seconds
define('LOGIN_ATTEMPT_WINDOW', 14400); // 4 hours in seconds
define('MAX_LOGIN_ATTEMPTS', 3);

// Create directories if they don't exist
if (!file_exists(ACCOUNTS_DIR)) mkdir(ACCOUNTS_DIR, 0755, true);
if (!file_exists(CHATS_DIR)) mkdir(CHATS_DIR, 0755, true);
if (!file_exists(USERFILES_DIR)) mkdir(USERFILES_DIR, 0755, true);

// Security functions
function sanitizeInput($input) {
    return htmlspecialchars(strip_tags(trim($input)), ENT_QUOTES, 'UTF-8');
}

// Strict sanitization for text messages
function sanitizeMessageContent($input) {
    // Remove HTML tags and dangerous entities
    $input = strip_tags($input);
    // Remove dangerous HTML entities
    $input = preg_replace('/&(?![a-zA-Z0-9#]{2,7};)/', '&amp;', $input);
    // Remove any attempt of script, style, iframe, etc
    $input = preg_replace('/<(script|style|iframe|object|embed|applet|meta|link|base|form|input|button|textarea|select|option|svg|math)[^>]*?>.*?<\/\1>/is', '', $input);
    // Remove on* attributes (onerror, onclick, etc)
    $input = preg_replace('/on[a-z]+\s*=\s*(["\"]).*?\1/i', '', $input);
    // Remove javascript: and data: in links
    $input = preg_replace('/(javascript:|data:)/i', '', $input);
    // Limit max message size
    $input = substr($input, 0, 2000);
    // Remove extra spaces
    $input = trim($input);
    return $input;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validateCSRFToken($token) {
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// Encryption functions
function encryptData($data, $key) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length('aes-256-cbc'));
    $encrypted = openssl_encrypt($data, 'aes-256-cbc', $key, 0, $iv);
    return base64_encode($encrypted . '::' . $iv);
}

function decryptData($data, $key) {
    list($encrypted_data, $iv) = explode('::', base64_decode($data), 2);
    return openssl_decrypt($encrypted_data, 'aes-256-cbc', $key, 0, $iv);
}

// File functions
function saveUserData($username, $data) {
    $filename = ACCOUNTS_DIR . '/' . sanitizeFileName($username) . '.json';
    file_put_contents($filename, json_encode($data, JSON_PRETTY_PRINT));
}

function loadUserData($username) {
    $filename = ACCOUNTS_DIR . '/' . sanitizeFileName($username) . '.json';
    if (file_exists($filename)) {
        return json_decode(file_get_contents($filename), true);
    }
    return null;
}

function sanitizeFileName($name) {
    return preg_replace('/[^a-zA-Z0-9\-_]/', '', $name);
}

// Authentication functions
function canCreateAccount($ip) {
    $attemptsFile = ACCOUNTS_DIR . '/creation_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $attempts = json_decode(file_get_contents($attemptsFile), true);
    }
    
    if (isset($attempts[$ip])) {
        if (time() - $attempts[$ip]['last_attempt'] < ACCOUNT_CREATION_COOLDOWN) {
            return false;
        }
    }
    
    return true;
}

function recordAccountCreationAttempt($ip) {
    $attemptsFile = ACCOUNTS_DIR . '/creation_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $attempts = json_decode(file_get_contents($attemptsFile), true);
    }
    
    $attempts[$ip] = ['last_attempt' => time()];
    file_put_contents($attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT));
}

function canAttemptLogin($username) {
    $attemptsFile = ACCOUNTS_DIR . '/login_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $attempts = json_decode(file_get_contents($attemptsFile), true);
    }
    
    if (isset($attempts[$username])) {
        if ($attempts[$username]['count'] >= MAX_LOGIN_ATTEMPTS) {
            if (time() - $attempts[$username]['last_attempt'] < LOGIN_ATTEMPT_WINDOW) {
                return false;
            } else {
                // Reset attempts after window passes
                unset($attempts[$username]);
                file_put_contents($attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT));
                return true;
            }
        }
    }
    
    return true;
}

function recordLoginAttempt($username, $success) {
    $attemptsFile = ACCOUNTS_DIR . '/login_attempts.json';
    $attempts = [];
    
    if (file_exists($attemptsFile)) {
        $attempts = json_decode(file_get_contents($attemptsFile), true);
    }
    
    if ($success) {
        if (isset($attempts[$username])) {
            unset($attempts[$username]);
        }
    } else {
        if (!isset($attempts[$username])) {
            $attempts[$username] = ['count' => 1, 'last_attempt' => time()];
        } else {
            $attempts[$username]['count']++;
            $attempts[$username]['last_attempt'] = time();
        }
    }
    
    file_put_contents($attemptsFile, json_encode($attempts, JSON_PRETTY_PRINT));
}

// Chat functions
function loadChatRoom($roomName, $encryptionKey) {
    $filename = CHATS_DIR . '/' . sanitizeFileName($roomName) . '.json';
    if (!file_exists($filename)) return null;
    
    $encryptedData = file_get_contents($filename);
    try {
        $decryptedData = decryptData($encryptedData, $encryptionKey);
        $chatData = json_decode($decryptedData, true);
        
        // Clean expired messages
        $cleanedMessages = [];
        foreach ($chatData['messages'] as $message) {
            if (time() - $message['timestamp'] < MESSAGE_LIFETIME) {
                $cleanedMessages[] = $message;
            }
        }
        
        if (count($cleanedMessages) != count($chatData['messages'])) {
            $chatData['messages'] = $cleanedMessages;
            saveChatRoom($roomName, $encryptionKey, $chatData);
        }
        
        return $chatData;
    } catch (Exception $e) {
        return null;
    }
}

function saveChatRoom($roomName, $encryptionKey, $data) {
    $filename = CHATS_DIR . '/' . sanitizeFileName($roomName) . '.json';
    $jsonData = json_encode($data, JSON_PRETTY_PRINT);
    $encryptedData = encryptData($jsonData, $encryptionKey);
    file_put_contents($filename, $encryptedData);
}

function addMessageToChat($roomName, $encryptionKey, $message, $username) {
    $chatData = loadChatRoom($roomName, $encryptionKey);
    if (!$chatData) return false;
    
    $newMessage = [
        'id' => uniqid(),
        'sender' => $username,
        'content' => $message['content'],
        'type' => $message['type'],
        'timestamp' => time(),
        'file' => $message['file'] ?? null
    ];
    
    $chatData['messages'][] = $newMessage;
    saveChatRoom($roomName, $encryptionKey, $chatData);
    return true;
}

// File upload functions
function handleFileUpload($file, $type) {
    $allowedImageTypes = ['image/jpeg', 'image/png', 'image/jpg'];
    $allowedFileTypes = ['text/plain', 'application/pdf', 'text/csv', 
                        'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
    $allowedAudioTypes = ['audio/mpeg', 'audio/wav', 'audio/ogg'];
    
    // Check type
    $fileType = mime_content_type($file['tmp_name']);
    $extension = pathinfo($file['name'], PATHINFO_EXTENSION);
    
    if ($type === 'image' && !in_array($fileType, $allowedImageTypes)) {
        return ['success' => false, 'error' => 'Unsupported image type'];
    } elseif ($type === 'file' && !in_array($fileType, $allowedFileTypes)) {
        return ['success' => false, 'error' => 'Unsupported file type'];
    } elseif ($type === 'audio' && !in_array($fileType, $allowedAudioTypes)) {
        return ['success' => false, 'error' => 'Unsupported audio type'];
    }
    
    // Check size
    if ($file['size'] > MAX_FILE_SIZE) {
        if ($type === 'image') {
            // Try to compress image
            $compressed = compressImage($file['tmp_name']);
            if (!$compressed || filesize($compressed) > MAX_FILE_SIZE) {
                return ['success' => false, 'error' => 'Image too large after compression'];
            }
            $file['tmp_name'] = $compressed;
        } else {
            return ['success' => false, 'error' => 'File too large'];
        }
    }
    
    // Generate unique name
    $filename = uniqid() . '.' . $extension;
    $destination = USERFILES_DIR . '/' . $filename;
    
    if (move_uploaded_file($file['tmp_name'], $destination)) {
        return ['success' => true, 'filename' => $filename];
    } else {
        return ['success' => false, 'error' => 'Error moving file'];
    }
}

function compressImage($source) {
    $info = getimagesize($source);
    
    if ($info['mime'] == 'image/jpeg') {
        $image = imagecreatefromjpeg($source);
    } elseif ($info['mime'] == 'image/png') {
        $image = imagecreatefrompng($source);
    } else {
        return false;
    }
    
    $tempFile = tempnam(sys_get_temp_dir(), 'mhcn_img');
    imagejpeg($image, $tempFile, 75);
    imagedestroy($image);
    
    return $tempFile;
}

// Process actions
$action = $_POST['action'] ?? '';
$response = ['status' => 'error', 'message' => 'Invalid action'];

if ($action === 'register') {
    if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
        $response = ['status' => 'error', 'message' => 'Invalid CSRF token'];
    } elseif (!canCreateAccount($_SERVER['REMOTE_ADDR'])) {
        $response = ['status' => 'error', 'message' => 'Account creation limit reached. Please try again later.'];
    } else {
        $username = sanitizeInput($_POST['username']);
        $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
        $fullname = sanitizeInput($_POST['fullname']);
        $password = $_POST['password'];
        
        if (empty($username) || empty($email) || empty($fullname) || empty($password)) {
            $response = ['status' => 'error', 'message' => 'All fields are required'];
        } elseif (strlen($password) < 8) {
            $response = ['status' => 'error', 'message' => 'Password must be at least 8 characters'];
        } elseif (file_exists(ACCOUNTS_DIR . '/' . sanitizeFileName($username) . '.json')) {
            $response = ['status' => 'error', 'message' => 'Username already in use'];
        } else {
            $hashedPassword = password_hash($password, PASSWORD_BCRYPT);
            $userData = [
                'username' => $username,
                'email' => $email,
                'fullname' => $fullname,
                'password' => $hashedPassword,
                'created_at' => time(),
                'settings' => [
                    'theme' => 'dark',
                    'font' => 'default',
                    'optimization' => 'balanced'
                ],
                'saved_chats' => []
            ];
            
            saveUserData($username, $userData);
            recordAccountCreationAttempt($_SERVER['REMOTE_ADDR']);
            $response = ['status' => 'success', 'message' => 'Account created successfully'];
        }
    }
} elseif ($action === 'login') {
    $username = sanitizeInput($_POST['username']);
    $password = $_POST['password'];
    
    if (!canAttemptLogin($username)) {
        $response = ['status' => 'error', 'message' => 'Too many login attempts. Please try again later.'];
    } else {
        $userData = loadUserData($username);
        
        if ($userData && password_verify($password, $userData['password'])) {
            $_SESSION['user'] = $userData;
            recordLoginAttempt($username, true);
            $response = ['status' => 'success', 'message' => 'Login successful'];
        } else {
            recordLoginAttempt($username, false);
            $response = ['status' => 'error', 'message' => 'Invalid credentials'];
        }
    }
} elseif ($action === 'logout') {
    session_destroy();
    $response = ['status' => 'success', 'message' => 'Logout successful'];
} elseif ($action === 'create_chat') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $roomName = sanitizeInput($_POST['room_name']);
        $encryptionKey = $_POST['encryption_key'];
        
        if (empty($roomName) || empty($encryptionKey)) {
            $response = ['status' => 'error', 'message' => 'Room name and key are required'];
        } elseif (file_exists(CHATS_DIR . '/' . sanitizeFileName($roomName) . '.json')) {
            $response = ['status' => 'error', 'message' => 'A room with this name already exists'];
        } else {
            $chatData = [
                'name' => $roomName,
                'created_by' => $_SESSION['user']['username'],
                'created_at' => time(),
                'admins' => [$_SESSION['user']['username']],
                'moderators' => [],
                'messages' => []
            ];
            
            saveChatRoom($roomName, $encryptionKey, $chatData);
            
            // Add to user's saved rooms list
            $userData = $_SESSION['user'];
            $userData['saved_chats'][] = [
                'name' => $roomName,
                'last_accessed' => time()
            ];
            $_SESSION['user'] = $userData;
            saveUserData($userData['username'], $userData);
            
            $response = ['status' => 'success', 'message' => 'Room created successfully'];
        }
    }
} elseif ($action === 'join_chat') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $roomName = sanitizeInput($_POST['room_name']);
        $encryptionKey = $_POST['encryption_key'];
        
        $chatData = loadChatRoom($roomName, $encryptionKey);
        if (!$chatData) {
            $response = ['status' => 'error', 'message' => 'Room not found or invalid key'];
        } else {
            // Add to user's saved rooms list
            $userData = $_SESSION['user'];
            $found = false;
            
            foreach ($userData['saved_chats'] as &$chat) {
                if ($chat['name'] === $roomName) {
                    $chat['last_accessed'] = time();
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $userData['saved_chats'][] = [
                    'name' => $roomName,
                    'last_accessed' => time()
                ];
            }
            
            $_SESSION['user'] = $userData;
            saveUserData($userData['username'], $userData);
            
            $response = [
                'status' => 'success',
                'message' => 'Joined room successfully',
                'room_data' => $chatData
            ];
        }
    }
} elseif ($action === 'send_message') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $roomName = sanitizeInput($_POST['room_name']);
        $encryptionKey = $_POST['encryption_key'];
        $messageType = sanitizeInput($_POST['message_type']);
        $content = $_POST['content'];
        // Strict sanitization for text messages
        if ($messageType === 'text') {
            $content = sanitizeMessageContent($content);
            if ($content === '' || empty($content)) {
                $response = ['status' => 'error', 'message' => 'Empty or invalid text message.'];
                echo json_encode($response);
                exit;
            }
        }
        $message = [
            'type' => $messageType,
            'content' => $content
        ];
        
        if ($messageType === 'file' || $messageType === 'image' || $messageType === 'audio') {
            if (isset($_FILES['file']) && $_FILES['file']['size'] > 0) {
                $uploadResult = handleFileUpload($_FILES['file'], $messageType);
                if (!$uploadResult['success']) {
                    $response = ['status' => 'error', 'message' => $uploadResult['error']];
                    echo json_encode($response);
                    exit;
                }
                $message['file'] = $uploadResult['filename'];
            } else {
                // If no file, treat as text
                $message['type'] = 'text';
                unset($message['file']);
            }
        }
        
        if (addMessageToChat($roomName, $encryptionKey, $message, $_SESSION['user']['username'])) {
            $response = ['status' => 'success', 'message' => 'Message sent'];
        } else {
            $response = ['status' => 'error', 'message' => 'Error sending message'];
        }
    }
} elseif ($action === 'get_messages') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $roomName = sanitizeInput($_POST['room_name']);
        $encryptionKey = $_POST['encryption_key'];
        
        $chatData = loadChatRoom($roomName, $encryptionKey);
        if (!$chatData) {
            $response = ['status' => 'error', 'message' => 'Room not found or invalid key'];
        } else {
            $response = [
                'status' => 'success',
                'messages' => $chatData['messages']
            ];
        }
    }
} elseif ($action === 'update_settings') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $settings = $_POST['settings'];
        $userData = $_SESSION['user'];
        
        if (isset($settings['theme'])) $userData['settings']['theme'] = sanitizeInput($settings['theme']);
        if (isset($settings['font'])) $userData['settings']['font'] = sanitizeInput($settings['font']);
        if (isset($settings['optimization'])) $userData['settings']['optimization'] = sanitizeInput($settings['optimization']);
        
        $_SESSION['user'] = $userData;
        saveUserData($userData['username'], $userData);
        
        $response = ['status' => 'success', 'message' => 'Settings updated'];
    }
} elseif ($action === 'change_password') {
    if (!isset($_SESSION['user'])) {
        $response = ['status' => 'error', 'message' => 'Not authenticated'];
    } else {
        $currentPassword = $_POST['current_password'];
        $newPassword = $_POST['new_password'];
        $userData = $_SESSION['user'];
        
        if (!password_verify($currentPassword, $userData['password'])) {
            $response = ['status' => 'error', 'message' => 'Current password incorrect'];
        } elseif (strlen($newPassword) < 8) {
            $response = ['status' => 'error', 'message' => 'The new password must be at least 8 characters'];
        } else {
            $userData['password'] = password_hash($newPassword, PASSWORD_BCRYPT);
            $_SESSION['user'] = $userData;
            saveUserData($userData['username'], $userData);
            $response = ['status' => 'success', 'message' => 'Password changed successfully'];
        }
    }
}

// If it's an AJAX request, return JSON
if (!empty($action)) {
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

// User Interface
?>
<!DOCTYPE html>
<html lang="en" class="<?php echo isset($_SESSION['user']) ? $_SESSION['user']['settings']['theme'] : 'dark'; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MadHatChatNet (MHCN)</title>
    <script src="tailwind.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        :root {
            --primary: #6d28d9;
            --primary-dark: #5b21b6;
            --secondary: #f59e0b;
            --dark: #1e293b;
            --darker: #0f172a;
            --light: #f8fafc;
            --gray: #94a3b8;
        }
        
        .dark {
            --bg: var(--darker);
            --text: var(--light);
            --bg-secondary: var(--dark);
            --border: #334155;
        }
        
        .light {
            --bg: #f1f5f9;
            --text: var(--darker);
            --bg-secondary: white;
            --border: #e2e8f0;
        }
        
        body {
            background-color: var(--bg);
            color: var(--text);
            transition: background-color 0.3s, color 0.3s;
        }
        
        .bg-primary {
            background-color: var(--primary);
        }
        
        .bg-secondary {
            background-color: var(--bg-secondary);
        }
        
        .text-primary {
            color: var(--primary);
        }
        
        .border-primary {
            border-color: var(--primary);
        }
        
        .border-secondary {
            border-color: var(--border);
        }
        
        .btn-primary {
            background-color: var(--primary);
            color: white;
        }
        
        .btn-primary:hover {
            background-color: var(--primary-dark);
        }
        
        .chat-bubble {
            display: inline-block;
            min-width: 0;
            max-width: 80%;
            word-wrap: break-word;
            min-height: 0;
            height: auto;
            vertical-align: bottom;
        }
        
        .markdown-bold {
            font-weight: bold;
        }
        
        .markdown-italic {
            font-style: italic;
        }
        
        .markdown-code {
            font-family: monospace;
            background-color: rgba(0,0,0,0.1);
            padding: 0.2em 0.4em;
            border-radius: 0.25rem;
        }
        
        .markdown-link {
            color: var(--primary);
            text-decoration: underline;
        }
        
        /* Animations */
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .animate-fade-in {
            animation: fadeIn 0.3s ease-out forwards;
        }
        
        /* Custom Scrollbar */
        ::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        
        ::-webkit-scrollbar-track {
            background: var(--bg-secondary);
        }
        
        ::-webkit-scrollbar-thumb {
            background: var(--primary);
            border-radius: 4px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: var(--primary-dark);
        }
        
        /* Glow effect for gamer elements */
        .glow-effect {
            box-shadow: 0 0 10px rgba(109, 40, 217, 0.5);
        }
        
        .glow-effect:hover {
            box-shadow: 0 0 15px rgba(109, 40, 217, 0.7);
        }
        
        /* Gradient effect for buttons */
        .gradient-btn {
            background: linear-gradient(135deg, var(--primary) 0%, #8b5cf6 100%);
        }
        
        .gradient-btn:hover {
            background: linear-gradient(135deg, var(--primary-dark) 0%, #7c3aed 100%);
        }
        
        /* Custom fonts */
        .font-default {
            font-family: 'Inter', sans-serif;
        }
        
        .font-gamer {
            font-family: 'Courier New', monospace;
        }
        
        .font-modern {
            font-family: 'Segoe UI', sans-serif;
        }
        
        .chat-bubble-text {
            display: inline-block;
            min-width: 0;
            max-width: 80%;
            word-wrap: break-word;
            min-height: 0;
            height: auto;
            vertical-align: bottom;
        }
    </style>
</head>
<body class="min-h-screen font-default flex flex-col">
    <!-- Navigation Bar -->
    <nav class="bg-secondary border-b border-secondary py-4 px-6 flex justify-between items-center">
        <div class="flex items-center space-x-2">
            <i class="fas fa-hat-wizard text-2xl text-primary"></i>
            <h1 class="text-xl font-bold">MadHatChatNet <span class="text-sm text-gray-500">(MHCN)</span></h1>
        </div>
        <div id="userSection" class="flex items-center space-x-4">
            <?php if (isset($_SESSION['user'])): ?>
                <span class="hidden md:inline">Welcome, <span class="font-semibold"><?php echo htmlspecialchars($_SESSION['user']['username']); ?></span></span>
                <button onclick="toggleDropdown('userDropdown')" class="flex items-center space-x-1 focus:outline-none">
                    <div class="w-8 h-8 rounded-full bg-primary flex items-center justify-center text-white">
                        <?php echo strtoupper(substr($_SESSION['user']['username'], 0, 1)); ?>
                    </div>
                    <i class="fas fa-chevron-down text-xs"></i>
                </button>
                <div id="userDropdown" class="hidden absolute right-6 mt-12 bg-secondary border border-secondary rounded shadow-lg py-2 z-50 w-48">
                    <a href="#" onclick="showSettings()" class="block px-4 py-2 hover:bg-gray-700"><i class="fas fa-cog mr-2"></i> Settings</a>
                    <a href="#" onclick="logout()" class="block px-4 py-2 hover:bg-gray-700"><i class="fas fa-sign-out-alt mr-2"></i> Logout</a>
                </div>
            <?php else: ?>
                <button onclick="showModal('loginModal')" class="btn-primary px-4 py-2 rounded-md">Login</button>
                <button onclick="showModal('registerModal')" class="border border-primary text-primary px-4 py-2 rounded-md">Register</button>
            <?php endif; ?>
        </div>
    </nav>

    <!-- Main Content -->
    <main class="flex-1 container mx-auto p-4 md:p-6">
        <?php if (isset($_SESSION['user'])): ?>
            <!-- Main screen for logged in users -->
            <div id="mainScreen" class="grid grid-cols-1 lg:grid-cols-4 gap-6">
                <!-- Sidebar -->
                <div class="lg:col-span-1 bg-secondary rounded-lg p-4 border border-secondary">
                    <div class="flex justify-between items-center mb-4">
                        <h2 class="text-lg font-semibold">Your Rooms</h2>
                        <button onclick="showModal('createRoomModal')" class="bg-primary text-white p-2 rounded-full hover:bg-purple-700 transition">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                    
                    <div class="space-y-2 max-h-96 overflow-y-auto" id="savedRoomsList">
                        <?php 
                        if (!empty($_SESSION['user']['saved_chats'])) {
                            // Sort by last accessed
                            usort($_SESSION['user']['saved_chats'], function($a, $b) {
                                return $b['last_accessed'] <=> $a['last_accessed'];
                            });
                            
                            foreach ($_SESSION['user']['saved_chats'] as $chat): 
                        ?>
                            <div class="p-3 rounded-md hover:bg-gray-700 cursor-pointer flex justify-between items-center" 
                                 onclick="joinRoom('<?php echo htmlspecialchars($chat['name']); ?>')">
                                <span><?php echo htmlspecialchars($chat['name']); ?></span>
                                <i class="fas fa-chevron-right text-xs text-gray-400"></i>
                            </div>
                        <?php 
                            endforeach;
                        } else {
                            echo '<p class="text-gray-400 text-sm">No saved rooms. Create or join a room to get started.</p>';
                        }
                        ?>
                    </div>
                    
                    <div class="mt-6">
                        <button onclick="showModal('joinRoomModal')" class="w-full gradient-btn text-white py-2 rounded-md flex items-center justify-center space-x-2">
                            <i class="fas fa-door-open"></i>
                            <span>Join a Room</span>
                        </button>
                    </div>
                </div>
                
                <!-- Content Area -->
                <div class="lg:col-span-3">
                    <div id="welcomePanel" class="bg-secondary rounded-lg p-6 border border-secondary flex flex-col items-center justify-center h-96">
                        <i class="fas fa-comments text-5xl text-primary mb-4"></i>
                        <h2 class="text-2xl font-bold mb-2">Welcome to MHCN</h2>
                        <p class="text-gray-400 mb-6 text-center">Select an existing room or create a new one to start chatting</p>
                        <div class="flex space-x-4">
                            <button onclick="showModal('createRoomModal')" class="gradient-btn text-white px-6 py-2 rounded-md flex items-center space-x-2">
                                <i class="fas fa-plus"></i>
                                <span>Create Room</span>
                            </button>
                            <button onclick="showModal('joinRoomModal')" class="border border-primary text-primary px-6 py-2 rounded-md flex items-center space-x-2">
                                <i class="fas fa-door-open"></i>
                                <span>Join Room</span>
                            </button>
                        </div>
                    </div>
                    
                    <div id="chatRoomPanel" class="hidden bg-secondary rounded-lg border border-secondary flex flex-col" style="min-height: 600px;">
                        <div class="border-b border-secondary p-4 flex justify-between items-center">
                            <h2 id="currentRoomName" class="text-lg font-semibold"></h2>
                            <div class="flex space-x-2">
                                <button id="roomSettingsBtn" class="p-2 rounded-full hover:bg-gray-700" title="Room Settings">
                                    <i class="fas fa-cog"></i>
                                </button>
                                <button onclick="exitRoom()" class="p-2 rounded-full hover:bg-gray-700" title="Leave Room">
                                    <i class="fas fa-times"></i>
                                </button>
                            </div>
                        </div>
                        
                        <div id="chatMessages" class="flex-1 p-4 overflow-y-auto space-y-4">
                            <!-- Messages will be loaded here -->
                        </div>
                        
                        <div class="border-t border-secondary p-4">
                            <div class="flex items-center space-x-2 mb-2">
                                <button id="attachBtn" class="p-2 rounded-full hover:bg-gray-700" title="Attach file">
                                    <i class="fas fa-paperclip"></i>
                                </button>
                                <button id="cameraBtn" class="p-2 rounded-full hover:bg-gray-700" title="Take photo">
                                    <i class="fas fa-camera"></i>
                                </button>
                                <button id="pollBtn" class="p-2 rounded-full hover:bg-gray-700" title="Create poll">
                                    <i class="fas fa-poll"></i>
                                </button>
                            </div>
                            
                            <div class="flex space-x-2">
                                <input type="text" id="messageInput" placeholder="Type your message..." 
                                       class="flex-1 bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                                <button id="sendMessageBtn" class="bg-primary text-white px-4 py-2 rounded-md hover:bg-purple-700 transition">
                                    <i class="fas fa-paper-plane"></i>
                                </button>
                            </div>
                            
                            <div id="fileUploadSection" class="hidden mt-4 p-3 border border-secondary rounded-md">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="font-medium">Attach file</span>
                                    <button onclick="hideFileUpload()" class="text-gray-400 hover:text-white">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2">
                                    <label class="flex flex-col items-center p-2 border border-secondary rounded-md hover:bg-gray-700 cursor-pointer">
                                        <input type="radio" name="fileType" value="image" class="hidden" checked>
                                        <i class="fas fa-image text-2xl mb-1 text-primary"></i>
                                        <span class="text-sm">Image</span>
                                    </label>
                                    <label class="flex flex-col items-center p-2 border border-secondary rounded-md hover:bg-gray-700 cursor-pointer">
                                        <input type="radio" name="fileType" value="file" class="hidden">
                                        <i class="fas fa-file-alt text-2xl mb-1 text-primary"></i>
                                        <span class="text-sm">File</span>
                                    </label>
                                    <label class="flex flex-col items-center p-2 border border-secondary rounded-md hover:bg-gray-700 cursor-pointer">
                                        <input type="radio" name="fileType" value="audio" class="hidden">
                                        <i class="fas fa-microphone text-2xl mb-1 text-primary"></i>
                                        <span class="text-sm">Audio</span>
                                    </label>
                                </div>
                                <input type="file" id="fileInput" class="hidden">
                                <button onclick="document.getElementById('fileInput').click()" class="w-full mt-2 gradient-btn text-white py-2 rounded-md">
                                    <i class="fas fa-upload mr-2"></i>Select File
                                </button>
                            </div>
                            
                            <div id="cameraSection" class="hidden mt-4 p-3 border border-secondary rounded-md">
                                <div class="flex justify-between items-center mb-2">
                                    <span class="font-medium">Take photo</span>
                                    <button onclick="hideCameraSection()" class="text-gray-400 hover:text-white">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="w-full bg-black rounded-md overflow-hidden mb-2" style="height: 200px;">
                                    <video id="cameraPreview" autoplay playsinline class="w-full h-full object-cover"></video>
                                </div>
                                <button id="takePhotoBtn" class="w-full gradient-btn text-white py-2 rounded-md">
                                    <i class="fas fa-camera mr-2"></i>Take Photo
                                </button>
                                <canvas id="photoCanvas" class="hidden"></canvas>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Welcome screen for visitors -->
            <div class="flex flex-col items-center justify-center h-screen-minus-nav py-12 px-4 text-center">
                <div class="max-w-3xl mx-auto">
                    <div class="glow-effect p-2 inline-block rounded-full mb-6">
                        <i class="fas fa-hat-wizard text-6xl text-primary"></i>
                    </div>
                    <h1 class="text-4xl md:text-5xl font-bold mb-6">MadHatChatNet</h1>
                    <p class="text-xl text-gray-400 mb-8">A secure, private, and modern messaging app for all types of users.</p>
                    <div class="flex flex-col sm:flex-row justify-center gap-4">
                        <button onclick="showModal('registerModal')" class="gradient-btn text-white px-8 py-3 rounded-lg text-lg font-semibold hover:shadow-lg transition">
                            Create Free Account
                        </button>
                        <button onclick="showModal('loginModal')" class="border-2 border-primary text-primary px-8 py-3 rounded-lg text-lg font-semibold hover:bg-purple-900 hover:bg-opacity-20 transition">
                            I already have an account
                        </button>
                    </div>
                </div>
                
                <div class="mt-16 grid grid-cols-1 md:grid-cols-3 gap-8 max-w-5xl">
                    <div class="bg-secondary p-6 rounded-xl border border-secondary animate-fade-in">
                        <div class="text-primary text-3xl mb-4">
                            <i class="fas fa-lock"></i>
                        </div>
                        <h3 class="text-xl font-semibold mb-2">Security</h3>
                        <p class="text-gray-400">All messages are encrypted with strong keys and stored securely.</p>
                    </div>
                    <div class="bg-secondary p-6 rounded-xl border border-secondary animate-fade-in" style="animation-delay: 0.1s">
                        <div class="text-primary text-3xl mb-4">
                            <i class="fas fa-bolt"></i>
                        </div>
                        <h3 class="text-xl font-semibold mb-2">Fast</h3>
                        <p class="text-gray-400">Optimized interface for performance, even on modest devices.</p>
                    </div>
                    <div class="bg-secondary p-6 rounded-xl border border-secondary animate-fade-in" style="animation-delay: 0.2s">
                        <div class="text-primary text-3xl mb-4">
                            <i class="fas fa-paint-brush"></i>
                        </div>
                        <h3 class="text-xl font-semibold mb-2">Customizable</h3>
                        <p class="text-gray-400">Dark/light theme, fonts, and optimizations to personalize your experience.</p>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </main>

    <!-- Footer -->
    <footer class="w-full fixed bottom-0 left-0 bg-secondary bg-opacity-80 text-xs text-gray-400 text-center py-2 border-t border-secondary z-40" style="backdrop-filter: blur(2px);">
        <span>All rights reserved, MeanByte.</span>
    </footer>

    <!-- Modals -->
    <!-- Register Modal -->
    <div id="registerModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Create Account</h2>
                <button onclick="hideModal('registerModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="registerForm" class="space-y-4">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="register">
                
                <div>
                    <label for="registerUsername" class="block text-sm font-medium mb-1">Username</label>
                    <input type="text" id="registerUsername" name="username" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="registerEmail" class="block text-sm font-medium mb-1">Email</label>
                    <input type="email" id="registerEmail" name="email" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="registerFullname" class="block text-sm font-medium mb-1">Full name</label>
                    <input type="text" id="registerFullname" name="fullname" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="registerPassword" class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" id="registerPassword" name="password" required minlength="8"
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                    <p class="text-xs text-gray-400 mt-1">Password must be at least 8 characters</p>
                </div>
                
                <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                    Create Account
                </button>
            </form>
            
            <div class="mt-4 text-center text-sm">
                <span class="text-gray-400">Already have an account?</span>
                <button onclick="hideModal('registerModal'); showModal('loginModal')" class="text-primary ml-1 hover:underline">
                    Login
                </button>
            </div>
        </div>
    </div>

    <!-- Login Modal -->
    <div id="loginModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Login</h2>
                <button onclick="hideModal('loginModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="loginForm" class="space-y-4">
                <input type="hidden" name="action" value="login">
                
                <div>
                    <label for="loginUsername" class="block text-sm font-medium mb-1">Username</label>
                    <input type="text" id="loginUsername" name="username" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="loginPassword" class="block text-sm font-medium mb-1">Password</label>
                    <input type="password" id="loginPassword" name="password" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                    Login
                </button>
            </form>
            
            <div class="mt-4 text-center text-sm">
                <span class="text-gray-400">Don't have an account?</span>
                <button onclick="hideModal('loginModal'); showModal('registerModal')" class="text-primary ml-1 hover:underline">
                    Register
                </button>
            </div>
        </div>
    </div>

    <!-- Create Room Modal -->
    <div id="createRoomModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Create Room</h2>
                <button onclick="hideModal('createRoomModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="createRoomForm" class="space-y-4">
                <input type="hidden" name="action" value="create_chat">
                
                <div>
                    <label for="roomName" class="block text-sm font-medium mb-1">Room Name</label>
                    <input type="text" id="roomName" name="room_name" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="roomEncryptionKey" class="block text-sm font-medium mb-1">Encryption Key</label>
                    <input type="password" id="roomEncryptionKey" name="encryption_key" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                    <p class="text-xs text-gray-400 mt-1">This key will be required to join the room. Keep it in a safe place.</p>
                </div>
                
                <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                    Create Room
                </button>
            </form>
        </div>
    </div>

    <!-- Join Room Modal -->
    <div id="joinRoomModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Join Room</h2>
                <button onclick="hideModal('joinRoomModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="joinRoomForm" class="space-y-4">
                <input type="hidden" name="action" value="join_chat">
                
                <div>
                    <label for="joinRoomName" class="block text-sm font-medium mb-1">Room Name</label>
                    <input type="text" id="joinRoomName" name="room_name" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="joinRoomEncryptionKey" class="block text-sm font-medium mb-1">Encryption Key</label>
                    <input type="password" id="joinRoomEncryptionKey" name="encryption_key" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                    Join Room
                </button>
            </form>
        </div>
    </div>

    <!-- Settings Modal -->
    <div id="settingsModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Settings</h2>
                <button onclick="hideModal('settingsModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <div class="space-y-6">
                <div>
                    <h3 class="font-medium mb-2">Appearance</h3>
                    <div class="space-y-3">
                        <div>
                            <label class="block text-sm mb-1">Theme</label>
                            <div class="flex space-x-2">
                                <button onclick="updateSetting('theme', 'dark')" 
                                        class="flex-1 py-2 border rounded-md flex items-center justify-center space-x-2 theme-option <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['theme'] === 'dark') ? 'border-primary bg-primary bg-opacity-20' : 'border-secondary'; ?>">
                                    <i class="fas fa-moon"></i>
                                    <span>Dark</span>
                                </button>
                                <button onclick="updateSetting('theme', 'light')" 
                                        class="flex-1 py-2 border rounded-md flex items-center justify-center space-x-2 theme-option <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['theme'] === 'light') ? 'border-primary bg-primary bg-opacity-20' : 'border-secondary'; ?>">
                                    <i class="fas fa-sun"></i>
                                    <span>Light</span>
                                </button>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm mb-1">Font</label>
                            <div class="grid grid-cols-3 gap-2">
                                <button onclick="updateSetting('font', 'default')" 
                                        class="py-2 border rounded-md text-sm <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['font'] === 'default') ? 'border-primary bg-primary bg-opacity-20' : 'border-secondary'; ?>">
                                    Default
                                </button>
                                <button onclick="updateSetting('font', 'gamer')" 
                                        class="py-2 border rounded-md text-sm font-gamer <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['font'] === 'gamer') ? 'border-primary bg-primary bg-opacity-20' : 'border-secondary'; ?>">
                                    Gamer
                                </button>
                                <button onclick="updateSetting('font', 'modern')" 
                                        class="py-2 border rounded-md text-sm font-modern <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['font'] === 'modern') ? 'border-primary bg-primary bg-opacity-20' : 'border-secondary'; ?>">
                                    Modern
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-medium mb-2">Optimization</h3>
                    <div>
                        <label class="block text-sm mb-1">Performance mode</label>
                        <select id="optimizationSelect" class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                            <option value="performance" <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['optimization'] === 'performance') ? 'selected' : ''; ?>>Maximum performance</option>
                            <option value="balanced" <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['optimization'] === 'balanced') ? 'selected' : ''; ?>>Balanced</option>
                            <option value="quality" <?php echo (isset($_SESSION['user']) && $_SESSION['user']['settings']['optimization'] === 'quality') ? 'selected' : ''; ?>>Best quality</option>
                        </select>
                        <p class="text-xs text-gray-400 mt-1">Adjust to improve performance on older devices</p>
                    </div>
                </div>
                
                <div>
                    <h3 class="font-medium mb-2">Account</h3>
                    <div class="space-y-2">
                        <button onclick="showChangePassword()" class="w-full text-left py-2 px-4 rounded-md hover:bg-gray-700">
                            Change password
                        </button>
                        <button onclick="logout()" class="w-full text-left py-2 px-4 rounded-md hover:bg-gray-700 text-red-400">
                            Logout
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Change Password Modal -->
    <div id="changePasswordModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Change Password</h2>
                <button onclick="hideModal('changePasswordModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="changePasswordForm" class="space-y-4">
                <input type="hidden" name="action" value="change_password">
                
                <div>
                    <label for="currentPassword" class="block text-sm font-medium mb-1">Current password</label>
                    <input type="password" id="currentPassword" name="current_password" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div>
                    <label for="newPassword" class="block text-sm font-medium mb-1">New password</label>
                    <input type="password" id="newPassword" name="new_password" required minlength="8"
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                    <p class="text-xs text-gray-400 mt-1">Password must be at least 8 characters</p>
                </div>
                
                <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                    Change Password
                </button>
            </form>
        </div>
    </div>

    <!-- Create Poll Modal -->
    <div id="pollModal" class="hidden fixed inset-0 bg-black bg-opacity-50 flex items-center justify-center z-50 p-4">
        <div class="bg-secondary rounded-lg max-w-md w-full p-6 border border-secondary animate-fade-in">
            <div class="flex justify-between items-center mb-4">
                <h2 class="text-xl font-semibold">Create Poll</h2>
                <button onclick="hideModal('pollModal')" class="text-gray-400 hover:text-white">
                    <i class="fas fa-times"></i>
                </button>
            </div>
            
            <form id="pollForm" class="space-y-4">
                <div>
                    <label for="pollQuestion" class="block text-sm font-medium mb-1">Question</label>
                    <input type="text" id="pollQuestion" required 
                           class="w-full bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary">
                </div>
                
                <div id="pollOptionsContainer">
                    <label class="block text-sm font-medium mb-1">Options</label>
                    <div class="space-y-2">
                        <div class="flex items-center space-x-2">
                            <input type="text" class="flex-1 bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary" placeholder="Option 1">
                            <button type="button" class="text-red-400" onclick="removePollOption(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                        <div class="flex items-center space-x-2">
                            <input type="text" class="flex-1 bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary" placeholder="Option 2">
                            <button type="button" class="text-red-400" onclick="removePollOption(this)">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <button type="button" onclick="addPollOption()" class="text-primary text-sm flex items-center">
                    <i class="fas fa-plus mr-1"></i> Add option
                </button>
                
                <div class="pt-2">
                    <button type="submit" class="w-full gradient-btn text-white py-2 rounded-md">
                        Create Poll
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Scripts -->
    <script>
        // Global variables
        let currentRoom = null;
        let currentEncryptionKey = null;
        let messageInterval = null;
        let stream = null;
        
        // UI functions
        function showModal(modalId) {
            document.getElementById(modalId).classList.remove('hidden');
            document.body.classList.add('overflow-hidden');
        }
        
        function hideModal(modalId) {
            document.getElementById(modalId).classList.add('hidden');
            document.body.classList.remove('overflow-hidden');
        }
        
        function toggleDropdown(dropdownId) {
            const dropdown = document.getElementById(dropdownId);
            dropdown.classList.toggle('hidden');
            
            // Close other dropdowns
            document.querySelectorAll('.dropdown').forEach(d => {
                if (d.id !== dropdownId) d.classList.add('hidden');
            });
        }
        
        function showSettings() {
            hideAllModals();
            showModal('settingsModal');
        }
        
        function showChangePassword() {
            hideModal('settingsModal');
            showModal('changePasswordModal');
        }
        
        function hideAllModals() {
            document.querySelectorAll('.modal').forEach(modal => {
                modal.classList.add('hidden');
            });
            document.body.classList.remove('overflow-hidden');
        }
        
        // Close modals when clicking outside
        document.querySelectorAll('.modal').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    hideModal(this.id);
                }
            });
        });
        
        // Close dropdowns when clicking outside
        document.addEventListener('click', function(e) {
            if (!e.target.closest('.dropdown') && !e.target.closest('[onclick*="toggleDropdown"]')) {
                document.querySelectorAll('.dropdown').forEach(d => d.classList.add('hidden'));
            }
        });
        
        // Authentication functions
        async function register(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert(data.message);
                    hideModal('registerModal');
                    showModal('loginModal');
                } else {
                    alert(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to register. Please try again.');
            }
        }
        
        async function login(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to login. Please try again.');
            }
        }
        
        async function logout() {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: 'action=logout'
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    window.location.reload();
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to logout. Please try again.');
            }
        }
        
        // Chat functions
        async function createRoom(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert(data.message);
                    hideModal('createRoomModal');
                    window.location.reload();
                } else {
                    alert(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to create the room. Please try again.');
            }
        }
        
        async function joinRoom(roomName = null, encryptionKey = null) {
            let formData;
            if (!roomName) {
                // Called via normal form (modal)
                const form = document.getElementById('joinRoomForm');
                formData = new FormData(form);
                roomName = formData.get('room_name');
                encryptionKey = formData.get('encryption_key');
            } else {
                // Called via click on saved room
                if (!encryptionKey) {
                    encryptionKey = prompt('Enter the room encryption key:');
                    if (!encryptionKey) {
                        alert('Key required!');
                        return;
                    }
                }
                formData = new FormData();
                formData.append('action', 'join_chat');
                formData.append('room_name', roomName);
                formData.append('encryption_key', encryptionKey);
            }
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                const text = await response.text();
                // Debug: uncomment the line below to see the received response
                // console.log('Received response:', text);
                const data = JSON.parse(text);
                if (data.status === 'success') {
                    currentRoom = data.room_data.name;
                    currentEncryptionKey = encryptionKey;
                    // Show chat room
                    document.getElementById('welcomePanel').classList.add('hidden');
                    document.getElementById('chatRoomPanel').classList.remove('hidden');
                    document.getElementById('currentRoomName').textContent = currentRoom;
                    // Load messages
                    loadMessages();
                    // Start polling messages
                    if (messageInterval) clearInterval(messageInterval);
                    messageInterval = setInterval(loadMessages, 3000);
                    hideModal('joinRoomModal');
                } else {
                    alert(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to join the room. Please try again.');
            }
        }
        
        function exitRoom() {
            currentRoom = null;
            currentEncryptionKey = null;
            
            if (messageInterval) {
                clearInterval(messageInterval);
                messageInterval = null;
            }
            
            document.getElementById('chatRoomPanel').classList.add('hidden');
            document.getElementById('welcomePanel').classList.remove('hidden');
            document.getElementById('chatMessages').innerHTML = '';
        }
        
        async function loadMessages() {
            if (!currentRoom || !currentEncryptionKey) return;
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=get_messages&room_name=${encodeURIComponent(currentRoom)}&encryption_key=${encodeURIComponent(currentEncryptionKey)}`
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    const messagesContainer = document.getElementById('chatMessages');
                    messagesContainer.innerHTML = '';
                    
                    data.messages.forEach(message => {
                        const messageElement = createMessageElement(message);
                        messagesContainer.appendChild(messageElement);
                    });
                    
                    // Auto scroll to last message
                    messagesContainer.scrollTop = messagesContainer.scrollHeight;
                }
            } catch (error) {
                console.error('Error loading messages:', error);
            }
        }
        
        function createMessageElement(message) {
            // console.log('Received message:', message); // <-- Debug
            const isCurrentUser = message.sender === '<?php echo isset($_SESSION['user']) ? $_SESSION['user']['username'] : ''; ?>';
            const messageDiv = document.createElement('div');
            messageDiv.className = `flex ${isCurrentUser ? 'justify-end' : 'justify-start'}`;

            const bubbleDiv = document.createElement('div');
            bubbleDiv.className = `chat-bubble rounded-lg p-3 ${isCurrentUser ? 'bg-primary text-white' : 'bg-secondary border border-secondary'}`;

            // Message header (user and time)
            const headerDiv = document.createElement('div');
            headerDiv.className = 'flex justify-between items-center mb-1';

            const userSpan = document.createElement('span');
            userSpan.className = 'font-semibold text-sm';
            userSpan.textContent = message.sender;

            const timeSpan = document.createElement('span');
            timeSpan.className = 'text-xs opacity-70';
            timeSpan.textContent = formatTime(message.timestamp);

            headerDiv.appendChild(userSpan);
            headerDiv.appendChild(timeSpan);
            bubbleDiv.appendChild(headerDiv);

            // Message content
            const contentDiv = document.createElement('div');

            if (message.type === 'text') {
                contentDiv.innerHTML = parseMarkdown(message.content);
                bubbleDiv.classList.add('chat-bubble-text');
            } else if (message.type === 'image' && message.file) {
                // Image message
                const img = document.createElement('img');
                img.src = `userfiles/${message.file}`;
                img.className = 'max-w-full h-auto rounded-md mt-1';
                img.loading = 'lazy';
                contentDiv.appendChild(img);

                // Show caption if present and not "Photo sent"
                if (message.content && message.content !== 'Photo sent') {
                    const caption = document.createElement('div');
                    caption.className = 'text-xs mt-1 opacity-80';
                    caption.innerHTML = parseMarkdown(message.content);
                    contentDiv.appendChild(caption);
                }
            } else if (message.type === 'file' && message.file) {
                // File message
                const fileLink = document.createElement('a');
                fileLink.href = `userfiles/${message.file}`;
                fileLink.className = 'flex items-center text-primary hover:underline';
                fileLink.target = '_blank';

                const icon = document.createElement('i');
                icon.className = 'fas fa-file-download mr-2';

                fileLink.appendChild(icon);
                fileLink.appendChild(document.createTextNode('Download file'));
                contentDiv.appendChild(fileLink);
            } else if (message.type === 'audio' && message.file) {
                // Audio message
                const audio = document.createElement('audio');
                audio.src = `userfiles/${message.file}`;
                audio.controls = true;
                audio.className = 'w-full mt-1';
                contentDiv.appendChild(audio);
            } else {
                // Unrecognized, show as text
                contentDiv.innerHTML = parseMarkdown(message.content);
            }

            bubbleDiv.appendChild(contentDiv);
            messageDiv.appendChild(bubbleDiv);

            return messageDiv;
        }
        
        function formatTime(timestamp) {
            const date = new Date(timestamp * 1000);
            return date.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
        }
        
        function parseMarkdown(text) {
            // Simple Markdown parser with limitations
            return text
                .replace(/\*\*(.*?)\*\*/g, '<span class="markdown-bold">$1</span>')
                .replace(/\*(.*?)\*/g, '<span class="markdown-italic">$1</span>')
                .replace(/`(.*?)`/g, '<span class="markdown-code">$1</span>')
                .replace(/\[(.*?)\]\((.*?)\)/g, '<a href="$2" class="markdown-link" target="_blank">$1</a>');
        }
        
        async function sendMessage() {
            const messageInput = document.getElementById('messageInput');
            const message = messageInput.value.trim();
            const fileInput = document.getElementById('fileInput');
            const canvas = document.getElementById('photoCanvas');
            const hasPhoto = canvas && canvas.width > 0 && canvas.height > 0;

            // Only send if there is text OR file OR photo
            if (!message && !fileInput.files.length && !hasPhoto) return;

            // If no file or photo, but text is empty, send nothing
            if (!fileInput.files.length && !hasPhoto && !message) return;

            try {
                const formData = new FormData();
                formData.append('action', 'send_message');
                formData.append('room_name', currentRoom);
                formData.append('encryption_key', currentEncryptionKey);

                if (fileInput.files.length) {
                    // If file, get selected type
                    const fileTypeRadios = document.getElementsByName('fileType');
                    let selectedFileType = 'image';
                    for (const radio of fileTypeRadios) {
                        if (radio.checked) {
                            selectedFileType = radio.value;
                            break;
                        }
                    }
                    formData.append('message_type', selectedFileType);
                    formData.append('content', message || 'File sent');
                    formData.append('file', fileInput.files[0]);
                } else if (hasPhoto) {
                    canvas.toBlob(function(blob) {
                        const file = new File([blob], 'photo.png', { type: 'image/png' });
                        formData.append('message_type', 'image');
                        formData.append('content', message || 'Photo sent');
                        formData.append('file', file);
                        sendFormData(formData);
                    }, 'image/png');
                    return;
                } else if (message) {
                    // If no file or photo, it's text
                    formData.append('message_type', 'text');
                    formData.append('content', message);
                    // Clear file field to avoid garbage
                    fileInput.value = '';
                } else {
                    // Do not send if no valid content
                    return;
                }

                await sendFormData(formData);

            } catch (error) {
                console.error('Error sending message:', error);
                alert('An error occurred while sending the message. Please try again.');
            } finally {
                messageInput.value = '';
                fileInput.value = '';
                if (canvas) {
                    canvas.width = 0;
                    canvas.height = 0;
                }
                hideFileUpload();
                hideCameraSection();
            }
        }
        
        async function sendFormData(formData) {
            const response = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.status !== 'success') {
                alert(data.message);
            } else {
                loadMessages();
            }
        }
        
        // File functions
        function showFileUpload() {
            document.getElementById('fileUploadSection').classList.remove('hidden');
            document.getElementById('cameraSection').classList.add('hidden');
        }
        
        function hideFileUpload() {
            document.getElementById('fileUploadSection').classList.add('hidden');
            document.getElementById('fileInput').value = '';
        }
        
        // Camera functions
        async function showCameraSection() {
            document.getElementById('fileUploadSection').classList.add('hidden');
            document.getElementById('cameraSection').classList.remove('hidden');
            
            try {
                stream = await navigator.mediaDevices.getUserMedia({ video: true });
                document.getElementById('cameraPreview').srcObject = stream;
            } catch (error) {
                console.error('Error accessing camera:', error);
                alert('Could not access the camera. Check permissions.');
                hideCameraSection();
            }
        }
        
        function hideCameraSection() {
            document.getElementById('cameraSection').classList.add('hidden');
            
            if (stream) {
                stream.getTracks().forEach(track => track.stop());
                stream = null;
            }
        }
        
        function takePhoto() {
            const video = document.getElementById('cameraPreview');
            const canvas = document.getElementById('photoCanvas');
            const context = canvas.getContext('2d');
            
            canvas.width = video.videoWidth;
            canvas.height = video.videoHeight;
            context.drawImage(video, 0, 0, canvas.width, canvas.height);
            
            hideCameraSection();
        }
        
        // Poll functions
        function showPollModal() {
            hideAllModals();
            showModal('pollModal');
        }
        
        function addPollOption() {
            const optionsContainer = document.getElementById('pollOptionsContainer').querySelector('.space-y-2');
            const optionCount = optionsContainer.children.length;
            
            if (optionCount >= 10) {
                alert('Maximum of 10 options allowed');
                return;
            }
            
            const optionDiv = document.createElement('div');
            optionDiv.className = 'flex items-center space-x-2';
            
            const input = document.createElement('input');
            input.type = 'text';
            input.className = 'flex-1 bg-secondary border border-secondary rounded-md px-4 py-2 focus:outline-none focus:border-primary';
            input.placeholder = `Option ${optionCount + 1}`;
            
            const removeBtn = document.createElement('button');
            removeBtn.type = 'button';
            removeBtn.className = 'text-red-400';
            removeBtn.innerHTML = '<i class="fas fa-times"></i>';
            removeBtn.onclick = function() { removePollOption(this); };
            
            optionDiv.appendChild(input);
            optionDiv.appendChild(removeBtn);
            optionsContainer.appendChild(optionDiv);
        }
        
        function removePollOption(button) {
            const optionsContainer = document.getElementById('pollOptionsContainer').querySelector('.space-y-2');
            if (optionsContainer.children.length > 2) {
                button.parentElement.remove();
            } else {
                alert('The poll must have at least 2 options');
            }
        }
        
        async function createPoll(e) {
            e.preventDefault();
            
            const question = document.getElementById('pollQuestion').value.trim();
            if (!question) {
                alert('Enter the poll question');
                return;
            }
            
            const options = [];
            const inputs = document.querySelectorAll('#pollOptionsContainer input[type="text"]');
            inputs.forEach(input => {
                const value = input.value.trim();
                if (value) options.push(value);
            });
            
            if (options.length < 2) {
                alert('The poll must have at least 2 options');
                return;
            }
            
            const pollMessage = `📊 *${question}*\n\n${options.map((opt, i) => `${i+1}. ${opt}`).join('\n')}`;
            document.getElementById('messageInput').value = pollMessage;
            
            hideModal('pollModal');
        }
        
        // Settings functions
        async function updateSetting(key, value) {
            try {
                const response = await fetch('', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=update_settings&settings[${key}]=${value}`
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    if (key === 'theme') {
                        document.documentElement.className = value;
                        document.querySelectorAll('.theme-option').forEach(opt => {
                            opt.classList.remove('border-primary', 'bg-primary', 'bg-opacity-20');
                            if (opt.querySelector('input').value === value) {
                                opt.classList.add('border-primary', 'bg-primary', 'bg-opacity-20');
                            }
                        });
                    } else if (key === 'font') {
                        document.body.className = document.body.className.replace(/(^|\s)font-\S+/g, '');
                        document.body.classList.add(`font-${value}`);
                    }
                }
            } catch (error) {
                console.error('Error updating setting:', error);
            }
        }
        
        async function changePassword(e) {
            e.preventDefault();
            const form = e.target;
            const formData = new FormData(form);
            
            try {
                const response = await fetch('', {
                    method: 'POST',
                    body: formData
                });
                
                const data = await response.json();
                
                if (data.status === 'success') {
                    alert(data.message);
                    hideModal('changePasswordModal');
                } else {
                    alert(data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('An error occurred while trying to change the password. Please try again.');
            }
        }
        
        // Event Listeners
        document.addEventListener('DOMContentLoaded', function() {
            // Forms
            document.getElementById('registerForm')?.addEventListener('submit', register);
            document.getElementById('loginForm')?.addEventListener('submit', login);
            document.getElementById('createRoomForm')?.addEventListener('submit', createRoom);
            document.getElementById('joinRoomForm')?.addEventListener('submit', function(e) {
                e.preventDefault();
                joinRoom();
            });
            document.getElementById('changePasswordForm')?.addEventListener('submit', changePassword);
            document.getElementById('pollForm')?.addEventListener('submit', createPoll);
            
            // Chat buttons
            document.getElementById('sendMessageBtn')?.addEventListener('click', sendMessage);
            document.getElementById('messageInput')?.addEventListener('keypress', function(e) {
                if (e.key === 'Enter') sendMessage();
            });
            
            // File buttons
            document.getElementById('attachBtn')?.addEventListener('click', showFileUpload);
            document.getElementById('cameraBtn')?.addEventListener('click', showCameraSection);
            document.getElementById('pollBtn')?.addEventListener('click', showPollModal);
            document.getElementById('takePhotoBtn')?.addEventListener('click', takePhoto);
            
            // Settings
            document.getElementById('optimizationSelect')?.addEventListener('change', function() {
                updateSetting('optimization', this.value);
            });
            
            // Check for room parameters in URL
            const urlParams = new URLSearchParams(window.location.search);
            const roomParam = urlParams.get('room');
            const keyParam = urlParams.get('key');
            
            if (roomParam && keyParam && <?php echo isset($_SESSION['user']) ? 'true' : 'false'; ?>) {
                joinRoom(roomParam, keyParam);
            }
        });
    </script>
</body>
</html>
<?php ob_end_flush(); ?>