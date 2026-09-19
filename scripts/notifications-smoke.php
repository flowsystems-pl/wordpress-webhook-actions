<?php
/**
 * Notifications smoke test. Run inside the WordPress container:
 *
 *   wp eval-file wp-content/plugins/flowsystems-webhook-actions/scripts/notifications-smoke.php --allow-root
 *
 * No network: every outbound HTTP call is intercepted with pre_http_request
 * and every wp_mail with pre_wp_mail, so the test asserts exactly what each
 * driver would have sent. Cleans up after itself.
 */

use FlowSystems\WebhookActions\Repositories\NotificationChannelRepository;
use FlowSystems\WebhookActions\Repositories\NotificationLogRepository;
use FlowSystems\WebhookActions\Repositories\NotificationRuleRepository;
use FlowSystems\WebhookActions\Repositories\WebhookRepository;
use FlowSystems\WebhookActions\Repositories\QueueRepository;
use FlowSystems\WebhookActions\Services\Dispatcher;
use FlowSystems\WebhookActions\Services\QueueService;
use FlowSystems\WebhookActions\Services\WPHttpTransport;
use FlowSystems\WebhookActions\Services\Notifications\NotificationSender;
use FlowSystems\WebhookActions\Services\Notifications\TemplateRenderer;
use FlowSystems\WebhookActions\Services\Notifications\TemplateLinter;
use FlowSystems\WebhookActions\Services\Notifications\RuleMatcher;
use FlowSystems\WebhookActions\Services\Notifications\DeliveryEvents;

global $wpdb;
$pass = 0;
$fail = 0;
$check = static function (string $name, bool $ok, string $detail = '') use (&$pass, &$fail): void {
  if ($ok) {
    $pass++;
    echo "PASS  {$name}\n";
  } else {
    $fail++;
    echo "FAIL  {$name}" . ($detail !== '' ? " — {$detail}" : '') . "\n";
  }
};

// ---------------------------------------------------------------------------
// Interceptors
// ---------------------------------------------------------------------------
$http  = [];            // every outbound request: [url, method, body, headers]
$mails = [];            // every wp_mail
$endpointCode = 500;    // what the fake webhook endpoint answers

add_filter('pre_http_request', static function ($pre, array $args, string $url) use (&$http, &$endpointCode) {
  $http[] = ['url' => $url, 'method' => $args['method'] ?? 'GET', 'body' => $args['body'] ?? null, 'headers' => $args['headers'] ?? []];
  if (str_contains($url, 'example.com/smoke-endpoint')) {
    return ['response' => ['code' => $endpointCode, 'message' => 'x'], 'body' => '{"error":"simulated"}', 'headers' => [], 'cookies' => [], 'filename' => null];
  }
  return ['response' => ['code' => 200, 'message' => 'OK'], 'body' => '{"ok":true}', 'headers' => [], 'cookies' => [], 'filename' => null];
}, 10, 3);

add_filter('pre_wp_mail', static function ($null, array $atts) use (&$mails) {
  $mails[] = $atts;
  return true;
}, 10, 2);

$sentTo = static function (string $needle) use (&$http): array {
  return array_values(array_filter($http, static fn(array $r): bool => str_contains($r['url'], $needle)));
};

// ---------------------------------------------------------------------------
// Unit: renderer, linter, matcher
// ---------------------------------------------------------------------------
$renderer = new TemplateRenderer();
$roots    = ['webhook' => ['name' => 'Orders → HubSpot'], 'args' => [['id' => 42, 'email' => 'a@b.c', 'items' => ['x', 'y']]], 'delivery' => ['error_message' => str_repeat('e', 300), 'http_code' => 502]];
$check('renderer: dotted paths + numeric index', $renderer->render('{{ webhook.name }} #{{ args.0.id }} {{ args.0.email | upper }}', $roots) === 'Orders → HubSpot #42 A@B.C');
$check('renderer: truncate + default + count', $renderer->render('{{ delivery.error_message | truncate:5 }}|{{ args.0.missing | default:"n/a" }}|{{ args.0.items | count }}', $roots) === 'eeeee…|n/a|2');
$check('renderer: unknown path renders empty', $renderer->render('[{{ nope.x }}]', $roots) === '[]');

