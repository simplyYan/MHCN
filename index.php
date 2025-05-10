<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// ========== BACKEND PHP ==========

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    function sendJsonResponse($data) {
        ob_clean();
        echo json_encode($data);
        exit;
    }

    $action = $_POST['action'] ?? '';
    $roomname = $_POST['roomname'] ?? '';
    $key = $_POST['key'] ?? '';
    $chatsDir = __DIR__ . '/chats';
    if (!is_dir($chatsDir)) mkdir($chatsDir, 0777, true);

    // Helper: safe room name
    function safe_roomname($name) {
        return preg_match('/^[a-zA-Z0-9_-]+$/', $name) ? $name : false;
    }

    // Helper: encryption
    function encrypt_json_php($json, $key) {
        $iv = random_bytes(12);
        $key_bin = hash_pbkdf2('sha256', $key, 'mhcn_salt', 100000, 32, true);
        $cipher = openssl_encrypt(json_encode($json), 'aes-256-gcm', $key_bin, OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv) . ':' . base64_encode($cipher . $tag);
    }
    function decrypt_json_php($cipher, $key) {
        $parts = explode(':', $cipher);
        if (count($parts) !== 2) return null;
        $iv = base64_decode($parts[0]);
        $data = base64_decode($parts[1]);
        $ciphertext = substr($data, 0, -16);
        $tag = substr($data, -16);
        $key_bin = hash_pbkdf2('sha256', $key, 'mhcn_salt', 100000, 32, true);
        $json = openssl_decrypt($ciphertext, 'aes-256-gcm', $key_bin, OPENSSL_RAW_DATA, $iv, $tag);
        return $json ? json_decode($json, true) : null;
    }

    // ========== CREATE CHATROOM ==========
    if ($action === 'create_room') {
        if (!($room = safe_roomname($roomname))) {
            sendJsonResponse(['success'=>false, 'error'=>'Invalid chatroom name.']);
        }
        $file = "$chatsDir/$room.json";
        if (file_exists($file)) {
            sendJsonResponse(['success'=>false, 'error'=>'Chatroom already exists.']);
        }
        $init = [];
        $cipher = encrypt_json_php($init, $key);
        file_put_contents($file, $cipher);
        sendJsonResponse(['success'=>true]);
    }

    // ========== GET CHATROOM ==========
    if ($action === 'get_room') {
        if (!($room = safe_roomname($roomname))) {
            sendJsonResponse(['success'=>false, 'error'=>'Invalid chatroom name.']);
        }
        $file = "$chatsDir/$room.json";
        if (!file_exists($file)) {
            sendJsonResponse(['success'=>false, 'error'=>'Chatroom not found.']);
        }
        $cipher = file_get_contents($file);
        sendJsonResponse(['success'=>true, 'cipher'=>$cipher]);
    }

    // ========== SEND MESSAGE ==========
    if ($action === 'send_message') {
        $message = json_decode($_POST['message'] ?? '', true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            sendJsonResponse(['success'=>false, 'error'=>'Invalid message.']);
        }
        if (!($room = safe_roomname($roomname))) {
            sendJsonResponse(['success'=>false, 'error'=>'Invalid chatroom name.']);
        }
        $file = "$chatsDir/$room.json";
        if (!file_exists($file)) {
            sendJsonResponse(['success'=>false, 'error'=>'Chatroom not found.']);
        }
        $cipher = file_get_contents($file);
        $messages = decrypt_json_php($cipher, $key);
        if (!is_array($messages)) $messages = [];
        // Limit message size
        if (isset($message['type']) && $message['type'] === 'text') {
            $message['text'] = substr($message['text'], 0, 500);
        }
        if (isset($message['type']) && $message['type'] === 'svg') {
            // Limit SVG to 100kb
            if (strlen($message['svg']) > 100*1024) {
                sendJsonResponse(['success'=>false, 'error'=>'SVG file too large.']);
            }
        }
        if (isset($message['type']) && $message['type'] === '__replace__' && isset($message['data']) && is_array($message['data'])) {
            $newCipher = encrypt_json_php($message['data'], $key);
            file_put_contents($file, $newCipher);
            sendJsonResponse(['success'=>true]);
        }
        $messages[] = $message;
        $newCipher = encrypt_json_php($messages, $key);
        file_put_contents('php://stderr', "DEBUG: Reached here\n", FILE_APPEND);
        file_put_contents($file, $newCipher);
        sendJsonResponse(['success'=>true]);
    }

    // ========== DEFAULT ==========
    sendJsonResponse(['success'=>false, 'error'=>'Invalid action.']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>madhatchatnet</title>
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap 5 CDN -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <style id="custom-theme-style">
        body {
            background: #181a1b;
            color: #f1f1f1;
            min-height: 100vh;
        }
        .mhcn-card {
            background: #23272b;
            border-radius: 1rem;
            box-shadow: 0 2px 16px #000a;
        }
        .mhcn-btn {
            background: #6f42c1;
            color: #fff;
            border: none;
        }
        .mhcn-btn:hover {
            background: #5936a8;
        }
        .chatroom-list-item {
            background: #23272b;
            border: 1px solid #343a40;
            border-radius: .5rem;
            margin-bottom: .5rem;
            padding: .75rem 1rem;
            cursor: pointer;
            transition: background .2s;
        }
        .chatroom-list-item:hover {
            background: #343a40;
        }
        .chat-messages {
            max-height: 60vh;
            overflow-y: auto;
            padding-bottom: 1rem;
        }
        .chat-message {
            margin-bottom: 1.2rem;
        }
        .chat-message .author {
            font-weight: bold;
            color: #b983ff;
        }
        .chat-message .timestamp {
            font-size: .85em;
            color: #aaa;
        }
        .chat-message .text {
            margin-top: .2rem;
            word-break: break-word;
        }
        .chat-message img {
            max-width: 200px;
            max-height: 200px;
            display: block;
            margin-top: .5rem;
            border-radius: .5rem;
            background: #fff;
        }
        .mhcn-scrollbar::-webkit-scrollbar {
            width: 8px;
            background: #23272b;
        }
        .mhcn-scrollbar::-webkit-scrollbar-thumb {
            background: #343a40;
            border-radius: 4px;
        }
        .premium-badge {
            display: inline-block;
            background: #ffd700;
            color: #23272b;
            font-weight: bold;
            border-radius: 0.5em;
            padding: 0.1em 0.5em;
            font-size: 0.9em;
            margin-left: 0.5em;
            vertical-align: middle;
        }
        .verified-badge {
            color: #1da1f2;
            margin-left: 0.3em;
            font-size: 1em;
            vertical-align: middle;
        }
        .fixed-message {
            background: #2d2d3a;
            border-left: 4px solid #6f42c1;
            padding: 0.5em 1em;
            margin-bottom: 1em;
            border-radius: 0.5em;
            font-weight: bold;
        }
        .chat-message .format-bold { font-weight: bold; }
        .chat-message .format-italic { font-style: italic; }
        .chat-message .format-underline { text-decoration: underline; }
        @media (max-width: 600px) {
            .mhcn-card {
                border-radius: 0;
                box-shadow: none;
            }
            .chat-messages {
                max-height: 40vh;
            }
        }
        /* Toast for premium offer */
        .mhcn-premium-toast {
            position: fixed;
            bottom: 2em;
            left: 50%;
            transform: translateX(-50%);
            background: #ffd700;
            color: #23272b;
            padding: 1em 2em;
            border-radius: 1em;
            z-index: 99999;
            font-weight: bold;
            box-shadow: 0 2px 16px #000a;
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.7em;
            font-size: 1.05em;
        }
        .mhcn-premium-toast .mhcn-premium-star {
            color: #ff9800;
            font-size: 1.3em;
        }
        .mhcn-premium-toast .mhcn-premium-link {
            color: #6f42c1;
            text-decoration: underline;
            font-weight: bold;
            margin-left: 0.5em;
        }
    </style>
</head>
<body>
<div class="container py-4">
    <div id="main-card" class="mhcn-card p-4 mx-auto" style="max-width: 480px;">
        <div class="d-flex align-items-center justify-content-between mb-2">
            <h2 class="text-center mb-0 flex-grow-1" style="font-family: monospace; letter-spacing: 2px;">madhatchatnet</h2>
            <button class="btn btn-warning btn-sm ms-2" id="premium-btn" title="Enable Premium"><i class="bi bi-star-fill"></i></button>
        </div>
        <div id="app-content"></div>
    </div>
</div>

<!-- Premium Modal (hidden, for custom CSS/theme) -->
<div id="premium-theme-modal" style="display:none; position:fixed; z-index:9999; left:0; top:0; width:100vw; height:100vh; background:rgba(0,0,0,0.7);">
    <div style="background:#23272b; color:#fff; max-width:400px; margin:5vh auto; padding:2em; border-radius:1em; position:relative;">
        <button id="close-theme-modal" style="position:absolute; right:1em; top:1em; background:none; border:none; color:#fff; font-size:1.5em;">&times;</button>
        <h5>Custom Theme (CSS)</h5>
        <textarea id="custom-css-input" style="width:100%;height:120px;resize:vertical;" placeholder="Type your custom CSS here..."></textarea>
        <div class="mt-2 d-flex justify-content-end">
            <button class="btn btn-sm btn-success me-2" id="save-theme-btn">Save</button>
            <button class="btn btn-sm btn-secondary" id="reset-theme-btn">Reset</button>
        </div>
    </div>
</div>

<script>
// ========== CONFIG ==========
const API_URL = ""; // PHP is on the same page

// Premium state
let isPremium = false;
let premiumKey = null;
let premiumFeatures = {
    audio: true,
    files: true,
    customTheme: true,
    deleteMessage: true,
    autoMessages: true,
    polls: true,
    verifiedBadge: true,
    pinMessage: true,
    formatting: true,
    pushNotifications: true
};
let userCustomCSS = localStorage.getItem("mhcn_custom_css") || "";

// ========== PREMIUM LOGIC ==========

function enablePremium(apiKey) {
    const url = `https://mhcn.42web.io/use_api.php?key=${encodeURIComponent(apiKey)}`;

    fetch(url)
        .then(response => response.json())
        .then(data => {
            if (data.success === true) {
                isPremium = true;
                premiumKey = apiKey;
                localStorage.setItem("mhcn_premium", "1");
                localStorage.setItem("mhcn_premium_key", apiKey);
                showPremiumToast("Premium enabled! Enjoy all features.");
                updatePremiumUI();
            } else {
                isPremium = false;
                premiumKey = null;
                localStorage.removeItem("mhcn_premium");
                localStorage.removeItem("mhcn_premium_key");
                showPremiumToast("Invalid premium key.", true);
            }
        })
        .catch(error => {
            console.error("⚠️ Error calling the API:", error);
            showPremiumToast("Error validating premium key.", true);
        });
}

function showPremiumToast(msg, error) {
    let toast = document.createElement("div");
    toast.textContent = msg;
    toast.style.position = "fixed";
    toast.style.bottom = "2em";
    toast.style.left = "50%";
    toast.style.transform = "translateX(-50%)";
    toast.style.background = error ? "#dc3545" : "#6f42c1";
    toast.style.color = "#fff";
    toast.style.padding = "1em 2em";
    toast.style.borderRadius = "1em";
    toast.style.zIndex = 99999;
    toast.style.fontWeight = "bold";
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 2500);
}

