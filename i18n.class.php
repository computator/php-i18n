<?php

/*
 * Fork this project on GitHub!
 * https://github.com/Philipp15b/php-i18n
 *
 * License: MIT
 */

class i18n {

    /**
     * Language file paths
     * This is the paths for the language files. You must use the '{LANGUAGE}' placeholder for the language or the script wont find any language files.
     *
     * @var array
     */
    protected $filePaths = array('./lang/lang_{LANGUAGE}.ini');

    /**
     * Cache file path
     * This is the path for all the cache files. Best is an empty directory with no other files in it.
     *
     * @var string
     */
    protected $cachePath = './langcache/';

    /**
     * Fallback language
     * This is the language which is used when there is no language file for all other user languages. It has the lowest priority.
     * Remember to create a language file for the fallback!!
     *
     * @var string
     */
    protected $fallbackLang = 'en';

    /**
     * Merge in fallback language
     * Whether to merge current language's strings with the strings of the fallback language ($fallbackLang).
     *
     * @var bool
     */
    protected $mergeFallback = false;

    /**
     * The class name of the compiled class that contains the translated texts.
     * @var string
     */
    protected $prefix = 'L';

    /**
     * Forced language
     * If you want to force a specific language define it here.
     *
     * @var string
     */
    protected $forcedLang = NULL;

    /**
     * This is the separator used if you use sections in your ini-file.
     * For example, if you have a string 'greeting' in a section 'welcomepage' you will can access it via 'L::welcomepage_greeting'.
     * If you changed it to 'ABC' you could access your string via 'L::welcomepageABCgreeting'
     *
     * @var string
     */
    protected $sectionSeparator = '_';

    /**
     * Static string replacements
     * This is an array of placeholders and their replacement values to be statically replaced. For example if you have
     * a string 'My {TYPE} string', and staticMap contains 'TYPE' => 'Favorite', then the resulting string will be 'My Favorite string'.
     *
     * @var array
     */
    protected $staticMap = array();


    /*
     * The following properties are only available after calling init().
     */

    /**
     * User languages
     * These are the languages the user uses.
     * Normally, if you use the getUserLangs-method this array will be filled in like this:
     * 1. Forced language
     * 2. Language in $_GET['lang']
     * 3. Language in $_SESSION['lang']
     * 4. Fallback language
     *
     * @var array
     */
    protected $userLangs = array();

    protected $appliedLang = NULL;
    protected $activeConfigs = NULL;
    protected $isInitialized = false;


    /**
     * Constructor
     * The constructor sets all important settings. All params are optional, you can set the options via extra functions too.
     *
     * @param mixed [$filePaths] This is the path (deprecated) or array of paths (preferred) for the language files. You must use the '{LANGUAGE}' placeholder for the language.
     * @param string [$cachePath] This is the path for all the cache files. Best is an empty directory with no other files in it. No placeholders.
     * @param string [$fallbackLang] This is the language which is used when there is no language file for all other user languages. It has the lowest priority.
     * @param string [$prefix] The class name of the compiled class that contains the translated texts. Defaults to 'L'.
     */
    public function __construct($filePaths = NULL, $cachePath = NULL, $fallbackLang = NULL, $prefix = NULL) {
        // Apply settings
        if ($filePaths != NULL) {
            $this->filePaths = is_array($filePaths) ? $filePaths : array($filePaths);
        }

        if ($cachePath != NULL) {
            $this->cachePath = $cachePath;
        }

        if ($fallbackLang != NULL) {
            $this->fallbackLang = $fallbackLang;
        }

        if ($prefix != NULL) {
            $this->prefix = $prefix;
        }
    }

