<?php
/**
 * The Server tab: what the web server and PHP offer and what is worth turning on.
 *
 * Read-only: every check looks (PHP settings, files, public responses of the site) and explains;
 * nothing here writes to the server, the database or the site. Public responses are requested the
 * way an anonymous visitor would ask for them, so a cache in front of the site answers as it does
 * for visitors.
 */

if (!defined('ABSPATH')) {
    exit;
}

final class Static_Bridge_Server_Check
{
    /** Seconds of browser cache below which CSS and JS are worth a longer lifetime. */
    const ASSET_MIN_MAX_AGE = 604800;
    /** Seconds of browser cache above which a published page can stay old in the browser. */
    const HTML_MAX_MAX_AGE = 600;
    const PROBE_TIMEOUT = 10;
    const PROBE_BODY_LIMIT = 65536;
    /** Seconds to wait for Redis or Memcached: a missing service must not slow the check down. */
    const SERVICE_TIMEOUT = 0.2;
    const STATUSES = array('pass', 'tip', 'warning', 'skip');
    /**
     * WP-Cron (2.5.0). An event of Static Bridge more than this many seconds past its time is late: WP-Cron starts at
     * most once a minute and a system cron usually every 5 minutes, so 15 minutes are three missed rounds of it,
     * while the workers of Static Bridge schedule themselves seconds or a few minutes ahead.
     */
    const CRON_LATE_AFTER = 900;
    /** Any event this many seconds past its time: WP-Cron has not started for at least that long. */
    const CRON_IDLE_AFTER = 3600;
    const HEADERS = array(
        'server', 'date', 'age', 'cache-control', 'expires', 'content-encoding',
        'cf-cache-status', 'x-aruba-cache', 'x-litespeed-cache', 'x-varnish', 'via',
        'x-cache', 'x-cache-status', 'x-proxy-cache', 'x-srcache-fetch-status', 'x-fastcgi-cache',
        'strict-transport-security', 'x-content-type-options', 'x-frame-options', 'referrer-policy',
        'permissions-policy', 'content-security-policy',
    );
    /** Plugins with a page cache of their own, by folder: with Static Bridge it is a second, conflicting layer. */
    const PAGE_CACHE_PLUGINS = array(
        'wp-rocket' => 'WP Rocket',
        'litespeed-cache' => 'LiteSpeed Cache',
        'w3-total-cache' => 'W3 Total Cache',
        'wp-super-cache' => 'WP Super Cache',
        'wp-fastest-cache' => 'WP Fastest Cache',
        'sg-cachepress' => 'SiteGround Speed Optimizer',
        'breeze' => 'Breeze',
        'cache-enabler' => 'Cache Enabler',
        'comet-cache' => 'Comet Cache',
        'hummingbird-performance' => 'Hummingbird',
        'swift-performance-lite' => 'Swift Performance',
        'wp-optimize' => 'WP-Optimize',
    );
    /** Plugins that only empty the hosting's cache: they fit with Static Bridge. */
    const CACHE_PURGE_PLUGINS = array(
        'aruba-hispeed-cache' => 'Aruba HiSpeed Cache',
        'nginx-helper' => 'Nginx Helper',
        'varnish-http-purge' => 'Proxy Cache Purge',
    );
    /** Names a known object-cache.php drop-in carries in its first lines. */
    const DROPIN_SIGNATURES = array(
        'Static Bridge' => 'Static Bridge',
        'Redis Object Cache' => 'Redis Object Cache',
        'Object Cache Pro' => 'Object Cache Pro',
        'LiteSpeed' => 'LiteSpeed Cache',
        'W3 Total Cache' => 'W3 Total Cache',
        'Docket Cache' => 'Docket Cache',
        'Memcached' => 'Memcached',
        'APCu' => 'APCu',
    );

    /** @var array{nginx: bool, cloudflare_purge: bool, aruba_purge: bool, home: string} */
    private $context;

    public function __construct(array $context)
    {
        $this->context = array(
            'nginx' => !empty($context['nginx']),
            'cloudflare_purge' => !empty($context['cloudflare_purge']),
            'aruba_purge' => !empty($context['aruba_purge']),
            'home' => isset($context['home']) ? (string) $context['home'] : home_url('/'),
            // 2.5.0: the plugin's self_request_follow(), which adds the Basic Auth header on this site only.
            'request' => isset($context['request']) && is_callable($context['request']) ? $context['request'] : null,
            // 2.5.0: hook => what it does, for the late events of Static Bridge in the WP-Cron check.
            'cron_hooks' => isset($context['cron_hooks']) && is_array($context['cron_hooks']) ? $context['cron_hooks'] : array(),
        );
    }

    /** The checks in the order the tab shows them: id => label. */
    public static function checks()
    {
        return array(
            'server' => __('Server web', 'static-bridge'),
            'host-cache' => __('Cache dell’hosting davanti al sito', 'static-bridge'),
            'page-cache-plugins' => __('Plugin di cache delle pagine', 'static-bridge'),
            'compression' => __('Compressione', 'static-bridge'),
            'browser-cache' => __('Cache del browser', 'static-bridge'),
            'php' => __('PHP', 'static-bridge'),
            'object-cache' => __('Object cache', 'static-bridge'),
            'https' => __('HTTPS e intestazioni di sicurezza', 'static-bridge'),
            'wp-cron' => __('WP-Cron', 'static-bridge'),
        );
    }

    public static function status_label($status)
    {
        $labels = array(
            'pass' => __('A posto', 'static-bridge'),
            'tip' => __('Consigliato', 'static-bridge'),
            'warning' => __('Da sistemare', 'static-bridge'),
            'skip' => __('Non verificabile', 'static-bridge'),
        );
        return isset($labels[$status]) ? $labels[$status] : $labels['skip'];
    }

