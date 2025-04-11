<?php
// Файл: operator_categories.php
// Страница управления категориями для Оператора

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна
require_once __DIR__ . '/includes/helpers.php'; // Для будущих хелперов, если понадобятся

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

$user_name = $_SESSION['full_name'];
$db_error = null;

// --- Получение списка категорий ---
$categories = [];
$sql = "SELECT id, name, is_active FROM categories ORDER BY name ASC";
$result = mysqli_query($db, $sql);
if ($result) {
    while ($row = mysqli_fetch_assoc($result)) {
        $categories[] = $row;
    }
    mysqli_free_result($result);
} else {
    $db_error = "Ошибка получения списка категорий: " . mysqli_error($db);
    error_log("MySQLi query error (get categories): " . mysqli_error($db));
}

// --- Отображение страницы ---
$page_title = 'Управление категориями';
require_once __DIR__ . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
     <h1><?php echo $page_title; ?></h1>
     <span><?php echo htmlspecialchars($user_name); ?></span>
</div>

<?php if ($db_error): ?>
    <div class="alert alert-danger">
        <?php echo htmlspecialchars($db_error); ?>
    </div>
<?php endif; ?>

<?php // --- Форма добавления новой категории --- ?>
<div class="card mb-4">
    <div class="card-header">Добавить новую категорию</div>
    <div class="card-body">
        <form method="POST" action="operator_category_actions.php" class="row g-3">
        <?php echo csrfInput(); ?>
            <input type="hidden" name="action" value="add_category"> <?php // Скрытое поле с типом действия ?>
            <div class="col-md-8">
                <label for="new_category_name" class="visually-hidden">Название категории</label>
                <input type="text" class="form-control" id="new_category_name" name="category_name" placeholder="Название новой категории" required maxlength="100">
            </div>
            <div class="col-md-4">
                <button type="submit" class="btn btn-success w-100">Добавить</button>
            </div>
        </form>
    </div>
</div>

<?php // --- Таблица существующих категорий --- ?>
<h2 class="h4 mb-3">Существующие категории</h2>
<div class="table-responsive">
    <table class="table table-striped table-hover table-bordered">
        <thead class="table-light">
            <tr>
                <th>ID</th>
                <th>Название</th>
                <th>Статус</th>
                <th>Действие</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($categories) && !$db_error): ?>
                <tr>
                    <td colspan="4" class="text-center text-muted">Категории еще не созданы.</td>
                </tr>
            <?php elseif (!empty($categories)): ?>
                <?php foreach ($categories as $category): ?>
                    <tr>
                        <td><?php echo $category['id']; ?></td>
                        <td><?php echo htmlspecialchars($category['name']); ?></td>
                        <td>
                            <?php if ($category['is_active']): ?>
                                <span class="badge bg-success">Активна</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">Неактивна</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="btn-group btn-group-sm"> <?php // Обертка для dropdown ?>
                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown" aria-expanded="false">
                                    Действия
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li> <?php // Действие Активировать/Деактивировать ?>
                                        <form method="POST" action="operator_category_actions.php" class="d-inline w-100"> <?php // Убрали d-inline, добавили w-100 ?>
                                            <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="toggle_status">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <button type="submit" class="dropdown-item <?php echo $category['is_active'] ? 'text-secondary' : 'text-success'; ?>">
                                                <?php echo $category['is_active'] ? 'Деактивировать' : 'Активировать'; ?>
                                            </button>
                                        </form>
                                    </li>
                                     <li><hr class="dropdown-divider"></li>
                                     <li> <?php // Действие Удалить ?>
                                        <form method="POST" action="operator_category_actions.php" class="d-inline w-100" onsubmit="return confirm('Вы уверены, что хотите удалить категорию «<?php echo htmlspecialchars(addslashes($category['name']), ENT_QUOTES); ?>»? Это действие необратимо и возможно только если нет связанных заявок!');">
                                             <?php echo csrfInput(); ?>
                                            <input type="hidden" name="action" value="delete_category">
                                            <input type="hidden" name="category_id" value="<?php echo $category['id']; ?>">
                                            <button type="submit" class="dropdown-item text-danger">Удалить</button>
                                        </form>
                                     </li>
                                     <?php // TODO: Добавить пункт меню "Редактировать" (если нужно) ?>
                                </ul>
                            </div>
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