    public function init() {
        if ($this->isInitialized()) {
            throw new BadMethodCallException('This object from class ' . __CLASS__ . ' is already initialized. It is not possible to init one object twice!');
        }

        $this->isInitialized = true;

        $this->userLangs = $this->getUserLangs();
        $this->appliedLang = $this->calcAppliedLang();

        $this->staticMap = $this->initStaticMap();
        $this->activeConfigs = $this->getActiveConfigs();

        $state_hctx = hash_init('md5');
        if ($this->staticMap) {
            $sorted = $this->staticMap;
            ksort($sorted);
            foreach ($sorted as $placeholder => $repl) {
                hash_update($state_hctx, $placeholder . $repl);
            }
            unset($sorted);
        }
        foreach ($this->activeConfigs as $langPath) {
            hash_update($state_hctx, $langPath);
        }
        $state_hash = hash_final($state_hctx);
        unset($state_hctx);

        // search for cache file
        $cacheFilePath = $this->cachePath . '/php_i18n_' . md5_file(__FILE__) . '_' . $state_hash . '_' . $this->prefix . '_' . $this->appliedLang . '.cache.php';

        // check whether we need to create a new cache file
        $outdated = false;
        if (!file_exists($cacheFilePath))
            $outdated = true;
        else {
            foreach ($this->activeConfigs as $langPath) {
                // check if the language config was updated since the cache file was created
                if (filemtime($cacheFilePath) < filemtime($langPath)) {
                    $outdated = true;
                    break;
                }
            }
        }

        if ($outdated) {
            $config = array();
            foreach ($this->activeConfigs as $langPath)
                $config = array_replace_recursive($this->load($langPath), $config);

            $compiled = "<?php class " . $this->prefix . " {\n"
            	. $this->compile($config)
            	. 'public static function __callStatic($string, $args) {' . "\n"
            	. '    return vsprintf(constant("self::" . $string), $args);'
            	. "\n}\n}\n"
            	. "function ".$this->prefix .'($string, $args=NULL) {'."\n"
            	. '    $return = constant("'.$this->prefix.'::".$string);'."\n"
            	. '    return $args !== NULL ? vsprintf($return,$args) : $return;'
            	. "\n}";

			if( ! is_dir($this->cachePath))
				mkdir($this->cachePath, 0755, true);

            if (file_put_contents($cacheFilePath, $compiled) === FALSE) {
                throw new Exception("Could not write cache file to path '" . $cacheFilePath . "'. Is it writable?");
            }
            chmod($cacheFilePath, 0755);

        }

        require_once $cacheFilePath;
    }

    public function isInitialized() {
        return $this->isInitialized;
    }

    public function getAppliedLang() {
        return $this->appliedLang;
    }

    public function getCachePath() {
        return $this->cachePath;
    }

    public function getFallbackLang() {
        return $this->fallbackLang;
    }

    public function setFilePaths($filePaths) {
        $this->fail_after_init();
        $this->filePaths = $filePaths;
    }

    public function setCachePath($cachePath) {
        $this->fail_after_init();
        $this->cachePath = $cachePath;
    }

    public function setFallbackLang($fallbackLang) {
        $this->fail_after_init();
        $this->fallbackLang = $fallbackLang;
    }

    public function setMergeFallback($mergeFallback) {
        $this->fail_after_init();
        $this->mergeFallback = $mergeFallback;
    }

    public function setPrefix($prefix) {
        $this->fail_after_init();
        $this->prefix = $prefix;
    }

    public function setForcedLang($forcedLang) {
        $this->fail_after_init();
        $this->forcedLang = $forcedLang;
    }

    public function setSectionSeparator($sectionSeparator) {
        $this->fail_after_init();
        $this->sectionSeparator = $sectionSeparator;
    }

    public function setStaticMap($map) {
        $this->fail_after_init();
        $this->staticMap = $map;
    }

    /**
     * @deprecated Use setFilePaths.
     */
    public function setFilePath($filePath) {
        $this->setFilePaths(array($filePath));
    }

    /**
     * @deprecated Use setSectionSeparator.
     */
    public function setSectionSeperator($sectionSeparator) {
        $this->setSectionSeparator($sectionSeparator);
    }

    /**
     * getUserLangs()
     * Returns the user languages
     * Normally it returns an array like this:
     * 1. Forced language
     * 2. Language in $_GET['lang']
     * 3. Language in $_SESSION['lang']
     * 4. HTTP_ACCEPT_LANGUAGE
     * 5. Fallback language
     * Note: duplicate values are deleted.
     *
     * @return array with the user languages sorted by priority.
     */
    public function getUserLangs() {
        $userLangs = array();

        // Highest priority: forced language
        if ($this->forcedLang != NULL) {
            $userLangs[] = $this->forcedLang;
        }

        // 2nd highest priority: GET parameter 'lang'
        if (isset($_GET['lang']) && is_string($_GET['lang'])) {
            $userLangs[] = $_GET['lang'];
        }

        // 3rd highest priority: SESSION parameter 'lang'
        if (isset($_SESSION['lang']) && is_string($_SESSION['lang'])) {
            $userLangs[] = $_SESSION['lang'];
        }

        // 4th highest priority: HTTP_ACCEPT_LANGUAGE
        if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            foreach (explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']) as $part) {
                $userLangs[] = strtolower(substr($part, 0, 2));
            }
        }