$lint = (new TemplateLinter())->lint(['body' => '{{ args.0.email }} {{ args.0.phone }} {{ bogus.x }} {{ webhook.name | shout }}'], ['args' => [['email' => 'x']]], null, ['twilio_sms']);
$check('linter: flags unknown path, root and modifier', $lint['unknown_paths'] === ['args.0.phone'] && $lint['unknown_roots'] === ['bogus'] && $lint['unknown_modifiers'] === ['shout'], wp_json_encode($lint));

$matcher = new RuleMatcher();
$ctxBase = ['event' => 'failed_attempt', 'attempt' => 2, 'http_code' => 503, 'trigger' => 'woocommerce_order_status_completed', 'is_test' => false];
$check('matcher: attempts + 5xx + wildcard trigger', $matcher->matches(['event' => 'failed_attempt', 'filters' => ['attempts' => [2, 3], 'http_codes' => ['5xx'], 'triggers' => ['woocommerce_*']]], $ctxBase));
$check('matcher: attempt mismatch rejects', !$matcher->matches(['event' => 'failed_attempt', 'filters' => ['attempts' => [1]]], $ctxBase));
$check('matcher: transport filter needs null code', $matcher->matches(['event' => 'failed_attempt', 'filters' => ['http_codes' => ['transport']]], ['http_code' => null] + $ctxBase) && !$matcher->matches(['event' => 'failed_attempt', 'filters' => ['http_codes' => ['transport']]], $ctxBase));
$check('matcher: tests excluded by default', !$matcher->matches(['event' => 'failed_attempt', 'filters' => []], ['is_test' => true] + $ctxBase));
$check('matcher: reason on permanently_failed', $matcher->matches(['event' => 'permanently_failed', 'filters' => ['reason' => 'exhausted']], ['event' => 'permanently_failed', 'reason' => 'exhausted'] + $ctxBase) && !$matcher->matches(['event' => 'permanently_failed', 'filters' => ['reason' => 'non_retryable']], ['event' => 'permanently_failed', 'reason' => 'exhausted'] + $ctxBase));

// ---------------------------------------------------------------------------
// Fixtures
// ---------------------------------------------------------------------------
$channels = new NotificationChannelRepository();
$rules    = new NotificationRuleRepository();
$nlog     = new NotificationLogRepository();
$webhooks = new WebhookRepository();
$sender   = new NotificationSender();
$created  = ['channels' => [], 'rules' => [], 'webhooks' => []];

$mk = static function (string $name, string $type, array $config, array $secrets) use ($channels, $sender, &$created): int {
  $id = $channels->create(['name' => $name, 'type' => $type, 'config' => $config, 'secret_ciphertext' => $sender->encryptSecrets($secrets), 'hint' => '…test']);
  $created['channels'][] = $id;
  return (int) $id;
};
$chEmail    = $mk('smoke email', 'email', ['to' => 'ops@example.com', 'html' => true], []);
$chSlack    = $mk('smoke slack', 'slack', [], ['webhook_url' => 'https://hooks.slack.com/services/T000/B000/XXXX']);
$chDiscord  = $mk('smoke discord', 'discord', ['username' => 'WA'], ['webhook_url' => 'https://discord.com/api/webhooks/1/abc']);
$chTelegram = $mk('smoke telegram', 'telegram', ['chat_id' => '123'], ['bot_token' => '111:AAA']);
$chTeams    = $mk('smoke teams', 'teams', [], ['webhook_url' => 'https://prod.logic.azure.com/workflows/x']);
$chGchat    = $mk('smoke gchat', 'gchat', [], ['webhook_url' => 'https://chat.googleapis.com/v1/spaces/x/messages?key=k']);
$chMm       = $mk('smoke mattermost', 'mattermost', [], ['webhook_url' => 'https://mm.example.com/hooks/x']);
$chPushover = $mk('smoke pushover', 'pushover', ['priority' => 'auto'], ['app_token' => 'a', 'user_key' => 'u']);
$chNtfy     = $mk('smoke ntfy', 'ntfy', ['server' => 'https://ntfy.sh', 'topic' => 'smoke-topic'], []);
$chSms      = $mk('smoke sms', 'twilio_sms', ['account_sid' => 'AC1', 'from' => '+15550001', 'to' => '+15550002'], ['auth_token' => 't']);
$chWa       = $mk('smoke whatsapp', 'twilio_whatsapp', ['account_sid' => 'AC1', 'from' => '+15550001', 'to' => '+15550003', 'content_sid' => 'HX1', 'content_variables' => "1=title\n2=short"], ['auth_token' => 't']);
$chPd       = $mk('smoke pagerduty', 'pagerduty', ['resolve_on_success' => true, 'severity' => 'auto'], ['routing_key' => 'rk']);
$chHook     = $mk('smoke webhook', 'webhook', ['url' => 'https://example.com/notify', 'headers' => 'X-Test: 1'], ['secret' => 'sig']);
$allChannels = array_values($created['channels']);

