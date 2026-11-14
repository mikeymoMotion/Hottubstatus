<?php
// ===== Put your admin password on the line below (no quotes needed) =====
$ADMIN_PASSWORD = <<<'PASSWORD'
change-me
PASSWORD;
// ===== Fridge settings (see README) ======================================
// Your Anthropic API key, used to count drinks in fridge photos:
$ANTHROPIC_API_KEY = <<<'KEY'
paste-your-anthropic-api-key-here
KEY;
// A second password, just for the fridge camera (the Raspberry Pi):
$DEVICE_KEY = <<<'KEY'
change-me-too
KEY;
// =========================================================================
$ADMIN_PASSWORD = trim($ADMIN_PASSWORD);
$ANTHROPIC_API_KEY = trim($ANTHROPIC_API_KEY);
$DEVICE_KEY = trim($DEVICE_KEY);

// The rest of this file is the page itself. Requests to ?api are the API:
//   GET  ?api                                    -> { ready, updatedAt }
//   POST ?api  { password, action: "login" }     -> check admin password
//   POST ?api  { password, ready: true|false }   -> set status
//   GET  ?api=fridge                             -> fridge contents
//   POST ?api  { password, action: "fridge-save", items, catalog } -> save fridge by hand
//   POST ?api=fridge-photo  (multipart: photo, and password or key) -> count drinks in a photo

$dataFile = __DIR__ . '/status.json';
$fridgeFile = __DIR__ . '/fridge.json';