        // Lowest priority: fallback
        $userLangs[] = $this->fallbackLang;

        // remove duplicate elements
        $userLangs = array_unique($userLangs);

        // remove illegal userLangs
        // only allow a-z, A-Z and 0-9 and _ and -
        $userLangs = preg_grep('/^[a-zA-Z0-9_-]*$/', $userLangs);

        return $userLangs;
    }

    protected function calcAppliedLang() {
        // search for language files
        // check order: $paths[0][LC] > $paths[1][LC] > ...$paths[N][LC]
        $this->appliedLang = NULL;
        foreach ($this->userLangs as $langcode) {
            foreach ($this->getConfigFilenames($langcode) as $langPath) {
                if (file_exists($langPath)) {
                    return $langcode;
                }
            }
        }
        throw new RuntimeException('No language file was found.');
    }

    protected function getConfigFilenames($langcode, $fallback = NULL) {
        /* merge order:
         *   no fallback: $paths[0][LC] > $paths[1][LC] > ...$paths[N][LC]
         *   fallback: $paths[0][LC] > $paths[0][FB] > $paths[1][LC] > $paths[1][FB] > ...$paths[N][LC] > $paths[N][FB]
         */
        $lc_files = str_replace('{LANGUAGE}', $langcode, $this->filePaths);
        if ($fallback == NULL)
            return $lc_files;
        $fb_files = str_replace('{LANGUAGE}', $fallback, $this->filePaths);
        $out_files = array();

        // interleave arrays
        for ($x = reset($lc_files), $y = reset($fb_files); $x && $y; $x = next($lc_files), $y = next($fb_files)) {
            $out_files[] = $x;
            $out_files[] = $y;
        }
        return $out_files;
    }

    protected function getActiveConfigs() {
        if ($this->mergeFallback)
            $fnames = $this->getConfigFilenames($this->appliedLang, $this->fallbackLang);
        else
            $fnames = $this->getConfigFilenames($this->appliedLang);
        return array_filter($fnames, 'file_exists');
    }

    protected function initStaticMap() {
        $newmap = array();
        if ($this->staticMap) {
            foreach ($this->staticMap as $placeholder => $repl) {
                $newmap['{' . $placeholder . '}'] = $repl;
            }
        }
        return $newmap;
    }

    protected function load($filename) {
        $ext = substr(strrchr($filename, '.'), 1);
        switch ($ext) {
            case 'properties':
            case 'ini':
                $config = parse_ini_file($filename, true);
                break;
            case 'yml':
            case 'yaml':
                if (function_exists('yaml_parse_file'))
                    $config = yaml_parse_file($filename);
                elseif (function_exists('spyc_load_file'))
                    $config = spyc_load_file($filename);
                else
                    throw new Exception('No suitable YAML parsing methods available! Please install the PHP YAML extension or the spyc library.');
                break;
            case 'json':
                $config = json_decode(file_get_contents($filename), true);
                break;
            default:
                throw new InvalidArgumentException($ext . " is not a valid extension!");
        }
        return $config;
    }

    /**
     * Recursively compile an associative array to PHP code.
     */
    protected function compile($config, $prefix = '') {
        $code = '';
        foreach ($config as $key => $value) {
            if (is_array($value)) {
                $code .= $this->compile($value, $prefix . $key . $this->sectionSeparator);
            } else {
                $fullName = $prefix . $key;
                if (!preg_match('/^[a-zA-Z_\x7f-\xff][a-zA-Z0-9_\x7f-\xff]*$/', $fullName)) {
                    throw new InvalidArgumentException(__CLASS__ . ": Cannot compile translation key " . $fullName . " because it is not a valid PHP identifier.");
                }
                $value = str_replace(array_keys($this->staticMap), $this->staticMap, $value);
                $code .= 'const ' . $fullName . ' = \'' . addslashes($value) . "';\n";
            }
        }
        return $code;
    }

    protected function fail_after_init() {
        if ($this->isInitialized()) {
            throw new BadMethodCallException('This ' . __CLASS__ . ' object is already initalized, so you can not change any settings.');
        }
    }
}
