<?php
// Файл: operator_user_actions.php
// Обработчик действий оператора с пользователями

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // Подключаем БД
require_once __DIR__ . '/includes/helpers.php';   // Подключаем хелперы (для CSRF)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') { /* ... обработка ... */ }
$current_operator_id = $_SESSION['user_id'];

// --- Проверка метода запроса ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* ... обработка ... */ }

// --- Проверка CSRF токена ---
$submitted_token = $_POST['csrf_token'] ?? null;
if (!validateCsrfToken($submitted_token)) { /* ... обработка ... */ }

// --- Получение данных из POST ---
$action = isset($_POST['action']) ? trim($_POST['action']) : null;
$available_roles = ['executor', 'operator'];

mysqli_begin_transaction($db);

try {
    switch ($action) {
        case 'add_user':
            $username = isset($_POST['username']) ? trim($_POST['username']) : null;
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
            $password = isset($_POST['password']) ? $_POST['password'] : null; // Не тримим пароль
            $role = isset($_POST['role']) ? trim($_POST['role']) : null;

            // Валидация
            if (empty($username) || empty($full_name) || empty($password) || empty($role)) {
                throw new Exception("Все поля (Логин, ФИО, Пароль, Роль) обязательны для заполнения.");
            }
            if (mb_strlen($username) > 50) { throw new Exception("Логин слишком длинный (макс. 50 символов)."); }
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) { throw new Exception("Логин может содержать только латинские буквы, цифры и знак подчеркивания (_)."); }
            if (mb_strlen($full_name) > 100) { throw new Exception("ФИО слишком длинное (макс. 100 символов)."); }
            if (!in_array($role, $available_roles)) { throw new Exception("Выбрана недопустимая роль."); }
            // TODO: Добавить проверку сложности пароля, если нужно

            // Проверка на уникальность логина
            $sql_check = "SELECT id FROM users WHERE username = ?";
            $stmt_check = mysqli_prepare($db, $sql_check);
            if (!$stmt_check) throw new Exception("Ошибка подготовки проверки логина: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_check, "s", $username);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_store_result($stmt_check);
            if (mysqli_stmt_num_rows($stmt_check) > 0) {
                 mysqli_stmt_close($stmt_check); // Закрываем перед генерацией исключения
                 throw new Exception("Пользователь с таким логином уже существует.");
            }
            mysqli_stmt_close($stmt_check);

            // Хеширование пароля
            $password_hash = password_hash($password, PASSWORD_DEFAULT);
            if ($password_hash === false) {
                 throw new Exception("Ошибка хеширования пароля.");
            }

            // Вставка нового пользователя (по умолчанию активен)
            $sql_insert = "INSERT INTO users (username, password_hash, full_name, role, is_active, created_at, updated_at)
                           VALUES (?, ?, ?, ?, 1, NOW(), NOW())";
            $stmt_insert = mysqli_prepare($db, $sql_insert);
            if (!$stmt_insert) throw new Exception("Ошибка подготовки запроса добавления пользователя: " . mysqli_error($db));

            mysqli_stmt_bind_param($stmt_insert, "ssss", $username, $password_hash, $full_name, $role);
            if (!mysqli_stmt_execute($stmt_insert)) {
                mysqli_stmt_close($stmt_insert); // Закрываем перед генерацией исключения
                throw new Exception("Ошибка добавления пользователя: " . mysqli_stmt_error($stmt_insert));
            }
            $new_user_id = mysqli_insert_id($db);
            mysqli_stmt_close($stmt_insert);

            // Успех - устанавливаем сообщение
            $_SESSION['success_message'] = "Пользователь \"".htmlspecialchars($username)."\" успешно добавлен.";
            break; // Конец case 'add_user'

        case 'toggle_status':
             // ... (код переключения статуса без изменений) ...
             $user_id_to_toggle = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

            // Валидация
            if ($user_id_to_toggle <= 0) { throw new Exception("Некорректный ID пользователя."); }
            if ($user_id_to_toggle === $current_operator_id) { throw new Exception("Вы не можете изменять свой статус."); } // Изменил текст ошибки

            // Получаем текущий статус
            $sql_get = "SELECT is_active FROM users WHERE id = ?";
            $stmt_get = mysqli_prepare($db, $sql_get);
             if (!$stmt_get) throw new Exception("Ошибка подготовки запроса получения статуса пользователя: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_get, "i", $user_id_to_toggle);
            mysqli_stmt_execute($stmt_get);
            $result_get = mysqli_stmt_get_result($stmt_get);
            $current_data = mysqli_fetch_assoc($result_get);
            mysqli_free_result($result_get);
            mysqli_stmt_close($stmt_get);

            if (!$current_data) { throw new Exception("Пользователь с ID {$user_id_to_toggle} не найден."); }

            // Определяем новый статус
            $new_status = $current_data['is_active'] ? 0 : 1;

            // --- ОТЛАДКА ---
            error_log("Toggling status for User ID: {$user_id_to_toggle}. Current: {$current_data['is_active']}, New: {$new_status}");
            // ---------------

            // Обновление
            $sql_update = "UPDATE users SET is_active = ? WHERE id = ?";
            $stmt_update = mysqli_prepare($db, $sql_update);
            if (!$stmt_update) throw new Exception("Ошибка подготовки запроса обновления пользователя: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_update, "ii", $new_status, $user_id_to_toggle);

            if (!mysqli_stmt_execute($stmt_update)) {
                 // Если выполнение не удалось, генерируем исключение
                 throw new Exception("Ошибка изменения статуса пользователя: " . mysqli_stmt_error($stmt_update));
            } else {
                // Проверяем, сколько строк было затронуто
                 $affected_rows = mysqli_stmt_affected_rows($stmt_update);
                 error_log("Toggle status affected rows: {$affected_rows}"); // <-- ОТЛАДКА

                 if ($affected_rows > 0) {
                     // Все хорошо, статус изменен
                     $_SESSION['success_message'] = "Статус пользователя ID: {$user_id_to_toggle} успешно изменен.";
                 } else {
                     // Запрос выполнился, но не затронул строки.
                     // Это странно, но возможно, если ID не существует (хотя мы проверяли)
                     // или статус уже был таким, какой мы пытаемся установить (маловероятно из-за инверсии).
                     // Генерируем исключение, чтобы увидеть ошибку.
                     throw new Exception("Не удалось изменить статус пользователя ID: {$user_id_to_toggle}. Затронуто строк: {$affected_rows}.");
                 }
            }
            mysqli_stmt_close($stmt_update);
            break;

        // --- Действие: Редактировать пользователя ---
        case 'edit_user':
            $user_id_to_edit = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
            $full_name = isset($_POST['full_name']) ? trim($_POST['full_name']) : null;
            $role = isset($_POST['role']) ? trim($_POST['role']) : null;
            $is_active_input = isset($_POST['is_active']) ? $_POST['is_active'] : null;
            $password = isset($_POST['password']) ? $_POST['password'] : null;
            $password_confirm = isset($_POST['password_confirm']) ? $_POST['password_confirm'] : null;
            // Получаем массив выбранных категорий (будет пустой, если не отправлено)
            $submitted_categories = isset($_POST['categories']) && is_array($_POST['categories']) ? $_POST['categories'] : [];

            // Валидация основных полей...
            if ($user_id_to_edit <= 0) { throw new Exception("Некорректный ID пользователя."); }
            if (empty($full_name)) { throw new Exception("Поле ФИО не может быть пустым."); }
            if (mb_strlen($full_name) > 100) { throw new Exception("ФИО слишком длинное."); }
            if (!in_array($role, $available_roles)) { throw new Exception("Выбрана недопустимая роль."); }
            if ($is_active_input === null || !in_array($is_active_input, ['0', '1'])) { throw new Exception("Некорректный статус активности."); }
            $is_active = (int)$is_active_input;

            // Проверка статуса активности для самого себя...
             if ($user_id_to_edit === $current_operator_id && $is_active === 0) {
                  throw new Exception("Вы не можете деактивировать свою учетную запись через редактирование.");
             }

            // Валидация и хеширование пароля...
            $password_hash_to_set = null;
            if (!empty($password)) {
                if ($password !== $password_confirm) { throw new Exception("Пароли не совпадают."); }
                $password_hash_to_set = password_hash($password, PASSWORD_DEFAULT);
                if ($password_hash_to_set === false) { throw new Exception("Ошибка хеширования нового пароля."); }
            }

             // --- Обновление основной информации пользователя ---
            $sql_base_update = "UPDATE users SET full_name = ?, role = ?, is_active = ?";
            $update_params = [$full_name, $role, $is_active];
            $update_types = "ssi";
            if ($password_hash_to_set !== null) { $sql_base_update .= ", password_hash = ?"; $update_params[] = $password_hash_to_set; $update_types .= "s"; }
            $sql_final_update = $sql_base_update . " WHERE id = ?"; $update_params[] = $user_id_to_edit; $update_types .= "i";
            $stmt_update = mysqli_prepare($db, $sql_final_update);
            if (!$stmt_update) { throw new Exception("Ошибка подготовки обновления пользователя: " . mysqli_error($db)); }
            mysqli_stmt_bind_param($stmt_update, $update_types, ...$update_params);
            if (!mysqli_stmt_execute($stmt_update)) { throw new Exception("Ошибка обновления пользователя: " . mysqli_stmt_error($stmt_update)); }
            mysqli_stmt_close($stmt_update);

            // --- Обновление категорий (только если роль - исполнитель) ---
            if ($role === 'executor') {
                // 1. Удаляем все старые привязки категорий для этого пользователя
                $sql_delete_cats = "DELETE FROM executor_categories WHERE user_id = ?";
                $stmt_delete = mysqli_prepare($db, $sql_delete_cats);
                if (!$stmt_delete) throw new Exception("Ошибка подготовки удаления категорий: " . mysqli_error($db));
                mysqli_stmt_bind_param($stmt_delete, "i", $user_id_to_edit);
                if (!mysqli_stmt_execute($stmt_delete)) { throw new Exception("Ошибка удаления старых категорий: " . mysqli_stmt_error($stmt_delete)); }
                mysqli_stmt_close($stmt_delete);

                // 2. Вставляем новые привязки, если были выбраны категории
                if (!empty($submitted_categories)) {
                    $sql_insert_cat = "INSERT INTO executor_categories (user_id, category_id) VALUES (?, ?)";
                    $stmt_insert_cat = mysqli_prepare($db, $sql_insert_cat);
                    if (!$stmt_insert_cat) throw new Exception("Ошибка подготовки вставки категорий: " . mysqli_error($db));

                    foreach ($submitted_categories as $category_id) {
                        $cat_id_int = (int)$category_id; // Приводим к int на всякий случай
                        if ($cat_id_int > 0) { // Проверяем корректность ID
                            mysqli_stmt_bind_param($stmt_insert_cat, "ii", $user_id_to_edit, $cat_id_int);
                            if (!mysqli_stmt_execute($stmt_insert_cat)) {
                                // Можно продолжить или прервать с ошибкой
                                error_log("Ошибка вставки категории ID {$cat_id_int} для пользователя ID {$user_id_to_edit}: " . mysqli_stmt_error($stmt_insert_cat));
                                // throw new Exception("Ошибка вставки категории ID {$cat_id_int}");
                            }
                        }
                    }
                    mysqli_stmt_close($stmt_insert_cat);
                }
            } else {
                 // Если роль НЕ исполнитель, убедимся, что у него нет привязок к категориям
                 $sql_delete_cats = "DELETE FROM executor_categories WHERE user_id = ?";
                 $stmt_delete = mysqli_prepare($db, $sql_delete_cats);
                 if ($stmt_delete) { mysqli_stmt_bind_param($stmt_delete, "i", $user_id_to_edit); mysqli_stmt_execute($stmt_delete); mysqli_stmt_close($stmt_delete); }
                 else { error_log("Ошибка подготовки удаления категорий при смене роли на не-исполнителя: " . mysqli_error($db)); }
            }
            // --- Конец обновления категорий ---

            $_SESSION['success_message'] = "Данные пользователя ID: {$user_id_to_edit} успешно обновлены.";

            break;
            
            // --- НОВОЕ: Действие: Удалить пользователя ---
    case 'delete_user':
        $user_id_to_delete = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;

        // Валидация и проверка
        if ($user_id_to_delete <= 0) { throw new Exception("Некорректный ID пользователя."); }
        if ($user_id_to_delete === $current_operator_id) { throw new Exception("Вы не можете удалить свою учетную запись."); }

        // Проверяем, существует ли пользователь (на всякий случай)
        $sql_check_del = "SELECT id, role FROM users WHERE id = ?";
        $stmt_check_del = mysqli_prepare($db, $sql_check_del);
        if (!$stmt_check_del) throw new Exception("Ошибка подготовки проверки пользователя: " . mysqli_error($db));
        mysqli_stmt_bind_param($stmt_check_del, "i", $user_id_to_delete);
        mysqli_stmt_execute($stmt_check_del);
        $result_check_del = mysqli_stmt_get_result($stmt_check_del);
        $user_to_delete = mysqli_fetch_assoc($result_check_del);
        mysqli_free_result($result_check_del);
        mysqli_stmt_close($stmt_check_del);

        if (!$user_to_delete) { throw new Exception("Пользователь с ID {$user_id_to_delete} не найден для удаления."); }

        // Если пользователь - исполнитель, снимаем его с заявок
        if ($user_to_delete['role'] === 'executor') {
            // Обновляем заявки, где он был исполнителем
            $sql_unassign = "UPDATE requests SET executor_id = NULL WHERE executor_id = ?";
            $stmt_unassign = mysqli_prepare($db, $sql_unassign);
            if (!$stmt_unassign) throw new Exception("Ошибка подготовки снятия заявок: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_unassign, "i", $user_id_to_delete);
            if (!mysqli_stmt_execute($stmt_unassign)) { throw new Exception("Ошибка снятия пользователя с заявок: " . mysqli_stmt_error($stmt_unassign)); }
            mysqli_stmt_close($stmt_unassign);

            // Удаляем его привязки к категориям
            $sql_del_cats = "DELETE FROM executor_categories WHERE user_id = ?";
            $stmt_del_cats = mysqli_prepare($db, $sql_del_cats);
            if (!$stmt_del_cats) throw new Exception("Ошибка подготовки удаления категорий пользователя: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_del_cats, "i", $user_id_to_delete);
             if (!mysqli_stmt_execute($stmt_del_cats)) { throw new Exception("Ошибка удаления привязки категорий: " . mysqli_stmt_error($stmt_del_cats)); }
            mysqli_stmt_close($stmt_del_cats);
        }

        // Удаляем самого пользователя
        $sql_delete = "DELETE FROM users WHERE id = ?";
        $stmt_delete = mysqli_prepare($db, $sql_delete);
        if (!$stmt_delete) throw new Exception("Ошибка подготовки удаления пользователя: " . mysqli_error($db));
        mysqli_stmt_bind_param($stmt_delete, "i", $user_id_to_delete);
         if (!mysqli_stmt_execute($stmt_delete)) { throw new Exception("Ошибка удаления пользователя: " . mysqli_stmt_error($stmt_delete)); }
         $affected_rows_del = mysqli_stmt_affected_rows($stmt_delete);
        mysqli_stmt_close($stmt_delete);

        if ($affected_rows_del > 0) {
            $_SESSION['success_message'] = "Пользователь ID: {$user_id_to_delete} успешно удален.";
        } else {
            // Это может произойти, если пользователя удалили между проверкой и DELETE
             throw new Exception("Не удалось удалить пользователя ID: {$user_id_to_delete} (возможно, он уже был удален).");
        }
        break;

        default:
            throw new Exception("Неизвестное действие: '$action'.");
            break;
    }

    mysqli_commit($db); // Фиксируем транзакцию
} catch (Exception $e) {
    mysqli_rollback($db); // Откатываем транзакцию
    $_SESSION['error_message'] = $e->getMessage();
    error_log("Operator user action error: " . $e->getMessage() . " | OperatorID: {$current_operator_id}, Action: {$action}");
}

// --- Закрытие соединения и редирект ---
if ($db) { mysqli_close($db); }
header('Location: operator_users.php');
exit();

?>