<?php
// Файл: executor_actions.php
// Обработчик действий исполнителя с заявками (с CSRF и AJAX/JSON и ИСТОРИЕЙ)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // Подключаем БД
require_once __DIR__ . '/includes/helpers.php';   // Подключаем хелперы (для CSRF и статусов)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'executor') { /* ... */ }
$executor_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => 'Неизвестная ошибка.'];

// --- Проверка метода запроса ---
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /* ... */ }

// --- Проверка CSRF токена ---
$submitted_token = $_POST['csrf_token'] ?? null;
if (!validateCsrfToken($submitted_token)) { /* ... */ }

// --- Получение данных из POST ---
$action = isset($_POST['action']) ? trim($_POST['action']) : null;
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : null;

// --- Валидация входных данных ---
if (!$action || !$request_id || $request_id <= 0) { /* ... */ }

// --- Проверка существования заявки ---
$request_data = null; $sql_get_request = "SELECT id, status, category_id, executor_id FROM requests WHERE id = ?";
$stmt_get = mysqli_prepare($db, $sql_get_request); if (!$stmt_get) { /*...*/ goto send_response; }
mysqli_stmt_bind_param($stmt_get, "i", $request_id); if (!mysqli_stmt_execute($stmt_get)) { /*...*/ mysqli_stmt_close($stmt_get); goto send_response; }
$result_get = mysqli_stmt_get_result($stmt_get); $request_data = mysqli_fetch_assoc($result_get); mysqli_free_result($result_get); mysqli_stmt_close($stmt_get);
if (!$request_data) { $response['message'] = "...не найдена."; $response['not_found'] = true; goto send_response; }

// --- Проверка прав доступа ---
$sql_update = null; $new_status = null; $params = []; $param_types = ""; $success_message = ""; $error_message = null;
if ($request_data['executor_id'] !== $executor_id && !in_array($action, ['take'])) { $error_message = "Заявка не назначена на вас."; }
elseif (in_array($request_data['status'], ['completed', 'cancelled']) && !in_array($action, ['take'])) { $error_message = "Заявка уже завершена или отменена."; }
if ($error_message){ $response['message'] = $error_message; $response['forbidden'] = true; goto send_response; }

