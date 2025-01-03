<?php

declare(strict_types=1);

namespace PhpMyAdmin;

use PhpMyAdmin\Config\Settings;
use PhpMyAdmin\Config\Settings\Server;
use PhpMyAdmin\ConfigStorage\Relation;
use PhpMyAdmin\Dbal\ConnectionType;
use PhpMyAdmin\Dbal\DatabaseInterface;
use PhpMyAdmin\Exceptions\ConfigException;
use PhpMyAdmin\I18n\LanguageManager;
use PhpMyAdmin\Routing\Routing;
use PhpMyAdmin\Theme\ThemeManager;
use Throwable;

use function __;
use function array_key_last;
use function array_replace_recursive;
use function array_slice;
use function count;
use function defined;
use function explode;
use function fclose;
use function file_exists;
use function filemtime;
use function fileperms;
use function fopen;
use function fread;
use function function_exists;
use function gd_info;
use function implode;
use function ini_get;
use function is_array;
use function is_dir;
use function is_numeric;
use function is_readable;
use function is_string;
use function is_writable;
use function mb_strtolower;
use function md5;
use function mkdir;
use function ob_end_clean;
use function ob_start;
use function parse_url;
use function realpath;
use function rtrim;
use function setcookie;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function stripos;
use function strtolower;
use function sys_get_temp_dir;
use function time;
use function trim;

use const CHANGELOG_FILE;
use const DIRECTORY_SEPARATOR;
use const PHP_OS;
use const PHP_URL_PATH;
use const PHP_URL_SCHEME;

/**
 * Configuration handling
 *
 * @psalm-import-type ServerSettingsType from Server
 * @psalm-import-type SettingsType from Settings
 */
class Config
{
    public static self|null $instance = null;

    /** @var mixed[]   default configuration settings */
    public array $default;

    /** @var mixed[]   configuration settings, without user preferences applied */
    public array $baseSettings;

    /** @psalm-var SettingsType */
    public array $settings;

    private string $source = '';

    /** @var int     source modification time */
    public int $sourceMtime = 0;

    private bool|null $isHttps = null;

    public Settings $config;
    /** @var int<0, max> */
    public int $server = 0;

    /** @var array<string,string> $tempDir */
    private static array $tempDir = [];

    private bool $hasSelectedServer = false;

    /** @psalm-var ServerSettingsType */
    public array $selectedServer;

    private bool $isSetup = false;

    /** @var ''|'db'|'session' */
    public string $userPreferences = '';

    public function __construct()
    {
        $this->config = new Settings([]);
        $config = $this->config->asArray();
        $this->default = $config;
        $this->settings = $config;
        $this->baseSettings = $config;
        $this->selectedServer = (new Server())->asArray();
    }

    /** @deprecated Use dependency injection instead. */
    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public function setSetup(bool $isSetup): void
    {
        $this->isSetup = $isSetup;
    }

    public function isSetup(): bool
    {
        return $this->isSetup;
    }

    /**
     * @param string|null $source source to read config from
     *
     * @throws ConfigException
     */
    public function loadAndCheck(string|null $source = null): void
    {
        if ($source !== null) {
            $this->setSource($source);
        }

        $this->load();

        // other settings, independent of config file, comes in
        $this->checkSystem();

        $this->baseSettings = $this->settings;
    }

    /**
     * sets system and application settings
     */
    public function checkSystem(): void
    {
        $this->checkGd2();
        $this->checkOutputCompression();
    }

    /**
     * whether to use gzip output compression or not
     */
    public function checkOutputCompression(): void
    {
        // If zlib output compression is set in the php configuration file, no
        // output buffering should be run
        if (ini_get('zlib.output_compression')) {
            $this->set('OBGzip', false);
        }

        // enable output-buffering (if set to 'auto')
        if ($this->config->OBGzip !== 'auto') {
            return;
        }

        $this->set('OBGzip', true);
    }

