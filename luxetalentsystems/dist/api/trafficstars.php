<?php
require __DIR__.'/../cors.php';
require __DIR__.'/../config.php';

// ═══════════════════════════════════════════════════
// Traffic Stars API Proxy
// ═══════════════════════════════════════════════════

const TS_API = 'https://api.trafficstars.com';
const TS_CLIENT_ID = '16327788472621319463715478791135';
const TS_CLIENT_SECRET = 'eyJhbGciOiJIUzI1NiIsInR5cCIgOiAiSldUIiwia2lkIiA6ICJjOGJmY2YyZi1lZjJlLTQwZGYtYTg4ZC1kYjQ3NmI4MTFiOGMifQ.eyJpYXQiOjE3Nzc1NDM4ODgsImp0aSI6ImViNjU0YjlmLTU1MmEtNDY3Ni04ZDE1LTU4NGQyZjllM2YyYiIsImlzcyI6Imh0dHBzOi8vaWQudHJhZmZpY3N0YXJzLmNvbS9yZWFsbXMvdHJhZmZpY3N0YXJzIiwiYXVkIjoiaHR0cHM6Ly9pZC50cmFmZmljc3RhcnMuY29tL3JlYWxtcy90cmFmZmljc3RhcnMiLCJzdWIiOiI4MTU2ZWE0Ni1hNWRiLTRhYjktYmIyOS0yOWJmZGFmNzM5M2YiLCJ0eXAiOiJPZmZsaW5lIiwiYXpwIjoiY29yZS1hcGkiLCJzZXNzaW9uX3N0YXRlIjoiZTExM2M0NTUtZTQ1Ni00ZjQ4LWFlYzEtZDRlYzU3Njc3N2UyIiwic2NvcGUiOiJvcGVuaWQgZW1haWwgb2ZmbGluZV9hY2Nlc3MgcHJvZmlsZSIsInNpZCI6ImUxMTNjNDU1LWU0NTYtNGY0OC1hZWMxLWQ0ZWM1NzY3NzdlMiJ9.2JMjqlUXTlCN9b3aLQXovfZ96cK6ncjpoBeyfG4D3SU';
const TS_TOKEN_FILE = '/tmp/ts_token.json';

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    switch($action) {
        case 'auth':        handleAuth(); break;
        case 'balance':     handleBalance(); break;
        case 'campaigns':   handleCampaigns(); break;
        case 'campaign':    handleCampaign(); break;
        case 'stats':       handleStats(); break;
        case 'pause':       handlePause(); break;
        case 'resume':      handleResume(); break;
        case 'creatives':   handleCreatives(); break;
        case 'countries':   handleCountries(); break;
        case 'test':        handleTest(); break;
        default: json_response(['success'=>false,'error'=>'Invalid action'],400);
    }
} catch(Throwable $e) {
    error_log('[trafficstars] '.$e->getMessage());
    json_response(['success'=>false,'error'=>$e->getMessage()],400);
}

// ═══ Auth - get/refresh access token ═══
function getAccessToken(): string {
    // Check cached token
    if(file_exists(TS_TOKEN_FILE)) {
        $cached = json_decode(file_get_contents(TS_TOKEN_FILE), true);
        if($cached && isset($cached['access_token']) && $cached['expires_at'] > time() + 60) {
            return $cached['access_token'];
        }
    }

    // Get new token using client credentials
    $ch = curl_init(TS_API.'/v1/auth/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => TS_CLIENT_SECRET,
        ]),
        CURLOPT_TIMEOUT => 15,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if(!$data || !isset($data['access_token'])) {
        // Try with client_id as refresh token
        $ch2 = curl_init(TS_API.'/v1/auth/token');
        curl_setopt_array($ch2, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'refresh_token',
                'refresh_token' => TS_CLIENT_ID,
            ]),
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch2);
        curl_close($ch2);
        $data = json_decode($response, true);
        if(!$data || !isset($data['access_token'])) {
            throw new RuntimeException('Auth failed: '.$response);
        }
    }

    // Cache token
    $data['expires_at'] = time() + ($data['expires_in'] ?? 3600);
    file_put_contents(TS_TOKEN_FILE, json_encode($data));
    return $data['access_token'];
}

