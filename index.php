<?php
// ============================================================
// SOZLAMALAR — environment variables dan o'qiladi
// ============================================================
define('FB_PIXEL_ID',     getenv('FB_PIXEL_ID'));
define('FB_ACCESS_TOKEN', getenv('FB_ACCESS_TOKEN'));
define('FB_API_VERSION',  'v25.0');

define('STATUS_MAP', [
    // ═══ WoodlyWorld B2C (5745133) ═══
    '50441950' => ['Lead',        null],
    '51110119' => ['CustomEvent', 'NoAnswer'],
    '78151198' => ['Contact',     null],
    '72942806' => ['CustomEvent', 'QualifiedLead'],
    '50441956' => ['CustomEvent', 'Objection'],
    '84963486' => ['CustomEvent', 'Reanimation'],
    '80904566' => ['Purchase',    null],
    '142'      => ['Purchase',    null],
    '143'      => ['CustomEvent', 'ClosedLost'],

    // ═══ EcoPalma B2C (7479766) ═══
    '77194962' => ['Lead',        null],
    '62059254' => ['CustomEvent', 'NoAnswer'],
    '63817154' => ['CustomEvent', 'QualifiedLead'],
    '62059434' => ['CustomEvent', 'Objection'],
    '68580990' => ['Purchase',    null],

    // ═══ Baby Joy (7272838) ═══
    '77456146' => ['Lead',        null],
    '60619362' => ['CustomEvent', 'NoAnswer'],
    '77689170' => ['Contact',     null],
    '60619366' => ['CustomEvent', 'QualifiedLead'],
    '60619414' => ['CustomEvent', 'Objection'],
    '80921382' => ['Purchase',    null],

    // ═══ Vodopad Rums (5866960) ═══
    '77738790' => ['Lead',        null],
    '77778442' => ['CustomEvent', 'NoAnswer'],
    '77797162' => ['Contact',     null],
    '51283312' => ['CustomEvent', 'QualifiedLead'],
    '51283315' => ['CustomEvent', 'Objection'],
    '80920030' => ['CustomEvent', 'SMSOffer'],
    '80920034' => ['Purchase',    null],

    // ═══ METAL DECOR (10136718) ═══
    '80313250' => ['Lead',        null],
    '80313202' => ['CustomEvent', 'NoAnswer'],
    '80313198' => ['Contact',     null],
    '80313254' => ['CustomEvent', 'QualifiedLead'],
    '80313206' => ['CustomEvent', 'Objection'],
    '80921418' => ['Purchase',    null],

    // ═══ Мебель 3/1 (10316026) ═══
    '81693398' => ['Lead',        null],
    '81857718' => ['CustomEvent', 'NoAnswer'],
    '81597198' => ['Contact',     null],
    '81597202' => ['CustomEvent', 'QualifiedLead'],
    '81597206' => ['CustomEvent', 'Objection'],
    '82101514' => ['Purchase',    null],
]);
// ============================================================

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['health'])) {
    echo json_encode(['status' => 'ok', 'time' => date('Y-m-d H:i:s')]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method Not Allowed');
}

$input = file_get_contents('php://input');
$data  = json_decode($input, true);
if (empty($data)) {
    parse_str($input, $data);
}
if (empty($data)) {
    $data = $_POST;
}

// Log
log_write('webhook_log.txt', "INPUT:\n" . print_r($data, true));

$lead    = extractLeadData($data);
$statusId = (string)($lead['status_id'] ?? '');
$map     = STATUS_MAP;

if (!array_key_exists($statusId, $map)) {
    log_write('webhook_log.txt', "SKIP: unknown status_id={$statusId}");
    echo json_encode(['status' => 'skipped', 'status_id' => $statusId]);
    exit;
}

[$eventName, $customEventName] = $map[$statusId];
$result = sendToFacebookCAPI($lead, $eventName, $customEventName);

http_response_code(200);
echo json_encode([
    'status'     => 'ok',
    'event'      => $eventName . ($customEventName ? "($customEventName)" : ''),
    'lead_id'    => $lead['lead_id'],
    'fb_response'=> $result,
]);


// ============================================================
// FUNKSIYALAR
// ============================================================

function extractLeadData(array $data): array
{
    $lead = $data['leads']['add'][0]
        ?? $data['leads']['status'][0]
        ?? $data['leads']['update'][0]
        ?? $data['leads'][0]
        ?? [];

    $contact = $data['contacts']['add'][0]
        ?? $data['contacts']['update'][0]
        ?? $data['contacts'][0]
        ?? [];

    $phone = '';
    $email = '';
    $name  = $contact['name'] ?? ($lead['name'] ?? '');

    foreach ($contact['custom_fields'] ?? [] as $field) {
        $code  = strtoupper($field['code']  ?? '');
        $fname = strtolower($field['name']  ?? '');
        $val   = $field['values'][0]['value'] ?? '';

        if ($code === 'PHONE' || str_contains($fname, 'phone') || str_contains($fname, 'тел')) {
            $phone = $val;
        }
        if ($code === 'EMAIL' || str_contains($fname, 'email')) {
            $email = $val;
        }
    }

    return [
        'lead_id'     => $lead['id']          ?? uniqid('lead_'),
        'status_id'   => $lead['status_id']   ?? null,
        'pipeline_id' => $lead['pipeline_id'] ?? null,
        'name'        => trim($name),
        'phone'       => normalizePhone($phone),
        'email'       => strtolower(trim($email)),
        'created_at'  => $lead['created_at']  ?? time(),
        'ip'          => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '',
        'user_agent'  => $_SERVER['HTTP_USER_AGENT'] ?? '',
    ];
}

function normalizePhone(string $phone): string
{
    $digits = preg_replace('/\D/', '', $phone);
    return $digits ? hash('sha256', $digits) : '';
}

function h(string $value): string
{
    return hash('sha256', strtolower(trim($value)));
}

function sendToFacebookCAPI(array $lead, string $eventName, ?string $customEventName): array
{
    $url = sprintf(
        'https://graph.facebook.com/%s/%s/events?access_token=%s',
        FB_API_VERSION,
        FB_PIXEL_ID,
        FB_ACCESS_TOKEN
    );

    $userData = [
        'client_ip_address' => $lead['ip'],
        'client_user_agent' => $lead['user_agent'],
    ];

    if ($lead['email'])  $userData['em'] = [h($lead['email'])];
    if ($lead['phone'])  $userData['ph'] = [$lead['phone']];   // allaqachon hash
    if ($lead['name']) {
        $parts = explode(' ', $lead['name'], 2);
        $userData['fn'] = [h($parts[0])];
        if (!empty($parts[1])) $userData['ln'] = [h($parts[1])];
    }

    $customData = ['lead_id' => (string)$lead['lead_id']];
    if ($eventName === 'Purchase') {
        $customData['currency'] = 'UZS';
        $customData['value']    = 1;
    }

    $event = [
        'event_name'    => $eventName,
        'event_time'    => (int)$lead['created_at'],
        'action_source' => 'crm',
        'user_data'     => $userData,
        'custom_data'   => $customData,
    ];

    if ($eventName === 'CustomEvent' && $customEventName) {
        $event['custom_data']['custom_event_name'] = $customEventName;
    }

    $payload = json_encode(['data' => [$event]]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Content-Length: ' . strlen($payload),
        ],
        CURLOPT_TIMEOUT        => 10,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    log_write('fb_log.txt',
        "EVENT: {$eventName}" . ($customEventName ? "({$customEventName})" : '') .
        " | HTTP: {$httpCode}\nPayload: {$payload}\nResponse: {$response}"
    );

    return json_decode($response, true) ?? [];
}

function log_write(string $file, string $message): void
{
    $line = date('Y-m-d H:i:s') . "\n" . $message . "\n" . str_repeat('-', 60) . "\n\n";
    file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
}
