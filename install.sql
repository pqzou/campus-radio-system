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
