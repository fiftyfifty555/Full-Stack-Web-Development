<?php
session_start();
require_once 'auth_check.php';
require_once 'session_timeout.php';

// Подключение к БД
$conn = new mysqli('localhost:3306', 'root', 'Vk280205+', 'jkh1');
if ($conn->connect_error) {
    die("Ошибка подключения: " . $conn->connect_error);
}

if (isset($_POST['logout'])) {
    session_unset();
    session_destroy();
    setcookie(session_name(), '', time() - 3600, '/');
    header("Location: index.php");
    exit();
}

$message = "";
$user_id = $_GET['id'] ?? null;
if ($user_id) {
    // Извлекаем данные пользователя и его адреса через JOIN
    $stmt = $conn->prepare("SELECT u.*, a.* FROM users u 
                            JOIN address a ON u.address_id = a.address_id 
                            WHERE u.user_id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows > 0) {
        $user = $result->fetch_assoc();
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Считываем данные из формы
            $fullname = trim($_POST['fullname'] ?? '');
            $login = trim($_POST['login'] ?? '');
            $password = trim($_POST['password'] ?? $user['password']); // Если пароль не введен, берем старый
            $phone = trim($_POST['phone'] ?? '');
            $email = trim($_POST['email'] ?? '');
            
            $region = trim($_POST['region'] ?? '');
            $city = trim($_POST['city'] ?? '');
            $street = trim($_POST['street'] ?? '');
            $home = trim($_POST['home'] ?? '');
            $corpus = trim($_POST['corpus'] ?? '');
            $post_index = trim($_POST['post_index'] ?? '');
            $flat_number = trim($_POST['flat_number'] ?? '');
            $square = trim($_POST['square'] ?? '');
            $residents_number = trim($_POST['residents_number'] ?? '');

            // Проверка на пустые поля
            if ($fullname === "" || $login === "" || $phone === "" || $email === "" || 
                $region === "" || $city === "" || $street === "" || $home === "" || 
                $post_index === "" || $square === "" || $residents_number === "") {
                $message = "Все обязательные поля должны быть заполнены.";
            } else {
                // Обновление данных пользователя
                $stmt_update = $conn->prepare("UPDATE users u
                                        JOIN address a ON u.address_id = a.address_id
                                        SET u.fullname = ?, u.login = ?, u.password = ?, u.phone = ?, u.email = ?,  
                                            a.region = ?, a.city = ?, a.street = ?, a.home = ?, a.corpus = ?, 
                                            a.post_index = ?, a.flat_number = ?, a.square = ?, a.residents_number = ?
                                        WHERE u.user_id = ?");
                $stmt_update->bind_param("ssssssssssssdsi", $fullname, $login, $password, $phone, $email, 
                                        $region, $city, $street, $home, $corpus, $post_index, $flat_number, $square, $residents_number, $user_id);
                if ($stmt_update->execute()) {
                    $message = "Данные пользователя успешно обновлены.";
                } else {
                    $message = "Ошибка обновления данных. Попробуйте еще раз.";
                }
                $stmt_update->close();
            }
        }
    } else {
        $message = "Пользователь не найден.";
    }
    $stmt->close();
}

$conn->close();

echo json_encode(['message' => $message]);
?>
