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
/* ========== MODERN THEME FOR MADHATCHATNET ========== */
:root {
  --primary: #6c5ce7;
  --primary-dark: #5649c0;
  --secondary: #00cec9;
  --dark: #2d3436;
  --darker: #1e272e;
  --light: #f5f6fa;
  --danger: #ff7675;
  --warning: #fdcb6e;
  --success: #55efc4;
  --info: #74b9ff;
  --text-primary: #f5f6fa;
  --text-secondary: #dfe6e9;
  --border-radius: 12px;
  --shadow: 0 8px 32px rgba(0, 0, 0, 0.3);
  --transition: all 0.3s ease;
}

/* ========== BASE STYLES ========== */
body {
  background: linear-gradient(135deg, var(--darker), var(--dark));
  color: var(--text-primary);
  font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
  min-height: 100vh;
  margin: 0;
  line-height: 1.6;
  overflow-x: hidden;
}

/* ========== TYPOGRAPHY ========== */
h1, h2, h3, h4, h5, h6 {
  font-weight: 600;
  margin: 0 0 1rem;
}

h2 {
  font-size: 1.75rem;
  background: linear-gradient(90deg, var(--primary), var(--secondary));
  -webkit-background-clip: text;
  background-clip: text;
  color: transparent;
  display: inline-block;
}

/* ========== LAYOUT ========== */
.container {
  padding: 2rem 1rem;
  max-width: 1200px;
  margin: 0 auto;
}

.mhcn-card {
  background: rgba(45, 52, 54, 0.8);
  backdrop-filter: blur(10px);
  border-radius: var(--border-radius);
  box-shadow: var(--shadow);
  padding: 2rem;
  border: 1px solid rgba(255, 255, 255, 0.1);
  transition: var(--transition);
}

@media (max-width: 768px) {
  .container {
    padding: 1rem;
  }
  
  .mhcn-card {
    padding: 1.5rem;
    border-radius: 0;
  }
}

/* ========== BUTTONS ========== */
.btn {
  border: none;
  border-radius: 50px;
  padding: 0.75rem 1.5rem;
  font-weight: 600;
  cursor: pointer;
  transition: var(--transition);
  display: inline-flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
}

.mhcn-btn {
  background: var(--primary);
  color: white;
}

.mhcn-btn:hover {
  background: var(--primary-dark);
  transform: translateY(-2px);
}

.btn-outline-light {
  border: 1px solid rgba(255, 255, 255, 0.2);
  background: transparent;
  color: var(--text-primary);
}

.btn-outline-light:hover {
  background: rgba(255, 255, 255, 0.1);
}

.btn-link {
  color: var(--secondary);
  text-decoration: none;
}

.btn-link:hover {
  text-decoration: underline;
}

.btn-sm {
  padding: 0.5rem 1rem;
  font-size: 0.875rem;
}

/* ========== FORMS ========== */
.form-control {
  background: rgba(255, 255, 255, 0.1);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text-primary);
  border-radius: 50px;
  padding: 0.75rem 1.25rem;
  transition: var(--transition);
}

.form-control:focus {
  background: rgba(255, 255, 255, 0.15);
  border-color: var(--primary);
  box-shadow: 0 0 0 0.25rem rgba(108, 92, 231, 0.25);
  color: var(--text-primary);
}

.form-label {
  font-weight: 500;
  margin-bottom: 0.5rem;
  display: block;
}

.input-group {
  display: flex;
  gap: 0.5rem;
}

.input-group .form-control {
  flex: 1;
  min-width: 0;
}

/* ========== CHAT INTERFACE ========== */
.chatroom-list-item {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: var(--border-radius);
  padding: 1rem;
  margin-bottom: 0.75rem;
  cursor: pointer;
  transition: var(--transition);
  display: flex;
  align-items: center;
  gap: 0.75rem;
}

.chatroom-list-item:hover {
  background: rgba(255, 255, 255, 0.1);
  transform: translateX(5px);
}

.chatroom-list-item i {
  font-size: 1.25rem;
  color: var(--secondary);
}

.chat-messages {
  max-height: 60vh;
  overflow-y: auto;
  padding: 1rem;
  margin: 1rem -1rem;
  background: rgba(0, 0, 0, 0.2);
  border-radius: var(--border-radius);
}

.chat-message {
  background: rgba(255, 255, 255, 0.05);
  border-radius: var(--border-radius);
  padding: 1rem;
  margin-bottom: 1rem;
  position: relative;
  transition: var(--transition);
}

.chat-message:hover {
  background: rgba(255, 255, 255, 0.1);
}