$webhookId = (int) $webhooks->create([
  'name'         => 'Smoke webhook',
  'endpoint_url' => 'https://example.com/smoke-endpoint',
  'http_method'  => 'POST',
  'is_enabled'   => 1,
  'triggers'     => ['fswa_smoke_trigger'],
  'retry_limit'  => 2,
]);
$created['webhooks'][] = $webhookId;
$check('fixture: webhook created', $webhookId > 0);

$ruleFailed = (int) $rules->create(['name' => 'smoke failed', 'event' => 'failed_attempt', 'channel_ids' => $allChannels, 'filters' => ['attempts' => [1]], 'template' => ['body' => "Order {{ args.0.order_id }} for {{ args.0.email }} failed: {{ delivery.error_message | truncate:40 }}"]]);
$rulePerm   = (int) $rules->create(['name' => 'smoke perm', 'event' => 'permanently_failed', 'channel_ids' => [$chEmail], 'filters' => []]);
$ruleSucc   = (int) $rules->create(['name' => 'smoke success', 'event' => 'success', 'webhook_id' => $webhookId, 'channel_ids' => [$chSlack, $chPd], 'filters' => [], 'throttle_seconds' => 3600]);
$ruleRetry  = (int) $rules->create(['name' => 'smoke retry', 'event' => 'retry_scheduled', 'channel_ids' => [$chHook], 'filters' => ['http_codes' => ['5xx']]]);
$created['rules'] = [$ruleFailed, $rulePerm, $ruleSucc, $ruleRetry];

$dispatcher = new Dispatcher(new WPHttpTransport(), new QueueService());
$queue      = new QueueRepository();

$countByStatus = static function (int $ruleId) use ($nlog): array {
  global $wpdb;
  $rows = $wpdb->get_results($wpdb->prepare("SELECT status, COUNT(*) n FROM {$wpdb->prefix}fswa_notification_log WHERE rule_id = %d GROUP BY status", $ruleId), ARRAY_A);
  $out = [];
  foreach ($rows as $r) {
    $out[$r['status']] = (int) $r['n'];
  }
  return $out;
};
$forceDue = static function () use ($queue): void {
  global $wpdb;
  $wpdb->query("UPDATE {$wpdb->prefix}fswa_queue SET scheduled_at = '2000-01-01 00:00:00' WHERE status = 'pending'");
};

// ---------------------------------------------------------------------------
// Scenario 1: 500 → failed_attempt (attempt 1) + retry_scheduled; then exhausted
// ---------------------------------------------------------------------------
$endpointCode = 500;
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-100', 'email' => 'buyer@example.com', 'user_pass' => 'hunter2']]);
$forceDue();
$http = [];
$mails = [];
$dispatcher->process(10);   // attempt 1 → 500 → retry scheduled; notifications flushed on the same tick

