<?php

declare(strict_types=1);

/**
 * Minimal iCalendar parser for VEVENT blocks (no Composer / tzdb).
 * Supports DTSTART/DTEND (date or datetime, Z/UTC), SUMMARY, DESCRIPTION,
 * LOCATION, UID. RRULE and complex TZID are not expanded (single instance only).
 */
final class IcsParser
{
    /**
     * @return list<array{
     *   uid: string,
     *   title: string,
     *   description: string,
     *   location: string,
     *   starts_at: string,
     *   ends_at: string,
     *   all_day: bool
     * }>
     */
    public static function parseEvents(string $ics): array
    {
        $text = self::normalize($ics);
        $blocks = self::extractVevents($text);
        $out = [];
        foreach ($blocks as $block) {
            $props = self::parseProperties($block);
            $start = self::parseDateProperty($props, 'DTSTART');
            if ($start === null) {
                continue;
            }
            $end = self::parseDateProperty($props, 'DTEND');
            if ($end === null) {
                // DURATION not supported; default +1 hour or +1 day for all-day
                if ($start['all_day']) {
                    $ts = strtotime($start['datetime'] . ' +1 day');
                    $end = [
                        'datetime' => $ts ? date('Y-m-d H:i:s', $ts) : $start['datetime'],
                        'all_day' => true,
                    ];
                } else {
                    $ts = strtotime($start['datetime'] . ' +1 hour');
                    $end = [
                        'datetime' => $ts ? date('Y-m-d H:i:s', $ts) : $start['datetime'],
                        'all_day' => false,
                    ];
                }
            }

            // All-day DTEND in ICS is exclusive; convert to inclusive end-of-previous-day style for our storage
            $allDay = $start['all_day'] || $end['all_day'];
            $startsAt = $start['datetime'];
            $endsAt = $end['datetime'];
            if ($allDay) {
                $startDay = substr($startsAt, 0, 10);
                $endDay = substr($endsAt, 0, 10);
                // exclusive end date → last inclusive day
                $endTs = strtotime($endDay . ' -1 day');
                if ($endTs !== false && strcmp($endDay, $startDay) > 0) {
                    $endDay = date('Y-m-d', $endTs);
                }
                if (strcmp($endDay, $startDay) < 0) {
                    $endDay = $startDay;
                }
                $startsAt = $startDay . ' 00:00:00';
                $endsAt = $endDay . ' 23:59:59';
            }

            $title = trim(self::propValue($props, 'SUMMARY') ?? '');
            if ($title === '') {
                $title = '(No title)';
            }

            $out[] = [
                'uid' => trim(self::propValue($props, 'UID') ?? ''),
                'title' => self::unescapeText($title),
                'description' => self::unescapeText(trim(self::propValue($props, 'DESCRIPTION') ?? '')),
                'location' => self::unescapeText(trim(self::propValue($props, 'LOCATION') ?? '')),
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'all_day' => $allDay,
            ];
        }
        return $out;
    }

    /**
     * Build date => name map from holiday-style ICS (typically all-day events).
     *
     * @return array<string, string> Y-m-d => name
     */
    public static function parseHolidays(string $ics): array
    {
        $events = self::parseEvents($ics);
        $map = [];
        foreach ($events as $ev) {
            // Holiday calendars are typically all-day; skip timed events
            if (empty($ev['all_day'])) {
                continue;
            }
            $startDay = substr($ev['starts_at'], 0, 10);
            $endDay = substr($ev['ends_at'], 0, 10);
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDay)) {
                continue;
            }
            $name = $ev['title'] !== '' ? $ev['title'] : 'Holiday';
            $cur = $startDay;
            $guard = 0;
            while (strcmp($cur, $endDay) <= 0 && $guard < 400) {
                if (!isset($map[$cur])) {
                    $map[$cur] = $name;
                }
                $ts = strtotime($cur . ' +1 day');
                if ($ts === false) {
                    break;
                }
                $cur = date('Y-m-d', $ts);
                $guard++;
            }
        }
        ksort($map);
        return $map;
    }

    private static function normalize(string $ics): string
    {
        $ics = str_replace(["\r\n", "\r"], "\n", $ics);
        // Unfold: newline + space/tab continuation
        $ics = preg_replace("/\n[ \t]/", '', $ics) ?? $ics;
        return $ics;
    }

    /** @return list<string> */
    private static function extractVevents(string $text): array
    {
        $blocks = [];
        if (!preg_match_all('/BEGIN:VEVENT\n(.*?)END:VEVENT/s', $text, $m)) {
            return [];
        }
        foreach ($m[1] as $body) {
            $blocks[] = $body;
        }
        return $blocks;
    }

    /**
     * @return array<string, array{params: array<string, string>, value: string}>
     */
    private static function parseProperties(string $block): array
    {
        $props = [];
        foreach (explode("\n", $block) as $line) {
            $line = trim($line);
            if ($line === '' || !str_contains($line, ':')) {
                continue;
            }
            [$left, $value] = explode(':', $line, 2);
            $parts = explode(';', $left);
            $name = strtoupper(array_shift($parts) ?? '');
            if ($name === '') {
                continue;
            }
            $params = [];
            foreach ($parts as $p) {
                if (str_contains($p, '=')) {
                    [$pk, $pv] = explode('=', $p, 2);
                    $params[strtoupper($pk)] = $pv;
                } else {
                    $params[strtoupper($p)] = '1';
                }
            }
            // First wins for simplicity (except we only need one of each)
            if (!isset($props[$name])) {
                $props[$name] = ['params' => $params, 'value' => $value];
            }
        }
        return $props;
    }

    /** @param array<string, array{params: array<string, string>, value: string}> $props */
    private static function propValue(array $props, string $name): ?string
    {
        return $props[$name]['value'] ?? null;
    }

    /**
     * @param array<string, array{params: array<string, string>, value: string}> $props
     * @return array{datetime: string, all_day: bool}|null
     */
    private static function parseDateProperty(array $props, string $name): ?array
    {
        if (!isset($props[$name])) {
            return null;
        }
        $params = $props[$name]['params'];
        $value = trim($props[$name]['value']);
        $value = strtoupper($value) === $value ? $value : $value; // keep as-is
        $isDate = isset($params['VALUE']) && strtoupper($params['VALUE']) === 'DATE';
        // YYYYMMDD
        if (preg_match('/^(\d{4})(\d{2})(\d{2})$/', $value, $m)) {
            return [
                'datetime' => sprintf('%s-%s-%s 00:00:00', $m[1], $m[2], $m[3]),
                'all_day' => true,
            ];
        }
        // YYYYMMDDTHHMMSS or with Z
        if (preg_match('/^(\d{4})(\d{2})(\d{2})T(\d{2})(\d{2})(\d{2})Z?$/i', $value, $m)) {
            $dt = sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6]);
            // If Z, treat as UTC then convert to server local
            if (str_ends_with(strtoupper($value), 'Z')) {
                $ts = strtotime($dt . ' UTC');
                if ($ts !== false) {
                    $dt = date('Y-m-d H:i:s', $ts);
                }
            }
            return [
                'datetime' => $dt,
                'all_day' => $isDate,
            ];
        }
        return null;
    }

    private static function unescapeText(string $text): string
    {
        $text = str_replace(['\\n', '\\N'], "\n", $text);
        $text = str_replace(['\\,', '\\;', '\\\\'], [',', ';', '\\'], $text);
        return $text;
    }
}