.chat-message .author {
  font-weight: 600;
  color: var(--secondary);
  display: flex;
  align-items: center;
}

.chat-message .timestamp {
  font-size: 0.75rem;
  color: rgba(255, 255, 255, 0.5);
  margin-left: 0.5rem;
}

.chat-message .text {
  margin-top: 0.5rem;
  word-break: break-word;
}

.chat-message img {
  max-width: 100%;
  max-height: 300px;
  border-radius: var(--border-radius);
  margin-top: 0.5rem;
  display: block;
}

/* ========== SCROLLBAR ========== */
.mhcn-scrollbar::-webkit-scrollbar {
  width: 8px;
}

.mhcn-scrollbar::-webkit-scrollbar-track {
  background: rgba(0, 0, 0, 0.1);
  border-radius: 10px;
}

.mhcn-scrollbar::-webkit-scrollbar-thumb {
  background: var(--primary);
  border-radius: 10px;
}

.mhcn-scrollbar::-webkit-scrollbar-thumb:hover {
  background: var(--primary-dark);
}

/* ========== BADGES ========== */
.premium-badge {
  background: linear-gradient(90deg, #fdcb6e, #e17055);
  color: var(--darker);
  font-weight: 700;
  border-radius: 50px;
  padding: 0.25rem 0.75rem;
  font-size: 0.75rem;
  margin-left: 0.5rem;
  display: inline-flex;
  align-items: center;
  gap: 0.25rem;
}

.verified-badge {
  color: var(--secondary);
  font-size: 1rem;
  margin-left: 0.25rem;
}

/* ========== SPECIAL ELEMENTS ========== */
.fixed-message {
  background: rgba(108, 92, 231, 0.2);
  border-left: 4px solid var(--primary);
  padding: 0.75rem 1rem;
  margin-bottom: 1rem;
  border-radius: var(--border-radius);
  font-weight: 500;
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.mhcn-premium-toast {
  position: fixed;
  bottom: 2rem;
  left: 50%;
  transform: translateX(-50%);
  background: linear-gradient(90deg, var(--darker), var(--dark));
  color: white;
  padding: 1rem 1.5rem;
  border-radius: var(--border-radius);
  box-shadow: var(--shadow);
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: 0.75rem;
  border: 1px solid var(--primary);
  max-width: 90%;
  width: fit-content;
}

.mhcn-premium-toast .mhcn-premium-star {
  color: var(--warning);
  font-size: 1.25rem;
}

.mhcn-premium-toast .mhcn-premium-link {
  color: var(--secondary);
  text-decoration: underline;
  font-weight: 600;
  margin-left: 0.5rem;
}

/* ========== MODAL ========== */
#premium-theme-modal {
  position: fixed;
  top: 0;
  left: 0;
  right: 0;
  bottom: 0;
  background: rgba(0, 0, 0, 0.8);
  backdrop-filter: blur(5px);
  z-index: 1000;
  display: flex;
  align-items: center;
  justify-content: center;
  opacity: 0;
  visibility: hidden;
  transition: var(--transition);
}

#premium-theme-modal[style*="display:block"] {
  opacity: 1;
  visibility: visible;
}

#premium-theme-modal > div {
  background: var(--darker);
  border-radius: var(--border-radius);
  padding: 2rem;
  max-width: 500px;
  width: 90%;
  box-shadow: var(--shadow);
  position: relative;
  border: 1px solid rgba(255, 255, 255, 0.1);
}

#close-theme-modal {
  background: none;
  border: none;
  color: var(--text-secondary);
  font-size: 1.5rem;
  position: absolute;
  top: 1rem;
  right: 1rem;
  cursor: pointer;
  padding: 0.5rem;
}

#custom-css-input {
  width: 100%;
  min-height: 150px;
  background: rgba(0, 0, 0, 0.3);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: var(--text-primary);
  padding: 1rem;
  border-radius: var(--border-radius);
  font-family: monospace;
  resize: vertical;
}

/* ========== ANIMATIONS ========== */
@keyframes fadeIn {
  from { opacity: 0; transform: translateY(10px); }
  to { opacity: 1; transform: translateY(0); }
}

.mhcn-card {
  animation: fadeIn 0.5s ease-out;
}

.chat-message {
  animation: fadeIn 0.3s ease-out;
}

/* ========== UTILITY CLASSES ========== */
.d-flex {
  display: flex;
}

.align-items-center {
  align-items: center;
}

.justify-content-between {
  justify-content: space-between;
}

.mb-3 {
  margin-bottom: 1rem;
}

