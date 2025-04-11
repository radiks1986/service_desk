<?php
// Файл: operator_edit_user.php
// Страница редактирования пользователя для Оператора (с управлением категориями)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php';

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// --- Проверка аутентификации и роли ---
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'operator') {
    $_SESSION['error_message'] = "Доступ запрещен.";
    header('Location: login.php');
    exit();
}

// --- Получение ID пользователя из GET-параметра ---
$user_id_to_edit = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($user_id_to_edit <= 0) {
    $_SESSION['error_message'] = "Некорректный ID пользователя.";
    header('Location: operator_users.php');
    exit();
}

// --- Получение данных редактируемого пользователя ---
$user_data = null;
$db_error = null;
$sql_get_user = "SELECT id, username, full_name, role, is_active FROM users WHERE id = ?";
$stmt_get = mysqli_prepare($db, $sql_get_user);
if ($stmt_get) {
    mysqli_stmt_bind_param($stmt_get, "i", $user_id_to_edit);
    if (mysqli_stmt_execute($stmt_get)) {
        $result_get = mysqli_stmt_get_result($stmt_get);
        $user_data = mysqli_fetch_assoc($result_get);
        mysqli_free_result($result_get);
    } else { $db_error = "Ошибка получения данных пользователя: " . mysqli_stmt_error($stmt_get); error_log("MySQLi execute error (get user for edit): " . mysqli_stmt_error($stmt_get)); }
    mysqli_stmt_close($stmt_get);
} else { $db_error = "Ошибка подготовки запроса: " . mysqli_error($db); error_log("MySQLi prepare error (get user for edit): " . mysqli_error($db)); }

// --- Проверка, найден ли пользователь ---
if (!$user_data && !$db_error) {
    $_SESSION['error_message'] = "Пользователь с ID #{$user_id_to_edit} не найден.";
    header('Location: operator_users.php');
    exit();
}

// --- Получение данных для формы (если пользователь найден) ---
$available_roles = ['executor' => 'Исполнитель', 'operator' => 'Оператор'];
$all_categories = [];
$assigned_category_ids = [];

if ($user_data) {
    // Получаем все активные категории
    $sql_cats = "SELECT id, name FROM categories WHERE is_active = 1 ORDER BY name";
    $result_cats = mysqli_query($db, $sql_cats);
    if ($result_cats) {
        while ($row = mysqli_fetch_assoc($result_cats)) {
            $all_categories[] = $row;
        }
        mysqli_free_result($result_cats);
    } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка загрузки категорий: ".mysqli_error($db); error_log("MySQLi query error (get all active cats): ".mysqli_error($db)); }

    // Если это исполнитель, получаем его назначенные категории
    if ($user_data['role'] === 'executor') {
        $sql_assigned = "SELECT category_id FROM executor_categories WHERE user_id = ?";
        $stmt_assigned = mysqli_prepare($db, $sql_assigned);
        if ($stmt_assigned) {
            mysqli_stmt_bind_param($stmt_assigned, "i", $user_id_to_edit);
            if (mysqli_stmt_execute($stmt_assigned)) {
                $result_assigned = mysqli_stmt_get_result($stmt_assigned);
                while ($row = mysqli_fetch_assoc($result_assigned)) {
                    $assigned_category_ids[] = $row['category_id']; // Собираем только ID
                }
                mysqli_free_result($result_assigned);
            } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка загрузки назначенных категорий: ".mysqli_stmt_error($stmt_assigned); error_log("MySQLi execute error (get assigned cats): ".mysqli_stmt_error($stmt_assigned)); }
            mysqli_stmt_close($stmt_assigned);
        } else { $db_error = ($db_error ? $db_error.'; ' : '')."Ошибка подготовки запроса назначенных категорий: ".mysqli_error($db); error_log("MySQLi prepare error (get assigned cats): ".mysqli_error($db)); }
    }
}

// --- Отображение страницы ---
$page_title = $user_data ? "Редактирование: " . htmlspecialchars($user_data['username']) : "Ошибка";
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
     <h1><?php echo $page_title; ?></h1>
     <a href="operator_users.php" class="btn btn-secondary">Назад к списку</a>
</div>