    /**
     * Whether GD2 is present
     */
    public function checkGd2(): void
    {
        if ($this->config->GD2Available === 'yes') {
            $this->set('PMA_IS_GD2', 1);

            return;
        }

        if ($this->config->GD2Available === 'no') {
            $this->set('PMA_IS_GD2', 0);

            return;
        }

        if (! function_exists('imagecreatetruecolor')) {
            $this->set('PMA_IS_GD2', 0);

            return;
        }

        if (function_exists('gd_info')) {
            $gdInfo = gd_info();
            if (str_contains($gdInfo['GD Version'], '2.')) {
                $this->set('PMA_IS_GD2', 1);

                return;
            }
        }

        $this->set('PMA_IS_GD2', 0);
    }

    public function isWindows(): bool
    {
        if (stripos(PHP_OS, 'win') !== false && stripos(PHP_OS, 'darwin') === false) {
            // Is it some version of Windows
            return true;
        }

        // Is it OS/2 (No file permissions like Windows)
        return stripos(PHP_OS, 'OS/2') !== false;
    }

    /**
     * loads configuration from $source, usually the config file
     * should be called on object creation
     *
     * @throws ConfigException
     */
    public function load(): bool
    {
        if (! $this->configFileExists()) {
            return false;
        }

        /** @var mixed $cfg */
        $cfg = [];

        ob_start();
        try {
            /**
             * Suppress any warnings generated by require or inside the included file
             *
             * @psalm-suppress UnresolvableInclude
             */
            @require $this->source;
        } catch (Throwable) {
            throw new ConfigException('Failed to load phpMyAdmin configuration.');
        }

        ob_end_clean();

        $this->sourceMtime = (int) filemtime($this->source);

        if (is_array($cfg)) {
            $this->config = new Settings($cfg);
            $this->settings = array_replace_recursive($this->settings, $this->config->asArray());
        }

        return true;
    }

    /**
     * Sets the connection collation
     */
    private function setConnectionCollation(): void
    {
        $collationConnection = $this->config->DefaultConnectionCollation;
        $dbi = DatabaseInterface::getInstance();
        if ($collationConnection === '' || $collationConnection === $dbi->getDefaultCollation()) {
            return;
        }

        $dbi->setCollation($collationConnection);
    }

