<?php
session_start();
require_once 'auth_check.php';
require_once 'session_timeout.php';

// Подключение к БД
$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

// Проверяем, что id передан в URL
if (!isset($_GET['id'])) {
    // Если id не передан, перенаправляем с сообщением об ошибке
    header("Location: admin.php?message=" . urlencode("Пользователь не найден"));
    exit();
}

$user_id = (int)$_GET['id'];

/*
   1. Проверяем, есть ли у пользователя хотя бы один счётчик.
      Связь у нас через address_id:
      user -> address_id = a.address_id
      meter -> address_id = a.address_id
      Значит ищем meter, у которого address_id совпадает с address_id пользователя.
*/

// Запрос для проверки:
$sql_check = "
    SELECT m.meter_id
    FROM meter m
    JOIN users u ON m.address_id = u.address_id
    WHERE u.user_id = ?
      -- Если вы используете мягкое удаление для meter, можно добавить:
      -- AND m.is_deleted=0
    LIMIT 1
";
$stmt_check = $conn->prepare($sql_check);
$stmt_check->bind_param("i", $user_id);
$stmt_check->execute();
$stmt_check->store_result();

if ($stmt_check->num_rows > 0) {
    // Значит у пользователя есть хотя бы один счётчик
    $stmt_check->close();
    $conn->close();

    // Перенаправляем с сообщением об ошибке
    header("Location: admin.php?message=" . urlencode("Нельзя удалить пользователя, у которого есть счётчики!"));
    exit();
}
$stmt_check->close();

/*
   2. Если счётчиков нет — удаляем пользователя
*/
$stmt_del = $conn->prepare("DELETE FROM users WHERE user_id = ?");
$stmt_del->bind_param("i", $user_id);

if ($stmt_del->execute()) {
    // Удаление успешно
    header("Location: admin.php?message=" . urlencode("Пользователь успешно удалён"));
} else {
    // Ошибка удаления
    header("Location: admin.php?message=" . urlencode("Ошибка при удалении пользователя"));
}

$stmt_del->close();
$conn->close();
exit();
