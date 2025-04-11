<?php
// Файл: includes/helpers.php
// Вспомогательные функции

if (!function_exists('getStatusBadge')) {
    /**
     * Возвращает HTML-бейдж для статуса заявки.
     * @param string $status Статус заявки ('new', 'in_progress', ...)
     * @return string HTML код бейджа
     */
    function getStatusBadge(string $status): string {
        $status_text = '';
        $status_badge_class = 'bg-secondary'; // По умолчанию
        switch ($status) {
            case 'new': $status_text = 'Новая'; $status_badge_class = 'bg-primary'; break;
            case 'in_progress': $status_text = 'В работе'; $status_badge_class = 'bg-info text-dark'; break;
            case 'paused': $status_text = 'Приостановлена'; $status_badge_class = 'bg-warning text-dark'; break;
            case 'info_requested': $status_text = 'Запрос инфо'; $status_badge_class = 'bg-light text-dark border'; break;
            case 'completed': $status_text = 'Выполнена'; $status_badge_class = 'bg-success'; break;
            case 'cancelled': $status_text = 'Отменена'; $status_badge_class = 'bg-danger'; break;
            default: $status_text = ucfirst($status); break; // Показываем как есть, если статус неизвестен
        }
        // Используем htmlspecialchars для текста статуса на всякий случай
        return '<span class="badge ' . $status_badge_class . '">' . htmlspecialchars($status_text) . '</span>';
    }
}

// --- НОВЫЕ Функции для CSRF-защиты ---

if (!function_exists('generateCsrfToken')) {
    /**
     * Генерирует CSRF-токен, ЕСЛИ ЕГО ЕЩЕ НЕТ в сессии для текущего запроса,
     * сохраняет его в сессии и возвращает.
     * @return string Токен из сессии
     * @throws Exception Если не удалось сгенерировать криптографически стойкие байты
     */
    function generateCsrfToken(): string {
        if (session_status() == PHP_SESSION_NONE) {
            session_start();
        }
        // Генерируем токен только если его нет или он устарел (если бы добавили проверку времени)
        // Для простоты - генерируем, только если его нет в сессии НА ДАННЫЙ МОМЕНТ
        if (empty($_SESSION['csrf_token'])) { // <<<--- ИЗМЕНЕНИЕ ЗДЕСЬ
            try {
                $token = bin2hex(random_bytes(32));
                $_SESSION['csrf_token'] = $token;
                $_SESSION['csrf_token_time'] = time();
            } catch (Exception $e) {
                // Обработка ошибки генерации случайных байт
                 error_log("Failed to generate random bytes for CSRF token: " . $e->getMessage());
                 // Можно пробросить исключение дальше или вернуть пустую строку/false, но лучше пробросить
                 throw $e;
            }
        }
        return $_SESSION['csrf_token']; // Всегда возвращаем токен, который сейчас в сессии
    }
}

if (!function_exists('validateCsrfToken')) {
    // ... (функция validateCsrfToken без изменений) ...
     function validateCsrfToken(?string $submitted_token, int $max_time = 3600): bool {
         if (session_status() == PHP_SESSION_NONE) { session_start(); }
         if (empty($submitted_token) || empty($_SESSION['csrf_token']) || empty($_SESSION['csrf_token_time'])) { error_log('CSRF validation failed: Missing token in session or submission.'); return false; }
         if ($max_time > 0 && (time() - $_SESSION['csrf_token_time'] > $max_time)) { error_log('CSRF validation failed: Token expired.'); unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']); return false; }
         if (hash_equals($_SESSION['csrf_token'], $submitted_token)) { unset($_SESSION['csrf_token'], $_SESSION['csrf_token_time']); return true; }
         else { error_log('CSRF validation failed: Token mismatch.'); return false; }
     }
}

if (!function_exists('csrfInput')) {
    /**
    * Получает текущий CSRF-токен (генерирует, если нужно) и возвращает HTML-код скрытого поля.
    * @return string HTML input field
    * @throws Exception Если не удалось сгенерировать токен
    */
    function csrfInput(): string {
        $token = generateCsrfToken(); // <<<--- Теперь эта функция гарантирует один токен на запрос
        return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token) . '">';
    }
}
?>