<?php if ($db_error): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($db_error); ?></div>
<?php elseif ($user_data): ?>

    <form method="POST" action="operator_user_actions.php">
        <?php echo csrfInput(); ?>
        <input type="hidden" name="action" value="edit_user">
        <input type="hidden" name="user_id" value="<?php echo $user_data['id']; ?>">

        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="edit_username" class="form-label">Логин</label>
                <input type="text" class="form-control" id="edit_username" value="<?php echo htmlspecialchars($user_data['username']); ?>" readonly disabled>
                <small class="text-muted">Логин изменить нельзя.</small>
            </div>
            <div class="col-md-6 mb-3">
                 <label for="edit_full_name" class="form-label">ФИО <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="edit_full_name" name="full_name" value="<?php echo htmlspecialchars($user_data['full_name']); ?>" required maxlength="100">
            </div>
        </div>

        <div class="row">
             <div class="col-md-6 mb-3">
                 <label for="edit_role" class="form-label">Роль <span class="text-danger">*</span></label>
                 <select name="role" id="edit_role" class="form-select" required>
                      <?php foreach ($available_roles as $role_key => $role_name): ?>
                         <option value="<?php echo $role_key; ?>" <?php echo ($user_data['role'] === $role_key) ? 'selected' : ''; ?>>
                             <?php echo $role_name; ?>
                         </option>
                      <?php endforeach; ?>
                 </select>
             </div>
             <div class="col-md-6 mb-3">
                 <label for="edit_is_active" class="form-label">Статус</label>
                 <select name="is_active" id="edit_is_active" class="form-select" <?php if ($user_data['id'] === $_SESSION['user_id']) echo 'disabled'; // Блокируем, если это текущий пользователь ?> >
                    <option value="1" <?php echo ($user_data['is_active'] == 1) ? 'selected' : ''; ?>>Активен</option>
                    <option value="0" <?php echo ($user_data['is_active'] == 0) ? 'selected' : ''; ?>>Неактивен</option>
                 </select>
                  <?php if ($user_data['id'] === $_SESSION['user_id']): ?>
                      <small class="text-danger">Вы не можете изменить свой статус активности.</small>
                  <?php endif; ?>
             </div>
        </div>

        <div class="row">
             <div class="col-md-6 mb-3">
                 <label for="edit_password" class="form-label">Новый пароль</label>
                 <input type="password" class="form-control" id="edit_password" name="password" aria-describedby="passwordHelp">
                 <div id="passwordHelp" class="form-text">Оставьте поле пустым, если не хотите менять пароль.</div>
             </div>
              <div class="col-md-6 mb-3">
                 <label for="edit_password_confirm" class="form-label">Подтвердите новый пароль</label>
                 <input type="password" class="form-control" id="edit_password_confirm" name="password_confirm">
             </div>
        </div>

        <?php // --- Блок управления категориями (только для исполнителей) --- ?>
        <?php if ($user_data['role'] === 'executor'): ?>
            <hr>
            <h5 class="mb-3">Обслуживаемые категории</h5>
            <?php if (empty($all_categories)): ?>
                <div class="alert alert-warning">Нет доступных активных категорий для назначения.</div>
            <?php else: ?>
                 <div class="row">
                    <?php foreach ($all_categories as $category): ?>
                        <div class="col-md-4 col-sm-6 mb-2"> <?php // Адаптивные колонки для чекбоксов ?>
                            <div class="form-check">
                                <input class="form-check-input"
                                       type="checkbox"
                                       value="<?php echo $category['id']; ?>"
                                       id="cat_<?php echo $category['id']; ?>"
                                       name="categories[]" <?php // Важно: имя с [] для создания массива в POST ?>
                                       <?php echo in_array($category['id'], $assigned_category_ids) ? 'checked' : ''; // Отмечаем назначенные ?>
                                       >
                                <label class="form-check-label" for="cat_<?php echo $category['id']; ?>">
                                    <?php echo htmlspecialchars($category['name']); ?>
                                </label>
                            </div>
                        </div>
                    <?php endforeach; ?>
                 </div>
            <?php endif; ?>
        <?php endif; ?>
         <?php // --- Конец блока управления категориями --- ?>

         <hr>

         <div class="d-flex justify-content-end">
            <a href="operator_users.php" class="btn btn-secondary me-2">Отмена</a>
            <button type="submit" class="btn btn-primary">Сохранить изменения</button>
         </div>

    </form>

<?php endif; ?>

<?php
if ($db) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php';
?>