    /**
     * Loads user preferences and merges them with current config
     * must be called after control connection has been established
     */
    public function loadUserPreferences(ThemeManager $themeManager, bool $isMinimumCommon = false): void
    {
        $cacheKey = 'server_' . Current::$server;
        if (Current::$server > 0 && ! $isMinimumCommon) {
            // cache user preferences, use database only when needed
            if (
                ! isset($_SESSION['cache'][$cacheKey]['userprefs'])
                || $_SESSION['cache'][$cacheKey]['config_mtime'] < $this->sourceMtime
            ) {
                $dbi = DatabaseInterface::getInstance();
                $userPreferences = new UserPreferences($dbi, new Relation($dbi), new Template());
                $prefs = $userPreferences->load();
                $_SESSION['cache'][$cacheKey]['userprefs'] = $userPreferences->apply($prefs['config_data']);
                $_SESSION['cache'][$cacheKey]['userprefs_mtime'] = $prefs['mtime'];
                $_SESSION['cache'][$cacheKey]['userprefs_type'] = $prefs['type'];
                $_SESSION['cache'][$cacheKey]['config_mtime'] = $this->sourceMtime;
            }
        } elseif (Current::$server === 0 || ! isset($_SESSION['cache'][$cacheKey]['userprefs'])) {
            $this->userPreferences = '';

            return;
        }

        $configData = $_SESSION['cache'][$cacheKey]['userprefs'];
        // type is 'db' or 'session'
        $this->userPreferences = $_SESSION['cache'][$cacheKey]['userprefs_type'];
        $this->set('user_preferences_mtime', $_SESSION['cache'][$cacheKey]['userprefs_mtime']);

        if (isset($configData['Server']) && is_array($configData['Server'])) {
            $serverConfig = array_replace_recursive($this->selectedServer, $configData['Server']);
            $this->selectedServer = (new Server($serverConfig))->asArray();
        }

        // load config array
        $this->settings = array_replace_recursive($this->settings, $configData);
        $this->config = new Settings($this->settings);

        if ($isMinimumCommon) {
            return;
        }

        // settings below start really working on next page load, but
        // changes are made only in index.php so everything is set when
        // in frames

        // save theme
        if ($themeManager->getThemeCookie() !== '' || isset($_REQUEST['set_theme'])) {
            if (
                (! isset($configData['ThemeDefault'])
                && $themeManager->theme->getId() !== 'original')
                || isset($configData['ThemeDefault'])
                && $configData['ThemeDefault'] != $themeManager->theme->getId()
            ) {
                $this->setUserValue(
                    null,
                    'ThemeDefault',
                    $themeManager->theme->getId(),
                    'original',
                );
            }
        } elseif (
            $this->settings['ThemeDefault'] != $themeManager->theme->getId()
            && $themeManager->themeExists($this->settings['ThemeDefault'])
        ) {
            // no cookie - read default from settings
            $themeManager->setActiveTheme($this->settings['ThemeDefault']);
            $themeManager->setThemeCookie();
        }

        // save language
        if ($this->issetCookie('pma_lang') || isset($_POST['lang'])) {
            if (
                (! isset($configData['lang'])
                && Current::$lang !== 'en')
                || isset($configData['lang'])
                && Current::$lang !== $configData['lang']
            ) {
                $this->setUserValue(null, 'lang', Current::$lang, 'en');
            }
        } elseif (isset($configData['lang'])) {
            $languageManager = LanguageManager::getInstance();
            // read language from settings
            $language = $languageManager->getLanguage($configData['lang']);
            if ($language !== false) {
                $languageManager->activate($language);
                $this->setCookie('pma_lang', $language->getCode());
            }
        }

        // set connection collation
        $this->setConnectionCollation();
    }

    /**
     * Sets config value which is stored in user preferences (if available)
     * or in a cookie.
     *
     * If user preferences are not yet initialized, option is applied to
     * global config and added to a update queue, which is processed
     * by {@link loadUserPreferences()}
     *
     * @param string|null $cookieName   can be null
     * @param string      $cfgPath      configuration path
     * @param mixed       $newCfgValue  new value
     * @param string|null $defaultValue default value
     */
    public function setUserValue(
        string|null $cookieName,
        string $cfgPath,
        mixed $newCfgValue,
        string|null $defaultValue = null,
    ): true|Message {
        $result = true;
        // use permanent user preferences if possible
        if ($this->userPreferences !== '') {
            if ($defaultValue === null) {
                $defaultValue = Core::arrayRead($cfgPath, $this->default);
            }

            $dbi = DatabaseInterface::getInstance();
            $userPreferences = new UserPreferences($dbi, new Relation($dbi), new Template());
            $result = $userPreferences->persistOption($cfgPath, $newCfgValue, $defaultValue);
        }

        if ($this->userPreferences !== 'db' && $cookieName) {
            // fall back to cookies
            if ($defaultValue === null) {
                $defaultValue = Core::arrayRead($cfgPath, $this->settings);
            }

            $this->setCookie($cookieName, (string) $newCfgValue, $defaultValue);
        }

        Core::arrayWrite($cfgPath, $this->settings, $newCfgValue);

        return $result;
    }

    /**
     * Reads value stored by {@link setUserValue()}
     *
     * @param string $cookieName cookie name
     * @param mixed  $cfgValue   config value
     */
    public function getUserValue(string $cookieName, mixed $cfgValue): mixed
    {
        $cookieExists = ! empty($this->getCookie($cookieName));
        if ($this->userPreferences === 'db') {
            // permanent user preferences value exists, remove cookie
            if ($cookieExists) {
                $this->removeCookie($cookieName);
            }
        } elseif ($cookieExists) {
            return $this->getCookie($cookieName);
        }

        // return value from $cfg array
        return $cfgValue;
    }

