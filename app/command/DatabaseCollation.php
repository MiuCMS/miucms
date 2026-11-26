<?php
declare(strict_types=1);

namespace app\command;

use backup\Backup;
use RuntimeException;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use think\db\PDOConnection;
use think\facade\Db;

final class DatabaseCollation extends Command
{
    private const COLLATION = 'utf8mb4_unicode_ci';

    protected function configure()
    {
        $this->setName('db-collation')
            ->setDescription('检查 CMS 表的排序规则，兼容 MySQL 5.7')
            ->addOption('apply', null, Option::VALUE_NONE, '备份并转换，请在停止站点写入后执行');
    }

    protected function execute(Input $input, Output $output): int
    {
        $db = Db::connect();
        $locked = false;
        $changing = '';
        $backup = '';
        $lockName = 'miu-collation-' . md5((string) $db->getConfig('database'));
        try {
            $prefix = (string) $db->getConfig('prefix');
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $prefix)) {
                throw new RuntimeException('请配置有效的 CMS 表前缀后再执行。');
            }
            if ($input->getOption('apply')) {
                $locked = (int) ($db->query('SELECT GET_LOCK(?, 0) AS acquired', [$lockName], true)[0]['acquired'] ?? 0) === 1;
                if (!$locked) {
                    throw new RuntimeException('已有排序规则迁移正在执行。');
                }
            }

            $plan = $this->plan($db, $prefix);
            if (!$plan) {
                $output->writeln('当前前缀下的表已使用兼容排序规则。');
                return 0;
            }
            foreach ($plan as $table => $change) {
                $output->writeln($table . ' · 表默认 ' . $change['default'] . ' → ' . self::COLLATION);
                foreach ($change['columns'] as $column => $details) {
                    $output->writeln('  ' . $column . ' · ' . $details['old'] . ' → ' . $details['target']);
                }
            }

            $statements = [];
            foreach ($plan as $table => $change) {
                $statements[$table] = $this->statement($db, $table, $change['columns']);
            }
            if (!$input->getOption('apply')) {
                $output->writeln('扫描完成。停止站点写入后，可加 --apply 自动备份并转换。');
                return 0;
            }