// --- Основная логика с транзакцией и ИСТОРИЕЙ ---
mysqli_begin_transaction($db);
try {
    switch ($action) {
        case 'take':
            if ($request_data['status'] === 'new' && $request_data['executor_id'] === null) {
                $sql_check_cat = "SELECT 1 FROM executor_categories WHERE user_id = ? AND category_id = ?"; $stmt_check_cat = mysqli_prepare($db, $sql_check_cat); mysqli_stmt_bind_param($stmt_check_cat, "ii", $executor_id, $request_data['category_id']); mysqli_stmt_execute($stmt_check_cat); mysqli_stmt_store_result($stmt_check_cat); $cat_access = mysqli_stmt_num_rows($stmt_check_cat) > 0; mysqli_stmt_close($stmt_check_cat);
                if (!$cat_access) { $error_message = "Нет доступа к категории."; break; }
                $new_status = 'in_progress'; $sql_update = "UPDATE requests SET status = ?, executor_id = ?, updated_at = NOW() WHERE id = ? AND status = 'new' AND executor_id IS NULL"; $param_types = "sii"; $params = [$new_status, $executor_id, $request_id]; $success_message = "Заявка #{$request_id} взята в работу.";
            } else { $error_message = "Заявка уже взята или статус не 'Новая'."; }
            break;
        case 'complete':
             if (in_array($request_data['status'], ['in_progress', 'paused', 'info_requested'])) { $new_status = 'completed'; $sql_update = "UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND executor_id = ?"; $param_types = "sii"; $params = [$new_status, $request_id, $executor_id]; $success_message = "Заявка #{$request_id} завершена."; }
             else { $error_message = "Нельзя завершить из статуса '{$request_data['status']}'."; }
            break;
        case 'cancel':
             $new_status = 'cancelled'; $sql_update = "UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND executor_id = ?"; $param_types = "sii"; $params = [$new_status, $request_id, $executor_id]; $success_message = "Заявка #{$request_id} отменена.";
            break;
        case 'pause':
             if ($request_data['status'] === 'in_progress') { $new_status = 'paused'; $sql_update = "UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND executor_id = ?"; $param_types = "sii"; $params = [$new_status, $request_id, $executor_id]; $success_message = "Заявка #{$request_id} приостановлена."; }
             else { $error_message = "Нельзя приостановить из статуса '{$request_data['status']}'."; }
            break;
        case 'request_info':
             if ($request_data['status'] === 'in_progress') { $new_status = 'info_requested'; $sql_update = "UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND executor_id = ?"; $param_types = "sii"; $params = [$new_status, $request_id, $executor_id]; $success_message = "Запрошена информация по заявке #{$request_id}."; }
             else { $error_message = "Нельзя запросить инфо из статуса '{$request_data['status']}'."; }
            break;
        case 'resume':
              if (in_array($request_data['status'], ['paused', 'info_requested'])) { $new_status = 'in_progress'; $sql_update = "UPDATE requests SET status = ?, updated_at = NOW() WHERE id = ? AND executor_id = ?"; $param_types = "sii"; $params = [$new_status, $request_id, $executor_id]; $success_message = "Работа по заявке #{$request_id} возобновлена."; }
              else { $error_message = "Нельзя возобновить из статуса '{$request_data['status']}'."; }
            break;
        default:
            $error_message = "Неизвестное действие: '$action'."; break;
    }

    if ($error_message) { throw new Exception($error_message); }

    // Выполнение основного UPDATE
    if ($sql_update && !empty($params)) {
        $stmt_update = mysqli_prepare($db, $sql_update);
        if (!$stmt_update) { throw new Exception("DB Error Prepare Update: " . mysqli_error($db));}
        mysqli_stmt_bind_param($stmt_update, $param_types, ...$params);
        if (!mysqli_stmt_execute($stmt_update)) { throw new Exception("DB Error Execute Update: " . mysqli_stmt_error($stmt_update)); }
        $affected_rows = mysqli_stmt_affected_rows($stmt_update);
        mysqli_stmt_close($stmt_update);

        if ($affected_rows > 0) {
            // --- ЗАПИСЬ В ИСТОРИЮ (после успешного UPDATE) ---
            $history_action_type = ''; $history_old_value = null; $history_new_value = null; $history_comment = 'Исполнитель ID: ' . $executor_id;
            $log_status_change = false;

            switch($action) {
                case 'take':
                    $history_action_type = 'Исполнитель назначен'; $history_old_value = null; $history_new_value = $executor_id; $history_comment = ''; $log_status_change = true; // Статус меняется отдельно
                    break;
                case 'complete': case 'cancel': case 'pause': case 'request_info': case 'resume':
                    $history_action_type = 'Статус изменен'; $history_old_value = $request_data['status']; $history_new_value = $new_status;
                    break;
            }

            if (!empty($history_action_type)) {
                $sql_history = "INSERT INTO request_history (request_id, user_id, action_type, old_value, new_value, comment) VALUES (?, ?, ?, ?, ?, ?)";
                $stmt_history = mysqli_prepare($db, $sql_history);
                if (!$stmt_history) throw new Exception("Ошибка подготовки истории: ".mysqli_error($db));
                mysqli_stmt_bind_param($stmt_history, "iissss", $request_id, $executor_id, $history_action_type, $history_old_value, $history_new_value, $history_comment);
                if (!mysqli_stmt_execute($stmt_history)) { throw new Exception("Ошибка записи истории: ".mysqli_stmt_error($stmt_history)); }
                mysqli_stmt_close($stmt_history);
            }
             // Доп. запись для смены статуса при 'take'
            if ($log_status_change) {
                 $sql_hist_status = "INSERT INTO request_history (request_id, user_id, action_type, old_value, new_value, comment) VALUES (?, ?, ?, ?, ?, ?)";
                 $stmt_hist_status = mysqli_prepare($db, $sql_hist_status); if (!$stmt_hist_status) throw new Exception("Ист.статус Prep Err: ".mysqli_error($db));
                 $type_st = 'Статус изменен'; $old_st = 'new'; $new_st = $new_status; $comm_st = 'Назначен исполнитель ID: '.$executor_id;
                 mysqli_stmt_bind_param($stmt_hist_status, "iissss", $request_id, $executor_id, $type_st, $old_st, $new_st, $comm_st);
                 if (!mysqli_stmt_execute($stmt_hist_status)) { throw new Exception("Ист.статус Exec Err: ".mysqli_stmt_error($stmt_hist_status)); }
                 mysqli_stmt_close($stmt_hist_status);
            }
            // --- КОНЕЦ ЗАПИСИ В ИСТОРИЮ ---

            mysqli_commit($db); // Фиксируем ТОЛЬКО после успешной записи в историю
            $response = ['success' => true, 'message' => $success_message];
            if (isset($new_status)) { $response['new_status'] = $new_status; }
            try { $response['new_csrf_token'] = generateCsrfToken(); } catch (Exception $e) {}

        } else {
             mysqli_rollback($db);
             throw new Exception("Действие не выполнено (статус мог измениться или дубль запроса).");
        }
    } elseif (!$error_message) {
         mysqli_rollback($db);
         throw new Exception("Действие '$action' не привело к изменению данных.");
    }

} catch (Exception $e) {
    mysqli_rollback($db);
    $response = ['success' => false, 'message' => $e->getMessage()];
    error_log("Executor action error: " . $e->getMessage() . " | RequestID: {$request_id}, ExecutorID: {$executor_id}, Action: {$action}");
}

// Метка для goto
send_response:

// --- Закрытие соединения и РЕДИРЕКТ или JSON ОТВЕТ ---
if (isset($db) && $db && mysqli_ping($db)) { mysqli_close($db); }

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');

if ($is_ajax) {
    header('Content-Type: application/json');
    $http_code = 200;
    if (!$response['success']) { $http_code = 400; if (isset($response['not_found'])) $http_code = 404; if (isset($response['forbidden'])) $http_code = 403; }
    http_response_code($http_code);
    echo json_encode($response);
    exit();
} else {
    if ($response['success']) { $_SESSION['success_message'] = $response['message']; } else { $_SESSION['error_message'] = $response['message']; }
    header('Location: executor_dashboard.php');
    exit();
}
?>