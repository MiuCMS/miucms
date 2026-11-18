<?php
/**
 * @copyright Copyright (c) 2026 米柚工作室
 * @license https://opensource.org/licenses/Apache-2.0
 * @link https://www.miucms.com
 */

declare(strict_types=1);

namespace app\home\service;

use think\facade\Db;
use think\facade\Log;

/** 前台内容翻译读写服务：只存翻译字段，中文原表仍是唯一原文来源。 */
class ContentI18n
{
    public const DEFAULT_LOCALE = 'zh-cn';
    /** 网站全局配置在翻译表中的固定逻辑记录 ID。 */
    private const SITE_CONFIG_ID = 1;

    /** @var bool 当前请求是否已确认翻译表可用 */
    private static $schemaReady = false;
    /** @var bool 当前请求是否已创建并升级翻译表结构 */
    private static $schemaEnsured = false;

    /** 创建唯一的通用翻译表，不复制任何内容主表或模型表。 */
    public static function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $table = Db::name('content_i18n')->getTable();
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
            throw new \RuntimeException('多语言翻译表名称无效');
        }
        if (!self::hasSchema()) {
            Db::execute('CREATE TABLE IF NOT EXISTS `' . $table . '` ('
                . '`id` bigint unsigned NOT NULL AUTO_INCREMENT COMMENT \'自增主键\','
                . '`translation_key` char(64) NOT NULL COMMENT \'翻译记录唯一键\','
                . '`entity_type` varchar(16) NOT NULL COMMENT \'实体类型（document/nav/doc_model/nav_model/tag/custom/site_config）\','
                . '`entity_table` varchar(64) NOT NULL DEFAULT \'\' COMMENT \'模型附加表名，非模型实体为空\','
                . '`entity_id` bigint unsigned NOT NULL COMMENT \'原记录ID\','
                . '`locale` varchar(12) NOT NULL COMMENT \'语言代码\','
                . '`field_name` varchar(32) NOT NULL COMMENT \'被翻译字段名\','
                . '`field_value` longtext NULL COMMENT \'翻译后的字段内容\','
                . '`source_hash` char(64) NOT NULL DEFAULT \'\' COMMENT \'翻译对应的原文字段SHA-256\','
                . '`update_time` int unsigned NOT NULL DEFAULT 0 COMMENT \'更新时间（Unix时间戳）\','
                . 'PRIMARY KEY (`id`),'
                . 'UNIQUE KEY `uniq_translation_key` (`translation_key`),'
                . 'KEY `idx_entity_locale` (`entity_type`,`entity_table`,`entity_id`,`locale`)'
                . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT=\'前台内容多语言翻译表\'');
            self::$schemaReady = true;
            self::$schemaEnsured = true;
            \think\facade\Cache::tag('contentI18nSchema')->set('contentI18nSchemaExists', 1, 86400);
            return;
        }

        //较早创建的翻译表可能使用 32 字符字段，扩至 MySQL 表名上限。
        try {
            $columns = Db::query("SELECT CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = 'entity_table' LIMIT 1");
            if (!empty($columns) && (int) ($columns[0]['max_length'] ?? 64) < 64) {
                Db::execute('ALTER TABLE `' . $table . '` MODIFY `entity_table` varchar(64) NOT NULL DEFAULT \'\'');
            }
        } catch (\Throwable $e) {
            self::logFailure('检查多语言翻译表结构失败', $e);
        }
        $sourceHashColumns = Db::query("SELECT COLUMN_NAME AS column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}' AND column_name = 'source_hash' LIMIT 1");
        if (empty($sourceHashColumns)) {
            Db::execute('ALTER TABLE `' . $table . '` ADD COLUMN `source_hash` char(64) NOT NULL DEFAULT \'\' COMMENT \'翻译对应的原文字段SHA-256\' AFTER `field_value`');
        }
        self::ensureSchemaComments($table);
        self::$schemaReady = true;
        self::$schemaEnsured = true;
        \think\facade\Cache::tag('contentI18nSchema')->set('contentI18nSchemaExists', 1, 86400);
    }

    /** 为已存在的翻译表补齐表注释和字段注释。 */
    private static function ensureSchemaComments(string $table): void
    {
        $tableComment = '前台内容多语言翻译表';
        $columns = [
            'id' => ['definition' => 'bigint unsigned NOT NULL AUTO_INCREMENT', 'comment' => '自增主键'],
            'translation_key' => ['definition' => 'char(64) NOT NULL', 'comment' => '翻译记录唯一键'],
            'entity_type' => ['definition' => 'varchar(16) NOT NULL', 'comment' => '实体类型（document/nav/doc_model/nav_model/tag/custom/site_config）'],
            'entity_table' => ['definition' => "varchar(64) NOT NULL DEFAULT ''", 'comment' => '模型附加表名，非模型实体为空'],
            'entity_id' => ['definition' => 'bigint unsigned NOT NULL', 'comment' => '原记录ID'],
            'locale' => ['definition' => 'varchar(12) NOT NULL', 'comment' => '语言代码'],
            'field_name' => ['definition' => 'varchar(32) NOT NULL', 'comment' => '被翻译字段名'],
            'field_value' => ['definition' => 'longtext NULL', 'comment' => '翻译后的字段内容'],
            'source_hash' => ['definition' => "char(64) NOT NULL DEFAULT ''", 'comment' => '翻译对应的原文字段SHA-256'],
            'update_time' => ['definition' => 'int unsigned NOT NULL DEFAULT 0', 'comment' => '更新时间（Unix时间戳）'],
        ];

        try {
            $tableRows = Db::query("SELECT TABLE_COMMENT AS table_comment FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$table}' LIMIT 1");
            $columnRows = Db::query("SELECT COLUMN_NAME AS column_name, COLUMN_COMMENT AS column_comment FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = '{$table}'");
            $existingComments = [];
            foreach ($columnRows as $row) {
                $existingComments[(string) ($row['column_name'] ?? '')] = (string) ($row['column_comment'] ?? '');
            }

            $alterations = [];
            if ((string) ($tableRows[0]['table_comment'] ?? '') !== $tableComment) {
                $alterations[] = "COMMENT='{$tableComment}'";
            }
            foreach ($columns as $name => $column) {
                if (!array_key_exists($name, $existingComments) || $existingComments[$name] === $column['comment']) {
                    continue;
                }
                $alterations[] = "MODIFY COLUMN `{$name}` {$column['definition']} COMMENT '{$column['comment']}'";
            }
            if ($alterations) {
                Db::execute('ALTER TABLE `' . $table . '` ' . implode(', ', $alterations));
            }
        } catch (\Throwable $e) {
            self::logFailure('更新多语言翻译表注释失败', $e);
        }
    }

    /** 当前模板启用的非默认语言。 */
    public static function enabledLocales(?string $template = null): array
    {
        $available = TemplateI18n::availableLocales($template);
        return array_values(array_filter($available, static function ($locale) {
            return $locale !== self::DEFAULT_LOCALE;
        }));
    }

    /** 批量读取待翻译字段当前记录，比较译文和原文版本时共用。 */
    public static function storedTranslations(string $entityType, string $entityTable, int $entityId, array $locales, array $fields): array
    {
        if ($entityId < 1 || !$locales || !$fields || !self::hasSchema()) {
            return [];
        }
        try {
            $rows = Db::name('content_i18n')->where([
                'entity_type' => $entityType,
                'entity_table' => $entityTable,
                'entity_id' => $entityId,
            ])->whereIn('locale', $locales)->whereIn('field_name', $fields)->select()->toArray();
        } catch (\Throwable $e) {
            self::logFailure('读取待翻译字段失败', $e);
            return [];
        }
        $stored = [];
        foreach ($rows as $row) {
            $stored[(string) ($row['locale'] ?? '')][(string) ($row['field_name'] ?? '')] = [
                'field_value' => (string) ($row['field_value'] ?? ''),
                'source_hash' => (string) ($row['source_hash'] ?? ''),
            ];
        }
        return $stored;
    }

    /** 正文和富文本类型自定义字段交给 Qwen-MT-Uni 处理。 */
    public static function uniTranslationFields(string $entityType, string $entityTable, int $channelId, array $fieldNames): array
    {
        if (!in_array($entityType, ['doc_model', 'nav_model'], true)) {
            return [];
        }

        $fields = array_fill_keys(array_intersect($fieldNames, ['content', 'md_content']), true);
        if ($channelId < 1 && $entityTable !== '') {
            $channelId = self::channelIdByTable($entityTable);
        }
        if ($channelId < 1) {
            return $fields;
        }

        $allowedNames = array_fill_keys($fieldNames, true);
        foreach (self::channelFieldRows($channelId) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (isset($allowedNames[$name]) && in_array((string) ($field['type'] ?? ''), ['editor', 'markdown', 'html'], true)) {
                $fields[$name] = true;
            }
        }

        return $fields;
    }

    /** 为表单构造每个语言页签和字段回显值。字段描述可指定 entity_type/entity_table。 */
    public static function formRows(int $entityId, array $fieldDefinitions): array
    {
        $names = TemplateI18n::supportedLocales();
        $rows = [];
        $groups = [];
        foreach ($fieldDefinitions as $definition) {
            $type = (string) ($definition['entity_type'] ?? 'document');
            $table = (string) ($definition['entity_table'] ?? '');
            $key = $type . "\0" . $table;
            $groups[$key] = ['type' => $type, 'table' => $table];
        }
        $firstLanguage = true;
        foreach (self::enabledLocales() as $locale) {
            $valuesByGroup = [];
            foreach ($groups as $key => $group) {
                $values = self::loadFields($group['type'], [$entityId], $locale, $group['table']);
                $valuesByGroup[$key] = $values[$entityId] ?? [];
            }
            $fields = [];
            foreach ($fieldDefinitions as $definition) {
                $type = (string) ($definition['entity_type'] ?? 'document');
                $table = (string) ($definition['entity_table'] ?? '');
                $key = $type . "\0" . $table;
                $fields[] = $definition + [
                    'value' => (string) ($valuesByGroup[$key][(string) $definition['name']] ?? ''),
                ];
            }
            $rows[] = [
                'code' => $locale,
                'label' => $names[$locale] ?? $locale,
                'fields' => $fields,
                'is_first' => $firstLanguage ? 1 : 0,
            ];
            $firstLanguage = false;
        }
        return $rows;
    }

    /** 模型正文和启用的自定义字段翻译表单定义。 */
    public static function modelFieldDefinitions(string $entityType, string $entityTable, int $channelId): array
    {
        if (!in_array($entityType, ['doc_model', 'nav_model'], true)) {
            return [];
        }
        $fields = [
            ['entity_type' => $entityType, 'entity_table' => $entityTable, 'name' => 'content', 'label' => '正文（HTML）', 'type' => 'textarea', 'editor' => 'tinymce', 'rows' => 12],
            ['entity_type' => $entityType, 'entity_table' => $entityTable, 'name' => 'md_content', 'label' => '正文（Markdown）', 'type' => 'textarea', 'editor' => 'markdown', 'rows' => 8],
        ];
        if ($channelId < 1) {
            return $fields;
        }
        $allowedTypes = ['text', 'multitext', 'textarea', 'editor', 'markdown', 'html'];
        foreach (self::channelFieldRows($channelId) as $custom) {
            $name = (string) ($custom['name'] ?? '');
            if (!in_array((string) ($custom['type'] ?? ''), $allowedTypes, true)
                || $name === '' || !preg_match('/^[a-zA-Z0-9_]{1,32}$/', $name)
                || in_array($name, ['content', 'md_content'], true)) {
                continue;
            }
            $type = (string) ($custom['type'] ?? '');
            $fields[] = [
                'entity_type' => $entityType,
                'entity_table' => $entityTable,
                'name' => $name,
                'label' => (string) ($custom['title'] ?? $name),
                'type' => in_array($type, ['multitext', 'textarea', 'editor', 'markdown', 'html'], true) ? 'textarea' : 'input',
                'rows' => 5,
            ];
        }
        return $fields;
    }

    /** 保存某类实体在本次表单提交中的翻译字段。调用方可放在原数据事务内。 */
    public static function savePosted(string $entityType, int $entityId, array $posted, string $entityTable = '', int $channelId = 0, array $source = []): void
    {
        if ($entityId < 1) {
            return;
        }
        $enabled = self::enabledLocales();
        if ($enabled) {
            self::ensureSchema();
        } elseif (!self::hasSchema()) {
            return;
        }
        $fieldNames = self::allowedFields($entityType, $entityTable, $channelId);
        $allowedFields = array_fill_keys($fieldNames, true);
        if (!$allowedFields) {
            return;
        }
        //原文字段清空时按实体和字段直接删除全部语言记录，兼容已停用或已从语言清单移除的旧语言。
        $emptySourceFields = [];
        foreach ($fieldNames as $field) {
            if (!array_key_exists($field, $source)
                || ($source[$field] !== null && !is_scalar($source[$field]))) {
                continue;
            }
            if (trim((string) ($source[$field] ?? '')) !== '') {
                continue;
            }
            Db::name('content_i18n')->where([
                'entity_type' => $entityType,
                'entity_table' => $entityTable,
                'entity_id' => $entityId,
                'field_name' => $field,
            ])->delete();
            $emptySourceFields[$field] = true;
        }
        if (!$enabled) {
            self::clearTranslationCaches($entityType);
            return;
        }

        $locales = $enabled;
        $purifiedFields = self::purifiedFields($entityType, $entityTable, $channelId, $fieldNames);
        $stored = $enabled
            ? self::storedTranslations($entityType, $entityTable, $entityId, $locales, $fieldNames)
            : [];
        $autoTranslate = \app\services\PluginService::isAutoTranslationEnabled();

        $now = time();
        foreach ($locales as $locale) {
            $normalizedLocale = TemplateI18n::normalizeLocale($locale);
            if ($normalizedLocale === '') {
                continue;
            }
            $locale = $normalizedLocale;
            $values = $posted[$locale][$entityType] ?? [];
            if (!is_array($values)) {
                $values = [];
            }
            foreach ($fieldNames as $field) {
                if (isset($emptySourceFields[$field])) {
                    continue;
                }
                $sourceExists = array_key_exists($field, $source)
                    && ($source[$field] === null || is_scalar($source[$field]));
                $sourceValue = $sourceExists ? (string) ($source[$field] ?? '') : null;
                $where = [
                    'entity_type' => $entityType,
                    'entity_table' => $entityTable,
                    'entity_id' => $entityId,
                    'locale' => $locale,
                    'field_name' => $field,
                ];

                if (!array_key_exists($field, $values) || !is_scalar($values[$field])) {
                    continue;
                }
                $value = (string) $values[$field];
                if (isset($purifiedFields[$field])) {
                    $safe = \app\admin\model\Document::purifyContent([$field => $value]);
                    $value = (string) ($safe[$field] ?? '');
                }
                if ($entityType === 'document' && $field === 'seo_description' && mb_strlen($value) > 200) {
                    $value = mb_substr($value, 0, 200);
                }
                if (trim($value) === '') {
                    Db::name('content_i18n')->where($where)->delete();
                    continue;
                }

                $sourceHash = $sourceExists ? hash('sha256', (string) $sourceValue) : '';
                $oldRow = $stored[$locale][$field] ?? null;
                if (is_array($oldRow) && $value === (string) ($oldRow['field_value'] ?? '')
                    && (!$autoTranslate || $sourceHash === '')) {
                    //关闭自动翻译时旧译文尚未刷新，保留旧原文指纹，之后重新开启仍能检测并重译。
                    $sourceHash = (string) $oldRow['source_hash'];
                }
                $data = $where + [
                    'translation_key' => self::translationKey($entityType, $entityTable, $entityId, $locale, $field),
                    'field_value' => $value,
                    'source_hash' => $sourceHash,
                    'update_time' => $now,
                ];
                Db::name('content_i18n')->duplicate([
                    'field_value' => $value,
                    'source_hash' => $sourceHash,
                    'update_time' => $now,
                ])->insert($data);
            }
        }
        self::clearTranslationCaches($entityType);
    }

    /** 源记录物理删除时同步清理其翻译字段。 */
    public static function deleteRecord(string $entityType, int $entityId, string $entityTable = ''): void
    {
        self::deleteRecords($entityType, [$entityId], $entityTable);
    }

    /** 批量清理源记录翻译，供回收站清空等操作使用。 */
    public static function deleteRecords(string $entityType, array $entityIds, string $entityTable = ''): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $entityIds), static function ($id) {
            return $id > 0;
        })));
        if (!$ids || !self::hasSchema()) {
            return;
        }
        Db::name('content_i18n')->where([
            'entity_type' => $entityType,
            'entity_table' => $entityTable,
        ])->whereIn('entity_id', $ids)->delete();
        self::clearTranslationCaches($entityType);
    }

    /** 用当前语言翻译覆盖一组文档、栏目或标签源行。 */
    public static function overlayRows(string $entityType, array $rows): array
    {
        if (!$rows || self::locale() === self::DEFAULT_LOCALE) {
            return $rows;
        }
        $ids = [];
        foreach ($rows as $row) {
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
        }
        $map = self::loadFields($entityType, $ids, self::locale());
        foreach ($rows as &$row) {
            $translated = $map[(int) ($row['id'] ?? 0)] ?? [];
            foreach ($translated as $field => $value) {
                $row[$field] = $value;
            }
        }
        unset($row);
        return $rows;
    }

    /** 用当前语言翻译覆盖一条源行。 */
    public static function overlayRow(string $entityType, array $row): array
    {
        $rows = self::overlayRows($entityType, [$row]);
        return $rows[0] ?? $row;
    }

    /** 按当前前台语言覆盖网站名称和首页 SEO 配置；未翻译字段沿用中文原值。 */
    public static function overlaySiteConfig(array $config): array
    {
        if (self::locale() === self::DEFAULT_LOCALE) {
            return $config;
        }

        $translations = self::loadFields('site_config', [self::SITE_CONFIG_ID], self::locale())[self::SITE_CONFIG_ID] ?? [];
        foreach (self::allowedFields('site_config') as $field) {
            if (isset($translations[$field]) && is_scalar($translations[$field]) && trim((string) $translations[$field]) !== '') {
                $config[$field] = (string) $translations[$field];
            }
        }
        return $config;
    }

    /** 覆盖批量文档模型附加表里的正文和自定义字段。 */
    public static function overlayDocumentExtras(array $documents, array $extras, string $entityType = 'doc_model'): array
    {
        if (!$documents || !$extras || self::locale() === self::DEFAULT_LOCALE) {
            return $extras;
        }
        $groups = [];
        foreach ($documents as $document) {
            $id = (int) ($document['id'] ?? 0);
            $channelId = (int) ($document['channel_id'] ?? 0);
            $channel = self::channel($channelId);
            $table = (string) ($channel['table'] ?? '');
            if ($id > 0 && $table !== '') {
                $groups[$table]['ids'][$id] = $id;
                $groups[$table]['channel_ids'][$channelId] = $channelId;
            }
        }
        foreach ($groups as $table => $group) {
            $ids = array_values($group['ids']);
            $map = self::loadFields($entityType, $ids, self::locale(), (string) $table);
            foreach ($map as $id => $values) {
                if (!isset($extras[$id])) {
                    continue;
                }
                foreach ($values as $field => $value) {
                    $extras[$id][$field] = $value;
                }
            }
        }
        return $extras;
    }

    /** 覆盖一条文档/单页模型附加表数据。 */
    public static function overlayModelRow(string $entityType, string $table, int $entityId, array $row): array
    {
        if ($entityId < 1 || $table === '' || self::locale() === self::DEFAULT_LOCALE) {
            return $row;
        }
        $map = self::loadFields($entityType, [$entityId], self::locale(), $table);
        foreach (($map[$entityId] ?? []) as $field => $value) {
            $row[$field] = $value;
        }
        return $row;
    }

    /** 当前语言搜索匹配的文档 ID 子查询；没有翻译表时返回 null。 */
    public static function documentSearchQuery(string $keyword)
    {
        if ($keyword === '' || self::locale() === self::DEFAULT_LOCALE || !self::hasSchema()) {
            return null;
        }
        $like = '%' . $keyword . '%';
        $locale = self::locale();
        return static function ($query) use ($like, $locale) {
            $query->name('content_i18n')->where('entity_type', 'document')
                ->where('entity_table', '')->where('locale', $locale)
                ->whereIn('field_name', ['title', 'short_title', 'title_ext_1', 'title_ext_2', 'title_ext_3', 'seo_title', 'seo_keywords', 'seo_description'])
                ->where('field_value', 'like', $like)->field('entity_id');
        };
    }

    /** 可翻译字段白名单。 */
    public static function allowedFields(string $entityType, string $entityTable = '', int $channelId = 0): array
    {
        $fields = [
            'document' => ['title', 'short_title', 'title_ext_1', 'title_ext_2', 'title_ext_3', 'seo_title', 'seo_keywords', 'seo_description'],
            'nav' => ['title', 'title_ext_1', 'title_ext_2', 'title_ext_3', 'seo_title', 'seo_keywords', 'seo_description'],
            'tag' => ['title'],
            'custom' => ['default_value'],
            'site_config' => ['site_name', 'site_title', 'site_keywords', 'site_desc'],
        ];
        if (in_array($entityType, ['doc_model', 'nav_model'], true)) {
            $result = ['content', 'md_content'];
            if ($channelId < 1 && $entityTable !== '') {
                $channelId = self::channelIdByTable($entityTable);
            }
            if ($channelId > 0) {
                $allowedTypes = ['text', 'multitext', 'textarea', 'editor', 'markdown', 'html'];
                foreach (self::channelFieldRows($channelId) as $custom) {
                    $name = (string) ($custom['name'] ?? '');
                    if (in_array((string) ($custom['type'] ?? ''), $allowedTypes, true)
                        && preg_match('/^[a-zA-Z0-9_]{1,32}$/', $name)) {
                        $result[] = $name;
                    }
                }
            }
            return array_values(array_unique($result));
        }
        return $fields[$entityType] ?? [];
    }

    /** 富文本翻译使用与中文正文相同的 HTMLPurifier 规则。 */
    private static function purifiedFields(string $entityType, string $entityTable, int $channelId, array $fieldNames): array
    {
        if (!in_array($entityType, ['doc_model', 'nav_model'], true)) {
            return [];
        }
        $purified = array_fill_keys(array_intersect($fieldNames, ['content', 'md_content']), true);
        if ($channelId < 1 && $entityTable !== '') {
            $channelId = self::channelIdByTable($entityTable);
        }
        if ($channelId < 1) {
            return $purified;
        }
        $allowedNames = array_fill_keys($fieldNames, true);
        foreach (self::channelFieldRows($channelId) as $field) {
            $name = (string) ($field['name'] ?? '');
            if (isset($allowedNames[$name]) && in_array((string) ($field['type'] ?? ''), ['editor', 'markdown', 'html'], true)) {
                $purified[$name] = true;
            }
        }
        return $purified;
    }

    /** 批量读取并缓存实体字段，未保存的字段自然回退中文。 */
    private static function loadFields(string $entityType, array $ids, string $locale, string $entityTable = ''): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static function ($id) {
            return $id > 0;
        })));
        if (!$ids || !self::hasSchema()) {
            return [];
        }

        $result = [];
        $missing = [];
        foreach ($ids as $id) {
            $cached = get_cache(self::cacheKey($entityType, $entityTable, $id, $locale));
            if (is_array($cached)) {
                $result[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }
        if (!$missing) {
            return $result;
        }

        $grouped = [];
        $allowedFields = array_fill_keys(self::allowedFields($entityType, $entityTable), true);
        try {
            $records = Db::name('content_i18n')->where('entity_type', $entityType)
                ->where('entity_table', $entityTable)->where('locale', $locale)
                ->whereIn('entity_id', $missing)->select()->toArray();
            foreach ($records as $record) {
                $id = (int) $record['entity_id'];
                $field = (string) $record['field_name'];
                if (isset($allowedFields[$field])) {
                    $grouped[$id][$field] = (string) $record['field_value'];
                }
            }
        } catch (\Throwable $e) {
            self::logFailure('读取内容翻译失败', $e);
            return $result;
        }

        foreach ($missing as $id) {
            $fields = $grouped[$id] ?? [];
            $result[$id] = $fields;
            \think\facade\Cache::tag('contentI18n')->set(self::cacheKey($entityType, $entityTable, $id, $locale), $fields, 86400);
        }
        return $result;
    }

    private static function channel(int $channelId): array
    {
        static $cache = [];
        if ($channelId < 1) {
            return [];
        }
        if (!array_key_exists($channelId, $cache)) {
            $cacheKey = 'contentI18nChannel_' . $channelId;
            $row = get_cache($cacheKey);
            if (!is_array($row)) {
                $row = Db::name('channel')->where('id', $channelId)->find();
                $row = is_array($row) ? $row : (is_object($row) ? $row->toArray() : []);
                \think\facade\Cache::tag('homeChannel')->set($cacheKey, $row, 86400);
            }
            $cache[$channelId] = $row;
        }
        return $cache[$channelId];
    }

    private static function channelIdByTable(string $entityTable): int
    {
        $entityTable = trim($entityTable);
        if ($entityTable === '') {
            return 0;
        }
        $cacheKey = 'contentI18nChannelId_' . md5($entityTable);
        $cached = get_cache($cacheKey);
        if (is_numeric($cached)) {
            return (int) $cached;
        }
        $channelId = (int) Db::name('channel')->where('table', $entityTable)->value('id');
        \think\facade\Cache::tag('homeChannel')->set($cacheKey, $channelId, 86400);
        return $channelId;
    }

    private static function channelFieldRows(int $channelId): array
    {
        $channelId = max(0, $channelId);
        if ($channelId < 1) {
            return [];
        }
        $cacheKey = 'contentI18nChannelFields_' . $channelId;
        $rows = get_cache($cacheKey);
        if (is_array($rows)) {
            return $rows;
        }
        try {
            $rows = Db::name('channel_field')->where('channel_id', $channelId)
                ->where('status', 1)->where('is_system', 0)
                ->order('sort asc,id asc')->select()->toArray();
        } catch (\Throwable $e) {
            return [];
        }
        \think\facade\Cache::tag('homeChannel')->set($cacheKey, $rows, 86400);
        return $rows;
    }

    public static function locale(): string
    {
        return TemplateI18n::locale();
    }

    private static function hasSchema(): bool
    {
        if (self::$schemaReady) {
            return true;
        }
        $cached = get_cache('contentI18nSchemaExists');
        if (is_numeric($cached)) {
            self::$schemaReady = (int) $cached === 1;
            return self::$schemaReady;
        }
        $table = Db::name('content_i18n')->getTable();
        $quoted = str_replace("'", "''", $table);
        try {
            $rows = Db::query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = '{$quoted}' LIMIT 1");
            self::$schemaReady = !empty($rows);
        } catch (\Throwable $e) {
            self::$schemaReady = false;
        }
        \think\facade\Cache::tag('contentI18nSchema')->set('contentI18nSchemaExists', self::$schemaReady ? 1 : 0, self::$schemaReady ? 86400 : 300);
        return self::$schemaReady;
    }

    private static function translationKey(string $entityType, string $entityTable, int $entityId, string $locale, string $field): string
    {
        return hash('sha256', implode("\0", [$entityType, $entityTable, (string) $entityId, $locale, $field]));
    }

    private static function cacheKey(string $entityType, string $entityTable, int $entityId, string $locale): string
    {
        return 'contentI18n_' . md5(implode('|', [$entityType, $entityTable, (string) $entityId, $locale]));
    }

    private static function clearTranslationCaches(string $entityType): void
    {
        clear_cache('contentI18n');
        if (in_array($entityType, ['document', 'doc_model'], true)) {
            clear_cache('Document');
        } elseif ($entityType === 'nav') {
            clear_cache('homeNav');
        } elseif ($entityType === 'tag') {
            clear_cache('homeTag');
        } elseif ($entityType === 'custom') {
            clear_cache('custom');
        }
    }

    private static function logFailure(string $message, \Throwable $e): void
    {
        try {
            Log::error($message . '：' . $e->getMessage());
        } catch (\Throwable $ignored) {
            error_log($message . '：' . $e->getMessage());
        }
    }
}
