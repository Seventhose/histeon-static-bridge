<?php
/**
 * Drives the Static Bridge 2.0.13 retention GUI over real HTTP on the disposable QA stack.
 * Usage:
 *   php retention-gui.php settings on|off <keep>   submit the real settings form, preserving every other field
 *   php retention-gui.php settings-view             retention fields as rendered on the Settings tab
 *   php retention-gui.php activity                  retention card on the Activity tab (preview rows, apply form)
 *   php retention-gui.php apply <role> [bad-nonce]  POST the "Applica ora" form as <role> with the admin's nonce
 *   php retention-gui.php versions                  the Versioni tab (2.4.0): offer, bulk form, one block per folder
 *   php retention-gui.php versions-post <action> [release] [role] [bad-nonce]
 *                                                   POST a Versioni form as <role> with the nonce the admin's page carries
 *   php retention-gui.php versions-name <name>      POST a deletion of <name> with no valid nonce (name checks come first)
 */
declare(strict_types=1);

require __DIR__ . '/profile.php';
$env = SBQA_ENV;
$command = (string) ($argv[1] ?? '');

function cookie_for(string $role): string
{
    return trim((string) @file_get_contents(SBQA_ENV . '/run/cookie-' . preg_replace('/[^a-z_]/', '', $role) . '.txt'));
}

function request(string $method, string $path, string $cookie, array $form = null): array
{
    $headers = array('User-Agent: static-bridge-qa-retention', $cookie);
    $content = '';
    if (null !== $form) {
        $content = http_build_query($form);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
    }
    $ctx = stream_context_create(array('http' => array('method' => $method, 'header' => implode("\r\n", $headers), 'content' => $content, 'ignore_errors' => true, 'follow_location' => 0, 'timeout' => 120)));
    $body = (string) @file_get_contents(BASE . $path, false, $ctx);
    $raw = http_get_last_response_headers() ?: array();
    $status = preg_match('#^HTTP/\S+\s+(\d+)#', (string) ($raw[0] ?? ''), $m) ? (int) $m[1] : 0;
    $location = '';
    foreach ($raw as $line) {
        if (0 === stripos((string) $line, 'Location:')) {
            $location = trim(substr((string) $line, 9));
        }
    }
    return array('status' => $status, 'location' => $location, 'body' => $body);
}

function dom(string $html): DOMXPath
{
    $dom = new DOMDocument();
    @$dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    return new DOMXPath($dom);
}

function form_with_action(DOMXPath $xpath, string $action): ?DOMElement
{
    foreach ($xpath->query('//form[.//input[@name="action" and @value="' . $action . '"]]') as $form) {
        return $form;
    }
    return null;
}