function updatePremiumUI() {
    // Add badge to header if premium
    let btn = document.getElementById("premium-btn");
    if (isPremium) {
        btn.innerHTML = '<i class="bi bi-star-fill"></i> <span class="premium-badge">Premium</span>';
        btn.classList.remove("btn-warning");
        btn.classList.add("btn-success");
        btn.title = "Premium enabled";
    } else {
        btn.innerHTML = '<i class="bi bi-star-fill"></i>';
        btn.classList.remove("btn-success");
        btn.classList.add("btn-warning");
        btn.title = "Enable Premium";
    }
}

// ========== PREMIUM OFFER TOAST & PUSH ==========

const PREMIUM_OFFER_URL = "https://mhcn.42web.io/buy.php";
const PREMIUM_OFFER_INTERVAL = 3 * 60 * 60 * 1000; // 3 hours in ms

function showPremiumOfferToast() {
    // Remove any existing
    document.querySelectorAll('.mhcn-premium-toast').forEach(e => e.remove());
    let toast = document.createElement("div");
    toast.className = "mhcn-premium-toast";
    toast.innerHTML = `
        <span class="mhcn-premium-star">&#11088;</span>
        <span>
            Go <b>premium</b>! Help keep the app alive and unlock audios, files, polls, a customizable theme, bots, a verified seal and message pinning.
            <span class="mhcn-premium-link">Read more</span>
        </span>
    `;
    toast.onclick = () => {
        window.open(PREMIUM_OFFER_URL, "_blank");
    };
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 12000);
}

