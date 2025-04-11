<?php
// Файл: includes/header.php
// Общая шапка HTML страниц

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

if (!defined('DB_HOST')) {
    require_once __DIR__ . '/../config.php';
}

// Определяем текущую роль пользователя для меню
$user_role = null;
$is_guest = false;
if (isset($_SESSION['user_id']) && isset($_SESSION['role'])) {
    $user_role = $_SESSION['role']; // 'executor', 'operator', 'admin'
} elseif (isset($_SESSION['guest_phone'])) {
    $is_guest = true;
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo isset($page_title) ? htmlspecialchars($page_title) . ' - ' : ''; ?><?php echo SITE_NAME; ?></title>

    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">

    <!-- Ваши кастомные стили (если понадобятся) -->
    <!-- <link rel="stylesheet" href="css/style.css"> -->

    <style>
        body { padding-top: 56px; padding-bottom: 60px; } /* Добавим отступ сверху под фикс. навбар */
        .container { max-width: 1140px; } /* Можно чуть шире для персонала */
        .footer {
            position: fixed;
            bottom: 0;
            width: 100%;
            height: 60px;
            line-height: 60px;
            background-color: #f5f5f5;
        }
    </style>
</head>
<body>

<nav class="navbar navbar-expand-lg navbar-light bg-light fixed-top mb-4"> <?php // fixed-top делает навбар прибитым к верху ?>
  <div class="container">
    <a class="navbar-brand" href="<?php
        // Ссылка на "дом" зависит от роли
        if ($user_role === 'executor') echo 'executor_dashboard.php';
        elseif ($user_role === 'operator') echo 'operator_dashboard.php';
        elseif ($is_guest) echo 'guest_requests.php';
        else echo 'index.php';
    ?>"><?php echo SITE_NAME; ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav" aria-controls="navbarNav" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarNav">
      <ul class="navbar-nav ms-auto">
        <?php // Динамическое меню ?>

        <?php if ($user_role): // Сотрудник вошел ?>
            <li class="nav-item">
                <span class="navbar-text me-3">
                    Здравствуйте, <?php echo htmlspecialchars($_SESSION['full_name']); ?>!
                </span>
            </li>
            <?php if ($user_role === 'executor'): ?>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'executor_dashboard.php' ? 'active' : ''; ?>" href="executor_dashboard.php">Мои Заявки</a>
                </li>
                 <?php // Сюда можно добавить другие пункты для исполнителя ?>
            <?php elseif ($user_role === 'operator'): ?>
                 <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'operator_dashboard.php' ? 'active' : ''; ?>" href="operator_dashboard.php">Все Заявки</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'operator_categories.php' ? 'active' : ''; ?>" href="operator_categories.php">Категории</a>
                </li>
                <li class="nav-item">
                    <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'operator_users.php' ? 'active' : ''; ?>" href="operator_users.php">Пользователи</a>
                </li>
                 <?php // Сюда можно добавить другие пункты для оператора (статистика, пользователи) ?>
            <?php endif; ?>
             <li class="nav-item">
                 <a class="nav-link" href="logout.php">Выход</a>
             </li>

        <?php elseif ($is_guest): // Гость вошел ?>
             <li class="nav-item">
                 <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'guest_requests.php' ? 'active' : ''; ?>" href="guest_requests.php">Мои заявки</a>
             </li>
             <li class="nav-item">
                 <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'create_request.php' ? 'active' : ''; ?>" href="create_request.php">Создать заявку</a>
             </li>
             <li class="nav-item">
                 <a class="nav-link" href="logout.php">Выход</a>
             </li>

        <?php else: // Никто не вошел (аноним) ?>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'index.php' ? 'active' : ''; ?>" href="index.php">Вход для гостя</a>
            </li>
            <li class="nav-item">
                <a class="nav-link <?php echo basename($_SERVER['PHP_SELF']) == 'login.php' ? 'active' : ''; ?>" href="login.php">Вход для персонала</a>
            </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<br />
<main class="container">
    <?php
    // Отображение сообщений об успехе или ошибках (если они есть в сессии)
    if (isset($_SESSION['success_message'])) {
        echo '<div class="alert alert-success alert-dismissible fade show" role="alert">'
             . htmlspecialchars($_SESSION['success_message'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        unset($_SESSION['success_message']);
    }
    if (isset($_SESSION['error_message'])) {
        echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">'
             . htmlspecialchars($_SESSION['error_message'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        unset($_SESSION['error_message']);
    }
     if (isset($_SESSION['warning_message'])) {
        echo '<div class="alert alert-warning alert-dismissible fade show" role="alert">'
             . htmlspecialchars($_SESSION['warning_message'])
             . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        unset($_SESSION['warning_message']);
    }
    ?>
<!-- Начало основного контента страницы (закрывающий тег </main> будет в footer.php) -->