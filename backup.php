<?php
/**
 * LYRASOFT backup script.
 *
 * @copyright  Copyright (C) 2015 LYRASOFT. All rights reserved.
 */

// Uncomment if debugging
use ZipStream\Option\File as FileOptions;

error_reporting(-1);

$options = [
    /*
     * Basic Information
     */
    'secret' => '{{ secret }}',
    'root' => '/',

    /*
     * The database information
     * Only support mysql now.
     */
    'dump_database' => 1,

    'database' => [
        'host' => 'localhost',
        'user' => '',
        'pass' => '',
        'name' => '',
    ],

    'includes' => [
        '*'
    ],

    /*
     * Ignore files of force included files.
     *
     * Ignore file:
     * /folder/to/ignore/*
     *
     * Force include
     * !/folder/to/retain.txt
     */
    'ignores' => [
        '*/.git/*',
        '/logs/*',
        '!/logs/index.html',
        '/log/*',
        '!/log/index.html',
        '/cache/*',
        '!/cache/index.html',
        '/tmp/*',
    ],

    'config' => 'backup.json',
    'mysqldump' => 'mysqldump'
];

class BackupApplication
{
    protected $sapi = '';

    /**
     * @var  array
     */
    protected $options = [];

    /**
     * Class init
     *
     * @param  array  $options
     */
    public function __construct(array $options)
    {
        $this->options = $options;

        // Override
        if (is_file(__DIR__ . '/' . $this->options['config'])) {
            $override = json_decode(file_get_contents(__DIR__ . '/' . $this->options['config']), true);

            $this->options = array_merge($this->options, $override);
        }

        $this->options['root'] = realpath($path = __DIR__ . '/' . trim($this->getOption('root'), '/'));

        if (!is_dir($this->options['root'])) {
            $this->close('Path: ' . $path . ' not exists');
        }
    }

    /**
     * execute
     *
     * @param  string  $sapi
     *
     * @return  void
     */
    public function execute(string $sapi): void
    {
        $this->sapi = $sapi;

        if ($sapi === 'cli') {
            $this->executeCli();
            return;
        }

        // $this->authenticate();

        $this->doBackup();
    }

    public function executeCli(): void
    {
        $argv = $_SERVER['argv'];
        $file = array_shift($argv);

        if (count($argv) === 0) {
            $token = $this->getToken($this->options['secret'] ?? $this->close('Np secret', 400));

            echo <<<HELP
LYRASOFT Backup script

Token: {$token}

Options:
-h    Show help.
-u    Show backup URL.

Commands:

Usage: {$file} <backup file position>
HELP;
            $this->close('', 200);
        }

        $this->doBackup(fopen($argv[0], 'wb'));

        echo sprintf('Output Zip file to: ' . realpath($argv[0]));
    }

    public function doBackup($output = 'php://output', bool $headers = true)
    {
        set_time_limit(0);
        ini_set('memory_limit', '1G');

        if ($headers) {
            $name = rawurldecode($this->getBackupFilename());
            header('Content-Type: application/x-zip');
            header("Content-Disposition: attachment; filename*=UTF-8''{$name}");
            header('Pragma: public');
            header('Cache-Control: public, must-revalidate');
            header('Content-Transfer-Encoding: binary');
        }

        $zip = new ZipStream($output);

        if ($this->getOption('dump_database', true)) {
            [$proc, $pipe] = $this->sqlDump();

            $zip->addFileFromStream('backup.sql', $pipe);
        }

        $this->zipFiles($zip);

        $zip->finish();
    }

    /**
     * @return  resource[]
     */
    protected function sqlDump(): array
    {
        $cmd = sprintf(
            '%s -u %s -p%s %s',
            $this->options['mysqldump'] ?? 'mysqldump',
            $this->options['database']['user'],
            $this->options['database']['pass'],
            $this->options['database']['name']
        );

        $descriptorspec = [
            0 => ["pipe", "r"],   // stdin is a pipe that the child will read from
            1 => ["pipe", "w"],   // stdout is a pipe that the child will write to
            2 => ["pipe", "w"]    // stderr is a pipe that the child will write to
        ];

        $process = proc_open($cmd, $descriptorspec, $pipes, getcwd(), []);

        return [$process, $pipes[1]];
    }

