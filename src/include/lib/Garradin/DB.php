<?php

namespace Garradin;

use KD2\DB\SQLite3;
use KD2\DB\DB_Exception;
use KD2\ErrorManager;

use Garradin\Entities\Email\Email;

class DB extends SQLite3
{
    /**
     * Application ID pour SQLite
     * @link https://www.sqlite.org/pragma.html#pragma_application_id
     */
    const APPID = 0x5da2d811;

    static protected $_instance = null;

    protected $_version = -1;

    static protected $unicode_patterns_cache = [];

    protected $_log_last = null;
    protected $_log_start = null;
    protected $_log_store = [];

    protected $_schema_update = 0;

    static public function getInstance()
    {
        if (null === self::$_instance) {
            self::$_instance = new DB('sqlite', ['file' => DB_FILE]);
        }

        return self::$_instance;
    }

    static public function deleteInstance()
    {
        self::$_instance = null;
    }

    private function __clone()
    {
        // Désactiver le clonage, car on ne veut qu'une seule instance
    }

    public function __construct(string $driver, array $params)
    {
        if (self::$_instance !== null) {
            throw new \LogicException('Cannot start instance');
        }

        parent::__construct($driver, $params);

        if (DB_OPEN_SQL) {
            $this->exec(DB_OPEN_SQL);
        }

        // Enable SQL debug log if configured
        if (SQL_DEBUG) {
            $this->callback = [$this, 'log'];
            $this->_log_start = microtime(true);
        }
    }

    public function __destruct()
    {
        parent::__destruct();

        if (null !== $this->callback) {
            $this->saveLog();
        }
    }

    /**
     * Disable logging if enabled
     * useful to disable logging when reloading log page
     */
    public function disableLog(): void {
        $this->callback = null;
        $this->_log_store = [];
    }

    /**
     * Saves the log in a different database at the end of the script
     */
    protected function saveLog(): void
    {
        if (!count($this->_log_store)) {
            return;
        }

        $db = new SQLite3('sqlite', ['file' => SQL_DEBUG]);
        $db->exec('CREATE TABLE IF NOT EXISTS sessions (id INTEGER PRIMARY KEY, date TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP, script TEXT, user TEXT);
            CREATE TABLE IF NOT EXISTS log (session INTEGER NOT NULL REFERENCES sessions (id), time INTEGER, duration INTEGER, sql TEXT, trace TEXT);');

        $user = $_SESSION['userSession']->id ?? null;

        $db->insert('sessions', ['script' => str_replace(ROOT, '', $_SERVER['SCRIPT_NAME']), 'user' => $user]);
        $id = $db->lastInsertId();

        $db->begin();

        foreach ($this->_log_store as $row) {
            $db->insert('log', array_merge($row, ['session' => $id]));
        }

        $db->commit();
        $db->close();
    }

    /**
     * Log current SQL query
     */
    protected function log(string $method, ?string $timing, $object, ...$params): void
    {
        if ($method != 'execute' && $method != 'exec') {
            return;
        }

        if ($timing == 'before') {
            $this->_log_last = microtime(true);
            return;
        }

        $now = microtime(true);
        $duration = round(($now - $this->_log_last) * 1000 * 1000);
        $time = round(($now - $this->_log_start) * 1000 * 1000);

        if ($method == 'execute') {
            $sql = $params[0]->getSQL(true);
        }
        else {
            $sql = $params[0];
        }

        $sql = preg_replace('/^\s+/m', '  ', $sql);

        $backtrace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 10);
        $trace = '';

        foreach ($backtrace as $line) {
            if (!isset($line['file']) || in_array(basename($line['file']), ['DB.php', 'SQLite3.php']) || strstr($line['file'], 'lib/KD2')) {
                continue;
            }

            $file = isset($line['file']) ? str_replace(ROOT . '/', '', $line['file']) : '';

            $trace .= sprintf("%s:%d\n", $file, $line['line']);
        }

        $this->_log_store[] = compact('duration', 'time', 'sql', 'trace');
    }

