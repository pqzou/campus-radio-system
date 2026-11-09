CREATE DATABASE IF NOT EXISTS `broadcast_music`
    DEFAULT CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `broadcast_music`;

CREATE TABLE IF NOT EXISTS `settings` (
    `setting_key`   VARCHAR(64)  NOT NULL,
    `setting_value` VARCHAR(255) NOT NULL,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_value`) VALUES
    ('upload_open', '1'),
    ('max_per_class', '3'),
    ('upload_opened_at', NOW());

CREATE TABLE IF NOT EXISTS `verified_uploaders` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `uid`        VARCHAR(20)  NOT NULL COMMENT 'B站用户 UID',
    `name`       VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '博主名称备注',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `majors` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(50)  NOT NULL COMMENT '专业名称',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '显示顺序',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `classes` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `major_id`   INT UNSIGNED NOT NULL COMMENT '所属专业',
    `name`       VARCHAR(50)  NOT NULL COMMENT '班级名称',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '显示顺序',
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name` (`name`),
    KEY `idx_major_order` (`major_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `articles` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`        VARCHAR(120) NOT NULL COMMENT '文章标题',
    `summary`      VARCHAR(255) NOT NULL DEFAULT '' COMMENT '文章摘要',
    `cover_url`    VARCHAR(500) NOT NULL DEFAULT '' COMMENT '封面图片地址',
    `content`      MEDIUMTEXT   NOT NULL COMMENT '文章正文',
    `content_format` VARCHAR(10) NOT NULL DEFAULT 'text' COMMENT '正文格式',
    `is_published` TINYINT(1)   NOT NULL DEFAULT 1 COMMENT '是否发布',
    `published_at` DATETIME     NULL COMMENT '发布时间',
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_published` (`is_published`, `published_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activities` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `title`       VARCHAR(150) NOT NULL,
    `subtitle`    VARCHAR(255) NOT NULL DEFAULT '',
    `cover_url`   VARCHAR(500) NOT NULL DEFAULT '',
    `content`     MEDIUMTEXT   NOT NULL,
    `is_open`     TINYINT(1)   NOT NULL DEFAULT 1,
    `deadline`    DATETIME     NULL,
    `max_entries` INT UNSIGNED NOT NULL DEFAULT 0,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_open` (`is_open`, `deadline`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_submissions` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `activity_id`   INT UNSIGNED NOT NULL,
    `name`          VARCHAR(50)  NOT NULL,
    `class_name`    VARCHAR(50)  NOT NULL,
    `student_no`    VARCHAR(50)  NOT NULL,
    `file_path`     VARCHAR(255) NOT NULL,
    `original_name` VARCHAR(255) NOT NULL,
    `file_size`     INT UNSIGNED NOT NULL DEFAULT 0,
    `mime_type`     VARCHAR(100) NOT NULL DEFAULT '',
    `form_data`     MEDIUMTEXT   NULL COMMENT '自定义字段填写内容（JSON）',
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity` (`activity_id`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `activity_fields` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `activity_id`  INT UNSIGNED NOT NULL,
    `label`        VARCHAR(80)  NOT NULL COMMENT '字段名称',
    `field_type`   VARCHAR(20)  NOT NULL DEFAULT 'text' COMMENT '字段类型',
    `required`     TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '是否必填',
    `options`      TEXT         NOT NULL COMMENT '选项，每行一个',
    `placeholder`  VARCHAR(120) NOT NULL DEFAULT '',
    `help_text`    VARCHAR(255) NOT NULL DEFAULT '',
    `sort_order`   INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at`   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_activity_order` (`activity_id`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `audit_logs` (
    `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `actor`       VARCHAR(80)  NOT NULL DEFAULT '' COMMENT '操作人',
    `actor_type`  VARCHAR(20)  NOT NULL DEFAULT 'admin' COMMENT '操作人类型',
    `action`      VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '操作类型',
    `category`    VARCHAR(30)  NOT NULL DEFAULT '' COMMENT '业务分类',
    `target_type` VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '对象类型',
    `target_id`   VARCHAR(40)  NOT NULL DEFAULT '' COMMENT '对象 ID',
    `detail`      VARCHAR(500) NOT NULL DEFAULT '' COMMENT '操作详情',
    `ip`          VARCHAR(45)  NOT NULL DEFAULT '' COMMENT '来源 IP',
    `fingerprint` VARCHAR(64)  NOT NULL DEFAULT '' COMMENT '浏览器指纹',
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_created` (`created_at`),
    KEY `idx_category` (`category`, `id`),
    KEY `idx_action` (`action`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `special_presets` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`       VARCHAR(80)  NOT NULL COMMENT '预制播音名称',
    `content`    MEDIUMTEXT   NOT NULL COMMENT '预置播稿内容',
    `sort_order` INT UNSIGNED NOT NULL DEFAULT 1,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uniq_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `special_broadcasts` (
    `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_name`     VARCHAR(50)  NOT NULL,
    `title`          VARCHAR(120) NOT NULL,
    `content`        MEDIUMTEXT   NOT NULL,
    `broadcast_date` DATE         NULL,
    `uploader_name`  VARCHAR(50)  NOT NULL DEFAULT '',
    `uploader_no`    VARCHAR(50)  NOT NULL DEFAULT '',
    `status`         VARCHAR(20)  NOT NULL DEFAULT 'pending',
    `reject_reason`  VARCHAR(255) NOT NULL DEFAULT '',
    `reviewer_name`  VARCHAR(50)  NOT NULL DEFAULT '',
    `reviewed_at`    DATETIME     NULL,
    `created_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_class` (`class_name`, `id`),
    KEY `idx_status` (`status`, `id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `songs` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `class_name`       VARCHAR(50)  NOT NULL COMMENT '班级名称',
    `uploader_name`    VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '上传人姓名',
    `uploader_no`      VARCHAR(50)  NOT NULL DEFAULT '' COMMENT '上传人学号',
    `title`            VARCHAR(100) NOT NULL COMMENT '歌曲名称',
    `artist`           VARCHAR(100) NOT NULL DEFAULT '' COMMENT '歌曲作者/演唱者',
    `bvid`             CHAR(12)     NULL COMMENT 'B站 BV 号，未匹配时为空',
    `source_input`     VARCHAR(500) NOT NULL DEFAULT '' COMMENT '播放链接来源',
    `remark`           VARCHAR(255) NOT NULL DEFAULT '' COMMENT '广播备注',
    `sort_order`       INT UNSIGNED NOT NULL DEFAULT 1 COMMENT '班内播放顺序',
    `status`           ENUM('pending', 're_review', 'approved', 'rejected') NOT NULL DEFAULT 'pending'
                       COMMENT 'pending=待审核 re_review=待重新审核 approved=已通过 rejected=未通过',
    `duration`         INT UNSIGNED NULL COMMENT '视频时长（秒），获取失败为 NULL',
    `progress_seconds` INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '上次播放位置（秒）',
    `reviewer_name`    VARCHAR(50)  NULL COMMENT '审核人姓名',
    `reviewer_no`      VARCHAR(50)  NULL COMMENT '审核人工号/学号',
    `reject_reason`    VARCHAR(255) NULL COMMENT '拒绝原因',
    `reviewed_at`      DATETIME     NULL COMMENT '审核时间',
    `completed_at`     DATETIME     NULL COMMENT '播放完成标记时间',
    `created_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_status` (`status`),
    KEY `idx_class_order` (`class_name`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