    protected function zipFiles(ZipStream $zip): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->getOption('root'), RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $filter = new FileFilter($this->getOption('ignores'), $this->getOption('root'));

        /** @var \SplFileInfo $item */
        foreach ($iterator as $item) {
            // Excludes
            if ($filter->test($item->getPathname())) {
                continue;
            }

            $dest = str_replace($this->getOption('root') . DIRECTORY_SEPARATOR, '', $item->getPathname());

            $zip->addFileFromPath($dest, $item->getPathname());
        }
    }

    public function authenticate(): bool
    {
        $token = $_REQUEST['token'] ?? $this->close('Invalid Token');

        $key = $this->getOption('secret') ?? $this->close('No secret');

        if ($this->getToken($key) !== $token) {
            $this->close('Invalid Token');
        }

        return true;
    }

    protected function getToken(string $secret): string
    {
        return sha1(md5('LYRASOFT:' . $secret));
    }

    public function getOption(string $key, $default = null)
    {
        return $this->options[$key] ?? $default;
    }

    public function close(string $msg, int $code = 401): void
    {
        if ($this->sapi === 'cli') {
            echo $msg;

            exit($code === 200 ? 0 : 255);
        }

        http_response_code($code);

        exit($msg);
    }

    public function getBackupFilename(): string
    {
        if (!isset($_SERVER['HTTP_HOST'])) {
            return 'backup';
        }

        $base = $_SERVER['HTTP_HOST'] . '/' . $_SERVER['SCRIPT_NAME'];

        $str = str_replace('-', ' ', $base);

        if (function_exists('mb_strtolower')) {
            $str = mb_strtolower(trim($str));
        } else {
            $str = strtolower(trim($str));
        }

        $str = preg_replace('/(\s|[^A-Za-z0-9\-])+/', '-', $str);

        return trim($str, '-') . '.zip';
    }

    public static function registerErrorHandler(): void
    {
        set_error_handler(
            function ($errno, $errstr, $errfile, $errline) {
                throw new ErrorException($errstr, $errno, 1, $errfile, $errline);
            }
        );
    }
}

class FileFilter
{
    /**
     * @var  array
     */
    protected $rules = [];

    /**
     * @var  string
     */
    protected $root = __DIR__;

    public function __construct(array $rules, string  $root = __DIR__)
    {
        $this->rules = $rules;

        foreach ($this->rules as &$rule) {
            $rule = str_replace(['/', '\\'], '/', $rule);
        }

        $this->root = $root;
    }

    public function test(string $string): string
    {
        $match = false;

        // fnmatch() only work for UNIX file path
        $string = str_replace(['/', '\\'], '/', $string);

        $string = substr($string, strlen(rtrim($this->root, '/')));

        foreach ($this->rules as $rule) {
            // Negative
            if (strpos($rule, '!') === 0) {
                $rule = substr($rule, 1);

                if (fnmatch($rule, $string)) {
                    $match = false;
                }
            } elseif (fnmatch($rule, $string)) {
                // Normal
                $match = true;
            }
        }

        return $match;
    }
}

class ZipStream
{
    public const DEFLATE_LEVEL = 6;

    public const METHOD_DEFLATE = 0x08;

    public const VERSION_ZIP64 = 0x002D;

    public const ZIP_VERSION_MADE_BY = 0x603;

    public const FILE_HEADER_SIGNATURE = 0x04034b50;

    public const CDR_FILE_SIGNATURE = 0x02014b50;

    public const CDR_EOF_SIGNATURE = 0x06054b50;

    public const DATA_DESCRIPTOR_SIGNATURE = 0x08074b50;

    public const ZIP64_CDR_EOF_SIGNATURE = 0x06064b50;

    public const ZIP64_CDR_LOCATOR_SIGNATURE = 0x07064b50;

    /**
     * @var array
     */
    public $files = [];

    /**
     * @var Bigint
     */
    public $cdr_ofs;

    /**
     * @var Bigint
     */
    public $ofs;

    /**
     * @var resource
     */
    protected $output;

