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
            $date_of_birth = trim($_POST['date_of_birth'] ?? '');
            $passport_series = trim($_POST['passport_series'] ?? '');
            $passport_number = trim($_POST['passport_number'] ?? '');
            $INN = trim($_POST['INN'] ?? '');
            
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
                $post_index === "" || $square === "" || $residents_number === "" ||
                $date_of_birth === "" || $passport_series === "" || $passport_number === "" || $INN === "") {
                $message = "Все обязательные поля должны быть заполнены.";
            } else {
                // Проверка на уникальность логина, номера паспорта и ИНН
                $stmt_check = $conn->prepare("SELECT user_id FROM users WHERE (login = ? OR passport_number = ? OR INN = ?) AND user_id != ?");
                $stmt_check->bind_param("sssi", $login, $passport_number, $INN, $user_id);
                $stmt_check->execute();
                $stmt_check->store_result();
                if ($stmt_check->num_rows > 0) {
                    $message = "Логин, номер паспорта или ИНН уже используются другим пользователем.";
                } else {
                    // Обновление данных пользователя
                    $stmt_update = $conn->prepare("UPDATE users u
                                            JOIN address a ON u.address_id = a.address_id
                                            SET u.fullname = ?, u.login = ?, u.password = ?, u.phone = ?, u.email = ?,  
                                                u.date_of_birth = ?, u.passport_series = ?, u.passport_number = ?, u.INN = ?,
                                                a.region = ?, a.city = ?, a.street = ?, a.home = ?, a.corpus = ?, 
                                                a.post_index = ?, a.flat_number = ?, a.square = ?, a.residents_number = ?
                                            WHERE u.user_id = ?");
                    $stmt_update->bind_param("ssssssssssssssdsi", $fullname, $login, $password, $phone, $email, 
                                            $date_of_birth, $passport_series, $passport_number, $INN,
                                            $region, $city, $street, $home, $corpus, $post_index, $flat_number, $square, $residents_number, $user_id);
                    if ($stmt_update->execute()) {
                        $message = "Данные пользователя успешно обновлены.";
                    } else {
                        $message = "Ошибка обновления данных. Попробуйте еще раз.";
                    }
                    $stmt_update->close();
                }
                $stmt_check->close();
            }
        }
    } else {
        $message = "Пользователь не найден.";
    }
    $stmt->close();
}