            $backup = $this->backupTables(array_keys($statements));
            $output->writeln('备份已保存到 ' . $backup);
            foreach ($statements as $table => $sql) {
                $changing = $table;
                $db->execute($sql);
                $output->writeln('已转换 ' . $table);
            }
            $changing = '';
            if ($this->plan($db, $prefix)) {
                throw new RuntimeException('复查发现仍有待转换数据表。');
            }
            $output->writeln('转换完成，可以重新导出数据库。');
            return 0;
        } catch (\Throwable $e) {
            $output->writeln('迁移停止' . ($changing === '' ? '' : '，失败表 ' . $changing) . '。' . $e->getMessage());
            if ($backup !== '') {
                $output->writeln('迁移前备份位于 ' . $backup . '。已完成的表可在再次执行时自动跳过。');
            }
            return 1;
        } finally {
            if ($locked) {
                $db->query('SELECT RELEASE_LOCK(?)', [$lockName], true);
            }
        }
    }

    private function plan(PDOConnection $db, string $prefix): array
    {
        $rows = $db->query(
            "SELECT t.TABLE_NAME AS table_name, t.TABLE_COLLATION AS table_collation,
                c.COLUMN_NAME AS column_name, c.COLUMN_TYPE AS column_type, c.COLLATION_NAME AS column_collation
            FROM information_schema.TABLES t
            LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = t.TABLE_SCHEMA AND c.TABLE_NAME = t.TABLE_NAME
                AND INSTR(c.COLLATION_NAME, '_0900_') > 0
            WHERE t.TABLE_SCHEMA = DATABASE() AND t.TABLE_TYPE = 'BASE TABLE'
                AND LEFT(t.TABLE_NAME, CHAR_LENGTH(?)) = ?
            ORDER BY t.TABLE_NAME, c.ORDINAL_POSITION",
            [$prefix, $prefix],
            true
        );
        $plan = [];
        foreach ($rows as $row) {
            $table = $row['table_name'];
            $old = $row['column_collation'];
            if (!str_starts_with($table, $prefix) || ($row['table_collation'] === self::COLLATION && $old === null)) {
                continue;
            }
            if (!preg_match('/\A[A-Za-z0-9_]+\z/', $table)) {
                throw new RuntimeException('现有备份工具无法处理表名 ' . $table);
            }
            $plan[$table] ??= ['default' => $row['table_collation'], 'columns' => []];
            if ($old === null) {
                continue;
            }
            $column = $row['column_name'];
            $target = match ($old) {
                'utf8mb4_0900_ai_ci' => self::COLLATION,
                'utf8mb4_0900_bin' => 'utf8mb4_bin',
                default => throw new RuntimeException("{$table}.{$column} 使用 {$old}，需要先确定对应的 MySQL 5.7 排序规则。"),
            };
            $plan[$table]['columns'][$column] = ['old' => $old, 'target' => $target, 'type' => $row['column_type']];
        }
        return $plan;
    }

    private function statement(PDOConnection $db, string $table, array $columns): string
    {
        $quotedTable = $this->identifier($table);
        $actions = ['DEFAULT CHARACTER SET utf8mb4 COLLATE ' . self::COLLATION];
        if (!$columns) {
            return 'ALTER TABLE ' . $quotedTable . ' ' . $actions[0];
        }

        $this->checkForeignKeys($db, $table, $columns);
        $this->checkUniqueIndexes($db, $table, $columns);
        $mode = $db->query('SELECT @@SESSION.sql_mode AS mode', [], true)[0]['mode'];
        $db->execute('SET SESSION sql_quote_show_create = 1');
        $create = $db->query('SHOW CREATE TABLE ' . $quotedTable, [], true)[0]['Create Table'];
        $definitions = $this->columnDefinitions($create, !in_array('NO_BACKSLASH_ESCAPES', explode(',', $mode), true));
        foreach ($columns as $name => $details) {
            $type = $details['type'];
            $definition = $definitions[$name] ?? '';
            $pattern = '~\A(' . preg_quote($this->identifier($name), '~') . '\s+' . preg_quote($type, '~')
                . ')(?:\s+CHARACTER SET\s+utf8mb4)?(?:\s+COLLATE\s+' . preg_quote($details['old'], '~') . ')?(?=\s|\z)~i';
            if (!preg_match($pattern, $definition, $original)) {
                throw new RuntimeException("无法保留 {$table}.{$name} 的完整字段定义。");
            }
            $rest = substr($definition, strlen($original[0]));
            if (preg_match('/\A\s+(?:CHARACTER\s+SET|COLLATE)\b/i', $rest)) {
                throw new RuntimeException("{$table}.{$name} 的字段定义与当前排序规则不一致。");
            }
            $actions[] = 'MODIFY COLUMN ' . $original[1] . ' CHARACTER SET utf8mb4 COLLATE ' . $details['target'] . $rest;
        }
        return 'ALTER TABLE ' . $quotedTable . ' ' . implode(', ', $actions);
    }

    private function checkForeignKeys(PDOConnection $db, string $table, array $columns): void
    {
        $foreignKeys = $db->query(
            'SELECT CONSTRAINT_NAME AS name,
                CASE WHEN TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? THEN COLUMN_NAME END AS child_column,
                CASE WHEN REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ? THEN REFERENCED_COLUMN_NAME END AS parent_column
            FROM information_schema.KEY_COLUMN_USAGE
            WHERE REFERENCED_TABLE_NAME IS NOT NULL
                AND ((TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?)
                    OR (REFERENCED_TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME = ?))',
            [$table, $table, $table, $table],
            true
        );
        foreach ($foreignKeys as $foreignKey) {
            $child = $foreignKey['child_column'];
            $parent = $foreignKey['parent_column'];
            if (($child !== null && isset($columns[$child])) || ($parent !== null && isset($columns[$parent]))) {
                throw new RuntimeException("{$table} 的外键 {$foreignKey['name']} 引用了待转换字段，需要先处理外键两端。");
            }
        }
    }

    private function checkUniqueIndexes(PDOConnection $db, string $table, array $columns): void
    {
        $quotedTable = $this->identifier($table);
        $indexes = [];
        foreach ($db->query('SHOW INDEX FROM ' . $quotedTable, [], true) as $row) {
            if ((int) $row['Non_unique'] === 0) {
                $indexes[$row['Key_name']][] = $row;
            }
        }
        foreach ($indexes as $index => $parts) {
            $expressions = [];
            $notNull = [];
            $affected = false;
            foreach ($parts as $part) {
                $name = $part['Column_name'];
                if ($name === null) {
                    throw new RuntimeException("{$table} 的唯一索引 {$index} 包含表达式，需要人工检查。");
                }
                $column = $this->identifier($name);
                $expression = $part['Sub_part'] === null ? $column : 'LEFT(' . $column . ', ' . (int) $part['Sub_part'] . ')';
                if (isset($columns[$name])) {
                    $expression .= ' COLLATE ' . $columns[$name]['target'];
                    $affected = true;
                }
                $expressions[] = $expression;
                $notNull[] = $column . ' IS NOT NULL';
            }
            if (!$affected) {
                continue;
            }
            $sql = 'SELECT 1 FROM ' . $quotedTable . ' WHERE ' . implode(' AND ', $notNull)
                . ' GROUP BY ' . implode(', ', $expressions) . ' HAVING COUNT(*) > 1 LIMIT 1';
            if ($db->query($sql, [], true)) {
                throw new RuntimeException("{$table} 的唯一索引 {$index} 在目标排序规则下存在重复值，请先处理冲突。");
            }
        }
    }

    private function columnDefinitions(string $create, bool $escape): array
    {
        // 仅在建表语句第一层括号内分隔字段。跳过字符串、标识符和版本注释。
        $quoted = $escape
            ? <<<'REGEX'
'(?:[^'\\]++|\\[\s\S]|'')*+'|"(?:[^"\\]++|\\[\s\S]|"")*+"
REGEX
            : <<<'REGEX'
