<?php
// Файл: login.php
// Страница входа для персонала (исполнителей, операторов)

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/db_connect.php'; // $db доступна

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Если пользователь уже аутентифицирован (как сотрудник), перенаправляем
if (isset($_SESSION['user_id'])) {
    // Перенаправляем на соответствующий дашборд
    if ($_SESSION['role'] === 'executor') {
        header('Location: executor_dashboard.php');
    } elseif ($_SESSION['role'] === 'operator') {
        header('Location: operator_dashboard.php');
    } else {
        // Для других ролей (admin?) пока можно на главную или показать ошибку
        header('Location: index.php');
    }
    exit();
}
// Если вошел гость, лучше его разлогинить перед входом сотрудника
if (isset($_SESSION['guest_phone'])) {
    // Можно просто удалить данные гостя или разлогинить полностью
    unset($_SESSION['guest_phone']);
    // Для полной очистки сессии гостя: include 'logout.php'; exit(); но это может быть нежелательно
}


$login_error = '';
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['username']) && isset($_POST['password'])) {
        $username = trim($_POST['username']);
        $password = $_POST['password']; // Не тримим пароль

        if (empty($username) || empty($password)) {
            $login_error = 'Пожалуйста, введите логин и пароль.';
        } else {
            // Ищем пользователя в БД
            $sql = "SELECT id, username, password_hash, full_name, role, organization_id, is_active
                    FROM users
                    WHERE username = ?";
            $stmt = mysqli_prepare($db, $sql);
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, "s", $username);
                mysqli_stmt_execute($stmt);
                $result = mysqli_stmt_get_result($stmt);
                $user = mysqli_fetch_assoc($result);
                mysqli_stmt_close($stmt);

                if ($user && $user['is_active'] == 1) {
                    // Пользователь найден и активен, проверяем пароль
                    if (password_verify($password, $user['password_hash'])) {
                        // Пароль верный! Аутентификация успешна

                        // Регенерируем ID сессии для безопасности
                        session_regenerate_id(true);

                        // Сохраняем данные пользователя в сессии
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['full_name'] = $user['full_name'];
                        $_SESSION['role'] = $user['role'];
                        $_SESSION['organization_id'] = $user['organization_id']; // Даже если null

                        // Убираем возможные остатки сессии гостя
                        unset($_SESSION['guest_phone']);

                        // Перенаправляем на соответствующий дашборд
                        if ($user['role'] === 'executor') {
                            header('Location: executor_dashboard.php');
                        } elseif ($user['role'] === 'operator') {
                             header('Location: operator_dashboard.php');
                        } else {
                            // По умолчанию или для админа (которого пока нет)
                            $_SESSION['warning_message'] = "Ваша роль ('".$user['role']."') пока не имеет специального дашборда.";
                            header('Location: index.php'); // Или другая страница
                        }
                        mysqli_close($db);
                        exit();

                    } else {
                        // Неверный пароль
                        $login_error = 'Неверный логин или пароль.';
                        // Дополнительно можно добавить логирование неудачных попыток входа
                    }
                } else {
                    // Пользователь не найден или неактивен
                    $login_error = 'Неверный логин или пароль.';
                }
            } else {
                // Ошибка подготовки запроса
                $login_error = "Ошибка на сервере при попытке входа. Попробуйте позже.";
                error_log("MySQLi prepare error on login: " . mysqli_error($db));
            }
        }
    } else {
        $login_error = 'Отсутствуют необходимые данные для входа.';
    }
    // Если дошли сюда, значит вход не удался
    mysqli_close($db);
}

// --- Отображение страницы ---
$page_title = 'Вход для персонала';
require_once __DIR__ . '/includes/header.php'; // Подключаем шапку (она должна корректно отображать меню)
?>

<div class="row justify-content-center">
    <div class="col-md-5">
        <h1 class="text-center mb-4"><?php echo $page_title; ?></h1>

        <?php if ($login_error): ?>
            <div class="alert alert-danger">
                <?php echo htmlspecialchars($login_error); ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label for="username" class="form-label">Логин</label>
                <input type="text" class="form-control <?php echo $login_error ? 'is-invalid' : ''; ?>" id="username" name="username" required value="<?php echo htmlspecialchars($username); ?>">
            </div>
            <div class="mb-3">
                <label for="password" class="form-label">Пароль</label>
                <input type="password" class="form-control <?php echo $login_error ? 'is-invalid' : ''; ?>" id="password" name="password" required>
            </div>
            <button type="submit" class="btn btn-primary w-100">Войти</button>
        </form>
         <div class="text-center mt-3">
             <a href="index.php">Вход для гостя</a>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/includes/footer.php'; // Подключаем подвал
// Соединение с БД уже должно быть закрыто в логике обработки POST или будет закрыто PHP автоматически
?>