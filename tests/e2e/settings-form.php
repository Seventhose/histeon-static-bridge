<?php
/**
 * Submits the real Static Bridge settings form on the disposable QA stack,
 * preserving every current field and toggling only the requested checkbox.
 * Usage: php settings-form.php <checkbox_name> on|off
 * SBQA_FORM_EXTRA (a query string, e.g. static_cache_control=public%2C+max-age%3D0) sets more fields or a button.
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$env = SBQA_ENV;
$cookie = trim((string) file_get_contents($env . '/run/cookie-administrator.txt'));
$field = (string) ($argv[1] ?? '');
$state = (string) ($argv[2] ?? '');
if (!preg_match('/^[a-z_]+$/', $field) || !in_array($state, array('on', 'off'), true)) {
    fwrite(STDERR, "Usage: php settings-form.php <checkbox_name> on|off\n");
    exit(2);
}

function fetch(string $method, string $url, string $cookie, string $body = ''): array
{
    $ctx = stream_context_create(array('http' => array('method' => $method, 'header' => $cookie . "\r\nContent-Type: application/x-www-form-urlencoded", 'content' => $body, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 60)));
    $out = (string) @file_get_contents($url, false, $ctx);
    $headers = http_get_last_response_headers() ?: array();
    return array($headers, $out);
}

$form = null;
foreach (array('', '&tab=settings', '&tab=impostazioni') as $tab) {
    [, $html] = fetch('GET', BASE . '/wp-admin/admin.php?page=static-bridge' . $tab, $cookie);
    $dom = new DOMDocument();
    @$dom->loadHTML($html);
    foreach ($dom->getElementsByTagName('form') as $candidate) {
        foreach ($candidate->getElementsByTagName('input') as $input) {
            if ('action' === $input->getAttribute('name') && 'static_bridge_save_settings' === $input->getAttribute('value')) {
                $form = $candidate;
                break 3;
            }
        }
    }
}
if (!$form) {
    fwrite(STDERR, "Settings form not found.\n");
    exit(1);
}
$data = array();
$found = false;
foreach ($form->getElementsByTagName('input') as $input) {
    $name = $input->getAttribute('name');
    if ('' === $name) {
        continue;
    }
    $type = strtolower($input->getAttribute('type'));
    if ($name === $field) {
        $found = true;
        if ('on' === $state) {
            $data[$name] = $input->getAttribute('value') ?: '1';
        }
        continue;
    }
    if (in_array($type, array('checkbox', 'radio'), true)) {
        if ($input->hasAttribute('checked')) {
            $data[$name] = $input->getAttribute('value') ?: '1';
        }
    } elseif ('submit' !== $type) {
        $data[$name] = $input->getAttribute('value');
    }
}
foreach ($form->getElementsByTagName('textarea') as $textarea) {
    $data[$textarea->getAttribute('name')] = $textarea->textContent;
}
if (!$found) {
    fwrite(STDERR, "Checkbox {$field} not present in the form.\n");
    exit(1);
}
parse_str((string) getenv('SBQA_FORM_EXTRA'), $extra);
$data = array_merge($data, $extra);
[$headers] = fetch('POST', BASE . '/wp-admin/admin-post.php', $cookie, http_build_query($data));
$status = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($headers[0] ?? ''), $m) ? (int) $m[1] : 0;
echo json_encode(array('http' => $status, 'field' => $field, 'state' => $state, 'fields_submitted' => array_keys($data)), JSON_UNESCAPED_SLASHES), PHP_EOL;
exit(302 === $status ? 0 : 1);