$s = $countByStatus($ruleFailed);
$check('scenario 1: failed_attempt rule sent one message per channel', ($s['sent'] ?? 0) === count($allChannels) && ($s['failed'] ?? 0) === 0, wp_json_encode($s));
$s = $countByStatus($ruleRetry);
$check('scenario 1: retry_scheduled rule matched 5xx', ($s['sent'] ?? 0) === 1, wp_json_encode($s));
$check('scenario 1: nothing left pending after the tick', $nlog->countPending() === 0);

$check('email: wp_mail called with HTML + subject', count($mails) === 1 && str_contains($mails[0]['subject'], 'Smoke webhook failed') && str_contains($mails[0]['message'], '<html'), wp_json_encode(array_map(static fn($m) => $m['subject'] ?? '', $mails)));
$check('email: body rendered payload fields', count($mails) === 1 && str_contains($mails[0]['message'], 'Order A-100 for buyer@example.com failed'));
$check('email: secret-named payload key redacted', count($mails) === 1 && !str_contains($mails[0]['message'], 'hunter2'));

$slack = $sentTo('hooks.slack.com');
$slackBody = $slack ? json_decode($slack[0]['body'], true) : null;
$check('slack: Block Kit with header + fallback text', $slackBody && ($slackBody['blocks'][0]['type'] ?? '') === 'header' && !empty($slackBody['text']));
$discord = $sentTo('discord.com');
$dBody = $discord ? json_decode($discord[0]['body'], true) : null;
$check('discord: embed with colour + fields', $dBody && isset($dBody['embeds'][0]['color']) && !empty($dBody['embeds'][0]['fields']));
$tg = $sentTo('api.telegram.org');
$tBody = $tg ? json_decode($tg[0]['body'], true) : null;
$check('telegram: HTML parse mode, bold title, chat id', $tBody && $tBody['parse_mode'] === 'HTML' && str_starts_with($tBody['text'], '<b>') && $tBody['chat_id'] === '123' && str_contains($tg[0]['url'], 'bot111'));
$teams = $sentTo('logic.azure.com');
$teamsBody = $teams ? json_decode($teams[0]['body'], true) : null;
$check('teams: adaptive card envelope', $teamsBody && ($teamsBody['attachments'][0]['contentType'] ?? '') === 'application/vnd.microsoft.card.adaptive');
$gc = $sentTo('chat.googleapis.com');
$gcBody = $gc ? json_decode($gc[0]['body'], true) : null;
$check('gchat: cardsV2 + text', $gcBody && isset($gcBody['cardsV2'][0]['card']['header']['title']) && !empty($gcBody['text']));
$mm = $sentTo('mm.example.com');
$mmBody = $mm ? json_decode($mm[0]['body'], true) : null;
$check('mattermost: attachments with color', $mmBody && isset($mmBody['attachments'][0]['color']));
$po = $sentTo('api.pushover.net');
parse_str((string) ($po[0]['body'] ?? ''), $poForm);
$check('pushover: form with token/user/priority', $po && ($poForm['token'] ?? '') === 'a' && ($poForm['user'] ?? '') === 'u' && isset($poForm['priority']));
$nt = $sentTo('ntfy.sh/smoke-topic');
$check('ntfy: Title + Priority headers, plain body', $nt && isset($nt[0]['headers']['Title'], $nt[0]['headers']['Priority']) && is_string($nt[0]['body']));
$sms = array_values(array_filter($sentTo('api.twilio.com'), static fn($r) => !str_contains((string) $r['body'], 'whatsapp')));
parse_str((string) ($sms[0]['body'] ?? ''), $smsForm);
$check('twilio sms: basic auth + From/To/Body', $sms && str_starts_with($sms[0]['headers']['Authorization'] ?? '', 'Basic ') && ($smsForm['To'] ?? '') === '+15550002' && !empty($smsForm['Body']));
$wa = array_values(array_filter($sentTo('api.twilio.com'), static fn($r) => str_contains((string) $r['body'], 'whatsapp')));
parse_str((string) ($wa[0]['body'] ?? ''), $waForm);
$check('twilio whatsapp: ContentSid + variables, whatsapp: prefix', $wa && ($waForm['ContentSid'] ?? '') === 'HX1' && str_starts_with($waForm['To'] ?? '', 'whatsapp:') && json_decode($waForm['ContentVariables'] ?? '', true)['1'] !== '');
$pd = $sentTo('events.pagerduty.com');
$pdBody = $pd ? json_decode($pd[0]['body'], true) : null;
$check('pagerduty: trigger with dedup key + severity', $pdBody && $pdBody['event_action'] === 'trigger' && str_starts_with($pdBody['dedup_key'], 'fswa-') && $pdBody['payload']['severity'] === 'warning');
// The failed_attempt rule also posts to the generic webhook; pick the retry one.
$hook = array_values(array_filter($sentTo('example.com/notify'), static fn($r) => ($r['headers']['X-FSWA-Event'] ?? '') === 'retry_scheduled'));
$hBody = $hook ? json_decode($hook[0]['body'], true) : null;
$check('generic webhook: JSON message + custom header + HMAC signature', $hBody && $hBody['event'] === 'retry_scheduled' && ($hook[0]['headers']['X-Test'] ?? '') === '1' && str_starts_with($hook[0]['headers']['X-FSWA-Signature'] ?? '', 'sha256='), wp_json_encode(['urls' => array_map(static fn($r) => $r['url'], $http), 'hook' => $hook ? ['headers' => $hook[0]['headers'], 'event' => $hBody['event'] ?? null] : null, 'log' => $wpdb->get_results($wpdb->prepare("SELECT status, error FROM {$wpdb->prefix}fswa_notification_log WHERE rule_id = %d", $ruleRetry), ARRAY_A)]));