    /**
     * Return a debug log session using its ID
     */
    static public function getDebugSession(int $id): ?\stdClass
    {
        $db = new SQLite3('sqlite', ['file' => SQL_DEBUG]);
        $s = $db->first('SELECT * FROM sessions WHERE id = ?;', $id);

        if ($s) {
            $s->list = $db->get('SELECT * FROM log WHERE session = ? ORDER BY time;', $id);

            foreach ($s->list as &$row) {
                try {
                    $explain = DB::getInstance()->get('EXPLAIN QUERY PLAN ' . $row->sql);
                    $row->explain = '';

                    foreach ($explain as $e) {
                        $row->explain .= $e->detail . "\n";
                    }
                }
                catch (DB_Exception $e) {
                    $row->explain = 'Error: ' . $e->getMessage();
                }
            }
        }

        $db->close();

        return $s;
    }

    /**
     * Return the list of all debug sessions
     */
    static public function getDebugSessionsList(): array
    {
        $db = new SQLite3('sqlite', ['file' => SQL_DEBUG]);
        $s = $db->get('SELECT s.*, SUM(l.duration) AS duration, COUNT(l.rowid) AS count
            FROM sessions s
            INNER JOIN log l ON l.session = s.id
            GROUP BY s.id
            ORDER BY s.date DESC;');

        $db->close();

        return $s;
    }

    public function connect(): void
    {
        if (null !== $this->db) {
            return;
        }

        parent::connect();

        // Activer les contraintes des foreign keys
        $this->db->exec('PRAGMA foreign_keys = ON;');

        // 10 secondes
        $this->db->busyTimeout(10 * 1000);

        // Performance enhancement
        // see https://www.cs.utexas.edu/~jaya/slides/apsys17-sqlite-slides.pdf
        // https://ericdraken.com/sqlite-performance-testing/
        $this->exec(sprintf('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA journal_size_limit = %d;', 32 * 1024 * 1024));

        self::registerCustomFunctions($this->db);
    }

    static public function registerCustomFunctions($db)
    {
        $db->createFunction('dirname', [Utils::class, 'dirname']);
        $db->createFunction('basename', [Utils::class, 'basename']);
        $db->createFunction('unicode_like', [self::class, 'unicodeLike']);
        $db->createFunction('transliterate_to_ascii', [Utils::class, 'unicodeTransliterate']);
        $db->createFunction('email_hash', [Email::class, 'getHash']);
        $db->createCollation('U_NOCASE', [Utils::class, 'unicodeCaseComparison']);
    }

    public function version(): ?string
    {
        if (-1 === $this->_version) {
            $this->connect();
            $this->_version = self::getVersion($this->db);
        }

        return $this->_version;
    }

    static public function getVersion($db)
    {
        $v = (int) $db->querySingle('PRAGMA user_version;');
        $v = self::parseVersion($v);

        if (null === $v) {
            try {
                // For legacy version before 1.1.0
                $v = $db->querySingle('SELECT valeur FROM config WHERE cle = \'version\';');
            }
            catch (\Exception $e) {
                throw new \RuntimeException('Cannot find application version', 0, $e);
            }
        }

        return $v ?: null;
    }

    static public function parseVersion(int $v): ?string
    {
        if ($v > 0) {
            $major = intval($v / 1000000);
            $v -= $major * 1000000;
            $minor = intval($v / 10000);
            $v -= $minor * 10000;
            $release = intval($v / 100);
            $v -= $release * 100;
            $type = $v;

            if ($type == 0) {
                $type = '';
            }
            // Corrective release: 1.2.3.1
            elseif ($type > 75) {
                $type = '.' . ($type - 75);
            }
            // RC release
            elseif ($type > 50) {
                $type = '-rc' . ($type - 50);
            }
            // Beta
            elseif ($type > 25) {
                $type = '-beta' . ($type - 25);
            }
            // Alpha
            else {
                $type = '-alpha' . $type;
            }

            $v = sprintf('%d.%d.%d%s', $major, $minor, $release, $type);
        }

        return $v ?: null;
    }

    /**
     * Save version to database
     * rc, alpha, beta and corrective release (4th number) are limited to 24 versions each
     * @param string $version Version string, eg. 1.2.3-rc2
     */
    public function setVersion(string $version): void
    {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)(?:(?:-(alpha|beta|rc)|\.)(\d+)|)?$/', $version, $match)) {
            throw new \InvalidArgumentException('Invalid version number: ' . $version);
        }