    /**
     * set source
     *
     * @param string $source source
     */
    public function setSource(string $source): void
    {
        $this->source = trim($source);
    }

    /** @throws ConfigException */
    public function configFileExists(): bool
    {
        if ($this->source === '') {
            // no configuration file set at all
            return false;
        }

        if (! @file_exists($this->source)) {
            return false;
        }

        if (! @is_readable($this->source)) {
            // manually check if file is readable
            // might be bug #3059806 Supporting running from CIFS/Samba shares

            $contents = false;
            $handle = @fopen($this->source, 'r');
            if ($handle !== false) {
                $contents = @fread($handle, 1); // reading 1 byte is enough to test
                fclose($handle);
            }

            if ($contents === false) {
                throw new ConfigException(sprintf(
                    function_exists('__')
                        ? __('Existing configuration file (%s) is not readable.')
                        : 'Existing configuration file (%s) is not readable.',
                    $this->source,
                ));
            }
        }

        return true;
    }

    /**
     * verifies the permissions on config file (if asked by configuration)
     * (must be called after config.inc.php has been merged)
     *
     * @throws ConfigException
     */
    public function checkPermissions(): void
    {
        // Check for permissions (on platforms that support it):
        if (! $this->config->CheckConfigurationPermissions || ! @file_exists($this->source)) {
            return;
        }

        $perms = @fileperms($this->source);
        if ($perms === false || ! ($perms & 2)) {
            return;
        }

        // This check is normally done after loading configuration
        if ($this->isWindows()) {
            return;
        }

        throw new ConfigException(__('Wrong permissions on configuration file, should not be world writable!'));
    }

    /**
     * returns specific config setting
     *
     * @param string $setting config setting
     *
     * @return mixed|null value
     */
    public function get(string $setting): mixed
    {
        return $this->settings[$setting] ?? null;
    }

    /**
     * sets configuration variable
     *
     * @param string $setting configuration option
     * @param mixed  $value   new value for configuration option
     */
    public function set(string $setting, mixed $value): void
    {
        if (isset($this->settings[$setting]) && $this->settings[$setting] === $value) {
            return;
        }

        $this->settings[$setting] = $value;
        $this->config = new Settings($this->settings);
    }

    /**
     * returns source for current config
     *
     * @return string  config source
     */
    public function getSource(): string
    {
        return $this->source;
    }

    public function isUploadEnabled(): bool
    {
        $iniValue = ini_get('file_uploads');

        // if set "php_admin_value file_uploads Off" in httpd.conf
        // ini_get() also returns the string "Off" in this case:
        return $iniValue !== false && $iniValue !== '' && $iniValue !== '0' && strtolower($iniValue) !== 'off';
    }

    /**
     * Checks if protocol is https
     *
     * This function checks if the https protocol on the active connection.
     */
    public function isHttps(): bool
    {
        if ($this->isHttps !== null) {
            return $this->isHttps;
        }

        $url = $this->config->PmaAbsoluteUri;

        $isHttps = false;
        if ($url !== '' && parse_url($url, PHP_URL_SCHEME) === 'https') {
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTP_SCHEME')) === 'https') {
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTPS')) === 'on') {
            $isHttps = true;
        } elseif (stripos(Core::getEnv('REQUEST_URI'), 'https:') === 0) {
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTP_HTTPS_FROM_LB')) === 'on') {
            // A10 Networks load balancer
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTP_FRONT_END_HTTPS')) === 'on') {
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTP_X_FORWARDED_PROTO')) === 'https') {
            $isHttps = true;
        } elseif (strtolower(Core::getEnv('HTTP_CLOUDFRONT_FORWARDED_PROTO')) === 'https') {
            // Amazon CloudFront, issue #15621
            $isHttps = true;
        } elseif (Util::getProtoFromForwardedHeader(Core::getEnv('HTTP_FORWARDED')) === 'https') {
            // RFC 7239 Forwarded header
            $isHttps = true;
        } elseif (Core::getEnv('SERVER_PORT') == 443) {
            $isHttps = true;
        }

        $this->isHttps = $isHttps;

        return $isHttps;
    }

