<?php
// Файл: logout.php
// Скрипт для выхода пользователя (уничтожение сессии)

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Уничтожаем все переменные сессии
$_SESSION = array();

// Если используется сессионные cookies, удаляем их
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Уничтожаем сессию
session_destroy();

// Перенаправляем на главную страницу или страницу входа
header("Location: index.php");
exit();
?>