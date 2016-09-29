<?php

namespace Dogma\Tools\Dumper;

use Dogma\Tools\Colors as C;
use Dogma\Tools\Configurator;
use Dogma\Tools\Console;
use Dogma\Tools\SimplePdo;

require __DIR__ . '/src/Colors.php';
require __DIR__ . '/src/Console.php';
$console = new Console();

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    $console->write(C::lcyan('Database Structure Dumper'))->ln(2);
    $console->write(C::white('Run `composer install` to install dependencies.', C::RED));
    die();
}

require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/src/Configurator.php';
require __DIR__ . '/src/SimplePdo.php';
require __DIR__ . '/src/System.php';
require __DIR__ . '/src/TableFormatter.php';
require __DIR__ . '/src/Dumper/IoAdapter.php';
require __DIR__ . '/src/Dumper/MysqlAdapter.php';
require __DIR__ . '/src/Dumper/DatabaseInfo.php';
require __DIR__ . '/src/Dumper/DatabaseDumper.php';
require __DIR__ . '/src/Dumper/DataDumper.php';
require __DIR__ . '/src/Dumper/DumpFormatter.php';
require __DIR__ . '/vendor/nette/neon/src/neon.php';

if (class_exists(\Tracy\Debugger::class)) {
    \Tracy\Debugger::enable(\Tracy\Debugger::DEVELOPMENT, __DIR__ . '/log');
    \Tracy\Debugger::$maxDepth = 8;
    \Tracy\Debugger::$maxLen = 1000;
    \Tracy\Debugger::$showLocation = true;
}

$arguments = [
        'Configuration:',
    'config' =>         ['c', Configurator::VALUES, 'configuration files', 'paths'],
        'Commands:',
    'read' =>           ['r', Configurator::FLAG, 'show differences against existing dump'],
    'write' =>          ['W', Configurator::FLAG, 'write outputs (rewrite existing dump)'],
    'query' =>          ['q', Configurator::VALUE, 'query the MySQL server and display results', 'SQL code'],
    'help' =>           ['', Configurator::FLAG, 'show help'],
    'license' =>        ['', Configurator::FLAG, 'show license'],
        'Database connection:',
    'host' =>           ['h', Configurator::VALUE, 'server host', 'address'],
    'port' =>           ['a', Configurator::VALUE, 'server port', 'number'],
    'user' =>           ['u', Configurator::VALUE, 'user name', 'name'],
    'password' =>       ['p', Configurator::VALUE, 'user password', 'password'],
        'What to dump:',
    'databases' =>      ['b', Configurator::VALUES, 'list of databases to dump', 'names'],
    'data' =>           ['', Configurator::VALUES, 'list of tables to dump with data', 'table names'],
    'skip' =>           ['', Configurator::SET, 'skip types', 'types', DatabaseDumper::TYPES],
    'removeCharsets' => ['', Configurator::VALUES, 'remove default charsets from table definitions', 'charsets'],
    'removeCollations' => ['', Configurator::VALUES, 'remove default collations from table definitions', 'collations'],
    'removeSizes' =>    ['', Configurator::ENUM, 'remove int/float size parameters from column definitions', 'which', ['default', 'all', 'none']],
    'prettyFormat' =>   ['f', Configurator::FLAG, 'pretty formatting for views and events'],
        'Input/Output:',
    'inputDir' =>       ['i', Configurator::VALUE, 'input directory', 'path'],
    'outputDir' =>      ['o', Configurator::VALUE, 'output directory', 'path'],
    'singleFile' =>     ['s', Configurator::FLAG, 'create single output file'],
    'filePerDatabase' => ['', Configurator::FLAG, 'create output file for each database'],
    'lineEndings' =>    ['l', Configurator::ENUM, 'line endings in output files', 'type', array_keys(DatabaseDumper::LINE_ENDINGS)],
    'indentation' =>    ['i', Configurator::ENUM, 'code indentation (spaces)', 'type', array_keys(DatabaseDumper::INDENTATION)],
        'CLI output formatting:',
    'short' =>          ['', Configurator::FLAG, 'short console output (no newlines)'],
    'noColors' =>       ['C', Configurator::FLAG, 'without colors'],
];
$defaults = [
    'config' => [strtr(__DIR__, '\\', '/') . '/config.neon'],
    'inputDir' => strtr(__DIR__, '\\', '/') . '/dump/in',
    'outputDir' => strtr(__DIR__, '\\', '/') . '/dump/out',
    'host' => '127.0.0.1',
    'port' => 3306,
    'lineEndings' => 'LF',
    'indentation' => 2,
    'prettyFormat' => true,
    'removeSizes' => 'default',
];
$config = new Configurator($arguments, $defaults);
$config->loadCliArguments();

if ($config->noColors) {
    C::$off = true;
}

$created = C::lcyan('Created by @paranoiq 2016');
$console->writeLn(C::lgreen("   _     _       _                    _                       "));
$console->writeLn(C::lgreen(" _| |___| |_ ___| |_ ___ ___ ___    _| |_ _ _____ ___ ___ ___ "));
$console->writeLn(C::lgreen("| . | .'|  _| .'| . | .'|_ -| -_|  | . | | |     | . | -_|  _|"));
$console->writeLn(C::lgreen("|___|__,|_| |__,|___|__,|___|___|  |___|___|_|_|_|  _|___|_|  "));
$console->writeLn($created .               C::lgreen("                        |_|          "))->ln();

if ($config->help || (!$config->hasValues() && (!$config->config))) {
    $console->write('Usage: php dump-db.php [options]')->ln(2);
    $console->write($config->renderHelp());
}
if ($config->help) {
    $console->writeLn('This tool was crated for generating documentation and development data sets.');
    $console->writeLn(C::red('DO NOT USE IT FOR DATABASE BACKUPS!'));
    exit;
}
if ($config->license) {
    $console->writeFile(__DIR__ . '/license.md');
    exit;
}
foreach ($config->config as $path) {
    $config->loadConfig($path);
}

try {
    $dsn = sprintf('mysql:host=%s;port=%d', $config->host, $config->port);
    $connection = new SimplePdo($dsn, $config->user, $config->password);
    $adapter = new MysqlAdapter($connection);
    $dumper = new DatabaseDumper($config, $adapter, $console);

    Console::switchTerminalToUtf8();
    $dumper->run();
} catch (\PDOException $e) {
    if (isset($connection)) {
        $console->ln()->writeLn(C::white('Error when trying to dump database.', C::RED));
    } else {
        $console->ln()->writeLn(C::white('Cannot connect to specified database server.', C::RED));
    }
    if (class_exists(\Tracy\Debugger::class)) {
        \Tracy\Debugger::log($e);
    } else {
        throw $e;
    }
}
$console->ln();
