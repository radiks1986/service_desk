Загрузка фотографий: Добавить возможность гостю прикреплять фото к заявке при ее создании и отображать это фото для исполнителя и оператора.
Детализация Заявки: Создать страницу или модальное окно для просмотра полной информации о заявке (включая фото, если оно есть, полное описание, возможно, историю изменений статуса).
Расширенные действия Исполнителя: Добавить кнопки и логику для статусов "Приостановить" и "Запросить доп. информацию".
Улучшения для Оператора:
Фильтры и Поиск: Добавить возможность фильтровать заявки по статусу, категории, исполнителю, дате на operator_dashboard.php.
Пагинация: Разбить длинный список заявок оператора на страницы.
Статистика: Вывести базовую статистику (количество заявок по статусам/категориям).
Управление: Создать интерфейсы для управления пользователями (исполнители/операторы) и категориями.
Уведомления: Продумать базовую систему уведомлений (например, для исполнителя о новой заявке или для гостя об изменении статуса — хотя без email/push это может быть сложно реализовать эффективно).
Улучшения Интерфейса (UX/UI): Сделать интерфейс более отзывчивым и удобным, возможно, используя AJAX для некоторых действий без перезагрузки страницы.
Безопасность: Усилить защиту (например, добавить CSRF-токены в формы).



<?php
// Вспомогательный скрипт для создания пользователей (запустите один раз)
// Можете создать файл, например, create_test_users.php, запустить его через браузер или CLI,
// а потом удалить. Не забудьте подключить config.php и db_connect.php

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php';

// Данные тестовых пользователей
$users_data = [
    ['username' => 'executor_ivan', 'password' => 'password', 'full_name' => 'Иванов Иван (Электрика, ИТ)', 'role' => 'executor', 'categories' => [2, 4]], // Электрика (ID=2), ИТ (ID=4)
    ['username' => 'executor_petr', 'password' => 'password', 'full_name' => 'Петров Петр (Сантехника)', 'role' => 'executor', 'categories' => [3]],      // Сантехника (ID=3)
    ['username' => 'operator_anna', 'password' => 'password', 'full_name' => 'Анна Оператор', 'role' => 'operator', 'categories' => []],   // Оператору не нужны категории
    ['username' => 'inactive_user', 'password' => 'password', 'full_name' => 'Неактивный Пользователь', 'role' => 'executor', 'is_active' => 0, 'categories' => [1]] // Неактивный
];

$errors = [];
$success_count = 0;

foreach ($users_data as $user_data) {
    $username = $user_data['username'];
    $password = $user_data['password'];
    $full_name = $user_data['full_name'];
    $role = $user_data['role'];
    $is_active = isset($user_data['is_active']) ? $user_data['is_active'] : 1;
    $categories = $user_data['categories'];

    // Проверка, существует ли пользователь
    $stmt_check = mysqli_prepare($db, "SELECT id FROM users WHERE username = ?");
    mysqli_stmt_bind_param($stmt_check, "s", $username);
    mysqli_stmt_execute($stmt_check);
    mysqli_stmt_store_result($stmt_check);
    $user_exists = mysqli_stmt_num_rows($stmt_check) > 0;
    mysqli_stmt_close($stmt_check);

    if ($user_exists) {
        echo "Пользователь '$username' уже существует. Пропускаем.<br>";
        continue;
    }

    // Хешируем пароль
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    if ($password_hash === false) {
         $errors[] = "Ошибка хеширования пароля для пользователя '$username'.";
         continue;
    }

    // Вставляем пользователя в таблицу users
    $sql_user = "INSERT INTO users (username, password_hash, full_name, role, is_active, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, NOW(), NOW())";
    $stmt_user = mysqli_prepare($db, $sql_user);
    if ($stmt_user) {
        mysqli_stmt_bind_param($stmt_user, "ssssi", $username, $password_hash, $full_name, $role, $is_active);
        if (mysqli_stmt_execute($stmt_user)) {
            $user_id = mysqli_insert_id($db);
            mysqli_stmt_close($stmt_user);

            // Если это исполнитель и есть категории, добавляем связи
            if ($role === 'executor' && !empty($categories)) {
                $sql_cat = "INSERT INTO executor_categories (user_id, category_id) VALUES (?, ?)";
                $stmt_cat = mysqli_prepare($db, $sql_cat);
                if($stmt_cat) {
                    foreach ($categories as $category_id) {
                        mysqli_stmt_bind_param($stmt_cat, "ii", $user_id, $category_id);
                        if (!mysqli_stmt_execute($stmt_cat)) {
                            $errors[] = "Ошибка добавления категории ID $category_id для пользователя '$username': " . mysqli_stmt_error($stmt_cat);
                        }
                    }
                    mysqli_stmt_close($stmt_cat);
                } else {
                     $errors[] = "Ошибка подготовки запроса для категорий пользователя '$username': " . mysqli_error($db);
                }
            }
            $success_count++;
        } else {
            $errors[] = "Ошибка добавления пользователя '$username': " . mysqli_stmt_error($stmt_user);
            mysqli_stmt_close($stmt_user);
        }
    } else {
         $errors[] = "Ошибка подготовки запроса для пользователя '$username': " . mysqli_error($db);
    }
}

mysqli_close($db);

echo "--------------------<br>";
echo "Операция завершена.<br>";
echo "Успешно добавлено пользователей: $success_count<br>";
if (!empty($errors)) {
    echo "Обнаружены ошибки:<br>";
    foreach ($errors as $error) {
        echo "- " . htmlspecialchars($error) . "<br>";
    }
}

?>