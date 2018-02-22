<?php declare(strict_types = 1);

namespace Dogma\Tools\Dumper;

final class DumpFormatter
{

    /** @var string[] Patterns to format SQL statements */
    private static $search = [
        '/ algorithm=/i',
        '/ definer=/i',
        '/ sql security/i',
        '/ view (`[^`]+`)/i',
        '/ as \\(select /i',
        '/ as (`[^`]+`),/i',
        '/ from /i',
        '/ where /i',
        '/ group by /i',
        '/ order by /i',
        '/ union /i',
        '/ (natural )?(left |right |full )?(outer |inner |cross )?join /i',
        '/ on\\(\\((.+)\\)\\)/i',
        '/ when /i',
        '/ else /i',
        '/ end/i',
    ];

    private static $replace = [
        "\n  algorithm=",
        "\n  definer=",
        "\n  sql security",
        "\nview $1",
        " as (\nselect\n  ",
        " as $1,\n  ",
        "\nfrom ",
        "\nwhere ",
        "\ngroup by ",
        "\norder by ",
        "\n\n  union\n\n",
        "\n  $1$2$3join ",
        " on $1",
        "\n    when ",
        "\n    else ",
        "\n  end",
    ];

    public static function formatView(string $old): string
    {
        $old = preg_replace(self::$search, self::$replace, $old);

        $new = '';
        while (true) {
            $new = preg_replace([
                '/where (.+) and /i',
                '/where (.+) or /i',
            ], [
                "where$1\n  and ",
                "where$1\n  or ",
            ], $old);
            if ($new === $old) {
                break;
            }
            $old = $new;
        };

        // uppercase
        $new = preg_replace_callback('~\'.*?\'|".*?"|`.*?`|[^\'"`]*~s', function ($match) {
            $match = $match[0];
            if ($match && ($match[0] === "'" || $match[0] === '"' || $match[0] === '`')) {
                // string
                return $match;
            } else {
                // code
                return strtoupper($match);
            }
        }, $new);

        return $new;
    }

    public static function formatEvent(string $old): string
    {
        return str_replace([' ON ', ' ENABLE ', ' DO '], ["\n  ON ", "\nENABLE ", "\nDO "], $old);
    }

}