// Second attempt → exhausted (retry_limit 2)
$http = [];
$mails = [];
$forceDue();
$dispatcher->process(10);
$s = $countByStatus($rulePerm);
$check('scenario 1: permanently_failed (exhausted) sent to email', ($s['sent'] ?? 0) === 1, wp_json_encode($s));
$s = $countByStatus($ruleFailed);
$check('scenario 1: attempt 2 did NOT match attempts:[1]', ($s['sent'] ?? 0) === count($allChannels), wp_json_encode($s));
$check('scenario 1: exhausted email says out of attempts', count($mails) === 1 && str_contains($mails[0]['message'], 'out of attempts'), wp_json_encode(array_map(static fn($m) => $m['subject'] ?? '', $mails)));

// ---------------------------------------------------------------------------
// Scenario 2: 404 → permanently_failed non_retryable, no retry event
// ---------------------------------------------------------------------------
$before = $countByStatus($ruleRetry);
$endpointCode = 404;
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-101', 'email' => 'x@example.com']]);
$forceDue();
$mails = [];
$dispatcher->process(10);
$after = $countByStatus($ruleRetry);
$check('scenario 2: 404 does not schedule a retry event', ($after['sent'] ?? 0) === ($before['sent'] ?? 0));
$s = $countByStatus($rulePerm);
$check('scenario 2: 404 → permanently_failed sent', ($s['sent'] ?? 0) === 2, wp_json_encode($s));
$permMail = array_values(array_filter($mails, static fn($m) => str_contains((string) ($m['subject'] ?? ''), 'permanently failed')));
$check('scenario 2: email says not retryable', count($permMail) === 1 && str_contains($permMail[0]['message'], 'not retryable'), wp_json_encode(['n' => count($mails), 'subjects' => array_map(static fn($m) => $m['subject'] ?? '', $mails), 'snippet' => isset($mails[0]) ? substr(wp_strip_all_tags($mails[0]['message']), 0, 300) : null]));

// ---------------------------------------------------------------------------
// Scenario 3: success → per-webhook rule, PagerDuty resolve, throttle
// ---------------------------------------------------------------------------
$endpointCode = 200;
$http = [];
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-102']]);
$forceDue();
$dispatcher->process(10);
$s = $countByStatus($ruleSucc);
$check('scenario 3: success rule sent slack + pagerduty', ($s['sent'] ?? 0) === 2, wp_json_encode($s));
$pd = $sentTo('events.pagerduty.com');
$pdBody = $pd ? json_decode($pd[0]['body'], true) : null;
$check('scenario 3: pagerduty resolve on success', $pdBody && $pdBody['event_action'] === 'resolve');

