SET FOREIGN_KEY_CHECKS=0;

-- ----------------------------
-- Table structure for miu_admin
-- ----------------------------
DROP TABLE IF EXISTS `miu_admin`;
CREATE TABLE `miu_admin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(100) NOT NULL DEFAULT '',
  `pwd` varchar(100) NOT NULL DEFAULT '',
  `salt` varchar(100) NOT NULL DEFAULT '',
  `nickname` varchar(255) DEFAULT '',
  `thumb` varchar(255) DEFAULT NULL,
  `theme` varchar(255) NOT NULL DEFAULT 'black' COMMENT '系统主题',
  `mobile` bigint(11) DEFAULT '0',
  `email` varchar(255) DEFAULT '',
  `desc` text COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  `last_login_time` int(11) NOT NULL DEFAULT '0',
  `login_num` int(11) NOT NULL DEFAULT '0',
  `last_login_ip` varchar(64) NOT NULL DEFAULT '',
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '1正常,0禁止登录,-1删除',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_admin
-- ----------------------------

-- ----------------------------
-- Table structure for miu_quick_nav
-- ----------------------------
DROP TABLE IF EXISTS `miu_quick_nav`;
CREATE TABLE `miu_quick_nav` (
  `admin_id` int(11) unsigned NOT NULL,
  `items` text NOT NULL,
  PRIMARY KEY (`admin_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='管理员快捷导航';

-- ----------------------------
-- Table structure for miu_admin_group
-- ----------------------------
DROP TABLE IF EXISTS `miu_admin_group`;
CREATE TABLE `miu_admin_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `status` int(1) NOT NULL DEFAULT '1',
  `rules` varchar(1000) DEFAULT '' COMMENT '用户组拥有的规则id， 多个规则","隔开',
  `desc` text COMMENT '备注',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `id` (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限分组表';

-- ----------------------------
-- Records of miu_admin_group
-- ----------------------------
INSERT INTO `miu_admin_group` VALUES ('1', '超级管理员', '1', '1,2,3,4,5,6,7,8,9,10,11,12,13,14,15,16,17,18,25,26,27,28,29,30,31,32,33,34,35,36,37,38,39,40,41,42,43,44,45,46,47,48,49,53,54,55,56,57,58,59,60,61,62,63,64,65,66,67,68,69,70,71,72,73,74,75,76,77,78,79,80,81,82,83,84,85,86,87,88,89,90,91,92,93,94,95,96,97,98,99,100,101,102,103,104,105,106,107,108,109,110,111,112,113,114,117,119,120,121,122,123,136,137,138,140,148,1000,1001,1002,1003,1004,1005,1006,1010,1011,1012,1013,1014,1015,1016,1017,1018,1019,1020,1021,1022,1023,1024,1025,1026,1027,1028,1029,1030,1031,1036,1037,1038,1039,1040,1041,1042,1043,1044,1045,1046,1047,1048,1049,1050,1052,1055,1051,1056,1057,1058,1059,1060,1061,1062,1063,1064,1065,1072,1077,1097,1099,1102,1103,1104,1105,1106,1162,1163,1164,1165,1166,1170', '超级管理员，系统自动分配所有可操作权限及菜单。', '0', '1794289216');

-- ----------------------------
-- Table structure for miu_admin_group_access
-- ----------------------------
DROP TABLE IF EXISTS `miu_admin_group_access`;
CREATE TABLE `miu_admin_group_access` (
  `uid` int(11) unsigned DEFAULT NULL,
  `group_id` int(11) DEFAULT NULL,
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  UNIQUE KEY `uid_group_id` (`uid`,`group_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='权限分组和管理员的关联表';

-- ----------------------------
-- Records of miu_admin_group_access
-- ----------------------------
INSERT INTO `miu_admin_group_access` VALUES ('1', '1', '0', '0');

-- ----------------------------
-- Table structure for miu_admin_log
-- ----------------------------
DROP TABLE IF EXISTS `miu_admin_log`;
CREATE TABLE `miu_admin_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `nickname` varchar(255) NOT NULL DEFAULT '' COMMENT '昵称',
  `type` varchar(80) NOT NULL DEFAULT '' COMMENT '操作类型',
  `action` varchar(80) NOT NULL DEFAULT '' COMMENT '操作动作',
  `subject` varchar(80) NOT NULL DEFAULT '' COMMENT '操作主体',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '操作标题',
  `content` text COMMENT '操作描述',
  `module` varchar(32) NOT NULL DEFAULT '' COMMENT '模块',
  `controller` varchar(32) NOT NULL DEFAULT '' COMMENT '控制器',
  `function` varchar(32) NOT NULL DEFAULT '' COMMENT '方法',
  `rule_menu` varchar(255) NOT NULL DEFAULT '' COMMENT '节点权限路径',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '登录ip',
  `param_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '操作数据id',
  `param` text COMMENT '参数json格式',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0删除 1正常',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='后台操作日志表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_admin_log
-- ----------------------------