    /**
     * Get phpMyAdmin root path
     */
    public function getRootPath(): string
    {
        $url = $this->config->PmaAbsoluteUri;

        if ($url !== '') {
            $path = parse_url($url, PHP_URL_PATH);
            if (! empty($path)) {
                if (! str_ends_with($path, '/')) {
                    return $path . '/';
                }

                return $path;
            }
        }

        $parsedUrlPath = Routing::getCleanPathInfo();

        $parts = explode('/', $parsedUrlPath);

        /* Remove filename */
        if (str_ends_with($parts[count($parts) - 1], '.php')) {
            $parts = array_slice($parts, 0, count($parts) - 1);
        }

        /* Remove extra path from javascript calls */
        if (defined('PMA_PATH_TO_BASEDIR')) {
            $parts = array_slice($parts, 0, count($parts) - 1);
        }

        // Add one more part to make the path end in slash unless it already ends with slash
        if (count($parts) < 2 || $parts[array_key_last($parts)] !== '') {
            $parts[] = '';
        }

        return implode('/', $parts);
    }

    /**
     * removes cookie
     *
     * @param string $cookieName name of cookie to remove
     */
    public function removeCookie(string $cookieName): bool
    {
        $httpCookieName = $this->getCookieName($cookieName);

        if ($this->issetCookie($cookieName)) {
            unset($_COOKIE[$httpCookieName]);
        }

        if (defined('TESTSUITE')) {
            return true;
        }

        return setcookie(
            $httpCookieName,
            '',
            time() - 3600,
            $this->getRootPath(),
            '',
            $this->isHttps(),
        );
    }

    /**
     * sets cookie if value is different from current cookie value,
     * or removes if value is equal to default
     *
     * @param string      $cookie   name of cookie to remove
     * @param string      $value    new cookie value
     * @param string|null $default  default value
     * @param int|null    $validity validity of cookie in seconds (default is one month)
     * @param bool        $httponly whether cookie is only for HTTP (and not for scripts)
     */
    public function setCookie(
        string $cookie,
        string $value,
        string|null $default = null,
        int|null $validity = null,
        bool $httponly = true,
    ): bool {
        if ($value !== '' && $value === $default) {
            // default value is used
            if ($this->issetCookie($cookie)) {
                // remove cookie
                return $this->removeCookie($cookie);
            }

            return false;
        }

        if ($value === '' && $this->issetCookie($cookie)) {
            // remove cookie, value is empty
            return $this->removeCookie($cookie);
        }

        $httpCookieName = $this->getCookieName($cookie);

        if (! $this->issetCookie($cookie) || $this->getCookie($cookie) !== $value) {
            // set cookie with new value
            /* Calculate cookie validity */
            if ($validity === null) {
                /* Valid for one month */
                $validity = time() + 2592000;
            } elseif ($validity === 0) {
                /* Valid for session */
                $validity = 0;
            } else {
                $validity += time();
            }

            if (defined('TESTSUITE')) {
                $_COOKIE[$httpCookieName] = $value;

                return true;
            }

            $cookieSameSite = $this->config->CookieSameSite;

            $optionalParams = [
                'expires' => $validity,
                'path' => $this->getRootPath(),
                'domain' => '',
                'secure' => $this->isHttps(),
                'httponly' => $httponly,
                'samesite' => $cookieSameSite,
            ];

            return setcookie($httpCookieName, $value, $optionalParams);
        }

        // cookie has already $value as value
        return true;
    }

    /**
     * get cookie
     *
     * @param string $cookieName The name of the cookie to get
     *
     * @return mixed result of getCookie()
     */
    public function getCookie(string $cookieName): mixed
    {
        return $_COOKIE[$this->getCookieName($cookieName)] ?? null;
    }

