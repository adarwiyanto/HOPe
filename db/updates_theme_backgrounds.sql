CREATE TABLE IF NOT EXISTS store_theme_backgrounds (
  id INT AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(140) NOT NULL,
  file_type ENUM('image','video') NOT NULL,
  file_path VARCHAR(255) NOT NULL,
  target_page ENUM('landing','login','both') NOT NULL DEFAULT 'both',
  is_enabled TINYINT(1) NOT NULL DEFAULT 1,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_target_enabled (target_page, is_enabled, sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO settings (`key`,`value`) VALUES ('theme_background_active_landing', '0')
  ON DUPLICATE KEY UPDATE `value`=`value`;
INSERT INTO settings (`key`,`value`) VALUES ('theme_background_active_login', '0')
  ON DUPLICATE KEY UPDATE `value`=`value`;