function sendPremiumPush() {
    if (!("Notification" in window)) return;
    if (Notification.permission === "granted") {
        let n = new Notification("Go Premium on madhatchatnet!", {
            body: "Help keep the app alive and unlock audios, files, polls, a customizable theme, bots, a verified seal and message pinning.",
            icon: "https://mhcn.42web.io/favicon.ico",
            tag: "mhcn-premium-offer"
        });
        n.onclick = function(e) {
            window.open(PREMIUM_OFFER_URL, "_blank");
            n.close();
        };
    } else if (Notification.permission !== "denied") {
        Notification.requestPermission().then(function(permission) {
            if (permission === "granted") {
                sendPremiumPush();
            }
        });
    }
}

function maybeShowPremiumOffer() {
    if (isPremium) return; // Não mostrar para premium
    let last = parseInt(localStorage.getItem("mhcn_premium_offer_last") || "0", 10);
    let now = Date.now();
    if (now - last > PREMIUM_OFFER_INTERVAL) {
        showPremiumOfferToast();
        sendPremiumPush();
        localStorage.setItem("mhcn_premium_offer_last", now + "");
    }
}

// Inicializa o ciclo de oferta premium
function startPremiumOfferCycle() {
    maybeShowPremiumOffer();
    setInterval(maybeShowPremiumOffer, PREMIUM_OFFER_INTERVAL);
}
document.addEventListener("DOMContentLoaded", startPremiumOfferCycle);

// ========== UTILS ==========

// Generate SHA-256 hash (for password)
async function sha256(str) {
    const buf = await crypto.subtle.digest('SHA-256', new TextEncoder().encode(str));
    return Array.from(new Uint8Array(buf)).map(b => b.toString(16).padStart(2, '0')).join('');
}

// Generate encryption key from password
async function getKeyFromPassword(password) {
    const enc = new TextEncoder();
    const keyMaterial = await window.crypto.subtle.importKey(
        "raw", enc.encode(password), {name: "PBKDF2"}, false, ["deriveKey"]
    );
    return window.crypto.subtle.deriveKey(
        {
            name: "PBKDF2",
            salt: enc.encode("mhcn_salt"),
            iterations: 100000,
            hash: "SHA-256"
        },
        keyMaterial,
        {name: "AES-GCM", length: 256},
        false,
        ["encrypt", "decrypt"]
    );
}

// Encrypt JSON with key
async function encryptJSON(json, password) {
    const iv = window.crypto.getRandomValues(new Uint8Array(12));
    const key = await getKeyFromPassword(password);
    const enc = new TextEncoder();
    const data = enc.encode(JSON.stringify(json));
    const encrypted = await window.crypto.subtle.encrypt(
        {name: "AES-GCM", iv: iv},
        key,
        data
    );
    // Return base64(iv) + ":" + base64(cipher)
    return btoa(String.fromCharCode(...iv)) + ":" + btoa(String.fromCharCode(...new Uint8Array(encrypted)));
}

// Decrypt JSON with key
async function decryptJSON(cipher, password) {
    try {
        const [ivb64, cipherb64] = cipher.split(":");
        const iv = Uint8Array.from(atob(ivb64), c => c.charCodeAt(0));
        const data = Uint8Array.from(atob(cipherb64), c => c.charCodeAt(0));
        const key = await getKeyFromPassword(password);
        const decrypted = await window.crypto.subtle.decrypt(
            {name: "AES-GCM", iv: iv},
            key,
            data
        );
        const dec = new TextDecoder();
        return JSON.parse(dec.decode(decrypted));
    } catch (e) {
        return null;
    }
}

