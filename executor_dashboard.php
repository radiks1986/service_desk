<?php
// Файл: executor_dashboard.php
// Дашборд для исполнителя: просмотр новых и текущих заявок

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php'; // Подключаем вспомогательные функции

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'executor') {
    $_SESSION['error_message'] = "Доступ запрещен. Пожалуйста, войдите как исполнитель.";
    $redirect_location = isset($_SESSION['guest_phone']) ? 'index.php' : 'login.php';
    header('Location: ' . $redirect_location);
    exit();
}

// --- Получение данных исполнителя ---
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];
$db_error = null;

// --- 1. Получение категорий, которые обслуживает исполнитель ---
$executor_categories_ids = [];
$sql_get_cats = "SELECT category_id FROM executor_categories WHERE user_id = ?";
$stmt_cats = mysqli_prepare($db, $sql_get_cats);
if ($stmt_cats) { mysqli_stmt_bind_param($stmt_cats, "i", $user_id); mysqli_stmt_execute($stmt_cats); $result_cats = mysqli_stmt_get_result($stmt_cats); while ($row = mysqli_fetch_assoc($result_cats)) { $executor_categories_ids[] = $row['category_id']; } mysqli_free_result($result_cats); mysqli_stmt_close($stmt_cats);
} else { $db_error = "Ошибка получения категорий исполнителя: " . mysqli_error($db); error_log("MySQLi prepare error (get executor cats): " . mysqli_error($db)); }

// --- 2. Получение новых заявок в категориях исполнителя ---
$new_requests = [];
if (!$db_error && !empty($executor_categories_ids)) {
    $placeholders = implode(',', array_fill(0, count($executor_categories_ids), '?')); $types = str_repeat('i', count($executor_categories_ids));
    $sql_new = "SELECT r.id, r.location, r.description, r.created_at, r.photo_path, c.name as category_name, r.status FROM requests r JOIN categories c ON r.category_id = c.id WHERE r.status = 'new' AND r.category_id IN ($placeholders) ORDER BY r.created_at ASC"; // Добавили r.status
    $stmt_new = mysqli_prepare($db, $sql_new);
    if ($stmt_new) { mysqli_stmt_bind_param($stmt_new, $types, ...$executor_categories_ids); if (mysqli_stmt_execute($stmt_new)) { $result_new = mysqli_stmt_get_result($stmt_new); while ($row = mysqli_fetch_assoc($result_new)) { $new_requests[] = $row; } mysqli_free_result($result_new); } else { $db_error = ($db_error? $db_error.'; ':'')."Ошибка получения новых заявок: ".mysqli_stmt_error($stmt_new); error_log("MySQLi execute error (get new requests): ".mysqli_stmt_error($stmt_new)); } mysqli_stmt_close($stmt_new);
    } else { $db_error = ($db_error? $db_error.'; ':'')."Ошибка подготовки запроса новых заявок: ".mysqli_error($db); error_log("MySQLi prepare error (get new requests): ".mysqli_error($db)); }
}

// --- 3. Получение заявок, назначенных этому исполнителю (в работе) ---
$my_requests = [];
if (!$db_error) {
    $sql_my = "SELECT r.id, r.location, r.description, r.status, r.created_at, r.updated_at, r.photo_path, c.name as category_name FROM requests r JOIN categories c ON r.category_id = c.id WHERE r.executor_id = ? AND r.status NOT IN ('completed', 'cancelled') ORDER BY r.updated_at DESC";
    $stmt_my = mysqli_prepare($db, $sql_my);
    if ($stmt_my) { mysqli_stmt_bind_param($stmt_my, "i", $user_id); if (mysqli_stmt_execute($stmt_my)) { $result_my = mysqli_stmt_get_result($stmt_my); while ($row = mysqli_fetch_assoc($result_my)) { $my_requests[] = $row; } mysqli_free_result($result_my); } else { $db_error = ($db_error? $db_error.'; ':'')."Ошибка получения ваших заявок: ".mysqli_stmt_error($stmt_my); error_log("MySQLi execute error (get my requests): ".mysqli_stmt_error($stmt_my)); } mysqli_stmt_close($stmt_my);
    } else { $db_error = ($db_error? $db_error.'; ':'')."Ошибка подготовки запроса ваших заявок: ".mysqli_error($db); error_log("MySQLi prepare error (get my requests): ".mysqli_error($db)); }
}

// --- Отображение страницы ---
$page_title = 'Панель исполнителя';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
     <h1><?php echo $page_title; ?></h1>
     <span><?php echo htmlspecialchars($user_name); ?></span>
</div>

<?php if ($db_error): ?> <div class="alert alert-danger">Ошибка загрузки данных: <?php echo htmlspecialchars($db_error); ?></div> <?php endif; ?>

