-- phpMyAdmin SQL Dump
-- version 5.2.0
-- https://www.phpmyadmin.net/
--
-- Хост: 127.0.0.1:3306
-- Время создания: Апр 11 2025 г., 19:08
-- Версия сервера: 8.0.30
-- Версия PHP: 7.4.30

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- База данных: `service_desk_db`
--

-- --------------------------------------------------------

--
-- Структура таблицы `categories`
--

CREATE TABLE `categories` (
  `id` int NOT NULL,
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Название категории',
  `is_active` tinyint(1) DEFAULT '1' COMMENT '1 - активна, 0 - не активна'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Категории заявок';

--
-- Дамп данных таблицы `categories`
--

INSERT INTO `categories` (`id`, `name`, `is_active`) VALUES
(1, 'Клининг', 1),
(2, 'Электрика', 1),
(3, 'Сантехника', 1),
(4, 'ИТ-поддержка', 1),
(6, 'Срочный клининг', 0);

-- --------------------------------------------------------

--
-- Структура таблицы `executor_categories`
--

CREATE TABLE `executor_categories` (
  `user_id` int NOT NULL COMMENT 'ID исполнителя',
  `category_id` int NOT NULL COMMENT 'ID категории'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Связь исполнителей с категориями, которые они обслуживают';

--
-- Дамп данных таблицы `executor_categories`
--

INSERT INTO `executor_categories` (`user_id`, `category_id`) VALUES
(1, 1),
(2, 1),
(4, 1),
(1, 2),
(2, 3),
(1, 4);

-- --------------------------------------------------------

--
-- Структура таблицы `requests`
--

CREATE TABLE `requests` (
  `id` int NOT NULL,
  `guest_phone` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Нормализованный номер телефона гостя',
  `category_id` int NOT NULL COMMENT 'Ссылка на категорию',
  `location` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Место (корпус, комната)',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Описание проблемы',
  `photo_path` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Путь к файлу фотографии',
  `status` enum('new','in_progress','paused','info_requested','completed','cancelled') COLLATE utf8mb4_unicode_ci DEFAULT 'new' COMMENT 'Статус заявки',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время создания',
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Время последнего обновления',
  `executor_id` int DEFAULT NULL COMMENT 'ID назначенного исполнителя (из таблицы users)',
  `organization_id` int DEFAULT NULL COMMENT 'ID организации (для мульти-тенантности)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Заявки на обслуживание';

--
-- Дамп данных таблицы `requests`
--

INSERT INTO `requests` (`id`, `guest_phone`, `category_id`, `location`, `description`, `photo_path`, `status`, `created_at`, `updated_at`, `executor_id`, `organization_id`) VALUES
(1, '79508232424', 1, 'Администрация, кабинет 6', 'Необходимо провести влажную уборку', NULL, 'cancelled', '2025-04-11 11:36:48', '2025-04-11 12:07:30', 2, NULL),
(2, '79508232424', 2, 'Администрация, кабинет 6', 'Замена лампочек', NULL, 'in_progress', '2025-04-11 11:49:57', '2025-04-11 12:16:25', 2, NULL),
(3, '79508232424', 3, 'Администрация, кабинет 6', 'Установить кулер и кофейный аппарат в кабинет', NULL, 'in_progress', '2025-04-11 11:50:30', '2025-04-11 12:07:37', 2, NULL),
(4, '89508232424', 4, 'Администрация, кабинет 6', 'Хочу новы ПК, купите мне пожалуйста !!!!', NULL, 'completed', '2025-04-11 12:02:33', '2025-04-11 12:55:43', 1, NULL),
(5, '79508232424', 1, 'Администрация, кабинет 6', 'тут сок кто-то пролил', NULL, 'completed', '2025-04-11 12:13:12', '2025-04-11 14:12:07', 1, NULL),
(6, '89508232424', 4, 'Администрация, кабинет 6', 'Слабый интернет', NULL, 'in_progress', '2025-04-11 12:17:04', '2025-04-11 13:02:34', 1, NULL),
(7, '89508232424', 4, 'Администрация, кабинет 6', 'Смотри какая фотка', 'uploads/req_photo_67f90c6e1e4fb1.45442554.jpg', 'info_requested', '2025-04-11 12:34:54', '2025-04-11 14:12:01', 1, NULL),
(8, '89508232424', 1, 'уцк', 'цуку', NULL, 'paused', '2025-04-11 13:06:20', '2025-04-11 14:11:57', 1, NULL),
(9, '89508232424', 1, '3423', '423423', NULL, 'completed', '2025-04-11 13:06:24', '2025-04-11 14:39:26', 1, NULL),
(10, '89508232424', 4, '32423', '42342342', NULL, 'info_requested', '2025-04-11 13:06:29', '2025-04-11 15:25:23', 1, NULL),
(11, '89508232424', 2, '324', '2342342342', NULL, 'in_progress', '2025-04-11 13:06:33', '2025-04-11 14:39:49', 1, NULL),
(12, '89508232424', 6, 'ааа', 'все пролилили', NULL, 'new', '2025-04-11 13:20:03', '2025-04-11 15:27:53', NULL, NULL);

-- --------------------------------------------------------

--
-- Структура таблицы `request_history`
--

CREATE TABLE `request_history` (
  `id` int NOT NULL,
  `request_id` int NOT NULL COMMENT 'ID заявки',
  `timestamp` timestamp NULL DEFAULT CURRENT_TIMESTAMP COMMENT 'Время события',
  `user_id` int DEFAULT NULL COMMENT 'ID пользователя, совершившего действие (NULL если система/автоматически)',
  `action_type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Тип действия (status_change, assigned, unassigned, created, etc.)',
  `old_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Предыдущее значение (статус, ID исполнителя)',
  `new_value` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Новое значение (статус, ID исполнителя)',
  `comment` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'Дополнительный комментарий (опционально)'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='История изменений статусов и исполнителей заявок';

-- --------------------------------------------------------

--
-- Структура таблицы `users`
--

CREATE TABLE `users` (
  `id` int NOT NULL,
  `username` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Логин пользователя',
  `password_hash` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Хеш пароля (используйте password_hash)',
  `full_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'ФИО пользователя',
  `role` enum('executor','operator','admin') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Роль пользователя',
  `organization_id` int DEFAULT NULL COMMENT 'ID организации (для будущей мульти-тенантности)',
  `is_active` tinyint(1) DEFAULT '1' COMMENT '1 - активен, 0 - неактивен',
  `created_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='Пользователи системы (Исполнители, Операторы)';

--
-- Дамп данных таблицы `users`
--

INSERT INTO `users` (`id`, `username`, `password_hash`, `full_name`, `role`, `organization_id`, `is_active`, `created_at`, `updated_at`) VALUES
(1, 'executor_ivan', '$2y$10$3ZEr1Rj5J9yAZiRdjL0gAOIsOimO2w08DZ4caxBuWaHhEQAYzaeWa', 'Иванов Иван (Электрика, ИТ)', 'executor', NULL, 1, '2025-04-11 11:40:36', '2025-04-11 11:40:36'),
(2, 'executor_petr', '$2y$10$QxMnQw/pXQ8HKdy7eieyBOQzZB2S.PvfhrK9QFdR.YUp79wy4CXOK', 'Петров Петр (Сантехника)', 'executor', NULL, 1, '2025-04-11 11:40:36', '2025-04-11 11:40:36'),
(3, 'operator_anna', '$2y$10$bGYzUO.TjoG1fvDP/2kZ9eAAPENUCglTgLRlX3NGR.Qec9hkiCiLC', 'Анна Оператор', 'operator', NULL, 1, '2025-04-11 11:40:36', '2025-04-11 11:40:36'),
(4, 'inactive_user', '$2y$10$f2XP6zNzoVcIZgkoQ4jsu.RQIGitLR9MpRXJyAIvKMsfMstYdlv3e', 'Неактивный Пользователь', 'executor', NULL, 0, '2025-04-11 11:40:36', '2025-04-11 13:35:40'),
(6, 'executor_alex', '$2y$10$lr3mdyRq3Ykke67bYwOkweKPtbhOe1GyDE8iSvT9VtyHs2Tcgwp7K', 'Алексей Исполнитель', 'executor', NULL, 0, '2025-04-11 14:25:58', '2025-04-11 14:26:03');

--
-- Индексы сохранённых таблиц
--

--
-- Индексы таблицы `categories`
--
ALTER TABLE `categories`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `name` (`name`);

--
-- Индексы таблицы `executor_categories`
--
ALTER TABLE `executor_categories`
  ADD PRIMARY KEY (`user_id`,`category_id`),
  ADD KEY `category_id` (`category_id`);

--
-- Индексы таблицы `requests`
--
ALTER TABLE `requests`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_guest_phone` (`guest_phone`),
  ADD KEY `idx_status` (`status`),
  ADD KEY `idx_category_id` (`category_id`),
  ADD KEY `idx_executor_id` (`executor_id`),
  ADD KEY `idx_organization_id` (`organization_id`);

--
-- Индексы таблицы `request_history`
--
ALTER TABLE `request_history`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_request_id_timestamp` (`request_id`,`timestamp`),
  ADD KEY `user_id` (`user_id`);

--
-- Индексы таблицы `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `username` (`username`);

--
-- AUTO_INCREMENT для сохранённых таблиц
--

--
-- AUTO_INCREMENT для таблицы `categories`
--
ALTER TABLE `categories`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=8;

--
-- AUTO_INCREMENT для таблицы `requests`
--
ALTER TABLE `requests`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=13;

--
-- AUTO_INCREMENT для таблицы `request_history`
--
ALTER TABLE `request_history`
  MODIFY `id` int NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT для таблицы `users`
--
ALTER TABLE `users`
  MODIFY `id` int NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=7;

--
-- Ограничения внешнего ключа сохраненных таблиц
--

--
-- Ограничения внешнего ключа таблицы `executor_categories`
--
ALTER TABLE `executor_categories`
  ADD CONSTRAINT `executor_categories_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `executor_categories_ibfk_2` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE CASCADE ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `requests`
--
ALTER TABLE `requests`
  ADD CONSTRAINT `requests_ibfk_1` FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

--
-- Ограничения внешнего ключа таблицы `request_history`
--
ALTER TABLE `request_history`
  ADD CONSTRAINT `request_history_ibfk_1` FOREIGN KEY (`request_id`) REFERENCES `requests` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  ADD CONSTRAINT `request_history_ibfk_2` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL ON UPDATE CASCADE;
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
