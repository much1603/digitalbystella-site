<?php
/**
 * booking.php — Digital by Stella · RDV visio Google Meet payant (Stripe)
 *
 * Paiement STRICT : le créneau n'est créé que si Stripe confirme le paiement.
 *
 *   1. GET  ?action=slots
 *        -> { ok, slots:[ISO,...], priceCents, currency, durationMin, tz }
 *   2. POST {action:'checkout', start, prenom, nom, email, telephone?, prestation?, message?}
 *        -> { ok, url }   (Stripe Checkout Session)
 *   3. GET  ?action=finalize&session_id=cs_xxx
 *        -> { ok, start, joinUrl }
 *   4. POST (Stripe webhook : Stripe-Signature header)
 *        -> { ok }   (idempotent : crée le RDV même si le client ferme l'onglet)
 *
 * Aucune clé en clair dans ce fichier. Config attendue HORS web root :
 *   <domaine>/private/booking.php :
 *     <?php return [
 *       'google_client_id'       => 'xxx.apps.googleusercontent.com',
 *       'google_client_secret'   => 'GOCSPX-xxxxxxxxxxxx',
 *       'google_refresh_token'   => '1//0xxxxxxxxxxxxxxxx',
 *       'google_calendar_id'     => 'primary',
 *       'organizer_email'        => 'stella.web@yahoo.com',
 *       'organizer_name'         => 'Digital by Stella',
 *       'stripe_secret'          => 'sk_live_xxxxxxxxxxxx',
 *       'stripe_webhook_secret'  => 'whsec_xxxxxxxxxxxx',
 *       'site_url'               => 'https://digitalbystella.com',
 *       'price_cents'            => 1900,
 *       'currency'               => 'eur',
 *       'product_name'           => 'Consultation Digital by Stella — appel visio 30 min',
 *       'success_path'           => '/#contact',
 *       'cancel_path'            => '/?canceled=1#contact',
 *       'tz'                     => 'Europe/Paris',
 *       'days'                   => [1,2,3,4,5],
 *       'start_hour'             => 9,
 *       'end_hour'               => 18,
 *       'lead_hours'             => 12,
 *       'horizon_days'           => 14,
 *       'duration_min'           => 30,
 *     ];
 *
 * Côté Google Cloud Console :
 *   - Activer Google Calendar API
 *   - OAuth client ID type "Web application"
 *   - Refresh token généré via OAuth Playground avec scope
 *     https://www.googleapis.com/auth/calendar
 *
 * Permissions requises côté Stella : compte propriétaire du calendrier.
 */

header('Content-Type: application/json; charset=utf-8');

function fail(int $code, string $msg): void {
  http_response_code($code);
  echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Lecture config hors web root ---------- */
$domainDir = dirname(__DIR__);
$cfgPath = $domainDir . '/private/booking.php';

clearstatcache(true);
if (function_exists('opcache_invalidate')) {
  @opcache_invalidate($cfgPath, true);
}

$cfg = is_file($cfgPath) ? include $cfgPath : null;
if (!is_array($cfg)) {
  error_log('booking.php: private/booking.php manquant ou invalide');
  fail(503, "Réservation indisponible. Écrivez à stella.web@yahoo.com.");
}

$GOOGLE_CLIENT_ID     = (string) ($cfg['google_client_id']     ?? '');
$GOOGLE_CLIENT_SECRET = (string) ($cfg['google_client_secret'] ?? '');
$GOOGLE_REFRESH_TOKEN = (string) ($cfg['google_refresh_token'] ?? '');
$GOOGLE_CALENDAR_ID   = (string) ($cfg['google_calendar_id']   ?? 'primary');
$ORGANIZER_EMAIL      = (string) ($cfg['organizer_email']      ?? '');
$ORGANIZER_NAME       = (string) ($cfg['organizer_name']       ?? 'Digital by Stella');
$STRIPE_SK            = (string) ($cfg['stripe_secret']        ?? '');
$STRIPE_WHSEC         = (string) ($cfg['stripe_webhook_secret'] ?? '');
$SITE_URL             = rtrim((string) ($cfg['site_url']       ?? ''), '/');
$PRICE_C              = (int)    ($cfg['price_cents']          ?? 1900);
$CURRENCY             = strtolower((string) ($cfg['currency']  ?? 'eur'));
$PRODUCT_NAME         = (string) ($cfg['product_name']         ?? 'Consultation Digital by Stella — appel visio 30 min');
$SUCCESS_PATH         = (string) ($cfg['success_path']         ?? '/#contact');
$CANCEL_PATH          = (string) ($cfg['cancel_path']          ?? '/?canceled=1#contact');
$TZ                   = (string) ($cfg['tz']                   ?? 'Europe/Paris');
$DAYS                 = is_array($cfg['days'] ?? null) ? array_map('intval', $cfg['days']) : [1,2,3,4,5];
$START_H              = (int)    ($cfg['start_hour']           ?? 9);
$END_H                = (int)    ($cfg['end_hour']             ?? 18);
$LEAD_H               = (int)    ($cfg['lead_hours']           ?? 12);
$HORIZON_D            = (int)    ($cfg['horizon_days']         ?? 14);
$DUR_MIN              = (int)    ($cfg['duration_min']         ?? 30);

if ($GOOGLE_CLIENT_ID === '' || $GOOGLE_CLIENT_SECRET === '' || $GOOGLE_REFRESH_TOKEN === '' || $STRIPE_SK === '') {
  error_log('booking.php: config Google/Stripe incomplète');
  fail(503, "Réservation indisponible. Écrivez à stella.web@yahoo.com.");
}

const STRIPE_SESSION_PROP = 'stripeSessionId';

/* ---------- HTTP helper ---------- */
function http_req(string $method, string $url, array $headers, $body, int $timeout = 15): array {
  if (!function_exists('curl_init')) return [0, null];
  $ch = curl_init($url);
  $opts = [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => $timeout,
    CURLOPT_CUSTOMREQUEST  => $method,
  ];
  if ($body !== null) {
    $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body, JSON_UNESCAPED_UNICODE);
    if (!is_string($body)) $headers[] = 'Content-Type: application/json';
  }
  $opts[CURLOPT_HTTPHEADER] = $headers;
  curl_setopt_array($ch, $opts);
  $resp = curl_exec($ch);
  $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);
  return [$code, $resp === false ? null : json_decode($resp, true)];
}

