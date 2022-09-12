<?php

namespace SeanKndy\Poller;

function humanSizeToBytes($size): int
{
    if (\preg_match('/^\-?([0-9\.]+)\s*([A-Za-z]+)$/', $size, $m)) {
        $num = $m[1];
        $sizeAbbrev = $m[2];

        switch ($sizeAbbrev) {
            case 'B':
            case 'Bytes':
                return intval($num);
            case 'KB':
            case 'kB':
            case 'Kilobytes':
            case 'KiloBytes':
            case 'kilobytes':
                return intval($num * 1000);
            case 'MB':
            case 'Megabytes':
            case 'MegaBytes':
            case 'megabytes':
                return intval($num * 1000 * 1000);
            case 'GB':
            case 'Gigabytes':
            case 'GigaBytes':
            case 'gigabytes':
                return intval($num * 1000 * 1000 * 1000);
            case 'TB':
            case 'Terabytes':
            case 'TeraBytes':
            case 'terabytes':
                return intval($num * 1000 * 1000 * 1000 * 1000);

            case 'b':
            case 'bits':
            case 'Bits':
                return intval($num/8);
            case 'Kb':
            case 'kb':
            case 'kbit':
            case 'Kbit':
            case 'kilobits':
            case 'Kilobits':
            case 'KiloBits':
                return intval($num/8 * 1000);
            case 'm':
            case 'M':
            case 'Mb':
            case 'mb':
            case 'mbit':
            case 'Mbit':
            case 'megabits':
            case 'Megabits':
            case 'MegaBits':
                return intval($num/8 * 1000 * 1000);
            case 'gb':
            case 'Gb':
            case 'gbit':
            case 'Gbit':
            case 'Gigabits':
            case 'GigaBits':
            case 'gigabits':
                return intval($num/8 * 1000 * 1000 * 1000);
            case 'Tb':
            case 'tb':
            case 'tbit':
            case 'Tbit':
            case 'terabits':
            case 'Terabits':
            case 'TeraBits':
                return intval($num/8 * 1000 * 1000 * 1000 * 1000);
        }

        return intval($num);
    } else {
        return intval(\preg_replace('/[^\-0-9\.]/', '', $size));
    }
}