// ========== LOCAL STORAGE (account and saved chatrooms) ==========

function saveAccount(account) {
    localStorage.setItem("mhcn_account", JSON.stringify(account));
}
function getAccount() {
    return JSON.parse(localStorage.getItem("mhcn_account") || "null");
}
function saveChatrooms(chatrooms) {
    localStorage.setItem("mhcn_chatrooms", JSON.stringify(chatrooms));
}
function getChatrooms() {
    return JSON.parse(localStorage.getItem("mhcn_chatrooms") || "[]");
}
function addChatroomToLocal(name, key) {
    let rooms = getChatrooms();
    if (!rooms.find(r => r.name === name)) {
        rooms.push({name, key});
        saveChatrooms(rooms);
    }
}

// ========== UI RENDERING ==========

function show(content) {
    document.getElementById("app-content").innerHTML = content;
}

function showLogin() {
    show(`
        <form id="login-form" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" class="form-control" name="username" required maxlength="20">
            </div>
            <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" class="form-control" name="password" required maxlength="32">
            </div>
            <div class="mb-3">
                <label class="form-label">Date of birth</label>
                <input type="date" class="form-control" name="dob" required>
            </div>
            <button type="submit" class="btn mhcn-btn w-100">Sign in / Create Account</button>
        </form>
    `);
    document.getElementById("login-form").onsubmit = async (e) => {
        e.preventDefault();
        const username = e.target.username.value.trim();
        const password = e.target.password.value;
        const dob = e.target.dob.value;
        if (!username || !password || !dob) return;
        const hash = await sha256(password);
        saveAccount({username, password: hash, dob});
        showHome();
    };
}

function showHome() {
    const account = getAccount();
    if (!account) return showLogin();
    const chatrooms = getChatrooms();
    let chatroomList = chatrooms.length ? chatrooms.map(r => `
        <div class="chatroom-list-item" data-name="${r.name}" data-key="${r.key}">
            <span class="me-2"><i class="bi bi-chat-dots"></i></span>
            <strong>${r.name}</strong>
        </div>
    `).join('') : `<div class="text-muted">No saved chatrooms.</div>`;
    show(`
        <div class="mb-4">
            <div class="d-flex align-items-center mb-2">
                <div class="me-auto">
                    <span class="fw-bold">Welcome, ${account.username}${isPremium && premiumFeatures.verifiedBadge ? '<span class="verified-badge" title="Verified"><i class="bi bi-patch-check-fill"></i></span>' : ''}</span>
                </div>
                <button class="btn btn-sm btn-outline-light ms-2" id="logout-btn" title="Sign out"><i class="bi bi-box-arrow-right"></i></button>
            </div>
        </div>
        <div class="mb-4">
            <button class="btn mhcn-btn w-100 mb-2" id="create-room-btn">Create Chatroom</button>
            <button class="btn btn-outline-light w-100" id="join-room-btn">Join Chatroom</button>
            ${isPremium && premiumFeatures.customTheme ? '<button class="btn btn-outline-warning w-100 mt-2" id="custom-theme-btn"><i class="bi bi-palette"></i> Custom Theme</button>' : ''}
        </div>
        <div class="mb-3">
            <div class="fw-bold mb-2">Saved chatrooms</div>
            <div id="chatroom-list">${chatroomList}</div>
        </div>
    `);
    document.getElementById("logout-btn").onclick = () => {
        localStorage.removeItem("mhcn_account");
        showLogin();
    };
    document.getElementById("create-room-btn").onclick = showCreateRoom;
    document.getElementById("join-room-btn").onclick = showJoinRoom;
    document.querySelectorAll(".chatroom-list-item").forEach(el => {
        el.onclick = () => {
            showEnterRoom(el.dataset.name, el.dataset.key);
        };
    });
    if (isPremium && premiumFeatures.customTheme) {
        document.getElementById("custom-theme-btn").onclick = showCustomThemeModal;
    }
}

function showCreateRoom() {
    show(`
        <form id="create-room-form" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Chatroom name</label>
                <input type="text" class="form-control" name="roomname" required maxlength="32" pattern="[a-zA-Z0-9_-]+" placeholder="Ex: room123">
                <div class="form-text">Only letters, numbers, _ and -</div>
            </div>
            <div class="mb-3">
                <label class="form-label">Encryption key</label>
                <input type="password" class="form-control" name="key" required maxlength="32" placeholder="Set a strong key">
            </div>
            <button type="submit" class="btn mhcn-btn w-100">Create</button>
            <button type="button" class="btn btn-link w-100 mt-2" id="back-btn">Back</button>
        </form>
    `);
    document.getElementById("back-btn").onclick = showHome;
    document.getElementById("create-room-form").onsubmit = async (e) => {
        e.preventDefault();
        const roomname = e.target.roomname.value.trim();
        const key = e.target.key.value;
        if (!roomname.match(/^[a-zA-Z0-9_-]+$/)) {
            alert("Invalid name.");
            return;
        }
        // Create chatroom on server
        const resp = await fetch(API_URL, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "create_room",
                roomname,
                key
            })
        });
        const text = await resp.text();
        console.log('Backend response:', text);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            alert('Error processing server response: ' + text);
            return;
        }
        if (data.success) {
            addChatroomToLocal(roomname, key);
            showEnterRoom(roomname, key);
        } else {
            alert(data.error || "Error creating chatroom.");
        }
    };
}