    /**
     * Get the real cookie name
     *
     * @param string $cookieName The name of the cookie
     */
    public function getCookieName(string $cookieName): string
    {
        return ($this->isHttps() ? '__Secure-' : '') . $cookieName . ($this->isHttps() ? '_https' : '');
    }

    /**
     * isset cookie
     *
     * @param string $cookieName The name of the cookie to check
     */
    public function issetCookie(string $cookieName): bool
    {
        return isset($_COOKIE[$this->getCookieName($cookieName)]);
    }

    /**
     * Returns temporary dir path
     *
     * @param string $name Directory name
     */
    public function getTempDir(string $name): string|null
    {
        if (isset(self::$tempDir[$name])) {
            return self::$tempDir[$name];
        }

        $path = $this->config->TempDir;
        if ($path === '') {
            return null;
        }

        $path = rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $name;
        if (! @is_dir($path)) {
            @mkdir($path, 0770, true);
        }

        if (! @is_dir($path) || ! @is_writable($path)) {
            return null;
        }

        self::$tempDir[$name] = $path;

        return $path;
    }

    /**
     * Returns temporary directory
     */
    public function getUploadTempDir(): string|null
    {
        // First try configured temp dir
        // Fallback to PHP upload_tmp_dir
        $dirs = [$this->getTempDir('upload'), ini_get('upload_tmp_dir'), sys_get_temp_dir()];

        foreach ($dirs as $dir) {
            if (! empty($dir) && @is_writable($dir)) {
                return realpath($dir);
            }
        }

        return null;
    }

    /** @return int<0, max> */
    public function selectServer(mixed $serverParamFromRequest): int
    {
        $serverNumber = 0;
        if (is_numeric($serverParamFromRequest)) {
            $serverNumber = (int) $serverParamFromRequest;
            $serverNumber = $serverNumber >= 1 ? $serverNumber : 0;
        } elseif (is_string($serverParamFromRequest) && $serverParamFromRequest !== '') {
            /** Lookup server by name (see FAQ 4.8) */
            foreach ($this->config->Servers as $i => $server) {
                if ($server->host === $serverParamFromRequest || $server->verbose === $serverParamFromRequest) {
                    $serverNumber = $i;
                    break;
                }

                $verboseToLower = mb_strtolower($server->verbose);
                $serverToLower = mb_strtolower($serverParamFromRequest);
                if ($verboseToLower === $serverToLower || md5($verboseToLower) === $serverToLower) {
                    $serverNumber = $i;
                    break;
                }
            }
        }

        /**
         * If no server is selected, make sure that $this->settings['Server'] is empty (so
         * that nothing will work), and skip server authentication.
         * We do NOT exit here, but continue on without logging into any server.
         * This way, the welcome page will still come up (with no server info) and
         * present a choice of servers in the case that there are multiple servers
         * and '$this->settings['ServerDefault'] = 0' is set.
         */
        if (isset($this->config->Servers[$serverNumber])) {
            $this->hasSelectedServer = true;
            $this->selectedServer = $this->config->Servers[$serverNumber]->asArray();
            $this->settings['Server'] = $this->selectedServer;
        } elseif (isset($this->config->Servers[$this->config->ServerDefault])) {
            $this->hasSelectedServer = true;
            $serverNumber = $this->config->ServerDefault;
            $this->selectedServer = $this->config->Servers[$this->config->ServerDefault]->asArray();
            $this->settings['Server'] = $this->selectedServer;
        } else {
            $this->hasSelectedServer = false;
            $serverNumber = 0;
            $this->selectedServer = (new Server())->asArray();
            $this->settings['Server'] = [];
        }

        $this->server = $serverNumber;

        return $this->server;
    }