    public function run($id)
    {
        switch ($id) {
            case 'server':
                return $this->check_server();
            case 'host-cache':
                return $this->check_host_cache();
            case 'page-cache-plugins':
                return $this->check_page_cache_plugins();
            case 'compression':
                return $this->check_compression();
            case 'browser-cache':
                return $this->check_browser_cache();
            case 'php':
                return $this->check_php();
            case 'object-cache':
                return $this->check_object_cache();
            case 'https':
                return $this->check_https();
            case 'wp-cron':
                return $this->check_wp_cron();
        }
        return null;
    }

    public static function result($id, $status, $summary, $remediation = '')
    {
        $checks = self::checks();
        $status = in_array($status, self::STATUSES, true) ? $status : 'skip';
        return array(
            'id' => sanitize_key($id),
            'label' => isset($checks[$id]) ? $checks[$id] : (string) $id,
            'status' => $status,
            'status_label' => self::status_label($status),
            'summary' => wp_strip_all_tags((string) $summary),
            'remediation' => wp_strip_all_tags((string) $remediation),
        );
    }

    // ------------------------------------------------------------------ checks

    private function check_server()
    {
        $server = self::server_software(isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '');
        if ('nginx' === $server['kind'] || $this->context['nginx']) {
            return self::result('server', 'pass', __('Nginx: non legge il file .htaccess, quindi le regole di Static Bridge vanno copiate nella configurazione del server (scheda Impostazioni) e ricaricate a ogni loro modifica.', 'static-bridge'));
        }
        if ('other' === $server['kind']) {
            return self::result('server', 'skip', __('Il server non dice di che tipo è. Static Bridge lo tratta come Apache e scrive le sue regole nel file .htaccess.', 'static-bridge'), __('Se le pagine statiche non vengono servite, chiedi all’hosting quale server web usa il sito.', 'static-bridge'));
        }
        $edition = isset($_SERVER['LSWS_EDITION']) ? strtolower((string) $_SERVER['LSWS_EDITION']) : '';
        if (0 === strpos($edition, 'openlitespeed')) {
            return self::result('server', 'pass', __('OpenLiteSpeed: Static Bridge scrive le sue regole nel file .htaccess, che OpenLiteSpeed legge solo quando parte. Ne usa le regole di riscrittura e ignora il resto (Header, compressione, scadenze): comprime da solo i file statici, mentre le intestazioni delle pagine statiche vanno nel contesto mostrato in Impostazioni.', 'static-bridge'), __('Riavvia OpenLiteSpeed dopo il primo export e dopo ogni salvataggio che cambia le regole: la Diagnostica dice quando serve.', 'static-bridge'));
        }
        $name = 'litespeed' === $server['kind'] ? 'LiteSpeed' : 'Apache';
        $modules = function_exists('apache_get_modules') ? @apache_get_modules() : false;
        if (!is_array($modules)) {
            return self::result('server', 'pass', sprintf(
                /* translators: %s: Apache or LiteSpeed */
                __('%s: Static Bridge scrive le sue regole nel file .htaccess. PHP gira separato dal server, quindi i suoi moduli non si possono leggere: compressione e cache vengono provate più sotto con richieste vere.', 'static-bridge'),
                $name
            ));
        }
        $wanted = array('mod_rewrite', 'mod_headers', 'mod_deflate', 'mod_expires');
        $present = array_values(array_intersect($wanted, $modules));
        $missing = array_values(array_diff($wanted, $modules));
        if (in_array('mod_rewrite', $missing, true)) {
            return self::result('server', 'warning', sprintf(
                /* translators: %s: Apache or LiteSpeed */
                __('%s senza mod_rewrite: le pagine statiche non possono essere servite.', 'static-bridge'),
                $name
            ), __('Chiedi all’hosting di attivare mod_rewrite.', 'static-bridge'));
        }
        return self::result('server', 'pass', sprintf(
            /* translators: 1: Apache or LiteSpeed, 2: list of modules, 3: list of modules or "nessuno" */
            __('%1$s: Static Bridge scrive le sue regole nel file .htaccess. Moduli attivi: %2$s. Mancano: %3$s.', 'static-bridge'),
            $name,
            implode(', ', $present),
            empty($missing) ? __('nessuno', 'static-bridge') : implode(', ', $missing)
        ));
    }

