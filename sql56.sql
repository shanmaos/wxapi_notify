-- 域名监控系统数据库结构 (MySQL 5.6兼容版本)
-- 数据库: wxapinotify

-- 分组表
CREATE TABLE IF NOT EXISTS `domain_groups` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '分组ID',
    `name` VARCHAR(100) NOT NULL DEFAULT '' COMMENT '分组名称',
    `notify_url` VARCHAR(500) DEFAULT '' COMMENT '分组通知URL设置',
    `description` VARCHAR(255) DEFAULT '' COMMENT '分组描述',
    `sort_order` INT UNSIGNED DEFAULT 0 COMMENT '排序权重',
    `status` TINYINT UNSIGNED DEFAULT 1 COMMENT '状态：0禁用 1启用',
    `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
    PRIMARY KEY (`id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_sort` (`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='域名分组表';

-- 域名列表表
CREATE TABLE IF NOT EXISTS `domainlist` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '域名ID',
    `domain` VARCHAR(255) NOT NULL DEFAULT '' COMMENT '域名',
    `group_id` INT UNSIGNED DEFAULT 0 COMMENT '所属分组ID',
    `status` TINYINT UNSIGNED DEFAULT 1 COMMENT '状态：1正常 2红色被封 3蓝色异常 4白色被封 5无法打开 6掉备案 7404 84xx 95xx',
    `notify_status` TINYINT UNSIGNED DEFAULT 0 COMMENT '通知状态：0未通知 1正常 2红色被封 3蓝色异常 4白色被封 5无法打开 6掉备案 7404 84xx 95xx',
    `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
    PRIMARY KEY (`id`),
    INDEX `idx_domain` (`domain`(191)),
    INDEX `idx_group` (`group_id`),
    INDEX `idx_status` (`status`),
    INDEX `idx_notify_status` (`notify_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='域名列表表';

-- 系统配置表
CREATE TABLE IF NOT EXISTS `config` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT COMMENT '配置ID',
    `api_key` VARCHAR(100) DEFAULT '' COMMENT '接口Key',
    `api_url` VARCHAR(500) DEFAULT '' COMMENT '接口URL',
    `notify_api_url` VARCHAR(500) DEFAULT '' COMMENT '通知接口URL',
    `request_interval` INT UNSIGNED DEFAULT 60 COMMENT '请求间隔秒数',
    `fapi` TINYINT UNSIGNED DEFAULT 0 COMMENT '接口类型：0未知 4高级版',
    `notify_types` VARCHAR(50) DEFAULT '2' COMMENT '通知类型：可多选，用逗号分隔，如2,3,4,5,6,7,8,9（2=红色被封，3=蓝色异常，4=白色被封，5=无法打开，6=掉备案，7=404，8=4xx，9=5xx）',
    `other_config` TEXT COMMENT '其他配置(JSON格式)',
    `create_time` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '创建时间',
    `update_time` TIMESTAMP NOT NULL DEFAULT '0000-00-00 00:00:00' COMMENT '更新时间',
    PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='系统配置表';

-- 初始化默认配置数据
INSERT INTO `config` (`api_key`, `api_url`, `notify_api_url`, `request_interval`, `fapi`, `notify_types`) VALUES 
('', '', '', 3, 0, '2');