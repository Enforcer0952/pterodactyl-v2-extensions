<?php

namespace McCrash\Support;

/**
 * Removes private details from a report or log before it is uploaded to a public paste site: IP addresses,
 * the arguments of login and registration commands, and the values of -D options in JVM crash logs (which
 * often hold passwords and tokens). Anything that merely looks like one of these is hidden too; a
 * report that is missing a version number is better than one that leaks an address.
 */
final class Redact
{
    public const string HIDDEN_IP = '[ip hidden]';

    public const string HIDDEN = '[hidden]';

    /** Commands whose arguments are passwords, with or without a plugin prefix such as "authme:". */
    private const string COMMANDS = 'login|register|l|reg|changepassword|cp|unregister|changepass';

    /**
     * @return array{text: string, ips: int, commands: int, jvm_args: int}
     */
    public static function apply(string $text, bool $jvm): array
    {
        $ips = 0;
        $commands = 0;
        $args = 0;

        // IPv6 first: an address with an IPv4 tail ("::ffff:1.2.3.4") is one token.
        $text = (string) preg_replace_callback('/(?<![\w:.])[0-9A-Fa-f:.]{3,}(?![\w])/', function (array $match) use (&$ips): string {
            $core = rtrim($match[0], '.:');
            if (! str_contains($core, ':') || filter_var($core, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                return $match[0];
            }
            $ips++;

            return self::HIDDEN_IP.substr($match[0], strlen($core));
        }, $text);

        $octet = '(?:25[0-5]|2[0-4]\d|1\d\d|[1-9]?\d)';
        $text = (string) preg_replace_callback('/(?<![\w.])'.$octet.'(?:\.'.$octet.'){3}(?![\w]|\.\d)/', function () use (&$ips): string {
            $ips++;

            return self::HIDDEN_IP;
        }, $text);

        $text = (string) preg_replace_callback(
            '/(issued server command:[ \t]*\/?)((?:[A-Za-z0-9_.-]+:)?(?:'.self::COMMANDS.'))(?![\w-])[^\r\n]*/i',
            function (array $match) use (&$commands): string {
                $commands++;

                return $match[1].$match[2].' '.self::HIDDEN;
            },
            $text,
        );

        if ($jvm) {
            $text = (string) preg_replace_callback('/(?<![\w])(-D[\w.\-]+=)(?:"[^"\r\n]*"|\'[^\'\r\n]*\'|[^\s"\']+)/', function (array $match) use (&$args): string {
                $args++;

                return $match[1].self::HIDDEN;
            }, $text);
        }

        return ['text' => $text, 'ips' => $ips, 'commands' => $commands, 'jvm_args' => $args];
    }

    /** Whether a listed file is a JVM crash log, which gets the -D treatment. */
    public static function isJvmLog(string $path): bool
    {
        return preg_match('~(?:^|/)hs_err_pid\d{1,10}\.log$~D', $path) === 1;
    }
}
