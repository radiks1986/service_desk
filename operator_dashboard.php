<?php
// Файл: operator_dashboard.php
// Дашборд для Оператора: просмотр всех заявок с фильтрами, пагинацией, статистикой и поиском

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php'; // Подключаем вспомогательные функции

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') {
    $_SESSION['error_message'] = "Доступ запрещен. Пожалуйста, войдите как оператор.";
    $redirect_location = isset($_SESSION['guest_phone']) ? 'index.php' : 'login.php';
    header('Location: ' . $redirect_location);
    exit();
}

// --- Получение данных оператора ---
$user_id = $_SESSION['user_id'];
$user_name = $_SESSION['full_name'];
$organization_id = $_SESSION['organization_id'];
$db_error = null;

// --- Получение статистики по статусам ---
$status_counts = ['new' => 0, 'in_progress' => 0, 'paused' => 0, 'info_requested' => 0];
$sql_stats = "SELECT status, COUNT(id) as count FROM requests GROUP BY status";
$result_stats = mysqli_query($db, $sql_stats);
if ($result_stats) { while ($row = mysqli_fetch_assoc($result_stats)) { if (array_key_exists($row['status'], $status_counts)) { $status_counts[$row['status']] = (int)$row['count']; } } mysqli_free_result($result_stats);
} else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка получения статистики: ".mysqli_error($db); error_log("MySQLi query error (get status stats): ".mysqli_error($db)); }

// --- Настройки пагинации ---
$results_per_page = 10;
$current_page = isset($_GET['page']) && ctype_digit($_GET['page']) ? (int)$_GET['page'] : 1;
if ($current_page < 1) $current_page = 1;
$start_limit = ($current_page - 1) * $results_per_page;

// --- Получение данных для фильтров ---
$statuses = [ 'new' => 'Новая', 'in_progress' => 'В работе', 'paused' => 'Приостановлена', 'info_requested' => 'Запрос инфо', 'completed' => 'Выполнена', 'cancelled' => 'Отменена' ];
$categories_for_filter = [];
$sql_categories = "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name";
$result_cats = mysqli_query($db, $sql_categories);
if ($result_cats) { while ($row = mysqli_fetch_assoc($result_cats)) { $categories_for_filter[$row['id']] = $row['name']; } mysqli_free_result($result_cats);
} else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка загрузки категорий для фильтра: ".mysqli_error($db); error_log("MySQLi query error (get categories for filter): ".mysqli_error($db)); }

// --- Обработка фильтров и ПОИСКА из GET ---
$filter_status = isset($_GET['status']) && array_key_exists($_GET['status'], $statuses) ? $_GET['status'] : '';
$filter_category = isset($_GET['category_id']) && ctype_digit($_GET['category_id']) ? (int)$_GET['category_id'] : '';
$search_term = isset($_GET['search']) ? trim($_GET['search']) : ''; // Новый параметр поиска
// Сохраняем все параметры для ссылок пагинации
$query_params_array = array_filter(['status' => $filter_status, 'category_id' => $filter_category, 'search' => $search_term]);
$filter_params_query = http_build_query($query_params_array);


// --- Формирование условий WHERE для SQL ---
$where_clauses = []; $params = []; $param_types = "";
if ($filter_status !== '') { $where_clauses[] = "r.status = ?"; $params[] = $filter_status; $param_types .= "s"; }
if ($filter_category !== '') { $where_clauses[] = "r.category_id = ?"; $params[] = $filter_category; $param_types .= "i"; }

// Добавляем условие ПОИСКА
if ($search_term !== '') {
    $search_like = "%" . mysqli_real_escape_string($db, $search_term) . "%"; // Экранируем для LIKE
    // Ищем по ID, описанию, месту, телефону
    $where_clauses[] = "(CAST(r.id AS CHAR) LIKE ? OR r.description LIKE ? OR r.location LIKE ? OR r.guest_phone LIKE ?)";
    // Добавляем параметр 4 раза (для каждого LIKE)
    $params[] = $search_like; $params[] = $search_like; $params[] = $search_like; $params[] = $search_like;
    $param_types .= "ssss"; // 4 строки
     // Примечание: Поиск по ID через CAST...LIKE не очень эффективен, но позволяет искать по части ID.
     // Альтернатива - сначала проверить, является ли $search_term числом, и если да, искать точное совпадение r.id = ?
}

