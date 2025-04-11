<?php
// Файл: operator_actions.php
// Обработчик действий Оператора (назначение исполнителя) (с CSRF и AJAX/JSON и ИСТОРИЕЙ)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // Подключаем БД
require_once __DIR__ . '/includes/helpers.php';   // Подключаем хелперы (для CSRF)

if (session_status() == PHP_SESSION_NONE) { session_start(); }

// --- Проверки аутентификации, роли, метода, CSRF ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') { /*...*/ }
$operator_id = $_SESSION['user_id'];
$response = ['success' => false, 'message' => 'Неизвестная ошибка.'];
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { /*...*/ }
$submitted_token = $_POST['csrf_token'] ?? null; if (!validateCsrfToken($submitted_token)) { /*...*/ }

// --- Получение данных и Валидация ---
$action = isset($_POST['action']) ? trim($_POST['action']) : null;
$request_id = isset($_POST['request_id']) ? (int)$_POST['request_id'] : null;
$executor_id_input = isset($_POST['executor_id']) ? trim($_POST['executor_id']) : null;
$new_executor_id = null; if ($executor_id_input !== null && $executor_id_input !== '' && $executor_id_input !== '0') { $new_executor_id = (int)$executor_id_input; }
if (!$action || !$request_id || $request_id <= 0 || $executor_id_input === null) { $response['message'] = "Некорректные данные."; goto send_response_op; }
if ($new_executor_id !== null && $new_executor_id <= 0) { $response['message'] = "Некорректный ID исполнителя."; goto send_response_op; }

// --- Проверка заявки ---
$request_data = null; $sql_get_request = "SELECT id, status, executor_id FROM requests WHERE id = ?";
$stmt_get = mysqli_prepare($db, $sql_get_request); if (!$stmt_get) { $response['message']="DB Error Prepare Get"; goto send_response_op; }
mysqli_stmt_bind_param($stmt_get, "i", $request_id); if (!mysqli_stmt_execute($stmt_get)) { $response['message']="DB Error Execute Get"; mysqli_stmt_close($stmt_get); goto send_response_op; }
$result_get = mysqli_stmt_get_result($stmt_get); $request_data = mysqli_fetch_assoc($result_get); mysqli_free_result($result_get); mysqli_stmt_close($stmt_get);
if (!$request_data) { $response['message'] = "Заявка #{$request_id} не найдена."; $response['not_found'] = true; goto send_response_op; }
if (in_array($request_data['status'], ['completed', 'cancelled'])) { $response['message'] = "Нельзя изменить исполнителя для зав. #{$request_id}."; $response['forbidden'] = true; goto send_response_op; }

// --- Основная логика с транзакцией и ИСТОРИЕЙ ---
$sql_update = null; $params = []; $param_types = ""; $success_message = ""; $warning_message = null;