$conn->close();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Редактировать пользователя</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #6dd5ed, #2193b0);
            color: #fff;
            margin: 0;
            padding: 0;
        }
        header, footer {
            text-align: center;
            background: rgba(0,0,0,0.6);
            padding: 20px;
        }
        .content {
            padding: 20px;
            text-align: center;
        }
        .form-container {
            background: rgba(255,255,255,0.9);
            color: #000;
            padding: 20px;
            margin: 20px auto;
            border-radius: 10px;
            width: 80%;
            max-width: 1000px;
        }
        .form-container h2 {
            margin-top: 0;
        }
        .form-container form input {
            width: 48%;
            padding: 8px;
            margin: 8px 0;
            box-sizing: border-box;
            font-size: 1em;
        }
        .form-container form button {
            width: 100%;
            padding: 10px 20px;
            background: #2193b0;
            color: #fff;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
        .form-container form button:hover {
            background: #197a91;
        }
        .message {
            color: green;
            font-weight: bold;
            margin-bottom: 20px;
        }
        .error {
            color: red;
            font-weight: bold;
            margin-bottom: 20px;
        }
        footer p {
            margin: 0;
        }
        .logout-btn {
            position: absolute;
            top: 20px;
            right: 20px;
            background: red;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 5px;
            cursor: pointer;
        }
        .logout-btn:hover {
            background: darkred;
        }
        .back-btn {
            background: #ff6347;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 5px;
            margin-top: 20px;
            display: inline-block;
        }
        .back-btn:hover {
            background: #e55347;
        }
    </style>
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script>
        $(document).ready(function() {
            // Обработчик отправки формы через AJAX
            $("form").on("submit", function(e) {
                e.preventDefault(); // Prevent normal form submission
                
                var formData = $(this).serialize(); // Get form data
                
                $.ajax({
                    url: 'update_user.php?id=<?php echo $_GET['id']; ?>',
                    type: 'POST',
                    data: formData,
                    dataType: 'json',
                    success: function(response) {
                        // Update message based on server response
                        if (response.message) {
                            $(".message").text(response.message).fadeIn();
                        }
                    },
                    error: function() {
                        alert('Произошла ошибка при обновлении данных');
                    }
                });
            });
        });
    </script>
</head>
<body>
<header>
    <h1>Редактировать пользователя</h1>
    <a href="admin.php" class="back-btn">Назад</a>
    <form method="POST">
        <button type="submit" name="logout" class="logout-btn">Выйти</button>
    </form>
</header>
<section class="content">
    <div class="form-container">
        <h2>Редактирование пользователя</h2>
        <div class="message" style="display:none;"></div> <!-- Message display area -->
        <?php if (isset($user)): ?>
            <form method="POST">
                <input type="text" name="fullname" value="<?php echo htmlspecialchars($user['fullname']); ?>" placeholder="ФИО" required>
                <input type="text" name="login" value="<?php echo htmlspecialchars($user['login']); ?>" placeholder="Логин" required>
                <input type="text" name="password" value="<?php echo htmlspecialchars($user['password']); ?>" placeholder="Новый пароль (оставьте пустым, чтобы не менять)">
                <input type="text" name="phone" value="<?php echo htmlspecialchars($user['phone']); ?>" placeholder="Телефон" required>
                <input type="email" name="email" value="<?php echo htmlspecialchars($user['email']); ?>" placeholder="Email" required>
                <input type="date" name="date_of_birth" value="<?php echo htmlspecialchars($user['date_of_birth']); ?>" placeholder="Дата рождения" required>
                <input type="text" name="passport_series" value="<?php echo htmlspecialchars($user['passport_series']); ?>" placeholder="Серия паспорта" required>
                <input type="text" name="passport_number" value="<?php echo htmlspecialchars($user['passport_number']); ?>" placeholder="Номер паспорта" required>
                <input type="text" name="INN" value="<?php echo htmlspecialchars($user['INN']); ?>" placeholder="ИНН" required>
                
                <input type="text" name="region" value="<?php echo htmlspecialchars($user['region']); ?>" placeholder="Регион" required>
                <input type="text" name="city" value="<?php echo htmlspecialchars($user['city']); ?>" placeholder="Город" required>
                <input type="text" name="street" value="<?php echo htmlspecialchars($user['street']); ?>" placeholder="Улица" required>
                <input type="text" name="home" value="<?php echo htmlspecialchars($user['home']); ?>" placeholder="Дом" required>
                <input type="text" name="corpus" value="<?php echo htmlspecialchars($user['corpus']); ?>" placeholder="Корпус (если есть)">
                <input type="text" name="post_index" value="<?php echo htmlspecialchars($user['post_index']); ?>" placeholder="Почтовый индекс" required>
                <input type="text" name="flat_number" value="<?php echo htmlspecialchars($user['flat_number']); ?>" placeholder="Номер квартиры (если есть)">
                <input type="number" step="0.01" name="square" value="<?php echo htmlspecialchars($user['square']); ?>" placeholder="Площадь (кв.м)" required>
                <input type="number" name="residents_number" value="<?php echo htmlspecialchars($user['residents_number']); ?>" placeholder="Количество жильцов" required>
                
                <button type="submit">Обновить данные</button>
            </form>
        <?php endif; ?>
    </div>
</section>
<footer>
    <p>&copy; 2025 ЖКХ Город. Все права защищены.</p>
</footer>
</body>
</html>
