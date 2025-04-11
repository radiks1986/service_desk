<?php
// Файл: operator_category_actions.php
// Обработчик действий оператора с категориями

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // Подключаем БД

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') {
    $_SESSION['error_message'] = "Доступ запрещен.";
    $redirect_location = isset($_SESSION['guest_phone']) ? 'index.php' : 'login.php';
    header('Location: ' . $redirect_location);
    exit();
}
$operator_id = $_SESSION['user_id'];

// --- Проверка метода запроса ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    
    // --- Проверка CSRF токена ---
    $submitted_token = $_POST['csrf_token'] ?? null; // Получаем токен из POST
    if (!validateCsrfToken($submitted_token)) {
         // Если токен невалиден, прерываем выполнение
         $_SESSION['error_message'] = "Ошибка безопасности: Неверный или просроченный токен формы. Пожалуйста, попробуйте отправить форму еще раз.";
         // Редирект на предыдущую страницу или страницу по умолчанию
         // Определить $previous_page можно по HTTP_REFERER (не всегда надежно) или передавать скрытым полем
         // Пока просто редирект на дашборд соответствующей роли или на index
         $redirect_url = 'index.php'; // По умолчанию
         if (isset($_SESSION['role'])) {
             if ($_SESSION['role'] === 'operator') $redirect_url = 'operator_dashboard.php';
             elseif ($_SESSION['role'] === 'executor') $redirect_url = 'executor_dashboard.php';
             // Добавить другие роли, если нужно
         } elseif (basename($_SERVER['PHP_SELF']) === 'create_request.php') { // Особый случай для create_request
              $redirect_url = 'create_request.php';
         }
         if($db) { mysqli_close($db); } // Закрываем соединение перед редиректом
         header('Location: ' . $redirect_url);
         exit();
    }
    // --- Конец проверки CSRF токена ---
    
     $_SESSION['error_message'] = "Недопустимый метод запроса.";
     header('Location: operator_categories.php');
     exit();
}

// --- Получение данных из POST ---
$action = isset($_POST['action']) ? trim($_POST['action']) : null;

mysqli_begin_transaction($db); // Начинаем транзакцию

