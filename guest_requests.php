<?php
// Файл: guest_requests.php
// Страница со списком заявок Гостя и кнопкой создания новой

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db теперь доступна
require_once __DIR__ . '/includes/helpers.php'; // Подключаем вспомогательные функции

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка, не вошел ли сотрудник ---
if (isset($_SESSION['user_id'])) {
    $_SESSION['warning_message'] = "Вы вошли как сотрудник. Используйте панель персонала.";
    if ($_SESSION['role'] === 'executor') {
        header('Location: executor_dashboard.php');
    } elseif ($_SESSION['role'] === 'operator') {
        header('Location: operator_dashboard.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

// --- Проверка аутентификации Гостя ---
if (!isset($_SESSION['guest_phone'])) {
    $_SESSION['error_message'] = "Пожалуйста, войдите, указав ваш номер телефона.";
    header('Location: index.php');
    exit();
}

$guest_phone = $_SESSION['guest_phone']; // Получаем номер телефона из сессии

// --- Получение заявок из БД ---
$requests = []; // Массив для хранения заявок
$error_message = null; // Сообщение об ошибке при запросе

// Готовим SQL запрос для выборки заявок текущего гостя
// Выбираем также имя категории и путь к фото
$sql = "SELECT r.id, r.location, r.description, r.status, r.created_at, r.photo_path, c.name as category_name
        FROM requests r
        JOIN categories c ON r.category_id = c.id
        WHERE r.guest_phone = ?
        ORDER BY r.created_at DESC"; // Сортируем по дате создания, новые сверху

$stmt = mysqli_prepare($db, $sql);
if ($stmt) {
    mysqli_stmt_bind_param($stmt, "s", $guest_phone);
    if (mysqli_stmt_execute($stmt)) {
        $result = mysqli_stmt_get_result($stmt);
        while ($row = mysqli_fetch_assoc($result)) {
            $requests[] = $row;
        }
        mysqli_free_result($result);
    } else {
        $error_message = "Ошибка получения списка заявок: " . mysqli_stmt_error($stmt);
        error_log("MySQLi execute error: " . mysqli_stmt_error($stmt) . " SQL: " . $sql . " Phone: " . $guest_phone);
    }
    mysqli_stmt_close($stmt);
} else {
    $error_message = "Ошибка подготовки запроса к базе данных: " . mysqli_error($db);
    error_log("MySQLi prepare error: " . mysqli_error($db) . " SQL: " . $sql);
}

// --- Отображение страницы ---
$page_title = 'Мои заявки';
require_once __DIR__ . '/includes/header.php'; // Подключаем шапку

?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1><?php echo $page_title; ?></h1>
    <a href="create_request.php" class="btn btn-success">Создать новую заявку</a>
</div>

<?php if ($error_message): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($error_message); ?>
        <br>Пожалуйста, попробуйте обновить страницу позже или обратитесь в поддержку.
    </div>
<?php endif; ?>


<?php if (empty($requests) && !$error_message): ?>
    <div class="alert alert-info text-center">
        У вас пока нет созданных заявок.
    </div>
<?php elseif (!empty($requests)): ?>
    <div class="list-group">
        <?php foreach ($requests as $request): ?>
            <div class="list-group-item list-group-item-action flex-column align-items-start mb-2">
                <div class="d-flex w-100 justify-content-between">
                    <h5 class="mb-1">
                        Заявка #<?php echo $request['id']; ?> -
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($request['category_name']); ?></span>
                         <?php if ($request['photo_path']): // Если есть фото, показываем иконку ?>
                           <a href="<?php echo htmlspecialchars($request['photo_path']); ?>" target="_blank" title="Посмотреть фото">
                                <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-image" viewBox="0 0 16 16">
                                    <path d="M6.002 5.5a1.5 1.5 0 1 1-3 0 1.5 1.5 0 0 1 3 0"/>
                                    <path d="M2.002 1a2 2 0 0 0-2 2v10a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V3a2 2 0 0 0-2-2zm12 1a1 1 0 0 1 1 1v6.5l-3.777-1.947a.5.5 0 0 0-.577.093l-3.71 3.71-2.66-1.772a.5.5 0 0 0-.63.055L1.5 12.5V3a1 1 0 0 1 1-1z"/>
                                </svg>
                            </a>
                        <?php endif; ?>
                    </h5>
                    <small class="text-muted"><?php echo date('d.m.Y H:i', strtotime($request['created_at'])); ?></small>
                </div>
                <p class="mb-1">
                    <strong>Место:</strong> <?php echo htmlspecialchars($request['location']); ?>
                </p>
                <p class="mb-1">
                    <strong>Описание:</strong> <?php echo nl2br(htmlspecialchars($request['description'])); ?>
                </p>
                <small>
                    <strong>Статус:</strong>
                    <?php
                    // Используем функцию из helpers.php
                    echo getStatusBadge($request['status']);
                    ?>
                </small>
                <a href="view_request.php?id=<?php echo $request['id']; ?>" class="btn btn-sm btn-outline-info">Подробнее</a>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>


<?php
mysqli_close($db); // Закрываем соединение с БД
require_once __DIR__ . '/includes/footer.php'; // Подключаем подвал
?>