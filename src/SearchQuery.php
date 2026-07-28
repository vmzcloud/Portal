<?php

declare(strict_types=1);

/**
 * Shared AND/OR search for Notes and Todo.
 * Syntax: terms, AND, OR, parentheses, "quoted phrases".
 * Default join between terms = AND. AND binds tighter than OR.
 */
final class SearchQuery
{
    /**
     * @param callable(string): ?string $normalizeTag  Optional tag normalizer for #exact match
     * @param list<string> $tags
     * @param list<string> $textFields  Already plain strings to search (title, body, names, …)
     */
    public static function matches(
        string $q,
        array $textFields,
        array $tags = [],
        ?callable $normalizeTag = null
    ): bool {
        $q = trim($q);
        if ($q === '') {
            return true;
        }
        try {
            $tokens = self::tokenize($q);
            if ($tokens === []) {
                return true;
            }
            $pos = 0;
            $result = self::parseOr($tokens, $pos, $textFields, $tags, $normalizeTag);
            if ($pos < count($tokens)) {
                return self::matchTerm($q, $textFields, $tags, $normalizeTag);
            }
            return $result;
        } catch (Throwable) {
            return self::matchTerm($q, $textFields, $tags, $normalizeTag);
        }
    }

    /**
     * @param list<string> $textFields
     * @param list<string> $tags
     * @param callable(string): ?string|null $normalizeTag
     */
    public static function matchTerm(
        string $term,
        array $textFields,
        array $tags = [],
        ?callable $normalizeTag = null
    ): bool {
        $term = mb_strtolower(trim($term));
        if ($term === '') {
            return true;
        }

        if (str_starts_with($term, '#')) {
            $tagName = null;
            if ($normalizeTag !== null) {
                $tagName = $normalizeTag($term);
            } else {
                $tagName = ltrim($term, '#');
                $tagName = $tagName !== '' ? mb_strtolower($tagName) : null;
            }
            if ($tagName !== null && $tagName !== '') {
                foreach ($tags as $t) {
                    if (mb_strtolower((string) $t) === $tagName) {
                        return true;
                    }
                }
            }
        }

        $tagBits = [];
        foreach ($tags as $t) {
            $tagBits[] = (string) $t;
            $tagBits[] = '#' . $t;
        }
        $hay = mb_strtolower(implode(' ', array_merge($textFields, $tagBits)));
        return str_contains($hay, $term);
    }

    /**
     * @return list<array{type:string,value:string}>
     */
    private static function tokenize(string $q): array
    {
        $tokens = [];
        $len = mb_strlen($q);
        $i = 0;
        while ($i < $len) {
            $ch = mb_substr($q, $i, 1);
            if (preg_match('/\s/u', $ch)) {
                $i++;
                continue;
            }
            if ($ch === '(' || $ch === ')') {
                $tokens[] = ['type' => $ch, 'value' => $ch];
                $i++;
                continue;
            }
            if ($ch === '"') {
                $i++;
                $buf = '';
                while ($i < $len) {
                    $c = mb_substr($q, $i, 1);
                    if ($c === '"') {
                        $i++;
                        break;
                    }
                    $buf .= $c;
                    $i++;
                }
                $tokens[] = ['type' => 'term', 'value' => $buf];
                continue;
            }
            $buf = '';
            while ($i < $len) {
                $c = mb_substr($q, $i, 1);
                if (preg_match('/\s/u', $c) || $c === '(' || $c === ')') {
                    break;
                }
                $buf .= $c;
                $i++;
            }
            $upper = mb_strtoupper($buf);
            if ($upper === 'AND' || $upper === 'OR') {
                $tokens[] = ['type' => $upper, 'value' => $upper];
            } else {
                $tokens[] = ['type' => 'term', 'value' => $buf];
            }
        }
        return $tokens;
    }

    /**
     * @param list<array{type:string,value:string}> $tokens
     * @param list<string> $textFields
     * @param list<string> $tags
     * @param callable(string): ?string|null $normalizeTag
     */
    private static function parseOr(
        array $tokens,
        int &$pos,
        array $textFields,
        array $tags,
        ?callable $normalizeTag
    ): bool {
        $left = self::parseAnd($tokens, $pos, $textFields, $tags, $normalizeTag);
        while ($pos < count($tokens) && ($tokens[$pos]['type'] ?? '') === 'OR') {
            $pos++;
            $right = self::parseAnd($tokens, $pos, $textFields, $tags, $normalizeTag);
            $left = $left || $right;
        }
        return $left;
    }

    /**
     * @param list<array{type:string,value:string}> $tokens
     * @param list<string> $textFields
     * @param list<string> $tags
     * @param callable(string): ?string|null $normalizeTag
     */
    private static function parseAnd(
        array $tokens,
        int &$pos,
        array $textFields,
        array $tags,
        ?callable $normalizeTag
    ): bool {
        $left = self::parsePrimary($tokens, $pos, $textFields, $tags, $normalizeTag);
        while ($pos < count($tokens)) {
            $type = $tokens[$pos]['type'] ?? '';
            if ($type === 'OR' || $type === ')') {
                break;
            }
            if ($type === 'AND') {
                $pos++;
            } elseif ($type !== 'term' && $type !== '(') {
                break;
            }
            $right = self::parsePrimary($tokens, $pos, $textFields, $tags, $normalizeTag);
            $left = $left && $right;
        }
        return $left;
    }

    /**
     * @param list<array{type:string,value:string}> $tokens
     * @param list<string> $textFields
     * @param list<string> $tags
     * @param callable(string): ?string|null $normalizeTag
     */
    private static function parsePrimary(
        array $tokens,
        int &$pos,
        array $textFields,
        array $tags,
        ?callable $normalizeTag
    ): bool {
        if ($pos >= count($tokens)) {
            return true;
        }
        $tok = $tokens[$pos];
        if ($tok['type'] === '(') {
            $pos++;
            $inner = self::parseOr($tokens, $pos, $textFields, $tags, $normalizeTag);
            if ($pos < count($tokens) && ($tokens[$pos]['type'] ?? '') === ')') {
                $pos++;
            }
            return $inner;
        }
        if ($tok['type'] === 'term') {
            $pos++;
            return self::matchTerm($tok['value'], $textFields, $tags, $normalizeTag);
        }
        if ($tok['type'] === 'AND' || $tok['type'] === 'OR') {
            $pos++;
            return self::parsePrimary($tokens, $pos, $textFields, $tags, $normalizeTag);
        }
        throw new RuntimeException('Unexpected token');
    }
}