$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-103']]);
$forceDue();
$dispatcher->process(10);
$s = $countByStatus($ruleSucc);
$check('scenario 3: second success within 1h is throttled', ($s['throttled'] ?? 0) === 1 && ($s['sent'] ?? 0) === 2, wp_json_encode($s));

// ---------------------------------------------------------------------------
// Scenario 4: webhook mode off → nothing; custom → only own rules
// ---------------------------------------------------------------------------
$webhooks->update($webhookId, ['notifications_mode' => 'off']);
$endpointCode = 404;
$before = $countByStatus($rulePerm);
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-104']]);
$forceDue();
$dispatcher->process(10);
$check('scenario 4: mode=off suppresses notifications', $countByStatus($rulePerm) === $before);

$webhooks->update($webhookId, ['notifications_mode' => 'inherit', 'muted_rule_ids' => [$rulePerm]]);
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-105']]);
$forceDue();
$dispatcher->process(10);
$check('scenario 4: muted global rule is skipped', $countByStatus($rulePerm) === $before);
$webhooks->update($webhookId, ['muted_rule_ids' => []]);

// ---------------------------------------------------------------------------
// Scenario 5: test dispatch never notifies
// ---------------------------------------------------------------------------
$before = $countByStatus($ruleFailed) + $countByStatus($rulePerm);
$endpointCode = 500;
$wh = $webhooks->find($webhookId);
$dispatcher->sendToWebhook($wh, ['hook' => 'fswa_smoke_trigger', 'args' => [[]]], 'fswa_smoke_trigger', null, 0, true, null);
$check('scenario 5: is_test dispatch produced no notification rows', ($countByStatus($ruleFailed) + $countByStatus($rulePerm)) === $before);

// ---------------------------------------------------------------------------
// Scenario 6: log-row summary + digest fold
// ---------------------------------------------------------------------------
global $wpdb;
$logIds = $wpdb->get_col($wpdb->prepare("SELECT id FROM {$wpdb->prefix}fswa_logs WHERE webhook_id = %d", $webhookId));
$summary = $nlog->summaryForLogs(array_map('intval', $logIds));
$check('summary: log rows carry sent counts', !empty($summary) && max(array_map(static fn($s) => $s['sent'], $summary)) >= 1);

$rules->update($ruleFailed, ['digest' => 'hourly']);
$sentBeforeDigest = $countByStatus($ruleFailed)['sent'] ?? 0;
$dispatcher->dispatch('fswa_smoke_trigger', [['order_id' => 'A-106']]);
$forceDue();
$dispatcher->process(10);
$s = $countByStatus($ruleFailed);
$check('digest: rows wait in the bucket', ($s['digested'] ?? 0) === count($allChannels), wp_json_encode($s));
$mails = [];
$queued = (new \FlowSystems\WebhookActions\Services\Notifications\DigestFlusher())->flush(true);
$sender->flushPending(100);
$s = $countByStatus($ruleFailed);
$check('digest: flush folds one message per channel', $queued === count($allChannels) && ($s['in_digest'] ?? 0) === count($allChannels) && ($s['sent'] ?? 0) === $sentBeforeDigest + count($allChannels), wp_json_encode($s));
$check('digest: email digest sent', count($mails) === 1 && str_contains($mails[0]['subject'], 'notification'), wp_json_encode(array_map(static fn($m) => $m['subject'] ?? '', $mails)));

// ---------------------------------------------------------------------------
// Cleanup
// ---------------------------------------------------------------------------
foreach ($created['rules'] as $id) {
  $rules->delete((int) $id);
}
foreach ($created['channels'] as $id) {
  $channels->delete((int) $id);
}
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fswa_notification_log WHERE webhook_id = %d", $webhookId));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fswa_queue WHERE webhook_id = %d", $webhookId));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fswa_logs WHERE webhook_id = %d", $webhookId));
$wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}fswa_trigger_schemas WHERE webhook_id = %d", $webhookId));
$webhooks->delete($webhookId);

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
  exit(1);
}