function showJoinRoom() {
    show(`
        <form id="join-room-form" autocomplete="off">
            <div class="mb-3">
                <label class="form-label">Chatroom name</label>
                <input type="text" class="form-control" name="roomname" required maxlength="32" pattern="[a-zA-Z0-9_-]+" placeholder="Ex: room123">
            </div>
            <div class="mb-3">
                <label class="form-label">Encryption key</label>
                <input type="password" class="form-control" name="key" required maxlength="32" placeholder="Enter the room key">
            </div>
            <button type="submit" class="btn mhcn-btn w-100">Join</button>
            <button type="button" class="btn btn-link w-100 mt-2" id="back-btn">Back</button>
        </form>
    `);
    document.getElementById("back-btn").onclick = showHome;
    document.getElementById("join-room-form").onsubmit = async (e) => {
        e.preventDefault();
        const roomname = e.target.roomname.value.trim();
        const key = e.target.key.value;
        // Try to join the room
        const resp = await fetch(API_URL, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "get_room",
                roomname
            })
        });
        const text = await resp.text();
        console.log('Backend response:', text);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            alert('Error processing server response: ' + text);
            return;
        }
        if (data.success) {
            // Test key
            const decrypted = await decryptJSON(data.cipher, key);
            console.log("Trying to decrypt with key:", key);
            if (decrypted) {
                addChatroomToLocal(roomname, key);
                showEnterRoom(roomname, key);
            } else {
                alert("Incorrect encryption key.");
            }
        } else {
            alert(data.error || "Chatroom not found.");
        }
    };
}

let chatroomInterval = null;
let currentRoom = null;
let currentKey = null;
let lastMessagesHash = null;
let pinnedMessage = null;
let autoMessageInterval = null;

