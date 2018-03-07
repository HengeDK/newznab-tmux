<?php

namespace Blacklight;

use App\Models\Predb;
use Blacklight\db\DB;

/**
 * Class IRCScraper.
 */
class IRCScraper extends IRCClient
{
    /**
     * Regex to ignore categories.
     * @var string|bool
     */
    protected $_categoryIgnoreRegex;

    /**
     * Array of current pre info.
     * @var array
     */
    protected $_curPre;

    /**
     * List of groups and their id's.
     * @var array
     */
    protected $_groupList;

    /**
     * Array of ignored channels.
     * @var array
     */
    protected $_ignoredChannels;

    /**
     * Is this pre nuked or un nuked?
     * @var bool
     */
    protected $_nuked;

    /**
     * Array of old pre info.
     * @var array|bool
     */
    protected $_oldPre;

    /**
     * @var \Blacklight\db\DB
     */
    protected $_pdo;

    /**
     * Run this in silent mode (no text output).
     * @var bool
     */
    protected $_silent;

    /**
     * Regex to ignore PRE titles.
     * @var string|bool
     */
    protected $_titleIgnoreRegex;

    /**
     * Construct.
     *
     * @param bool $silent Run this in silent mode (no text output).
     * @param bool $debug Turn on debug? Shows sent/received socket buffer messages.
     * @throws \Exception
     */
    public function __construct(&$silent, &$debug)
    {
        if (config('irc_settings.scrape_irc_source_ignore')) {
            $this->_ignoredChannels = unserialize(
                config('irc_settings.scrape_irc_source_ignore'),
                ['allowed_classes' => ['#a.b.cd.image',
                                                                                  '#a.b.console.ps3',
                                                                                  '#a.b.dvd',
                                                                                  '#a.b.erotica',
                                                                                  '#a.b.flac',
                                                                                  '#a.b.foreign',
                                                                                  '#a.b.games.nintendods',
                                                                                  '#a.b.inner-sanctum',
                                                                                  '#a.b.moovee',
                                                                                  '#a.b.movies.divx',
                                                                                  '#a.b.sony.psp',
                                                                                  '#a.b.sounds.mp3.complete_cd',
                                                                                  '#a.b.teevee',
                                                                                  '#a.b.games.wii',
                                                                                  '#a.b.warez',
                                                                                  '#a.b.games.xbox360',
                                                                                  '#pre@corrupt',
                                                                                  '#scnzb',
                                                                                  '#tvnzb',
                                                                                  'srrdb',
                                                                                 ],
            ]
            );
        } else {
            $this->_ignoredChannels = [
                '#a.b.cd.image'               => false,
                '#a.b.console.ps3'            => false,
                '#a.b.dvd'                    => false,
                '#a.b.erotica'                => false,
                '#a.b.flac'                   => false,
                '#a.b.foreign'                => false,
                '#a.b.games.nintendods'       => false,
                '#a.b.inner-sanctum'          => false,
                '#a.b.moovee'                 => false,
                '#a.b.movies.divx'            => false,
                '#a.b.sony.psp'               => false,
                '#a.b.sounds.mp3.complete_cd' => false,
                '#a.b.teevee'                 => false,
                '#a.b.games.wii'              => false,
                '#a.b.warez'                  => false,
                '#a.b.games.xbox360'          => false,
                '#pre@corrupt'                => false,
                '#scnzb'                      => false,
                '#tvnzb'                      => false,
                'srrdb'                       => false,
            ];
        }

        $this->_categoryIgnoreRegex = false;
        if (config('irc_settings.scrape_irc_category_ignore') !== '') {
            $this->_categoryIgnoreRegex = config('irc_settings.scrape_irc_category_ignore');
        }

        $this->_titleIgnoreRegex = false;
        if (config('irc_settings.scrape_irc_title_ignore') !== '') {
            $this->_titleIgnoreRegex = SCRAPE_IRC_TITLE_IGNORE;
        }

        $this->_pdo = new DB();
        $this->_groupList = [];
        $this->_silent = $silent;
        $this->_debug = $debug;
        $this->_resetPreVariables();
        $this->_startScraping();
    }

    public function __destruct()
    {
        parent::__destruct();
    }