/* ---------- Google : access token via refresh_token ---------- */
function google_access_token(string $clientId, string $clientSecret, string $refreshToken): ?string {
  $body = http_build_query([
    'client_id'     => $clientId,
    'client_secret' => $clientSecret,
    'refresh_token' => $refreshToken,
    'grant_type'    => 'refresh_token',
  ]);
  for ($a = 1; $a <= 3; $a++) {
    [$code, $data] = http_req(
      'POST',
      'https://oauth2.googleapis.com/token',
      ['Content-Type: application/x-www-form-urlencoded'],
      $body,
      20
    );
    if ($code === 200 && !empty($data['access_token'])) return $data['access_token'];
    if ($code === 400 || $code === 401) break;
    if ($a < 3) usleep(400000);
  }
  return null;
}

$token = google_access_token($GOOGLE_CLIENT_ID, $GOOGLE_CLIENT_SECRET, $GOOGLE_REFRESH_TOKEN);
if (!$token) {
  error_log('booking.php: échec jeton Google');
  fail(502, "Réservation momentanément indisponible. Réessayez plus tard.");
}
$AUTH = ["Authorization: Bearer {$token}", 'Accept: application/json'];

$tz     = new DateTimeZone($TZ);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/* ---------- Créneaux libres (FreeBusy + génération locale) ---------- */
function free_slots(
  array $auth, string $calendarId, DateTimeZone $tz,
  array $days, int $startH, int $endH, int $leadH, int $horizonD, int $durMin
): array {
  $now    = new DateTime('now', $tz);
  $winBeg = (clone $now)->setTime($startH, 0, 0);
  if ((clone $now) >= $winBeg) $winBeg->modify('+1 day')->setTime($startH, 0, 0);
  $winEnd = (clone $winBeg)->modify("+{$horizonD} days")->setTime($endH, 0, 0);

  [$code, $data] = http_req(
    'POST',
    'https://www.googleapis.com/calendar/v3/freeBusy',
    $auth,
    [
      'timeMin'  => $winBeg->format(DateTime::ATOM),
      'timeMax'  => $winEnd->format(DateTime::ATOM),
      'timeZone' => $tz->getName(),
      'items'    => [['id' => $calendarId]],
    ],
    15
  );
  if ($code !== 200 || !is_array($data['calendars'][$calendarId]['busy'] ?? null)) {
    error_log("booking.php freeBusy HTTP {$code}");
    return [];
  }
  $busy = $data['calendars'][$calendarId]['busy'];

  $earliest = (new DateTime('now', $tz))->modify("+{$leadH} hours");
  $slots = [];
  for ($d = 0; $d <= $horizonD; $d++) {
    $day = (clone $winBeg)->modify("+{$d} days");
    if (!in_array((int) $day->format('N'), $days, true)) continue;
    for ($mins = $startH * 60; $mins + $durMin <= $endH * 60; $mins += $durMin) {
      $h = intdiv($mins, 60);
      $m = $mins % 60;
      $slot = (clone $day)->setTime($h, $m, 0);
      if ($slot < $earliest) continue;
      $slotEnd = (clone $slot)->modify("+{$durMin} minutes");
      $overlap = false;
      foreach ($busy as $b) {
        $bs = new DateTime($b['start']);
        $be = new DateTime($b['end']);
        if ($slot < $be && $slotEnd > $bs) { $overlap = true; break; }
      }
      if (!$overlap) $slots[] = clone $slot;
    }
  }
  return $slots;
}