    /**
     * @param  string|resource  $output
     */
    public function __construct($output = 'php://output')
    {
        $this->cdr_ofs = new Bigint();
        $this->ofs     = new Bigint();

        if (!is_resource($output)) {
            $output = fopen($output, 'rb+');
        }

        $this->output  = $output;
    }

    public function addFile(string $name, string $data): void
    {
        $file = new File($this, $name);
        $file->processData($data);
    }

    public function addFileFromPath(string $name, string $path): void
    {
        $file = new File($this, $name);
        $file->processPath($path);
    }

    public function addFileFromStream(string $name, $stream): void
    {
        $file = new File($this, $name);
        $file->processStream($stream);
    }

    public function finish(): void
    {
        // add trailing cdr file records
        foreach ($this->files as $cdrFile) {
            $this->send($cdrFile);
            $this->cdr_ofs = $this->cdr_ofs->add(Bigint::init(strlen($cdrFile)));
        }

        // Add 64bit headers (if applicable)
        if (count($this->files) >= 0xFFFF ||
            $this->cdr_ofs->isOver32() ||
            $this->ofs->isOver32()) {
            $this->addCdr64Eof();
            $this->addCdr64Locator();
        }

        // add trailing cdr eof record
        $this->addCdrEof();
    }

    protected function addCdr64Eof(): void
    {
        $num_files  = count($this->files);
        $cdr_length = $this->cdr_ofs;
        $cdr_offset = $this->ofs;

        $fields = [
            ['V', static::ZIP64_CDR_EOF_SIGNATURE],     // ZIP64 end of central file header signature
            ['P', 44],                                  // Length of data below this header (length of block - 12) = 44
            ['v', static::ZIP_VERSION_MADE_BY],         // Made by version
            ['v', self::VERSION_ZIP64],                      // Extract by version
            ['V', 0x00],                                // disk number
            ['V', 0x00],                                // no of disks
            ['P', $num_files],                          // no of entries on disk
            ['P', $num_files],                          // no of entries in cdr
            ['P', $cdr_length],                         // CDR size
            ['P', $cdr_offset],                         // CDR offset
        ];

        $ret = static::packFields($fields);
        $this->send($ret);
    }

    public static function packFields(array $fields): string
    {
        $fmt  = '';
        $args = [];

        // populate format string and argument list
        foreach ($fields as [$format, $value]) {
            if ($format === 'P') {
                $fmt .= 'VV';
                if ($value instanceof Bigint) {
                    $args[] = $value->getLow32();
                    $args[] = $value->getHigh32();
                } else {
                    $args[] = $value;
                    $args[] = 0;
                }
            } else {
                if ($value instanceof Bigint) {
                    $value = $value->getLow32();
                }
                $fmt    .= $format;
                $args[] = $value;
            }
        }

        // prepend format string to argument list
        array_unshift($args, $fmt);

        // build output string from header and compressed data
        return pack(...$args);
    }

    public function send(string $str): void
    {
        fwrite($this->output, $str);

        // flush output buffer if it is on and flushable
        $status = ob_get_status();
        if (isset($status['flags']) && ($status['flags'] & PHP_OUTPUT_HANDLER_FLUSHABLE)) {
            ob_flush();
        }

        // Flush system buffers after flushing userspace output buffer
        flush();
    }

    protected function addCdr64Locator(): void
    {
        $cdr_offset = $this->ofs->add($this->cdr_ofs);

        $fields = [
            ['V', static::ZIP64_CDR_LOCATOR_SIGNATURE], // ZIP64 end of central file header signature
            ['V', 0x00],                                // Disc number containing CDR64EOF
            ['P', $cdr_offset],                         // CDR offset
            ['V', 1],                                   // Total number of disks
        ];

        $ret = static::packFields($fields);
        $this->send($ret);
    }

    protected function addCdrEof(): void
    {
        $num_files  = count($this->files);
        $cdr_length = $this->cdr_ofs;
        $cdr_offset = $this->ofs;

        // grab comment (if specified)

        $fields = [
            ['V', static::CDR_EOF_SIGNATURE],   // end of central file header signature
            ['v', 0x00],                        // disk number
            ['v', 0x00],                        // no of disks
            ['v', min($num_files, 0xFFFF)],     // no of entries on disk
            ['v', min($num_files, 0xFFFF)],     // no of entries in cdr
            ['V', $cdr_length->getLowFF()],     // CDR size
            ['V', $cdr_offset->getLowFF()],     // CDR offset
            ['v', 0],            // Zip Comment size
        ];

        $ret = static::packFields($fields);
        $this->send($ret);
    }