    /**
     * Return connection parameters for the database server
     */
    public static function getConnectionParams(Server $currentServer, ConnectionType $connectionType): Server
    {
        if ($connectionType !== ConnectionType::ControlUser) {
            if ($currentServer->host !== '' && $currentServer->port !== '') {
                return $currentServer;
            }

            $server = $currentServer->asArray();
            $server['host'] = $server['host'] === '' ? 'localhost' : $server['host'];
            $server['port'] = $server['port'] === '' ? '0' : $server['port'];

            return new Server($server);
        }

        $server = [
            'user' => $currentServer->controlUser,
            'password' => $currentServer->controlPass,
            'host' => $currentServer->controlHost !== '' ? $currentServer->controlHost : $currentServer->host,
            'port' => '0',
            'socket' => null,
            'compress' => null,
            'ssl' => null,
            'ssl_key' => null,
            'ssl_cert' => null,
            'ssl_ca' => null,
            'ssl_ca_path' => null,
            'ssl_ciphers' => null,
            'ssl_verify' => null,
            'hide_connection_errors' => null,
        ];

        // Share the settings if the host is same
        if ($server['host'] === $currentServer->host) {
            $server['port'] = $currentServer->port !== '' ? $currentServer->port : '0';
            $server['socket'] = $currentServer->socket;
            $server['compress'] = $currentServer->compress;
            $server['ssl'] = $currentServer->ssl;
            $server['ssl_key'] = $currentServer->sslKey;
            $server['ssl_cert'] = $currentServer->sslCert;
            $server['ssl_ca'] = $currentServer->sslCa;
            $server['ssl_ca_path'] = $currentServer->sslCaPath;
            $server['ssl_ciphers'] = $currentServer->sslCiphers;
            $server['ssl_verify'] = $currentServer->sslVerify;
            $server['hide_connection_errors'] = $currentServer->hideConnectionErrors;
        }

        // Set configured port
        if ($currentServer->controlPort !== '') {
            $server['port'] = $currentServer->controlPort;
        }

        // Set any configuration with control_ prefix
        $server['socket'] = $currentServer->controlSocket ?? $server['socket'];
        $server['compress'] = $currentServer->controlCompress ?? $server['compress'];
        $server['ssl'] = $currentServer->controlSsl ?? $server['ssl'];
        $server['ssl_key'] = $currentServer->controlSslKey ?? $server['ssl_key'];
        $server['ssl_cert'] = $currentServer->controlSslCert ?? $server['ssl_cert'];
        $server['ssl_ca'] = $currentServer->controlSslCa ?? $server['ssl_ca'];
        $server['ssl_ca_path'] = $currentServer->controlSslCaPath ?? $server['ssl_ca_path'];
        $server['ssl_ciphers'] = $currentServer->controlSslCiphers ?? $server['ssl_ciphers'];
        $server['ssl_verify'] = $currentServer->controlSslVerify ?? $server['ssl_verify'];
        $server['hide_connection_errors'] = $currentServer->controlHideConnectionErrors
            ?? $server['hide_connection_errors'];

        if ($server['host'] === '') {
            $server['host'] = 'localhost';
        }

        return new Server($server);
    }

    /**
     * Get LoginCookieValidity from preferences cache.
     *
     * No generic solution for loading preferences from cache as some settings
     * need to be kept for processing in loadUserPreferences().
     *
     * @see loadUserPreferences()
     */
    public function getLoginCookieValidityFromCache(int $server): void
    {
        $cacheKey = 'server_' . $server;

        if (! isset($_SESSION['cache'][$cacheKey]['userprefs']['LoginCookieValidity'])) {
            return;
        }

        $value = $_SESSION['cache'][$cacheKey]['userprefs']['LoginCookieValidity'];
        $this->set('LoginCookieValidity', $value);
        $this->settings['LoginCookieValidity'] = $value;
    }

    public function getSettings(): Settings
    {
        return $this->config;
    }

    public function hasSelectedServer(): bool
    {
        return $this->hasSelectedServer;
    }

    public function getChangeLogFilePath(): string
    {
        return CHANGELOG_FILE;
    }
}