'(?:[^']++|'')*+'|"(?:[^"]++|"")*+"
REGEX;
        $pattern = '~' . $quoted . '|`(?:[^`]++|``)*+`|/\*[\s\S]*?\*/|[(),]~';
        if (preg_match_all($pattern, $create, $tokens, PREG_OFFSET_CAPTURE) === false) {
            throw new RuntimeException('无法解析建表语句。');
        }
        $depth = 0;
        $start = 0;
        $definitions = [];
        foreach ($tokens[0] as [$token, $offset]) {
            if ($token === '(') {
                if ($depth++ === 0) {
                    $start = $offset + 1;
                }
                continue;
            }
            if ($depth === 1 && ($token === ',' || $token === ')')) {
                $definition = trim(substr($create, $start, $offset - $start));
                if (preg_match('/\A`((?:[^`]|``)+)`\s/', $definition, $match)) {
                    $definitions[str_replace('``', '`', $match[1])] = $definition;
                }
                $start = $offset + 1;
                if ($token === ')') {
                    return $definitions;
                }
            }
            if ($token === ')') {
                $depth--;
            }
        }
        throw new RuntimeException('建表语句缺少完整的字段列表。');
    }

    private function identifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    private function backupTables(array $tables): string
    {
        $path = runtime_path('collation-backup') . date('Ymd-His') . '-' . bin2hex(random_bytes(4)) . DIRECTORY_SEPARATOR;
        $umask = umask(0077);
        try {
            if (!mkdir($path, 0700, true)) {
                throw new RuntimeException('无法创建排序规则迁移备份目录。');
            }
            $backup = new Backup(['path' => $path, 'compress' => 0, 'part' => PHP_INT_MAX]);
            $backup->setFile(['name' => 'miucms', 'part' => 1]);
            if ($backup->Backup_Init() === false) {
                throw new RuntimeException('无法初始化迁移备份。');
            }
            foreach ($tables as $table) {
                if ($backup->backup($table, 0) === false) {
                    throw new RuntimeException('无法备份表 ' . $table);
                }
            }
            unset($backup);
            return $path . 'miucms-1.sql';
        } finally {
            umask($umask);
        }
    }
}