function showEnterRoom(roomname, key) {
    currentRoom = roomname;
    currentKey = key;
    show(`
        <div class="mb-3 d-flex align-items-center">
            <button class="btn btn-link text-light p-0 me-2" id="back-btn"><i class="bi bi-arrow-left"></i></button>
            <h5 class="mb-0 me-auto">${roomname}</h5>
            <button class="btn btn-link text-danger p-0 ms-2" id="leave-btn" title="Leave room"><i class="bi bi-x-lg"></i></button>
        </div>
        <div id="pinned-message"></div>
        <div id="chat-messages" class="chat-messages mhcn-scrollbar mb-3"></div>
        <form id="send-message-form" autocomplete="off" class="d-flex flex-column gap-2">
            <div class="input-group">
                <input type="text" class="form-control" name="message" placeholder="Type your message..." maxlength="500" autocomplete="off">
                <button class="btn mhcn-btn" type="submit"><i class="bi bi-send"></i></button>
                ${isPremium && premiumFeatures.formatting ? `
                <button class="btn btn-outline-secondary" type="button" id="format-btn" title="Formatting"><i class="bi bi-type-bold"></i></button>
                ` : ''}
            </div>
            <div class="input-group">
                <input type="file" class="form-control" name="svgfile" accept=".svg,image/svg+xml">
                <button class="btn btn-outline-light" type="button" id="send-svg-btn"><i class="bi bi-image"></i> Send SVG</button>
                ${isPremium && premiumFeatures.files ? `
                <input type="file" class="form-control" name="fileupload" style="max-width:180px;">
                <button class="btn btn-outline-info" type="button" id="send-file-btn"><i class="bi bi-paperclip"></i> Send File</button>
                ` : ''}
                ${isPremium && premiumFeatures.audio ? `
                <button class="btn btn-outline-primary" type="button" id="send-audio-btn"><i class="bi bi-mic"></i> Audio</button>
                ` : ''}
            </div>
            ${isPremium && premiumFeatures.polls ? `
            <div class="input-group">
                <button class="btn btn-outline-success w-100" type="button" id="send-poll-btn"><i class="bi bi-bar-chart"></i> Send Poll</button>
            </div>
            ` : ''}
            ${isPremium && premiumFeatures.autoMessages ? `
            <div class="input-group">
                <button class="btn btn-outline-warning w-100" type="button" id="auto-message-btn"><i class="bi bi-robot"></i> Auto Message</button>
            </div>
            ` : ''}
        </form>
    `);
    document.getElementById("back-btn").onclick = () => {
        clearInterval(chatroomInterval);
        if (autoMessageInterval) clearInterval(autoMessageInterval);
        showHome();
    };
    document.getElementById("leave-btn").onclick = () => {
        clearInterval(chatroomInterval);
        if (autoMessageInterval) clearInterval(autoMessageInterval);
        // Remove from local list
        let rooms = getChatrooms().filter(r => r.name !== roomname);
        saveChatrooms(rooms);
        showHome();
    };
    document.getElementById("send-message-form").onsubmit = async (e) => {
        e.preventDefault();
        const msg = e.target.message.value.trim();
        if (!msg) return;
        let content = {type: "text", text: msg};
        if (isPremium && premiumFeatures.formatting) {
            content.formatted = true;
        }
        await sendMessage(roomname, key, content);
        e.target.message.value = "";
    };
    document.getElementById("send-svg-btn").onclick = async () => {
        const fileInput = document.querySelector('input[name="svgfile"]');
        if (fileInput.files.length === 0) return alert("Select an SVG file.");
        const file = fileInput.files[0];
        if (file.type !== "image/svg+xml" && !file.name.endsWith(".svg")) {
            alert("Only SVG files are allowed.");
            return;
        }
        const reader = new FileReader();
        reader.onload = async function(e) {
            let svgText = e.target.result;
            // Sanitize SVG (remove scripts)
            svgText = svgText.replace(/<script[\s\S]*?>[\s\S]*?<\/script>/gi, "");
            await sendMessage(roomname, key, {type: "svg", svg: svgText});
            fileInput.value = "";
        };
        reader.readAsText(file);
    };
    if (isPremium && premiumFeatures.files) {
        document.getElementById("send-file-btn").onclick = async () => {
            const fileInput = document.querySelector('input[name="fileupload"]');
            if (fileInput.files.length === 0) return alert("Select a file.");
            const file = fileInput.files[0];
            if (file.size > 2 * 1024 * 1024) {
                alert("File too large (max 2MB).");
                return;
            }
            const reader = new FileReader();
            reader.onload = async function(e) {
                let fileData = e.target.result;
                await sendMessage(roomname, key, {
                    type: "file",
                    filename: file.name,
                    mimetype: file.type,
                    data: fileData.split(",")[1]
                });
                fileInput.value = "";
            };
            reader.readAsDataURL(file);
        };
    }
    if (isPremium && premiumFeatures.audio) {
        let mediaRecorder, audioChunks = [];
        document.getElementById("send-audio-btn").onclick = async () => {
            if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
                alert("Audio recording not supported.");
                return;
            }
            let btn = document.getElementById("send-audio-btn");
            if (!mediaRecorder || mediaRecorder.state === "inactive") {
                navigator.mediaDevices.getUserMedia({ audio: true }).then(stream => {
                    mediaRecorder = new MediaRecorder(stream);
                    audioChunks = [];
                    mediaRecorder.ondataavailable = e => audioChunks.push(e.data);
                    mediaRecorder.onstop = async () => {
                        let blob = new Blob(audioChunks, { type: "audio/webm" });
                        let reader = new FileReader();
                        reader.onload = async function(e) {
                            await sendMessage(roomname, key, {
                                type: "audio",
                                data: e.target.result.split(",")[1]
                            });
                        };
                        reader.readAsDataURL(blob);
                        btn.innerHTML = '<i class="bi bi-mic"></i> Audio';
                    };
                    mediaRecorder.start();
                    btn.innerHTML = '<i class="bi bi-stop-circle"></i> Stop';
                });
            } else if (mediaRecorder.state === "recording") {
                mediaRecorder.stop();
            }
        };
    }
    if (isPremium && premiumFeatures.polls) {
        document.getElementById("send-poll-btn").onclick = async () => {
            let question = prompt("Poll question:");
            if (!question) return;
            let options = prompt("Poll options (comma separated):");
            if (!options) return;
            let opts = options.split(",").map(s => s.trim()).filter(Boolean);
            if (opts.length < 2) return alert("At least 2 options required.");
            await sendMessage(roomname, key, {
                type: "poll",
                question,
                options: opts,
                votes: Array(opts.length).fill(0)
            });
        };
    }
    if (isPremium && premiumFeatures.autoMessages) {
        document.getElementById("auto-message-btn").onclick = () => {
            let js = prompt("Enter JavaScript for auto message (function send(msg) {...}):", "send('Hello from bot!')");
            if (!js) return;
            if (autoMessageInterval) clearInterval(autoMessageInterval);
            try {
                // eslint-disable-next-line no-new-func
                let send = (msg) => sendMessage(roomname, key, {type: "text", text: msg});
                let fn = new Function("send", js);
                autoMessageInterval = setInterval(() => { fn(send); }, 5000);
                showPremiumToast("Auto message bot started.");
            } catch (e) {
                alert("Invalid JavaScript.");
            }
        };
    }
    if (isPremium && premiumFeatures.formatting) {
        document.getElementById("format-btn").onclick = () => {
            alert("Formatting: *bold* _italic_ ~underline~");
        };
    }
    // Load and update messages
    loadAndRenderMessages(roomname, key);
    chatroomInterval = setInterval(() => {
        loadAndRenderMessages(roomname, key, true);
    }, 2000);
}

async function sendMessage(roomname, key, content) {
    const account = getAccount();
    try {
        const resp = await fetch(API_URL, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "send_message",
                roomname,
                key,
                message: JSON.stringify({
                    author: account.username,
                    datetime: new Date().toISOString(),
                    ...content
                })
            })
        });
        const text = await resp.text();
        console.log('Backend response:', text);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            alert('Error processing server response: ' + text);
            return;
        }
        if (!data.success) {
            alert(data.error || "Error sending message.");
        }
    } catch (e) {
        console.error('Network error:', e);
        alert('Connection error with server.');
    }
}

