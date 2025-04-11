<?php
// Файл: operator_users.php
// Страница управления пользователями для Оператора

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php'; // Подключаем хелперы (для CSRF)

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

$current_operator_id = $_SESSION['user_id']; // ID текущего оператора
$user_name = $_SESSION['full_name'];
$db_error = null;

// --- Получение списка пользователей ---
$users = [];
$sql = "SELECT id, username, full_name, role, is_active, created_at FROM users ORDER BY FIELD(role, 'operator', 'executor', 'admin'), full_name ASC";
$result = mysqli_query($db, $sql);
if ($result) { while ($row = mysqli_fetch_assoc($result)) { $users[] = $row; } mysqli_free_result($result);
} else { $db_error = "Ошибка получения списка пользователей: " . mysqli_error($db); error_log("MySQLi query error (get users): " . mysqli_error($db)); }

// Определяем роли для формы добавления
$available_roles = ['executor' => 'Исполнитель', 'operator' => 'Оператор'];

// --- Отображение страницы ---
$page_title = 'Управление пользователями';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
     <h1><?php echo $page_title; ?></h1>
     <span><?php echo htmlspecialchars($user_name); ?></span>
</div>

<?php if ($db_error): ?> <div class="alert alert-danger"><?php echo htmlspecialchars($db_error); ?></div> <?php endif; ?>

<?php // --- Форма добавления нового пользователя --- ?>
<div class="card mb-4">
    <div class="card-header">Добавить нового пользователя</div>
    <div class="card-body">
        <form method="POST" action="operator_user_actions.php" class="row g-3">
             <?php echo csrfInput(); ?>
             <input type="hidden" name="action" value="add_user">
             <div class="col-md-6 col-lg-3"><label for="new_username" class="form-label">Логин <span class="text-danger">*</span></label><input type="text" class="form-control" id="new_username" name="username" required maxlength="50" pattern="[a-zA-Z0-9_]+" title="Латинские буквы, цифры и _"></div>
             <div class="col-md-6 col-lg-3"><label for="new_full_name" class="form-label">ФИО <span class="text-danger">*</span></label><input type="text" class="form-control" id="new_full_name" name="full_name" required maxlength="100"></div>
             <div class="col-md-4 col-lg-2"><label for="new_password" class="form-label">Пароль <span class="text-danger">*</span></label><input type="password" class="form-control" id="new_password" name="password" required></div>
             <div class="col-md-4 col-lg-2"><label for="new_role" class="form-label">Роль <span class="text-danger">*</span></label><select name="role" id="new_role" class="form-select" required><option value="">-- Выберите --</option><?php foreach ($available_roles as $role_key => $role_name): ?><option value="<?php echo $role_key; ?>"><?php echo $role_name; ?></option><?php endforeach; ?></select></div>
             <div class="col-lg-2 col-md-4 d-flex align-items-end"><div class="btn-group btn-group-sm w-100" role="group"><button type="submit" class="btn btn-success">Добавить</button></div></div> <?php // Обновили кнопку добавления для единообразия ?>
        </form>
    </div>
</div>

<?php // --- Таблица существующих пользователей --- ?>
<h2 class="h4 mb-3">Существующие пользователи</h2>
<div class="table-responsive">
    <table class="table table-striped table-hover table-bordered align-middle">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Логин</th>
                <th>ФИО</th>
                <th>Роль</th>
                <th>Статус</th>
                <th>Дата создания</th>
                <th>Действия</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($users) && !$db_error): ?>
                <tr><td colspan="7" class="text-center text-muted">Пользователи не найдены.</td></tr>
            <?php elseif (!empty($users)): ?>
                <?php foreach ($users as $user): ?>
                    <tr>
                        <td><?php echo $user['id']; ?></td>
                        <td><?php echo htmlspecialchars($user['username']); ?></td>
                        <td><?php echo htmlspecialchars($user['full_name']); ?></td>
                        <td><?php echo $available_roles[$user['role']] ?? htmlspecialchars(ucfirst($user['role'])); ?></td>
                        <td>
                            <?php if ($user['is_active']): ?><span class="badge bg-success">Активен</span>
                            <?php else: ?><span class="badge bg-secondary">Неактивен</span><?php endif; ?>
                        </td>
                        <td><?php echo date('d.m.Y H:i', strtotime($user['created_at'])); ?></td>
                        <td>
                            <?php // Если это текущий пользователь, действий нет ?>
                            <?php if ($user['id'] === $current_operator_id): ?>
                                 <span class="text-muted fst-italic">Это вы</span>
                            <?php else: ?>
                                <?php // Для остальных пользователей - кнопка с выпадающим меню ?>
                                <div class="btn-group btn-group-sm">
                                  <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    Действия
                                  </button>
                                  <ul class="dropdown-menu dropdown-menu-end">
                                    <?php // Пункт меню "Редактировать" (ссылка) ?>
                                    <li>
                                        <a class="dropdown-item" href="operator_edit_user.php?id=<?php echo $user['id']; ?>">Редактировать</a>
                                    </li>
                                    <?php // Пункт меню "Активировать/Деактивировать" (форма с кнопкой) ?>
                                    <li>
                                        <form method="POST" action="operator_user_actions.php" class="d-inline w-100">
                                            <?php echo csrfInput(); // <<<--- ВОТ ЭТА СТРОКА ВАЖНА ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="dropdown-item <?php echo $user['is_active'] ? 'text-secondary' : 'text-success'; ?>">
                                                <?php echo $user['is_active'] ? 'Деактивировать' : 'Активировать'; ?>
                                            </button>
                                        </form>
                                    </li>
                                    <li><hr class="dropdown-divider"></li> <?php // Разделитель ?>
                                    <li> <?php // Кнопка Удалить ?>
                                        <form method="POST" action="operator_user_actions.php" class="d-inline w-100" onsubmit="return confirm('Вы уверены, что хотите удалить пользователя <?php echo htmlspecialchars(addslashes($user['username']), ENT_QUOTES); ?>? Это действие необратимо!');"> <?php // Добавили onsubmit ?>
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                            <button type="submit" class="dropdown-item text-danger">Удалить</button>
                                        </form>
                                    </li>
                                  </ul>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php
if ($db) { mysqli_close($db); }
require_once __DIR__ . '/includes/footer.php';
?>