// TODO: Фильтр по организации
$where_sql = !empty($where_clauses) ? " WHERE " . implode(" AND ", $where_clauses) : "";

// --- 1. Получение ОБЩЕГО КОЛИЧЕСТВА заявок (с учетом фильтров и ПОИСКА) ---
$total_requests_filtered = 0;
$sql_count = "SELECT COUNT(r.id) as total FROM requests r" . $where_sql;
$stmt_count = mysqli_prepare($db, $sql_count);
if ($stmt_count) {
    if (!empty($params)) { mysqli_stmt_bind_param($stmt_count, $param_types, ...$params); }
    if (mysqli_stmt_execute($stmt_count)) { $result_count = mysqli_stmt_get_result($stmt_count); $total_requests_filtered = (int)mysqli_fetch_assoc($result_count)['total']; mysqli_free_result($result_count);
    } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка подсчета заявок: ".mysqli_stmt_error($stmt_count); error_log("MySQLi execute error (count requests): ".mysqli_stmt_error($stmt_count)); }
    mysqli_stmt_close($stmt_count);
} else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка подготовки подсчета заявок: ".mysqli_error($db); error_log("MySQLi prepare error (count requests): ".mysqli_error($db)); }
$total_pages = $total_requests_filtered > 0 ? ceil($total_requests_filtered / $results_per_page) : 0;
if ($current_page > $total_pages && $total_pages > 0) { $current_page = $total_pages; $start_limit = ($current_page - 1) * $results_per_page; }
elseif ($current_page < 1) { $current_page = 1; }

// --- 2. Получение заявок ДЛЯ ТЕКУЩЕЙ СТРАНИЦЫ (с учетом фильтров и ПОИСКА) ---
$requests = [];
if ($total_requests_filtered > 0) {
    $sql_base = "SELECT r.id, r.guest_phone, r.location, r.description, r.status, r.created_at, r.updated_at, r.photo_path,
                    c.name as category_name, u.full_name as executor_name, u.id as executor_id
                FROM requests r JOIN categories c ON r.category_id = c.id LEFT JOIN users u ON r.executor_id = u.id";
    $sql_query = $sql_base . $where_sql . " ORDER BY r.created_at DESC LIMIT ?, ?";
    $current_params = $params; $current_params[] = $start_limit; $current_params[] = $results_per_page; $current_param_types = $param_types . "ii";
    $stmt_requests = mysqli_prepare($db, $sql_query);
    if ($stmt_requests) {
        if (!empty($current_params)) { mysqli_stmt_bind_param($stmt_requests, $current_param_types, ...$current_params); }
        if (mysqli_stmt_execute($stmt_requests)) { $result_all = mysqli_stmt_get_result($stmt_requests); while ($row = mysqli_fetch_assoc($result_all)) { $requests[] = $row; } mysqli_free_result($result_all);
        } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка выполнения запроса заявок: ".mysqli_stmt_error($stmt_requests); error_log("MySQLi execute error (get paginated requests): ".mysqli_stmt_error($stmt_requests)); }
        mysqli_stmt_close($stmt_requests);
    } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка подготовки запроса заявок: ".mysqli_error($db); error_log("MySQLi prepare error (get paginated requests): ".mysqli_error($db)); }
}

// --- Получение списка активных исполнителей ---
$executors = []; // ... (код получения исполнителей без изменений) ...
$sql_executors = "SELECT id, full_name FROM users WHERE role = 'executor' AND is_active = 1 ORDER BY full_name";
$result_executors = mysqli_query($db, $sql_executors);
if ($result_executors) { while ($row = mysqli_fetch_assoc($result_executors)) { $executors[] = $row; } mysqli_free_result($result_executors);
} else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка получения списка исполнителей: ".mysqli_error($db); error_log("MySQLi query error (get executors for operator): ".mysqli_error($db)); }

// --- Отображение страницы ---
$page_title = 'Панель оператора';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
     <h1><?php echo $page_title; ?></h1>
     <span><?php echo htmlspecialchars($user_name); ?></span>
</div>

<?php if ($db_error): ?> <div class="alert alert-danger">Ошибка загрузки данных: <?php echo htmlspecialchars($db_error); ?></div> <?php endif; ?>

