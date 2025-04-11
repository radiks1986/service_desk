<?php
// Файл: create_request.php
// Страница создания новой заявки Гостем

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php'; // Подключаем хелперы (для CSRF)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации Гостя ---
if (!isset($_SESSION['guest_phone'])) {
    $_SESSION['error_message'] = "Пожалуйста, войдите, чтобы создать заявку.";
    header('Location: index.php');
    exit();
}
$guest_phone = $_SESSION['guest_phone'];

// --- Проверка, не вошел ли сотрудник ---
if (isset($_SESSION['user_id'])) {
    $_SESSION['warning_message'] = "Вы вошли как сотрудник. Используйте панель персонала.";
    if ($_SESSION['role'] === 'executor') { header('Location: executor_dashboard.php'); }
    elseif ($_SESSION['role'] === 'operator') { header('Location: operator_dashboard.php'); }
    else { header('Location: index.php'); }
    exit();
}

// --- Получение активных категорий ---
$categories = []; $category_error = null;
$sql_categories = "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name ASC";
$result = mysqli_query($db, $sql_categories);
if ($result) { while ($row = mysqli_fetch_assoc($result)) { $categories[] = $row; } mysqli_free_result($result); }
else { $category_error = "Ошибка загрузки категорий: ".mysqli_error($db); error_log("MySQLi cat load error: ".mysqli_error($db)); }

// --- Обработка отправки формы ---
$errors = []; $location = ''; $description = ''; $category_id = ''; $photo_path = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // --- Проверка CSRF токена ---
    $submitted_token = $_POST['csrf_token'] ?? null;
    if (!validateCsrfToken($submitted_token)) {
        $errors['csrf'] = "Ошибка безопасности формы. Обновите страницу и попробуйте снова.";
        // Не выходим сразу, покажем ошибку в форме
    } else {
        // Если токен валиден, обрабатываем остальные данные
        $category_id = isset($_POST['category_id']) ? trim($_POST['category_id']) : '';
        $location = isset($_POST['location']) ? trim($_POST['location']) : '';
        $description = isset($_POST['description']) ? trim($_POST['description']) : '';

        // --- Валидация полей ---
        if (empty($category_id)) { $errors['category_id'] = 'Выберите категорию.'; } else { /* ... проверка существования категории ... */ }
        if (empty($location)) { $errors['location'] = 'Укажите место.'; } elseif (mb_strlen($location) > 255) { $errors['location'] = 'Название места слишком длинное.'; }
        if (empty($description)) { $errors['description'] = 'Опишите проблему.'; }

        // --- Обработка фото ---
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] !== UPLOAD_ERR_NO_FILE) {
            $file = $_FILES['photo'];
            if ($file['error'] !== UPLOAD_ERR_OK) { $errors['photo'] = 'Ошибка загрузки файла. Код: '.$file['error']; }
            else {
                $max_size = 10 * 1024 * 1024; if ($file['size'] > $max_size) { $errors['photo'] = 'Файл не должен превышать 10 Мб.'; }
                $allowed_types = ['image/jpeg', 'image/png', 'image/gif']; $allowed_extensions = ['jpg', 'jpeg', 'png', 'gif'];
                $finfo = finfo_open(FILEINFO_MIME_TYPE); $mime_type = finfo_file($finfo, $file['tmp_name']); finfo_close($finfo);
                $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($mime_type, $allowed_types) || !in_array($file_extension, $allowed_extensions)) { $errors['photo'] = 'Недопустимый тип файла (JPG, PNG, GIF).'; }
                if (!isset($errors['photo'])) {
                    $upload_dir = __DIR__ . '/uploads/'; if (!is_dir($upload_dir)) { mkdir($upload_dir, 0777, true); }
                    $new_filename = uniqid('req_photo_', true) . '.' . $file_extension; $destination_path = $upload_dir . $new_filename;
                    if (move_uploaded_file($file['tmp_name'], $destination_path)) { $photo_path = 'uploads/' . $new_filename; }
                    else { $errors['photo'] = 'Ошибка сохранения файла.'; error_log("Move upload failed: {$file['tmp_name']} to {$destination_path}"); }
                }
            }
        } elseif (isset($_FILES['photo']) && ($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE)) { $errors['photo'] = 'Размер файла превышает лимит.'; }

        // --- Сохранение в БД, если нет ошибок ---
        if (empty($errors)) {
            mysqli_begin_transaction($db);
            $new_request_id = null;
            try {
                // Вставка заявки
                $sql_insert = "INSERT INTO requests (guest_phone, category_id, location, description, photo_path, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, 'new', NOW(), NOW())";
                $stmt = mysqli_prepare($db, $sql_insert);
                if (!$stmt) throw new Exception("Ошибка подготовки запроса заявки: ".mysqli_error($db));
                mysqli_stmt_bind_param($stmt, "sisss", $guest_phone, $category_id, $location, $description, $photo_path);
                if (!mysqli_stmt_execute($stmt)) { throw new Exception("Ошибка сохранения заявки: ".mysqli_stmt_error($stmt)); }
                $new_request_id = mysqli_insert_id($db);
                mysqli_stmt_close($stmt);

                if (!($new_request_id > 0)) { throw new Exception("Не удалось получить ID новой заявки."); }

                // Запись в историю
                $action_type = 'Заявка создана'; $new_value_hist = 'new'; $comment_hist = "Тел: ".$guest_phone;
                $sql_history = "INSERT INTO request_history (request_id, action_type, new_value, comment) VALUES (?, ?, ?, ?)";
                $stmt_history = mysqli_prepare($db, $sql_history);
                if (!$stmt_history) throw new Exception("Ошибка подготовки истории: ".mysqli_error($db));
                mysqli_stmt_bind_param($stmt_history, "isss", $new_request_id, $action_type, $new_value_hist, $comment_hist);
                if (!mysqli_stmt_execute($stmt_history)) { throw new Exception("Ошибка записи истории: ".mysqli_stmt_error($stmt_history)); }
                mysqli_stmt_close($stmt_history);

                // Фиксация и редирект
                mysqli_commit($db);
                mysqli_close($db);
                $_SESSION['success_message'] = "Ваша заявка #".$new_request_id." успешно создана!";
                header('Location: guest_requests.php');
                exit();

            } catch (Exception $e) {
                mysqli_rollback($db); // Откат транзакции
                $errors['db'] = "Ошибка сохранения: " . $e->getMessage();
                error_log("Error creating request: " . $e->getMessage());
                if ($photo_path && file_exists(__DIR__ . '/' . $photo_path)) { @unlink(__DIR__ . '/' . $photo_path); }
            }
        } // Конец if (empty($errors))
    } // Конец проверки CSRF
} // Конец if POST