async function loadAndRenderMessages(roomname, key, onlyIfChanged = false) {
    try {
        const resp = await fetch(API_URL, {
            method: "POST",
            headers: {"Content-Type": "application/x-www-form-urlencoded"},
            body: new URLSearchParams({
                action: "get_room",
                roomname
            })
        });
        const text = await resp.text();
        console.log('Backend response:', text);
        let data;
        try {
            data = JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text);
            document.getElementById("chat-messages").innerHTML = `<div class="text-danger">Error processing server response.</div>`;
            return;
        }
        if (!data.success) {
            document.getElementById("chat-messages").innerHTML = `<div class="text-danger">Error loading messages.</div>`;
            return;
        }
        const decrypted = await decryptJSON(data.cipher, key);
        if (!decrypted) {
            document.getElementById("chat-messages").innerHTML = `<div class="text-danger">Incorrect encryption key.</div>`;
            return;
        }
        // Check if changed
        const hash = JSON.stringify(decrypted);
        if (onlyIfChanged && hash === lastMessagesHash) return;
        lastMessagesHash = hash;
        renderMessages(decrypted);
    } catch (e) {
        console.error('Network error:', e);
        document.getElementById("chat-messages").innerHTML = `<div class="text-danger">Connection error with server.</div>`;
    }
}

function renderMessages(messages) {
    const account = getAccount();
    let html = "";
    let pinned = null;
    messages.forEach((msg, idx) => {
        let author = `<span class="author">${escapeHTML(msg.author)}${isPremium && premiumFeatures.verifiedBadge && msg.author === account.username ? '<span class="verified-badge" title="Verified"><i class="bi bi-patch-check-fill"></i></span>' : ''}</span>`;
        let time = `<span class="timestamp ms-2">${formatDate(msg.datetime)}</span>`;
        let content = "";
        if (msg.type === "text") {
            if (isPremium && premiumFeatures.formatting && msg.formatted) {
                let t = escapeHTML(msg.text)
                    .replace(/\*([^\*]+)\*/g, '<span class="format-bold">$1</span>')
                    .replace(/_([^_]+)_/g, '<span class="format-italic">$1</span>')
                    .replace(/~([^~]+)~/g, '<span class="format-underline">$1</span>');
                content = `<div class="text">${t}</div>`;
            } else {
                content = `<div class="text">${escapeHTML(msg.text)}</div>`;
            }
        } else if (msg.type === "svg") {
            content = `<div class="text"><img src="data:image/svg+xml;base64,${btoa(msg.svg)}" alt="SVG"></div>`;
        } else if (isPremium && premiumFeatures.files && msg.type === "file") {
            content = `<div class="text"><a href="data:${msg.mimetype};base64,${msg.data}" download="${escapeHTML(msg.filename)}"><i class="bi bi-paperclip"></i> ${escapeHTML(msg.filename)}</a></div>`;
        } else if (isPremium && premiumFeatures.audio && msg.type === "audio") {
            content = `<div class="text"><audio controls src="data:audio/webm;base64,${msg.data}"></audio></div>`;
        } else if (isPremium && premiumFeatures.polls && msg.type === "poll") {
            let pollHtml = `<div class="text"><strong>${escapeHTML(msg.question)}</strong><ul style="list-style:none;padding-left:0;">`;
            msg.options.forEach((opt, i) => {
                pollHtml += `<li>
                    <button class="btn btn-sm btn-outline-primary vote-btn" data-idx="${i}" data-msgidx="${idx}">${escapeHTML(opt)}</button>
                    <span class="ms-2">${msg.votes && msg.votes[i] ? msg.votes[i] : 0} votes</span>
                </li>`;
            });
            pollHtml += "</ul></div>";
            content = pollHtml;
        }
        let actions = "";
        if (isPremium && premiumFeatures.deleteMessage && msg.author === account.username) {
            actions += `<button class="btn btn-sm btn-link text-danger p-0 ms-2 delete-msg-btn" data-msgidx="${idx}" title="Delete"><i class="bi bi-trash"></i></button>`;
        }
        if (isPremium && premiumFeatures.pinMessage) {
            actions += `<button class="btn btn-sm btn-link text-warning p-0 ms-2 pin-msg-btn" data-msgidx="${idx}" title="Pin"><i class="bi bi-pin-angle"></i></button>`;
        }
        html += `<div class="chat-message" data-msgidx="${idx}">${author} ${time}${content}${actions}</div>`;
        if (msg.pinned) pinned = msg;
    });
    document.getElementById("chat-messages").innerHTML = html;
    // Pinned message
    if (isPremium && premiumFeatures.pinMessage && pinned) {
        document.getElementById("pinned-message").innerHTML = `<div class="fixed-message"><i class="bi bi-pin-angle"></i> ${escapeHTML(pinned.text || "")}</div>`;
    } else {
        document.getElementById("pinned-message").innerHTML = "";
    }
    // Scroll to bottom
    const el = document.getElementById("chat-messages");
    el.scrollTop = el.scrollHeight;

    // Add event listeners for premium actions
    if (isPremium && premiumFeatures.deleteMessage) {
        document.querySelectorAll(".delete-msg-btn").forEach(btn => {
            btn.onclick = async function() {
                let idx = parseInt(btn.dataset.msgidx);
                if (!confirm("Delete this message?")) return;
                // Remove message locally and re-encrypt
                let roomname = currentRoom, key = currentKey;
                const resp = await fetch(API_URL, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: new URLSearchParams({
                        action: "get_room",
                        roomname
                    })
                });
                const text = await resp.text();
                let data;
                try { data = JSON.parse(text); } catch {}
                if (data && data.success) {
                    let decrypted = await decryptJSON(data.cipher, key);
                    if (decrypted && Array.isArray(decrypted)) {
                        decrypted.splice(idx, 1);
                        const newCipher = await encryptJSON(decrypted, key);
                        await fetch(API_URL, {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: new URLSearchParams({
                                action: "send_message",
                                roomname,
                                key,
                                message: JSON.stringify({type: "__replace__", data: decrypted})
                            })
                        });
                        loadAndRenderMessages(roomname, key, false);
                    }
                }
            };
        });
    }
    if (isPremium && premiumFeatures.pinMessage) {
        document.querySelectorAll(".pin-msg-btn").forEach(btn => {
            btn.onclick = async function() {
                let idx = parseInt(btn.dataset.msgidx);
                // Pin message locally and re-encrypt
                let roomname = currentRoom, key = currentKey;
                const resp = await fetch(API_URL, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: new URLSearchParams({
                        action: "get_room",
                        roomname
                    })
                });
                const text = await resp.text();
                let data;
                try { data = JSON.parse(text); } catch {}
                if (data && data.success) {
                    let decrypted = await decryptJSON(data.cipher, key);
                    if (decrypted && Array.isArray(decrypted)) {
                        decrypted.forEach((m, i) => { if (m.pinned) delete m.pinned; });
                        if (decrypted[idx]) decrypted[idx].pinned = true;
                        const newCipher = await encryptJSON(decrypted, key);
                        await fetch(API_URL, {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: new URLSearchParams({
                                action: "send_message",
                                roomname,
                                key,
                                message: JSON.stringify({type: "__replace__", data: decrypted})
                            })
                        });
                        loadAndRenderMessages(roomname, key, false);
                    }
                }
            };
        });
    }
    if (isPremium && premiumFeatures.polls) {
        document.querySelectorAll(".vote-btn").forEach(btn => {
            btn.onclick = async function() {
                let idx = parseInt(btn.dataset.msgidx);
                let optIdx = parseInt(btn.dataset.idx);
                let roomname = currentRoom, key = currentKey;
                const resp = await fetch(API_URL, {
                    method: "POST",
                    headers: {"Content-Type": "application/x-www-form-urlencoded"},
                    body: new URLSearchParams({
                        action: "get_room",
                        roomname
                    })
                });
                const text = await resp.text();
                let data;
                try { data = JSON.parse(text); } catch {}
                if (data && data.success) {
                    let decrypted = await decryptJSON(data.cipher, key);
                    if (decrypted && Array.isArray(decrypted) && decrypted[idx] && decrypted[idx].type === "poll") {
                        if (!decrypted[idx].votes) decrypted[idx].votes = Array(decrypted[idx].options.length).fill(0);
                        decrypted[idx].votes[optIdx]++;
                        const newCipher = await encryptJSON(decrypted, key);
                        await fetch(API_URL, {
                            method: "POST",
                            headers: {"Content-Type": "application/x-www-form-urlencoded"},
                            body: new URLSearchParams({
                                action: "send_message",
                                roomname,
                                key,
                                message: JSON.stringify({type: "__replace__", data: decrypted})
                            })
                        });
                        loadAndRenderMessages(roomname, key, false);
                    }
                }
            };
        });
    }
    // Push notifications
    if (isPremium && premiumFeatures.pushNotifications && "Notification" in window) {
        if (Notification.permission === "default") {
            Notification.requestPermission();
        }
        if (Notification.permission === "granted") {
            let lastMsg = messages[messages.length - 1];
            if (lastMsg && lastMsg.author !== getAccount().username) {
                new Notification("New message in " + currentRoom, {
                    body: (lastMsg.text ? lastMsg.text : "New message"),
                    icon: "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/icons/chat-dots.svg"
                });
            }
        }
    }
}