<?php // --- Блок со статистикой (БЕЗ ИЗМЕНЕНИЙ) --- ?>
<div class="row mb-4">
    <div class="col-md-3 col-6 mb-3"><div class="card text-center h-100"><div class="card-body"><h5 class="card-title display-6"><?php echo $status_counts['new']; ?></h5><p class="card-text text-primary">Новые</p></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="card text-center h-100"><div class="card-body"><h5 class="card-title display-6"><?php echo $status_counts['in_progress']; ?></h5><p class="card-text text-info">В работе</p></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="card text-center h-100"><div class="card-body"><h5 class="card-title display-6"><?php echo $status_counts['paused']; ?></h5><p class="card-text text-warning">На паузе</p></div></div></div>
    <div class="col-md-3 col-6 mb-3"><div class="card text-center h-100"><div class="card-body"><h5 class="card-title display-6"><?php echo $status_counts['info_requested']; ?></h5><p class="card-text text-secondary">Запрос инфо</p></div></div></div>
</div>

<?php // --- Форма фильтров и ПОИСКА --- ?>
<div class="card mb-4">
    <div class="card-body bg-light">
        <form method="GET" action="operator_dashboard.php" class="row g-3 align-items-end">
            <div class="col-lg-3 col-md-6"> <?php // Изменили колонки для лучшего размещения ?>
                <label for="filter_status" class="form-label">Статус</label>
                <select name="status" id="filter_status" class="form-select form-select-sm"><option value="">-- Все статусы --</option><?php foreach ($statuses as $k => $v): ?><option value="<?php echo $k; ?>" <?php echo ($filter_status === $k) ? 'selected' : ''; ?>><?php echo htmlspecialchars($v); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-lg-3 col-md-6">
                 <label for="filter_category" class="form-label">Категория</label>
                 <select name="category_id" id="filter_category" class="form-select form-select-sm"><option value="">-- Все категории --</option><?php foreach ($categories_for_filter as $id => $name): ?><option value="<?php echo $id; ?>" <?php echo ($filter_category === $id) ? 'selected' : ''; ?>><?php echo htmlspecialchars($name); ?></option><?php endforeach; ?></select>
            </div>
            <div class="col-lg-4 col-md-8"> <?php // Поле поиска ?>
                 <label for="search_term" class="form-label">Поиск (ID, описание, место, тел.)</label>
                 <input type="search" class="form-control form-control-sm" id="search_term" name="search" value="<?php echo htmlspecialchars($search_term); ?>">
            </div>
            <div class="col-lg-2 col-md-4 d-flex align-items-end"> <?php // Кнопки: Добавлен d-flex и align-items-end ?>
                 <div class="btn-group btn-group-sm w-100" role="group"> <?php // Обернули в btn-group ?>
                     <button type="submit" class="btn btn-primary">Применить</button>
                     <a href="operator_dashboard.php" class="btn btn-secondary">Сбросить</a>
                 </div>
            </div>
        </form>
    </div>
</div>

<?php // --- Итоги и блок пагинации (НАД ТАБЛИЦЕЙ) --- ?>
<div class="d-flex justify-content-between align-items-center mb-3">
    <h2 class="h4 mb-0">Список заявок <small class="text-muted">(Найдено: <?php echo $total_requests_filtered; ?>)</small></h2>
    <?php if ($total_pages > 1): ?>
    <nav aria-label="Навигация по заявкам">
        <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $current_page - 1; ?>&<?php echo $filter_params_query; ?>" aria-label="Previous"><span aria-hidden="true">«</span></a></li>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?><li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&<?php echo $filter_params_query; ?>"><?php echo $i; ?></a></li><?php endfor; ?>
            <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $current_page + 1; ?>&<?php echo $filter_params_query; ?>" aria-label="Next"><span aria-hidden="true">»</span></a></li>
        </ul>
    </nav>
    <?php endif; ?>
</div>