        $version = ($match[1] * 100 * 100 * 100) + ($match[2] * 100 * 100) + ($match[3] * 100);

        if (isset($match[5])) {
            if ($match[5] > 24) {
                throw new \InvalidArgumentException('Invalid version number: cannot have a 4th component larger than 24: ' . $version);
            }

            if ($match[4] == 'rc') {
                $version += $match[5] + 50;
            }
            elseif ($match[4] == 'beta') {
                $version += $match[5] + 25;
            }
            elseif ($match[4] == 'alpha') {
                $version += $match[5];
            }
            else {
                $version += $match[5] + 75;
            }
        }

        $this->db->exec(sprintf('PRAGMA user_version = %d;', $version));
    }

    public function beginSchemaUpdate()
    {
        // Only start if not already taking place
        if ($this->_schema_update++ == 0) {
            $this->toggleForeignKeys(false);
            $this->begin();
        }
    }

    public function commitSchemaUpdate()
    {
        // Only commit if last call
        if (--$this->_schema_update == 0) {
            $this->commit();
            $this->toggleForeignKeys(true);
        }
    }

    public function lastErrorMsg()
    {
        return $this->db->lastErrorMsg();
    }

    /**
     * @see https://www.sqlite.org/lang_altertable.html
     */
    public function toggleForeignKeys(bool $enable): void
    {
        $this->connect();

        if (!$enable) {
            $this->db->exec('PRAGMA legacy_alter_table = ON;');
            $this->db->exec('PRAGMA foreign_keys = OFF;');

            if ($this->firstColumn('PRAGMA foreign_keys;')) {
                throw new \LogicException('Cannot disable foreign keys in an already started transaction');
            }
        }
        else {
            $this->db->exec('PRAGMA legacy_alter_table = OFF;');
            $this->db->exec('PRAGMA foreign_keys = ON;');
        }
    }

    /**
     * This is a rewrite of SQLite LIKE function that is transforming
     * the pattern and the value to lowercase ascii, so that we can match
     * "émilie" with "emilie".
     *
     * This is probably not the best way to do that, but we have to resort to that
     * as ICU extension is rarely available.
     *
     * @see https://www.sqlite.org/c3ref/strlike.html
     * @see https://sqlite.org/src/file?name=ext/icu/icu.c&ci=trunk
     */
    static public function unicodeLike($pattern, $value, $escape = null) {
        if (null === $pattern || null === $value) {
            return false;
        }

        $pattern = str_replace('’', '\'', $pattern); // Normalize French apostrophe
        $value = str_replace('’', '\'', $value);

        $id = md5($pattern . $escape);

        if (!array_key_exists($id, self::$unicode_patterns_cache)) {
            $escape = $escape ? '(?!' . preg_quote($escape, '/') . ')' : '';
            preg_match_all('/('.$escape.'[%_])|(\pL+)|(.+?)/iu', $pattern, $parts, PREG_SET_ORDER);
            $pattern = '';

            foreach ($parts as $part) {
                if (isset($part[3])) {
                    $pattern .= preg_quote(strtolower($part[0]), '/');
                }
                elseif (isset($part[2])) {
                    $pattern .= preg_quote(Utils::unicodeCaseFold($part[2]), '/');
                }
                elseif ($part[1] == '%') {
                    $pattern .= '.*';
                }
                elseif ($part[1] == '_') {
                    $pattern .= '.';
                }
            }

            $pattern = '/^' . $pattern . '$/im';
            self::$unicode_patterns_cache[$id] = $pattern;
        }

        $value = Utils::unicodeCaseFold($value);

        return (bool) preg_match(self::$unicode_patterns_cache[$id], $value);
    }
}
