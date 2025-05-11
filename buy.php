<?php
// Configurações
$PIX_KEY = '07071963967';
$ETH_ADDRESS = '0x406D43F026F73Eee7EeEB02Bc965BdD6bb1005dE'; 
$PRICE_USD = 4.00;
$KEYS_FILE = __DIR__ . '/keys.json';

// Função para gerar chave aleatória
function generateKey($length = 16) {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789abcdefghijkmnopqrstuvwxyz';
    $key = '';
    for ($i = 0; $i < $length; $i++) {
        $key .= $chars[random_int(0, strlen($chars) - 1)];
    }
    return $key;
}

// Função para salvar chave
function saveKey($key) {
    global $KEYS_FILE;
    $keys = [];
    if (file_exists($KEYS_FILE)) {
        $json = file_get_contents($KEYS_FILE);
        $keys = json_decode($json, true) ?: [];
    }
    $keys[] = [
        'key' => $key,
        'created' => date('c'),
        'ip' => $_SERVER['REMOTE_ADDR']
    ];
    file_put_contents($KEYS_FILE, json_encode($keys, JSON_PRETTY_PRINT));
}

// Função para obter cotação ETH/BRL
function getEthPriceBRL() {
    $data = @file_get_contents('https://api.coingecko.com/api/v3/simple/price?ids=ethereum&vs_currencies=brl,usd');
    if ($data) {
        $json = json_decode($data, true);
        return [
            'brl' => $json['ethereum']['brl'],
            'usd' => $json['ethereum']['usd']
        ];
    }
    return ['brl' => 0, 'usd' => 0];
}

// Função para obter cotação USD/BRL
function getUsdPriceBRL() {
    $data = @file_get_contents('https://api.exchangerate.host/latest?base=USD&symbols=BRL');
    if ($data) {
        $json = json_decode($data, true);
        return $json['rates']['BRL'] ?? 0;
    }
    return 0;
}

// Função para gerar QR Code base64
function qrCodeBase64($text) {
    $url = "https://api.qrserver.com/v1/create-qr-code/?size=240x240&data=" . urlencode($text);
    $img = @file_get_contents($url);
    if ($img) {
        return 'data:image/png;base64,' . base64_encode($img);
    }
    return '';
}