try {
    switch ($action) {

        // --- Действие: Добавить категорию ---
        case 'add_category':
            $category_name = isset($_POST['category_name']) ? trim($_POST['category_name']) : null;

            // Валидация
            if (empty($category_name)) {
                throw new Exception("Название категории не может быть пустым.");
            }
            if (mb_strlen($category_name) > 100) {
                throw new Exception("Название категории слишком длинное (макс. 100 символов).");
            }

            // Проверка на уникальность (без учета регистра для надежности)
            $sql_check = "SELECT id FROM categories WHERE LOWER(name) = LOWER(?)";
            $stmt_check = mysqli_prepare($db, $sql_check);
            mysqli_stmt_bind_param($stmt_check, "s", $category_name);
            mysqli_stmt_execute($stmt_check);
            mysqli_stmt_store_result($stmt_check);
            if (mysqli_stmt_num_rows($stmt_check) > 0) {
                 throw new Exception("Категория с таким названием уже существует.");
            }
            mysqli_stmt_close($stmt_check);

            // Вставка
            $sql_insert = "INSERT INTO categories (name, is_active) VALUES (?, 1)"; // Новая категория сразу активна
            $stmt_insert = mysqli_prepare($db, $sql_insert);
            if (!$stmt_insert) throw new Exception("Ошибка подготовки запроса добавления: " . mysqli_error($db));

            mysqli_stmt_bind_param($stmt_insert, "s", $category_name);
            if (!mysqli_stmt_execute($stmt_insert)) {
                throw new Exception("Ошибка добавления категории: " . mysqli_stmt_error($stmt_insert));
            }
            $new_id = mysqli_insert_id($db);
            mysqli_stmt_close($stmt_insert);
            $_SESSION['success_message'] = "Категория \"".htmlspecialchars($category_name)."\" (ID: {$new_id}) успешно добавлена.";

            break;

        // --- Действие: Переключить статус активности ---
        case 'toggle_status':
            $category_id = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

            // Валидация
            if ($category_id <= 0) {
                throw new Exception("Некорректный ID категории.");
            }

            // Получаем текущий статус
            $sql_get = "SELECT is_active FROM categories WHERE id = ?";
            $stmt_get = mysqli_prepare($db, $sql_get);
            if (!$stmt_get) throw new Exception("Ошибка подготовки запроса получения статуса: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_get, "i", $category_id);
            mysqli_stmt_execute($stmt_get);
            $result_get = mysqli_stmt_get_result($stmt_get);
            $current_data = mysqli_fetch_assoc($result_get);
            mysqli_free_result($result_get);
            mysqli_stmt_close($stmt_get);

            if (!$current_data) {
                 throw new Exception("Категория с ID {$category_id} не найдена.");
            }

            // Определяем новый статус (инвертируем)
            $new_status = $current_data['is_active'] ? 0 : 1; // 1 становится 0, 0 становится 1

            // Обновление
            $sql_update = "UPDATE categories SET is_active = ? WHERE id = ?";
            $stmt_update = mysqli_prepare($db, $sql_update);
             if (!$stmt_update) throw new Exception("Ошибка подготовки запроса обновления: " . mysqli_error($db));

            mysqli_stmt_bind_param($stmt_update, "ii", $new_status, $category_id);
            if (!mysqli_stmt_execute($stmt_update)) {
                 throw new Exception("Ошибка изменения статуса категории: " . mysqli_stmt_error($stmt_update));
            }
            mysqli_stmt_close($stmt_update);

            $_SESSION['success_message'] = "Статус категории ID: {$category_id} успешно изменен.";

            break;

            // --- НОВОЕ: Действие: Удалить категорию ---
        case 'delete_category':
            $category_id_to_delete = isset($_POST['category_id']) ? (int)$_POST['category_id'] : 0;

            // Валидация
            if ($category_id_to_delete <= 0) { throw new Exception("Некорректный ID категории."); }

            // Проверка, есть ли заявки или исполнители, связанные с этой категорией
            $sql_check_req = "SELECT 1 FROM requests WHERE category_id = ? LIMIT 1";
            $stmt_check_req = mysqli_prepare($db, $sql_check_req);
            mysqli_stmt_bind_param($stmt_check_req, "i", $category_id_to_delete);
            mysqli_stmt_execute($stmt_check_req);
            mysqli_stmt_store_result($stmt_check_req);
            $has_requests = mysqli_stmt_num_rows($stmt_check_req) > 0;
            mysqli_stmt_close($stmt_check_req);

            if ($has_requests) {
                throw new Exception("Невозможно удалить категорию, так как существуют связанные с ней заявки.");
            }

            $sql_check_exec = "SELECT 1 FROM executor_categories WHERE category_id = ? LIMIT 1";
            $stmt_check_exec = mysqli_prepare($db, $sql_check_exec);
             mysqli_stmt_bind_param($stmt_check_exec, "i", $category_id_to_delete);
             mysqli_stmt_execute($stmt_check_exec);
             mysqli_stmt_store_result($stmt_check_exec);
             $has_executors = mysqli_stmt_num_rows($stmt_check_exec) > 0;
             mysqli_stmt_close($stmt_check_exec);

            if ($has_executors) {
                // Если есть исполнители, просто удалим связи (можно и запретить)
                $sql_del_links = "DELETE FROM executor_categories WHERE category_id = ?";
                $stmt_del_links = mysqli_prepare($db, $sql_del_links);
                mysqli_stmt_bind_param($stmt_del_links, "i", $category_id_to_delete);
                if (!mysqli_stmt_execute($stmt_del_links)) { throw new Exception("Ошибка удаления связей исполнителей с категорией: ".mysqli_stmt_error($stmt_del_links)); }
                mysqli_stmt_close($stmt_del_links);
                $_SESSION['warning_message'] = "Связи исполнителей с удаляемой категорией были удалены."; // Добавим предупреждение
            }

            // Удаляем саму категорию
            $sql_delete = "DELETE FROM categories WHERE id = ?";
            $stmt_delete = mysqli_prepare($db, $sql_delete);
            if (!$stmt_delete) throw new Exception("Ошибка подготовки удаления категории: " . mysqli_error($db));
            mysqli_stmt_bind_param($stmt_delete, "i", $category_id_to_delete);
            if (!mysqli_stmt_execute($stmt_delete)) { throw new Exception("Ошибка удаления категории: " . mysqli_stmt_error($stmt_delete)); }
            $affected_rows_del = mysqli_stmt_affected_rows($stmt_delete);
            mysqli_stmt_close($stmt_delete);

             if ($affected_rows_del > 0) {
                $_SESSION['success_message'] = "Категория ID: {$category_id_to_delete} успешно удалена.";
             } else {
                 throw new Exception("Не удалось удалить категорию ID: {$category_id_to_delete} (возможно, она уже была удалена).");
             }
            break;

        default:
            throw new Exception("Неизвестное действие: '$action'.");
            break;
    }

    // Если все прошло успешно, фиксируем транзакцию
    mysqli_commit($db);

} catch (Exception $e) {
    // В случае любой ошибки откатываем транзакцию
    mysqli_rollback($db);
    $_SESSION['error_message'] = $e->getMessage();
    // Логирование ошибки можно добавить сюда, если нужно
    error_log("Operator category action error: " . $e->getMessage() . " | OperatorID: {$operator_id}, Action: {$action}");
}

// --- Закрытие соединения и редирект ---
if ($db) {
    mysqli_close($db);
}
header('Location: operator_categories.php'); // Возвращаем на страницу категорий
exit();

?>