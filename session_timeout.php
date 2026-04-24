<?php
// session_timeout.php
// Подключать В САМОМ ВЕРХУ СРАЗУ ПОСЛЕ session_start()

// Проверяем, есть ли у пользователя LAST_ACTIVITY
if (isset($_SESSION['LAST_ACTIVITY'])) {
    // Проверяем, сколько времени прошло
    if (time() - $_SESSION['LAST_ACTIVITY'] > 120) { 
        // 2 минуты (120 секунд) неактивности
        // Уничтожаем сессию
        session_unset();
        session_destroy();
        // Перенаправляем на index.php?session_expired=1
        header("Location: index.php?session_expired=1");
        exit();
    }
}
// Обновляем метку времени
$_SESSION['LAST_ACTIVITY'] = time();