function slot_is_free(
  array $auth, string $calendarId, DateTimeZone $tz, array $days,
  int $startH, int $endH, int $leadH, int $horizonD, int $durMin, DateTime $target
): bool {
  foreach (free_slots($auth, $calendarId, $tz, $days, $startH, $endH, $leadH, $horizonD, $durMin) as $s) {
    if ($s->format('c') === $target->format('c')) return true;
  }
  return false;
}

/* ---------- GET ?action=slots ---------- */
if ($method === 'GET' && ($_GET['action'] ?? '') === 'slots') {
  $slots = free_slots($AUTH, $GOOGLE_CALENDAR_ID, $tz, $DAYS, $START_H, $END_H, $LEAD_H, $HORIZON_D, $DUR_MIN);
  echo json_encode([
    'ok'          => true,
    'slots'       => array_map(static fn(DateTime $d) => $d->format('c'), $slots),
    'tz'          => $TZ,
    'durationMin' => $DUR_MIN,
    'priceCents'  => $PRICE_C,
    'currency'    => $CURRENCY,
  ], JSON_UNESCAPED_UNICODE);
  exit;
}

/* ---------- Création event Google Calendar + Google Meet ---------- */
function create_event(
  array $auth, string $calendarId, string $organizerName, DateTimeZone $tz, int $durMin,
  DateTime $startDt, string $displayName, string $email, string $bodyHtml, string $sessionId
): array {
  $endDt    = (clone $startDt)->modify("+{$durMin} minutes");
  $safeName = mb_substr($displayName, 0, 120);

  return http_req(
    'POST',
    'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId)
      . '/events?conferenceDataVersion=1&sendUpdates=all',
    $auth,
    [
      'summary'     => "Appel visio {$organizerName} — {$safeName}",
      'description' => $bodyHtml,
      'start'       => ['dateTime' => $startDt->format(DateTime::ATOM), 'timeZone' => $tz->getName()],
      'end'         => ['dateTime' => $endDt->format(DateTime::ATOM),   'timeZone' => $tz->getName()],
      'attendees'   => [['email' => $email, 'displayName' => $safeName]],
      'conferenceData' => [
        'createRequest' => [
          'requestId'             => 'sb-' . $sessionId,
          'conferenceSolutionKey' => ['type' => 'hangoutsMeet'],
        ],
      ],
      'extendedProperties' => [
        'private' => [ STRIPE_SESSION_PROP => $sessionId ],
      ],
      'reminders' => ['useDefault' => true],
    ],
    20
  );
}

function event_meet_url(array $ev): ?string {
  if (!empty($ev['hangoutLink'])) return $ev['hangoutLink'];
  foreach ($ev['conferenceData']['entryPoints'] ?? [] as $ep) {
    if (($ep['entryPointType'] ?? '') === 'video' && !empty($ep['uri'])) return $ep['uri'];
  }
  return null;
}

/* ---------- Vérification HMAC du webhook Stripe ---------- */
function stripe_verify_sig(string $payload, string $sigHeader, string $secret, int $tol = 300): bool {
  if ($secret === '' || $sigHeader === '') return false;
  $t = null;
  $v1 = [];
  foreach (explode(',', $sigHeader) as $part) {
    $kv = explode('=', trim($part), 2);
    if (count($kv) !== 2) continue;
    if ($kv[0] === 't')  $t = $kv[1];
    if ($kv[0] === 'v1') $v1[] = $kv[1];
  }
  if ($t === null || !$v1) return false;
  if (abs(time() - (int) $t) > $tol) return false;
  $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
  foreach ($v1 as $sig) {
    if (hash_equals($expected, $sig)) return true;
  }
  return false;
}