    private function check_host_cache()
    {
        $first = $this->probe($this->context['home']);
        if (is_wp_error($first)) {
            return self::result('host-cache', 'skip', $first->get_error_message(), __('Il sito deve poter chiamare sé stesso via HTTP; ripeti il controllo più tardi.', 'static-bridge'));
        }
        $second = $this->probe($this->context['home']);
        $layer = self::cache_layer($first['headers'], is_wp_error($second) ? array() : $second['headers']);
        if (null === $layer) {
            return self::result('host-cache', 'pass', __('Nessuna cache davanti al sito: ogni pubblicazione è visibile subito ai visitatori.', 'static-bridge'));
        }
        if (false === $layer['stores']) {
            return self::result('host-cache', 'pass', sprintf(
                /* translators: 1: name of the cache, 2: header and value */
                __('%1$s è davanti al sito ma non conserva le pagine HTML (%2$s).', 'static-bridge'),
                $layer['name'],
                $layer['evidence']
            ));
        }
        if ('aruba' === $layer['id'] && $this->context['aruba_purge']) {
            return self::result('host-cache', 'pass', sprintf(
                /* translators: %s: header and value */
                __('La cache HiSpeed di Aruba conserva le pagine e Static Bridge la svuota a ogni pubblicazione, attraverso il plugin Aruba HiSpeed Cache (%s).', 'static-bridge'),
                $layer['evidence']
            ));
        }
        if ('cloudflare' === $layer['id'] && $this->context['cloudflare_purge']) {
            return self::result('host-cache', 'pass', sprintf(
                /* translators: %s: header and value */
                __('Cloudflare conserva le pagine e Static Bridge lo svuota a ogni pubblicazione (%s).', 'static-bridge'),
                $layer['evidence']
            ));
        }
        $summary = null === $layer['stores']
            ? sprintf(
                /* translators: 1: name of the cache, 2: header and value */
                __('%1$s è davanti al sito (%2$s), ma dalle risposte non si capisce se conservi le pagine.', 'static-bridge'),
                $layer['name'],
                $layer['evidence']
            )
            : sprintf(
                /* translators: 1: name of the cache, 2: header and value */
                __('%1$s conserva le pagine (%2$s): dopo una pubblicazione i visitatori possono vedere ancora la versione precedente.', 'static-bridge'),
                $layer['name'],
                $layer['evidence']
            );
        if ('cloudflare' === $layer['id']) {
            $remediation = __('Attiva lo svuotamento di Cloudflare in Impostazioni, oppure escludi l’HTML dalla cache di Cloudflare.', 'static-bridge');
        } elseif ('aruba' === $layer['id']) {
            $remediation = __('Attiva il plugin Aruba HiSpeed Cache: Static Bridge lo usa per svuotare la cache a ogni pubblicazione. Oppure disattiva HiSpeed Cache dal pannello Aruba: con Static Bridge le pagine sono già statiche.', 'static-bridge');
        } else {
            $remediation = __('Chiedi all’hosting di escludere l’HTML dalla cache del server, oppure di svuotarla dopo ogni pubblicazione.', 'static-bridge');
        }
        return self::result('host-cache', null === $layer['stores'] ? 'tip' : 'warning', $summary, $remediation);
    }

    private function check_page_cache_plugins()
    {
        $active = (array) get_option('active_plugins', array());
        if (function_exists('is_multisite') && is_multisite()) {
            $active = array_merge($active, array_keys((array) get_site_option('active_sitewide_plugins', array())));
        }
        $found = self::plugins_in($active, self::PAGE_CACHE_PLUGINS);
        $advanced = WP_CONTENT_DIR . '/advanced-cache.php';
        if (defined('WP_CACHE') && WP_CACHE && is_file($advanced) && empty($found)) {
            $found[] = sprintf(
                /* translators: %s: name of the plugin or "di origine sconosciuta" */
                __('advanced-cache.php (%s)', 'static-bridge'),
                self::file_owner($advanced, array()) ?: __('di origine sconosciuta', 'static-bridge')
            );
        }
        $purgers = self::plugins_in($active, self::CACHE_PURGE_PLUGINS);
        if (!empty($found)) {
            return self::result('page-cache-plugins', 'warning', sprintf(
                /* translators: %s: list of plugin names */
                __('Cache delle pagine attiva in: %s. Con Static Bridge è un secondo strato che non velocizza e può mostrare pagine vecchie dopo una pubblicazione.', 'static-bridge'),
                implode(', ', $found)
            ), __('Disattiva la cache delle pagine nel plugin (le sue altre funzioni possono restare), oppure disattiva il plugin.', 'static-bridge'));
        }
        if (!empty($purgers)) {
            return self::result('page-cache-plugins', 'pass', sprintf(
                /* translators: %s: list of plugin names */
                __('Nessuna cache delle pagine nei plugin. %s svuota soltanto la cache dell’hosting: con Static Bridge va bene.', 'static-bridge'),
                implode(', ', $purgers)
            ));
        }
        return self::result('page-cache-plugins', 'pass', __('Nessun plugin di cache delle pagine: le pagine le serve Static Bridge.', 'static-bridge'));
    }

    private function check_compression()
    {
        $missing = array();
        $used = array();
        foreach ($this->sample_urls() as $kind => $url) {
            $response = $this->probe($url, array('Accept-Encoding' => 'br, gzip'));
            if (is_wp_error($response)) {
                return self::result('compression', 'skip', $response->get_error_message(), __('Il sito deve poter chiamare sé stesso via HTTP; ripeti il controllo più tardi.', 'static-bridge'));
            }
            $encoding = strtolower(trim($response['headers']['content-encoding']));
            if (in_array($encoding, array('br', 'gzip', 'deflate', 'zstd'), true)) {
                $used[$encoding] = true;
            } else {
                $missing[] = self::sample_label($kind);
            }
        }
        if (empty($missing)) {
            return self::result('compression', 'pass', sprintf(
                /* translators: %s: compression methods, for example "br, gzip" */
                __('Pagine, CSS e JavaScript arrivano compressi (%s).', 'static-bridge'),
                implode(', ', array_keys($used))
            ));
        }
        $remediation = $this->is_nginx()
            ? __('Aggiungi alla configurazione di Nginx «gzip on;» e «gzip_types text/css application/javascript application/json image/svg+xml;», poi ricarica Nginx; oppure chiedilo all’hosting.', 'static-bridge')
            : __('Chiedi all’hosting di attivare la compressione (mod_deflate o Brotli) per HTML, CSS e JavaScript.', 'static-bridge');
        return self::result('compression', 'warning', sprintf(
            /* translators: %s: list such as "pagine, CSS" */
            __('Arrivano senza compressione: %s. Compressi peserebbero da tre a cinque volte meno.', 'static-bridge'),
            self::human_list($missing)
        ), $remediation);
    }