    /**
     * Save file attributes for trailing CDR record.
     *
     * @param  File  $file
     *
     * @return void
     */
    public function addToCdr(File $file): void
    {
        $file->ofs     = $this->ofs;
        $this->ofs     = $this->ofs->add($file->getTotalLength());
        $this->files[] = $file->getCdrFile();
    }
}

class Bigint
{
    /**
     * @var int[]
     */
    private $bytes = [0, 0, 0, 0, 0, 0, 0, 0];

    public function __construct(int $value = 0)
    {
        $this->fillBytes($value, 0, 8);
    }

    protected function fillBytes(int $value, int $start, int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->bytes[$start + $i] = $i >= PHP_INT_SIZE ? 0 : $value & 0xFF;

            $value >>= 8;
        }
    }

    public static function init(int $value = 0): self
    {
        return new self($value);
    }

    public static function fromLowHigh(int $low, int $high): self
    {
        $bigint = new Bigint();
        $bigint->fillBytes($low, 0, 4);
        $bigint->fillBytes($high, 4, 4);

        return $bigint;
    }

    public function getHigh32(): int
    {
        return $this->getValue(4, 4);
    }

    public function getValue(int $end = 0, int $length = 8): int
    {
        $result = 0;
        for ($i = $end + $length - 1; $i >= $end; $i--) {
            $result <<= 8;
            $result |= $this->bytes[$i];
        }

        return $result;
    }

    public function getLowFF(bool $force = false): float
    {
        if ($force || $this->isOver32()) {
            return (float) 0xFFFFFFFF;
        }

        return (float) $this->getLow32();
    }

    public function isOver32(bool $force = false): bool
    {
        // value 0xFFFFFFFF already needs a Zip64 header
        return $force ||
            max(array_slice($this->bytes, 4, 4)) > 0 ||
            min(array_slice($this->bytes, 0, 4)) === 0xFF;
    }

    public function getLow32(): int
    {
        return $this->getValue(0, 4);
    }

    public function getHex64(): string
    {
        $result = '0x';
        for ($i = 7; $i >= 0; $i--) {
            $result .= sprintf('%02X', $this->bytes[$i]);
        }

        return $result;
    }

    public function add(Bigint $other): Bigint
    {
        $result   = clone $this;
        $overflow = false;
        for ($i = 0; $i < 8; $i++) {
            $result->bytes[$i] += $other->bytes[$i];
            if ($overflow) {
                $result->bytes[$i]++;
                $overflow = false;
            }
            if ($result->bytes[$i] & 0x100) {
                $overflow          = true;
                $result->bytes[$i] &= 0xFF;
            }
        }
        if ($overflow) {
            throw new \OverflowException();
        }

        return $result;
    }
}

class File
{
    public const HASH_ALGORITHM = 'crc32b';

    public const BIT_ZERO_HEADER = 0x0008;

    public const COMPUTE = 1;

    public const SEND = 2;

    private const CHUNKED_READ_BLOCK_SIZE = 1048576;

    /**
     * @var string
     */
    public $name;

    /**
     * @var Bigint
     */
    public $len;

    /**
     * @var Bigint
     */
    public $zlen;

    /** @var  int */
    public $crc;

    /**
     * @var Bigint
     */
    public $hlen;

    /**
     * @var Bigint
     */
    public $ofs;

    /**
     * @var int
     */
    public $bits;

    /**
     * @var int
     */
    public $version;

    /**
     * @var ZipStream
     */
    public $zip;

    /**
     * @var resource
     */
    private $deflate;

    /**
     * @var resource
     */
    private $hash;

    /**
     * @var mixed
     */
    private $method;

    /**
     * @var Bigint
     */
    private $totalLength;

    public function __construct(ZipStream $zip, string $name)
    {
        $this->zip = $zip;

        $this->name    = $name;
        $this->method  = ZipStream::METHOD_DEFLATE;
        $this->version = ZipStream::VERSION_ZIP64;
        $this->ofs     = new Bigint();
    }