/* ---------- Réserve à partir d'une session Stripe payée (idempotent) ---------- */
function book_from_session(
  array $auth, string $calendarId, string $organizerName, DateTimeZone $tz, int $durMin,
  string $sid, array $m
): array {
  $start    = trim($m['start']      ?? '');
  $prenom   = trim($m['prenom']     ?? '');
  $nom      = trim($m['nom']        ?? '');
  $email    = trim($m['email']      ?? '');
  $tel      = trim($m['telephone']  ?? '');
  $presta   = trim($m['prestation'] ?? '');
  $msg      = trim($m['message']    ?? '');
  if ($start === '' || $prenom === '' || $email === '') {
    return ['http' => 500, 'ok' => false, 'error' => "infos manquantes (réf. {$sid})"];
  }
  $displayName = trim($prenom . ' ' . $nom);
  try {
    $startDt = (new DateTime($start))->setTimezone($tz);
  } catch (Exception $e) {
    return ['http' => 500, 'ok' => false, 'error' => "créneau illisible (réf. {$sid})"];
  }

  // Idempotence : recherche event existant par sessionId
  $params = http_build_query([
    'privateExtendedProperty' => STRIPE_SESSION_PROP . '=' . $sid,
    'singleEvents'            => 'true',
    'showDeleted'             => 'false',
    'maxResults'              => 5,
  ]);
  [$qc, $qd] = http_req(
    'GET',
    'https://www.googleapis.com/calendar/v3/calendars/' . rawurlencode($calendarId) . '/events?' . $params,
    $auth,
    null,
    15
  );
  if ($qc === 200 && !empty($qd['items'][0])) {
    $ev = $qd['items'][0];
    return [
      'http'    => 200, 'ok' => true,
      'start'   => $startDt->format('c'),
      'uid'     => $ev['id'] ?? null,
      'joinUrl' => event_meet_url($ev),
    ];
  }

  $esc = fn(string $s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
  $bodyHtml  = '<p>Réservation payée via Digital by Stella (Stripe ' . $esc($sid) . ')</p>'
    . '<p><b>Client :</b> ' . $esc($displayName) . '</p>'
    . '<p><b>Email :</b> ' . $esc($email) . '</p>'
    . ($tel    !== '' ? '<p><b>Téléphone :</b> ' . $esc($tel) . '</p>' : '')
    . ($presta !== '' ? '<p><b>Prestation :</b> ' . $esc($presta) . '</p>' : '')
    . ($msg    !== '' ? '<p><b>Message :</b><br>' . nl2br($esc(mb_substr($msg, 0, 2000))) . '</p>' : '');

  [$code, $data] = create_event($auth, $calendarId, $organizerName, $tz, $durMin, $startDt, $displayName, $email, $bodyHtml, $sid);
  if (($code === 200 || $code === 201) && is_array($data)) {
    return [
      'http'    => 200, 'ok' => true,
      'start'   => $startDt->format('c'),
      'uid'     => $data['id'] ?? null,
      'joinUrl' => event_meet_url($data),
    ];
  }
  error_log("booking.php create_event HTTP {$code} : " . json_encode($data));
  return ['http' => 502, 'ok' => false, 'error' => "création du RDV échouée (réf. {$sid})"];
}

/* ---------- POST : webhook Stripe + checkout ---------- */
if ($method === 'POST') {
  $raw = file_get_contents('php://input');

  // Webhook Stripe
  $sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';
  if ($sigHeader !== '') {
    if ($STRIPE_WHSEC === '' || !stripe_verify_sig($raw, $sigHeader, $STRIPE_WHSEC)) {
      http_response_code(400);
      echo json_encode(['ok' => false, 'error' => 'invalid signature']);
      exit;
    }
    $evt = json_decode($raw, true);
    $type = is_array($evt) ? ($evt['type'] ?? '') : '';
    if ($type === 'checkout.session.completed' || $type === 'checkout.session.async_payment_succeeded') {
      $sess = $evt['data']['object'] ?? [];
      $sid  = $sess['id'] ?? '';
      if (($sess['payment_status'] ?? '') === 'paid' && $sid !== '') {
        $m = is_array($sess['metadata'] ?? null) ? $sess['metadata'] : [];
        $r = book_from_session($AUTH, $GOOGLE_CALENDAR_ID, $ORGANIZER_NAME, $tz, $DUR_MIN, $sid, $m);
        if (!$r['ok']) {
          error_log("booking.php webhook: {$r['error']}");
          http_response_code(500);
          echo json_encode(['ok' => false]);
          exit;
        }
      }
    }
    echo json_encode(['ok' => true]);
    exit;
  }

  $in = json_decode($raw, true);
  if (!is_array($in)) $in = $_POST;

  if (($in['action'] ?? '') !== 'checkout') fail(400, 'Action inconnue.');

  $start  = trim((string) ($in['start']      ?? ''));
  $prenom = trim((string) ($in['prenom']     ?? ''));
  $nom    = trim((string) ($in['nom']        ?? ''));
  $email  = trim((string) ($in['email']      ?? ''));
  $tel    = trim((string) ($in['telephone']  ?? ''));
  $presta = trim((string) ($in['prestation'] ?? ''));
  $msg    = trim((string) ($in['message']    ?? ''));

  if ($start === '' || $prenom === '' || $nom === '' || $email === '') fail(422, 'Champs obligatoires manquants.');
  if (!filter_var($email, FILTER_VALIDATE_EMAIL)) fail(422, 'Adresse email invalide.');
  try {
    $startDt = (new DateTime($start))->setTimezone($tz);
  } catch (Exception $e) {
    fail(422, 'Créneau invalide.');
  }
  if (!slot_is_free($AUTH, $GOOGLE_CALENDAR_ID, $tz, $DAYS, $START_H, $END_H, $LEAD_H, $HORIZON_D, $DUR_MIN, $startDt)) {
    fail(409, "Ce créneau vient d'être réservé. Choisissez-en un autre.");
  }

  $params = [
    'mode'           => 'payment',
    // Stripe revient sur la home avec ?session_id, le widget JS détecte et finalise.
    'success_url'    => $SITE_URL . '/?session_id={CHECKOUT_SESSION_ID}#contact',
    'cancel_url'     => $SITE_URL . $CANCEL_PATH,
    'customer_email' => $email,
    'line_items'     => [[
      'quantity'   => 1,
      'price_data' => [
        'currency'     => $CURRENCY,
        'unit_amount'  => $PRICE_C,
        'product_data' => ['name' => $PRODUCT_NAME],
      ],
    ]],
    'metadata' => [
      'start'      => $startDt->format('c'),
      'prenom'     => mb_substr($prenom, 0, 120),
      'nom'        => mb_substr($nom, 0, 120),
      'email'      => $email,
      'telephone'  => mb_substr($tel, 0, 40),
      'prestation' => mb_substr($presta, 0, 120),
      'message'    => mb_substr($msg, 0, 460),
    ],
  ];

  // Stripe attend des line_items[0][price_data]... → http_build_query gère ça nativement.
  [$code, $data] = http_req(
    'POST',
    'https://api.stripe.com/v1/checkout/sessions',
    ["Authorization: Bearer {$STRIPE_SK}", 'Content-Type: application/x-www-form-urlencoded'],
    http_build_query($params),
    20
  );

  if ($code === 200 && !empty($data['url'])) {
    echo json_encode(['ok' => true, 'url' => $data['url']], JSON_UNESCAPED_UNICODE);
    exit;
  }
  error_log('booking.php checkout Stripe HTTP ' . $code . ' : ' . json_encode($data));
  fail(502, "Impossible d'ouvrir le paiement. Réessayez ou écrivez à stella.web@yahoo.com.");
}

/* ---------- GET ?action=finalize ---------- */
if ($method === 'GET' && ($_GET['action'] ?? '') === 'finalize') {
  $sid = trim($_GET['session_id'] ?? '');
  if ($sid === '' || !preg_match('/^cs_[A-Za-z0-9_]+$/', $sid)) {
    fail(422, 'Session de paiement invalide.');
  }
  [$sc, $sess] = http_req(
    'GET',
    'https://api.stripe.com/v1/checkout/sessions/' . rawurlencode($sid),
    ["Authorization: Bearer {$STRIPE_SK}"],
    null,
    20
  );
  if ($sc !== 200 || !is_array($sess)) {
    fail(502, "Vérification du paiement impossible. Écrivez à stella.web@yahoo.com.");
  }
  if (($sess['payment_status'] ?? '') !== 'paid') {
    fail(402, "Le paiement n'a pas été confirmé. Aucun créneau n'a été réservé.");
  }

  $m = is_array($sess['metadata'] ?? null) ? $sess['metadata'] : [];
  $r = book_from_session($AUTH, $GOOGLE_CALENDAR_ID, $ORGANIZER_NAME, $tz, $DUR_MIN, $sid, $m);
  if ($r['ok']) {
    echo json_encode([
      'ok'      => true,
      'start'   => $r['start'],
      'uid'     => $r['uid'],
      'joinUrl' => $r['joinUrl'],
    ], JSON_UNESCAPED_UNICODE);
    exit;
  }
  fail(
    $r['http'] ?? 502,
    "Paiement reçu mais réservation à finaliser ({$r['error']}). Écrivez à stella.web@yahoo.com."
  );
}

fail(405, 'Méthode non autorisée.');