    private function check_browser_cache()
    {
        $short = array();
        $page_age = null;
        foreach ($this->sample_urls() as $kind => $url) {
            $response = $this->probe($url);
            if (is_wp_error($response)) {
                return self::result('browser-cache', 'skip', $response->get_error_message(), __('Il sito deve poter chiamare sé stesso via HTTP; ripeti il controllo più tardi.', 'static-bridge'));
            }
            $age = self::max_age($response['headers']['cache-control'], $response['headers']['expires'], $response['headers']['date']);
            if ('page' === $kind) {
                $page_age = $age;
            } elseif (null === $age || $age < self::ASSET_MIN_MAX_AGE) {
                $short[] = self::sample_label($kind);
            }
        }
        if (null !== $page_age && $page_age > self::HTML_MAX_MAX_AGE) {
            return self::result('browser-cache', 'warning', sprintf(
                /* translators: %s: duration, for example "3 ore" */
                __('Il browser tiene le pagine per %s: dopo una pubblicazione chi è già passato dal sito continua a vedere la versione precedente.', 'static-bridge'),
                human_time_diff(0, $page_age)
            ), __('In Impostazioni, «Cache-Control delle pagine statiche»: usa max-age=0 (s-maxage vale solo per la CDN). Se l’intestazione non viene da Static Bridge, chiedi all’hosting di toglierla dall’HTML.', 'static-bridge'));
        }
        if (!empty($short)) {
            $remediation = $this->is_nginx()
                ? __('Aggiungi a Nginx, per i file .css e .js, «expires 30d;» (o un Cache-Control con max-age lungo), poi ricarica Nginx; oppure chiedilo all’hosting.', 'static-bridge')
                : __('Chiedi all’hosting di attivare mod_expires, o un Cache-Control con max-age lungo, per CSS, JavaScript e immagini.', 'static-bridge');
            return self::result('browser-cache', 'tip', sprintf(
                /* translators: %s: list such as "CSS, JavaScript" */
                __('%s senza una cache del browser lunga: a ogni pagina il browser li richiede di nuovo. Le pagine sono a posto.', 'static-bridge'),
                self::human_list($short)
            ), $remediation);
        }
        return self::result('browser-cache', 'pass', __('CSS e JavaScript restano nel browser a lungo; le pagine vengono ricontrollate a ogni visita, quindi una pubblicazione si vede subito.', 'static-bridge'));
    }

    private function check_php()
    {
        $memory = function_exists('wp_convert_hr_to_bytes') ? wp_convert_hr_to_bytes((string) ini_get('memory_limit')) : 0;
        $memory_text = $memory > 0 ? size_format($memory) : __('senza limite', 'static-bridge');
        // opcache_get_status() can be disabled or restricted (opcache.restrict_api) while OPcache runs:
        // then the ini setting tells; without the extension, OPcache is off.
        $opcache = null;
        if (function_exists('opcache_get_status')) {
            $status = @opcache_get_status(false);
            $opcache = is_array($status) ? !empty($status['opcache_enabled']) : null;
        }
        if (null === $opcache) {
            $opcache = extension_loaded('Zend OPcache') ? in_array(strtolower((string) ini_get('opcache.enable')), array('1', 'on', 'true'), true) : false;
        }
        if (false === $opcache) {
            return self::result('php', 'warning', sprintf(
                /* translators: 1: PHP version, 2: memory limit */
                __('PHP %1$s con OPcache spento: ogni richiesta a WordPress (amministrazione, moduli, rigenerazione delle pagine) ricompila il codice. Memoria: %2$s.', 'static-bridge'),
                PHP_VERSION,
                $memory_text
            ), __('Chiedi all’hosting di attivare OPcache, di solito è un’opzione del pannello PHP.', 'static-bridge'));
        }
        if ($memory > 0 && $memory < 134217728) {
            return self::result('php', 'tip', sprintf(
                /* translators: 1: PHP version, 2: memory limit */
                __('PHP %1$s, memoria %2$s: poca per rigenerare pagine pesanti.', 'static-bridge'),
                PHP_VERSION,
                $memory_text
            ), __('Chiedi all’hosting almeno 256 MB di memory_limit.', 'static-bridge'));
        }
        return self::result('php', 'pass', sprintf(
            /* translators: 1: PHP version, 2: memory limit */
            __('PHP %1$s con OPcache attivo, memoria %2$s.', 'static-bridge'),
            PHP_VERSION,
            $memory_text
        ));
    }

    private function check_object_cache()
    {
        $dropin = WP_CONTENT_DIR . '/object-cache.php';
        $owner = is_file($dropin) ? (self::file_owner($dropin, self::DROPIN_SIGNATURES) ?: __('di origine sconosciuta', 'static-bridge')) : '';
        $in_use = function_exists('wp_using_ext_object_cache') && wp_using_ext_object_cache();
        if ($in_use) {
            return self::result('object-cache', 'pass', '' !== $owner
                ? sprintf(
                    /* translators: %s: name of the drop-in */
                    __('Object cache attiva (object-cache.php: %s).', 'static-bridge'),
                    $owner
                )
                : __('Object cache attiva.', 'static-bridge'));
        }
        if ('' !== $owner) {
            return self::result('object-cache', 'warning', sprintf(
                /* translators: %s: name of the drop-in */
                __('C’è un file object-cache.php (%s), ma WordPress non sta usando una cache esterna: il servizio potrebbe non rispondere.', 'static-bridge'),
                $owner
            ), __('Controlla il plugin che ha installato il file, o chiedi all’hosting se Redis o Memcached sono attivi.', 'static-bridge'));
        }
        $services = self::object_cache_services();
        foreach ($services as $service) {
            if ($service['reachable']) {
                return self::result('object-cache', 'tip', sprintf(
                    /* translators: 1: Redis or Memcached, 2: address such as 127.0.0.1:6379 */
                    __('%1$s risponde su %2$s. Un’object cache velocizzerebbe amministrazione, editor, moduli e la rigenerazione delle pagine; ai visitatori arrivano già pagine statiche, quindi per loro cambia poco.', 'static-bridge'),
                    $service['name'],
                    $service['where']
                ), __('Si attiva qui sotto, in «Cosa può fare Static Bridge».', 'static-bridge'));
            }
        }
        if (!empty($services)) {
            $names = array();
            foreach ($services as $service) {
                $names[] = $service['name'];
            }
            return self::result('object-cache', 'tip', sprintf(
                /* translators: %s: Redis, Memcached or both */
                __('PHP ha l’estensione per %s, ma nessun servizio risponde agli indirizzi soliti.', 'static-bridge'),
                implode(', ', $names)
            ), __('Chiedi all’hosting se il servizio è compreso nel piano e a quale indirizzo risponde.', 'static-bridge'));
        }
        return self::result('object-cache', 'pass', __('Né Redis né Memcached sono disponibili. Non manca nulla: le pagine statiche non ne hanno bisogno.', 'static-bridge'));
    }

