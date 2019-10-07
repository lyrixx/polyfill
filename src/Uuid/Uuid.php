<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Polyfill\Uuid;

/**
 * @internal
 *
 * @author Grégoire Pineau <lyrixx@lyrixx.info>
 */
final class Uuid
{
    const UUID_VARIANT_NCS = 0;
    const UUID_VARIANT_DCE = 1;
    const UUID_VARIANT_MICROSOFT = 2;
    const UUID_VARIANT_OTHER = 3;
    const UUID_TYPE_DEFAULT = 0;
    const UUID_TYPE_TIME = 1;
    const UUID_TYPE_DCE = 4;
    const UUID_TYPE_NAME = 1;
    const UUID_TYPE_RANDOM = 4;
    const UUID_TYPE_NULL = -1;
    const UUID_TYPE_INVALID = -42;

    public static function uuid_create(int $uuid_type = UUID_TYPE_DEFAULT): string
    {
        switch ($uuid_type) {
            case UUID_TYPE_NAME:
            case UUID_TYPE_TIME:
                return self::uuid_generate_time();
            case UUID_TYPE_DCE:
            case UUID_TYPE_RANDOM:
            case UUID_TYPE_DEFAULT:
                return self::uuid_generate_random();
            default:
                trigger_error("Unknown/invalid UUID type '%d' requested, using default type instead", E_USER_WARNING);
                return self::uuid_generate_random();
        }
    }

    public static function uuid_is_valid(string $uuid): bool
    {
        return null !== self::uuid_parse_as_array($uuid);
    }

    public static function uuid_compare(string $uuid1, string $uuid2)
    {
        if (null === self::uuid_parse_as_array($uuid1)) {
            return false;
        }

        if (null === self::uuid_parse_as_array($uuid2)) {
            return false;
        }

        return $uuid1 <=> $uuid2;
    }

    public static function uuid_is_null(string $uuid): bool
    {
        return '00000000-0000-0000-0000-000000000000' === $uuid;
    }

    public static function uuid_type(string $uuid)
    {
        if (null === $parsed = self::uuid_parse_as_array($uuid)) {
            return false;
        }

        if (self::uuid_is_null($uuid)) {
            return self::UUID_TYPE_NULL;
        }

        return ($parsed['time_hi_and_version'] >> 12) & 0xF;
    }

    public static function uuid_variant(string $uuid)
    {
        if (null === $parsed = self::uuid_parse_as_array($uuid)) {
            return false;
        }

        if (self::uuid_is_null($uuid)) {
            return self::UUID_TYPE_NULL;
        }

        if (($parsed['clock_seq'] & 0x8000) == 0) {
            return self::UUID_VARIANT_NCS;
        }
        if (($parsed['clock_seq'] & 0x4000) == 0) {
            return self::UUID_VARIANT_DCE;
        }
        if (($parsed['clock_seq'] & 0x2000) == 0) {
            return self::UUID_VARIANT_MICROSOFT;
        }

        return self::UUID_VARIANT_OTHER;
    }

    public static function uuid_time(string $uuid)
    {
        if (PHP_INT_SIZE !== 8) {
            throw new \RuntimeException('UUID time generation is not supported on 32bits system.');
        }

        if (null === $parsed = self::uuid_parse_as_array($uuid)) {
            return false;
        }

        if (self::UUID_TYPE_TIME !== self::uuid_type($uuid)) {
            return false;
        }

        $high = $parsed['time_mid'] | (($parsed['time_hi_and_version'] & 0xFFF) << 16);
        $clockReg = $parsed['time_low'] | ($high << 32);
        $clockReg -= 122192928000000000;

        return (int) ($clockReg / 10000000);
    }

    public static function uuid_mac(string $uuid)
    {
        if (null === $parsed = self::uuid_parse_as_array($uuid)) {
            return false;
        }

        if (self::UUID_TYPE_TIME !== self::uuid_type($uuid)) {
            return false;
        }

        return dechex($parsed['node']);
    }

