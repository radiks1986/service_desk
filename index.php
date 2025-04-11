<?php
// Файл: index.php
// Страница входа для Гостя (ввод номера телефона)

// Подключаем конфиг (если еще не был подключен через header)
if (!defined('DB_HOST')) {
    require_once __DIR__ . '/config.php';
}
// Подключаем шапку
$page_title = 'Вход для гостя';
require_once __DIR__ . '/includes/header.php';

// Проверяем, не вошел ли гость уже (или другой пользователь)
if (isset($_SESSION['guest_phone'])) {
    header('Location: guest_requests.php'); // Перенаправляем на список заявок
    exit();
}
if (isset($_SESSION['user_id'])) {
    // Если вошел сотрудник, решаем, куда его перенаправить
    // Пока просто выведем сообщение или перенаправим на его дашборд (сделаем позже)
    echo '<div class="alert alert-info">Вы уже авторизованы как сотрудник. <a href="logout.php">Выйти</a>?</div>';
    require_once __DIR__ . '/includes/footer.php'; // Не забываем подвал
    exit();
}


// Обработка отправки формы
$phone_error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['phone'])) {
    $phone = trim($_POST['phone']);

    // Простая валидация номера телефона (можно усложнить)
    // Убираем все кроме цифр
    $phone_digits = preg_replace('/\D+/', '', $phone);

    // Примерная проверка длины (например, для российских номеров 10 цифр без +7/8)
    if (strlen($phone_digits) >= 10) { // Условие можно уточнить
        // Будем хранить "чистый" номер телефона
        $_SESSION['guest_phone'] = $phone_digits;
        // Сообщение об успехе (не обязательно, т.к. сразу редирект)
        // $_SESSION['success_message'] = 'Вы успешно вошли!';
        header('Location: guest_requests.php');
        exit();
    } else {
        $phone_error = 'Пожалуйста, введите корректный номер телефона (не менее 10 цифр).';
    }
}

?>

<div class="row justify-content-center">
    <div class="col-md-6">
        <h1 class="text-center mb-4"><?php echo $page_title; ?></h1>
        <p class="text-center text-muted">Введите ваш номер телефона для просмотра и создания заявок на обслуживание.</p>

        <form method="POST" action="index.php">
            <div class="mb-3">
                <label for="phone" class="form-label">Номер телефона</label>
                <input type="tel" class="form-control <?php echo $phone_error ? 'is-invalid' : ''; ?>" id="phone" name="phone" placeholder="+7 (___) ___-__-__" required value="<?php echo isset($_POST['phone']) ? htmlspecialchars($_POST['phone']) : ''; ?>">
                <?php if ($phone_error): ?>
                    <div class="invalid-feedback">
                        <?php echo $phone_error; ?>
                    </div>
                <?php endif; ?>
            </div>
            <button type="submit" class="btn btn-primary w-100">Войти / Посмотреть заявки</button>
        </form>
        <div class="text-center mt-3">
             <a href="login.php">Вход для персонала</a>
        </div>
    </div>
</div>

<?php
// Подключаем подвал
require_once __DIR__ . '/includes/footer.php';
?>