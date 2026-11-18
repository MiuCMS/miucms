-- 演示插件：安装时建的演示表（记录新发布的文档）
-- __PREFIX__ 会被替换成项目实际表前缀
CREATE TABLE IF NOT EXISTS `__PREFIX__demo_record` (
  `id` int(11) UNSIGNED NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `doc_id` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '文档ID',
  `doc_title` varchar(255) NOT NULL DEFAULT '' COMMENT '文档标题',
  `created_at` int(11) UNSIGNED NOT NULL DEFAULT 0 COMMENT '记录时间',
  PRIMARY KEY (`id`),
  KEY `idx_doc` (`doc_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='演示插件记录表';