    public static function uuid_parse(string $uuid)
    {
        if (null === $parsed = self::uuid_parse_as_array($uuid)) {
            return false;
        }

        $uuid = str_replace('-', '', $uuid);

        return pack('H*', $uuid);
    }

    public static function uuid_unparse(string $uuidAsBinary)
    {
        $data = unpack('H*', $uuidAsBinary)[1];

        if (32 != \strlen($data)) {
            return false;
        }

        $uuid = sprintf('%s-%s-%s-%s-%s',
            substr($data, 0, 8),
            substr($data, 8, 4),
            substr($data, 12, 4),
            substr($data, 16, 4),
            substr($data, 20, 12)
        );

        if (null === self::uuid_parse_as_array($uuid)) {
            return false;
        }

        return $uuid;
    }

    private static function uuid_generate_random(): string
    {
        return sprintf('%08x-%04x-%04x-%04x-%012x',
            // 32 bits for "time_low"
            random_int(0, 0xffffffff),

            // 16 bits for "time_mid"
            random_int(0, 0xffff),

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 4
            random_int(0, 0x0fff) | 0x4000,

            // 16 bits:
            // * 8 bits for "clk_seq_hi_res",
            // * 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            random_int(0, 0x3fff) | 0x8000,

            // 48 bits for "node"
            random_int(0, 0xffffffffffff)
        );
    }

    /**
     * @see http://tools.ietf.org/html/rfc4122#section-4.2.2
     */
    private static function uuid_generate_time(): string
    {
        // https://tools.ietf.org/html/rfc4122#section-4.1.4
        // 0x01b21dd213814000 is the number of 100-ns intervals between the
        // UUID epoch 1582-10-15 00:00:00 and the Unix epoch 1970-01-01 00:00:00.
        $offset = 0x01b21dd213814000;
        $timeOfDay = gettimeofday();
        $time = ($timeOfDay['sec'] * 10000000) + ($timeOfDay['usec'] * 10) + $offset;

        // https://tools.ietf.org/html/rfc4122#section-4.1.5
        // We are using a random data for the sake of simplicity: since we are
        // not able to get a super precise timeOfDay as a unique sequence
        $clockSeq = random_int(0, 0x3fff);

        if (\function_exists('apcu_fetch')) {
            $node = apcu_fetch('__symfony_uuid_node');
            if (false === $node) {
                $node = random_int(0, 0xffffffffffff);
                if (false === apcu_store('__symfony_uuid_node', $node)) {
                    goto static_node;
                }
            }
        } else {
            static_node:
            static $node;
            if (null === $node) {
                $node = random_int(0, 0xffffffffffff);
            }
        }

        return sprintf('%08x-%04x-%04x-%04x-%012x',
            // 32 bits for "time_low"
            $time & 0xffffffff,

            // 16 bits for "time_mid"
            ($time >> 32) & 0xffff,

            // 16 bits for "time_hi_and_version",
            // four most significant bits holds version number 1
            ($time >> 48) | 1 << 12,

            // 16 bits:
            // * 8 bits for "clk_seq_hi_res",
            // * 8 bits for "clk_seq_low",
            // two most significant bits holds zero and one for variant DCE1.1
            $clockSeq | 0x8000,

            // 48 bits for "node"
            $node
        );
    }

    private static function uuid_parse_as_array(string $uuid)
    {
        if (36 != \strlen($uuid)) {
            return null;
        }

        static $regex = '{^(?<time_low>[0-9a-f]{8})-(?<time_mid>[0-9a-f]{4})-(?<time_hi_and_version>[0-9a-f]{4})-(?<clock_seq>[0-9a-f]{4})-(?<node>[0-9a-f]{12})$}i';
        if (!preg_match($regex, $uuid, $matches)) {
            return null;
        }

        return array(
            'time_low' => hexdec($matches['time_low']),
            'time_mid' => hexdec($matches['time_mid']),
            'time_hi_and_version' => hexdec($matches['time_hi_and_version']),
            'clock_seq' => hexdec($matches['clock_seq']),
            'node' => hexdec($matches['node']),
        );
    }
}