    private function check_https()
    {
        $scheme = strtolower((string) wp_parse_url($this->context['home'], PHP_URL_SCHEME));
        if ('https' !== $scheme) {
            return self::result('https', 'warning', __('Il sito non usa HTTPS: i browser lo segnalano come non sicuro e i moduli viaggiano in chiaro.', 'static-bridge'), __('Attiva un certificato dal pannello dell’hosting (di solito Let’s Encrypt è gratuito), poi aggiorna l’indirizzo del sito in Impostazioni → Generali.', 'static-bridge'));
        }
        $response = $this->probe($this->context['home']);
        if (is_wp_error($response)) {
            return self::result('https', 'skip', $response->get_error_message(), __('Il sito deve poter chiamare sé stesso via HTTP; ripeti il controllo più tardi.', 'static-bridge'));
        }
        $missing = array();
        foreach (array('x-content-type-options' => 'X-Content-Type-Options', 'x-frame-options' => 'X-Frame-Options', 'referrer-policy' => 'Referrer-Policy') as $header => $name) {
            if ('' === $response['headers'][$header]) {
                $missing[] = $name;
            }
        }
        $hsts = '' !== $response['headers']['strict-transport-security'];
        if (empty($missing) && $hsts) {
            return self::result('https', 'pass', __('HTTPS attivo, con Strict-Transport-Security e le intestazioni di sicurezza di base.', 'static-bridge'));
        }
        if (empty($missing)) {
            return self::result('https', 'tip', __('HTTPS attivo, con le intestazioni di sicurezza di base; manca Strict-Transport-Security.', 'static-bridge'), __('Aggiungila solo quando tutto il sito, sottodomini compresi, funziona in HTTPS: chiedila all’hosting o mettila da un plugin di sicurezza. Static Bridge la ripete da solo sulle pagine statiche al prossimo export completo.', 'static-bridge'));
        }
        $parts = array();
        if (!empty($missing)) {
            $parts[] = sprintf(
                /* translators: %s: list of header names */
                _n('manca %s', 'mancano %s', count($missing), 'static-bridge'),
                self::human_list($missing)
            );
        }
        if (!$hsts) {
            $parts[] = __('manca Strict-Transport-Security', 'static-bridge');
        }
        return self::result('https', 'tip', sprintf(
            /* translators: %s: what is missing */
            __('HTTPS attivo; %s.', 'static-bridge'),
            implode('; ', $parts)
        ), __('Aggiungile da un plugin di sicurezza o chiedile all’hosting: Static Bridge le ripete da solo sulle pagine statiche al prossimo export completo. Strict-Transport-Security solo quando tutto il sito, sottodomini compresi, funziona in HTTPS.', 'static-bridge'));
    }

    /** WP-Cron (2.5.0): the scheduled events as WP keeps them, read only. */
    private function check_wp_cron()
    {
        $cron = function_exists('_get_cron_array') ? _get_cron_array() : get_option('cron', array());
        return self::wp_cron_result(
            is_array($cron) ? $cron : array(),
            time(),
            defined('DISABLE_WP_CRON') && DISABLE_WP_CRON,
            defined('ALTERNATE_WP_CRON') && ALTERNATE_WP_CRON,
            $this->context['cron_hooks'],
            site_url('wp-cron.php')
        );
    }

