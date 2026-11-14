<?php
/**
 * Smoke test for the credential scrub (3.4.3).
 *
 *   wp eval-file wp-content/plugins/flowsystems-webhook-actions/scripts/credential-scrub-smoke.php
 *
 * No database writes, no HTTP. Prints one line per check and a summary, and
 * exits non-zero when anything fails.
 */

use FlowSystems\WebhookActions\Services\Export\PayloadRedactor as ExportRedactor;
use FlowSystems\WebhookActions\Services\PayloadNormalizer;
use FlowSystems\WebhookActions\Services\StoredCredentialCleanup;
use FlowSystems\WebhookActions\Support\CredentialScrubber;

$pass = 0;
$fail = 0;
$check = static function (string $label, bool $ok) use (&$pass, &$fail): void {
  $ok ? $pass++ : $fail++;
  echo ($ok ? 'ok    ' : 'FAIL  ') . $label . "\n";
};

// 1. Key names.
foreach ([
  'user_pass', 'pass', 'pass1', 'pass2', 'password', 'Password', 'user_password', 'userPassword', 'account_password',
  'password_1', 'password_2', 'password_current', 'new_password', 'newPassword', 'confirm_password', 'password_confirmation',
  'your-password', 'Confirm Password', 'passwd', 'pwd', 'user_pwd', 'smtp_pass', 'post_password', '_wp_password',
  'user_activation_key', 'activation_key', 'password_hash', 'passphrase',
] as $key) {
  $check("key is a credential: {$key}", CredentialScrubber::isCredentialKey($key));
}
foreach ([
  'order_key', 'token', 'api_key', 'secret', 'nonce', 'cart_hash', 'boarding_pass', 'season_pass', 'bypass', 'compass',
  'passenger', 'password_reset_url', 'password_strength', 'passwordless_login', 'user_login', 'user_email', 'key', 'pass_through_fee',
  'is_password_protected_page', 'license_key', 'billing_postcode',
] as $key) {
  $check("key is left alone: {$key}", !CredentialScrubber::isCredentialKey($key));
}

// 2. The envelope: user_register as WordPress fires it.
$payload = PayloadNormalizer::envelope('user_register', [42, ['user_login' => 'jane', 'user_email' => 'jane@example.com', 'user_pass' => 'hunter2-plain', 'role' => 'subscriber']]);
$check('user_register: user_pass is null', $payload['args'][1]['user_pass'] === null);
$check('user_register: key kept', array_key_exists('user_pass', $payload['args'][1]));
$check('user_register: other fields intact', $payload['args'][1]['user_login'] === 'jane' && $payload['args'][1]['user_email'] === 'jane@example.com' && $payload['args'][0] === 42);
$check('user_register: nothing left in the JSON', strpos(wp_json_encode($payload), 'hunter2') === false);

// 3. Positional arguments.
$payload = PayloadNormalizer::envelope('wp_set_password', ['hunter2-plain', 7, null]);
$check('wp_set_password: arg 0 is null', $payload['args'][0] === null && $payload['args'][1] === 7);
$payload = PayloadNormalizer::envelope('password_reset', [['ID' => 7], 'hunter2-plain']);
$check('password_reset: arg 1 is null', $payload['args'][1] === null);
$payload = PayloadNormalizer::envelope('check_passwords', ['jane', 'hunter2-plain', 'hunter2-plain']);
$check('check_passwords: args 1 and 2 are null, login kept', $payload['args'] === ['jane', null, null]);
$payload = PayloadNormalizer::envelope('wp_login', ['jane', (object) ['data' => (object) ['user_login' => 'jane', 'user_pass' => '$wp$2y$10$abcdefghijk', 'user_activation_key' => '1700000000:$generic$abc']]]);
$check('wp_login: hash and activation key are null', $payload['args'][1]['data']['user_pass'] === null && $payload['args'][1]['data']['user_activation_key'] === null && $payload['args'][0] === 'jane');
$payload = PayloadNormalizer::envelope('save_post', ['hunter2-plain', 7]);
$check('an unrelated hook keeps its positional strings', $payload['args'][0] === 'hunter2-plain');

// 4. Declared fields, the shape form plugins use.
$payload = PayloadNormalizer::envelope('some_form_submit', [[
  'fields' => [
    1 => ['name' => 'Email', 'value' => 'jane@example.com', 'type' => 'email'],
    2 => ['name' => 'Choose a secret', 'value' => 'hunter2-plain', 'value_raw' => 'hunter2-plain', 'type' => 'password'],
    3 => ['name' => 'password-1', 'value' => 'hunter2-plain'],
    4 => ['key' => '_billing_phone', 'value' => '+48 600 100 200'],
    5 => ['key' => 'X-Password', 'value' => ['primary' => 'hunter2-plain', 'secondary' => 'hunter2-plain']],
  ],
  'account_password' => 'hunter2-plain',
  'createaccount'    => true,
  'has_password'     => true,
  'order_key'        => 'wc_order_abc123',
  'empty_password'   => ['password' => ''],
]]);
$f = $payload['args'][0]['fields'];
$check('type=password: value and value_raw are null', $f[2]['value'] === null && $f[2]['value_raw'] === null && $f[2]['name'] === 'Choose a secret');
$check('name=password-1: value is null', $f[3]['value'] === null);
$check('nested value under a password pair is blanked', $f[5]['value'] === ['primary' => null, 'secondary' => null]);
$check('ordinary pairs untouched', $f[1]['value'] === 'jane@example.com' && $f[4]['value'] === '+48 600 100 200');
$check('account_password is null, order_key kept, flags kept', $payload['args'][0]['account_password'] === null && $payload['args'][0]['order_key'] === 'wc_order_abc123' && $payload['args'][0]['has_password'] === true && $payload['args'][0]['createaccount'] === true);
$check('an empty password stays an empty string', $payload['args'][0]['empty_password']['password'] === '');
$check('form payload: nothing left in the JSON', strpos(wp_json_encode($payload), 'hunter2') === false);