$admin = cookie_for('administrator');
switch ($command) {
    case 'settings':
        $state = (string) ($argv[2] ?? '');
        $keep = (string) ($argv[3] ?? '3');
        $page = request('GET', '/wp-admin/admin.php?page=static-bridge&tab=settings', $admin);
        $xpath = dom($page['body']);
        $form = form_with_action($xpath, 'static_bridge_save_settings');
        if (!$form || !in_array($state, array('on', 'off'), true)) {
            fwrite(STDERR, "Settings form not found or bad arguments.\n");
            exit(1);
        }
        $data = array();
        foreach ($xpath->query('.//input[@name]', $form) as $input) {
            $name = $input->getAttribute('name');
            $type = strtolower($input->getAttribute('type'));
            if ('retention_enabled' === $name) {
                if ('on' === $state) {
                    $data[$name] = '1';
                }
            } elseif ('retention_keep' === $name) {
                $data[$name] = $keep;
            } elseif (in_array($type, array('checkbox', 'radio'), true)) {
                if ($input->hasAttribute('checked')) {
                    $data[$name] = $input->getAttribute('value') ?: '1';
                }
            } elseif ('submit' !== $type) {
                $data[$name] = $input->getAttribute('value');
            }
        }
        foreach ($xpath->query('.//textarea[@name]', $form) as $textarea) {
            $data[$textarea->getAttribute('name')] = $textarea->textContent;
        }
        $result = request('POST', '/wp-admin/admin-post.php', $admin, $data);
        echo json_encode(array('http' => $result['status'], 'location' => $result['location'], 'submitted_retention' => array('enabled' => $data['retention_enabled'] ?? '', 'keep' => $data['retention_keep'] ?? null)), JSON_UNESCAPED_SLASHES), PHP_EOL;
        exit(302 === $result['status'] ? 0 : 1);
    case 'settings-view':
        $xpath = dom(request('GET', '/wp-admin/admin.php?page=static-bridge&tab=settings', $admin)['body']);
        $enabled = $xpath->query('//input[@name="retention_enabled"]')->item(0);
        $keep = $xpath->query('//input[@name="retention_keep"]')->item(0);
        echo json_encode(array(
            'switch_present' => null !== $enabled,
            'checked' => $enabled instanceof DOMElement && $enabled->hasAttribute('checked'),
            'keep' => $keep instanceof DOMElement ? $keep->getAttribute('value') : null,
            'keep_min' => $keep instanceof DOMElement ? $keep->getAttribute('min') : null,
            'keep_max' => $keep instanceof DOMElement ? $keep->getAttribute('max') : null,
        )), PHP_EOL;
        break;
    case 'activity':
        $page = request('GET', '/wp-admin/admin.php?page=static-bridge&tab=activity' . (isset($argv[2]) ? '&retention-run=' . rawurlencode((string) $argv[2]) : ''), $admin);
        $xpath = dom($page['body']);
        $card = $xpath->query('//section[contains(@class,"sb-retention-card")]')->item(0);
        $rows = array();
        $map = array('Conservata' => 'keep', 'Da eliminare' => 'delete', 'Ignorata' => 'ignore');
        if ($card) {
            foreach ($xpath->query('.//table//tbody/tr', $card) as $tr) {
                $cells = $xpath->query('./td', $tr);
                $label = trim($cells->item(3)->textContent);
                $rows[trim($cells->item(0)->textContent)] = array('action' => $map[$label] ?? $label, 'kind' => trim($cells->item(1)->textContent), 'reason' => trim($cells->item(4)->textContent));
            }
        }
        $notice = $xpath->query('//div[contains(@class,"notice")]/p')->item(0);
        echo json_encode(array(
            'http' => $page['status'],
            'card' => null !== $card,
            'text' => $card ? preg_replace('/\s+/', ' ', trim($card->textContent)) : '',
            'apply_form' => null !== form_with_action($xpath, 'static_bridge_apply_retention'),
            'rows' => $rows,
            'notice' => $notice ? trim($notice->textContent) : '',
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'apply':
        $role = (string) ($argv[2] ?? 'administrator');
        $xpath = dom(request('GET', '/wp-admin/admin.php?page=static-bridge&tab=activity', $admin)['body']);
        $form = form_with_action($xpath, 'static_bridge_apply_retention');
        $nonce = $form ? (string) $xpath->query('.//input[@name="_wpnonce"]', $form)->item(0)?->getAttribute('value') : '';
        if ('bad-nonce' === ($argv[3] ?? '')) {
            $nonce = 'deadbeef00';
        }
        if ('' === $nonce) {
            fwrite(STDERR, "Apply form not present (retention disabled?).\n");
            exit(1);
        }
        $result = request('POST', '/wp-admin/admin-post.php', cookie_for($role), array('action' => 'static_bridge_apply_retention', '_wpnonce' => $nonce, '_wp_http_referer' => '/wp-admin/admin.php?page=static-bridge&tab=activity'));
        parse_str((string) parse_url($result['location'], PHP_URL_QUERY), $query);
        echo json_encode(array('http' => $result['status'], 'retention_run' => $query['retention-run'] ?? '', 'denied_message' => 302 === $result['status'] ? '' : trim(strip_tags(substr($result['body'], 0, 2000)))), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'versions':
        $page = request('GET', '/wp-admin/admin.php?page=static-bridge&tab=versions' . (isset($argv[2]) ? '&versions-run=' . rawurlencode((string) $argv[2]) : ''), $admin);
        $xpath = dom($page['body']);
        $blocks = array();
        foreach ($xpath->query('//article[contains(@class,"sb-version")]') as $article) {
            $name = trim((string) $xpath->query('.//code', $article)->item(0)?->textContent);
            $reason = $xpath->query('.//p[contains(@class,"sb-version-reason")]', $article)->item(0);
            $blocks[$name] = array(
                'state' => trim((string) $xpath->query('.//span[contains(@class,"sb-status-pill")]', $article)->item(0)?->textContent),
                'deletable' => null !== form_with_action(dom($article->ownerDocument->saveHTML($article)), 'static_bridge_delete_release'),
                'reason' => $reason ? preg_replace('/\s+/', ' ', trim($reason->textContent)) : '',
            );
        }
        $notice = $xpath->query('//div[contains(@class,"notice")]/p')->item(0);
        $card = $xpath->query('//section[contains(@class,"sb-versions-card")]')->item(0);
        echo json_encode(array(
            'http' => $page['status'],
            'tab_link' => false !== strpos($page['body'], 'tab=versions'),
            'offer' => null !== form_with_action($xpath, 'static_bridge_enable_retention'),
            'cleanup_form' => null !== form_with_action($xpath, 'static_bridge_cleanup_releases'),
            'confirm' => $xpath->query('//form[@data-sb-confirm]')->length,
            'text' => $card ? preg_replace('/\s+/', ' ', trim($card->textContent)) : '',
            'blocks' => $blocks,
            'notice' => $notice ? trim($notice->textContent) : '',
        ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'versions-post':
        $action = (string) ($argv[2] ?? '');
        $release = (string) ($argv[3] ?? '');
        $role = (string) ($argv[4] ?? 'administrator');
        $xpath = dom(request('GET', '/wp-admin/admin.php?page=static-bridge&tab=versions', $admin)['body']);
        $nonce = '';
        foreach ($xpath->query('//form[.//input[@name="action" and @value="' . $action . '"]]') as $form) {
            $field = $xpath->query('.//input[@name="release"]', $form)->item(0);
            if ('' === $release || ($field && $field->getAttribute('value') === $release)) {
                $nonce = (string) $xpath->query('.//input[@name="_wpnonce"]', $form)->item(0)?->getAttribute('value');
                break;
            }
        }
        if ('bad-nonce' === ($argv[5] ?? '')) {
            $nonce = 'deadbeef00';
        }
        if ('' === $nonce) {
            fwrite(STDERR, "Form not present on Versioni.\n");
            exit(1);
        }
        $fields = array('action' => $action, '_wpnonce' => $nonce, '_wp_http_referer' => '/wp-admin/admin.php?page=static-bridge&tab=versions');
        if ('' !== $release) {
            $fields['release'] = $release;
        }
        $result = request('POST', '/wp-admin/admin-post.php', cookie_for($role), $fields);
        parse_str((string) parse_url($result['location'], PHP_URL_QUERY), $query);
        echo json_encode(array('http' => $result['status'], 'run' => $query['versions-run'] ?? ($query['retention-enabled'] ?? ''), 'location' => $result['location']), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
        break;
    case 'versions-name':
        $result = request('POST', '/wp-admin/admin-post.php', $admin, array('action' => 'static_bridge_delete_release', 'release' => (string) ($argv[2] ?? ''), '_wpnonce' => 'deadbeef00'));
        echo json_encode(array('http' => $result['status'])), PHP_EOL;
        break;
    default:
        fwrite(STDERR, "Unknown command.\n");
        exit(2);
}