// --- Отображение страницы ---
$page_title = 'Создание новой заявки';
require_once __DIR__ . '/includes/header.php';
?>

<h1><?php echo $page_title; ?></h1>

<?php if ($category_error): ?> <div class="alert alert-warning"><?php echo htmlspecialchars($category_error); ?> Невозможно создать заявку.</div> <?php endif; ?>
<?php if (isset($errors['db'])): ?> <div class="alert alert-danger"><?php echo htmlspecialchars($errors['db']); ?></div> <?php endif; ?>
<?php if (isset($errors['csrf'])): ?> <div class="alert alert-danger"><?php echo htmlspecialchars($errors['csrf']); ?></div> <?php endif; ?>

<form method="POST" action="create_request.php" enctype="multipart/form-data">
    <?php echo csrfInput(); // CSRF токен ?>

    <div class="mb-3">
        <label for="category_id" class="form-label">Категория <span class="text-danger">*</span></label>
        <select class="form-select <?php echo isset($errors['category_id']) ? 'is-invalid' : ''; ?>" id="category_id" name="category_id" required <?php if(empty($categories) || $category_error) echo 'disabled'; ?>>
            <option value="">-- Выберите категорию --</option>
            <?php foreach ($categories as $cat): ?> <option value="<?php echo $cat['id']; ?>" <?php echo ($category_id == $cat['id']) ? 'selected' : ''; ?>><?php echo htmlspecialchars($cat['name']); ?></option> <?php endforeach; ?>
        </select>
        <?php if (isset($errors['category_id'])): ?> <div class="invalid-feedback"><?php echo $errors['category_id']; ?></div> <?php endif; ?>
    </div>

    <div class="mb-3">
        <label for="location" class="form-label">Место (корпус, № комнаты/зоны) <span class="text-danger">*</span></label>
        <input type="text" class="form-control <?php echo isset($errors['location']) ? 'is-invalid' : ''; ?>" id="location" name="location" value="<?php echo htmlspecialchars($location); ?>" required maxlength="255">
         <?php if (isset($errors['location'])): ?> <div class="invalid-feedback"><?php echo $errors['location']; ?></div> <?php endif; ?>
    </div>

    <div class="mb-3">
        <label for="description" class="form-label">Описание проблемы <span class="text-danger">*</span></label>
        <textarea class="form-control <?php echo isset($errors['description']) ? 'is-invalid' : ''; ?>" id="description" name="description" rows="4" required><?php echo htmlspecialchars($description); ?></textarea>
         <?php if (isset($errors['description'])): ?> <div class="invalid-feedback"><?php echo $errors['description']; ?></div> <?php endif; ?>
    </div>

    <div class="mb-3">
        <label for="photo" class="form-label">Прикрепить фото (необязательно, макс. 10Мб)</label>
        <input class="form-control <?php echo isset($errors['photo']) ? 'is-invalid' : ''; ?>" type="file" id="photo" name="photo" accept="image/jpeg, image/png, image/gif">
        <?php if (isset($errors['photo'])): ?> <div class="invalid-feedback d-block"><?php echo $errors['photo']; ?></div> <?php endif; ?>
    </div>

    <div class="d-flex justify-content-between">
         <a href="guest_requests.php" class="btn btn-secondary">Отмена</a>
         <button type="submit" class="btn btn-primary" <?php if(empty($categories) || $category_error) echo 'disabled'; ?>>Отправить заявку</button>
    </div>

</form>

<?php
// Закрываем соединение с БД только если оно еще не было закрыто
if (isset($db) && $db && mysqli_ping($db)) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php';
?>