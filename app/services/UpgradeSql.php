<?php
declare(strict_types=1);

namespace app\services;

/** 解析已验签升级包中的 SQL，客户端 DELIMITER 指令不发送给数据库。 */
final class UpgradeSql
{
    /**
     * 执行方在每条语句后更新 sqlMode，让后续字符串转义遵循当前连接设置。
     * @return \Generator<int, array{sql: string, line: int}>
     */
    public static function statements(string $sql, string &$sqlMode): \Generator
    {
        if (str_starts_with($sql, "\xEF\xBB\xBF")) $sql = substr($sql, 3);
        if (str_contains($sql, "\0") || preg_match('//u', $sql) !== 1) {
            throw new \RuntimeException('upgrade.sql 必须是 UTF-8 文本，且不能包含空字节');
        }
        $sql = str_replace(["\r\n", "\r"], "\n", $sql);
        $length = strlen($sql);
        $buffer = '';
        $delimiter = ';';
        $quote = '';
        $escape = false;
        $lineComment = false;
        $blockComment = false;
        $keepComment = false;
        $hasSql = false;
        $line = 1;
        $startLine = 1;

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';
            if ($lineComment) {
                if ($char === "\n") {
                    $lineComment = false;
                    $line++;
                    $buffer .= "\n";
                }
                continue;
            }
            if ($blockComment) {
                if ($keepComment) $buffer .= $char;
                if ($char === "\n") $line++;
                if ($char === '*' && $next === '/') {
                    if ($keepComment) $buffer .= '/';
                    else $buffer .= ' ';
                    $blockComment = false;
                    $i++;
                }
                continue;
            }
            if ($quote !== '') {
                $buffer .= $char;
                if ($char === "\n") $line++;
                if ($escape && $char === '\\' && $next !== '') {
                    $buffer .= $next;
                    if ($next === "\n") $line++;
                    $i++;
                } elseif ($char === $quote) {
                    if ($next === $quote) {
                        $buffer .= $next;
                        $i++;
                    } else {
                        $quote = '';
                    }
                }
                continue;
            }

            if (($i === 0 || $sql[$i - 1] === "\n")
                && preg_match('/\G[ \t]*DELIMITER[ \t]+(\S+)[ \t]*(?:\n|\z)/i', $sql, $directive, 0, $i)) {
                if ($hasSql) throw new \RuntimeException('upgrade.sql 第 ' . $line . ' 行：DELIMITER 必须放在完整语句之间');
                $delimiter = $directive[1];
                if (strlen($delimiter) > 16 || strpbrk($delimiter, "'\"`\\") !== false) {
                    throw new \RuntimeException('upgrade.sql 第 ' . $line . ' 行：分隔符格式不受支持');
                }
                $line += substr_count($directive[0], "\n");
                $buffer = '';
                $i += strlen($directive[0]) - 1;
                continue;
            }
            if (substr_compare($sql, $delimiter, $i, strlen($delimiter)) === 0) {
                $statement = trim($buffer);
                $buffer = '';
                $i += strlen($delimiter) - 1;
                if ($hasSql) {
                    $hasSql = false;
                    yield ['sql' => $statement, 'line' => $startLine];
                }
                continue;
            }
            if ($char === '#' || ($char === '-' && $next === '-' && ($i + 2 >= $length || ord($sql[$i + 2]) <= 32))) {
                $buffer .= ' ';
                $lineComment = true;
                if ($char === '-') $i++;
                continue;
            }
            if ($char === '/' && $next === '*') {
                $blockComment = true;
                // 保留 MySQL/MariaDB 可执行注释和优化器提示，普通注释只保留空白。
                $tail = substr($sql, $i + 2, 2);
                $keepComment = str_starts_with($tail, '!') || str_starts_with($tail, '+') || strtoupper($tail) === 'M!';
                if ($keepComment) $buffer .= '/*';
                if ((str_starts_with($tail, '!') || strtoupper($tail) === 'M!') && !$hasSql) {
                    $hasSql = true;
                    $startLine = $line;
                }
                $i++;
                continue;
            }
            if (!$hasSql && !ctype_space($char)) {
                $hasSql = true;
                $startLine = $line;
            }
            if ($char === "'" || $char === '"' || $char === '`') {
                $quote = $char;
                $modes = array_fill_keys(explode(',', strtoupper($sqlMode)), true);
                $escape = $char !== '`' && !($char === '"' && isset($modes['ANSI_QUOTES'])) && !isset($modes['NO_BACKSLASH_ESCAPES']);
            }
            if ($char === "\n") $line++;
            $buffer .= $char;
        }

        if ($quote !== '' || $blockComment) {
            throw new \RuntimeException('upgrade.sql 第 ' . $line . ' 行附近存在未闭合的字符串、标识符或注释');
        }
        if ($hasSql) yield ['sql' => trim($buffer), 'line' => $startLine];
    }
}