.mt-2 {
  margin-top: 0.5rem;
}

.ms-2 {
  margin-left: 0.5rem;
}

.me-2 {
  margin-right: 0.5rem;
}

.w-100 {
  width: 100%;
}

.text-center {
  text-align: center;
}

.text-muted {
  color: rgba(255, 255, 255, 0.5);
}

/* ========== RESPONSIVE ADJUSTMENTS ========== */
@media (max-width: 576px) {
  .chat-messages {
    max-height: 50vh;
  }
  
  .input-group {
    flex-wrap: wrap;
  }
  
  .input-group .btn {
    flex: 1 0 auto;
  }
  
  .input-group .form-control {
    min-width: 100%;
  }
}
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
    fetch('keys.json', {cache: "no-store"})
        .then(response => {
            if (!response.ok) throw new Error("Could not load keys.json");
            return response.json();
        })
        .then(keys => {
            // keys can be an array or object, handle both
            let found = false;
            let newKeys;
            if (Array.isArray(keys)) {
                const idx = keys.findIndex(k => k.key === apiKey);
                if (idx !== -1) {
                    found = true;
                    // Remove the used key
                    newKeys = keys.slice(0, idx).concat(keys.slice(idx + 1));
                }
            } else if (typeof keys === "object" && keys !== null) {
                // If keys.json is an object (unlikely, but for safety)
                if (Object.values(keys).some(k => k.key === apiKey)) {
                    found = true;
                    // Remove the key from the object
                    newKeys = {};
                    for (const [i, k] of Object.entries(keys)) {
                        if (k.key !== apiKey) newKeys[i] = k;
                    }
                }
            }
            if (found) {
                // Try to update keys.json to consume the key
                fetch('keys.json', {
                    method: 'PUT',
                    headers: {'Content-Type': 'application/json'},
                    body: JSON.stringify(newKeys)
                }).catch(() => {/* ignore errors, best effort */});
                isPremium = true;
                premiumKey = apiKey;
                localStorage.setItem("mhcn_premium", "1");
                localStorage.setItem("mhcn_premium_key", apiKey);
                showToast("Premium enabled! Enjoy all features.");
                updatePremiumUI();
            } else {
                isPremium = false;
                premiumKey = null;
                localStorage.removeItem("mhcn_premium");
                localStorage.removeItem("mhcn_premium_key");
                showToast("Invalid premium key.", true);
            }
        })
        .catch(error => {
            console.error("⚠️ Error reading keys.json:", error);
            showToast("Error validating premium key.", true);
        });
}

function showToast(msg, error = false) {
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
    toast.style.boxShadow = "0 4px 24px #0008";
    document.body.appendChild(toast);
    setTimeout(() => { toast.remove(); }, 2500);
}

function showInputToast(msg, defaultValue, callback) {
    let toast = document.createElement("div");
    toast.style.position = "fixed";
    toast.style.bottom = "2em";
    toast.style.left = "50%";
    toast.style.transform = "translateX(-50%)";
    toast.style.background = "#232336";
    toast.style.color = "#fff";
    toast.style.padding = "1.5em 2em 1em 2em";
    toast.style.borderRadius = "1em";
    toast.style.zIndex = 99999;
    toast.style.fontWeight = "bold";
    toast.style.boxShadow = "0 4px 24px #0008";
    toast.innerHTML = `<div style='margin-bottom:0.7em;'>${msg}</div>` +
        `<input type='text' id='input-toast-field' style='width:100%;padding:0.5em;border-radius:0.5em;border:none;margin-bottom:0.7em;' value="${defaultValue ? String(defaultValue).replace(/"/g, '&quot;') : ''}">` +
        `<div style='text-align:right;'><button id='input-toast-ok' style='background:#6f42c1;color:#fff;border:none;border-radius:0.5em;padding:0.5em 1.2em;font-weight:600;cursor:pointer;'>OK</button></div>`;
    document.body.appendChild(toast);
    let input = toast.querySelector('#input-toast-field');
    input.focus();
    input.select();
    toast.querySelector('#input-toast-ok').onclick = () => {
        let val = input.value;
        toast.remove();
        callback(val);
    };
    input.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            toast.querySelector('#input-toast-ok').click();
        }
    });
}