    /**
     * The WP-Cron result from the events array (timestamp => hook => events), at $now. Late events of Static Bridge
     * (static_bridge_*, more than CRON_LATE_AFTER) are a warning; any event more than CRON_IDLE_AFTER late is a warning
     * too (WP-Cron has not started). Otherwise DISABLE_WP_CRON means a system cron that works (pass); without it,
     * WP-Cron starts only with the visits that reach WP, rare with the static copy, and a system cron is advised
     * (tip), also with ALTERNATE_WP_CRON, which starts it with a redirect of those visits.
     */
    public static function wp_cron_result(array $cron, $now, $disabled, $alternate, array $labels = array(), $cron_url = '')
    {
        $now = (int) $now;
        $oldest = 0;
        $waiting = 0;
        $late_oldest = 0;
        $late_count = 0;
        $late = array();
        foreach ($cron as $timestamp => $hooks) {
            if (!is_numeric($timestamp) || !is_array($hooks) || (int) $timestamp > $now) {
                continue;
            }
            $timestamp = (int) $timestamp;
            foreach ($hooks as $hook => $events) {
                $count = is_array($events) ? max(1, count($events)) : 1;
                $waiting += $count;
                $oldest = 0 === $oldest ? $timestamp : min($oldest, $timestamp);
                if (is_string($hook) && 0 === strpos($hook, 'static_bridge_') && $now - $timestamp > self::CRON_LATE_AFTER) {
                    $late_count += $count;
                    $late_oldest = 0 === $late_oldest ? $timestamp : min($late_oldest, $timestamp);
                    $late[$hook] = isset($labels[$hook]) && is_string($labels[$hook]) ? $labels[$hook] : $hook;
                }
            }
        }
        $cron_url = '' !== (string) $cron_url ? (string) $cron_url : 'wp-cron.php';
        $system_cron = $disabled
            ? sprintf(
                /* translators: %s: address of wp-cron.php */
                __('DISABLE_WP_CRON è acceso in wp-config.php, quindi WP-Cron parte solo dal cron di sistema, che non sembra girare: controlla che richiami %s ogni 5 minuti (o che esegua «wp cron event run --due-now» con WP-CLI).', 'static-bridge'),
                $cron_url
            )
            : sprintf(
                /* translators: %s: address of wp-cron.php */
                __('Configura un cron di sistema che richiami %s ogni 5 minuti (o che esegua «wp cron event run --due-now» con WP-CLI), poi definisci DISABLE_WP_CRON a true in wp-config.php. Se WP-Cron non parte nemmeno quando apri l’amministrazione, esegui la Diagnostica: il server potrebbe non riuscire a chiamare sé stesso.', 'static-bridge'),
                $cron_url
            );
        if ($late_count > 0) {
            return self::result('wp-cron', 'warning', sprintf(
                /* translators: 1: number of events, 2: how long, such as «2 ore», 3: what they do */
                _n('%1$s evento di Static Bridge è in ritardo di %2$s (%3$s): le pagine statiche non si aggiornano finché WP-Cron non parte.', '%1$s eventi di Static Bridge sono in ritardo, il più vecchio di %2$s (%3$s): le pagine statiche non si aggiornano finché WP-Cron non parte.', $late_count, 'static-bridge'),
                number_format_i18n($late_count),
                human_time_diff($late_oldest, $now),
                implode(', ', array_slice(array_values(array_unique($late)), 0, 5))
            ), $system_cron);
        }
        if ($oldest > 0 && $now - $oldest > self::CRON_IDLE_AFTER) {
            return self::result('wp-cron', 'warning', sprintf(
                /* translators: 1: how long, such as «2 ore», 2: number of events */
                _n('WP-Cron non parte da almeno %1$s: %2$s evento aspetta, e con lui aspettano gli aggiornamenti incrementali, l’export pianificato e i contenuti programmati.', 'WP-Cron non parte da almeno %1$s: %2$s eventi aspettano, e con loro aspettano gli aggiornamenti incrementali, l’export pianificato e i contenuti programmati.', $waiting, 'static-bridge'),
                human_time_diff($oldest, $now),
                number_format_i18n($waiting)
            ), $system_cron);
        }
        if ($disabled) {
            return self::result('wp-cron', 'pass', __('WP-Cron parte dal cron di sistema (DISABLE_WP_CRON) e nessun evento è in ritardo.', 'static-bridge'));
        }
        if ($alternate) {
            return self::result('wp-cron', 'tip', __('Nessun evento in ritardo, ma con ALTERNATE_WP_CRON WP-Cron parte con un redirect delle visite che arrivano a WP, mai con quelle servite dalla copia statica: aggiornamenti incrementali, export pianificato e contenuti programmati possono aspettare.', 'static-bridge'), $system_cron);
        }
        return self::result('wp-cron', 'tip', __('Nessun evento in ritardo, ma WP-Cron parte solo con le visite che arrivano a WP, poche quando le pagine sono statiche: aggiornamenti incrementali, export pianificato e contenuti programmati possono aspettare.', 'static-bridge'), $system_cron);
    }

    // ----------------------------------------------------------------- helpers

    /** @return array{kind: string} kind: apache, litespeed, nginx or other. */
    public static function server_software($software)
    {
        $software = strtolower((string) $software);
        foreach (array('litespeed' => 'litespeed', 'nginx' => 'nginx', 'apache' => 'apache') as $needle => $kind) {
            if (false !== strpos($software, $needle)) {
                return array('kind' => $kind);
            }
        }
        return array('kind' => 'other');
    }

    /**
     * The cache in front of the site, from two consecutive responses for the same page.
     *
     * @return array{id: string, name: string, stores: bool|null, evidence: string}|null
     *         stores: true keeps HTML, false lets it through, null cannot tell.
     */
    public static function cache_layer(array $first, array $second)
    {
        $get = static function (array $headers, $name) {
            return isset($headers[$name]) ? trim((string) $headers[$name]) : '';
        };
        $value = $get($first, 'cf-cache-status');
        if ('' !== $value) {
            $later = $get($second, 'cf-cache-status');
            $state = strtoupper('' !== $later ? $later : $value);
            return array(
                'id' => 'cloudflare',
                'name' => 'Cloudflare',
                'stores' => !in_array($state, array('DYNAMIC', 'BYPASS', 'NONE'), true),
                'evidence' => 'cf-cache-status: ' . $state,
            );
        }
        $value = $get($second, 'x-aruba-cache') ?: $get($first, 'x-aruba-cache');
        if ('' !== $value && 'NA' !== strtoupper($value)) {
            return array(
                'id' => 'aruba',
                'name' => __('La cache HiSpeed di Aruba', 'static-bridge'),
                'stores' => false === stripos($value, 'bypass'),
                'evidence' => 'x-aruba-cache: ' . $value,
            );
        }
        $value = $get($second, 'x-litespeed-cache') ?: $get($first, 'x-litespeed-cache');
        if ('' !== $value) {
            return array(
                'id' => 'litespeed',
                'name' => __('La cache di LiteSpeed', 'static-bridge'),
                'stores' => false === stripos($value, 'bypass') && false === stripos($value, 'no-cache'),
                'evidence' => 'x-litespeed-cache: ' . $value,
            );
        }
        $age = (int) $get($second, 'age');
        if ('' !== $get($first, 'x-varnish') || false !== stripos($get($first, 'via'), 'varnish')) {
            return array(
                'id' => 'varnish',
                'name' => 'Varnish',
                'stores' => $age > 0 ? true : null,
                'evidence' => $age > 0 ? 'age: ' . $age : ('' !== $get($first, 'x-varnish') ? 'x-varnish' : 'via: ' . $get($first, 'via')),
            );
        }
        foreach (array('x-cache', 'x-cache-status', 'x-proxy-cache', 'x-srcache-fetch-status', 'x-fastcgi-cache') as $name) {
            $value = $get($second, $name) ?: $get($first, $name);
            if ('' === $value) {
                continue;
            }
            $state = strtoupper($value);
            $stores = null;
            if (preg_match('/\b(HIT|MISS|EXPIRED|STALE|UPDATING|REVALIDATED)\b/', $state)) {
                $stores = true;
            } elseif (preg_match('/\b(BYPASS|DYNAMIC|DISABLED|PASS)\b/', $state)) {
                $stores = false;
            }
            return array(
                'id' => 'server',
                'name' => __('Una cache del server', 'static-bridge'),
                'stores' => $stores,
                'evidence' => $name . ': ' . $value,
            );
        }
        if ($age > 0) {
            return array(
                'id' => 'server',
                'name' => __('Una cache del server', 'static-bridge'),
                'stores' => true,
                'evidence' => 'age: ' . $age,
            );
        }
        return null;
    }