    public function processPath(string $path): void
    {
        $stream = fopen($path, 'rb');
        $this->processStream($stream);
        fclose($stream);
    }

    public function processData(string $data): void
    {
        $this->len = new Bigint(strlen($data));
        $this->crc = crc32($data);

        $data       = gzdeflate($data);
        $this->zlen = new Bigint(strlen($data));
        $this->addFileHeader();
        $this->zip->send($data);
        $this->addFileFooter();
    }

    public function addFileHeader(): void
    {
        $name = static::filterFilename($this->name);

        // calculate name length
        $nameLength = strlen($name);

        // create dos timestamp
        $time = static::dosTime(time());

        $this->version = ZipStream::VERSION_ZIP64;

        $force = (boolean) ($this->bits & self::BIT_ZERO_HEADER);

        $footer = $this->buildZip64ExtraBlock($force);

        $fields = [
            ['V', ZipStream::FILE_HEADER_SIGNATURE],
            ['v', $this->version],      // Version needed to Extract
            ['v', $this->bits],                     // General purpose bit flags - data descriptor flag set
            ['v', $this->method],       // Compression method
            ['V', $time],                           // Timestamp (DOS Format)
            ['V', $this->crc],                      // CRC32 of data (0 -> moved to data descriptor footer)
            ['V', $this->zlen->getLowFF($force)],   // Length of compressed data (forced to 0xFFFFFFFF for zero header)
            ['V', $this->len->getLowFF($force)],    // Length of original data (forced to 0xFFFFFFFF for zero header)
            ['v', $nameLength],                     // Length of filename
            ['v', strlen($footer)],                 // Extra data (see above)
        ];

        // pack fields and calculate "total" length
        $header = ZipStream::packFields($fields);

        // print header and filename
        $data = $header . $name . $footer;
        $this->zip->send($data);

        // save header length
        $this->hlen = Bigint::init(strlen($data));
    }

    public static function filterFilename(string $filename): string
    {
        $filename = preg_replace('/^\\/+/', '', $filename);

        return str_replace(['\\', ':', '*', '?', '"', '<', '>', '|'], '_', $filename);
    }

    final protected static function dosTime(int $when): int
    {
        // get date array for timestamp
        $d = getdate($when);

        // set lower-bound on dates
        if ($d['year'] < 1980) {
            $d = [
                'year' => 1980,
                'mon' => 1,
                'mday' => 1,
                'hours' => 0,
                'minutes' => 0,
                'seconds' => 0,
            ];
        }

        // remove extra years from 1980
        $d['year'] -= 1980;

        // return date string
        return
            ($d['year'] << 25) |
            ($d['mon'] << 21) |
            ($d['mday'] << 16) |
            ($d['hours'] << 11) |
            ($d['minutes'] << 5) |
            ($d['seconds'] >> 1);
    }

    protected function buildZip64ExtraBlock(bool $force = false): string
    {
        $fields = [];
        if ($this->len->isOver32($force)) {
            $fields[] = ['P', $this->len];          // Length of original data
        }

        if ($this->len->isOver32($force)) {
            $fields[] = ['P', $this->zlen];         // Length of compressed data
        }

        if ($this->ofs->isOver32()) {
            $fields[] = ['P', $this->ofs];          // Offset of local header record
        }

        if (!empty($fields)) {
            array_unshift(
                $fields,
                ['v', 0x0001],                      // 64 bit extension
                ['v', count($fields) * 8]             // Length of data block
            );
            $this->version = ZipStream::VERSION_ZIP64;
        }

        return ZipStream::packFields($fields);
    }

    public function addFileFooter(): void
    {
        if ($this->bits & self::BIT_ZERO_HEADER) {
            $sizeFormat = 'P'; // Zip64
            $fields     = [
                ['V', ZipStream::DATA_DESCRIPTOR_SIGNATURE],
                ['V', $this->crc],              // CRC32
                [$sizeFormat, $this->zlen],     // Length of compressed data
                [$sizeFormat, $this->len],      // Length of original data
            ];

            $footer = ZipStream::packFields($fields);
            $this->zip->send($footer);
        } else {
            $footer = '';
        }
        $this->totalLength = $this->hlen->add($this->zlen)->add(Bigint::init(strlen($footer)));
        $this->zip->addToCdr($this);
    }