-- ----------------------------
-- Table structure for miu_admin_rule
-- ----------------------------
DROP TABLE IF EXISTS `miu_admin_rule`;
CREATE TABLE `miu_admin_rule` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `pid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '父id',
  `src` varchar(255) NOT NULL DEFAULT '' COMMENT 'url链接',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '名称',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '日志操作名称',
  `icon` varchar(255) NOT NULL DEFAULT '' COMMENT '图标',
  `menu` int(1) NOT NULL DEFAULT '0' COMMENT '是否是菜单,1是,2不是',
  `sort` int(11) NOT NULL DEFAULT '1' COMMENT '越小越靠前',
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '状态,0禁用,1正常',
  `module` varchar(255) NOT NULL DEFAULT '' COMMENT '所属模块',
  `crud` varchar(255) NOT NULL DEFAULT '' COMMENT 'crud标识',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=1242 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='菜单及权限表';

-- ----------------------------
-- Records of miu_admin_rule
-- ----------------------------
INSERT INTO `miu_admin_rule` VALUES ('1', '0', '', '系统', '系统管理', 'layui-icon-set', '1', '20', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('2', '0', '', '工具', '系统工具', 'layui-icon-util', '1', '30', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('3', '0', '', '基础', '基础数据', 'layui-icon-component', '1', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('4', '0', '', '会员', '会员管理', 'layui-icon-user', '1', '10', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('13', '1', 'admin/rule/index', '功能节点', '功能节点', '', '1', '3', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('14', '13', 'admin/rule/add', '新建/编辑', '功能节点', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('15', '13', 'admin/rule/delete', '删除', '功能节点', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('16', '1', 'admin/role/index', '权限角色', '权限角色', '', '1', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('17', '16', 'admin/role/add', '新建/编辑', '权限角色新建/编辑', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('18', '16', 'admin/role/delete', '删除', '权限角色删除', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('25', '1', 'admin/admin/index', '管理员', '管理员', '', '1', '0', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('26', '25', 'admin/admin/add', '添加/修改', '系统用户', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('27', '25', 'admin/admin/view', '查看', '系统用户', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('28', '25', 'admin/admin/delete', '删除', '系统用户', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('29', '2', 'admin/log/index', '后台日志', '后台日志', '', '1', '3', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('34', '1027', 'admin/database/database', '备份数据', '数据备份', '', '1', '2', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('35', '34', 'admin/database/backup', '备份数据表', '数据', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('36', '34', 'admin/database/optimize', '优化数据表', '数据表', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('37', '34', 'admin/database/repair', '修复数据表', '数据表', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('38', '1027', 'admin/database/backuplist', '还原数据', '数据还原', '', '1', '3', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('39', '38', 'admin/database/import', '还原数据表', '数据', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('40', '38', 'admin/database/downfile', '下载备份数据', '备份数据', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('41', '38', 'admin/database/del', '删除备份数据', '备份数据', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('42', '2', 'admin/files/index', '附件管理', '附件管理', '', '1', '4', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('43', '42', 'admin/files/edit', '编辑附件', '附件', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('44', '42', 'admin/files/move', '移动附件', '附件', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('45', '42', 'admin/files/delete', '删除附件', '附件', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('46', '42', 'admin/files/get_group', '附件分组', '附件分组', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('47', '42', 'admin/files/add_group', '新建/编辑', '附件分组', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('48', '42', 'admin/files/del_group', '删除附件分组', '附件分组', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('49', '0', 'admin/nav/index', '栏目', '栏目', 'layui-icon-folder', '1', '2', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('53', '49', 'admin/nav/edit', '编辑', '栏目', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('54', '49', 'admin/nav/delete', '删除', '栏目', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('55', '1025', 'admin/seo/sitemap', 'Sitemap', 'Sitemap', '', '1', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('61', '3', 'admin/adv/index', '广告/轮播', '广告/轮播', '', '1', '10', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('62', '61', 'admin/adv/add', '新建/编辑', '广告/轮播', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('63', '61', 'admin/adv/delete', '删除', '广告/轮播', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('67', '1025', 'admin/links/index', '友情链接', '友情链接', '', '1', '3', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('68', '67', 'admin/links/add', '新建/编辑', '友情链接', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('69', '67', 'admin/links/delete', '删除', '友情链接', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('70', '3', 'admin/keywords/index', '标签管理', '标签管理', '', '1', '10', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('71', '70', 'admin/keywords/add', '新建/编辑', 'SEO关键字', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('72', '70', 'admin/keywords/delete', '删除', 'SEO关键字', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('73', '3', 'admin/search/index', '搜索关键字', '搜索关键字', '', '1', '10', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('74', '73', 'admin/search/delete', '删除', '搜索关键字', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('75', '4', 'admin/level/index', '用户等级', '用户等级', '', '1', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('76', '75', 'admin/level/add', '新建/编辑', '用户等级', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('77', '75', 'admin/level/disable', '禁用/启用', '用户等级', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('78', '4', 'admin/user/index', '用户管理', '用户', '', '1', '0', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('79', '78', 'admin/user/edit', '编辑', '用户信息', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('80', '78', 'admin/user/view', '查看', '用户信息', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('81', '78', 'admin/user/disable', '禁用/启用', '用户', '', '2', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('83', '4', 'admin/user/log', '操作日志', '用户操作日志', '', '1', '1', '1', '', '', '0', '0');
INSERT INTO `miu_admin_rule` VALUES ('117', '13', 'admin/rule/edit_field', '编辑排序', '节点排序', '', '2', '1', '1', '', '', '1788961236', '0');
INSERT INTO `miu_admin_rule` VALUES ('119', '1', 'admin/channel/index', '模型管理', '模型管理', '', '1', '2', '1', '', '', '1789036585', '0');
INSERT INTO `miu_admin_rule` VALUES ('120', '119', 'admin/channel/add', '新建/编辑', '模型', '', '2', '1', '1', '', '', '1789036716', '0');
INSERT INTO `miu_admin_rule` VALUES ('121', '119', 'admin/channel/delete', '删除', '模型', '', '2', '1', '1', '', '', '1789036804', '0');
INSERT INTO `miu_admin_rule` VALUES ('122', '119', 'admin/channel/field_add', '新建/编辑字段', '模型字段', '', '2', '1', '1', '', '', '1789037140', '0');
INSERT INTO `miu_admin_rule` VALUES ('123', '119', 'admin/channel/field_delete', '删除字段', '模型字段', '', '2', '1', '1', '', '', '1789037184', '0');
INSERT INTO `miu_admin_rule` VALUES ('136', '49', 'admin/nav/add', '新增', '栏目', '', '2', '1', '1', '', '', '1789206228', '0');
INSERT INTO `miu_admin_rule` VALUES ('137', '49', 'admin/nav/edit_field', '排序', '栏目', '', '2', '0', '1', '', '', '1789216212', '0');
INSERT INTO `miu_admin_rule` VALUES ('138', '119', 'admin/channel/field', '模型字段', '模型字段', '', '2', '1', '1', '', '', '1789239570', '0');
INSERT INTO `miu_admin_rule` VALUES ('140', '119', 'admin/channel/edit_field', '修改', '模型', '', '2', '0', '1', '', '', '1789408198', '0');
INSERT INTO `miu_admin_rule` VALUES ('1000', '0', 'admin/document/index', '内容', '内容', 'layui-icon-read', '1', '4', '1', '', '', '1789490181', '0');
INSERT INTO `miu_admin_rule` VALUES ('1001', '1000', 'admin/document/index_lite', '选择文档', '文档', '', '2', '0', '1', '', '', '1789547548', '0');
INSERT INTO `miu_admin_rule` VALUES ('1002', '1000', 'admin/document/add', '新建', '文档', '', '2', '0', '1', '', '', '1789571823', '0');
INSERT INTO `miu_admin_rule` VALUES ('1003', '1000', 'admin/document/edit', '编辑', '文档', '', '2', '0', '1', '', '', '1789571842', '0');
INSERT INTO `miu_admin_rule` VALUES ('1004', '1000', 'admin/document/read', '查看', '文档', '', '2', '0', '1', '', '', '1789571856', '0');
INSERT INTO `miu_admin_rule` VALUES ('1005', '1000', 'admin/document/delete', '删除', '文档', '', '2', '0', '1', '', '', '1789571874', '0');
INSERT INTO `miu_admin_rule` VALUES ('1006', '1000', 'admin/document/edit_field', '排序', '文档', '', '2', '0', '1', '', '', '1789666480', '0');
INSERT INTO `miu_admin_rule` VALUES ('1015', '3', 'admin/conf/index', '网站配置', '网站配置', '', '1', '0', '1', '', '', '1790431960', '0');
INSERT INTO `miu_admin_rule` VALUES ('1016', '49', 'admin/nav/content', '单页内容', '栏目', '', '2', '2', '1', '', '', '1790530288', '0');
INSERT INTO `miu_admin_rule` VALUES ('1017', '1000', 'admin/document/recycle', '文档回收站', '文档', '', '2', '0', '1', '', '', '1790569284', '0');
INSERT INTO `miu_admin_rule` VALUES ('1018', '1000', 'admin/document/restore', '恢复', '文档', '', '2', '0', '1', '', '', '1790569443', '0');
INSERT INTO `miu_admin_rule` VALUES ('1019', '49', 'admin/nav/recycle', '回收站', '栏目', '', '2', '0', '1', '', '', '1790570944', '0');
INSERT INTO `miu_admin_rule` VALUES ('1020', '49', 'admin/nav/restore', '恢复', '栏目', '', '2', '0', '1', '', '', '1790570944', '0');
INSERT INTO `miu_admin_rule` VALUES ('1021', '1000', 'admin/document/purge', '批量彻底删除', '文档', '', '2', '0', '1', '', '', '1790574104', '0');
INSERT INTO `miu_admin_rule` VALUES ('1022', '49', 'admin/nav/purge', '彻底删除', '栏目', '', '2', '0', '1', '', '', '1790574104', '0');
INSERT INTO `miu_admin_rule` VALUES ('1023', '1000', 'admin/document/clear', '清空回收站', '文档', '', '2', '0', '1', '', '', '1790575008', '0');
INSERT INTO `miu_admin_rule` VALUES ('1024', '49', 'admin/nav/clear', '清空回收站', '栏目', '', '2', '0', '1', '', '', '1790575008', '0');
INSERT INTO `miu_admin_rule` VALUES ('1025', '0', '', 'SEO', 'SEO', 'miuai-seo', '1', '8', '1', '', '', '1790661749', '0');
INSERT INTO `miu_admin_rule` VALUES ('1026', '1025', 'admin/seo/index', 'URL配置', 'URL配置', '', '1', '0', '1', '', '', '1790662060', '0');
INSERT INTO `miu_admin_rule` VALUES ('1027', '2', '', '数据库备份', '数据库备份', '', '1', '2', '1', '', '', '1790662277', '0');
INSERT INTO `miu_admin_rule` VALUES ('1028', '3', 'admin/custom/index', '自定义变量', '自定义变量', '', '1', '1', '1', '', '', '1790785636', '0');
INSERT INTO `miu_admin_rule` VALUES ('1029', '1028', 'admin/custom/add', '添加/编辑', '自定义变量', '', '2', '0', '1', '', '', '1790786776', '0');
INSERT INTO `miu_admin_rule` VALUES ('1030', '1028', 'admin/custom/edit_field', '编辑', '自定义变量', '', '2', '0', '1', '', '', '1790789101', '0');
INSERT INTO `miu_admin_rule` VALUES ('1031', '1025', 'admin/seo/createhtml', '页面生成', '页面生成', '', '1', '2', '1', '', '', '1790868054', '0');
INSERT INTO `miu_admin_rule` VALUES ('1036', '0', '', '留言', '留言', 'layui-icon-dialogue', '1', '9', '1', '', '', '1791044107', '0');
INSERT INTO `miu_admin_rule` VALUES ('1037', '1036', 'admin/form/index', '表单管理', '留言', '', '1', '0', '1', '', '', '1791044206', '0');
INSERT INTO `miu_admin_rule` VALUES ('1038', '1036', 'admin/form/message', '留言管理', '留言', '', '1', '0', '1', '', '', '1791044291', '0');
INSERT INTO `miu_admin_rule` VALUES ('1039', '1037', 'admin/form/add', '添加/编辑', '留言', '', '2', '0', '1', '', '', '1791044321', '0');
INSERT INTO `miu_admin_rule` VALUES ('1040', '1037', 'admin/form/delete', '删除', '留言', '', '2', '0', '1', '', '', '1791044351', '0');
INSERT INTO `miu_admin_rule` VALUES ('1041', '1036', 'admin/form/field', '表单字段列表', '留言表单', '', '2', '0', '1', '', '', '1791044412', '0');
INSERT INTO `miu_admin_rule` VALUES ('1042', '1041', 'admin/form/field_add', '添加/编辑字段', '留言表单字段', '', '2', '0', '1', '', '', '1791044462', '0');
INSERT INTO `miu_admin_rule` VALUES ('1043', '1041', 'admin/form/field_delete', '表单字段删除', '表单字段', '', '2', '0', '1', '', '', '1791044514', '0');
INSERT INTO `miu_admin_rule` VALUES ('1044', '1036', 'admin/form/message_read', '标记已读', '留言', '', '2', '0', '1', '', '', '1791046691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1045', '1036', 'admin/form/message_delete', '删除留言', '留言', '', '2', '0', '1', '', '', '1791046691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1046', '1036', 'admin/form/message_star', '星标留言', '留言', '', '2', '0', '1', '', '', '1791046691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1047', '1036', 'admin/form/message_reply', '回复留言', '留言', '', '2', '0', '1', '', '', '1791046691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1048', '1036', 'admin/form/message_examine', '审核留言', '留言', '', '2', '0', '1', '', '', '1791046691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1050', '0', '', '插件', '插件', 'layui-icon-app', '1', '15', '1', '', '', '1791139691', '0');
INSERT INTO `miu_admin_rule` VALUES ('1051', '1050', 'admin/plugin/index', '插件管理', '插件管理', '', '1', '0', '1', '', '', '1791200001', '0');
INSERT INTO `miu_admin_rule` VALUES ('1055', '1050', 'admin/plugin/hooks', '钩子管理', '钩子管理', '', '1', '1', '1', '', '', '1791172005', '0');
INSERT INTO `miu_admin_rule` VALUES ('1056', '1051', 'admin/plugin/install', '安装插件', '安装插件', '', '2', '0', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1057', '1051', 'admin/plugin/uninstall', '卸载插件', '卸载插件', '', '2', '1', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1058', '1051', 'admin/plugin/enable', '启用插件', '启用插件', '', '2', '2', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1059', '1051', 'admin/plugin/disable', '禁用插件', '禁用插件', '', '2', '3', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1060', '1051', 'admin/plugin/config', '插件配置', '插件配置', '', '2', '4', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1061', '1051', 'admin/plugin/save_config', '保存配置', '保存配置', '', '2', '5', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1062', '1051', 'admin/plugin/upload', '上传插件', '上传插件', '', '2', '6', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1063', '1051', 'admin/plugin/run', '运行插件页', '运行插件页', '', '2', '7', '1', '', '', '1791172256', '0');
INSERT INTO `miu_admin_rule` VALUES ('1064', '1051', 'admin/plugin/dev', '插件开发', '插件开发', '', '2', '100', '1', '', '', '1791181743', '0');
INSERT INTO `miu_admin_rule` VALUES ('1065', '1051', 'admin/plugin/make', '生成插件骨架', '生成插件骨架', '', '2', '101', '1', '', '', '1791181743', '0');
INSERT INTO `miu_admin_rule` VALUES ('1072', '1051', 'admin/plugin/add_menu', '添加后台菜单', '添加后台菜单', '', '2', '100', '1', '', '', '1791185224', '0');
INSERT INTO `miu_admin_rule` VALUES ('1077', '1051', 'admin/plugin/remove_menu', '删除后台菜单', '删除后台菜单', '', '2', '101', '1', '', '', '1791185657', '0');
INSERT INTO `miu_admin_rule` VALUES ('1097', '2', 'admin/tplmanage/index', '模板管理', '模板管理', '', '1', '5', '1', '', '', '1791221051', '0');
INSERT INTO `miu_admin_rule` VALUES ('1099', '1097', 'admin/tplmanage/api', '模板文件操作', '模板管理', '', '2', '0', '1', '', '', '1791228725', '0');
INSERT INTO `miu_admin_rule` VALUES ('1102', '70', 'admin/keywords/group', '分组管理', 'SEO关键字', '', '2', '1', '1', '', '', '1791361544', '0');
INSERT INTO `miu_admin_rule` VALUES ('1103', '70', 'admin/keywords/group_add', '分组新建/编辑', 'SEO关键字', '', '2', '1', '1', '', '', '1791361544', '0');
INSERT INTO `miu_admin_rule` VALUES ('1104', '70', 'admin/keywords/group_delete', '分组删除', 'SEO关键字', '', '2', '1', '1', '', '', '1791361544', '0');
INSERT INTO `miu_admin_rule` VALUES ('1105', '70', 'admin/keywords/move', '批量移动', 'SEO关键字', '', '2', '1', '1', '', '', '1791361939', '0');
INSERT INTO `miu_admin_rule` VALUES ('1106', '70', 'admin/keywords/edit_field', '列表内改分组', 'SEO关键字', '', '2', '1', '1', '', '', '1791363263', '0');
INSERT INTO `miu_admin_rule` VALUES ('1162', '1051', 'admin/plugin/export', '导出插件', '插件', '', '2', '0', '1', '', '', '1791733496', '0');
INSERT INTO `miu_admin_rule` VALUES ('1163', '3', 'admin/multilanguage/index', '国际化', '国际化', '', '1', '0', '1', '', '', '1791814631', '0');
INSERT INTO `miu_admin_rule` VALUES ('1164', '1028', 'admin/custom/delete', '删除', '自定义变量', '', '2', '0', '1', '', '', '1792702723', '0');
INSERT INTO `miu_admin_rule` VALUES ('1165', '0', 'admin/template/setting', '模板', '模板', 'layui-icon-template', '1', '17', '1', '', '', '1793099231', '0');
INSERT INTO `miu_admin_rule` VALUES ('1166', '1', 'admin/index/upgrade', '系统更新', '系统更新', '', '1', '4', '1', '', '', '1793614514', '0');
INSERT INTO `miu_admin_rule` VALUES ('1170', '1000', 'admin/document/draft', '草稿箱', '文档', '', '2', '0', '1', '', '', '1793802581', '0');

-- ----------------------------
-- Table structure for miu_adv
-- ----------------------------
DROP TABLE IF EXISTS `miu_adv`;
CREATE TABLE `miu_adv` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '',
  `type` tinyint(1) NOT NULL DEFAULT '1',
  `mark` varchar(64) NOT NULL DEFAULT '' COMMENT '标识',
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '1可用-1禁用',
  `content` longtext,
  `desc` varchar(1000) DEFAULT NULL,
  `add_time` int(11) DEFAULT '0',
  `update_time` int(11) DEFAULT '0',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='广告表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_adv
-- ----------------------------

-- ----------------------------
-- Table structure for miu_article
-- ----------------------------
DROP TABLE IF EXISTS `miu_article`;
CREATE TABLE `miu_article` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '主表document ID',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文章模型附加表 cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_article
-- ----------------------------

-- ----------------------------
-- Table structure for miu_channel
-- ----------------------------
DROP TABLE IF EXISTS `miu_channel`;
CREATE TABLE `miu_channel` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `nid` varchar(32) NOT NULL DEFAULT '' COMMENT '识别id',
  `title` varchar(32) DEFAULT '' COMMENT '名称',
  `ntitle` varchar(32) DEFAULT '' COMMENT '左侧菜单名称',
  `table` varchar(32) DEFAULT '' COMMENT '表名',
  `ctl_name` varchar(32) DEFAULT '' COMMENT '控制器名称（区分大小写）',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(1=启用，0=屏蔽)',
  `is_default` tinyint(1) DEFAULT '0' COMMENT '是否默认模型',
  `is_system` tinyint(1) DEFAULT '0' COMMENT '字段分类，1=系统(不可修改)，0=自定义',
  `is_repeat_title` tinyint(1) DEFAULT '1' COMMENT '文档标题重复，1=允许，0=不允许',
  `is_release` tinyint(1) DEFAULT '0' COMMENT '模型是否允许应用于会员投稿发布，1是，0否',
  `is_litpic_users_release` tinyint(1) DEFAULT '1' COMMENT '缩略图是否应用于会员投稿，1=允许，0=不允许',
  `data` text COMMENT '额外序列化存储数据',
  `is_delete` tinyint(1) DEFAULT '0' COMMENT '伪删除，1=是，0=否',
  `sort` smallint(6) DEFAULT '1' COMMENT '排序',
  `add_time` int(11) DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT NULL COMMENT '删除时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `idention` (`nid`) USING BTREE,
  UNIQUE KEY `ctl_name` (`ctl_name`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=10 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统模型表';

-- ----------------------------
-- Records of miu_channel
-- ----------------------------
INSERT INTO `miu_channel` VALUES ('1', 'article', '文章模型', '文章模型', 'article', 'Article', '1', '1', '1', '1', '0', '1', '', '0', '1', '1789227108', '1789450434', null);
INSERT INTO `miu_channel` VALUES ('2', 'image', '图集模型', '图集模型', 'image', 'Image', '1', '0', '1', '1', '0', '1', '', '0', '1', '1789228836', '1789450476', null);
INSERT INTO `miu_channel` VALUES ('3', 'product', '产品模型', '产品模型', 'product', 'Product', '1', '0', '1', '1', '0', '1', '', '0', '1', '1790231455', '0', null);
INSERT INTO `miu_channel` VALUES ('4', 'download', '下载模型', '下载模型', 'download', 'Download', '1', '0', '1', '1', '0', '1', '', '0', '1', '1790253445', '0', null);
INSERT INTO `miu_channel` VALUES ('5', 'recruit', '招聘模型', '招聘模型', 'recruit', 'Recruit', '1', '0', '1', '1', '0', '1', '', '0', '1', '1790268945', '0', null);
INSERT INTO `miu_channel` VALUES ('6', 'special', '专题模型', '专题模型', 'special', 'Special', '1', '0', '1', '1', '0', '1', '', '0', '1', '1790333555', '0', null);
INSERT INTO `miu_channel` VALUES ('7', 'single', '单页模型', '单页模型', 'single', 'Single', '1', '0', '1', '1', '0', '1', '', '0', '1', '1790530561', '0', null);
INSERT INTO `miu_channel` VALUES ('8', 'clue', '留言模型', '留言模型', 'clue', 'Clue', '1', '0', '1', '1', '0', '1', null, '0', '1', '1791045377', '0', null);
INSERT INTO `miu_channel` VALUES ('9', 'video', '视频模型', '视频模型', 'video', 'Video', '1', '0', '1', '1', '0', '1', null, '0', '1', '1792322449', '0', null);

-- ----------------------------
-- Table structure for miu_channel_field
-- ----------------------------
DROP TABLE IF EXISTS `miu_channel_field`;
CREATE TABLE `miu_channel_field` (
  `id` int(10) NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(32) NOT NULL DEFAULT '' COMMENT '字段标识',
  `channel_id` int(10) NOT NULL DEFAULT '0' COMMENT '所属文档模型id',
  `title` varchar(250) NOT NULL DEFAULT '' COMMENT '字段名称',
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT '字段类型',
  `define` text NOT NULL COMMENT '字段定义',
  `maxlength` int(10) NOT NULL DEFAULT '0' COMMENT '最大长度，文本数据必须填写，大于255为text类型',
  `default_value` text NOT NULL COMMENT '默认值',
  `tips` varchar(256) NOT NULL DEFAULT '' COMMENT '提示说明',
  `is_screening` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否应用于条件筛选',
  `is_multiselect` tinyint(1) NOT NULL DEFAULT '0' COMMENT '筛选多选开关',
  `is_release` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否应用于会员投稿发布',
  `is_require` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否必填',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '字段分类，1=系统(不可修改)，0=自定义',
  `is_important` tinyint(1) DEFAULT '0' COMMENT '是否重要，为1时不允许删除',
  `sort` int(5) NOT NULL DEFAULT '1' COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `set_type` tinyint(3) DEFAULT '0' COMMENT '区域选择时使用是否为三级联动,1-是',
  `unit` varchar(10) DEFAULT NULL COMMENT '默认单位',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=1087 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义字段表';

-- ----------------------------
-- Records of miu_channel_field
-- ----------------------------
INSERT INTO `miu_channel_field` VALUES ('1', 'add_time', '0', '新增时间', 'datetime', 'int(11)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533091575', '1533091575', '0', '');
INSERT INTO `miu_channel_field` VALUES ('2', 'update_time', '0', '更新时间', 'datetime', 'int(11)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533091601', '1533091601', '0', '');
INSERT INTO `miu_channel_field` VALUES ('3', 'aid', '0', '文档ID', 'int', 'int(11)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533091624', '1533091624', '0', '');
INSERT INTO `miu_channel_field` VALUES ('4', 'nav_id', '0', '当前栏目ID', 'int', 'int(11)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533091930', '1533091930', '0', '');
INSERT INTO `miu_channel_field` VALUES ('5', 'channel_id', '0', '模型ID', 'int', 'int(11)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092214', '1533092214', '0', '');
INSERT INTO `miu_channel_field` VALUES ('6', 'is_b', '0', '是否加粗', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092246', '1533092246', '0', '');
INSERT INTO `miu_channel_field` VALUES ('7', 'title', '0', '文档标题', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092381', '1533092381', '0', '');
INSERT INTO `miu_channel_field` VALUES ('8', 'litpic', '0', '缩略图', 'img', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092398', '1533092398', '0', '');
INSERT INTO `miu_channel_field` VALUES ('9', 'is_head', '0', '是否头条', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092420', '1533092420', '0', '');
INSERT INTO `miu_channel_field` VALUES ('10', 'is_special', '0', '是否特荐', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092439', '1533092439', '0', '');
INSERT INTO `miu_channel_field` VALUES ('11', 'is_top', '0', '是否置顶', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092454', '1533092454', '0', '');
INSERT INTO `miu_channel_field` VALUES ('12', 'is_recom', '0', '是否推荐', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092468', '1533092468', '0', '');
INSERT INTO `miu_channel_field` VALUES ('13', 'is_jump', '0', '是否跳转', 'switch', 'tinyint(1)', '250', '0', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092484', '1533092484', '0', '');
INSERT INTO `miu_channel_field` VALUES ('14', 'author', '0', '编辑者', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092498', '1533092498', '0', '');
INSERT INTO `miu_channel_field` VALUES ('15', 'click', '0', '浏览量', 'int', 'int(11)', '250', '1', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092512', '1533092512', '0', '');
INSERT INTO `miu_channel_field` VALUES ('16', 'arcrank', '0', '阅读权限', 'select', 'tinyint(1)', '250', '1', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092534', '1533092534', '0', '');
INSERT INTO `miu_channel_field` VALUES ('17', 'jumplinks', '0', '跳转链接', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092553', '1533092553', '0', '');
INSERT INTO `miu_channel_field` VALUES ('19', 'seo_title', '0', 'SEO标题', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092713', '1533092713', '0', '');
INSERT INTO `miu_channel_field` VALUES ('20', 'seo_keywords', '0', 'SEO关键词', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092725', '1533092725', '0', '');
INSERT INTO `miu_channel_field` VALUES ('21', 'seo_description', '0', 'SEO描述', 'multitext', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092739', '1533092739', '0', '');
INSERT INTO `miu_channel_field` VALUES ('22', 'status', '0', '状态', 'switch', 'tinyint(1)', '250', '1', '', '0', '0', '0', '0', '1', '0', '0', '1', '1533092753', '1533092753', '0', '');
INSERT INTO `miu_channel_field` VALUES ('23', 'sort', '0', '排序号', 'int', 'int(11)', '250', '0', '越小越靠前', '0', '0', '0', '0', '1', '0', '0', '1', '1533092766', '1533092766', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1068', 'imgs', '2', '图片集', 'imgs', 'text', '250', '', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790229125', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1069', 'imgs', '3', '图片集', 'imgs', 'text', '250', '', '建议尺寸：800*800像素，可以拖拽图片调整顺序', '0', '0', '0', '0', '0', '1', '100', '1', '1790231956', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1070', 'price', '3', '产品价格', 'decimal', 'float(18,2)', '250', '', '元', '0', '0', '0', '0', '0', '1', '100', '1', '1790232019', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1071', 'buy_url', '3', '购买地址', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790232090', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1072', 'download', '4', '上传文件', 'download', 'text', '250', '', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790253532', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1073', 'word_location', '5', '工作地点', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790269253', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1074', 'educational', '5', '学历要求', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790269309', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1075', 'salary', '5', '薪资待遇', 'radio', 'varchar(255)', '250', '1000-3000|3000-5000|5000-8000|8000-10000|10000-15000|15000-20000|20000+', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790269447', '1790270530', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1076', 'working_years', '5', '工作年限', 'radio', 'varchar(255)', '250', '无要求|1-3年|3-5年|5-10年|10年+', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790269551', '1790269563', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1077', 'recruit_num', '5', '招聘人数', 'radio', 'varchar(255)', '250', '1-5人|5-10人|10-30人|30+', '', '0', '0', '0', '0', '0', '1', '100', '1', '1790269665', '1790330276', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1079', 'videos', '9', '视频集', 'video', 'mediumtext', '250', '', '视频播放地址，每行填写一集，格式：集数$播放地址', '0', '0', '0', '0', '0', '1', '100', '1', '1792322890', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1080', 'size', '4', '软件大小', 'text', 'varchar(255)', '250', '', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400511', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1081', 'language', '4', '软件语言', 'select', 'varchar(255)', '250', '简体中文|繁体中文|英文', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400573', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1082', 'softtype', '4', '软件类型', 'select', 'varchar(255)', '250', '国产软件|国外软件', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400613', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1083', 'authorization', '4', '软件授权', 'select', 'varchar(255)', '250', '免费软件|付费软件|部分功能免费', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400704', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1084', 'updatetime', '4', '更新时间', 'datetime', 'varchar(32)', '250', '', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400734', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1085', 'typecate', '4', '软件类别', 'select', 'varchar(255)', '250', '网络软件|系统工具|应用软件|联络聊天|图形图像|多媒体类|游戏娱乐|安全相关|教育教学|手机软件', '', '0', '0', '0', '0', '0', '0', '100', '1', '1792400802', '0', '0', '');
INSERT INTO `miu_channel_field` VALUES ('1086', 'authorurl', '4', '开发者官网', 'text', 'varchar(255)', '250', 'https://', 'https://', '0', '0', '0', '0', '0', '0', '100', '1', '1792400895', '1792434292', '0', '');

-- ----------------------------
-- Table structure for miu_clue
-- ----------------------------
DROP TABLE IF EXISTS `miu_clue`;
CREATE TABLE `miu_clue` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='clue模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_clue
-- ----------------------------

-- ----------------------------
-- Table structure for miu_config
-- ----------------------------
DROP TABLE IF EXISTS `miu_config`;
CREATE TABLE `miu_config` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '配置名称',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '配置标识',
  `content` text COMMENT '配置内容',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：-1删除 0禁用 1启用',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=6 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- ----------------------------
-- Records of miu_config
-- ----------------------------
INSERT INTO `miu_config` VALUES ('1', '网站配置', 'global', 'a:73:{s:11:\"admin_title\";s:9:\"米柚CMS\";s:7:\"version\";s:4:\"1.01\";s:9:\"site_name\";s:9:\"米柚CMS\";s:11:\"site_domain\";s:0:\"\";s:9:\"site_logo\";s:86:\"https://miucms.oss-cn-beijing.aliyuncs.com/202611/ace2bf49081725cfd7a7e2df1c0217ae.png\";s:8:\"site_ico\";s:12:\"/favicon.ico\";s:8:\"site_icp\";s:0:\"\";s:10:\"site_beian\";s:0:\"\";s:9:\"site_code\";s:0:\"\";s:14:\"site_copyright\";s:37:\"© 2026 MiuCMS 米柚cms 版权所有\";s:10:\"site_title\";s:9:\"米柚CMS\";s:13:\"site_keywords\";s:16:\"miucms,米柚cms\";s:9:\"site_desc\";s:60:\"米柚CMS是一个轻量级·现代化 内容管理系统。\";s:10:\"admin_path\";s:5:\"admin\";s:11:\"site_status\";s:1:\"1\";s:16:\"site_status_mode\";s:1:\"2\";s:16:\"site_status_text\";s:31:\"网站暂时关闭，维护中!\";s:15:\"site_status_url\";s:0:\"\";s:15:\"site_status_tpl\";s:21:\"public/tpl/close.html\";s:10:\"is_recycle\";s:1:\"1\";s:19:\"upload_max_filesize\";s:2:\"10\";s:6:\"editor\";s:1:\"1\";s:15:\"is_random_click\";s:1:\"1\";s:24:\"random_click_scope_start\";s:3:\"200\";s:22:\"random_click_scope_end\";s:3:\"500\";s:21:\"editor_img_clear_link\";s:1:\"0\";s:28:\"editor_img_download_to_local\";s:1:\"0\";s:14:\"session_expire\";s:5:\"86400\";s:4:\"smtp\";s:11:\"smtp.qq.com\";s:9:\"smtp_port\";s:3:\"465\";s:9:\"smtp_user\";s:0:\"\";s:8:\"smtp_pwd\";s:0:\"\";s:4:\"from\";s:15:\"系统管理员\";s:5:\"email\";s:0:\"\";s:14:\"email_template\";s:159:\"<p><span style=\"color: #e03e2d;\">米柚CMS是一套基于ThinkPHP8 + Layui + MySql打造的轻量级、高性能快速建站的内容管理系统。</span></p>\";s:5:\"token\";s:0:\"\";s:14:\"login_back_url\";s:0:\"\";s:5:\"appid\";s:0:\"\";s:9:\"appsecret\";s:0:\"\";s:5:\"mchid\";s:0:\"\";s:11:\"secrect_key\";s:0:\"\";s:8:\"cert_url\";s:0:\"\";s:12:\"pay_back_url\";s:0:\"\";s:9:\"xcx_appid\";s:0:\"\";s:13:\"xcx_appsecret\";s:0:\"\";s:3:\"iss\";s:0:\"\";s:3:\"aud\";s:6:\"miucms\";s:7:\"secrect\";s:0:\"\";s:7:\"exptime\";s:4:\"3600\";s:12:\"cache_driver\";s:4:\"file\";s:12:\"url_no_index\";i:1;s:12:\"url_del_home\";i:1;s:11:\"url_del_nav\";i:1;s:12:\"url_del_side\";i:1;s:31:\"editor_img_clear_link_whitelist\";s:0:\"\";s:9:\"stat_open\";s:1:\"0\";s:23:\"password_reset_template\";s:869:\"<div style=\"max-width: 600px; margin: 0 auto; padding: 32px; color: #303630; font: 15px/1.8 Arial,\'Microsoft YaHei\',sans-serif;\">\n<h2 style=\"margin: 0 0 20px;\">重置登录密码</h2>\n<p>你好，%username%：</p>\n<p>我们收到了重置 %site_name% 会员账号密码的请求。请点击下面的链接设置新密码：</p>\n<p style=\"margin: 26px 0;\"><a style=\"display: inline-block; padding: 11px 22px; background: #26352b; color: #fff; text-decoration: none;\" href=\"%reset_url%\">重置密码</a></p>\n<p>如果按钮无法打开，请复制下面的链接到浏览器中：</p>\n<p style=\"word-break: break-all; color: #596159;\">%reset_url_text%</p>\n<p>链接将在 %expire_minutes% 分钟后失效，且只能使用一次。</p>\n<p>如果这不是你发起的请求，请忽略这封邮件。</p>\n<p style=\"margin-top: 28px; color: #858a85;\">%site_name%</p>\n</div>\";s:21:\"register_email_verify\";i:0;s:23:\"register_email_template\";s:556:\"<div style=\"max-width: 600px; margin: 0 auto; padding: 32px; color: #303630; font: 15px/1.8 Arial,\'Microsoft YaHei\',sans-serif;\">\n<h2 style=\"margin: 0 0 20px;\">邮箱验证码</h2>\n<p>这是你在 %site_name% 发送的邮件验证码。</p>\n<p>邮箱：%email%</p>\n<p style=\"font-size: 28px; font-weight: bold; letter-spacing: 8px;\">%code%</p>\n<p>验证码 %expire_minutes% 分钟内有效，请勿转发给他人。</p>\n<p>如果这不是你发起的请求，请忽略这封邮件。</p>\n<p style=\"margin-top: 28px; color: #858a85;\">%site_name%</p>\n</div>\";s:21:\"email_verify_template\";s:556:\"<div style=\"max-width: 600px; margin: 0 auto; padding: 32px; color: #303630; font: 15px/1.8 Arial,\'Microsoft YaHei\',sans-serif;\">\n<h2 style=\"margin: 0 0 20px;\">邮箱验证码</h2>\n<p>这是你在 %site_name% 发送的邮件验证码。</p>\n<p>邮箱：%email%</p>\n<p style=\"font-size: 28px; font-weight: bold; letter-spacing: 8px;\">%code%</p>\n<p>验证码 %expire_minutes% 分钟内有效，请勿转发给他人。</p>\n<p>如果这不是你发起的请求，请忽略这封邮件。</p>\n<p style=\"margin-top: 28px; color: #858a85;\">%site_name%</p>\n</div>\";s:13:\"user_log_open\";i:0;s:12:\"storage_type\";s:5:\"local\";s:10:\"oss_region\";s:14:\"oss-cn-beijing\";s:10:\"oss_bucket\";s:0:\"\";s:17:\"oss_access_key_id\";s:0:\"\";s:21:\"oss_access_key_secret\";s:0:\"\";s:12:\"oss_endpoint\";s:0:\"\";s:10:\"oss_domain\";s:0:\"\";s:10:\"cos_region\";s:10:\"ap-beijing\";s:10:\"cos_bucket\";s:0:\"\";s:13:\"cos_secret_id\";s:0:\"\";s:14:\"cos_secret_key\";s:0:\"\";s:10:\"cos_domain\";s:0:\"\";}', '1', '1612514630', '1794319644');
INSERT INTO `miu_config` VALUES ('2', 'SEO配置', 'seo', 'a:21:{s:10:\"url_pseudo\";i:3;s:15:\"url_html_arcdir\";s:4:\"html\";s:17:\"url_html_listname\";i:2;s:17:\"url_html_pagename\";i:2;s:18:\"url_rewrite_format\";i:1;s:23:\"url_rewrite_view_format\";i:4;s:21:\"url_liststitle_format\";i:2;s:20:\"url_viewtitle_format\";i:2;s:16:\"url_title_symbol\";s:1:\"-\";s:12:\"url_pub_home\";i:1;s:11:\"url_pub_nav\";i:1;s:12:\"url_pub_side\";i:1;s:13:\"url_edit_home\";i:1;s:12:\"url_edit_nav\";i:1;s:13:\"url_edit_side\";i:1;s:12:\"url_del_home\";i:1;s:11:\"url_del_nav\";i:1;s:12:\"url_del_side\";i:1;s:12:\"url_no_index\";i:1;s:16:\"url_html_tagname\";i:1;s:19:\"url_tagtitle_format\";i:2;}', '1', '1790736994', '1793561503');
INSERT INTO `miu_config` VALUES ('3', 'sitemap', 'sitemap', 'a:17:{s:11:\"sitemap_xml\";i:1;s:11:\"sitemap_txt\";i:0;s:12:\"sitemap_html\";i:0;s:12:\"sitemap_auto\";i:1;s:12:\"sitemap_not1\";i:0;s:12:\"sitemap_not2\";i:1;s:24:\"sitemap_changefreq_index\";s:5:\"daily\";s:23:\"sitemap_changefreq_list\";s:6:\"hourly\";s:23:\"sitemap_changefreq_view\";s:5:\"daily\";s:22:\"sitemap_priority_index\";s:3:\"1.0\";s:21:\"sitemap_priority_list\";s:3:\"0.8\";s:21:\"sitemap_priority_view\";s:3:\"0.5\";s:20:\"sitemap_archives_num\";i:100;s:16:\"sitemap_tags_num\";i:100;s:20:\"sitemap_zzbaidutoken\";s:0:\"\";s:18:\"sitemap_baidu_push\";i:0;s:12:\"sitemap_llms\";i:0;}', '1', '1790872592', '1794319965');
INSERT INTO `miu_config` VALUES ('4', '前台多语言', 'multilanguage', 'a:2:{s:9:\"templates\";a:4:{s:7:\"default\";a:1:{i:0;s:5:\"zh-cn\";}s:3:\"new\";a:3:{i:0;s:5:\"zh-cn\";i:1;s:5:\"zh-hk\";i:2;s:2:\"en\";}s:4:\"new2\";a:3:{i:0;s:5:\"zh-cn\";i:1;s:5:\"zh-hk\";i:2;s:2:\"en\";}s:7:\"general\";a:3:{i:0;s:5:\"zh-cn\";i:1;s:5:\"zh-hk\";i:2;s:2:\"en\";}}s:8:\"defaults\";a:4:{s:7:\"default\";s:5:\"zh-cn\";s:3:\"new\";s:5:\"zh-cn\";s:4:\"new2\";s:5:\"zh-cn\";s:7:\"general\";s:5:\"zh-cn\";}}', '1', '1791816702', '1794319818');
INSERT INTO `miu_config` VALUES ('5', '模板配置', 'template', 'a:1:{s:7:\"current\";s:7:\"default\";}', '1', '1793100573', '1794319422');

-- ----------------------------
-- Table structure for miu_content_i18n
-- ----------------------------
DROP TABLE IF EXISTS `miu_content_i18n`;
CREATE TABLE `miu_content_i18n` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `translation_key` char(64) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '翻译记录唯一键',
  `entity_type` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '实体类型（document/nav/doc_model/nav_model/tag/custom/site_config）',
  `entity_table` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '模型附加表名，非模型实体为空',
  `entity_id` bigint(20) unsigned NOT NULL COMMENT '原记录ID',
  `locale` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '语言代码',
  `field_name` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '被翻译字段名',
  `field_value` longtext COLLATE utf8mb4_unicode_ci COMMENT '翻译后的字段内容',
  `source_hash` char(64) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '翻译对应的原文字段SHA-256',
  `update_time` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '更新时间（Unix时间戳）',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uniq_translation_key` (`translation_key`),
  KEY `idx_entity_locale` (`entity_type`,`entity_table`,`entity_id`,`locale`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='前台内容多语言翻译表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_content_i18n
-- ----------------------------

-- ----------------------------
-- Table structure for miu_custom
-- ----------------------------
DROP TABLE IF EXISTS `miu_custom`;
CREATE TABLE `miu_custom` (
  `id` int(10) NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `name` varchar(32) NOT NULL DEFAULT '' COMMENT '字段标识',
  `title` varchar(250) NOT NULL DEFAULT '' COMMENT '字段名称',
  `type` varchar(32) NOT NULL DEFAULT '' COMMENT '变量类型',
  `define` text NOT NULL COMMENT '字段定义',
  `maxlength` int(10) NOT NULL DEFAULT '0' COMMENT '最大长度，文本数据必须填写，大于255为text类型',
  `default_value` text NOT NULL COMMENT '默认值',
  `tips` varchar(256) NOT NULL DEFAULT '' COMMENT '提示说明',
  `sort` int(5) NOT NULL DEFAULT '1' COMMENT '排序',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='自定义变量表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_custom
-- ----------------------------

-- ----------------------------
-- Table structure for miu_document
-- ----------------------------
DROP TABLE IF EXISTS `miu_document`;
CREATE TABLE `miu_document` (
  `id` int(10) NOT NULL AUTO_INCREMENT,
  `nav_id` int(10) NOT NULL DEFAULT '0' COMMENT '当前栏目',
  `sub_nav_ids` varchar(255) DEFAULT '' COMMENT '副栏目ID集合，逗号隔开，前后加逗号',
  `channel_id` int(10) NOT NULL DEFAULT '0' COMMENT '模型ID',
  `title` varchar(500) NOT NULL DEFAULT '' COMMENT '文档标题',
  `short_title` varchar(255) DEFAULT NULL COMMENT '短标题',
  `title_ext_1` varchar(255) DEFAULT '' COMMENT '扩展标题1',
  `title_ext_2` varchar(255) DEFAULT '',
  `title_ext_3` varchar(255) DEFAULT '',
  `litpic` varchar(255) DEFAULT '' COMMENT '封面图片',
  `title_color` varchar(16) DEFAULT '' COMMENT '标题颜色，为空为不设置',
  `is_original` tinyint(1) DEFAULT '0' COMMENT '是否原创',
  `is_head` tinyint(1) DEFAULT '0' COMMENT '头条（0=否，1=是）',
  `is_special` tinyint(1) DEFAULT '0' COMMENT '特荐（0=否，1=是）',
  `is_b` tinyint(1) DEFAULT '0' COMMENT '加粗',
  `is_top` tinyint(1) DEFAULT '0' COMMENT '置顶（0=否，1=是）',
  `is_recommend` tinyint(1) DEFAULT '0' COMMENT '推荐（0=否，1=是）',
  `is_jump` tinyint(1) DEFAULT '0' COMMENT '跳转链接（0=否，1=是）',
  `jumplinks` varchar(255) DEFAULT '' COMMENT '跳转网址',
  `is_litpic` tinyint(1) DEFAULT '0' COMMENT '有图（0=否，1=是）',
  `from` varchar(64) DEFAULT '' COMMENT '文章来源',
  `from_url` varchar(255) DEFAULT '' COMMENT '来源url',
  `author` varchar(64) DEFAULT '' COMMENT '作者',
  `click` int(10) DEFAULT '0' COMMENT '点击数',
  `arcrank` int(10) DEFAULT '0' COMMENT '阅读权限：0=开放浏览，-1=待审核稿件，1=普通会员可见',
  `seo_title` varchar(500) DEFAULT '' COMMENT 'SEO标题',
  `seo_keywords` varchar(500) DEFAULT '' COMMENT 'SEO关键词',
  `seo_description` text COMMENT 'SEO描述',
  `status` tinyint(1) DEFAULT '1' COMMENT '状态(0=屏蔽，1=正常)',
  `sort` int(10) DEFAULT '0' COMMENT '排序号',
  `lang` varchar(50) DEFAULT 'cn' COMMENT '语言标识',
  `admin_id` int(10) DEFAULT '0' COMMENT '管理员ID',
  `arc_level_id` int(10) DEFAULT '0' COMMENT '文档会员权限ID',
  `restric_type` tinyint(1) DEFAULT '0' COMMENT '限制模式，0=免费，1=付费，2=会员专享，3=会员付费，4=会员积分购买',
  `is_del` tinyint(1) DEFAULT '0' COMMENT '伪删除，1=是，0=否',
  `appraise` int(10) DEFAULT '0' COMMENT '评价数',
  `collection` int(10) DEFAULT '0' COMMENT '收藏数',
  `tag_ids` varchar(255) DEFAULT '' COMMENT 'tagid集',
  `add_time` int(11) DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT '0' COMMENT '逻辑删除时间，和is_del相关，时间大于0=删除',
  `release_time` int(11) DEFAULT NULL COMMENT '发布时间，用户自己设置的发布时间',
  PRIMARY KEY (`id`),
  KEY `add_time` (`add_time`) USING BTREE,
  KEY `id` (`id`),
  KEY `delete_time` (`delete_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文档主表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_document
-- ----------------------------

-- ----------------------------
-- Table structure for miu_download
-- ----------------------------
DROP TABLE IF EXISTS `miu_download`;
CREATE TABLE `miu_download` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT '0' COMMENT '删除时间',
  `download` text COMMENT '上传文件',
  `size` varchar(255) DEFAULT NULL COMMENT '软件大小',
  `language` varchar(255) DEFAULT NULL COMMENT '软件语言',
  `softtype` varchar(255) DEFAULT NULL COMMENT '软件类型',
  `authorization` varchar(255) DEFAULT NULL COMMENT '软件授权',
  `updatetime` varchar(32) DEFAULT NULL COMMENT '更新时间',
  `typecate` varchar(255) DEFAULT NULL COMMENT '软件类别',
  `authorurl` varchar(255) DEFAULT NULL COMMENT '开发者官网',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_download
-- ----------------------------

-- ----------------------------
-- Table structure for miu_field_type
-- ----------------------------
DROP TABLE IF EXISTS `miu_field_type`;
CREATE TABLE `miu_field_type` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(32) DEFAULT NULL,
  `title` varchar(32) DEFAULT NULL,
  `status` tinyint(11) DEFAULT '1',
  `sort` int(11) DEFAULT NULL,
  `default` tinyint(1) DEFAULT '0' COMMENT '是否默认',
  `define` varchar(255) DEFAULT NULL COMMENT '字段定义',
  `is_require_value` tinyint(1) DEFAULT '0' COMMENT 'channel_field表是否要填写默认值',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=19 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------
-- Records of miu_field_type
-- ----------------------------
INSERT INTO `miu_field_type` VALUES ('1', 'text', '单行文本', '1', '1', '1', 'varchar(255)', '0');
INSERT INTO `miu_field_type` VALUES ('2', 'multitext', '多行文本', '1', '2', '0', 'text', '0');
INSERT INTO `miu_field_type` VALUES ('3', 'switch', '开关', '1', '6', '0', 'tinyint(1)', '0');
INSERT INTO `miu_field_type` VALUES ('4', 'select', '下拉框', '1', '5', '0', 'varchar(255)', '1');
INSERT INTO `miu_field_type` VALUES ('5', 'img', '单张图', '1', '9', '0', 'varchar(255)', '0');
INSERT INTO `miu_field_type` VALUES ('6', 'int', '整数类型', '1', '11', '0', 'int(11)', '0');
INSERT INTO `miu_field_type` VALUES ('7', 'datetime', '日期和时间', '1', '7', '0', 'varchar(32)', '0');
INSERT INTO `miu_field_type` VALUES ('8', 'htmltext', 'HTML文本', '1', '2', '0', 'text', '0');
INSERT INTO `miu_field_type` VALUES ('9', 'imgs', '多张图', '1', '9', '0', 'mediumtext', '0');
INSERT INTO `miu_field_type` VALUES ('10', 'decimal', '金额类型', '1', '10', '0', 'float(18,2)', '0');
INSERT INTO `miu_field_type` VALUES ('11', 'float', '小数类型', '1', '11', '0', 'float(18,2)', '0');
INSERT INTO `miu_field_type` VALUES ('12', 'region', '区域类型', '0', '12', '0', 'varchar(255)', '0');
INSERT INTO `miu_field_type` VALUES ('13', 'file', '附件类型', '1', '13', '0', 'varchar(255)', '0');
INSERT INTO `miu_field_type` VALUES ('14', 'media', '多媒体类型', '1', '14', '0', 'varchar(255)', '0');
INSERT INTO `miu_field_type` VALUES ('15', 'checkbox', '多选项', '1', '4', '0', 'varchar(255)', '1');
INSERT INTO `miu_field_type` VALUES ('16', 'radio', '单选项', '1', '3', '0', 'varchar(255)', '1');
INSERT INTO `miu_field_type` VALUES ('17', 'download', '文件下载', '1', '17', '0', 'text', '0');
INSERT INTO `miu_field_type` VALUES ('18', 'video', '视频播放', '1', '18', '0', 'mediumtext', '0');

-- ----------------------------
-- Table structure for miu_file
-- ----------------------------
DROP TABLE IF EXISTS `miu_file`;
CREATE TABLE `miu_file` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `module` varchar(15) NOT NULL DEFAULT '' COMMENT '所属模块',
  `sha1` varchar(60) NOT NULL COMMENT 'sha1',
  `md5` varchar(60) NOT NULL COMMENT 'md5',
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '原始文件名',
  `filename` varchar(255) NOT NULL DEFAULT '' COMMENT '文件名',
  `filepath` varchar(255) NOT NULL DEFAULT '' COMMENT '文件路径+文件名',
  `filesize` int(10) NOT NULL DEFAULT '0' COMMENT '文件大小',
  `fileext` varchar(10) NOT NULL DEFAULT '' COMMENT '文件后缀',
  `mimetype` varchar(100) NOT NULL DEFAULT '' COMMENT '文件类型',
  `group_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '文件分组ID',
  `user_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '上传会员ID',
  `uploadip` varchar(15) NOT NULL DEFAULT '' COMMENT '上传IP',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '0未审核1已审核-1不通过',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `admin_id` int(11) NOT NULL COMMENT '审核者id',
  `delete_time` int(11) NOT NULL DEFAULT '0' COMMENT '删除时间',
  `audit_time` int(11) NOT NULL DEFAULT '0' COMMENT '审核时间',
  `action` varchar(50) NOT NULL DEFAULT '' COMMENT '来源模块功能',
  `use` varchar(255) DEFAULT NULL COMMENT '用处',
  `download` int(11) NOT NULL DEFAULT '0' COMMENT '下载量',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_file
-- ----------------------------

-- ----------------------------
-- Table structure for miu_file_group
-- ----------------------------
DROP TABLE IF EXISTS `miu_file_group`;
CREATE TABLE `miu_file_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '分组名',
  `admin_id` int(10) unsigned NOT NULL DEFAULT '0' COMMENT '创建人',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) NOT NULL DEFAULT '0' COMMENT '删除时间',
  `is_system` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否系统分组，系统分组不能删除',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='文件分组表';

-- ----------------------------
-- Records of miu_file_group
-- ----------------------------
INSERT INTO `miu_file_group` VALUES ('1', '默认分组', '0', '0', '0', '0', '1');
INSERT INTO `miu_file_group` VALUES ('2', '会员头像', '0', '0', '0', '0', '1');

-- ----------------------------
-- Table structure for miu_form
-- ----------------------------
DROP TABLE IF EXISTS `miu_form`;
CREATE TABLE `miu_form` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '表单ID',
  `nav_id` int(11) DEFAULT '0' COMMENT '留言模型的栏目id',
  `form_name` varchar(255) NOT NULL DEFAULT '' COMMENT '表单名称',
  `intro` text COMMENT '表单描述，预留',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '表单状态，0关闭，1开启',
  `attr_auto` tinyint(1) DEFAULT '0' COMMENT '自动标签：0=否，1=是',
  `lang` varchar(10) NOT NULL DEFAULT 'cn' COMMENT '语言标识',
  `open_reply` tinyint(1) DEFAULT '0' COMMENT '是否开启回复 0-未开启,1-开启',
  `open_examine` tinyint(1) DEFAULT '0' COMMENT '是否开启审核 0-不审核,1-审核',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单管理表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_form
-- ----------------------------

-- ----------------------------
-- Table structure for miu_form_field
-- ----------------------------
DROP TABLE IF EXISTS `miu_form_field`;
CREATE TABLE `miu_form_field` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '字段ID',
  `form_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '所属表单ID',
  `attr_name` varchar(60) DEFAULT '' COMMENT '字段名称',
  `attr_input_type` tinyint(1) unsigned DEFAULT '0' COMMENT '控件类型：0=单行文本 1=下拉框 2=多行文本 3=单选框 4=多选框 5=单张图 6=手机号码 7=Email邮箱 8=附件 9=区域联动 10=时间类型 11=多张图',
  `attr_values` text COMMENT '可选值列表（一行一个）',
  `is_showlist` tinyint(1) DEFAULT '0' COMMENT '在留言列表显示 0=隐藏，1=显示',
  `required` tinyint(1) DEFAULT '0' COMMENT '必填 0=否，1=是',
  `validate_type` smallint(5) DEFAULT '0' COMMENT '验证格式：0=不验证 6=手机 7=Email',
  `sort_order` int(11) unsigned DEFAULT '0' COMMENT '排序',
  `is_del` tinyint(1) DEFAULT '0' COMMENT '是否已删除，0=否，1=是',
  `add_time` int(11) DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `form_id` (`form_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='表单字段表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_form_field
-- ----------------------------

-- ----------------------------
-- Table structure for miu_form_message
-- ----------------------------
DROP TABLE IF EXISTS `miu_form_message`;
CREATE TABLE `miu_form_message` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `form_id` int(11) DEFAULT '0' COMMENT '表单ID',
  `users_id` int(11) DEFAULT '0' COMMENT '用户id',
  `md5data` varchar(50) DEFAULT '' COMMENT '提交内容序列化后的MD5，用于去重',
  `ip` varchar(255) DEFAULT '' COMMENT 'ip地址',
  `is_read` tinyint(1) DEFAULT '0' COMMENT '0=未读，1=已读',
  `is_star` tinyint(1) DEFAULT '0' COMMENT '标记星号',
  `source` tinyint(1) DEFAULT '0' COMMENT '提交来源：1=电脑端，2=手机端',
  `lang` varchar(50) DEFAULT 'cn' COMMENT '语言标识',
  `reply` varchar(1000) DEFAULT '' COMMENT '留言回复内容',
  `admin_id` int(11) DEFAULT '0' COMMENT '回复管理员ID',
  `reply_time` int(11) DEFAULT '0' COMMENT '回复时间',
  `examine` tinyint(1) DEFAULT '1' COMMENT '0-未审核 1-审核通过 2-审核不通过',
  `add_time` int(11) DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `form_id` (`form_id`) USING BTREE,
  KEY `is_read` (`is_read`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言主表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_form_message
-- ----------------------------

-- ----------------------------
-- Table structure for miu_form_message_attr
-- ----------------------------
DROP TABLE IF EXISTS `miu_form_message_attr`;
CREATE TABLE `miu_form_message_attr` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '自增ID',
  `aid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '留言id',
  `attr_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '字段id',
  `attr_value` text COMMENT '字段值',
  `add_time` int(11) DEFAULT '0' COMMENT '新增时间',
  `update_time` int(11) DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  KEY `aid` (`aid`) USING BTREE,
  KEY `attr_id` (`attr_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='留言字段值表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_form_message_attr
-- ----------------------------

-- ----------------------------
-- Table structure for miu_image
-- ----------------------------
DROP TABLE IF EXISTS `miu_image`;
CREATE TABLE `miu_image` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT '手机版内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT '0' COMMENT '删除 时间',
  `imgs` mediumtext COMMENT '图片集',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_image
-- ----------------------------

-- ----------------------------
-- Table structure for miu_keywords
-- ----------------------------
DROP TABLE IF EXISTS `miu_keywords`;
CREATE TABLE `miu_keywords` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '关键字名称',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `click` int(11) NOT NULL DEFAULT '0' COMMENT '点击数',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态：-1删除 0禁用 1启用',
  `group_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '所属分组id，0=未分组',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB AUTO_INCREMENT=15 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='关键字表';

-- ----------------------------
-- Records of miu_keywords
-- ----------------------------
INSERT INTO `miu_keywords` VALUES ('1', '米柚CMS', '0', '4', '1', '3', '0', '0');

-- ----------------------------
-- Table structure for miu_keywords_group
-- ----------------------------
DROP TABLE IF EXISTS `miu_keywords_group`;
CREATE TABLE `miu_keywords_group` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '分组名称',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `add_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='标签分组表';

-- ----------------------------
-- Records of miu_keywords_group
-- ----------------------------
INSERT INTO `miu_keywords_group` VALUES ('1', '默认分组', '0', '1791362325', '0');

-- ----------------------------
-- Table structure for miu_links
-- ----------------------------
DROP TABLE IF EXISTS `miu_links`;
CREATE TABLE `miu_links` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(255) NOT NULL DEFAULT '' COMMENT '网站标题',
  `logo` varchar(255) NOT NULL DEFAULT '' COMMENT '网站logo',
  `src` varchar(255) DEFAULT NULL COMMENT '链接',
  `target` int(1) NOT NULL DEFAULT '1' COMMENT '是否新窗口打开，1是,0否',
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '状态:1可用-1禁用',
  `sort` int(11) NOT NULL DEFAULT '0' COMMENT '排序',
  `create_time` int(11) NOT NULL DEFAULT '0',
  `update_time` int(11) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=2 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='友情链接';

-- ----------------------------
-- Records of miu_links
-- ----------------------------
INSERT INTO `miu_links` VALUES ('1', '米柚CMS', '0', 'https://www.miucms.com', '1', '1', '1', '1624516962', '1624517078');

-- ----------------------------
-- Table structure for miu_nav
-- ----------------------------
DROP TABLE IF EXISTS `miu_nav`;
CREATE TABLE `miu_nav` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `pid` int(11) NOT NULL DEFAULT '0',
  `channel_id` int(11) DEFAULT NULL COMMENT '模型ID',
  `title` varchar(255) DEFAULT '',
  `title_en` varchar(255) DEFAULT NULL,
  `title_ext_1` varchar(255) DEFAULT NULL COMMENT '扩展标题',
  `title_ext_2` varchar(255) DEFAULT NULL COMMENT '扩展标题2',
  `title_ext_3` varchar(255) DEFAULT NULL,
  `litpic` varchar(255) DEFAULT '' COMMENT '缩略图',
  `temp_list` varchar(128) NOT NULL DEFAULT '' COMMENT '列表页模板',
  `temp_view` varchar(128) NOT NULL DEFAULT '' COMMENT '内容页模板',
  `seo_title` varchar(255) DEFAULT '',
  `seo_keywords` varchar(255) DEFAULT '',
  `seo_description` varchar(255) DEFAULT '',
  `is_url` tinyint(1) DEFAULT '0' COMMENT '是否外部链接',
  `url` varchar(255) DEFAULT NULL COMMENT '外链URL',
  `is_show` tinyint(1) DEFAULT '1' COMMENT '是否隐藏栏目 1正常 0隐藏',
  `is_del` tinyint(1) DEFAULT '0' COMMENT '是否删除，1=删除',
  `param` varchar(255) DEFAULT NULL,
  `target` int(1) DEFAULT '0' COMMENT '是否新窗口打开,默认0,1新窗口打开',
  `status` int(1) DEFAULT '1' COMMENT '1可用,0禁用',
  `sort` int(11) DEFAULT '0',
  `add_time` int(11) DEFAULT '0',
  `update_time` int(11) DEFAULT '0',
  `delete_time` int(11) DEFAULT '0' COMMENT '逻辑删除时间，和is_del相关',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='导航详情表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_nav
-- ----------------------------

-- ----------------------------
-- Table structure for miu_plugin
-- ----------------------------
DROP TABLE IF EXISTS `miu_plugin`;
CREATE TABLE `miu_plugin` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `name` varchar(50) NOT NULL DEFAULT '' COMMENT '插件标识（目录名）',
  `title` varchar(100) NOT NULL DEFAULT '' COMMENT '插件名称',
  `version` varchar(20) NOT NULL DEFAULT '1.0.0' COMMENT '版本号',
  `author` varchar(50) NOT NULL DEFAULT '' COMMENT '作者',
  `description` text COMMENT '插件描述',
  `config` text COMMENT '插件配置(JSON)',
  `rule_ids` varchar(1000) NOT NULL DEFAULT '' COMMENT '插件注册的后台菜单节点ID(逗号分隔)',
  `installed` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已安装',
  `status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否启用',
  `install_time` int(11) unsigned DEFAULT '0' COMMENT '安装时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_name` (`name`),
  KEY `idx_status` (`status`),
  KEY `idx_installed` (`installed`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='插件表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_plugin
-- ----------------------------

-- ----------------------------
-- Table structure for miu_product
-- ----------------------------
DROP TABLE IF EXISTS `miu_product`;
CREATE TABLE `miu_product` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT '手机版内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT '0' COMMENT '删除时间',
  `imgs` mediumtext COMMENT '图片集',
  `price` float(18,2) DEFAULT '0.00' COMMENT '产品价格',
  `buy_url` varchar(255) DEFAULT NULL COMMENT '购买地址',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_product
-- ----------------------------

-- ----------------------------
-- Table structure for miu_recruit
-- ----------------------------
DROP TABLE IF EXISTS `miu_recruit`;
CREATE TABLE `miu_recruit` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) DEFAULT NULL,
  `word_location` varchar(255) DEFAULT NULL COMMENT '工作地点',
  `educational` varchar(255) DEFAULT NULL COMMENT '学历要求',
  `salary` varchar(255) DEFAULT NULL COMMENT '薪资待遇',
  `working_years` varchar(255) DEFAULT NULL COMMENT '工作年限',
  `recruit_num` varchar(255) DEFAULT NULL COMMENT '招聘人数',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_recruit
-- ----------------------------

-- ----------------------------
-- Table structure for miu_search_keywords
-- ----------------------------
DROP TABLE IF EXISTS `miu_search_keywords`;
CREATE TABLE `miu_search_keywords` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '关键字',
  `times` int(11) NOT NULL DEFAULT '1' COMMENT '搜索次数',
  `type` tinyint(4) NOT NULL DEFAULT '1' COMMENT '1,2',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='搜索关键字表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_search_keywords
-- ----------------------------

-- ----------------------------
-- Table structure for miu_single
-- ----------------------------
DROP TABLE IF EXISTS `miu_single`;
CREATE TABLE `miu_single` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id，单页模型为栏目id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='single模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_single
-- ----------------------------

-- ----------------------------
-- Table structure for miu_special
-- ----------------------------
DROP TABLE IF EXISTS `miu_special`;
CREATE TABLE `miu_special` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `special_nodes` longtext COMMENT '专题节点及节点文档列表(JSON)',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='special模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_special
-- ----------------------------

-- ----------------------------
-- Table structure for miu_user
-- ----------------------------
DROP TABLE IF EXISTS `miu_user`;
CREATE TABLE `miu_user` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '用户ID',
  `nickname` varchar(255) NOT NULL DEFAULT '' COMMENT '用户微信昵称',
  `nickname_a` varchar(255) NOT NULL DEFAULT '' COMMENT '用户微信昵称16进制',
  `username` varchar(100) NOT NULL DEFAULT '' COMMENT '账号',
  `password` varchar(100) NOT NULL DEFAULT '' COMMENT '密码',
  `salt` varchar(100) NOT NULL DEFAULT '' COMMENT '密码盐',
  `name` varchar(100) NOT NULL DEFAULT '' COMMENT '真实姓名',
  `mobile` varchar(20) NOT NULL DEFAULT '' COMMENT '手机（也可以作为登录账号)',
  `mobile_status` tinyint(1) NOT NULL DEFAULT '0' COMMENT '手机绑定状态： 0未绑定 1已绑定',
  `email` varchar(50) NOT NULL DEFAULT '' COMMENT '邮箱',
  `headimgurl` varchar(255) NOT NULL DEFAULT '' COMMENT '微信头像',
  `sex` tinyint(1) NOT NULL DEFAULT '0' COMMENT '性别 0:未知 1:女 2:男 ',
  `desc` varchar(1000) NOT NULL DEFAULT '' COMMENT '个人简介',
  `birthday` int(11) DEFAULT '0' COMMENT '生日',
  `country` varchar(20) NOT NULL DEFAULT '' COMMENT '国家',
  `province` varchar(20) NOT NULL DEFAULT '' COMMENT '省',
  `city` varchar(20) NOT NULL DEFAULT '' COMMENT '城市',
  `company` varchar(100) NOT NULL DEFAULT '' COMMENT '公司',
  `address` varchar(100) NOT NULL DEFAULT '' COMMENT '公司地址',
  `depament` varchar(20) NOT NULL DEFAULT '' COMMENT '部门',
  `position` varchar(20) NOT NULL DEFAULT '' COMMENT '职位',
  `puid` int(11) NOT NULL DEFAULT '0' COMMENT '推荐人ID,默认是0',
  `qrcode_invite` int(11) NOT NULL DEFAULT '0' COMMENT '邀请场景二维码id',
  `level` tinyint(1) NOT NULL DEFAULT '1' COMMENT '等级  默认是普通会员',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '状态  -1删除 0禁用 1正常',
  `last_login_time` int(11) NOT NULL DEFAULT '0' COMMENT '最后登录时间',
  `last_login_ip` varchar(64) NOT NULL DEFAULT '' COMMENT '最后登录IP',
  `login_num` int(11) NOT NULL DEFAULT '0',
  `register_time` int(11) NOT NULL DEFAULT '0' COMMENT '注册时间',
  `register_ip` varchar(64) NOT NULL DEFAULT '' COMMENT '注册IP',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '信息更新时间',
  `wx_platform` int(11) NOT NULL DEFAULT '0' COMMENT '首次注册来自于哪个微信平台',
  `username_unique` varchar(100) GENERATED ALWAYS AS (nullif(`username`,'')) STORED,
  `active_email_unique` varchar(50) GENERATED ALWAYS AS ((case when ((`status` <> -(1)) and (`email` <> '')) then `email` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_user_username_unique` (`username_unique`),
  UNIQUE KEY `uq_user_active_email` (`active_email_unique`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_user
-- ----------------------------

-- ----------------------------
-- Table structure for miu_user_collect
-- ----------------------------
DROP TABLE IF EXISTS `miu_user_collect`;
CREATE TABLE `miu_user_collect` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '收藏ID',
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `doc_id` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '文档ID',
  `create_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '收藏时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `uid_doc_id` (`uid`,`doc_id`),
  KEY `doc_id` (`doc_id`),
  KEY `uid_create_time` (`uid`,`create_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会员收藏表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_user_collect
-- ----------------------------

-- ----------------------------
-- Table structure for miu_user_level
-- ----------------------------
DROP TABLE IF EXISTS `miu_user_level`;
CREATE TABLE `miu_user_level` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `title` varchar(255) NOT NULL DEFAULT '' COMMENT '等级名称',
  `desc` varchar(1000) DEFAULT NULL,
  `status` int(1) NOT NULL DEFAULT '1' COMMENT '状态:0禁用,1正常',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) NOT NULL DEFAULT '0' COMMENT '更新时间',
  PRIMARY KEY (`id`) USING BTREE
) ENGINE=InnoDB AUTO_INCREMENT=4 DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会员等级表';

-- ----------------------------
-- Records of miu_user_level
-- ----------------------------
INSERT INTO `miu_user_level` VALUES ('1', '注册会员', '', '1', '0', '1792598556');
INSERT INTO `miu_user_level` VALUES ('2', '中级会员', '', '1', '0', '1789796704');
INSERT INTO `miu_user_level` VALUES ('3', '高级会员', '', '1', '0', '1789796704');

-- ----------------------------
-- Table structure for miu_user_log
-- ----------------------------
DROP TABLE IF EXISTS `miu_user_log`;
CREATE TABLE `miu_user_log` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT 'ID',
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '用户ID',
  `nickname` varchar(255) NOT NULL DEFAULT '' COMMENT '昵称',
  `type` varchar(80) NOT NULL DEFAULT '' COMMENT '操作类型',
  `title` varchar(80) NOT NULL DEFAULT '' COMMENT '操作标题',
  `content` text COMMENT '操作描述',
  `module` varchar(32) NOT NULL DEFAULT '' COMMENT '模块',
  `controller` varchar(32) NOT NULL DEFAULT '' COMMENT '控制器',
  `function` varchar(32) NOT NULL DEFAULT '' COMMENT '方法',
  `ip` varchar(64) NOT NULL DEFAULT '' COMMENT '登录ip',
  `browser` varchar(255) DEFAULT '' COMMENT '浏览器标识',
  `os` varchar(255) DEFAULT '' COMMENT '操作系统',
  `url` text COMMENT '访问页面链接',
  `param_id` int(11) unsigned NOT NULL COMMENT '操作ID',
  `param` text COMMENT '参数json格式',
  `status` tinyint(1) NOT NULL DEFAULT '1' COMMENT '0删除 1正常',
  `create_time` int(11) NOT NULL DEFAULT '0' COMMENT '创建时间',
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='用户操作日志表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_user_log
-- ----------------------------

-- ----------------------------
-- Table structure for miu_user_password_reset
-- ----------------------------
DROP TABLE IF EXISTS `miu_user_password_reset`;
CREATE TABLE `miu_user_password_reset` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT COMMENT '记录ID',
  `uid` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '会员ID',
  `email` varchar(50) NOT NULL DEFAULT '' COMMENT '申请时绑定邮箱',
  `token_hash` char(64) NOT NULL DEFAULT '' COMMENT '重置令牌哈希',
  `expire_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '过期时间',
  `create_time` int(11) unsigned NOT NULL DEFAULT '0' COMMENT '申请时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `token_hash` (`token_hash`),
  KEY `uid` (`uid`),
  KEY `expire_time` (`expire_time`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='会员密码重置表' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_user_password_reset
-- ----------------------------

-- ----------------------------
-- Table structure for miu_video
-- ----------------------------
DROP TABLE IF EXISTS `miu_video`;
CREATE TABLE `miu_video` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `doc_id` int(11) NOT NULL DEFAULT '0' COMMENT '所属文档id',
  `content` text COMMENT '内容',
  `md_content` text COMMENT 'markdown编辑器内容',
  `add_time` int(11) unsigned DEFAULT '0' COMMENT '创建时间',
  `update_time` int(11) unsigned DEFAULT '0' COMMENT '更新时间',
  `delete_time` int(11) unsigned DEFAULT '0' COMMENT '删除时间',
  `videos` mediumtext COMMENT '视频集',
  PRIMARY KEY (`id`),
  KEY `doc_id` (`doc_id`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='video模型附加表,cms创建' AUTO_INCREMENT=1;

-- ----------------------------
-- Records of miu_video
-- ----------------------------