    /** Seconds a browser may keep a response, null when the response says nothing. s-maxage is for shared caches only. */
    public static function max_age($cache_control, $expires = '', $date = '')
    {
        $cache_control = strtolower((string) $cache_control);
        if ('' !== $cache_control) {
            if (preg_match('/(?:^|[,\s])(no-store|no-cache)(?:$|[,\s=])/', $cache_control)) {
                return 0;
            }
            if (preg_match('/(?:^|[,\s])max-age\s*=\s*"?(\d+)/', $cache_control, $match)) {
                return (int) $match[1];
            }
        }
        if ('' !== (string) $expires) {
            $until = strtotime((string) $expires);
            if (false === $until) {
                return 0;
            }
            $now = '' !== (string) $date && false !== strtotime((string) $date) ? strtotime((string) $date) : time();
            return max(0, $until - $now);
        }
        return null;
    }

    /** Names of the active plugins (basenames such as wp-rocket/wp-rocket.php) found in $known (folder => name). */
    public static function plugins_in(array $active, array $known)
    {
        $found = array();
        foreach ($active as $basename) {
            $folder = strtok((string) $basename, '/');
            if (false !== $folder && isset($known[$folder])) {
                $found[$folder] = $known[$folder];
            }
        }
        return array_values($found);
    }

    /** Who wrote a drop-in, from its first 4 KB: its Plugin Name, else a known signature, else ''. */
    public static function file_owner($file, array $signatures)
    {
        $head = @file_get_contents($file, false, null, 0, 4096);
        if (!is_string($head) || '' === $head) {
            return '';
        }
        if (preg_match('/^[\s\/*#@]*Plugin Name:\s*(.+)$/mi', $head, $match)) {
            return substr(trim(wp_strip_all_tags($match[1])), 0, 80);
        }
        foreach ($signatures as $needle => $name) {
            if (false !== stripos($head, $needle)) {
                return $name;
            }
        }
        return '';
    }

    /**
     * Redis and Memcached as PHP sees them: only services whose extension is loaded, each with the first
     * address that answers. Addresses come from the host's constants, then the usual ones. Credentials
     * are used to connect but never returned.
     *
     * @return array<int, array{name: string, reachable: bool, where: string, host: string, port: int, auth: bool, database: int}>
     */
    public static function object_cache_services()
    {
        $services = array();
        if (extension_loaded('redis') && class_exists('Redis')) {
            $services[] = self::redis_service();
        }
        if (extension_loaded('memcached') && class_exists('Memcached')) {
            $services[] = self::memcached_service();
        }
        return $services;
    }

    private static function redis_service()
    {
        $endpoints = array();
        if (defined('WP_REDIS_PATH') && '' !== (string) WP_REDIS_PATH) {
            $endpoints[] = array((string) WP_REDIS_PATH, 0);
        } elseif (defined('WP_REDIS_HOST') && '' !== (string) WP_REDIS_HOST) {
            $endpoints[] = array((string) WP_REDIS_HOST, defined('WP_REDIS_PORT') ? (int) WP_REDIS_PORT : 6379);
        } else {
            foreach (array('/run/redis/redis-server.sock', '/var/run/redis/redis.sock', '/tmp/redis.sock') as $socket) {
                if (@file_exists($socket)) {
                    $endpoints[] = array($socket, 0);
                }
            }
            $endpoints[] = array('127.0.0.1', 6379);
        }
        foreach ($endpoints as $endpoint) {
            try {
                $redis = new Redis();
                if (!@$redis->connect($endpoint[0], $endpoint[1], self::SERVICE_TIMEOUT)) {
                    continue;
                }
                if (defined('WP_REDIS_PASSWORD') && '' !== WP_REDIS_PASSWORD) {
                    $redis->auth(WP_REDIS_PASSWORD);
                }
                if (defined('WP_REDIS_DATABASE') && (int) WP_REDIS_DATABASE > 0) {
                    $redis->select((int) WP_REDIS_DATABASE);
                }
                $pong = $redis->ping();
                $redis->close();
                if (true === $pong || (is_string($pong) && false !== stripos($pong, 'pong'))) {
                    return self::service('Redis', $endpoint, defined('WP_REDIS_PASSWORD') && '' !== WP_REDIS_PASSWORD, defined('WP_REDIS_DATABASE') ? (int) WP_REDIS_DATABASE : 0);
                }
            } catch (Throwable $error) {
                continue;
            }
        }
        return self::service('Redis', null);
    }