function formatDate(dt) {
    const d = new Date(dt);
    return d.toLocaleString("en-US", {dateStyle: "short", timeStyle: "short"});
}

function escapeHTML(str) {
    if (!str) return "";
    return str.replace(/[&<>"']/g, function(m) {
        return ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#39;'
        })[m];
    });
}

// ========== PREMIUM THEME UI ==========

function showCustomThemeModal() {
    document.getElementById("premium-theme-modal").style.display = "block";
    document.getElementById("custom-css-input").value = userCustomCSS;
}
document.getElementById("close-theme-modal").onclick = function() {
    document.getElementById("premium-theme-modal").style.display = "none";
};
document.getElementById("save-theme-btn").onclick = function() {
    userCustomCSS = document.getElementById("custom-css-input").value;
    localStorage.setItem("mhcn_custom_css", userCustomCSS);
    applyCustomTheme();
    document.getElementById("premium-theme-modal").style.display = "none";
};
document.getElementById("reset-theme-btn").onclick = function() {
    userCustomCSS = "";
    localStorage.removeItem("mhcn_custom_css");
    applyCustomTheme();
    document.getElementById("premium-theme-modal").style.display = "none";
};
function applyCustomTheme() {
    let style = document.getElementById("custom-theme-style");
    if (userCustomCSS && isPremium && premiumFeatures.customTheme) {
        style.innerHTML += "\n" + userCustomCSS;
    } else {
        // Reset to default (reload page style)
        style.innerHTML = style.innerHTML.split("/*CUSTOM*/")[0];
    }
}

// ========== INITIALIZATION ==========

function initPremiumState() {
    isPremium = localStorage.getItem("mhcn_premium") === "1";
    premiumKey = localStorage.getItem("mhcn_premium_key") || null;
    updatePremiumUI();
    applyCustomTheme();
}
initPremiumState();
showHome();

// Premium button logic
document.getElementById("premium-btn").onclick = function() {
    if (isPremium) {
        alert("Premium is already enabled!");
        return;
    }
    let key = prompt("Enter your premium key:");
    if (!key) return;
    enablePremium(key);
};

applyCustomTheme();

</script>

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