// Processamento AJAX para checagem de pagamento e geração de chave
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'check_pix') {
        // Checagem Pix real via API Pix do Banco Central (exemplo de consulta de cobrança)
        // Espera-se que o frontend envie o txid ou endToEndId da cobrança Pix gerada
        $txid = $_POST['pix_txid'] ?? '';
        $valor_esperado = $price_brl ?? 0; // valor em BRL esperado para a cobrança

        // Configurações da sua API Pix
        $pix_url = 'https://pix-h.api.efipay.com.br/v2/cob/' . urlencode($txid); // substitua se necessário
        $pix_token = "https://pix-h.api.efipay.com.br/oauth/token"; // coloque seu token OAuth2 Pix aqui

        // Requisição para a API Pix
        $ch = curl_init($pix_url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer $pix_token",
            "Content-Type: application/json"
        ]);
        $response = curl_exec($ch);
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpcode === 200 && $response) {
            $pix_data = json_decode($response, true);
            // Verifica se o status é 'CONCLUIDA' ou 'COMPLETED' e se o valor bate
            if (
                isset($pix_data['status']) &&
                in_array($pix_data['status'], ['CONCLUIDA', 'COMPLETED', 'REALIZADO', 'COMPLETED_SETTLED']) &&
                isset($pix_data['valor']['original']) &&
                floatval($pix_data['valor']['original']) >= floatval($valor_esperado) * 0.98 // tolerância de 2%
            ) {
                $key = generateKey();
                saveKey($key);
                echo json_encode(['success' => true, 'key' => $key]);
                exit;
            } else {
                echo json_encode(['success' => false, 'msg' => 'Pagamento não encontrado ou valor incorreto.']);
                exit;
            }
        } else {
            // Trata erros da API Pix
            $msg = 'Erro ao consultar pagamento Pix.';
            if ($response) {
                $err = json_decode($response, true);
                if (isset($err['detail'])) $msg .= ' ' . $err['detail'];
            }
            echo json_encode(['success' => false, 'msg' => $msg]);
            exit;
        }
    }
    if ($_POST['action'] === 'check_eth') {
        // Checagem Ethereum via Etherscan API
        $tx = $_POST['tx'] ?? '';
        $eth_address = strtolower($ETH_ADDRESS);
        if ($tx) {
            $api = "https://api.etherscan.io/api?module=transaction&action=gettxreceiptstatus&txhash=$tx";
            $data = @file_get_contents($api);
            $json = json_decode($data, true);
            if ($json && $json['status'] == '1' && $json['result']['status'] == '1') {
                // Confirmado, agora checar se foi para o endereço correto e valor suficiente
                $api2 = "https://api.etherscan.io/api?module=proxy&action=eth_getTransactionByHash&txhash=$tx";
                $data2 = @file_get_contents($api2);
                $json2 = json_decode($data2, true);
                if ($json2 && isset($json2['result'])) {
                    $to = strtolower($json2['result']['to']);
                    $value = hexdec($json2['result']['value']) / 1e18;
                    $eth_price = getEthPriceBRL();
                    $usd = $eth_price['usd'];
                    $eth_needed = $PRICE_USD / $usd;
                    if ($to === $eth_address && $value >= $eth_needed * 0.98) { // 2% tolerância
                        $key = generateKey();
                        saveKey($key);
                        echo json_encode(['success' => true, 'key' => $key]);
                        exit;
                    }
                }
            }
        }
        echo json_encode(['success' => false, 'msg' => 'Payment not detected yet.']);
        exit;
    }
}
$eth_price = getEthPriceBRL();
$usd_brl = getUsdPriceBRL();
$price_brl = round($PRICE_USD * $usd_brl, 2);
$eth_needed = $eth_price['usd'] > 0 ? round($PRICE_USD / $eth_price['usd'], 6) : 0;
$pix_qr = $PIX_KEY; // Pode ser chave ou código copia e cola
$pix_qr_img = qrCodeBase64($pix_qr);
$eth_qr = "ethereum:$ETH_ADDRESS?value=" . $eth_needed;
$eth_qr_img = qrCodeBase64($eth_qr);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Buy Premium API Key</title>
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <style>
        body {
            background: #181824;
            color: #f8f8ff;
            font-family: 'Segoe UI', 'Roboto', Arial, sans-serif;
            margin: 0;
            min-height: 100vh;
        }
        .container {
            max-width: 420px;
            margin: 3em auto 0 auto;
            background: #232336;
            border-radius: 1.2em;
            box-shadow: 0 8px 32px #0006;
            padding: 2.5em 2em 2em 2em;
            text-align: center;
        }
        h1 {
            font-size: 2em;
            font-weight: 700;
            margin-bottom: 0.2em;
            color: #e0b3ff;
            letter-spacing: 0.02em;
        }
        .desc {
            color: #bdbde6;
            font-size: 1.1em;
            margin-bottom: 2em;
        }
        .pay-tabs {
            display: flex;
            margin-bottom: 2em;
            border-radius: 0.7em;
            overflow: hidden;
            background: #232336;
            border: 1px solid #2d2d44;
        }
        .pay-tab {
            flex: 1;
            padding: 1em 0;
            cursor: pointer;
            background: #232336;
            color: #bdbde6;
            font-weight: 600;
            border: none;
            outline: none;
            transition: background 0.2s, color 0.2s;
        }
        .pay-tab.active {
            background: #6f42c1;
            color: #fff;
        }
        .pay-method {
            display: none;
            margin-bottom: 2em;
        }
        .pay-method.active {
            display: block;
        }
        .qrcode {
            margin: 1em auto 0.5em auto;
            width: 180px;
            height: 180px;
            background: #181824;
            border-radius: 1em;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 2px 12px #0004;
        }
        .pay-info {
            margin: 1em 0 0.5em 0;
            font-size: 1.1em;
            color: #e0b3ff;
            word-break: break-all;
        }
        .copy-btn {
            background: #6f42c1;
            color: #fff;
            border: none;
            border-radius: 0.5em;
            padding: 0.5em 1.2em;
            font-size: 1em;
            cursor: pointer;
            margin-top: 0.5em;
            transition: background 0.2s;
        }
        .copy-btn:hover {
            background: #8e5fff;
        }
        .status {
            margin: 1.5em 0 0.5em 0;
            font-size: 1.1em;
            min-height: 2em;
        }
        .key-box {
            background: #232336;
            border: 1px solid #6f42c1;
            color: #fff;
            font-size: 1.3em;
            padding: 1em;
            border-radius: 0.7em;
            margin: 1.5em 0 0.5em 0;
            word-break: break-all;
            letter-spacing: 0.08em;
            font-family: 'Fira Mono', 'Consolas', monospace;
        }
        .footer {
            margin-top: 2.5em;
            color: #6f42c1;
            font-size: 0.95em;
            opacity: 0.7;
        }
        @media (max-width: 600px) {
            .container { padding: 1.2em 0.5em; }
            .qrcode { width: 120px; height: 120px; }
        }
    </style>