    private static function memcached_service()
    {
        $endpoints = array();
        foreach (array('/run/memcached/memcached.sock', '/var/run/memcached/memcached.sock', '/tmp/memcached.sock') as $socket) {
            if (@file_exists($socket)) {
                $endpoints[] = array($socket, 0);
            }
        }
        $endpoints[] = array('127.0.0.1', 11211);
        foreach ($endpoints as $endpoint) {
            try {
                $memcached = new Memcached();
                $memcached->setOption(Memcached::OPT_CONNECT_TIMEOUT, (int) (self::SERVICE_TIMEOUT * 1000));
                $memcached->setOption(Memcached::OPT_SEND_TIMEOUT, (int) (self::SERVICE_TIMEOUT * 1000000));
                $memcached->setOption(Memcached::OPT_RECV_TIMEOUT, (int) (self::SERVICE_TIMEOUT * 1000000));
                $memcached->addServer($endpoint[0], $endpoint[1]);
                $versions = $memcached->getVersion();
                $memcached->quit();
                if (is_array($versions)) {
                    foreach ($versions as $version) {
                        if (is_string($version) && '' !== $version && '255.255.255' !== $version) {
                            return self::service('Memcached', $endpoint);
                        }
                    }
                }
            } catch (Throwable $error) {
                continue;
            }
        }
        return self::service('Memcached', null);
    }

    /** "a", "a e b", "a, b e c". */
    private static function human_list(array $items)
    {
        $items = array_values($items);
        if (count($items) < 2) {
            return implode('', $items);
        }
        $last = array_pop($items);
        return sprintf(
            /* translators: 1: comma-separated items, 2: last item */
            __('%1$s e %2$s', 'static-bridge'),
            implode(', ', $items),
            $last
        );
    }

    /** A service found ($endpoint array(host or socket, port; port 0 for a socket)) or not (null). */
    private static function service($name, $endpoint, $auth = false, $database = 0)
    {
        if (null === $endpoint) {
            return array('name' => $name, 'reachable' => false, 'where' => '', 'host' => '', 'port' => 0, 'auth' => false, 'database' => 0);
        }
        return array(
            'name' => $name,
            'reachable' => true,
            'where' => 0 === $endpoint[1] ? $endpoint[0] : $endpoint[0] . ':' . $endpoint[1],
            'host' => (string) $endpoint[0],
            'port' => (int) $endpoint[1],
            'auth' => (bool) $auth,
            'database' => (int) $database,
        );
    }

    /** A page, a stylesheet and a script of the site: WordPress' own, present on every install. */
    private function sample_urls()
    {
        return array(
            'page' => $this->context['home'],
            'css' => includes_url('css/dashicons.min.css'),
            'js' => includes_url('js/jquery/jquery.min.js'),
        );
    }

    private static function sample_label($kind)
    {
        return 'page' === $kind ? __('pagine', 'static-bridge') : ('css' === $kind ? 'CSS' : 'JavaScript');
    }

    private function is_nginx()
    {
        return $this->context['nginx'] || 'nginx' === self::server_software(isset($_SERVER['SERVER_SOFTWARE']) ? (string) $_SERVER['SERVER_SOFTWARE'] : '')['kind'];
    }

    /**
     * A same-site GET as an anonymous visitor sends it: no cookies and no cache-busting headers,
     * so a cache in front of the site behaves as it does for visitors. Only the headers are kept. Behind Basic Auth
     * (2.5.0) the request carries the credentials of wp-config.php, as a visitor's browser does.
     *
     * @return WP_Error|array{status: int, headers: array<string, string>}
     */
    private function probe($url, array $headers = array())
    {
        $host = strtolower((string) wp_parse_url($url, PHP_URL_HOST));
        $home_host = strtolower((string) wp_parse_url($this->context['home'], PHP_URL_HOST));
        if ('' === $host || $host !== $home_host) {
            return new WP_Error('static_bridge_server_check_host', __('Richiesta bloccata: l’indirizzo non appartiene al sito.', 'static-bridge'));
        }
        $args = array(
            'timeout' => self::PROBE_TIMEOUT,
            'redirection' => 3,
            'decompress' => false,
            'limit_response_size' => self::PROBE_BODY_LIMIT,
            'sslverify' => !preg_match('/(?:\.local|\.test|\.localhost)$/i', $home_host),
            'headers' => array_merge(array('Accept' => 'text/html,*/*;q=0.8'), $headers),
            'user-agent' => 'Mozilla/5.0 (compatible; Static-Bridge server check)',
        );
        // A site behind Basic Auth: the plugin sends the header, and then follows the redirects itself.
        $response = null !== $this->context['request'] ? call_user_func($this->context['request'], $url, $args) : wp_safe_remote_get($url, $args);
        if (is_wp_error($response)) {
            return new WP_Error('static_bridge_server_check_http', sprintf(
                /* translators: %s: error message */
                __('Il sito non ha risposto alla richiesta di prova: %s', 'static-bridge'),
                $response->get_error_message()
            ));
        }
        $status = (int) wp_remote_retrieve_response_code($response);
        if ($status < 200 || $status >= 400) {
            return new WP_Error('static_bridge_server_check_status', sprintf(
                /* translators: 1: HTTP status code, 2: URL */
                __('Il sito ha risposto HTTP %1$s a %2$s.', 'static-bridge'),
                $status,
                $url
            ));
        }
        $values = array();
        foreach (self::HEADERS as $name) {
            $value = wp_remote_retrieve_header($response, $name);
            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }
            $values[$name] = substr(trim(wp_strip_all_tags((string) $value)), 0, 200);
        }
        return array('status' => $status, 'headers' => $values);
    }
}