    public function processStream($stream): void
    {
        $this->zlen = new Bigint();
        $this->len  = new Bigint();

        $this->processStreamWithZeroHeader($stream);
    }

    protected function processStreamWithZeroHeader($stream): void
    {
        $this->bits |= self::BIT_ZERO_HEADER;
        $this->addFileHeader();
        $this->readStream($stream, self::COMPUTE | self::SEND);
        $this->addFileFooter();
    }

    protected function readStream($stream, ?int $options = null): void
    {
        $this->deflateInit();
        $total = 0;
        $size  = 0;
        while (!feof($stream) && ($size === 0 || $total < $size)) {
            $data  = fread($stream, self::CHUNKED_READ_BLOCK_SIZE);
            $total += strlen($data);
            if ($size > 0 && $total > $size) {
                $data = substr($data, 0, strlen($data) - ($total - $size));
            }
            $this->deflateData($stream, $data, $options);
            if ($options & self::SEND) {
                $this->zip->send($data);
            }
        }
        $this->deflateFinish($options);
    }

    protected function deflateInit(): void
    {
        $this->hash = hash_init(self::HASH_ALGORITHM);
        if ($this->method === ZipStream::METHOD_DEFLATE) {
            $this->deflate = deflate_init(
                ZLIB_ENCODING_RAW,
                ['level' => ZipStream::DEFLATE_LEVEL]
            );
        }
    }

    protected function deflateData($stream, string &$data, ?int $options = null): void
    {
        if ($options & self::COMPUTE) {
            $this->len = $this->len->add(Bigint::init(strlen($data)));
            hash_update($this->hash, $data);
        }
        if ($this->deflate) {
            $data = deflate_add(
                $this->deflate,
                $data,
                feof($stream)
                    ? ZLIB_FINISH
                    : ZLIB_NO_FLUSH
            );
        }
        if ($options & self::COMPUTE) {
            $this->zlen = $this->zlen->add(Bigint::init(strlen($data)));
        }
    }

    protected function deflateFinish(?int $options = null): void
    {
        if ($options & self::COMPUTE) {
            $this->crc = hexdec(hash_final($this->hash));
        }
    }

    public function getCdrFile(): string
    {
        $name = static::filterFilename($this->name);

        // get attributes
        $comment = '';

        // get dos timestamp
        $time = static::dosTime(time());

        $footer = $this->buildZip64ExtraBlock();

        $fields = [
            ['V', ZipStream::CDR_FILE_SIGNATURE],   // Central file header signature
            ['v', ZipStream::ZIP_VERSION_MADE_BY],  // Made by version
            ['v', $this->version],      // Extract by version
            ['v', $this->bits],                     // General purpose bit flags - data descriptor flag set
            ['v', $this->method],       // Compression method
            ['V', $time],                           // Timestamp (DOS Format)
            ['V', $this->crc],                      // CRC32
            ['V', $this->zlen->getLowFF()],         // Compressed Data Length
            ['V', $this->len->getLowFF()],          // Original Data Length
            ['v', strlen($name)],                   // Length of filename
            ['v', strlen($footer)],                 // Extra data len (see above)
            ['v', strlen($comment)],                // Length of comment
            ['v', 0],                               // Disk number
            ['v', 0],                               // Internal File Attributes
            ['V', 32],                              // External File Attributes
            ['V', $this->ofs->getLowFF()]           // Relative offset of local header
        ];

        // pack fields, then append name and comment
        $header = ZipStream::packFields($fields);

        return $header . $name . $footer . $comment;
    }

    public function getTotalLength(): Bigint
    {
        return $this->totalLength;
    }
}

// Set error handler
BackupApplication::registerErrorHandler();

$app = new BackupApplication($options);

try {
    $app->execute(PHP_SAPI);
} catch (\Throwable $e) {
    echo $e;

    if (PHP_SAPI === 'cli') {
        exit(255);
    }

    http_response_code($e->getCode());
}
