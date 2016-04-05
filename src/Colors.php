<?php

namespace Dogma\Tools;

final class Colors
{
    public static $off = false;

    const WHITE = 'white';
    const LGRAY = 'lgray';
    const GRAY = 'gray';
    const BLACK = 'black';
    const RED = 'red';
    const LRED = 'lred';
    const GREEN = 'green';
    const LGREEN = 'lgreen';
    const BLUE = 'blue';
    const LBLUE = 'lblue';
    const CYAN = 'cyan';
    const LCYAN = 'lcyan';
    const PURPLE = 'purple';
    const LPURPLE = 'lpurple';
    const YELLOW = 'yellow';
    const LYELLOW = 'lyellow';

    private static $fg = array(
        self::WHITE => '1;37',
        self::LGRAY => '0;37',
        self::GRAY => '1;30',
        self::BLACK => '0;30',

        self::RED => '0;31',
        self::LRED => '1;31',
        self::GREEN => '0;32',
        self::LGREEN => '1;32',
        self::BLUE => '0;34',
        self::LBLUE => '1;34',

        self::CYAN => '0;36',
        self::LCYAN => '1;36',
        self::PURPLE => '0;35',
        self::LPURPLE => '1;35',
        self::YELLOW => '1;33',
        self::LYELLOW => '0;33',
    );

    private static $bg = array(
        self::LGRAY => '47',
        self::BLACK => '40',

        self::RED => '41',
        self::GREEN => '42',
        self::BLUE => '44',

        self::YELLOW => '43',
        self::PURPLE => '45',
        self::CYAN => '46',
    );

    static function color(string $string, string $foreground = null, string $background = null): string
    {
        if (self::$off) {
            return $string;
        }

        if (!isset(self::$fg[$foreground]) && !isset(self::$bg[$background])) {
            return $string;
        }

        $out = '';
        if (isset(self::$fg[$foreground])) {
            $out .= "\x1B[" . self::$fg[$foreground] . 'm';
        }
        if (isset(self::$bg[$background])) {
            $out .= "\x1B[" . self::$bg[$background] . 'm';
        }

        return $out . $string . "\x1B[0m";
    }

    static function background(string $string, string $background): string
    {
        return self::color($string, null, $background);
    }

    /**
     * Remove formatting characters from a string
     */
    static function remove(string $string): string
    {
        return preg_replace('/\\x1B\\[[^m]+m/U', '', $string);
    }

    /**
     * Safely pads string with formatting characters to length
     */
    static function padString(string $string, int $length, string $with = ' ', int $type = STR_PAD_RIGHT): string
    {
        $original = self::remove($string);

        return str_pad($string, $length + strlen($string) - strlen($original), $with, $type);
    }

    // shortcuts -------------------------------------------------------------------------------------------------------

    static function white(string $string, string $background = null): string
    {
        return self::color($string, self::WHITE, $background);
    }

    static function lgray(string $string, string $background = null): string
    {
        return self::color($string, self::LGRAY, $background);
    }

    static function gray(string $string, string $background = null): string
    {
        return self::color($string, self::GRAY, $background);
    }

    static function black(string $string, string $background = null): string
    {
        return self::color($string, self::BLACK, $background);
    }

    static function red(string $string, string $background = null): string
    {
        return self::color($string, self::RED, $background);
    }

    static function lred(string $string, string $background = null): string
    {
        return self::color($string, self::LRED, $background);
    }

    static function green(string $string, string $background = null): string
    {
        return self::color($string, self::GREEN, $background);
    }

    static function lgreen(string $string, string $background = null): string
    {
        return self::color($string, self::LGREEN, $background);
    }

    static function blue(string $string, string $background = null): string
    {
        return self::color($string, self::BLUE, $background);
    }

    static function lblue(string $string, string $background = null): string
    {
        return self::color($string, self::LBLUE, $background);
    }

    static function cyan(string $string, string $background = null): string
    {
        return self::color($string, self::CYAN, $background);
    }

    static function lcyan(string $string, string $background = null): string
    {
        return self::color($string, self::LCYAN, $background);
    }

    static function purple(string $string, string $background = null): string
    {
        return self::color($string, self::PURPLE, $background);
    }

    static function lpurple(string $string, string $background = null): string
    {
        return self::color($string, self::LPURPLE, $background);
    }

    static function yellow(string $string, string $background = null): string
    {
        return self::color($string, self::YELLOW, $background);
    }

    static function lyellow(string $string, string $background = null): string
    {
        return self::color($string, self::LYELLOW, $background);
    }

}