<?php // --- Блок новых заявок --- ?>
<div class="card mb-4">
    <div class="card-header"><h2 class="h5 mb-0">Новые заявки в ваших категориях (<span id="new-requests-count"><?php echo count($new_requests); ?></span>)</h2></div>
    <div class="card-body">
        <?php if (empty($new_requests) && empty($executor_categories_ids) && !$db_error): ?><p class="text-muted">Вы не подписаны ни на одну категорию заявок.</p>
        <?php elseif (empty($new_requests) && !$db_error): ?><p class="text-muted">Нет новых заявок, доступных для взятия в работу.</p>
        <?php elseif (!empty($new_requests)): ?>
            <div id="new-requests-list" class="list-group list-group-flush executor-request-list"> <?php // Класс executor-request-list добавлен ?>
                <?php foreach ($new_requests as $request): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center" id="request-item-<?php echo $request['id']; ?>">
                        <div class="flex-grow-1 me-3">
                            <h6 class="mb-1"> <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($request['category_name']); ?></span> Заявка #<?php echo $request['id']; ?> от <?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?> <?php if ($request['photo_path']): ?><a href="<?php echo htmlspecialchars($request['photo_path']); ?>" target="_blank" title="Посмотреть фото" class="ms-1"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-image" viewBox="0 0 16 16"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.055L1.5 12.5V3a1 1 0 0 1 1-1z"/></svg></a><?php endif; ?> </h6>
                            <p class="mb-1"><strong>Место:</strong> <?php echo htmlspecialchars($request['location']); ?></p>
                            <small class="text-muted"><?php echo nl2br(htmlspecialchars(mb_substr($request['description'], 0, 150) . (mb_strlen($request['description']) > 150 ? '...' : ''))); ?></small>
                        </div>
                        <div class="flex-shrink-0 request-actions-dropdown"> <?php // Класс для JS ?>
                             <div class="btn-group btn-group-sm" role="group">
                                <button id="newRequestActionsDropdown<?php echo $request['id']; ?>_<?php echo time(); ?>" type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false"> Действия </button> <?php // Добавили time() к ID ?>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="newRequestActionsDropdown<?php echo $request['id']; ?>_<?php echo time(); ?>">
                                    <li><a class="dropdown-item" href="view_request.php?id=<?php echo $request['id']; ?>">Подробнее</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="take"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-success">Взять в работу</button></form></li>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php // --- Блок заявок в работе --- ?>
<div class="card">
    <div class="card-header"><h2 class="h5 mb-0">Заявки в вашей работе (<span id="my-requests-count"><?php echo count($my_requests); ?></span>)</h2></div>
     <div class="card-body">
        <?php if (empty($my_requests) && !$db_error): ?><p class="text-muted">У вас нет заявок, находящихся в работе.</p>
        <?php elseif(!empty($my_requests)): ?>
            <div id="my-requests-list" class="list-group list-group-flush executor-request-list"> <?php // Класс executor-request-list добавлен ?>
                <?php foreach ($my_requests as $request): ?>
                    <div class="list-group-item d-flex justify-content-between align-items-center" id="request-item-<?php echo $request['id']; ?>">
                        <div class="flex-grow-1 me-3">
                           <h6 class="mb-1"> <span class="badge bg-secondary me-1"><?php echo htmlspecialchars($request['category_name']); ?></span> Заявка #<?php echo $request['id']; ?> <?php if ($request['photo_path']): ?><a href="<?php echo htmlspecialchars($request['photo_path']); ?>" target="_blank" title="Посмотреть фото" class="ms-1"><svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-image" viewBox="0 0 16 16"><path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/><path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.055L1.5 12.5V3a1 1 0 0 1 1-1z"/></svg></a><?php endif; ?> </h6>
                           <p class="mb-1"><strong>Место:</strong> <?php echo htmlspecialchars($request['location']); ?></p>
                           <small><strong>Статус:</strong> <span class="request-status-badge"><?php echo getStatusBadge($request['status']); ?></span></small> <?php // Класс для JS ?>
                           <small class="text-muted ms-2">(Обновлено: <?php echo date('d.m H:i', strtotime($request['updated_at'])); ?>)</small>
                       </div>
                       <div class="flex-shrink-0 request-actions-dropdown"> <?php // Класс для JS ?>
                           <div class="btn-group btn-group-sm">
                               <?php // Кнопка и dropdown - генерируется через JS при обновлении, но нужен первоначальный HTML ?>
                               <button id="myRequestActionsDropdown<?php echo $request['id']; ?>_<?php echo time(); ?>" type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">Действия</button> <?php // Добавили time() к ID ?>
                               <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="myRequestActionsDropdown<?php echo $request['id']; ?>_<?php echo time(); ?>">
                                    <li><a class="dropdown-item" href="view_request.php?id=<?php echo $request['id']; ?>">Подробнее</a></li>
                                    <li><hr class="dropdown-divider"></li>
                                     <?php if ($request['status'] === 'in_progress'): ?>
                                        <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="pause"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-warning">Приостановить</button></form></li>
                                        <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="request_info"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item">Запросить информацию</button></form></li>
                                        <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="complete"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-primary">Завершить</button></form></li>
                                     <?php elseif ($request['status'] === 'paused' || $request['status'] === 'info_requested'): ?>
                                          <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="resume"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-success">Возобновить</button></form></li>
                                          <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="complete"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-primary">Завершить</button></form></li>
                                     <?php endif; ?>
                                     <li><hr class="dropdown-divider"></li>
                                     <li><form method="POST" action="executor_actions.php" class="d-inline w-100"><?php echo csrfInput(); ?><input type="hidden" name="action" value="cancel"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><button type="submit" class="dropdown-item text-danger">Отменить заявку</button></form></li>
                               </ul>
                           </div>
                       </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
if ($db) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php'; // Убедитесь, что footer.php подключает js/app.js и содержит контейнер для toast
?>