function showConfirmToast(msg, callback) {
    let toast = document.createElement("div");
    toast.style.position = "fixed";
    toast.style.bottom = "2em";
    toast.style.left = "50%";
    toast.style.transform = "translateX(-50%)";
    toast.style.background = "#232336";
    toast.style.color = "#fff";
    toast.style.padding = "1.5em 2em 1em 2em";
    toast.style.borderRadius = "1em";
    toast.style.zIndex = 99999;
    toast.style.fontWeight = "bold";
    toast.style.boxShadow = "0 4px 24px #0008";
    toast.innerHTML = `<div style='margin-bottom:0.7em;'>${msg}</div>` +
        `<div style='text-align:right;'><button id='confirm-toast-yes' style='background:#6f42c1;color:#fff;border:none;border-radius:0.5em;padding:0.5em 1.2em;font-weight:600;cursor:pointer;margin-right:0.5em;'>Sim</button><button id='confirm-toast-no' style='background:#dc3545;color:#fff;border:none;border-radius:0.5em;padding:0.5em 1.2em;font-weight:600;cursor:pointer;'>Não</button></div>`;
    document.body.appendChild(toast);
    toast.querySelector('#confirm-toast-yes').onclick = () => { toast.remove(); callback(true); };
    toast.querySelector('#confirm-toast-no').onclick = () => { toast.remove(); callback(false); };
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

const PREMIUM_OFFER_URL = "./buy.php";
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
            showToast("Invalid name.", true);
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
            showToast('Error processing server response: ' + text, true);
            return;
        }
        if (data.success) {
            addChatroomToLocal(roomname, key);
            showEnterRoom(roomname, key);
        } else {
            showToast(data.error || "Error creating chatroom.", true);
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
            showToast('Error processing server response: ' + text, true);
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
                showToast("Incorrect encryption key.", true);
            }
        } else {
            showToast(data.error || "Chatroom not found.", true);
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
        if (fileInput.files.length === 0) return showToast("Select an SVG file.", true);
        const file = fileInput.files[0];
        if (file.type !== "image/svg+xml" && !file.name.endsWith(".svg")) {
            showToast("Only SVG files are allowed.", true);
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
            if (fileInput.files.length === 0) return showToast("Select a file.", true);
            const file = fileInput.files[0];
            if (file.size > 2 * 1024 * 1024) {
                showToast("File too large (max 2MB).", true);
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
                showToast("Audio recording not supported.", true);
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
            showInputToast("Poll question:", "", function(question) {
                if (!question) return;
                showInputToast("Poll options (comma separated):", "", function(options) {
                    if (!options) return;
                    let opts = options.split(",").map(s => s.trim()).filter(Boolean);
                    if (opts.length < 2) return showToast("At least 2 options required.", true);
                    sendMessage(roomname, key, {
                        type: "poll",
                        question,
                        options: opts,
                        votes: Array(opts.length).fill(0)
                    });
                });
            });
        };
    }
    if (isPremium && premiumFeatures.autoMessages) {
        document.getElementById("auto-message-btn").onclick = () => {
            showInputToast("Enter JavaScript for auto message (function send(msg) {...}):", "send('Hello from bot!')", function(js) {
                if (!js) return;
                if (autoMessageInterval) clearInterval(autoMessageInterval);
                try {
                    let send = (msg) => sendMessage(roomname, key, {type: "text", text: msg});
                    let fn = new Function("send", js);
                    autoMessageInterval = setInterval(() => { fn(send); }, 5000);
                    showToast("Auto message bot started.");
                } catch (e) {
                    showToast("Invalid JavaScript.", true);
                }
            });
        };
    }
    if (isPremium && premiumFeatures.formatting) {
        document.getElementById("format-btn").onclick = () => {
            showToast("Formatting: *bold* _italic_ ~underline~");
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
            showToast('Error processing server response: ' + text, true);
            return;
        }
        if (!data.success) {
            showToast(data.error || "Error sending message.", true);
        }
    } catch (e) {
        console.error('Network error:', e);
        showToast('Connection error with server.', true);
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
            showToast('Error processing server response: ' + text, true);
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
                showConfirmToast("Delete this message?", async function(confirmed) {
                    if (!confirmed) return;
                    // Remove message localmente e re-encripta
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
                });
            };
        });
    }
    if (isPremium && premiumFeatures.pinMessage) {
        document.querySelectorAll(".pin-msg-btn").forEach(btn => {
            btn.onclick = async function() {
                let idx = parseInt(btn.dataset.msgidx);
                // Pin message locally e re-encripta
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
        showToast("Premium is already enabled!");
        return;
    }
    showInputToast("Enter your premium key:", "", function(key) {
        if (!key) return;
        enablePremium(key);
    });
};

applyCustomTheme();

</script>

<!-- Bootstrap Icons -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.5/font/bootstrap-icons.css">
