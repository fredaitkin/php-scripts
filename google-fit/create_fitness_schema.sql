CREATE DATABASE IF NOT EXISTS `fitness`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE `fitness`;

CREATE TABLE IF NOT EXISTS `measurements` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `date` DATETIME NOT NULL,
  `meters` INT NOT NULL,
  `walking` INT,
  `treadmill` INT,
  `biking` INT,
  `steps` INT,
  PRIMARY KEY (`id`)
);

CREATE USER IF NOT EXISTS 'fitness_user'@'localhost' IDENTIFIED BY 'change_this_password';
GRANT ALL PRIVILEGES ON `fitness`.* TO 'fitness_user'@'localhost';
FLUSH PRIVILEGES;