<div class="table-responsive">
    <?php // Таблица заявок (<thead> и <tbody> без изменений) ?>
     <table class="table table-striped table-hover table-bordered">
        <thead class="table-light">
            <tr><th>ID</th><th>Статус</th><th>Категория</th><th>Место</th><th>Описание</th><th>Фото</th><th>Исполнитель</th><th>Телефон гостя</th><th>Создана</th><th>Действия</th></tr>
        </thead>
        <tbody>
            <?php if (empty($requests) && !$db_error): ?>
                <tr><td colspan="10" class="text-center text-muted"><?php if ($filter_status !== '' || $filter_category !== '' || $search_term !== ''): ?>Заявок, соответствующих критериям, не найдено. <a href="operator_dashboard.php">Сбросить все</a>?<?php else: ?>Заявок пока нет.<?php endif; ?></td></tr>
            <?php elseif (!empty($requests)): ?>
                <?php foreach ($requests as $request): ?>
                     <tr>
                         <td><a href="view_request.php?id=<?php echo $request['id']; ?>" title="Подробнее"><?php echo $request['id']; ?></a></td>
                         <td><?php echo getStatusBadge($request['status']); ?></td>
                         <td><?php echo htmlspecialchars($request['category_name']); ?></td>
                         <td><?php echo htmlspecialchars($request['location']); ?></td>
                         <td><?php echo nl2br(htmlspecialchars(mb_substr($request['description'], 0, 100) . (mb_strlen($request['description']) > 100 ? '...' : ''))); ?></td>
                         <td><?php if ($request['photo_path']): ?><a href="<?php echo htmlspecialchars($request['photo_path']); ?>" target="_blank" title="Посмотреть фото"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="currentColor" class="bi bi-camera-fill" viewBox="0 0 16 16"><path d="M10.5 8.5a2.5 2.5 0 1 1-5 0 2.5 2.5 0 0 1 5 0"/><path d="M2 4a2 2 0 0 0-2 2v6a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V6a2 2 0 0 0-2-2h-1.172a2 2 0 0 1-1.414-.586L11 2.5a2 2 0 0 0-1.414-.586H6.414A2 2 0 0 0 5 2.5l-.828.828A2 2 0 0 1 2.828 4zm.828 1a.5.5 0 1 1-.828-.414.5.5 0 0 1 .828.414"/></svg></a><?php else: ?><small class="text-muted">Нет</small><?php endif; ?></td>
                         <td><?php if ($request['executor_name']): ?><?php echo htmlspecialchars($request['executor_name']); ?><?php else: ?><em class="text-muted">Не назначен</em><?php endif; ?></td>
                         <td><?php echo htmlspecialchars($request['guest_phone']); ?></td>
                         <td><?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?></td>
                         <td><?php if (!in_array($request['status'], ['completed', 'cancelled'])): ?><form method="POST" action="operator_actions.php" class="d-inline-block align-top"><?php echo csrfInput();?><input type="hidden" name="action" value="assign"><input type="hidden" name="request_id" value="<?php echo $request['id']; ?>"><div class="input-group input-group-sm"><select class="form-select" name="executor_id" title="Выберите исполнителя" <?php if(empty($executors)) echo 'disabled'; ?>><option value="">-- Снять/Назначить --</option><?php foreach ($executors as $executor): ?><?php $selected = ($request['executor_id'] == $executor['id']) ? 'selected' : ''; ?><option value="<?php echo $executor['id']; ?>" <?php echo $selected; ?>><?php echo htmlspecialchars($executor['full_name']); ?></option><?php endforeach; ?><option value="0">-- Снять исполнителя --</option></select><button class="btn btn-outline-primary" type="submit" title="Назначить/Снять исполнителя" <?php if(empty($executors)) echo 'disabled'; ?>>OK</button></div></form><?php else: ?><small class="text-muted">Нет действий</small><?php endif; ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php // --- Блок пагинации (ПОД ТАБЛИЦЕЙ) --- ?>
<?php if ($total_pages > 1): ?>
<div class="d-flex justify-content-center mt-3">
    <nav aria-label="Навигация по заявкам">
        <ul class="pagination pagination-sm">
             <li class="page-item <?php echo ($current_page <= 1) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $current_page - 1; ?>&<?php echo $filter_params_query; ?>" aria-label="Previous"><span aria-hidden="true">«</span></a></li>
             <?php for ($i = 1; $i <= $total_pages; $i++): ?> <li class="page-item <?php echo ($i == $current_page) ? 'active' : ''; ?>"><a class="page-link" href="?page=<?php echo $i; ?>&<?php echo $filter_params_query; ?>"><?php echo $i; ?></a></li> <?php endfor; ?>
             <li class="page-item <?php echo ($current_page >= $total_pages) ? 'disabled' : ''; ?>"><a class="page-link" href="?page=<?php echo $current_page + 1; ?>&<?php echo $filter_params_query; ?>" aria-label="Next"><span aria-hidden="true">»</span></a></li>
        </ul>
    </nav>
</div>
<?php endif; ?>

<?php
if ($db) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php';
?>