function tsRequest(string $method, string $endpoint, ?array $body = null): array {
    $token = getAccessToken();
    $url = TS_API.$endpoint;

    // Append query params for GET
    if($method === 'GET' && $body) {
        $url .= '?'.http_build_query($body);
        $body = null;
    }

    $ch = curl_init($url);
    $headers = [
        'Authorization: Bearer '.$token,
        'Content-Type: application/json',
    ];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 30,
    ]);
    if($body && $method !== 'GET') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $data = json_decode($response, true);
    if($httpCode === 401) {
        // Token expired, delete cache and retry once
        @unlink(TS_TOKEN_FILE);
        $token = getAccessToken();
        $ch2 = curl_init($url);
        $headers[0] = 'Authorization: Bearer '.$token;
        curl_setopt_array($ch2, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
        ]);
        if($body && $method !== 'GET') {
            curl_setopt($ch2, CURLOPT_POSTFIELDS, json_encode($body));
        }
        $response = curl_exec($ch2);
        curl_close($ch2);
        $data = json_decode($response, true);
    }

    return $data ?: ['raw'=>$response, 'http_code'=>$httpCode];
}

// ═══ Handlers ═══

function handleAuth(): void {
    try {
        $token = getAccessToken();
        json_response(['success'=>true,'message'=>'Authenticated','token_preview'=>substr($token,0,20).'...']);
    } catch(Throwable $e) {
        json_response(['success'=>false,'error'=>$e->getMessage()]);
    }
}

function handleBalance(): void {
    $data = tsRequest('GET', '/v2/userinfo/balance');
    json_response($data);
}

function handleCampaigns(): void {
    $params = [];
    if(isset($_GET['status'])) $params['status'] = $_GET['status'];
    if(isset($_GET['page'])) $params['page'] = (int)$_GET['page'];
    if(isset($_GET['size'])) $params['size'] = (int)$_GET['size'];
    $params['size'] = $params['size'] ?? 50;
    $data = tsRequest('GET', '/v1.1/campaigns', $params);
    json_response($data);
}

function handleCampaign(): void {
    $id = (int)($_GET['id'] ?? 0);
    if(!$id) throw new RuntimeException('Missing: id');
    $data = tsRequest('GET', '/v1.1/campaigns/'.$id);
    json_response($data);
}

function handleStats(): void {
    $json = json_decode(file_get_contents('php://input'), true) ?: $_GET;
    $campaignId = $json['campaign_id'] ?? $_GET['campaign_id'] ?? '';
    $dateFrom = $json['date_from'] ?? $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
    $dateTo = $json['date_to'] ?? $_GET['date_to'] ?? date('Y-m-d');
    $groupBy = $json['group_by'] ?? $_GET['group_by'] ?? 'day';

    $params = [
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'group_by' => $groupBy,
    ];
    if($campaignId) $params['campaign_id'] = $campaignId;

    $data = tsRequest('GET', '/v1.1/statistics/campaign', $params);
    json_response($data);
}

function handlePause(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id'] ?? 0);
    if(!$id) throw new RuntimeException('Missing: id');
    $data = tsRequest('PATCH', '/v1.1/campaigns/'.$id, ['status'=>'paused']);
    json_response($data);
}

function handleResume(): void {
    $json = json_decode(file_get_contents('php://input'), true);
    $id = (int)($json['id'] ?? 0);
    if(!$id) throw new RuntimeException('Missing: id');
    $data = tsRequest('PATCH', '/v1.1/campaigns/'.$id, ['status'=>'active']);
    json_response($data);
}

function handleCreatives(): void {
    $campaignId = $_GET['campaign_id'] ?? '';
    $params = ['size'=>50];
    if($campaignId) $params['campaign_id'] = $campaignId;
    $data = tsRequest('GET', '/v1.1/creatives', $params);
    json_response($data);
}

function handleCountries(): void {
    $data = tsRequest('GET', '/v1.1/countries');
    json_response($data);
}

function handleTest(): void {
    // Test auth + balance
    try {
        $token = getAccessToken();
        $balance = tsRequest('GET', '/v2/userinfo/balance');
        json_response(['success'=>true,'auth'=>'OK','balance'=>$balance]);
    } catch(Throwable $e) {
        json_response(['success'=>false,'error'=>$e->getMessage()]);
    }
}

// ═══ Helpers ═══
function getPDO(): PDO {
    static $pdo = null;
    if(!$pdo) $pdo = new PDO('mysql:host='.DB_HOST.';dbname='.DB_NAME.';charset=utf8mb4', DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    return $pdo;
}