mysqli_begin_transaction($db);
try {
    switch ($action) {
        case 'assign':
            // Проверка существования исполнителя (если назначаем)
            if ($new_executor_id !== null) {
                $sql_check_executor = "SELECT 1 FROM users WHERE id = ? AND role = 'executor' AND is_active = 1"; $stmt_check = mysqli_prepare($db, $sql_check_executor); mysqli_stmt_bind_param($stmt_check, "i", $new_executor_id); mysqli_stmt_execute($stmt_check); mysqli_stmt_store_result($stmt_check); $executor_exists = mysqli_stmt_num_rows($stmt_check) > 0; mysqli_stmt_close($stmt_check);
                if (!$executor_exists) { throw new Exception("Выбранный исполнитель не найден или неактивен."); }
            }

            $current_executor_id = $request_data['executor_id'];
            $current_status = $request_data['status'];
            $status_to_set = $current_status;
            $executor_id_to_update = null; // Что записываем в executor_id
            $history_action_type = ''; $history_old_value = $current_executor_id; $history_new_value = null; $history_comment = 'Оператор ID: ' . $operator_id;
            $log_status_change = false;

            // Логика определения действия
            if ($new_executor_id !== null) { // НАЗНАЧАЕМ
                if ($new_executor_id == $current_executor_id) { $warning_message = "Исполнитель уже назначен."; goto end_assign_logic_op; }
                if ($current_status == 'new') { $status_to_set = 'in_progress'; $log_status_change = true; }
                $executor_id_to_update = $new_executor_id; $history_action_type = $current_executor_id === null ? 'Исполнитель назначен' : 'Исполнитель изменен'; $history_new_value = $new_executor_id;
                $sql_update = "UPDATE requests SET executor_id = ?, status = ?, updated_at = NOW() WHERE id = ?"; $param_types = "isi"; $params = [$executor_id_to_update, $status_to_set, $request_id]; $success_message = "Исполнитель назначен/изменен.";
            } else { // СНИМАЕМ
                if ($current_executor_id === null) { $warning_message = "Исполнитель не был назначен."; goto end_assign_logic_op; }
                $status_to_set = 'new'; $log_status_change = ($current_status !== 'new'); $executor_id_to_update = null; $history_action_type = 'Исполнитель снят'; $history_new_value = null;
                $sql_update = "UPDATE requests SET executor_id = NULL, status = ?, updated_at = NOW() WHERE id = ?"; $param_types = "si"; $params = [$status_to_set, $request_id]; $success_message = "Исполнитель снят, статус изменен на 'Новая'.";
            }

            // Выполнение UPDATE (если не было warning)
            if ($warning_message === null && $sql_update) {
                $stmt_update = mysqli_prepare($db, $sql_update); if (!$stmt_update) throw new Exception("DB Error Prepare Update: ".mysqli_error($db));
                mysqli_stmt_bind_param($stmt_update, $param_types, ...$params); if (!mysqli_stmt_execute($stmt_update)) { throw new Exception("DB Error Execute Update: ".mysqli_stmt_error($stmt_update)); }
                $affected_rows = mysqli_stmt_affected_rows($stmt_update); mysqli_stmt_close($stmt_update);

                if ($affected_rows >= 0) { // >= 0 т.к. update того же статуса может не затронуть строки
                    // --- ЗАПИСЬ В ИСТОРИЮ (Исполнитель) ---
                    $sql_h_exec = "INSERT INTO request_history (request_id, user_id, action_type, old_value, new_value, comment) VALUES (?, ?, ?, ?, ?, ?)"; $stmt_h_exec = mysqli_prepare($db, $sql_h_exec); if (!$stmt_h_exec) throw new Exception("Ист. Исп. Prep Err: ".mysqli_error($db));
                    mysqli_stmt_bind_param($stmt_h_exec, "iissss", $request_id, $operator_id, $history_action_type, $history_old_value, $history_new_value, $history_comment); if (!mysqli_stmt_execute($stmt_h_exec)) { throw new Exception("Ист. Исп. Exec Err: ".mysqli_stmt_error($stmt_h_exec)); } mysqli_stmt_close($stmt_h_exec);

                    // --- ЗАПИСЬ В ИСТОРИЮ (Статус, если изменился) ---
                    if ($log_status_change) {
                        $sql_h_status = "INSERT INTO request_history (request_id, user_id, action_type, old_value, new_value, comment) VALUES (?, ?, ?, ?, ?, ?)"; $stmt_h_status = mysqli_prepare($db, $sql_h_status); if (!$stmt_h_status) throw new Exception("Ист. Статус Prep Err: ".mysqli_error($db));
                        $st_action = 'Статус изменен'; $st_comment = $history_comment; // Комментарий тот же - действие оператора
                        mysqli_stmt_bind_param($stmt_h_status, "iissss", $request_id, $operator_id, $st_action, $current_status, $status_to_set, $st_comment); if (!mysqli_stmt_execute($stmt_h_status)) { throw new Exception("Ист. Статус Exec Err: ".mysqli_stmt_error($stmt_h_status)); } mysqli_stmt_close($stmt_h_status);
                    }
                    $response = ['success' => true, 'message' => $success_message]; // Успех
                } else {
                    mysqli_rollback($db); throw new Exception("Не удалось обновить заявку."); // Странная ситуация
                }
            } elseif ($warning_message !== null) {
                 $response = ['success' => true, 'message' => $warning_message, 'warning' => true]; mysqli_rollback($db);
            } elseif (!$warning_message && !$sql_update) {
                 throw new Exception("Действие '$action' не привело к изменению данных.");
            }
            end_assign_logic_op: // Метка для goto
            break; // Конец case 'assign'
        default: throw new Exception("Неизвестное действие оператора: '$action'."); break;
    } // Конец switch

    // Коммитим только если не было предупреждения и был выполнен SQL
    if ($warning_message === null && $sql_update) { mysqli_commit($db); }

} catch (Exception $e) {
    mysqli_rollback($db); $response = ['success' => false, 'message' => $e->getMessage()]; error_log("Operator action error: ".$e->getMessage()." | ReqID:{$request_id}, OpID:{$operator_id}, Act:{$action}, TargetExecID:{$executor_id_input}");
}

// Метка для goto
send_response_op:

// --- Закрытие соединения и РЕДИРЕКТ или JSON ОТВЕТ ---
if (isset($db) && $db && mysqli_ping($db)) { mysqli_close($db); }

$is_ajax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest');
if ($is_ajax) {
    header('Content-Type: application/json'); $http_code = 200;
    if (!$response['success']) { $http_code = 400; if (isset($response['not_found'])) $http_code = 404; if (isset($response['forbidden'])) $http_code = 403; }
    http_response_code($http_code); echo json_encode($response); exit();
} else {
    if (isset($response['warning']) && $response['warning']) { $_SESSION['warning_message'] = $response['message']; }
    elseif ($response['success']) { $_SESSION['success_message'] = $response['message']; }
    else { $_SESSION['error_message'] = $response['message']; }
    header('Location: operator_dashboard.php'); exit();
}
?>