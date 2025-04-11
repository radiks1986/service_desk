<?php
// Файл: view_request.php
// Страница для просмотра деталей одной заявки

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php';
require_once __DIR__ . '/includes/helpers.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Получение ID заявки из GET-параметра ---
$request_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($request_id <= 0) {
    $_SESSION['error_message'] = "Некорректный ID заявки.";
    header('Location: index.php'); // Или другая страница по умолчанию
    exit();
}

// --- Определение роли пользователя и проверка доступа ---
$user_role = null;
$user_id = null;
$guest_phone = null;
$can_view = false; // Флаг, разрешен ли просмотр

if (isset($_SESSION['user_id'])) { // Сотрудник
    $user_id = $_SESSION['user_id'];
    $user_role = $_SESSION['role'];
} elseif (isset($_SESSION['guest_phone'])) { // Гость
    $guest_phone = $_SESSION['guest_phone'];
} else { // Аноним
    $_SESSION['error_message'] = "Пожалуйста, войдите для просмотра заявки.";
    header('Location: index.php');
    exit();
}

// --- Получение данных заявки из БД ---
$request = null;
$db_error = null;

$sql = "SELECT
            r.*, -- Все поля из requests
            c.name as category_name,
            u.full_name as executor_name
        FROM requests r
        JOIN categories c ON r.category_id = c.id
        LEFT JOIN users u ON r.executor_id = u.id
        WHERE r.id = ?";

$stmt = mysqli_prepare($db, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "i", $request_id);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        $request = mysqli_fetch_assoc($result);
        mysqli_free_result($result);
    } else {
        $db_error = "Ошибка получения данных заявки: " . mysqli_stmt_error($stmt);
        error_log("MySQLi execute error (get request details): " . mysqli_stmt_error($stmt));
    }
    mysqli_stmt_close($stmt);
} else {
    $db_error = "Ошибка подготовки запроса: " . mysqli_error($db);
    error_log("MySQLi prepare error (get request details): " . mysqli_error($db));
}

// --- Проверка, найдена ли заявка ---
if (!$db_error && !$request) {
    $_SESSION['error_message'] = "Заявка с ID #{$request_id} не найдена.";
    // Перенаправляем в зависимости от роли
    if ($user_role === 'operator') header('Location: operator_dashboard.php');
    elseif ($user_role === 'executor') header('Location: executor_dashboard.php');
    elseif ($guest_phone) header('Location: guest_requests.php');
    else header('Location: index.php');
    exit();
}

// --- Проверка прав доступа к этой заявке ---
if (!$db_error && $request) {
    if ($user_role === 'operator') {
        // Оператор может видеть все (в будущем - своей организации)
        $can_view = true;
    } elseif ($user_role === 'executor') {
        // Исполнитель может видеть заявки, назначенные ему ИЛИ новые в его категориях
        if ($request['executor_id'] === $user_id) {
            $can_view = true;
        } else if ($request['status'] === 'new') {
            // Проверяем, есть ли у него доступ к категории
            $sql_check_cat = "SELECT COUNT(*) as cnt FROM executor_categories WHERE user_id = ? AND category_id = ?";
            $stmt_check_cat = mysqli_prepare($db, $sql_check_cat);
            mysqli_stmt_bind_param($stmt_check_cat, "ii", $user_id, $request['category_id']);
            mysqli_stmt_execute($stmt_check_cat);
            $result_check_cat = mysqli_stmt_get_result($stmt_check_cat);
            if (mysqli_fetch_assoc($result_check_cat)['cnt'] > 0) {
                $can_view = true;
            }
            mysqli_free_result($result_check_cat);
            mysqli_stmt_close($stmt_check_cat);
        }
    } elseif ($guest_phone) {
        // Гость может видеть только свои заявки
        if ($request['guest_phone'] === $guest_phone) {
            $can_view = true;
        }
    }
}

// Если просмотр не разрешен
if (!$can_view && !$db_error) {
     $_SESSION['error_message'] = "У вас нет прав на просмотр заявки #{$request_id}.";
     // Перенаправляем в зависимости от роли
     if ($user_role === 'operator') header('Location: operator_dashboard.php');
     elseif ($user_role === 'executor') header('Location: executor_dashboard.php');
     elseif ($guest_phone) header('Location: guest_requests.php');
     else header('Location: index.php');
     exit();
}

// --- Определение ссылки "Назад" ---
$back_link = 'index.php'; // По умолчанию
if ($user_role === 'operator') $back_link = 'operator_dashboard.php';
elseif ($user_role === 'executor') $back_link = 'executor_dashboard.php';
elseif ($guest_phone) $back_link = 'guest_requests.php';


// --- Отображение страницы ---
$page_title = "Детали заявки #" . $request_id;
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <h1><?php echo $page_title; ?></h1>
    <a href="<?php echo $back_link; ?>" class="btn btn-secondary">Назад к списку</a>
</div>

<?php if ($db_error): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($db_error); ?>
    </div>
<?php elseif ($request): ?>
    <div class="card">
        <div class="card-header">
            <div class="d-flex justify-content-between">
                <span>Категория: <strong><?php echo htmlspecialchars($request['category_name']); ?></strong></span>
                <span>Статус: <?php echo getStatusBadge($request['status']); ?></span>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-<?php echo $request['photo_path'] ? '8' : '12'; ?>"> <?php // Делаем колонку шире, если нет фото ?>
                    <p><strong>Место:</strong> <?php echo htmlspecialchars($request['location']); ?></p>
                    <p><strong>Телефон гостя:</strong> <?php echo htmlspecialchars($request['guest_phone']); ?></p>
                    <p><strong>Описание:</strong></p>
                    <div class="bg-light p-3 rounded border mb-3">
                        <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                    </div>
                    <p><strong>Исполнитель:</strong> <?php echo $request['executor_name'] ? htmlspecialchars($request['executor_name']) : '<em class="text-muted">Не назначен</em>'; ?></p>
                </div>
                <?php if ($request['photo_path']): ?>
                <div class="col-md-4">
                    <p><strong>Прикрепленное фото:</strong></p>
                    <a href="<?php echo htmlspecialchars($request['photo_path']); ?>" target="_blank">
                        <img src="<?php echo htmlspecialchars($request['photo_path']); ?>" alt="Фото к заявке #<?php echo $request['id']; ?>" class="img-fluid img-thumbnail" style="max-height: 300px;">
                    </a>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <div class="card-footer text-muted">
             <div class="d-flex justify-content-between">
                 <span>Создана: <?php echo date('d.m.Y H:i:s', strtotime($request['created_at'])); ?></span>
                 <span>Обновлена: <?php echo date('d.m.Y H:i:s', strtotime($request['updated_at'])); ?></span>
             </div>
        </div>
    </div>

    <?php // TODO: Добавить блок с историей изменений статусов, если нужно ?>

<?php endif; ?>


<?php
if ($db) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php';
?>