</head>
<body>
<div class="container">
    <h1>Buy MHCN Premium</h1>
    <div class="desc">
        Unlock premium features and support the project.<br>
        <b>Price:</b> $<?=number_format($PRICE_USD,2)?> (~R$<?=number_format($price_brl,2)?>)
    </div>
    <div class="pay-tabs">
        <button class="pay-tab disabled" data-tab="pix">Pix</button>
        <button class="pay-tab active" data-tab="eth">Ethereum</button>
    </div>
    <div class="pay-method" id="pay-pix">
        <h3>PIX IS DISABLED AS A PAYMENT METHOD!</h3>
        <div class="qrcode">
            <img src="pix.png" alt="Pix QR" width="100%" height="100%">
        </div>
        <div class="pay-info">
            Pix Key:<br>
            <span id="pix-key"><?=$PIX_KEY?></span>
        </div>
        <button class="copy-btn" onclick="copyPix()">Copy Pix Key</button>
        <div style="margin-top:1em;color:#bdbde6;font-size:0.98em;">
            After payment, enter <b>PAGO</b> below and click "Check Payment".
        </div>
        <input type="text" id="pix-code" placeholder="Enter payment code" style="margin-top:0.7em;width:80%;padding:0.5em;border-radius:0.4em;border:1px solid #444;background:#181824;color:#fff;">
        <button class="copy-btn" style="margin-top:0.7em;" onclick="checkPix()">Check Payment</button>
    </div>
    <div class="pay-method" id="pay-eth">
        <div class="qrcode">
            <img src="<?=$eth_qr_img?>" alt="ETH QR" width="100%" height="100%">
        </div>
        <div class="pay-info">
            Send exactly <b><?=$eth_needed?></b> ETH<br>
            to <span id="eth-address"><?=$ETH_ADDRESS?></span>
        </div>
        <button class="copy-btn" onclick="copyEth()">Copy Address</button>
        <div style="margin-top:1em;color:#bdbde6;font-size:0.98em;">
            After payment, paste your transaction hash below and click "Check Payment".
        </div>
        <input type="text" id="eth-tx" placeholder="Paste transaction hash" style="margin-top:0.7em;width:80%;padding:0.5em;border-radius:0.4em;border:1px solid #444;background:#181824;color:#fff;">
        <button class="copy-btn" style="margin-top:0.7em;" onclick="checkEth()">Check Payment</button>
    </div>
    <div class="status" id="status"></div>
    <div class="key-box" id="key-box" style="display:none;"></div>
    <div class="footer">
        Powered by <b>madhatchatnet</b> &middot; Secure payments via Pix & Ethereum
    </div>
</div>
<script>
    // Tab switching
    document.querySelectorAll('.pay-tab').forEach(tab => {
        tab.onclick = function() {
            document.querySelectorAll('.pay-tab').forEach(t => t.classList.remove('active'));
            document.querySelectorAll('.pay-method').forEach(m => m.classList.remove('active'));
            this.classList.add('active');
            document.getElementById('pay-' + this.dataset.tab).classList.add('active');
            document.getElementById('status').textContent = '';
            document.getElementById('key-box').style.display = 'none';
        }
    });

    function copyPix() {
        let txt = document.getElementById('pix-key').textContent;
        navigator.clipboard.writeText(txt);
        showStatus('Pix key copied!', false);
    }
    function copyEth() {
        let txt = document.getElementById('eth-address').textContent;
        navigator.clipboard.writeText(txt);
        showStatus('Ethereum address copied!', false);
    }
    function showStatus(msg, error) {
        let el = document.getElementById('status');
        el.textContent = msg;
        el.style.color = error ? '#ff6b6b' : '#6f42c1';
    }
    function showKey(key) {
        let box = document.getElementById('key-box');
        box.textContent = key;
        box.style.display = 'block';
        showStatus('Your API key is ready! Save it securely.', false);
    }
    function checkPix() {
        let code = document.getElementById('pix-code').value.trim();
        if (!code) {
            showStatus('Enter the payment code.', true);
            return;
        }
        showStatus('Checking payment...', false);
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=check_pix&pix_code=' + encodeURIComponent(code)
        }).then(r => r.json()).then(j => {
            if (j.success) {
                showKey(j.key);
            } else {
                showStatus(j.msg || 'Payment not detected yet.', true);
            }
        });
    }
    function checkEth() {
        let tx = document.getElementById('eth-tx').value.trim();
        if (!tx) {
            showStatus('Paste your transaction hash.', true);
            return;
        }
        showStatus('Checking payment...', false);
        fetch('', {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'action=check_eth&tx=' + encodeURIComponent(tx)
        }).then(r => r.json()).then(j => {
            if (j.success) {
                showKey(j.key);
            } else {
                showStatus(j.msg || 'Payment not detected yet.', true);
            }
        });
    }
</script>
</body>
</html>