// 5. JSON inside a string.
$payload = PayloadNormalizer::envelope('some_hook', ['{"user":{"login":"jane","password":"hunter2-plain"}}', '{"total":"19.99"}', 'not json but mentions password']);
$check('JSON string: password removed', json_decode($payload['args'][0], true) === ['user' => ['login' => 'jane', 'password' => null]]);
$check('JSON string with nothing to remove is byte-identical', $payload['args'][1] === '{"total":"19.99"}' && $payload['args'][2] === 'not json but mentions password');

// 6. The fswa_payload filter cannot put it back.
$putBack = static function ($p, $hook, $args) {
  $p['args'][1]['user_pass'] = $args[1]['user_pass'];
  $p['extra'] = ['password' => $args[1]['user_pass']];
  return $p;
};
add_filter('fswa_payload', $putBack, 10, 3);
$payload = PayloadNormalizer::envelope('user_register', [42, ['user_login' => 'jane', 'user_pass' => 'hunter2-plain']]);
remove_filter('fswa_payload', $putBack, 10);
$check('fswa_payload filter: re-added password is removed again', strpos(wp_json_encode($payload), 'hunter2') === false);

// 7. The opt-out.
$optOut = static fn($scrub, $hook) => $hook === 'user_register' ? false : $scrub;
add_filter('fswa_scrub_credentials', $optOut, 10, 2);
$payload  = PayloadNormalizer::envelope('user_register', [42, ['user_pass' => 'hunter2-plain']]);
$payload2 = PayloadNormalizer::envelope('wp_set_password', ['hunter2-plain', 7]);
remove_filter('fswa_scrub_credentials', $optOut, 10);
$check('fswa_scrub_credentials=false: that trigger keeps the value', $payload['args'][1]['user_pass'] === 'hunter2-plain');
$check('fswa_scrub_credentials=false: other triggers still scrubbed', $payload2['args'][0] === null);

// 8. Extra positions through the filter.
$extra = static function ($map) { $map['my_plugin_login'] = [1]; return $map; };
add_filter('fswa_credential_arg_positions', $extra);
$payload = PayloadNormalizer::envelope('my_plugin_login', ['jane', 'hunter2-plain']);
remove_filter('fswa_credential_arg_positions', $extra);
$check('fswa_credential_arg_positions adds a hook', $payload['args'] === ['jane', null]);

// 9. A stored log row from before the update.
$original = ['event' => ['id' => 'x'], 'hook' => 'user_register', 'args' => [42, ['user_login' => 'jane', 'user_email' => 'jane@example.com', 'user_pass' => 'hunter2-plain']]];
$mapped   = ['email' => 'jane@example.com', 'secret_word' => 'hunter2-plain', 'note' => 'welcome jane'];
$row = [
  'trigger_name'     => 'user_register',
  'original_payload' => wp_json_encode($original),
  'request_payload'  => wp_json_encode($mapped),
  'response_body'    => wp_json_encode(['received' => $mapped]),
  'attempt_history'  => wp_json_encode([['attempt' => 0, 'http_code' => 200, 'response_body' => 'echo: hunter2-plain ok']]),
];
$changed = StoredCredentialCleanup::scrubLogRow($row);
$after   = array_merge($row, $changed);
$check('stored row: all four columns rewritten', count($changed) === 4);
$check('stored row: password gone everywhere', strpos(implode('|', $after), 'hunter2') === false);
$check('stored row: renamed field is null, the rest kept', json_decode($after['request_payload'], true) === ['email' => 'jane@example.com', 'secret_word' => null, 'note' => 'welcome jane']);
$check('stored row: a second pass changes nothing', StoredCredentialCleanup::scrubLogRow($after) === []);
$clean = ['trigger_name' => 'save_post', 'original_payload' => null, 'request_payload' => '{"hook":"save_post","args":[12,{"post_title":"Hello","post_password":""}]}', 'response_body' => 'OK', 'attempt_history' => null];
$check('stored row with nothing in it is not rewritten', StoredCredentialCleanup::scrubLogRow($clean) === []);

// 10. Export redactor (builds that are downloaded or published).
$exported = (new ExportRedactor())->redact(['args' => [42, ['user_pass' => 'hunter2-plain', 'user_login' => 'jane', 'pass1' => 'hunter2-plain', 'account_password' => 'hunter2-plain']]]);
$check('export: user_pass, pass1, account_password redacted', strpos(wp_json_encode($exported), 'hunter2') === false && $exported['args'][1]['user_pass'] === '[redacted]');

echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
  exit(1);
}