if (isset($_GET['api'])) {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');

    function respond($code, $body) {
        http_response_code($code);
        echo json_encode($body);
        exit;
    }

    function loadStatus($dataFile) {
        $status = @json_decode(@file_get_contents($dataFile), true);
        return is_array($status) ? $status : ['ready' => false, 'updatedAt' => gmdate('c')];
    }

    function loadFridge($fridgeFile) {
        $fridge = @json_decode(@file_get_contents($fridgeFile), true);
        $fridge = is_array($fridge) ? $fridge : [];
        return [
            'items' => cleanItems($fridge['items'] ?? []),
            'catalog' => cleanCatalog($fridge['catalog'] ?? []),
            'note' => (string)($fridge['note'] ?? ''),
            'source' => (string)($fridge['source'] ?? ''),
            'updatedAt' => $fridge['updatedAt'] ?? null,
        ];
    }

    function saveFridge($fridgeFile, $fridge) {
        if (file_put_contents($fridgeFile, json_encode($fridge), LOCK_EX) === false) {
            respond(500, ['error' => 'could not save fridge']);
        }
    }

    function cleanText($value, $max) {
        $text = trim(preg_replace('/\s+/', ' ', (string)$value));
        return function_exists('mb_substr') ? mb_substr($text, 0, $max) : substr($text, 0, $max);
    }

    function cleanItems($items) {
        $clean = [];
        foreach (is_array($items) ? $items : [] as $item) {
            $name = cleanText($item['name'] ?? '', 80);
            if ($name === '') continue;
            $confidence = $item['confidence'] ?? 'high';
            $clean[] = [
                'name' => $name,
                'count' => max(0, min(999, (int)($item['count'] ?? 0))),
                'confidence' => in_array($confidence, ['high', 'medium', 'low'], true) ? $confidence : 'high',
            ];
        }
        return array_slice($clean, 0, 50);
    }

    function cleanCatalog($catalog) {
        $clean = [];
        foreach (is_array($catalog) ? $catalog : [] as $entry) {
            $name = cleanText($entry['name'] ?? '', 80);
            if ($name === '') continue;
            $clean[] = ['name' => $name, 'hint' => cleanText($entry['hint'] ?? '', 200)];
        }
        return array_slice($clean, 0, 50);
    }

    function isAdmin($given) {
        global $ADMIN_PASSWORD;
        return $ADMIN_PASSWORD !== '' && $ADMIN_PASSWORD !== 'change-me' && hash_equals($ADMIN_PASSWORD, (string)$given);
    }

    function isDevice($given) {
        global $DEVICE_KEY;
        return $DEVICE_KEY !== '' && $DEVICE_KEY !== 'change-me-too' && hash_equals($DEVICE_KEY, (string)$given);
    }

    // Shrinks big photos so they upload to Claude quickly (and stay under its size limit).
    function preparePhoto($bytes) {
        $info = @getimagesizefromstring($bytes);
        if (!$info) return null;
        $mime = $info['mime'];
        if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) return null;

        $maxSide = 1600;
        if (max($info[0], $info[1]) > $maxSide && function_exists('imagecreatefromstring')) {
            $src = @imagecreatefromstring($bytes);
            if ($src) {
                $scale = $maxSide / max($info[0], $info[1]);
                $w = (int)round($info[0] * $scale);
                $h = (int)round($info[1] * $scale);
                $dst = imagecreatetruecolor($w, $h);
                imagecopyresampled($dst, $src, 0, 0, 0, 0, $w, $h, $info[0], $info[1]);
                ob_start();
                imagejpeg($dst, null, 85);
                $bytes = ob_get_clean();
                $mime = 'image/jpeg';
            }
        }
        if (strlen($bytes) > 5 * 1024 * 1024) return null;
        return ['bytes' => $bytes, 'mime' => $mime];
    }

    // Sends the photo to Claude and returns [items, note].
    function countDrinks($photo, $catalog) {
        global $ANTHROPIC_API_KEY;

        $catalogLines = [];
        foreach ($catalog as $entry) {
            $catalogLines[] = '- ' . $entry['name'] . ($entry['hint'] !== '' ? ' (' . $entry['hint'] . ')' : '');
        }
        $catalogText = $catalogLines
            ? "Drinks usually stocked in this fridge:\n" . implode("\n", $catalogLines)
            : 'No drink list has been set up yet.';

        $system = 'You count drinks in photos of a small outdoor mini fridge next to a hot tub. '
            . 'Friends check the results on a website before coming over, to decide whether to bring their own drinks. '
            . 'Count every drink container you can see. Include cans or bottles that are partly hidden behind others, '
            . 'and estimate ones you can reasonably infer (for example a row that clearly continues behind the front can). '
            . 'The shelves may be glass: do not count reflections of cans in the glass, or the same can seen twice through a shelf. '
            . 'Use the names from the drink list when a drink matches one, including matches by the description given '
            . '(some drinks are stored with their labels hidden). Include every drink on the list, with count 0 if you see none. '
            . 'For a drink not on the list, name it by brand and flavor as printed on it, or describe it briefly if you cannot read it. '
            . 'Set confidence to low when much of the count is a guess. '
            . 'In note, write one or two short sentences about anything that made counting hard, for the fridge owner. Leave it empty if nothing did.';

        $payload = [
            'model' => 'claude-opus-5-5',
            'max_tokens' => 16000,
            'fallbacks' => 'default',
            'output_config' => [
                'effort' => 'medium',
                'format' => [
                    'type' => 'json_schema',
                    'schema' => [
                        'type' => 'object',
                        'properties' => [
                            'items' => [
                                'type' => 'array',
                                'items' => [
                                    'type' => 'object',
                                    'properties' => [
                                        'name' => ['type' => 'string'],
                                        'count' => ['type' => 'integer'],
                                        'confidence' => ['type' => 'string', 'enum' => ['high', 'medium', 'low']],
                                    ],
                                    'required' => ['name', 'count', 'confidence'],
                                    'additionalProperties' => false,
                                ],
                            ],
                            'note' => ['type' => 'string'],
                        ],
                        'required' => ['items', 'note'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'system' => $system,
            'messages' => [[
                'role' => 'user',
                'content' => [
                    ['type' => 'image', 'source' => [
                        'type' => 'base64',
                        'media_type' => $photo['mime'],
                        'data' => base64_encode($photo['bytes']),
                    ]],
                    ['type' => 'text', 'text' => $catalogText . "\n\nCount the drinks in this photo of the fridge."],
                ],
            ]],
        ];

        $ch = curl_init(getenv('ANTHROPIC_API_URL') ?: 'https://api.anthropic.com/v1/messages');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 150,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $ANTHROPIC_API_KEY,
                'anthropic-version: 2026-10-21',
                'anthropic-beta: server-side-fallback-2026-07-01',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $raw = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Could not reach Claude: ' . $curlError);
        }
        $response = json_decode($raw, true);
        if ($httpCode !== 200) {
            $message = $response['error']['message'] ?? ('HTTP ' . $httpCode);
            if ($httpCode === 401) $message = 'The Anthropic API key was rejected. Check it in index.php.';
            if ($httpCode === 429 || $httpCode === 529) $message = 'Claude is busy right now. Try again in a minute.';
            throw new RuntimeException($message);
        }
        if (($response['stop_reason'] ?? '') === 'refusal') {
            throw new RuntimeException('Claude declined to read this photo. Try another one.');
        }
        if (($response['stop_reason'] ?? '') === 'max_tokens') {
            throw new RuntimeException('Claude ran out of room before finishing. Try again.');
        }

        $text = '';
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') $text .= $block['text'];
        }
        $result = json_decode($text, true);
        if (!is_array($result) || !isset($result['items'])) {
            throw new RuntimeException('Claude sent back something unexpected. Try again.');
        }
        return [cleanItems($result['items']), cleanText($result['note'] ?? '', 400)];
    }

    $method = $_SERVER['REQUEST_METHOD'];
    $route = (string)$_GET['api'];

    if ($method === 'GET' && $route === 'fridge') {
        respond(200, loadFridge($fridgeFile));
    }

    if ($method === 'GET') {
        respond(200, loadStatus($dataFile));
    }

    if ($method !== 'POST') {
        respond(405, ['error' => 'method not allowed']);
    }

    if ($route === 'fridge-photo') {
        $given = $_POST['password'] ?? '';
        $key = $_POST['key'] ?? ($_SERVER['HTTP_X_DEVICE_KEY'] ?? '');
        if (!isAdmin($given) && !isDevice($key)) {
            respond(401, ['error' => 'unauthorized']);
        }
        if ($ANTHROPIC_API_KEY === '' || $ANTHROPIC_API_KEY === 'paste-your-anthropic-api-key-here') {
            respond(500, ['error' => 'Add your Anthropic API key to index.php first.']);
        }
        $upload = $_FILES['photo'] ?? null;
        if (!$upload || $upload['error'] !== UPLOAD_ERR_OK) {
            respond(400, ['error' => 'No photo was received. It may be too big for the server.']);
        }
        $photo = preparePhoto(file_get_contents($upload['tmp_name']));
        if (!$photo) {
            respond(400, ['error' => 'That file is not a photo the server can read (use JPEG or PNG).']);
        }

        @set_time_limit(180);
        $fridge = loadFridge($fridgeFile);
        try {
            [$items, $note] = countDrinks($photo, $fridge['catalog']);
        } catch (RuntimeException $e) {
            respond(502, ['error' => $e->getMessage()]);
        }

        $fridge['items'] = $items;
        $fridge['note'] = $note;
        $fridge['source'] = isAdmin($given) ? 'photo' : 'camera';
        $fridge['updatedAt'] = gmdate('c');
        saveFridge($fridgeFile, $fridge);
        respond(200, $fridge);
    }

    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $given = (string)($body['password'] ?? '');

    if (!isAdmin($given)) {
        respond(401, ['error' => 'unauthorized']);
    }

    if (($body['action'] ?? '') === 'login') {
        respond(200, ['ok' => true]);
    }

    if (($body['action'] ?? '') === 'fridge-save') {
        $fridge = loadFridge($fridgeFile);
        $fridge['items'] = cleanItems($body['items'] ?? []);
        $fridge['catalog'] = cleanCatalog($body['catalog'] ?? []);
        $fridge['note'] = '';
        $fridge['source'] = 'manual';
        $fridge['updatedAt'] = gmdate('c');
        saveFridge($fridgeFile, $fridge);
        respond(200, $fridge);
    }

    $status = ['ready' => !empty($body['ready']), 'updatedAt' => gmdate('c')];
    if (file_put_contents($dataFile, json_encode($status), LOCK_EX) === false) {
        respond(500, ['error' => 'could not save status']);
    }
    respond(200, $status);
}
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Hot Tub Status</title>
  <style>
    * { box-sizing: border-box; margin: 0; }

    html, body { height: 100%; }

    body {
      font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif;
      color: #fff;
      background: #1b2a4a;
      overflow: hidden;
    }

    /* Two stacked gradient layers so we can crossfade between them. */
    .bg {
      position: fixed;
      inset: 0;
      transition: opacity 1.2s ease;
    }
    .bg-warm { background: linear-gradient(160deg, #ffb347 0%, #ff7b54 45%, #d7385e 100%); opacity: 0; }
    .bg-cool { background: linear-gradient(160deg, #3a7bd5 0%, #1f4e8c 50%, #0f2027 100%); }
    body.ready .bg-warm { opacity: 1; }
    body.ready .bg-cool { opacity: 0; }

    /* Steam */
    .steam {
      position: fixed;
      inset: 0;
      pointer-events: none;
      opacity: 0;
      transition: opacity 1.5s ease;
    }
    body.ready .steam { opacity: 1; }

    /* Each wisp is a thin, soft streak that sways as it rises, then spreads out and fades. */
    .wisp {
      position: absolute;
      bottom: -30vh;
      width: 5vmax;
      height: 30vmax;
      border-radius: 50%;
      background: radial-gradient(ellipse at center, rgba(255,255,255,0.5) 0%, rgba(255,255,255,0.15) 45%, rgba(255,255,255,0) 70%);
      filter: blur(10px);
      opacity: 0;
      animation: rise-a 6s ease-in infinite;
    }
    .wisp:nth-child(even) { animation-name: rise-b; }
    .wisp:nth-child(1)  { left: 4%;  animation-duration: 6s;   animation-delay: 0s; }
    .wisp:nth-child(2)  { left: 12%; animation-duration: 5.2s; animation-delay: -2.1s; }
    .wisp:nth-child(3)  { left: 21%; animation-duration: 6.6s; animation-delay: -4s; }
    .wisp:nth-child(4)  { left: 29%; animation-duration: 5.5s; animation-delay: -1s; }
    .wisp:nth-child(5)  { left: 38%; animation-duration: 6.2s; animation-delay: -3.3s; }
    .wisp:nth-child(6)  { left: 46%; animation-duration: 5s;   animation-delay: -0.5s; }
    .wisp:nth-child(7)  { left: 55%; animation-duration: 6.8s; animation-delay: -5.2s; }
    .wisp:nth-child(8)  { left: 63%; animation-duration: 5.4s; animation-delay: -2.7s; }
    .wisp:nth-child(9)  { left: 72%; animation-duration: 6.1s; animation-delay: -1.6s; }
    .wisp:nth-child(10) { left: 80%; animation-duration: 5.7s; animation-delay: -4.4s; }
    .wisp:nth-child(11) { left: 88%; animation-duration: 6.4s; animation-delay: -0.9s; }
    .wisp:nth-child(12) { left: 95%; animation-duration: 5.3s; animation-delay: -3.7s; }

    @keyframes rise-a {
      0%   { transform: translate(0, 0) rotate(0deg) scale(0.5, 0.7); opacity: 0; }
      15%  { opacity: 0.6; }
      35%  { transform: translate(2.5vw, -35vh) rotate(6deg) scale(0.8, 1); }
      60%  { transform: translate(-1.5vw, -65vh) rotate(-5deg) scale(1.3, 1.1); opacity: 0.35; }
      100% { transform: translate(3vw, -105vh) rotate(8deg) scale(2.2, 1.2); opacity: 0; }
    }
    @keyframes rise-b {
      0%   { transform: translate(0, 0) rotate(0deg) scale(0.5, 0.7); opacity: 0; }
      15%  { opacity: 0.55; }
      35%  { transform: translate(-2.5vw, -35vh) rotate(-7deg) scale(0.8, 1); }
      60%  { transform: translate(2vw, -65vh) rotate(5deg) scale(1.3, 1.1); opacity: 0.3; }
      100% { transform: translate(-3vw, -105vh) rotate(-8deg) scale(2.2, 1.2); opacity: 0; }
    }

    @media (prefers-reduced-motion: reduce) {
      .wisp { animation: none; }
      .steam { display: none; }
    }

    /* Status */
    main {
      position: relative;
      height: 100%;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: center;
      text-align: center;
      padding: 16px;
    }
    .label {
      font-size: clamp(1rem, 3vw, 1.6rem);
      font-weight: 600;
      letter-spacing: 0.3em;
      text-transform: uppercase;
      opacity: 0.8;
      margin-bottom: 0.5em;
    }
    #status {
      font-size: clamp(1.75rem, 9vw, 6.5rem);
      font-weight: 900;
      line-height: 0.95;
      letter-spacing: -0.02em;
      text-transform: uppercase;
      text-shadow: 0 6px 30px rgba(0,0,0,0.25);
    }

    /* Admin controls: a faint lock in the corner, and a switch once unlocked. */
    .admin {
      position: fixed;
      right: 16px;
      bottom: 16px;
      display: flex;
      align-items: center;
      gap: 12px;
    }
    .lock {
      background: none;
      border: none;
      color: #fff;
      font-size: 1.2rem;
      opacity: 0.25;
      cursor: pointer;
      padding: 8px;
    }
    .lock:hover { opacity: 0.7; }

    .login { display: none; gap: 8px; }
    .login.open { display: flex; }
    .login input, .login button {
      font: inherit;
      font-size: 0.9rem;
      padding: 8px 12px;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.4);
      background: rgba(0,0,0,0.25);
      color: #fff;
    }
    .login button { cursor: pointer; }

    .switch { display: none; align-items: center; gap: 10px; font-size: 0.9rem; font-weight: 600; cursor: pointer; }
    body.admin-on .switch { display: flex; }
    body.admin-on .lock { display: none; }
    .switch input { display: none; }
    .track {
      width: 60px;
      height: 32px;
      border-radius: 16px;
      background: rgba(0,0,0,0.35);
      border: 1px solid rgba(255,255,255,0.4);
      position: relative;
      transition: background 0.3s;
    }
    .track::after {
      content: "";
      position: absolute;
      top: 3px;
      left: 3px;
      width: 24px;
      height: 24px;
      border-radius: 50%;
      background: #fff;
      transition: transform 0.3s;
    }
    .switch input:checked + .track { background: rgba(255,255,255,0.35); }
    .switch input:checked + .track::after { transform: translateX(28px); }

    /* Fridge button and panel */
    .fridge-open {
      margin-top: 2.2em;
      font: inherit;
      font-size: 1rem;
      font-weight: 600;
      color: #fff;
      padding: 12px 22px;
      border-radius: 999px;
      border: 1px solid rgba(255,255,255,0.45);
      background: rgba(255,255,255,0.14);
      backdrop-filter: blur(8px);
      -webkit-backdrop-filter: blur(8px);
      cursor: pointer;
      transition: background 0.2s;
    }
    .fridge-open:hover { background: rgba(255,255,255,0.24); }

    dialog.fridge {
      margin: auto;
      width: min(440px, calc(100vw - 32px));
      max-height: min(640px, calc(100dvh - 32px));
      padding: 0;
      border: 1px solid rgba(255,255,255,0.3);
      border-radius: 20px;
      color: #fff;
      background: rgba(18, 30, 52, 0.82);
      backdrop-filter: blur(18px);
      -webkit-backdrop-filter: blur(18px);
      box-shadow: 0 24px 60px rgba(0,0,0,0.4);
      overflow: hidden;
    }
    dialog.fridge[open] { display: flex; flex-direction: column; }
    dialog.fridge::backdrop { background: rgba(0,0,0,0.35); }

    .fridge-head {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 12px;
      padding: 20px 20px 4px;
    }
    .fridge-head h2 { font-size: 1.25rem; font-weight: 800; }
    .fridge-close {
      background: none;
      border: none;
      color: #fff;
      font-size: 1.5rem;
      line-height: 1;
      opacity: 0.7;
      cursor: pointer;
      padding: 4px 8px;
    }
    .fridge-close:hover { opacity: 1; }
    .fridge-when { padding: 0 20px 12px; font-size: 0.85rem; opacity: 0.7; }
    .fridge-when.stale { color: #ffd479; opacity: 1; }

    .fridge-body { overflow-y: auto; padding: 0 20px 20px; }

    .drinks { list-style: none; padding: 0; }
    .drinks li {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 12px 0;
      border-top: 1px solid rgba(255,255,255,0.12);
    }
    .drinks li:first-child { border-top: none; }
    .drink-name { flex: 1; font-weight: 600; }
    .drink-count { font-size: 0.9rem; opacity: 0.75; font-variant-numeric: tabular-nums; white-space: nowrap; }
    .level {
      font-size: 0.75rem;
      font-weight: 700;
      padding: 4px 10px;
      border-radius: 999px;
      white-space: nowrap;
    }
    .level.plenty { background: rgba(120, 220, 150, 0.25); color: #b8f5c8; }
    .level.low { background: rgba(255, 200, 90, 0.25); color: #ffe0a0; }
    .level.out { background: rgba(255, 110, 110, 0.22); color: #ffc0c0; }
    .drinks li.is-out .drink-name { opacity: 0.6; }

    .fridge-empty { padding: 16px 0; opacity: 0.75; line-height: 1.5; }
    .fridge-hint { margin-top: 14px; font-size: 0.85rem; opacity: 0.7; line-height: 1.45; }

    /* Fridge admin */
    .fridge-admin {
      display: none;
      margin-top: 18px;
      padding-top: 16px;
      border-top: 1px solid rgba(255,255,255,0.25);
    }
    body.admin-on .fridge-admin { display: block; }
    .fridge-admin h3 {
      font-size: 0.75rem;
      font-weight: 700;
      letter-spacing: 0.15em;
      text-transform: uppercase;
      opacity: 0.7;
      margin-bottom: 10px;
    }
    .fridge-note {
      font-size: 0.85rem;
      line-height: 1.45;
      padding: 10px 12px;
      border-radius: 10px;
      background: rgba(255,255,255,0.08);
      margin-bottom: 12px;
    }
    .fridge-note:empty { display: none; }
    .fridge-message { font-size: 0.85rem; margin: 10px 0; min-height: 1.2em; line-height: 1.4; }
    .fridge-message.error { color: #ffc0c0; }

    .btn-row { display: flex; flex-wrap: wrap; gap: 8px; }
    .btn {
      font: inherit;
      font-size: 0.9rem;
      font-weight: 600;
      color: #fff;
      padding: 10px 14px;
      border-radius: 10px;
      border: 1px solid rgba(255,255,255,0.35);
      background: rgba(255,255,255,0.12);
      cursor: pointer;
      display: inline-flex;
      align-items: center;
      gap: 6px;
    }
    .btn:hover { background: rgba(255,255,255,0.2); }
    .btn.primary { background: #fff; color: #1b2a4a; border-color: #fff; }
    .btn:disabled, .btn.disabled { opacity: 0.5; cursor: default; pointer-events: none; }
    .btn input[type=file] { display: none; }

    .editor { display: none; margin-top: 14px; }
    dialog.editing .editor { display: block; }
    dialog.editing .not-editing,
    dialog.editing .drinks,
    dialog.editing .fridge-hint,
    dialog.editing .fridge-note { display: none; }
    .edit-row { display: flex; align-items: center; gap: 6px; margin-bottom: 8px; }
    .edit-row input[type=text], .editor textarea {
      font: inherit;
      font-size: 0.9rem;
      color: #fff;
      padding: 8px 10px;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.3);
      background: rgba(0,0,0,0.25);
      min-width: 0;
    }
    .edit-row input[type=text] { flex: 1; }
    .edit-row .num {
      width: 2.6em;
      text-align: center;
      font-weight: 700;
      font-variant-numeric: tabular-nums;
    }
    .icon-btn {
      font: inherit;
      font-size: 1rem;
      width: 34px;
      height: 34px;
      flex: none;
      border-radius: 8px;
      border: 1px solid rgba(255,255,255,0.3);
      background: rgba(255,255,255,0.1);
      color: #fff;
      cursor: pointer;
    }
    .icon-btn:hover { background: rgba(255,255,255,0.2); }
    .editor label { display: block; font-size: 0.85rem; font-weight: 600; margin: 16px 0 6px; }
    .editor textarea { width: 100%; min-height: 110px; resize: vertical; line-height: 1.4; }
    .editor small { display: block; font-size: 0.8rem; opacity: 0.7; margin-top: 6px; line-height: 1.4; }
    .editor .btn-row { margin-top: 14px; }
  </style>
</head>
<body>
  <div class="bg bg-cool"></div>
  <div class="bg bg-warm"></div>
  <div class="steam" aria-hidden="true">
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
    <div class="wisp"></div><div class="wisp"></div><div class="wisp"></div>
  </div>

  <main>
    <div class="label">Hot Tub</div>
    <h1 id="status">&nbsp;</h1>
    <button class="fridge-open" id="fridge-open" type="button">&#129380; What's in the fridge?</button>
  </main>

  <dialog class="fridge" id="fridge" aria-labelledby="fridge-title">
    <div class="fridge-head">
      <h2 id="fridge-title">In the fridge</h2>
      <button class="fridge-close" id="fridge-close" type="button" aria-label="Close">&times;</button>
    </div>
    <div class="fridge-when" id="fridge-when"></div>
    <div class="fridge-body">
      <ul class="drinks" id="drinks"></ul>
      <p class="fridge-hint" id="fridge-hint"></p>

      <section class="fridge-admin" id="fridge-admin">
        <h3>Fridge admin</h3>
        <p class="fridge-note" id="fridge-note"></p>
        <div class="btn-row not-editing">
          <label class="btn primary" id="photo-btn">
            &#128247; Count from photo
            <input type="file" id="photo-input" accept="image/*">
          </label>
          <button class="btn" id="edit-btn" type="button">&#9998; Edit</button>
        </div>
        <p class="fridge-message" id="fridge-message" role="status"></p>

        <div class="editor" id="editor">
          <div id="edit-rows"></div>
          <button class="btn" id="add-row" type="button">+ Add drink</button>

          <label for="catalog">Drinks you usually stock</label>
          <textarea id="catalog" placeholder="Dr Pepper Blackberry - purple and red cans&#10;Gatorade Glacier Freeze - blue bottles, stored bottoms out"></textarea>
          <small>One per line. After a dash, describe what it looks like in the fridge. Claude uses this list to name what it sees, even when labels are hidden.</small>

          <div class="btn-row">
            <button class="btn primary" id="save-btn" type="button">Save</button>
            <button class="btn" id="cancel-btn" type="button">Cancel</button>
          </div>
        </div>
      </section>
    </div>
  </dialog>

  <div class="admin">
    <form class="login" id="login">
      <input type="password" id="password" placeholder="Admin password" autocomplete="current-password">
      <button type="submit">Unlock</button>
    </form>
    <label class="switch">
      <span>Ready</span>
      <input type="checkbox" id="toggle">
      <span class="track"></span>
    </label>
    <button class="lock" id="lock" title="Admin" aria-label="Admin">&#128274;</button>
  </div>

  <script>
    const statusEl = document.getElementById("status");
    const toggle = document.getElementById("toggle");
    const loginForm = document.getElementById("login");
    const passwordInput = document.getElementById("password");
    const KEY = "hottub-admin-password";

    function getPassword() {
      try { return localStorage.getItem(KEY); } catch { return null; }
    }
    function setPassword(pw) {
      try { pw ? localStorage.setItem(KEY, pw) : localStorage.removeItem(KEY); } catch {}
    }

    function render(ready) {
      statusEl.textContent = ready ? "Ready" : "Not Ready";
      document.body.classList.toggle("ready", ready);
      toggle.checked = ready;
    }

    async function refresh() {
      try {
        const res = await fetch("?api", { cache: "no-store" });
        render((await res.json()).ready);
      } catch {}
    }

    async function login(pw) {
      const res = await fetch("?api", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ action: "login", password: pw }),
      });
      if (!res.ok) return false;
      setPassword(pw);
      document.body.classList.add("admin-on");
      loginForm.classList.remove("open");
      return true;
    }

    function signOut() {
      setPassword(null);
      document.body.classList.remove("admin-on");
    }

    document.getElementById("lock").addEventListener("click", () => {
      loginForm.classList.toggle("open");
      passwordInput.focus();
    });

    loginForm.addEventListener("submit", async (e) => {
      e.preventDefault();
      if (!(await login(passwordInput.value))) {
        passwordInput.value = "";
        passwordInput.placeholder = "Wrong password";
      }
    });

    toggle.addEventListener("change", async () => {
      const ready = toggle.checked;
      render(ready);
      const res = await fetch("?api", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({ ready, password: getPassword() }),
      });
      if (res.status === 401) signOut();
      refresh();
    });

    // ---------- Fridge ----------
    const fridgeDialog = document.getElementById("fridge");
    const drinksEl = document.getElementById("drinks");
    const whenEl = document.getElementById("fridge-when");
    const hintEl = document.getElementById("fridge-hint");
    const noteEl = document.getElementById("fridge-note");
    const messageEl = document.getElementById("fridge-message");
    const editRowsEl = document.getElementById("edit-rows");
    const catalogEl = document.getElementById("catalog");
    const photoInput = document.getElementById("photo-input");
    const photoBtn = document.getElementById("photo-btn");
    let fridge = null;

    function level(count) {
      if (count <= 0) return ["out", "Out"];
      if (count <= 3) return ["low", "Running low"];
      return ["plenty", "Plenty"];
    }

    function timeAgo(iso) {
      const minutes = Math.round((Date.now() - new Date(iso).getTime()) / 60000);
      if (minutes < 1) return "just now";
      if (minutes < 60) return minutes + (minutes === 1 ? " minute ago" : " minutes ago");
      const hours = Math.round(minutes / 60);
      if (hours < 24) return hours + (hours === 1 ? " hour ago" : " hours ago");
      const days = Math.round(hours / 24);
      return days + (days === 1 ? " day ago" : " days ago");
    }

    // Catalog order first, then anything else; empty drinks sink to the bottom.
    function sortedItems(data) {
      const order = new Map(data.catalog.map((c, i) => [c.name.toLowerCase(), i]));
      return [...data.items].sort((a, b) => {
        if ((a.count > 0) !== (b.count > 0)) return a.count > 0 ? -1 : 1;
        const ai = order.get(a.name.toLowerCase()) ?? 999;
        const bi = order.get(b.name.toLowerCase()) ?? 999;
        return ai - bi;
      });
    }

    function renderFridge(data) {
      fridge = data;
      drinksEl.replaceChildren();
      const items = sortedItems(data);

      if (!data.updatedAt) {
        whenEl.textContent = "";
        drinksEl.innerHTML = '<li class="fridge-empty">Nothing has been logged yet. Check back soon!</li>';
      } else {
        const stale = Date.now() - new Date(data.updatedAt).getTime() > 3 * 24 * 3600 * 1000;
        whenEl.textContent = "Checked " + timeAgo(data.updatedAt) + (stale ? ", so this may be out of date" : "");
        whenEl.classList.toggle("stale", stale);

        if (items.length === 0) {
          drinksEl.innerHTML = '<li class="fridge-empty">The fridge looks empty.</li>';
        }
        for (const item of items) {
          const [cls, label] = level(item.count);
          const li = document.createElement("li");
          li.classList.toggle("is-out", item.count <= 0);
          const name = document.createElement("span");
          name.className = "drink-name";
          name.textContent = item.name;
          const count = document.createElement("span");
          count.className = "drink-count";
          if (item.count > 0) {
            count.textContent = (item.confidence === "high" ? "" : "about ") + item.count;
          }
          const pill = document.createElement("span");
          pill.className = "level " + cls;
          pill.textContent = label;
          li.append(name, count, pill);
          drinksEl.append(li);
        }
      }

      const wanted = items.filter((i) => i.count <= 0).map((i) => i.name);
      hintEl.textContent = wanted.length && data.updatedAt
        ? "Out of " + wanted.join(", ") + ". Grab some on the way if you want it!"
        : "";
      noteEl.textContent = data.note ? "Claude's note: " + data.note : "";
    }

    async function loadFridge() {
      try {
        const res = await fetch("?api=fridge", { cache: "no-store" });
        renderFridge(await res.json());
      } catch {
        whenEl.textContent = "Couldn't load the fridge right now.";
      }
    }

    function showMessage(text, isError) {
      messageEl.textContent = text;
      messageEl.classList.toggle("error", !!isError);
    }

    document.getElementById("fridge-open").addEventListener("click", () => {
      showMessage("");
      fridgeDialog.showModal();
      loadFridge();
    });
    document.getElementById("fridge-close").addEventListener("click", () => fridgeDialog.close());
    fridgeDialog.addEventListener("click", (e) => {
      if (e.target === fridgeDialog) fridgeDialog.close();
    });
    fridgeDialog.addEventListener("close", () => fridgeDialog.classList.remove("editing"));

    // Shrink the photo in the browser first: faster uploads, and phones' HEIC photos become JPEG.
    async function shrinkPhoto(file) {
      const url = URL.createObjectURL(file);
      try {
        const img = await new Promise((resolve, reject) => {
          const i = new Image();
          i.onload = () => resolve(i);
          i.onerror = reject;
          i.src = url;
        });
        const scale = Math.min(1, 1600 / Math.max(img.naturalWidth, img.naturalHeight));
        const canvas = document.createElement("canvas");
        canvas.width = Math.round(img.naturalWidth * scale);
        canvas.height = Math.round(img.naturalHeight * scale);
        canvas.getContext("2d").drawImage(img, 0, 0, canvas.width, canvas.height);
        return await new Promise((resolve) => canvas.toBlob(resolve, "image/jpeg", 0.85));
      } catch {
        return file;
      } finally {
        URL.revokeObjectURL(url);
      }
    }

    photoInput.addEventListener("change", async () => {
      const file = photoInput.files[0];
      photoInput.value = "";
      if (!file) return;
      photoBtn.classList.add("disabled");
      photoInput.disabled = true;
      showMessage("Claude is counting the drinks. This takes about 20–60 seconds…");
      try {
        const form = new FormData();
        form.append("password", getPassword() || "");
        form.append("photo", await shrinkPhoto(file), "fridge.jpg");
        const res = await fetch("?api=fridge-photo", { method: "POST", body: form });
        const data = await res.json().catch(() => ({}));
        if (res.status === 401) {
          signOut();
          showMessage("Your admin password didn't work. Unlock again with the 🔒.", true);
        } else if (!res.ok) {
          showMessage(data.error || "Something went wrong. Try again.", true);
        } else {
          renderFridge(data);
          showMessage("Updated! Check the counts and fix anything with Edit.");
        }
      } catch {
        showMessage("Couldn't reach the server. Try again.", true);
      } finally {
        photoInput.disabled = false;
        photoBtn.classList.remove("disabled");
      }
    });

    // ----- Editing counts and the drink list by hand -----
    function addEditRow(name = "", count = 0) {
      const row = document.createElement("div");
      row.className = "edit-row";
      const nameInput = document.createElement("input");
      nameInput.type = "text";
      nameInput.value = name;
      nameInput.placeholder = "Drink name";
      nameInput.setAttribute("aria-label", "Drink name");
      const minus = document.createElement("button");
      minus.type = "button";
      minus.className = "icon-btn";
      minus.textContent = "−";
      minus.setAttribute("aria-label", "One less");
      const num = document.createElement("span");
      num.className = "num";
      num.textContent = count;
      const plus = document.createElement("button");
      plus.type = "button";
      plus.className = "icon-btn";
      plus.textContent = "+";
      plus.setAttribute("aria-label", "One more");
      const remove = document.createElement("button");
      remove.type = "button";
      remove.className = "icon-btn";
      remove.textContent = "×";
      remove.setAttribute("aria-label", "Remove drink");
      minus.onclick = () => { num.textContent = Math.max(0, +num.textContent - 1); };
      plus.onclick = () => { num.textContent = +num.textContent + 1; };
      remove.onclick = () => row.remove();
      row.append(nameInput, minus, num, plus, remove);
      editRowsEl.append(row);
      return nameInput;
    }

    function parseCatalog(text) {
      return text.split("\n").map((line) => {
        const parts = line.split(/\s+[-–—]\s+/);
        return { name: parts.shift().trim(), hint: parts.join(" - ").trim() };
      }).filter((entry) => entry.name);
    }

    document.getElementById("edit-btn").addEventListener("click", () => {
      editRowsEl.replaceChildren();
      const items = fridge ? sortedItems(fridge) : [];
      // Start with every drink on the list, so restocking is just tapping +.
      const names = new Set(items.map((i) => i.name.toLowerCase()));
      for (const item of items) addEditRow(item.name, item.count);
      for (const entry of fridge ? fridge.catalog : []) {
        if (!names.has(entry.name.toLowerCase())) addEditRow(entry.name, 0);
      }
      catalogEl.value = (fridge ? fridge.catalog : [])
        .map((c) => c.name + (c.hint ? " - " + c.hint : "")).join("\n");
      showMessage("");
      fridgeDialog.classList.add("editing");
    });

    document.getElementById("add-row").addEventListener("click", () => addEditRow().focus());
    document.getElementById("cancel-btn").addEventListener("click", () => fridgeDialog.classList.remove("editing"));

    document.getElementById("save-btn").addEventListener("click", async () => {
      const items = [...editRowsEl.querySelectorAll(".edit-row")].map((row) => ({
        name: row.querySelector("input").value.trim(),
        count: +row.querySelector(".num").textContent,
        confidence: "high",
      })).filter((i) => i.name);
      const res = await fetch("?api", {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({
          action: "fridge-save",
          password: getPassword(),
          items,
          catalog: parseCatalog(catalogEl.value),
        }),
      });
      if (res.status === 401) {
        signOut();
        showMessage("Your admin password didn't work. Unlock again with the 🔒.", true);
        return;
      }
      if (!res.ok) {
        showMessage("Couldn't save. Try again.", true);
        return;
      }
      renderFridge(await res.json());
      fridgeDialog.classList.remove("editing");
      showMessage("Saved.");
    });

    const saved = getPassword();
    if (saved) login(saved);

    refresh();
    setInterval(refresh, 15000);
  </script>
</body>
</html>