    /**
     * Main method for scraping.
     */
    protected function _startScraping()
    {

        // Connect to IRC.
        if ($this->connect(config('irc_settings.scrape_irc_server'), config('irc_settings.scrape_irc_port'), config('irc_settings.scrape_irc_tls')) === false) {
            exit(
                'Error connecting to ('.
                config('irc_settings.scrape_irc_server').
                ':'.
                config('irc_settings.scrape_irc_port').
                '). Please verify your server information and try again.'.
                PHP_EOL
            );
        }

        // Login to IRC.
        if ($this->login(config('irc_settings.scrape_irc_nickname'), config('irc_settings.scrape_irc_realname'), config('irc_settings.scrape_irc_username'), config('irc_settings.scrape_irc_password')) === false) {
            exit('Error logging in to: ('.
                config('irc_settings.scrape_irc_server').':'.config('irc_settings.scrape_irc_port').') nickname: ('.config('irc_settings.scrape_irc_nickname').
                '). Verify your connection information, you might also be banned from this server or there might have been a connection issue.'.
                PHP_EOL
            );
        }

        // Join channels.
        $channels = config('irc_settings.scrape_irc_channels') ? unserialize(config('irc_settings.scrape_irc_channels'), ['allowed_classes' => ['#PreNNTmux', '#nZEDbPRE']]) : ['#PreNNTmux' => null];
        $this->joinChannels($channels);

        if (! $this->_silent) {
            echo
                '['.
                date('r').
                '] [Scraping of IRC channels for ('.
                config('irc_settings.scrape_irc_server').
                ':'.
                config('irc_settings.scrape_irc_port').
                ') ('.
                config('irc_settings.scrape_irc_nickname').
                ') started.]'.
                PHP_EOL;
        }

        // Scan incoming IRC messages.
        $this->readIncoming();
    }

    /**
     * Process bot messages, insert/update PREs.
     */
    protected function processChannelMessages(): void
    {
        if (preg_match(
            '/^(NEW|UPD|NUK): \[DT: (?P<time>.+?)\]\s?\[TT: (?P<title>.+?)\]\s?\[SC: (?P<source>.+?)\]\s?\[CT: (?P<category>.+?)\]\s?\[RQ: (?P<req>.+?)\]'.
            '\s?\[SZ: (?P<size>.+?)\]\s?\[FL: (?P<files>.+?)\]\s?(\[FN: (?P<filename>.+?)\]\s?)?(\[(?P<nuked>(UN|MOD|RE|OLD)?NUKED?): (?P<reason>.+?)\])?$/i',
            $this->_channelData['message'],
            $matches
        )) {
            if (isset($this->_ignoredChannels[$matches['source']]) && $this->_ignoredChannels[$matches['source']] === true) {
                return;
            }

            if ($this->_categoryIgnoreRegex !== false && preg_match((string) $this->_categoryIgnoreRegex, $matches['category'])) {
                return;
            }

            if ($this->_titleIgnoreRegex !== false && preg_match((string) $this->_titleIgnoreRegex, $matches['title'])) {
                return;
            }

            $this->_curPre['predate'] = $this->_pdo->from_unixtime(strtotime($matches['time'].' UTC'));
            $this->_curPre['title'] = $matches['title'];
            $this->_curPre['source'] = $matches['source'];
            if ($matches['category'] !== 'N/A') {
                $this->_curPre['category'] = $matches['category'];
            }
            if ($matches['req'] !== 'N/A' && preg_match('/^(?P<req>\d+):(?P<group>.+)$/i', $matches['req'], $matches2)) {
                $this->_curPre['reqid'] = $matches2['req'];
                $this->_curPre['group_id'] = $this->_getGroupID($matches2['group']);
            }
            if ($matches['size'] !== 'N/A') {
                $this->_curPre['size'] = $matches['size'];
            }
            if ($matches['files'] !== 'N/A') {
                $this->_curPre['files'] = substr($matches['files'], 0, 50);
            }

            if (isset($matches['filename']) && $matches['filename'] !== 'N/A') {
                $this->_curPre['filename'] = $matches['filename'];
            }

            if (isset($matches['nuked'])) {
                switch ($matches['nuked']) {
                    case 'NUKED':
                        $this->_curPre['nuked'] = Predb::PRE_NUKED;
                        break;
                    case 'UNNUKED':
                        $this->_curPre['nuked'] = Predb::PRE_UNNUKED;
                        break;
                    case 'MODNUKED':
                        $this->_curPre['nuked'] = Predb::PRE_MODNUKE;
                        break;
                    case 'RENUKED':
                        $this->_curPre['nuked'] = Predb::PRE_RENUKED;
                        break;
                    case 'OLDNUKE':
                        $this->_curPre['nuked'] = Predb::PRE_OLDNUKE;
                        break;
                }
                $this->_curPre['reason'] = (isset($matches['reason']) ? substr($matches['reason'], 0, 255) : '');
            }
            $this->_checkForDupe();
        }
    }

