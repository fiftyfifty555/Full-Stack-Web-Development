<?php

if (session_status() == PHP_SESSION_NONE) {
    session_start();
}


if (!isset($_SESSION['user_id'])) {
    echo "
    <style>
        .error-message {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: rgba(255, 0, 0, 0.9);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
            z-index: 1000;
            font-size: 1.5em;
        }
        .overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }
    </style>
    <div class='overlay'></div>
    <div class='error-message'>
        Страница доступна только для авторизованных пользователей. <a href='index.php' style='color: #fff; text-decoration: underline;'>Войдите</a>, чтобы посмотреть содержимое страницы.
    </div>
    ";
    exit(); 
}
?>
