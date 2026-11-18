<?php
/**
 * Database account scope row without WordPress (plugin 0.5.3): a fake $wpdb
 * answers CURRENT_USER(). The row is exactly id, status and one of three
 * words; the account name and the host never appear in it, and every
 * failure sends no row. Run:
 *
 *   php -d zend.assertions=1 -d assert.exception=1 tests/db_account_scope.php
 */

declare(strict_types=1);

define('ABSPATH', sys_get_temp_dir().'/');

final class ScopeWpdb
{
    /** @var mixed */
    public $answer;

    public bool $throws = false;

    public bool $is_mysql = true;

    public string $dbhost = 'localhost';

    public bool $suppressed = false;

    /** @var array<int, string> */
    public array $log = [];

    /** @param mixed $answer */
    public function __construct($answer)
    {
        $this->answer = $answer;
    }

    public function suppress_errors(bool $suppress = true): bool
    {
        $previous = $this->suppressed;
        $this->suppressed = $suppress;

        return $previous;
    }

    public function get_var(string $query)
    {
        $this->log[] = $query;
        assert($this->suppressed, 'database errors are suppressed while the query runs');

        if ($this->throws) {
            throw new RuntimeException('connection lost');
        }

        return $this->answer;
    }
}

require dirname(__DIR__).'/includes/autoload.php';

use Sitetus\WP\WordPressChecks;

function scope($answer): ?array
{
    $GLOBALS['wpdb'] = new ScopeWpdb($answer);
    $row = WordPressChecks::databaseAccountScope();

    assert($GLOBALS['wpdb']->log === ['SELECT CURRENT_USER()'], 'one fixed read-only query: '.json_encode($GLOBALS['wpdb']->log));
    assert($GLOBALS['wpdb']->suppressed === false, 'the error-suppression state is restored');

    return $row;
}

// --- The three words -------------------------------------------------------
$cases = [
    'wp_secretuser@localhost' => 'local',
    'wp_secretuser@LOCALHOST' => 'local',
    'wp_secretuser@127.0.0.1' => 'local',
    'wp_secretuser@::1' => 'local',
    'wp_secretuser@%' => 'any',
    // A pattern that narrows the wildcard is not "any host".
    'wp_secretuser@10.0.%' => 'specific',
    'wp_secretuser@%.hosting.example' => 'specific',
    'wp_secretuser@web_.hosting.example' => 'specific',
    'wp_secretuser@10.20.30.40' => 'specific',
    'wp_secretuser@web01.hosting.example' => 'specific',
    'odd@name@localhost' => 'local',
    'odd@localhost@db.hosting.example' => 'specific',
];

foreach ($cases as $account => $word) {
    $row = scope($account);
    assert($row === ['id' => 'db_account_scope', 'status' => 'ok', 'value' => $word], "{$account} → {$word}: ".json_encode($row));

    // The proof that nothing identifying is sent: the whole row, as it goes on the wire.
    $wire = (string) json_encode($row);
    list($user, $host) = [substr($account, 0, (int) strrpos($account, '@')), substr($account, (int) strrpos($account, '@') + 1)];
    assert(stripos($wire, $user) === false, "the account name is not in the row for {$account}");
    assert(in_array(strtolower($host), ['localhost', '%'], true) || stripos($wire, $host) === false, "the host is not in the row for {$account}");
    assert(strpos($wire, '@') === false && strpos($wire, 'wp_secretuser') === false && strpos($wire, 'hosting.example') === false, "no part of {$account} is in the row");
    assert(array_keys($row) === ['id', 'status', 'value'], 'the row has no detail and no message');
}

// --- No row at all ---------------------------------------------------------
foreach ([null, false, '', 'no-host-part', 'trailing@', 42, ['wp_secretuser@localhost']] as $answer) {
    assert(scope($answer) === null, 'no usable answer → no row: '.json_encode($answer));
}

$GLOBALS['wpdb'] = new ScopeWpdb('wp_secretuser@localhost');
$GLOBALS['wpdb']->throws = true;
assert(WordPressChecks::databaseAccountScope() === null, 'a failing query → no row');
assert($GLOBALS['wpdb']->suppressed === false, 'the error-suppression state is restored after a failure');

$GLOBALS['wpdb'] = new ScopeWpdb('wp_secretuser@localhost');
$GLOBALS['wpdb']->is_mysql = false;
assert(WordPressChecks::databaseAccountScope() === null && $GLOBALS['wpdb']->log === [], 'another database engine → no query, no row');

// The row is read with this server's own MySQL port: a database on another server sends none.
foreach (['db7.hosting.example', '10.20.30.40', '10.20.30.40:3306', ''] as $remote) {
    $GLOBALS['wpdb'] = new ScopeWpdb('wp_secretuser@%');
    $GLOBALS['wpdb']->dbhost = $remote;
    assert(WordPressChecks::databaseAccountScope() === null && $GLOBALS['wpdb']->log === [], "a database on another server → no query, no row: {$remote}");
}

foreach (['localhost:3306', '127.0.0.1', 'localhost:/var/run/mysqld/mysqld.sock', '[::1]:3306'] as $local) {
    $GLOBALS['wpdb'] = new ScopeWpdb('wp_secretuser@localhost');
    $GLOBALS['wpdb']->dbhost = $local;
    assert((WordPressChecks::databaseAccountScope()['value'] ?? null) === 'local', "a database on this server is read: {$local}");
}

$GLOBALS['wpdb'] = (object) ['prefix' => 'wp_'];
assert(WordPressChecks::databaseAccountScope() === null, 'a $wpdb without get_var → no row');

$GLOBALS['wpdb'] = null;
assert(WordPressChecks::databaseAccountScope() === null, 'no $wpdb → no row');

fwrite(STDERR, "db_account_scope ok\n");