    /**
     * Check if we already have the PRE, update if we have it, insert if not.
     */
    protected function _checkForDupe()
    {
        $this->_oldPre = $this->_pdo->queryOneRow(sprintf('SELECT category, size FROM predb WHERE title = %s', $this->_pdo->escapeString($this->_curPre['title'])));
        if ($this->_oldPre === false) {
            $this->_insertNewPre();
        } else {
            $this->_updatePre();
        }
        $this->_resetPreVariables();
    }

    /**
     * Insert new PRE into the DB.
     *
     * @throws \RuntimeException
     */
    protected function _insertNewPre()
    {
        if (empty($this->_curPre['title'])) {
            return;
        }

        $query = 'INSERT INTO predb (';

        $query .= (! empty($this->_curPre['size']) ? 'size, ' : '');
        $query .= (! empty($this->_curPre['category']) ? 'category, ' : '');
        $query .= (! empty($this->_curPre['source']) ? 'source, ' : '');
        $query .= (! empty($this->_curPre['reason']) ? 'nukereason, ' : '');
        $query .= (! empty($this->_curPre['files']) ? 'files, ' : '');
        $query .= (! empty($this->_curPre['reqid']) ? 'requestid, ' : '');
        $query .= (! empty($this->_curPre['group_id']) ? 'groups_id, ' : '');
        $query .= (! empty($this->_curPre['nuked']) ? 'nuked, ' : '');
        $query .= (! empty($this->_curPre['filename']) ? 'filename, ' : '');

        $query .= 'predate, title) VALUES (';

        $query .= (! empty($this->_curPre['size']) ? $this->_pdo->escapeString($this->_curPre['size']).', ' : '');
        $query .= (! empty($this->_curPre['category']) ? $this->_pdo->escapeString($this->_curPre['category']).', ' : '');
        $query .= (! empty($this->_curPre['source']) ? $this->_pdo->escapeString($this->_curPre['source']).', ' : '');
        $query .= (! empty($this->_curPre['reason']) ? $this->_pdo->escapeString($this->_curPre['reason']).', ' : '');
        $query .= (! empty($this->_curPre['files']) ? $this->_pdo->escapeString($this->_curPre['files']).', ' : '');
        $query .= (! empty($this->_curPre['reqid']) ? $this->_curPre['reqid'].', ' : '');
        $query .= (! empty($this->_curPre['group_id']) ? $this->_curPre['group_id'].', ' : '');
        $query .= (! empty($this->_curPre['nuked']) ? $this->_curPre['nuked'].', ' : '');
        $query .= (! empty($this->_curPre['filename']) ? $this->_pdo->escapeString($this->_curPre['filename']).', ' : '');
        $query .= (! empty($this->_curPre['predate']) ? $this->_curPre['predate'].', ' : 'NOW(), ');

        $query .= '%s)';

        $this->_pdo->ping(true);

        $this->_pdo->queryExec(
            sprintf(
                $query,
                $this->_pdo->escapeString($this->_curPre['title'])
            )
        );

        $this->_doEcho(true);
    }

    /**
     * Updates PRE data in the DB.
     *
     * @throws \RuntimeException
     */
    protected function _updatePre()
    {
        if (empty($this->_curPre['title'])) {
            return;
        }

        $query = 'UPDATE predb SET ';

        $query .= (! empty($this->_curPre['size']) ? 'size = '.$this->_pdo->escapeString($this->_curPre['size']).', ' : '');
        $query .= (! empty($this->_curPre['source']) ? 'source = '.$this->_pdo->escapeString($this->_curPre['source']).', ' : '');
        $query .= (! empty($this->_curPre['files']) ? 'files = '.$this->_pdo->escapeString($this->_curPre['files']).', ' : '');
        $query .= (! empty($this->_curPre['reason']) ? 'nukereason = '.$this->_pdo->escapeString($this->_curPre['reason']).', ' : '');
        $query .= (! empty($this->_curPre['reqid']) ? 'requestid = '.$this->_curPre['reqid'].', ' : '');
        $query .= (! empty($this->_curPre['group_id']) ? 'groups_id = '.$this->_curPre['group_id'].', ' : '');
        $query .= (! empty($this->_curPre['predate']) ? 'predate = '.$this->_curPre['predate'].', ' : '');
        $query .= (! empty($this->_curPre['nuked']) ? 'nuked = '.$this->_curPre['nuked'].', ' : '');
        $query .= (! empty($this->_curPre['filename']) ? 'filename = '.$this->_pdo->escapeString($this->_curPre['filename']).', ' : '');
        $query .= (
        (empty($this->_oldPre['category']) && ! empty($this->_curPre['category']))
            ? 'category = '.$this->_pdo->escapeString($this->_curPre['category']).', '
            : ''
        );

        if ($query === 'UPDATE predb SET ') {
            return;
        }

        $query .= 'title = '.$this->_pdo->escapeString($this->_curPre['title']);
        $query .= ' WHERE title = '.$this->_pdo->escapeString($this->_curPre['title']);

        $this->_pdo->ping(true);

        $this->_pdo->queryExec($query);

        $this->_doEcho(false);
    }

    /**
     * Echo new or update pre to CLI.
     *
     * @param bool $new
     */
    protected function _doEcho($new = true)
    {
        if (! $this->_silent) {
            $nukeString = '';
            if ($this->_nuked !== false) {
                switch ((int) $this->_curPre['nuked']) {
                    case Predb::PRE_NUKED:
                        $nukeString = '[ NUKED ] ';
                        break;
                    case Predb::PRE_UNNUKED:
                        $nukeString = '[UNNUKED] ';
                        break;
                    case Predb::PRE_MODNUKE:
                        $nukeString = '[MODNUKE] ';
                        break;
                    case Predb::PRE_OLDNUKE:
                        $nukeString = '[OLDNUKE] ';
                        break;
                    case Predb::PRE_RENUKED:
                        $nukeString = '[RENUKED] ';
                        break;
                    default:
                        break;
                }
                $nukeString .= '['.$this->_curPre['reason'].'] ';
            }

            echo
                '['.
                date('r').
                ($new ? '] [ Added Pre ] [' : '] [Updated Pre] [').
                $this->_curPre['source'].
                '] '.
                $nukeString.
                '['.
                $this->_curPre['title'].
                ']'.
                (
                    ! empty($this->_curPre['category'])
                    ? ' ['.$this->_curPre['category'].']'
                    : (
                        ! empty($this->_oldPre['category'])
                        ? ' ['.$this->_oldPre['category'].']'
                        : ''
                    )
                ).
                (! empty($this->_curPre['size']) ? ' ['.$this->_curPre['size'].']' : '').
                PHP_EOL;
        }
    }

    /**
     * Get a group id for a group name.
     *
     * @param string $groupName
     *
     * @return mixed
     */
    protected function _getGroupID($groupName)
    {
        if (! isset($this->_groupList[$groupName])) {
            $group = $this->_pdo->queryOneRow(sprintf('SELECT id FROM groups WHERE name = %s', $this->_pdo->escapeString($groupName)));
            $this->_groupList[$groupName] = $group['id'];
        }

        return $this->_groupList[$groupName];
    }

    /**
     * After updating or inserting new PRE, reset these.
     */
    protected function _resetPreVariables()
    {
        $this->_nuked = false;
        $this->_oldPre = [];
        $this->_curPre =
            [
                'title'    => '',
                'size'     => '',
                'predate'  => '',
                'category' => '',
                'source'   => '',
                'group_id'  => '',
                'reqid'    => '',
                'nuked'    => '',
                'reason'   => '',
                'files'    => '',
                'filename' => '',
            ];
    }
}
